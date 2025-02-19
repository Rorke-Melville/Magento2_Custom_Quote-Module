<?php
namespace Gelmar\QuoteGenerator\Controller\Quote;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Message\ManagerInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Quote\Model\ResourceModel\Quote\Item\CollectionFactory as QuoteItemCollectionFactory;
use Magento\Catalog\Model\ProductRepository; // Added for loading product objects

class History extends Action
{
    protected $resultPageFactory;
    protected $messageManager;
    protected $logger;
    protected $resourceConnection;
    protected $customerSession;
    protected $quoteItemCollectionFactory;
    protected $productRepository; // Inject ProductRepository for loading products

    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        ManagerInterface $messageManager,
        LoggerInterface $logger,
        ResourceConnection $resourceConnection,
        CustomerSession $customerSession,
        QuoteItemCollectionFactory $quoteItemCollectionFactory,
        ProductRepository $productRepository // Add dependency for ProductRepository
    ) {
        $this->resultPageFactory = $resultPageFactory;
        $this->messageManager = $messageManager;
        $this->logger = $logger;
        $this->resourceConnection = $resourceConnection;
        $this->customerSession = $customerSession;
        $this->quoteItemCollectionFactory = $quoteItemCollectionFactory;
        $this->productRepository = $productRepository; // Initialize ProductRepository
        parent::__construct($context);
    }

    public function execute()
    {
        try {
            $customerId = $this->customerSession->getCustomerId(); // Fetch customer ID from CustomerSession
            if (!$customerId) {
                $this->messageManager->addErrorMessage(__('No customer ID found.'));
                $this->_redirect('customer/account/login');
                return;
            }

            // Fetch all quotes for the customer
            $connection = $this->resourceConnection->getConnection();
            $quoteTable = $connection->getTableName('quote');
            $select = $connection->select()
                ->from($quoteTable, [
                    'entity_id as quote_id',
                    'created_at as quote_date',
                    'grand_total as total_price',
                    'is_active'
                ])
                ->where('customer_id = ?', $customerId);
            $quotes = $connection->fetchAll($select);

            // Add quote items and product images to each quote
            foreach ($quotes as &$quote) {
                $quoteId = $quote['quote_id'];

                // Fetch visible quote items for the current quote
                $quoteItemCollection = $this->quoteItemCollectionFactory->create()
                    ->addFieldToFilter('quote_id', $quoteId)
                    ->addFieldToFilter('parent_item_id', ['null' => true]); // Exclude child items

                $quoteItems = [];
                foreach ($quoteItemCollection as $item) {
                    try {
                        // Load the full product object for the quote item
                        $productId = $item->getProductId();
                        $product = $this->productRepository->getById($productId);

                        // Get the product image URL
                        $imageHelper = $this->_objectManager->get('Magento\Catalog\Helper\Image');
                        $imageUrl = $imageHelper->init($product, 'product_page_image_small')
                            ->setImageFile($product->getImage())
                            ->getUrl();

                        // Add product details to the quote item
                        $quoteItems[] = [
                            'product_name' => $product->getName(),
                            'product_image' => $imageUrl,
                            'product_sku' => $product->getSku(),
                        ];
                    } catch (\Exception $e) {
                        $this->logger->warning('Error loading product for quote item: ' . $e->getMessage());
                        continue; // Skip this item if there's an error
                    }
                }

                // Attach quote items to the quote
                $quote['products'] = $quoteItems; // Use 'products' key for consistency
            }

            // Pass data to the block
            $resultPage = $this->resultPageFactory->create();
            $block = $resultPage->getLayout()->getBlock('quote_history_block');
            if ($block) {
                $block->setData('quotes', $quotes);
                $block->setData('customer_name', $this->customerSession->getCustomer()->getFirstname() . ' ' . $this->customerSession->getCustomer()->getLastname());
            } else {
                $this->messageManager->addErrorMessage(__('Quote block not found.'));
                $this->logger->warning('Quote block not found in layout.');
            }

            return $resultPage;
        } catch (\Exception $e) {
            $this->logger->error('Error fetching quote history: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('An error occurred while fetching quote history.'));
            $this->_redirect('customer/account');
        }
    }
}