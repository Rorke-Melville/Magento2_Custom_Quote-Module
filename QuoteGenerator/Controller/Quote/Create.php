<?php

namespace Gelmar\QuoteGenerator\Controller\Quote;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Checkout\Model\Cart;
use Magento\Framework\View\Result\PageFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Message\ManagerInterface;

class Create extends Action
{
    protected $cart;
    protected $resultPageFactory;
    protected $customerSession;
    protected $messageManager;

    public function __construct(
        Context $context,
        Cart $cart,
        PageFactory $resultPageFactory,
        CustomerSession $customerSession,
        ManagerInterface $messageManager,
    ) {
        $this->cart = $cart;
        $this->resultPageFactory = $resultPageFactory;
        $this->customerSession = $customerSession;
        $this->messageManager = $messageManager;
        parent::__construct($context);
    }

    public function execute()
    {
        try {
            // Check if the user is logged in
            if (!$this->customerSession->isLoggedIn()) {
                // User is not logged in, redirect and display message
                $this->messageManager->addErrorMessage(__('You must be signed in to generate a quote.'));
                $this->_redirect('customer/account/');
                return;
            }
            // Get the current cart
            $cart = $this->cart->getQuote();

            // Check if the cart is empty
            if ($cart && $cart->getItemsCount() > 0) {
                // Pass the quote to the block
                $this->_view->loadLayout();
                $this->_view->getLayout()->getBlock('quote_create_block')->setQuote($cart);
                $this->_view->renderLayout();
            } else {
                // Handle case where cart is empty
                throw new \Magento\Framework\Exception\LocalizedException(__('Your cart is empty.'));
            }
        } catch (\Exception $e) {
            // Log error and display message
            $this->_objectManager->get('Psr\Log\LoggerInterface')->critical($e);
            $this->_redirect('/checkout/cart');
        }
    }
}
