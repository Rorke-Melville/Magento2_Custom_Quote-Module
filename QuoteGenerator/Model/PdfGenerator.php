<?php
namespace Gelmar\QuoteGenerator\Model;

use Zend_Pdf;
use Zend_Pdf_Page;
use Zend_Pdf_Font;
use Zend_Pdf_Color_GrayScale;
use Zend_Pdf_Color_Rgb;
use Zend_Pdf_Resource_Image_Png;
use Zend_Pdf_Resource_Image_Jpeg;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Driver\File;

class PdfGenerator
{
    protected $directoryList;
    protected $fileDriver;

    public function __construct(
        DirectoryList $directoryList,
        File $fileDriver
    ) {
        $this->directoryList = $directoryList;
        $this->fileDriver = $fileDriver;
    }

    public function generate(CartInterface $quote)
    {
        $pdf = new Zend_Pdf();
        $page = new Zend_Pdf_Page(Zend_Pdf_Page::SIZE_A4);
        $pdf->pages[] = $page;

        $pageWidth = $page->getWidth();
        $logoPath = BP . '/pub/media/logo.png';

        
        try {
            if ($this->fileDriver->isExists($logoPath)) {
                $image = str_ends_with(strtolower($logoPath), '.png')
                    ? new Zend_Pdf_Resource_Image_Png($logoPath)
                    : new Zend_Pdf_Resource_Image_Jpeg($logoPath);

                $logoWidth = 125;
                $logoHeight = 25;
                $startingX = 40;
                $bottomY = 780;

                $page->drawImage($image, $startingX, $bottomY, $startingX + $logoWidth, $bottomY + $logoHeight);
            }
        } catch (\Exception $e) {
            // Handle missing or invalid logo
            $page->setFont(Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA), 12);
            $page->drawText('Error loading logo: ' . $e->getMessage(), 50, 800);
        }

        $startX = 40;
        $startY = 765;

        $page->setFont(Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA), 10);
        $page->drawText('20 Rustic Close', $startX, $startY);
        $page->drawText('P.O. Box 2753', 400, $startY);
        $startY -= 10;
        $page->drawText('Briardene Industrial Park', $startX, $startY);
        $page->drawText('Durban', 400, $startY);
        $startY -= 10;
        $page->drawText('Durban', $startX, $startY);
        $page->drawText('4000', 400, $startY);
        $startY -= 10;
        $page->drawText('4051', $startX, $startY);

        $startY -= 20;
        $page->drawText('Tel: 031 573 2490', $startX, $startY);
        $page->drawText('Vat Reg No: 4270107271', 400, $startY);
        $startY -= 10;
        $page->drawText('Fax:' , $startX, $startY);
        $startY -= 10;

        // Set rectangle dimensions
        $rectStartX = $startX - 10; // Slightly wider on the left
        $rectEndX = 555; // Slightly wider on the right
        $rectStartY = $startY; // Adjust to place it above text
        $rectEndY = $startY - 25; // Height of the rectangle

        // Draw the rectangle with a fill color
        $page->setFillColor(new Zend_Pdf_Color_GrayScale(0.75)); // Light grey fill
        $page->setLineColor(new Zend_Pdf_Color_RGB(0, 0, 0)); // Black border
        $page->setLineWidth(1); // Border width
        $page->drawRectangle($rectStartX, $rectStartY, $rectEndX, $rectEndY, Zend_Pdf_Page::SHAPE_DRAW_FILL_AND_STROKE);

        // Add centered text to the rectangle
        $text = 'PRO FORMA INVOICE';
        // Set the font
        $font = Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA_BOLD);
        $page->setFont($font, 16);
        // Set the font
        $font = Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA_BOLD);
        $fontSize = 16;
        $page->setFont($font, $fontSize);
        $averageCharWidth = 7; // Approximate width in points
        $textWidth = strlen($text) * $averageCharWidth;
        // Calculate X position to center the text
        $textStartX = ($rectStartX + (($rectEndX - $rectStartX) - $textWidth) / 2) - 20;
        // Calculate Y position to center text vertically within the rectangle
        $textStartY = $rectEndY - (($rectEndY - $rectStartY) / 2) - 7; // Adjust height for font baseline
        // Draw the text
        $page->setFillColor(new Zend_Pdf_Color_RGB(0, 0, 0)); // Black text color
        $page->drawText($text, $textStartX, $textStartY, 'UTF-8');

        $startY -= 50;
        $page->setFont(Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA), 12);
        $customerName = $quote->getCustomerFirstname() . ' ' . $quote->getCustomerLastname();
        $customerNum = $quote->getBillingAddress()->getTelephone();
        $shippingAddress = $quote->getShippingAddress();
        $shippingAmount = $shippingAddress->getShippingAmount();
        $ClickStore = $quote->getShippingAddress()->getData('shipping_description');
        $street = $shippingAddress->getData('street');
        $province = $shippingAddress->getData('region');
        $city = $shippingAddress->getData('city');

        if (!$quote->getCustomerId() && $shippingAddress) {
            $customerName = $shippingAddress->getFirstname() . ' ' . $shippingAddress->getLastname();
        }

        $page->drawText('Quote for: ' . $customerName . ' (' . $customerNum . ')', $startX, $startY);
        $date = date('d-m-Y');
        $page->drawText('Date: ' . $date, 400, $startY);
        $startY -= 15;
        $page->drawText('Quote ID: ' . $quote->getId(), $startX, $startY);

        //Product Section
        $totalPrice = 0;
        $startY -= 20;
        // Define the widths for the rectangles (with 5 units decrementing from left to right)
        $widths = [
            360, // Width for 'Item(s)'
            60,  // Width for 'Price' (width of 'Item(s)' - 5)
            30,  // Width for 'Qty' (width of 'Price' - 5)
            70  // Width for 'Subtotal' (width of 'Qty' - 5)
        ];
        // Text for each heading
        $headings = [
            'Item(s)',
            'Price',
            'Qty',
            'Subtotal'
        ];
        // Starting X position (keep it for the rest of the content)
        $currentX = $startX -10; // Create a new variable for heading positions
        // Draw rectangles and center text
        foreach ($headings as $index => $heading) {
            // New variables for the current heading rectangle and text
            $currentRectStartX = $currentX; // X position for the rectangle
            $currentRectEndX = $currentX + $widths[$index]; // X position for the end of the rectangle
            $currentRectStartY = $startY; // Y position for the top of the rectangle
            $currentRectEndY = $startY - 15; // Y position for the bottom of the rectangle
            // Draw the rectangle
            $page->setLineColor(new Zend_Pdf_Color_RGB(0, 0, 0)); // Black border
            $page->setLineWidth(1); // Border width
            $page->drawRectangle($currentRectStartX, $currentRectStartY, $currentRectEndX, $currentRectEndY, Zend_Pdf_Page::SHAPE_DRAW_STROKE);
            // Set the font and draw the text in the center
            $page->setFont(Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA_BOLD), 12);         
            // Calculate text width to center it
            $textWidth = strlen($heading) * 7; // Approximate text width
            $currentTextStartX = $currentRectStartX + (($currentRectEndX - $currentRectStartX) - $textWidth) / 2; // X position to center text
            $currentTextStartY = $currentRectStartY - 12; // Y position to center text vertically
            // Draw the text
            $page->drawText($heading, $currentTextStartX, $currentTextStartY);
            // Move to the next X position for the next heading
            $currentX += $widths[$index]; // Increment $currentX for the next heading
        }
        $startY -= 30; // Move Y position down after headings
        //Individual Products
        $page->setFont(Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA), 12);
        $itemNumber = 1;
        foreach ($quote->getAllItems() as $item) {
            $page->setFont(Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA), 12);
            $productName = $item->getName();
            $sku = $item->getSku();
            $weight = $item->getWeight();
            $quantity = $item->getQty();
            $price = $item->getPrice();
            $itemTotal = $price * $quantity;
            $page->drawText($itemNumber . '.', $startX, $startY);
            $page->drawText($productName, $startX + 20, $startY);
            $page->drawText(number_format($price, 2), 400, $startY);
            $page->drawText($quantity, 465, $startY);
            $page->drawText(number_format($itemTotal, 2), 500, $startY);
            $page->setFont(Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA), 10);
            $startY -= 15;
            $page->drawText('SKU: ' . $sku, $startX + 20, $startY);
            $startY -= 15;
            $page->drawText('Weight: ' . number_format($weight, 2) . ' kg', $startX + 20, $startY);
            $startY -= 20;
            $itemNumber++;
            $totalPrice += $itemTotal;
        }
        $totalCost = $shippingAmount + $totalPrice;
        if ($shippingAmount == 0) {
            $startY -= 10;
            $page->setFont(Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA_BOLD), 12);
            $page->drawText('Total Cost: R' . number_format($totalCost, 2), 400, $startY);
        } else {
            $startY -= 10;
            $page->setFont(Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA_BOLD), 12);
            $page->drawText('Subtotal: R' . number_format($totalPrice, 2), 400, $startY);
            $startY -= 20;
            $page->drawText('Delivery: R' . number_format($shippingAmount, 2), 400, $startY);
            $startY -= 20;
            $page->drawText('Total Cost: R' . number_format($totalCost, 2), 400, $startY);
        }

        //Draw Line
        $startY -= 5;
        $page->setLineColor(new Zend_Pdf_Color_RGB(0, 0, 0));
        $page->drawLine($rectStartX, $startY, $rectEndX, $startY);
        $startY -= 1;
        // Draw the grey rectangle for "Delivery/Collection"
        $deliveryRectStartY = $startY; // Position the rectangle below the previous content
        $deliveryRectEndY = $deliveryRectStartY - 20; // Height of the rectangle
        $page->setFillColor(new Zend_Pdf_Color_GrayScale(0.75)); // Grey fill
        $page->drawRectangle($rectStartX, $deliveryRectStartY, $rectEndX, $deliveryRectEndY, Zend_Pdf_Page::SHAPE_DRAW_FILL);
        // Add centered text to the rectangle
        $deliveryText = 'DELIVERY/COLLECTION';
        $page->setFont($font, $fontSize - 4); // Use the same font and size as before
        // Set the font color to black
        $page->setFillColor(new Zend_Pdf_Color_RGB(0, 0, 0));
        // Approximate the text width for centering
        $deliveryTextWidth = strlen($deliveryText) * $averageCharWidth; // Using the same average char width
        // Calculate X position to center the text
        $deliveryTextStartX = ($rectStartX + (($rectEndX - $rectStartX) - $deliveryTextWidth) / 2) - 8; 
        // Calculate Y position to center the text vertically within the rectangle
        $deliveryTextStartY = $deliveryRectEndY - (($deliveryRectEndY - $deliveryRectStartY) / 2) - 5; // Adjust height for font baseline
        // Draw the centered text
        $page->drawText($deliveryText, $deliveryTextStartX, $deliveryTextStartY);
        $startY -= 20; 
        //Draw Click n Collect store OR Delivery Address
        if ($shippingAmount == 0) {
            $startY -= 15;
            $ClickStore = explode(',', $ClickStore)[1] ?? '';
            $page->setFont(Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA), 12);
            $page->drawText('- Click n Collect ', $startX, $startY);
            $startY -= 15;
            $page->drawText('- Store:' . $ClickStore, $startX, $startY);
        } else {
            $startY -= 15;
            $page->setFont(Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA), 12);
            $page->drawText('- Delivery', $startX, $startY);
            $startY -= 15;
            $page->drawText('- Address: ' . $street, $startX, $startY);
            $startY -= 15;
            $page->drawText('- City: ' . $city, $startX, $startY);
            $startY -= 15;
            $page->drawText('- Province: ' . $province, $startX, $startY);
        }

        // Draw a black line across the page
        $lineStartX = $rectStartX; // Same as rectangle's start X
        $lineEndX = $rectEndX;     // Same as rectangle's end X
        $lineY = $startY - 10;   // Just below the first rectangle
        $page->setLineWidth(1);    // Line thickness
        $page->setLineColor(new Zend_Pdf_Color_RGB(0, 0, 0)); // Black color
        $page->drawLine($lineStartX, $lineY, $lineEndX, $lineY);
        // Draw the second grey rectangle
        $secondRectStartY = $lineY - 2; // Position below the line
        $secondRectEndY = $secondRectStartY - 20; // Height of the rectangle
        $page->setFillColor(new Zend_Pdf_Color_GrayScale(0.75)); // Grey fill
        $page->drawRectangle($rectStartX, $secondRectStartY, $rectEndX, $secondRectEndY, Zend_Pdf_Page::SHAPE_DRAW_FILL);
        // Add centered text to the second rectangle
        $secondText = 'PROFORMA INVOICE ONLY - DO NOT RELEASE STOCK';
        $page->setFont($font, $fontSize-4); // Use the same font and size as the first rectangle
        // Approximate the text width for centering
        $secondTextWidth = strlen($secondText) * $averageCharWidth; // Using the same average char width
        // Calculate X position to center the text
        $secondTextStartX = ($rectStartX + (($rectEndX - $rectStartX) - $secondTextWidth) / 2)- 15;
        // Calculate Y position to center the text vertically within the rectangle
        $secondTextStartY = $secondRectEndY - (($secondRectEndY - $secondRectStartY) / 2) - 5; // Adjust height for font baseline

        // Draw the second text
        $page->setFillColor(new Zend_Pdf_Color_RGB(0, 0, 0)); // Black text color
        $page->drawText($secondText, $secondTextStartX, $secondTextStartY, 'UTF-8');
        // Set font for text
        $page->setFont(Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA), 10);
        // Create the unique payment reference with the quote ID
        $quoteId = $quote->getId();
        $paymentReference = 'UNIQUE PAYMENT REFERENCE FOR THIS EFT PAYMENT IS :- ' . '92-' . $quoteId;
        // Define the text
        $textLines = [
            "Prices are valid for 14 days from issue date OR while stocks last.",
            "Please confirm Stock Availability before making payment.",
            "I hereby confirm that the order items and quantities are correct.",
            "PAYMENT OPTIONS:-",
            "***Pay in-store by Cash or Credit/Debit card, to avoid delays in payment processing.***",
            "EFT payments may take up to 3 business days to clear, goods will only be released",
            "once the funds reflect in our account.",
            "Proof of EFT payment must be emailed to eft@gelmar.co.za",
            "Banking details:- Nedbank, Account number 1011474832, Branch code 164826",
            $paymentReference,
            "Customer order form must be presented upon collection.",
            "All rejected/returned deliveries will incur a 15% handling charge."
        ];
        // Starting Y position for text
        $startY -= 50; // Adjust as needed
        // Loop through each line of text and draw it with the correct color
        foreach ($textLines as $index => $line) {
            // Set color for specific lines
            if (in_array($index, [0, 1, 4, 9])) {
                // Set red color for 1st, 2nd, 5th, and 10th lines
                $page->setFillColor(new Zend_Pdf_Color_RGB(1, 0, 0)); // Red color
            } else {
                // Set black color for the other lines
                $page->setFillColor(new Zend_Pdf_Color_RGB(0, 0, 0)); // Black color
            }       
            // Draw the text line
            $page->drawText($line, $startX, $startY);            
            // Move the Y position down for the next line
            $startY -= 12; // Adjust as needed for spacing between lines
        }

        //Generate The PDF
        return $pdf;
    }

    public function savePdfToFile(Zend_Pdf $pdf, $filename)
    {
        $directoryPath = $this->directoryList->getPath(DirectoryList::VAR_DIR) . '/quotes/';

        if (!$this->fileDriver->isDirectory($directoryPath)) {
            $this->fileDriver->createDirectory($directoryPath);
        }

        $filePath = $directoryPath . $filename;

        try {
            $pdfContent = $pdf->render();
            $this->fileDriver->filePutContents($filePath, $pdfContent);
        } catch (\Exception $e) {
            throw new LocalizedException(__('Unable to save PDF file: ' . $e->getMessage()));
        }
    }
}