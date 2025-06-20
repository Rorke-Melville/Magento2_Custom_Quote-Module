<?php
namespace Gelmar\QuoteGenerator\Controller\Quote;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Magento\Quote\Model\ResourceModel\Quote\CollectionFactory as QuoteCollectionFactory;
use Psr\Log\LoggerInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Controller\Result\RedirectFactory;

class View implements HttpGetActionInterface
{
    protected $context;
    protected $pageFactory;
    protected $quoteCollectionFactory;
    protected $logger;
    protected $messageManager;
    protected $resultRedirectFactory;

    public function __construct(
        Context $context,
        PageFactory $pageFactory,
        QuoteCollectionFactory $quoteCollectionFactory,
        LoggerInterface $logger,
        ManagerInterface $messageManager,
        RedirectFactory $resultRedirectFactory
    ) {
        $this->context = $context;
        $this->pageFactory = $pageFactory;
        $this->quoteCollectionFactory = $quoteCollectionFactory;
        $this->logger = $logger;
        $this->messageManager = $messageManager;
        $this->resultRedirectFactory = $resultRedirectFactory;
    }

    public function execute()
    {
        $this->logger->debug("Quote View action is being triggered.");
        $quoteId = $this->context->getRequest()->getParam('quote_id');
        if (!$quoteId) {
            $this->messageManager->addErrorMessage(__('Quote ID is missing.'));
            return $this->resultRedirectFactory->create()->setPath('quote/quote/history');
        }

        // Fetch the quote data
        $quote = $this->quoteCollectionFactory->create()
            ->addFieldToFilter('entity_id', $quoteId)
            ->getFirstItem();

        if (!$quote->getId()) {
            $this->messageManager->addErrorMessage(__('Quote not found.'));
            return $this->resultRedirectFactory->create()->setPath('quote/quote/history');
        }

        // Fetch associated products (quote items)
        $quoteItems = $quote->getAllItems(); 

        // Pass quote data to the page
        $resultPage = $this->pageFactory->create();
        $resultPage->getLayout()->getBlock('quote.view')
            ->setData('quote', $quote)
            ->setData('quote_items', $quoteItems);

        return $resultPage;
    }
}
