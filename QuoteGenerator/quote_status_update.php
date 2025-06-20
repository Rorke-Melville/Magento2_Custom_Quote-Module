<?php
require __DIR__ . '/app/bootstrap.php';

// Bootstrap Magento
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();

// Set area code to 'adminhtml' for proper context
$state = $objectManager->get('Magento\Framework\App\State');
$state->setAreaCode('adminhtml');

// Get necessary services
$quoteRepository = $objectManager->get('Magento\Quote\Api\CartRepositoryInterface');
$cartManagement = $objectManager->get('Magento\Quote\Api\CartManagementInterface');
$orderRepository = $objectManager->get('Magento\Sales\Api\OrderRepositoryInterface');
$logger = $objectManager->get('Psr\Log\LoggerInterface');
$storeManager = $objectManager->get('Magento\Store\Model\StoreManagerInterface');
$productRepository = $objectManager->get('Magento\Catalog\Api\ProductRepositoryInterface');
$quoteItemFactory = $objectManager->get('Magento\Quote\Model\Quote\ItemFactory');

// Define the folder to scan for JSON files
$folderToScan = "/var/www/html/quotestatus/";
$processedFolder = $folderToScan . "Processed/";

// Ensure the Processed folder exists
if (!file_exists($processedFolder)) {
    mkdir($processedFolder, 0777, true);
}

// Function to determine shipping method based on delivery type
function getShippingMethodFromDeliveryType($deliveryTypeId) {
    // DeliveryTypeId: 1 = Click & Collect, 2 = Delivery
    return ($deliveryTypeId == 1) ? 'flatrate_flatrate' : 'tablerate_bestway';
}

// Function to get store ID from store code
function getStoreIdFromCode($storeManager, $storeCode) {
    try {
        $store = $storeManager->getStore($storeCode);
        return $store->getId();
    } catch (\Exception $e) {
        return null;
    }
}

// Function to compare and update quote products
function updateQuoteProducts($quote, $newProducts, $productRepository, $quoteItemFactory, $objectManager) {
    $changes = [];
    $currentItems = $quote->getAllVisibleItems();
    $currentItemsBySku = [];
    
    // Index current items by SKU
    foreach ($currentItems as $item) {
        $currentItemsBySku[$item->getSku()] = $item;
    }
    
    // Index new products by SKU
    $newProductsBySku = [];
    foreach ($newProducts as $product) {
        $newProductsBySku[$product['sku']] = $product;
    }
    
    // Check for removed or modified items
    foreach ($currentItemsBySku as $sku => $currentItem) {
        if (!isset($newProductsBySku[$sku])) {
            // Item was removed
            $quote->removeItem($currentItem->getId());
            $changes[] = "Removed product: {$currentItem->getName()} (SKU: $sku)";
        } else {
            $newProduct = $newProductsBySku[$sku];
            $qtyChanged = false;
            $priceChanged = false;
            
            // Check quantity change
            if ($currentItem->getQty() != $newProduct['quantity']) {
                $changes[] = "Updated quantity for {$currentItem->getName()} from {$currentItem->getQty()} to {$newProduct['quantity']}";
                $currentItem->setQty($newProduct['quantity']);
                $qtyChanged = true;
            }
            
            // Check price change
            if (abs($currentItem->getPrice() - floatval($newProduct['price'])) > 0.001) {
                $changes[] = "Updated price for {$currentItem->getName()} from {$currentItem->getPrice()} to {$newProduct['price']}";
                $currentItem->setCustomPrice($newProduct['price']);
                $currentItem->setOriginalCustomPrice($newProduct['price']);
                $currentItem->getProduct()->setIsSuperMode(true);
                $priceChanged = true;
            }
            
            if ($qtyChanged || $priceChanged) {
                $currentItem->save();
            }
        }
    }
    
    // Check for new items
    foreach ($newProductsBySku as $sku => $newProduct) {
        if (!isset($currentItemsBySku[$sku])) {
            try {
                $product = $productRepository->get($sku);
                $quoteItem = $quoteItemFactory->create();
                $quoteItem->setQuote($quote);
                $quoteItem->setProduct($product);
                $quoteItem->setQty($newProduct['quantity']);
                $quoteItem->setCustomPrice($newProduct['price']);
                $quoteItem->setOriginalCustomPrice($newProduct['price']);
                $quote->addItem($quoteItem);
                $changes[] = "Added new product: {$newProduct['name']} (SKU: $sku) - Qty: {$newProduct['quantity']}";
            } catch (\Exception $e) {
                $changes[] = "Failed to add product with SKU: $sku - " . $e->getMessage();
            }
        }
    }
    
    return $changes;
}

// Scan the folder for JSON files
$files = scandir($folderToScan);

foreach ($files as $file) {
    // Skip directories and non-JSON files
    if ($file === '.' || $file === '..' || $file === 'Processed' || pathinfo($file, PATHINFO_EXTENSION) !== 'json') {
        continue;
    }

    $filePath = $folderToScan . $file;
    echo "Processing file: $file" . PHP_EOL;

    try {
        // Read and decode the JSON file
        $jsonContent = file_get_contents($filePath);
        if ($jsonContent === false) {
            echo "Failed to read file: $file" . PHP_EOL;
            continue;
        }

        $jsonData = json_decode($jsonContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "Invalid JSON in file: $file - Deleting file" . PHP_EOL;
            unlink($filePath);
            continue;
        }

        // Handle both array of objects and single object
        $quotesToProcess = is_array($jsonData) && isset($jsonData[0]) ? $jsonData : [$jsonData];

        foreach ($quotesToProcess as $result) {
            // Check for required fields
            if (!isset($result['quote_id']) || !isset($result['reserved_order_number'])) {
                echo "Missing required fields (quote_id or reserved_order_number) in JSON object" . PHP_EOL;
                continue;
            }

            $quoteId = $result['quote_id'];
            $reservedOrderNumber = $result['reserved_order_number'];
            $changes = [];

            // Parse quote_id (assuming format "store_id-entity_id" like "92-787998")
            $parts = explode('-', $quoteId);
            if (count($parts) !== 2 || !is_numeric($parts[1])) {
                echo "Invalid quote_id format: $quoteId" . PHP_EOL;
                continue;
            }
            $entityId = (int)$parts[1];

            try {
                // Load the quote by entity_id
                $quote = $quoteRepository->get($entityId);

                // Check if quote is still active
                if (!$quote->getIsActive()) {
                    echo "Quote $quoteId is already inactive - skipping updates" . PHP_EOL;
                    continue;
                }

                // Check if order already exists
                $orderCollection = $objectManager->create('Magento\Sales\Model\ResourceModel\Order\Collection');
                $orderCollection->addFieldToFilter('quote_id', $quote->getId());
                if ($orderCollection->getSize() > 0) {
                    echo "Order already exists for quote $quoteId - skipping updates" . PHP_EOL;
                    continue;
                }

                echo "Processing quote updates for: $quoteId" . PHP_EOL;

                // Update delivery method if changed
                if (isset($result['DeliveryTypeId'])) {
                    $newShippingMethod = getShippingMethodFromDeliveryType($result['DeliveryTypeId']);
                    $currentShippingMethod = $quote->getShippingAddress()->getShippingMethod();
                    
                    if ($currentShippingMethod !== $newShippingMethod) {
                        // Properly set shipping method by collecting rates first
                        $shippingAddress = $quote->getShippingAddress();
                        $shippingAddress->setCollectShippingRates(true);
                        $shippingAddress->collectShippingRates();
                        
                        // Set the shipping method
                        $shippingAddress->setShippingMethod($newShippingMethod);
                        $changes[] = "Updated shipping method from '$currentShippingMethod' to '$newShippingMethod'";
                    }
                }

                // Update delivery method description directly from JSON
                if (isset($result['DeliveryMethodDescription'])) {
                    $currentDescription = $quote->getShippingAddress()->getShippingDescription();
                    if ($currentDescription !== $result['DeliveryMethodDescription']) {
                        $quote->getShippingAddress()->setShippingDescription($result['DeliveryMethodDescription']);
                        // Also set the delivery_comment column to match delivery description
                        $quote->setData('delivery_comment', $result['DeliveryMethodDescription']);
                        $changes[] = "Updated delivery description from '$currentDescription' to '{$result['DeliveryMethodDescription']}'";
                    }
                }

                // Update delivery cost if changed
                if (isset($result['DeliveryCost'])) {
                    $newDeliveryCost = floatval($result['DeliveryCost']);
                    $currentShippingAmount = $quote->getShippingAddress()->getShippingAmount();
                    
                    if (abs($currentShippingAmount - $newDeliveryCost) > 0.001) {
                        $quote->getShippingAddress()->setShippingAmount($newDeliveryCost);
                        $quote->getShippingAddress()->setBaseShippingAmount($newDeliveryCost);
                        $changes[] = "Updated delivery cost from '$currentShippingAmount' to '$newDeliveryCost'";
                    }
                }

                // Update fulfilling store if changed
                if (isset($result['FulFillingStoreCode'])) {
                    $newStoreCode = $result['FulFillingStoreCode'];
                    $newStoreId = getStoreIdFromCode($storeManager, $newStoreCode);
                    
                    if ($newStoreId && $quote->getStoreId() != $newStoreId) {
                        $quote->setStoreId($newStoreId);
                        $changes[] = "Updated store from '{$quote->getStoreId()}' to '$newStoreId' (Code: $newStoreCode)";
                    }
                }

                // Update customer information if changed
                if (isset($result['CustomerName'])) {
                    $nameParts = explode(' ', trim($result['CustomerName']), 2);
                    $firstName = $nameParts[0];
                    $lastName = isset($nameParts[1]) ? $nameParts[1] : '';
                    
                    if ($quote->getCustomerFirstname() !== $firstName || $quote->getCustomerLastname() !== $lastName) {
                        $quote->setCustomerFirstname($firstName);
                        $quote->setCustomerLastname($lastName);
                        $quote->getBillingAddress()->setFirstname($firstName);
                        $quote->getBillingAddress()->setLastname($lastName);
                        $quote->getShippingAddress()->setFirstname($firstName);
                        $quote->getShippingAddress()->setLastname($lastName);
                        $changes[] = "Updated customer name to '{$result['CustomerName']}'";
                    }
                }

                // Update customer telephone if changed
                if (isset($result['CustomerTelNo'])) {
                    if ($quote->getBillingAddress()->getTelephone() !== $result['CustomerTelNo']) {
                        $quote->getBillingAddress()->setTelephone($result['CustomerTelNo']);
                        $quote->getShippingAddress()->setTelephone($result['CustomerTelNo']);
                        $changes[] = "Updated customer telephone to '{$result['CustomerTelNo']}'";
                    }
                }

                // Update delivery address if changed
                $addressChanged = false;
                if (isset($result['DeliveryAddress']) && $quote->getShippingAddress()->getStreetLine(1) !== $result['DeliveryAddress']) {
                    $quote->getShippingAddress()->setStreet([$result['DeliveryAddress']]);
                    $addressChanged = true;
                }
                if (isset($result['DeliveryCity']) && $quote->getShippingAddress()->getCity() !== $result['DeliveryCity']) {
                    $quote->getShippingAddress()->setCity($result['DeliveryCity']);
                    $addressChanged = true;
                }
                if (isset($result['DeliveryProvinceState']) && $quote->getShippingAddress()->getRegion() !== $result['DeliveryProvinceState']) {
                    $quote->getShippingAddress()->setRegion($result['DeliveryProvinceState']);
                    $addressChanged = true;
                }
                if (isset($result['Country']) && $quote->getShippingAddress()->getCountryId() !== $result['Country']) {
                    $quote->getShippingAddress()->setCountryId($result['Country']);
                    $addressChanged = true;
                }
                
                if ($addressChanged) {
                    $changes[] = "Updated delivery address";
                }

                // Update products if changed
                if (isset($result['products']) && is_array($result['products'])) {
                    $productChanges = updateQuoteProducts($quote, $result['products'], $productRepository, $quoteItemFactory, $objectManager);
                    $changes = array_merge($changes, $productChanges);
                }

                // Update reserved order number if changed
                $formattedOrderId = '000' . $reservedOrderNumber;
                if ($quote->getReservedOrderId() !== $formattedOrderId) {
                    $quote->setReservedOrderId($formattedOrderId);
                    $changes[] = "Updated reserved order ID to '$formattedOrderId'";
                }

                // If there were changes, save the quote and recalculate totals
                if (!empty($changes)) {
                    $quote->collectTotals()->save();
                    echo "Updated quote $quoteId:" . PHP_EOL;
                    foreach ($changes as $change) {
                        echo "  - $change" . PHP_EOL;
                    }
                    //$logger->info("Quote $quoteId updated with changes: " . implode(', ', $changes));
                } else {
                    echo "No changes detected for quote $quoteId" . PHP_EOL;
                    // Still need to collect totals even if no changes to ensure quote is ready for order creation
                    $quote->collectTotals()->save();
                }

                // Now convert the quote to an order (like original logic)
                // Check if order with this increment_id already exists
                $formattedOrderId = '000' . $reservedOrderNumber;
                $existingOrderCollection = $objectManager->create('Magento\Sales\Model\ResourceModel\Order\Collection');
                $existingOrderCollection->addFieldToFilter('increment_id', $formattedOrderId);
                
                if ($existingOrderCollection->getSize() > 0) {
                    echo "Order with increment_id $formattedOrderId already exists" . PHP_EOL;
                    continue;
                }

                // Ensure shipping method is properly set before creating order
                $shippingAddress = $quote->getShippingAddress();
                if (!$shippingAddress->getShippingMethod()) {
                    // Set default shipping method based on delivery type if not set
                    $deliveryTypeId = isset($result['DeliveryTypeId']) ? $result['DeliveryTypeId'] : 1;
                    $defaultShippingMethod = getShippingMethodFromDeliveryType($deliveryTypeId);
                    $shippingAddress->setCollectShippingRates(true);
                    $shippingAddress->collectShippingRates();
                    $shippingAddress->setShippingMethod($defaultShippingMethod);
                    echo "Set default shipping method: $defaultShippingMethod" . PHP_EOL;
                }

                // Set payment method and create order
                $quote->getPayment()->importData(['method' => 'checkmo']);
                $quote->collectTotals()->save();
                $orderId = $cartManagement->placeOrder($quote->getId());

                // Load the order and set status to complete
                $order = $orderRepository->get($orderId);
                $order->setState(\Magento\Sales\Model\Order::STATE_COMPLETE)
                      ->setStatus('complete')
                      ->save();

                echo "Order created for quote $quoteId with order ID $formattedOrderId" . PHP_EOL;
                //$logger->info("Order created for quote $quoteId with custom order increment ID $formattedOrderId");

            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                echo "Quote $quoteId not found" . PHP_EOL;
            } catch (\Exception $e) {
                //$logger->error("Error processing quote $quoteId: " . $e->getMessage());
                echo "Error processing quote $quoteId: " . $e->getMessage() . PHP_EOL;
            }
        }

        // Move the file to Processed folder after successful processing
        rename($filePath, $processedFolder . $file);
        echo "File $file moved to Processed folder" . PHP_EOL;

    } catch (\Exception $e) {
        echo "Error processing file $file: " . $e->getMessage() . PHP_EOL;
    }
}

echo "Quote status update process completed" . PHP_EOL;
?>