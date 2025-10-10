<?php
namespace Gelmar\OrderCreation\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;

class AceOrder implements ObserverInterface
{
    protected $_logger;
    protected $order;
    protected $quote;

    public function __construct(
        \Magento\Sales\Model\Order $order,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Quote\Model\Quote $quote,
        array $data = []
    ) {
        $this->_logger = $logger;
        $this->order = $order;
        $this->quote = $quote;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $invoice = $observer->getEvent()->getInvoice();
        $order = $invoice->getOrder();
        $order_id = $order->getIncrementId();
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $order = $objectManager->get('Magento\Sales\Model\Order');
        $order_information = $order->loadByIncrementId($order_id);

        // Base order information
        $myArray = $order_information->getData();

        // Get customer VAT number
        $VatReg = "";
        $customerID = $myArray['customer_id'];
        $customerObj = $objectManager->create('Magento\Customer\Model\Customer')->load($customerID);
        if ($customerObj->getData()) {
            $customer = $customerObj->getData();
            $VatReg = $customer['taxvat'] ?? "";
        }
        
        // Shipping details
        $streetLine1 = "";
        $streetLine2 = "";
        $street1 = "";
        $street2 = "";
        $street3 = "";
        $company = "";

        $shippingdetails = $order->getShippingAddress()->getData();
        $streetLine = $order->getShippingAddress()->getStreet();

        $counter = 0;
        foreach($streetLine as $sLine) {
            if ($counter == 0) {
                $streetLine1 = $sLine;
            } elseif ($counter == 1) {
                $streetLine2 = $sLine;
            }
            $counter++;
        }        
        
        $company = $shippingdetails['company'] ?? "";

        if ($company == "") {
            $street1 = $streetLine1;
            $street2 = $streetLine2;
            $street3 = "";
        } else {
            $street1 = $company;
            $street2 = $streetLine1;
            $street3 = $streetLine2;
        }

        if ($VatReg == "") {
            $VatReg = $shippingdetails['vat_id'] ?? "";
        }

        // Payment details
        $paymentdetails = $order->getPayment();
        $Paytext = $order->getBaseTotalPaid();
        
        $linen = 1;
        $jProd = [];
        $orderItems = $order->getAllVisibleItems();
        
        foreach($orderItems as $orderItem) {
            $jProd[] = [
                'LineID' => $linen,
                'ItemCode' => $orderItem->getSku(),
                'OrderQty' => $orderItem->getQtyOrdered(),
                'UnitIncl' => number_format($orderItem->getPrice(), 2, '.', ',')
            ];
            $linen++;
        }

        // Payment provider
        $pcode = [];
        $orderData = $order->getPayment()->getData();
        $methodTitle = $orderData['additional_information']['method_title'] ?? "";

        if ($methodTitle == "PayFlex") {
            $pcode[] = [
                'PaymentId' => 1,
                'PaymentProviderCode' => '04',
                'PaymentTypeCode' => '11',
                'PaymentAmount' => number_format($myArray['grand_total'], 2, '.', ',')
            ];
        } elseif ($methodTitle == "PayJustNow") {
            $pcode[] = [
                'PaymentId' => 1,
                'PaymentProviderCode' => '04',
                'PaymentTypeCode' => '12',
                'PaymentAmount' => number_format($myArray['grand_total'], 2, '.', ',')
            ];
        } elseif ($methodTitle == "Zapper") {
            $pcode[] = [
                'PaymentId' => 1,
                'PaymentProviderCode' => '03',
                'PaymentTypeCode' => '00',
                'PaymentAmount' => number_format($myArray['grand_total'], 2, '.', ',')
            ];
        } elseif ($methodTitle == "PayGate") {
            $paymentType = $orderData['additional_information']['paygate-payment-type'] ?? "";
            if ($paymentType == "CC" || $paymentType == "0") {
                $pcode[] = [
                    'PaymentId' => 1,
                    'PaymentProviderCode' => '04',
                    'PaymentTypeCode' => '03',
                    'PaymentAmount' => number_format($myArray['grand_total'], 2, '.', ',')
                ];
            } elseif ($paymentType == "EW-MasterPass" || $paymentType == "EW-SCANTOPAY") {
                $pcode[] = [
                    'PaymentId' => 1,
                    'PaymentProviderCode' => '04',
                    'PaymentTypeCode' => '08',
                    'PaymentAmount' => number_format($myArray['grand_total'], 2, '.', ',')
                ];
            } elseif ($paymentType == "BT") {
                $pcode[] = [
                    'PaymentId' => 1,
                    'PaymentProviderCode' => '04',
                    'PaymentTypeCode' => '09',
                    'PaymentAmount' => number_format($myArray['grand_total'], 2, '.', ',')
                ];
            } elseif ($paymentType == "EW-Mobicred") {
                $pcode[] = [
                    'PaymentId' => 1,
                    'PaymentProviderCode' => '04',
                    'PaymentTypeCode' => '10',
                    'PaymentAmount' => number_format($myArray['grand_total'], 2, '.', ',')
                ];
            }
        }
        
        // Delivery method and fulfilling store
        $fulfillStore = "91";
        $delType = 1;
        if ($myArray['shipping_amount'] != 0) {
            $delType = 2;
            if ($shippingdetails['region'] == "Gauteng") {
                $fulfillStore = "48";
            } elseif ($shippingdetails['region'] == "Limpopo") {
                $fulfillStore = "48";
            } elseif ($shippingdetails['region'] == "Mpumalanga") {
                $fulfillStore = "48";
            } elseif ($shippingdetails['region'] == "North-West") {
                $fulfillStore = "48";
            } elseif ($shippingdetails['region'] == "Western Cape") {
                $fulfillStore = "50";
            } elseif ($shippingdetails['region'] == "Northern Cape") {
                $fulfillStore = "50";
            }
        } else {
            if (!empty($myArray['delivery_comment'])) {
                $fulfillStore = substr($myArray['delivery_comment'], 0, 2);
            }
        }

        $affiliate_discount_amount = $myArray['affiliate_discount_amount'] ?? 0.0000;
        if ($affiliate_discount_amount === 'null' || $affiliate_discount_amount === null) {
            $affiliate_discount_amount = 0.0000;
        }

        // Quote logic
        $quoteId = $order_information->getQuoteId();
        $quote = $this->quote->load($quoteId);
        $quoteData = $quote->getData();
        $userGenerated = $quoteData['User_Generated'] ?? 0;

        // Create json file
        $json = [
            'StoreCode' => "90",
            'OriginatingSysOrderId' => $order_id,
            'OrderDate' => date('c', strtotime('+2 hours')),
            'ChannelId' => 1,
            'DeliveryTypeId' => $delType,
            'DeliveryCost' => $myArray['shipping_amount'],
            'FulFillingStoreCode' => $fulfillStore,
            'ItmExclVat' => "N",
            'Discount' => $affiliate_discount_amount,
            'OrderTotal' => $myArray['grand_total'],
            'QtyTotal' => $myArray['total_qty_ordered'],
            'CustomerDeliveryInstruct' => "",
            'CustomerName' => $myArray['customer_firstname'] . " " . $myArray['customer_lastname'],
            'CustomerTelNo1' => $shippingdetails['telephone'] ?? "",
            'CustomerTelNo2' => "",
            'CustomerEmailAddress' => $shippingdetails['email'] ?? "",
            'VatReg' => $VatReg,
            'DeliveryAddress1' => $street1,
            'DeliveryAddress2' => $street2,
            'DeliveryAddress3' => $street3,
            'DeliveryCity' => $shippingdetails['city'] ?? "",
            'DeliveryPostalCode' => $shippingdetails['postcode'] ?? "",
            'DeliveryProvinceState' => $shippingdetails['region'] ?? "",
            'OrderLines' => $jProd,
            'OrderPayments' => $pcode,
            'QuoteId' => $userGenerated == 1 ? $quoteId : 0
        ];
        
        // Write file
        $dirPath = '/var/www/html/orders/';
        $dataf = json_encode($json);
        $fname = $order_id;
        $tmpF = $fname . ".tmp";
        $jsonF = $fname . ".json";
        $file = fopen($dirPath . $tmpF, 'w');
        fwrite($file, $dataf);
        fclose($file);
        rename($dirPath . $tmpF, $dirPath . $jsonF);

        return $this;
    }
}

