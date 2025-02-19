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

class GenerateQuote extends Action
{
    protected $cartRepository;
    protected $formKeyValidator;
    protected $logger;
    protected $fileDriver;
    protected $jsonSerializer;
    protected $directoryList;
    protected $pdfGenerator;

    public function __construct(
        Context $context,
        CartRepositoryInterface $cartRepository,
        FormKeyValidator $formKeyValidator,
        LoggerInterface $logger,
        \Gelmar\QuoteGenerator\Model\PdfGenerator $pdfGenerator,
        File $fileDriver,
        JsonSerializer $jsonSerializer,
        DirectoryList $directoryList
    ) {
        $this->cartRepository = $cartRepository;
        $this->formKeyValidator = $formKeyValidator;
        $this->logger = $logger;
        $this->pdfGenerator = $pdfGenerator;
        $this->fileDriver = $fileDriver;
        $this->jsonSerializer = $jsonSerializer;
        $this->directoryList = $directoryList;
        parent::__construct($context);
    }

    public function execute()
    {
        try {
            // Get POST data
            $postData = $this->getRequest()->getPostValue();

            if (empty($postData)) {
                throw new \Exception("No data received from the form.");
            }

            $quoteId = $postData['quote_id'] ?? null;
            if (!$quoteId) {
                throw new \Exception("Quote ID is missing.");
            }

            // Load the quote
            $quote = $this->cartRepository->get($quoteId);

            // Update the quote with received data
            $quote->setCustomerFirstname($postData['firstname'] ?? '');
            $quote->setCustomerLastname($postData['lastname'] ?? '');
            $quote->getBillingAddress()->setStreet([$postData['street'] ?? '']);
            $quote->getBillingAddress()->setCity($postData['city'] ?? '');
            $quote->getBillingAddress()->setRegionId($postData['region'] ?? '');
            $quote->getBillingAddress()->setCountryId($postData['country'] ?? '');
            $quote->getBillingAddress()->setTelephone($postData['telephone'] ?? '');
            $quote->getShippingAddress()->setStreet([$postData['street'] ?? '']);
            $quote->getShippingAddress()->setCity($postData['city'] ?? '');
            $quote->getShippingAddress()->setRegionId($postData['region'] ?? '');
            $quote->getShippingAddress()->setCountryId($postData['country'] ?? '');
            $quote->getShippingAddress()->setTelephone($postData['telephone'] ?? '');
            $quote->getShippingAddress()->setShippingMethod($postData['shippingMethod'] ?? '');
            $shippingAmount = isset($postData['delivery_cost']) ? (float)$postData['delivery_cost'] : 0.0;

            $fulfillStore = "91";
            $delType = 1;
            $region = "";
            if($postData['region'] == 702)
            {
                $region = "Free State";
            }
            else if($postData['region'] == 703)
            {
                $region = "Gauteng";
                $fulfillStore = "48";
            }
            else if($postData['region'] == 704)
            {
                $region = "Eastern Cape";
            }
            else if($postData['region'] == 705)
            {
                $region = "Kwazulu-Natal";
            }
            else if($postData['region'] == 706)
            {
                $region = "Limpopo";
                $fulfillStore = "48";
            }
            else if($postData['region'] == 707)
            {
                $region = "Mpumalanga";
                $fulfillStore = "48";
            }
            else if($postData['region'] == 708)
            {
                $region = "Northern Cape";
                $fulfillStore = "50";
            }
            else if($postData['region'] == 709)
            {
                $region = "North-West";
                //$fulfillStore = "48";
            }
            else if($postData['region'] == 710)
            {
                $region = "Western Cape";
                $fulfillStore = "50";
            }
            if ($postData['shippingMethod'] === 'Click n Collect') {
                //Set quote info
                $quote->getShippingAddress()->setData('shipping_description', $postData['store'] ?? '');
                $shippingAmount = 0.0;
                $quote->getShippingAddress()->setShippingAmount($shippingAmount);
                $fulfillStore = explode(',', $postData['store'] ?? '')[0];
                $quote->setDeliveryComment($fulfillStore);
                
            }

            if ($shippingAmount) {
                $quote->getShippingAddress()->setShippingAmount($shippingAmount);
                $quote->setDeliveryComment(($postData['shippingMethod'] ?? '') . ': ' . ($postData['street'] ?? ''));
                $delType = 2;
            }

            // Save the updated quote
            $quote->save();

            // Generate and return the PDF
            $pdf = $this->pdfGenerator->generate($quote);

            // Clear response and set headers
            $this->getResponse()->clearHeaders()->clearBody();
            $this->getResponse()->setHeader('Content-Type', 'application/pdf', true);
            /*Open PDF in tab
            $this->getResponse()->setHeader(
                'Content-Disposition',
                'inline; filename="quote_' . $quoteId . '.pdf"',
                true
            );*/

            // Set the file name and prompt the user for download if desired
            $this->getResponse()->setHeader(
                'Content-Disposition',
                'attachment; filename="GelmarQuote_' . $quoteId . '.pdf"',
                true
            );

            // Prevent caching for the PDF request
            $this->getResponse()->setHeader('Cache-Control', 'no-store', true);
            $this->getResponse()->setHeader('Pragma', 'no-cache', true);
            $this->getResponse()->setHeader('Expires', '0', true);

            $this->getResponse()->setHeader('Content-Transfer-Encoding', 'binary', true);

            // Output the PDF content
            $this->getResponse()->setBody($pdf->render());
            $this->getRequest()->setParam('no_cache', true);

            // Create and save the JSON file with the form data
            $this->createJsonFile($quoteId, $postData, $quote, $shippingAmount, $delType, $fulfillStore, $region);

            // Return immediately to prevent further processing
            return;

            

        } catch (\Exception $e) {
            $this->logger->critical('Error generating quote: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('Error generating quote. Please try again.'));
            return $this->_redirect('checkout/cart');
        }
    }

    /**
     * Create the JSON file with form data
     *
     * @param string $quoteId
     * @param array $postData
     * @return void
     */
    protected function createJsonFile($quoteId, $postData, $quote, $shippingAmount, $delType, $fulfillStore, $region)
    {
        try {
            // Prepare data for JSON
            $jsonData = [
                'quote_id' => '92-' . $quoteId,
                'QuoteDate' => $quote['updated_at'] ?? '',
                'DeliveryTypeId' => $delType, //Order Delivery Type. 1 - Pickup, 2 - Deliver
                'DeliveryMethod' => $postData['shippingMethod'] ?? '',
                'DeliveryCost' => $shippingAmount,
                'FulFillingStoreCode' => $fulfillStore,
                'QuoteTotal' => 0,
                'QtyTotal' => 0,
                'CustomerName' => $postData['firstname'] ?? '' . " " . $postData['lastname'] ?? '',
                'CustomerTelNo' => $postData['telephone'] ?? '',
                'DeliveryAddress' => $postData['street'] ?? '',
                'DeliveryCity' => $postData['city'] ?? '',
                'DeliveryProvinceState' => $region,
                'Country' => $postData['country'] ?? '',
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

            // Convert data to JSON
            $jsonContent = $this->jsonSerializer->serialize($jsonData);

            // Get the directory path to save the JSON file
            $quoteDirectory = '/var/www/html/quotes';

            // Ensure the directory exists, create if not
            if (!$this->fileDriver->isDirectory($quoteDirectory)) {
                $this->fileDriver->createDirectory($quoteDirectory);
            }

            // Create and write to the JSON file
            $jsonFileName = $quoteDirectory . '/quote_' . $quoteId . '.json';
            $this->fileDriver->filePutContents($jsonFileName, $jsonContent);

        } catch (\Exception $e) {
            $this->logger->critical('Error creating JSON file: ' . $e->getMessage());
        }
    }
}
