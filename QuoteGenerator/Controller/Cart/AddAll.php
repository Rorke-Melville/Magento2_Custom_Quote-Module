<?php

namespace Gelmar\QuoteGenerator\Controller\Cart;

use Magento\Checkout\Model\Cart;
use Magento\Catalog\Model\ProductRepository;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\RedirectFactory;
use Psr\Log\LoggerInterface;

class AddAll extends Action
{
    /**
     * @var Cart
     */
    protected $cart;

    /**
     * @var ProductRepository
     */
    protected $productRepository;

    /**
     * @var RedirectFactory
     */
    protected $resultRedirectFactory;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(
        Context $context,
        Cart $cart,
        ProductRepository $productRepository,
        RedirectFactory $resultRedirectFactory,
        LoggerInterface $logger
    ) {
        $this->cart = $cart;
        $this->productRepository = $productRepository;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->logger = $logger;
        parent::__construct($context);
    }

    public function execute()
    {
        // Get the quote ID from the request
        $quoteId = $this->getRequest()->getParam('quote_id');

        if (!$quoteId) {
            // No quote ID found, redirect back with an error message
            $this->messageManager->addErrorMessage(__('Quote ID is missing.'));
            return $this->_redirect('*/*/');
        }

        try {
            // Load the quote by ID
            $quote = $this->_objectManager->create('Magento\Quote\Model\Quote')->load($quoteId);

            if (!$quote->getId()) {
                // Quote does not exist, redirect back with an error message
                $this->messageManager->addErrorMessage(__('Quote not found.'));
                return $this->_redirect('*/*/');
            }

            // Iterate over each quote item and add it to the cart
            foreach ($quote->getAllItems() as $quoteItem) {
                $productId = $quoteItem->getProductId();
                $product = $this->productRepository->getById($productId);
                $qty = $quoteItem->getQty();

                // Add the product to the cart
                $this->cart->addProduct($product, $qty);
            }

            // Save the cart
            $this->cart->save();

            // Redirect to the checkout page
            $this->messageManager->addSuccessMessage(__('All products from the quote have been added to your cart.'));
            return $this->_redirect('checkout/cart');
        } catch (\Exception $e) {
            // Log error and redirect with error message
            $this->logger->error('Error adding products to cart: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('An error occurred while adding products to your cart.'));
            return $this->_redirect('*/*/');
        }
    }
}
