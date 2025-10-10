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
    protected $pdf;
    protected $currentPage;
    protected $currentPageIndex = 0;
    
    // Constants for better maintainability
    const LOGO_WIDTH = 175;
    const LOGO_HEIGHT = 50;
    const PAGE_MARGIN = 40;
    const MIN_PAGE_MARGIN = 100;
    const AVERAGE_CHAR_WIDTH = 6.5;
    const TEXT_PADDING = 5;
    const LINE_HEIGHT = 15;
    const SECTION_SPACING = 20;
    const VAT_RATE = 1.15;
    const FOOTER_HEIGHT = 200; // Height reserved for footer content
    
    // Column configuration
    const COLUMN_WIDTHS = [70, 210, 60, 60, 60, 60];
    const COLUMN_HEADERS = ['Item Code', 'Description', 'Qty', 'Unit Incl.', 'Vat', 'Total'];

    public function __construct(
        DirectoryList $directoryList,
        File $fileDriver
    ) {
        $this->directoryList = $directoryList;
        $this->fileDriver = $fileDriver;
    }

    /**
     * Add background image that spans the entire A4 page
     */
    protected function addBackgroundImage()
    {
        $backgroundImagePath = BP . '/app/code/Gelmar/QuoteGenerator/proForma.jpg';
        
        try {
            if ($this->fileDriver->isExists($backgroundImagePath)) {
                // Determine image type and create appropriate resource
                $imageExtension = strtolower(pathinfo($backgroundImagePath, PATHINFO_EXTENSION));
                
                if ($imageExtension === 'png') {
                    $backgroundImage = new Zend_Pdf_Resource_Image_Png($backgroundImagePath);
                } elseif (in_array($imageExtension, ['jpg', 'jpeg'])) {
                    $backgroundImage = new Zend_Pdf_Resource_Image_Jpeg($backgroundImagePath);
                } else {
                    throw new \Exception('Unsupported image format: ' . $imageExtension);
                }

                // A4 page dimensions in points (72 points per inch)
                // A4 = 210mm x 297mm = 595.28 x 841.89 points
                $pageWidth = 595.28;
                $pageHeight = 841.89;

                // Draw the background image to cover the entire page
                $this->currentPage->drawImage(
                    $backgroundImage,
                    0,           // x1 (left edge)
                    0,           // y1 (bottom edge)
                    $pageWidth,  // x2 (right edge)
                    $pageHeight  // y2 (top edge)
                );
            }
        } catch (\Exception $e) {
            // If background image fails to load, you can either:
            // 1. Log the error and continue without background
            // 2. Add a text message indicating the issue
            error_log('Background image failed to load: ' . $e->getMessage());
            
            // Optional: Add a subtle background color as fallback
            $this->currentPage->setFillColor(new Zend_Pdf_Color_GrayScale(0.95));
            $this->currentPage->drawRectangle(0, 0, 595.28, 841.89, Zend_Pdf_Page::SHAPE_DRAW_FILL);
        }
    }

    /**
     * Calculate accurate text width for Zend_Pdf
     */
    protected function getTextWidth($text, $font, $fontSize)
    {
        $drawingText = iconv('UTF-8', 'UTF-16BE//IGNORE', $text);
        $characters = [];
        for ($i = 0; $i < strlen($drawingText); $i += 2) {
            $characters[] = (ord($drawingText[$i]) << 8) | ord($drawingText[$i + 1]);
        }
        $glyphs = $font->glyphNumbersForCharacters($characters);
        $widths = $font->widthsForGlyphs($glyphs);
        return (array_sum($widths) / $font->getUnitsPerEm()) * $fontSize;
    }

    /**
     * Wrap text to fit within a specified width
     */
    protected function wrapText($text, $maxWidth, $font, $fontSize)
    {
        $words = explode(' ', $text);
        $lines = [];
        $currentLine = '';

        foreach ($words as $word) {
            $testLine = $currentLine . ($currentLine ? ' ' : '') . $word;
            $testWidth = $this->getTextWidth($testLine, $font, $fontSize);

            if ($testWidth <= $maxWidth) {
                $currentLine = $testLine;
            } else {
                if ($currentLine) {
                    $lines[] = $currentLine;
                    $currentLine = $word;
                } else {
                    $lines[] = $this->breakLongWord($word, $maxWidth, $font, $fontSize);
                    $currentLine = '';
                }
            }
        }

        if ($currentLine) {
            $lines[] = $currentLine;
        }

        return $lines;
    }

    /**
     * Break a long word that doesn't fit in the available width
     */
    protected function breakLongWord($word, $maxWidth, $font, $fontSize)
    {
        $chars = str_split($word);
        $result = '';
        
        foreach ($chars as $char) {
            $testText = $result . $char;
            if ($this->getTextWidth($testText, $font, $fontSize) <= $maxWidth) {
                $result .= $char;
            } else {
                break;
            }
        }
        
        return $result ?: $word[0];
    }

    /**
     * Check if we need a new page and create one if necessary
     */
    protected function checkAndCreateNewPage($currentY, $minMargin = self::MIN_PAGE_MARGIN, $reserveFooterSpace = true)
    {
        $requiredMargin = $reserveFooterSpace ? ($minMargin + self::FOOTER_HEIGHT) : $minMargin;
        
        if ($currentY <= $requiredMargin) {
            $this->createNewPage();
            return 750;
        }
        return $currentY;
    }

    /**
     * Create a new page and set it as current
     */
    protected function createNewPage()
    {
        $newPage = new Zend_Pdf_Page(Zend_Pdf_Page::SIZE_A4);
        $this->pdf->pages[] = $newPage;
        $this->currentPage = $newPage;
        $this->currentPageIndex++;
        
        // Add background image to new page
        $this->addBackgroundImage();
        $this->addPageHeader();
    }

    /**
     * Add header to continuation pages
     */
    protected function addPageHeader()
    {
        $this->addLogo(self::PAGE_MARGIN, 750);
        
        // Add page number
        $this->currentPage->setFont(Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA), 9);
        $this->currentPage->drawText('Page ' . ($this->currentPageIndex + 1), 500, 780);
    }

    /**
     * Add logo to the page
     */
    protected function addLogo($x, $y)
    {
        $logoPath = BP . '/app/code/Gelmar/QuoteGenerator/Gelmar.png';
        try {
            if ($this->fileDriver->isExists($logoPath)) {
                $image = str_ends_with(strtolower($logoPath), '.png')
                    ? new Zend_Pdf_Resource_Image_Png($logoPath)
                    : new Zend_Pdf_Resource_Image_Jpeg($logoPath);

                $this->currentPage->drawImage(
                    $image, 
                    $x, 
                    $y, 
                    $x + self::LOGO_WIDTH, 
                    $y + self::LOGO_HEIGHT
                );
            }
        } catch (\Exception $e) {
            $this->currentPage->setFont(Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA), 10);
            $this->currentPage->drawText('Error loading logo: ' . $e->getMessage(), 50, $y + 30);
        }
    }

    /**
     * Draw a filled rectangle with text
     */
    protected function drawTextRectangle($startX, $endX, $startY, $endY, $text, $fontSize = 16, $isBold = true)
    {
        // Draw rectangle
        $this->currentPage->setFillColor(new Zend_Pdf_Color_GrayScale(0.75));
        $this->currentPage->setLineColor(new Zend_Pdf_Color_RGB(0, 0, 0));
        $this->currentPage->setLineWidth(1);
        $this->currentPage->drawRectangle($startX, $startY, $endX, $endY, Zend_Pdf_Page::SHAPE_DRAW_FILL_AND_STROKE);

        // Add centered text
        $font = $isBold 
            ? Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA_BOLD)
            : Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA);
        
        $this->currentPage->setFont($font, $fontSize);
        $textWidth = $this->getTextWidth($text, $font, $fontSize);
        $textStartX = $startX + (($endX - $startX) - $textWidth) / 2;
        $textStartY = $endY - (($endY - $startY) / 2) - 7;
        
        $this->currentPage->setFillColor(new Zend_Pdf_Color_RGB(0, 0, 0));
        $this->currentPage->drawText($text, $textStartX, $textStartY, 'UTF-8');
    }

    /**
     * Add company information section
     */
    protected function addCompanyInfo($startY)
    {
        $this->currentPage->setFont(Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA), 9);
        
        $companyInfo = [
            ['20 Rustic Close', 'P.O. Box 2753'],
            ['Briardene Industrial Park', 'Durban'],
            ['Durban', '4000'],
            ['4051', '']
        ];
        
        foreach ($companyInfo as $line) {
            $this->currentPage->drawText($line[0], self::PAGE_MARGIN, $startY);
            if ($line[1]) {
                $this->currentPage->drawText($line[1], 400, $startY);
            }
            $startY -= 9;
        }
        
        $startY -= 9;
        $this->currentPage->drawText('Tel: 031 573 2490', self::PAGE_MARGIN, $startY);
        $this->currentPage->drawText('Vat Reg No: 4270107271', 400, $startY);
        $startY -= 9;
        $this->currentPage->drawText('Fax:', self::PAGE_MARGIN, $startY);
        $this->currentPage->drawText('1935/007335/07', 400, $startY);
        
        return $startY - 9;
    }

    /**
     * Add customer information section
     */
    protected function addCustomerInfo(CartInterface $quote, $startY)
    {
        // Get customer and address information
        $customerName = $quote->getCustomerFirstname() . ' ' . $quote->getCustomerLastname();
        $shippingAddress = $quote->getShippingAddress();
        $billingAddress = $quote->getBillingAddress();
        
        // Use shipping address if available, otherwise billing address
        $address = $shippingAddress ?: $billingAddress;
        
        if (!$quote->getCustomerId() && $address) {
            $customerName = $address->getFirstname() . ' ' . $address->getLastname();
        }
        
        // Extract address details
        $street = $address ? implode(' ', $address->getStreet()) : '';
        $city = $address ? $address->getCity() : '';
        $postcode = $address ? $address->getPostcode() : '';
        $region = $address ? $address->getRegion() : '';
        $telephone = $address ? $address->getTelephone() : '';
        $email = $quote->getCustomerEmail() ?: ($address ? $address->getEmail() : '');
        
        $storeInfo = $this->formatStoreInfo($quote->getData('selected_store_info'));
        $date = date('d-m-Y');
        $quoteId = '94-' . $quote->getId();
        
        // Left side - Customer info (bold)
        $this->currentPage->setFont(Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA_BOLD), 10);
        
        // Customer name
        $this->currentPage->drawText($customerName, self::PAGE_MARGIN, $startY);
        $startY -= 25; // Space after name
        
        // Street address
        if ($street) {
            $this->currentPage->drawText($street, self::PAGE_MARGIN, $startY);
            $startY -= 12;
        }
        
        // City
        if ($city) {
            $this->currentPage->drawText($city, self::PAGE_MARGIN, $startY);
            $startY -= 12;
        }
        
        // Postal code
        if ($postcode) {
            $this->currentPage->drawText($postcode, self::PAGE_MARGIN, $startY);
            $startY -= 12;
        }
        
        // Province/Region
        if ($region) {
            $this->currentPage->drawText($region, self::PAGE_MARGIN, $startY);
            $startY -= 25; // Space after address block
        }
        
        // Phone number
        if ($telephone) {
            $this->currentPage->drawText($telephone, self::PAGE_MARGIN, $startY);
            $startY -= 12;
        }
        
        // Email
        if ($email) {
            $this->currentPage->drawText($email, self::PAGE_MARGIN, $startY);
            $startY -= 12;
        }
        
        // Right side - Quote details
        $rightStartY = $startY + (110); // Reset to top position for right side
        
        // Quote ID
        $this->currentPage->setFont(Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA), 10);
        $this->currentPage->drawText('Quote ID: ', 400, $rightStartY);
        $this->currentPage->setFont(Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA_BOLD), 10);
        $this->currentPage->drawText($quoteId, 445, $rightStartY);
        $rightStartY -= 12;
        
        // Date
        $this->currentPage->setFont(Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA), 10);
        $this->currentPage->drawText('Date: ', 400, $rightStartY);
        $this->currentPage->setFont(Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA_BOLD), 10);
        $this->currentPage->drawText($date, 425, $rightStartY);
        $rightStartY -= 12;
        
        // Store
        $this->currentPage->setFont(Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA), 10);
        $this->currentPage->drawText('Store: ', 400, $rightStartY);
        $this->currentPage->setFont(Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA_BOLD), 10);
        $this->currentPage->drawText($storeInfo, 428, $rightStartY);
        
        return $startY;
    }

    /**
     * Format store information
     */
    protected function formatStoreInfo($storeInfo)
    {
        if (!$storeInfo) {
            return '';
        }
        
        list($storeNumber, $rest) = explode(',', $storeInfo, 2);
        $storeNumber = trim($storeNumber);
        
        $storeName = '';
        if (preg_match('/Gelmar\s+(.+)$/', $storeInfo, $matches)) {
            $storeName = trim($matches[1]);
        }
        
        return $storeNumber . ' - ' . $storeName;
    }

    /**
     * Add product table headers
     */
    protected function addProductTableHeaders($startY)
    {
        $startX = self::PAGE_MARGIN - 10;
        $currentX = $startX;

        $this->currentPage->setFont(
            Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA_BOLD),
            10
        );

        foreach (self::COLUMN_HEADERS as $index => $heading) {
            $currentRectStartX = $currentX;
            $currentRectEndX = $currentX + self::COLUMN_WIDTHS[$index];
            $currentRectStartY = $startY;
            $currentRectEndY = $startY - self::LINE_HEIGHT;

            // Draw column border
            $this->currentPage->setLineColor(new Zend_Pdf_Color_RGB(0, 0, 0));
            $this->currentPage->setLineWidth(1);
            $this->currentPage->drawRectangle(
                $currentRectStartX,
                $currentRectStartY,
                $currentRectEndX,
                $currentRectEndY,
                Zend_Pdf_Page::SHAPE_DRAW_STROKE
            );

            // Center text in column
            $textWidth = strlen($heading) * (self::AVERAGE_CHAR_WIDTH - 0.5);
            $currentTextStartX = $currentRectStartX + (($currentRectEndX - $currentRectStartX) - $textWidth) / 2;
            $currentTextStartY = $currentRectStartY - 11;

            // Manual adjustment for "Unit Incl."
            if ($heading === 'Unit Incl.') {
                $currentTextStartX += 6;
            }

            $this->currentPage->drawText($heading, $currentTextStartX, $currentTextStartY);
            $currentX += self::COLUMN_WIDTHS[$index];
        }

        return $startY - 30;
    }

    /**
     * Helper function to right-align text in column
     */
    protected function getRightAlignedPosition($text, $colIndex, $colX, $font, $fontSize)
    {
        $textWidth = $this->getTextWidth($text, $font, $fontSize);
        $rightEdge = $colX[$colIndex] + self::COLUMN_WIDTHS[$colIndex] - self::TEXT_PADDING;
        return $rightEdge - $textWidth;
    }

    /**
     * Add product items to the PDF
     */
    protected function addProductItems(CartInterface $quote, $startY)
    {
        $colStartX = self::PAGE_MARGIN - 10;
        $colX = [];
        $currentX = $colStartX;
        
        // Precompute column X positions
        foreach (self::COLUMN_WIDTHS as $width) {
            $colX[] = $currentX;
            $currentX += $width;
        }

        $this->currentPage->setFont(
            Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA),
            10
        );

        $numericFont = Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA);
        $numericFontSize = 10;

        foreach ($quote->getAllItems() as $item) {
            $sku = $item->getSku();
            $productName = $item->getName();
            $quantity = (int)$item->getQty();
            $price = $item->getPrice();
            $itemTotal = $price * $quantity;

            // Calculate VAT
            $unitExcl = $price / self::VAT_RATE;
            $unitVat = $price - $unitExcl;
            $vatAmount = $unitVat * $quantity;

            // Wrap product description
            $descStartX = $colX[1] + self::TEXT_PADDING;
            $descWidth = self::COLUMN_WIDTHS[1] + 10;
            $productNameFont = Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA);
            $productNameLines = $this->wrapText($productName, $descWidth, $productNameFont, 10);

            // Calculate required height
            $itemHeight = (count($productNameLines) * 12) + 15;

            // Check for page break (with footer space reserved)
            $startY = $this->checkAndCreateNewPage($startY, $itemHeight + 50, true);

            // Redraw headers if new page
            if ($this->currentPageIndex > 0 && $startY > 700) {
                $startY = $this->addProductTableHeaders($startY - 30);
            }

            $currentLineY = $startY;
            $firstLineY = $startY;

            // Column 1: Item Code
            $this->currentPage->setFont($numericFont, $numericFontSize);
            $this->currentPage->drawText($sku, $colX[0] + self::TEXT_PADDING, $currentLineY);

            // Column 2: Description (wrapped)
            foreach ($productNameLines as $line) {
                $this->currentPage->drawText($line, $descStartX, $currentLineY);
                $currentLineY -= 12;
            }

            // Numeric columns (right-aligned)
            $numericData = [
                2 => (string)$quantity,
                3 => number_format($price, 2),
                4 => number_format($vatAmount, 2),
                5 => number_format($itemTotal, 2)
            ];

            foreach ($numericData as $colIndex => $value) {
                $drawX = $this->getRightAlignedPosition($value, $colIndex, $colX, $numericFont, $numericFontSize);
                $this->currentPage->drawText($value, $drawX, $firstLineY);
            }

            $startY = $currentLineY - 1;
        }

        return $startY;
    }

    /**
     * Add footer content to the current page
     */
    protected function addFooter(CartInterface $quote, $forceNewPage = false)
    {
        // Calculate totals
        $totalInclVat = $quote->getGrandTotal() ?? 0;
        $totalExclVat = $totalInclVat / self::VAT_RATE;
        $vatTotal = $totalInclVat - $totalExclVat;
        
        // If forcing new page or not enough space, create new page
        if ($forceNewPage) {
            $this->createNewPage();
            $startY = 700;
        } else {
            $startY = self::FOOTER_HEIGHT;
        }

        // Draw separator line
        $rectStartX = self::PAGE_MARGIN - 10;
        $rectEndX = 555;
        $lineY = $startY;
        
        $this->currentPage->setLineWidth(1);
        $this->currentPage->setLineColor(new Zend_Pdf_Color_RGB(0, 0, 0));
        $this->currentPage->drawLine($rectStartX, $lineY, $rectEndX, $lineY);

        // Draw warning rectangle without border
        $this->currentPage->setFillColor(new Zend_Pdf_Color_GrayScale(0.75));
        $this->currentPage->drawRectangle(
            $rectStartX,
            $lineY - 2,
            $rectEndX,
            $lineY - 22,
            Zend_Pdf_Page::SHAPE_DRAW_FILL
        );

        // Add centered text
        $font = Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA_BOLD);
        $this->currentPage->setFont($font, 12);
        $text = 'PROFORMA INVOICE ONLY - DO NOT RELEASE STOCK';
        $textWidth = $this->getTextWidth($text, $font, 12);
        $textStartX = $rectStartX + (($rectEndX - $rectStartX) - $textWidth) / 2;
        $textStartY = $lineY - 22 - (($lineY - 22 - ($lineY - 2)) / 2) - 7;
        
        $this->currentPage->setFillColor(new Zend_Pdf_Color_RGB(0, 0, 0));
        $this->currentPage->drawText($text, $textStartX, $textStartY, 'UTF-8');

        $startY -= 30;

        // Draw totals with signature
        $this->drawTotalsWithSignature($totalExclVat, $vatTotal, $totalInclVat, $startY - 20);

        // Add customer terms
        $this->addCustomerTerms($startY - 100);
    }

    /**
     * Draw totals section with signature fields
     */
    protected function drawTotalsWithSignature($totalExclVat, $vatTotal, $totalInclVat, $currentY)
    {
        $labelRightEdge = 470;
        $valueRightEdge = 530;
        $dateLabelX = self::PAGE_MARGIN;
        $dotsStartX = 120;

        $this->currentPage->setFillColor(new Zend_Pdf_Color_RGB(0, 0, 0));

        $drawSummaryLine = function($label, $value, $isBold = false) use (
            &$currentY, $labelRightEdge, $valueRightEdge
        ) {
            $font = $isBold 
                ? Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA_BOLD)
                : Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA);

            $this->currentPage->setFont($font, 10);

            // Right-align label
            $labelWidth = $this->getTextWidth($label, $font, 10);
            $labelX = $labelRightEdge - $labelWidth;
            $this->currentPage->drawText($label, $labelX, $currentY);

            // Right-align value
            $valueText = number_format((float)$value, 2);
            $valueWidth = $this->getTextWidth($valueText, $font, 10);
            $valueX = $valueRightEdge - $valueWidth;
            $this->currentPage->drawText($valueText, $valueX, $currentY);

            $currentY -= 12;
        };

        // Draw totals
        $drawSummaryLine('Total Excl Vat:', $totalExclVat);
        $dateY = $currentY + 12;

        $drawSummaryLine('Vat:', $vatTotal);
        $currentY -= 4;

        // Draw lines around final total
        $lineStartX = $labelRightEdge + 2;
        $lineEndX = $valueRightEdge + 6;
        $this->currentPage->setLineWidth(1);
        $this->currentPage->drawLine($lineStartX, $currentY + 10, $lineEndX, $currentY + 10);

        $drawSummaryLine('Total Incl Vat:', $totalInclVat, true);
        $signatureY = $currentY + 12;

        $this->currentPage->drawLine($lineStartX, $currentY + 8, $lineEndX, $currentY + 8);

        // Draw signature fields
        $this->currentPage->setFont(Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA), 10);
        
        $drawFieldLine = function($label, $y) use ($dateLabelX, $dotsStartX) {
            $this->currentPage->drawText($label, $dateLabelX, $y);
            $this->currentPage->drawText('..........................................', $dotsStartX, $y);
        };

        $drawFieldLine('Date:', $dateY);
        $drawFieldLine('Signature:', $signatureY);
        $drawFieldLine('Name:', $signatureY - 18);
    }

    /**
     * Add customer terms and conditions
     */
    protected function addCustomerTerms($startY)
    {
        $this->currentPage->setFont(Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA), 9);

        $textLines = [
            "I hereby confirm that the order items and quantities are correct.",
            "Prices are valid for 14 days from issue date OR while stocks last.",
            "Please confirm Stock Availability before making payment.",
            "PAYMENT OPTIONS:-",
            "Pay in-store by Cash or Credit/Debit card, to avoid delays in payment processing.",
            "All rejected/returned deliveries will incur a 15% handling charge."
        ];

        $redLineIndices = [1, 2, 4];

        foreach ($textLines as $index => $line) {
            // Set color based on line index
            $color = in_array($index, $redLineIndices) 
                ? new Zend_Pdf_Color_RGB(1, 0, 0) 
                : new Zend_Pdf_Color_RGB(0, 0, 0);
            
            $this->currentPage->setFillColor($color);
            $this->currentPage->drawText($line, self::PAGE_MARGIN, $startY);
            $startY -= 11;
        }

        return $startY;
    }

    /**
     * Main PDF generation method
     */
    public function generate(CartInterface $quote)
    {
        // Initialize PDF
        $this->pdf = new Zend_Pdf();
        $this->currentPage = new Zend_Pdf_Page(Zend_Pdf_Page::SIZE_A4);
        $this->pdf->pages[] = $this->currentPage;

        // Add background image first (so it appears behind everything else)
        $this->addBackgroundImage();

        // Add logo
        $this->addLogo(self::PAGE_MARGIN, 780);

        // Add company information
        $startY = $this->addCompanyInfo(765);

        // Add main title rectangle
        $rectStartX = self::PAGE_MARGIN - 10;
        $rectEndX = 555;
        $this->drawTextRectangle($rectStartX, $rectEndX, $startY, $startY - 25, 'PRO FORMA INVOICE');

        // Add customer information
        $startY = $this->addCustomerInfo($quote, $startY - 50);

        // Add product section
        $startY -= 5;
        $startY = $this->addProductTableHeaders($startY);
        $startY = $this->addProductItems($quote, $startY);

        // Check if we have enough space for footer, if not create new page
        if ($startY <= self::FOOTER_HEIGHT + 50) {
            $this->addFooter($quote, true); // Force new page for footer
        } else {
            $this->addFooter($quote, false); // Add footer to current page
        }

        return $this->pdf;
    }

    /**
     * Save PDF to file
     */
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