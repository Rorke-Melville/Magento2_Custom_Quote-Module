<?php
namespace Gelmar\QuoteGenerator\Controller\Cart;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Quote\Api\CartRepositoryInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\ResourceConnection;

class GenerateQuote extends Action
{
    protected $cartRepository;
    protected $formKeyValidator;
    protected $logger;
    protected $fileDriver;
    protected $jsonSerializer;
    protected $directoryList;
    protected $pdfGenerator;
    protected $customerSession;
    protected $resourceConnection;

    public function __construct(
        Context $context,
        CartRepositoryInterface $cartRepository,
        FormKeyValidator $formKeyValidator,
        LoggerInterface $logger,
        \Gelmar\QuoteGenerator\Model\PdfGenerator $pdfGenerator,
        File $fileDriver,
        JsonSerializer $jsonSerializer,
        DirectoryList $directoryList,
        CustomerSession $customerSession,
        ResourceConnection $resourceConnection
    ) {
        $this->cartRepository = $cartRepository;
        $this->formKeyValidator = $formKeyValidator;
        $this->logger = $logger;
        $this->pdfGenerator = $pdfGenerator;
        $this->fileDriver = $fileDriver;
        $this->jsonSerializer = $jsonSerializer;
        $this->directoryList = $directoryList;
        $this->customerSession = $customerSession;
        $this->resourceConnection = $resourceConnection;
        parent::__construct($context);
    }

    public function execute()
    {
        try {
            // Check if user is logged in
            if (!$this->customerSession->isLoggedIn()) {
                $this->logger->debug('User not logged in, returning JSON error.');
                $this->getResponse()->setHeader('Content-Type', 'application/json', true);
                $this->getResponse()->setBody(json_encode([
                    'error' => 'You must be signed in to generate a quote.',
                    'redirect' => $this->_url->getUrl('customer/account/login')
                ]));
                return;
            }

            // Get POST data (only expecting quote_id from checkout)
            $postData = $this->getRequest()->getPostValue();
            $quoteId = $postData['quote_id'] ?? null;
            if (!$quoteId) {
                throw new \Exception("Quote ID is missing.");
            }

            // Load the quote
            $quote = $this->cartRepository->get($quoteId);

            // Validate required quote data
            $shippingAddress = $quote->getShippingAddress();
            $billingAddress = $quote->getBillingAddress();

            // Check for required fields
            if (!$billingAddress || 
                !$billingAddress->getFirstname() ||
                !$billingAddress->getLastname() ||
                !$billingAddress->getStreet() ||
                !$billingAddress->getCity() ||
                !$billingAddress->getRegionId() ||
                !$billingAddress->getCountryId() ||
                !$billingAddress->getTelephone()) {
                throw new \Exception("Billing address is incomplete. Please fill in all required fields.");
            }

            if (!$shippingAddress || 
                !$shippingAddress->getStreet() ||
                !$shippingAddress->getCity() ||
                !$shippingAddress->getRegionId() ||
                !$shippingAddress->getCountryId() ||
                !$shippingAddress->getTelephone()) {
                throw new \Exception("Shipping address is incomplete. Please fill in all required fields.");
            }

            if (!$shippingAddress->getShippingMethod()) {
                throw new \Exception("Shipping method not selected. Please choose a shipping method.");
            }

            // Extract data from quote
            $shippingMethod = $shippingAddress->getShippingMethod();
            $shippingAmount = (float)$shippingAddress->getShippingAmount();
            $regionId = $shippingAddress->getRegionId();

            // Determine fulfillment store and delivery type
            $fulfillStore = "91"; // Default store
            $delType = 2; // Default to Delivery
            $region = $this->getRegionName($regionId);
            $selectedStore = $postData['selected_store'] ?? '';

            // Check if this is click-n-collect
            if ($shippingMethod === 'flatrate_flatrate') { // Adjust this to match your click-n-collect shipping method code
                // Get the selected store from the request data
                if (empty($selectedStore)) {
                    throw new \Exception("Store not selected for Click-n-Collect. Please select a store.");
                }

                // Parse the store selection (format: "store_id, Collect from Store Name")
                $storeParts = explode(',', $selectedStore, 2);
                if (count($storeParts) < 2) {
                    throw new \Exception("Invalid store selection for Click-n-Collect. Format: " . $selectedStore);
                }

                $fulfillStore = trim($storeParts[0]);
                $shippingAmount = 0.0; // Click-n-collect is free
                $delType = 1; // Pickup
                
                // Save selected store info to quote
                $quote->setData('selected_store_info', $selectedStore);
                $quote->save();

                //$this->logger->info('Click-n-Collect selected. Store: ' . $fulfillStore . ', Selection: ' . $selectedStore);
            } else {
                // Regular delivery: Determine fulfillStore based on region
                if ($regionId == 703 || $regionId == 706 || $regionId == 707) { // Gauteng, Limpopo, Mpumalanga
                    $fulfillStore = "48";
                } elseif ($regionId == 708 || $regionId == 710) { // Northern Cape, Western Cape
                    $fulfillStore = "50";
                }
                
                //$this->logger->info('Regular delivery selected. Region: ' . $region . ', Store: ' . $fulfillStore);
            }

            // Set User_Generated flag
            $quote->setData('User_Generated', 1);
            $quote->save();

            // Determine delivery method description
            $deliveryMethodDesc = '';
            if ($delType == 1) { // Click-n-collect
                if (!empty($selectedStore)) {
                    $storeParts = explode(',', $selectedStore, 2);
                    $deliveryMethodDesc = count($storeParts) > 1 ? trim($storeParts[1]) : 'Click-n-Collect';
                } else {
                    $deliveryMethodDesc = 'Click-n-Collect';
                }
            } else {
                $deliveryMethodDesc = $shippingAddress->getShippingDescription() ?? 'Standard Delivery';
            }

            // Save delivery method description to quote
            $quote->setData('delivery_comment', $deliveryMethodDesc);
            $quote->save();

            // Generate and return the PDF
            $pdf = $this->pdfGenerator->generate($quote);

            // Set response headers
            $this->getResponse()->clearHeaders()->clearBody();
            $this->getResponse()->setHeader('Content-Type', 'application/pdf', true);
            $this->getResponse()->setHeader(
                'Content-Disposition',
                'attachment; filename="GelmarQuote_' . $quoteId . '.pdf"',
                true
            );
            $this->getResponse()->setHeader('Cache-Control', 'no-store', true);
            $this->getResponse()->setHeader('Pragma', 'no-cache', true);
            $this->getResponse()->setHeader('Expires', '0', true);
            $this->getResponse()->setHeader('Content-Transfer-Encoding', 'binary', true);

            // Output the PDF
            $this->getResponse()->setBody($pdf->render());
            $this->getRequest()->setParam('no_cache', true);

            // Create JSON file
            $this->createJsonFile($quoteId, $quote, $shippingAmount, $delType, $fulfillStore, $region, $selectedStore);

            return;

        } catch (\Exception $e) {
            $this->logger->critical('Error generating quote: ' . $e->getMessage());
            $this->getResponse()->setHeader('Content-Type', 'application/json', true);
            $this->getResponse()->setBody(json_encode(['error' => $e->getMessage()]));
            return;
        }
    }

    /**
     * Get region name from region ID
     */
    protected function getRegionName($regionId)
    {
        $regions = [
            702 => "Free State",
            703 => "Gauteng",
            704 => "Eastern Cape",
            705 => "Kwazulu-Natal",
            706 => "Limpopo",
            707 => "Mpumalanga",
            708 => "Northern Cape",
            709 => "North-West",
            710 => "Western Cape"
        ];
        return $regions[$regionId] ?? "";
    }

    /**
     * Get next sequence number from sequence_order_1 table
     * Uses database transaction to ensure thread safety
     */
    protected function getNextSequenceNumber()
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('sequence_order_1');
        
        // Start transaction
        $connection->beginTransaction();
        
        try {
            // Lock the table and get current max sequence_value
            $select = $connection->select()
                ->from($tableName, ['sequence_value'])
                ->order('sequence_value DESC')
                ->limit(1)
                ->forUpdate(); // This locks the row for update
            
            $currentMax = $connection->fetchOne($select);
            
            // If no records exist, start from 1, otherwise increment
            $nextSequence = $currentMax ? ($currentMax + 1) : 1;
            
            // Insert the new sequence number
            $connection->insert($tableName, ['sequence_value' => $nextSequence]);
            
            // Commit transaction
            $connection->commit();
            
            //$this->logger->info('Generated next sequence number: ' . $nextSequence);
            
            return $nextSequence;
            
        } catch (\Exception $e) {
            // Rollback on error
            $connection->rollBack();
            $this->logger->critical('Error generating sequence number: ' . $e->getMessage());
            throw new \Exception('Failed to generate sequence number: ' . $e->getMessage());
        }
    }

    /**
     * Create the JSON file with quote data
     */
    protected function createJsonFile($quoteId, $quote, $shippingAmount, $delType, $fulfillStore, $region, $selectedStore = '')
    {
        try {
            $shippingAddress = $quote->getShippingAddress();
            $shippingMethod = $shippingAddress->getShippingMethod();
            
            // Get next sequence number for fake reserved order number
            $reservedOrderNumber = $this->getNextSequenceNumber();
            
            // Determine delivery method description
            $deliveryMethodDesc = '';
            if ($delType == 1) { // Click-n-collect
                if (!empty($selectedStore)) {
                    $storeParts = explode(',', $selectedStore, 2);
                    $deliveryMethodDesc = count($storeParts) > 1 ? trim($storeParts[1]) : 'Click-n-Collect';
                } else {
                    $deliveryMethodDesc = 'Click-n-Collect';
                }
            } else {
                $deliveryMethodDesc = $shippingAddress->getShippingDescription() ?? 'Standard Delivery';
            }

            $jsonData = [
                'quote_id' => '92-' . $quoteId,
                'reserved_order_number' => $reservedOrderNumber,
                'QuoteDate' => $quote->getCreatedAt() ?? '',
                'DeliveryTypeId' => $delType,
                'DeliveryMethod' => $shippingMethod ?? '',
                'DeliveryMethodDescription' => $deliveryMethodDesc,
                'DeliveryCost' => $shippingAmount,
                'FulFillingStoreCode' => $fulfillStore,
                'QuoteTotal' => 0,
                'QtyTotal' => 0,
                'CustomerName' => $quote->getCustomerFirstname() . ' ' . $quote->getCustomerLastname(),
                'CustomerTelNo' => $shippingAddress->getTelephone() ?? '',
                'DeliveryAddress' => implode(', ', (array)$shippingAddress->getStreet()) ?? '',
                'DeliveryCity' => $shippingAddress->getCity() ?? '',
                'DeliveryProvinceState' => $region,
                'Country' => $shippingAddress->getCountryId() ?? '',
                'products' => [],
            ];

            $totalSub = 0;
            foreach ($quote->getAllVisibleItems() as $item) {
                $subtotal = $item->getPrice() * $item->getQty();
                $jsonData['products'][] = [
                    'name' => $item->getName(),
                    'sku' => $item->getSku(),
                    'price' => $item->getPrice(),
                    'quantity' => $item->getQty(),
                    'subtotal' => $subtotal,
                ];
                $jsonData['QtyTotal'] += $item->getQty();
                $totalSub += $subtotal;
            }

            $jsonData['QuoteTotal'] = $totalSub + $shippingAmount;

            $jsonContent = $this->jsonSerializer->serialize($jsonData);
            $quoteDirectory = '/var/www/html/quotes';
            if (!$this->fileDriver->isDirectory($quoteDirectory)) {
                $this->fileDriver->createDirectory($quoteDirectory);
            }
            $jsonFileName = $quoteDirectory . '/quote_' . $quoteId . '.json';
            $this->fileDriver->filePutContents($jsonFileName, $jsonContent);

            //$this->logger->info('JSON file created successfully: ' . $jsonFileName . ' with reserved order number: ' . $reservedOrderNumber);

        } catch (\Exception $e) {
            $this->logger->critical('Error creating JSON file: ' . $e->getMessage());
        }
    }
}