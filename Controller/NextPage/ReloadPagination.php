<?php


namespace WeltPixel\AjaxInfiniteScroll\Controller\NextPage;

use Magento\Framework\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Json\Helper\Data;
use Psr\Log\LoggerInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;


class ReloadPagination extends \Magento\Framework\App\Action\Action
{

    /**
     * @var Data
     */
    protected $_jsonHelper;

    /**
     * @var LoggerInterface
     */
    protected $_logger;

    /**
     * @var CategoryRepositoryInterface
     */
    protected $_categoryRepository;

    /**
     * @var PageFactory
     */
    protected $_resultPageFactory;

    /**
     * @var StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * @var \Magento\Framework\Controller\ResultFactory
     */
    protected $resultFactory;


    /**
     * ReloadPagination constructor.
     * @param Context $context
     * @param PageFactory $resultPageFactory
     * @param Data $jsonHelper
     * @param LoggerInterface $logger
     * @param CategoryRepositoryInterface $categoryRepository
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        Data $jsonHelper,
        LoggerInterface $logger,
        CategoryRepositoryInterface $categoryRepository,
        StoreManagerInterface $storeManager


    ) {
        $this->_resultPageFactory = $resultPageFactory;
        $this->_jsonHelper = $jsonHelper;
        $this->resultFactory = $context->getResultFactory();
        $this->_logger = $logger;
        $this->_categoryRepository = $categoryRepository;
        $this->_storeManager = $storeManager;
        parent::__construct($context);


    }

    /**
     * Execute view action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $responseData = [];
        $params = $this->getRequest()->getParams();

        $isAjax = isset($params['is_ajax']) && is_scalar($params['is_ajax']) && $params['is_ajax'];

        if ($isAjax) {
            /**
             * All of this used to run outside the try below. A parameter submitted as an array
             * reached explode() or an array offset and raised a TypeError, which is an Error rather
             * than an Exception and so was not caught, and a missing pager_url or p, or an unknown
             * category id, threw for the same reason. The endpoint is anonymous, so each of those
             * was a 500 and a report file holding the request. A bad request now answers errors.
             */
            try {
                $categoryId = (int)$this->getScalarParam($params, 'category_id');
                $page = (int)$this->getScalarParam($params, 'p');
                $filterParams = $this->_getUrlFilterParams($this->getScalarParam($params, 'pager_url'));

                $category = $categoryId
                    ? $this->_categoryRepository->get($categoryId, $this->_storeManager->getStore()->getId())
                    : false;

                if ($category) {
                    $layout = $this->_resultPageFactory->create()->getLayout();
                    $productList = $layout->createBlock('Magento\Catalog\Block\Product\ListProduct');

                    $collection = $productList->setCategoryId($category->getId())
                        ->injectAttributeFilters($filterParams)
                        ->getLoadedProductCollection();
                    $collection = $collection->setCurPage($page > 0 ? $page : 1);

                    $pagerBlock = $layout
                        ->createBlock('Magento\Catalog\Block\Product\Widget\Html\Pager')
                        ->setTemplate('Magento_Theme::html/pager.phtml')
                        ->setUseContainer(false)
                        ->setCollection($collection);

                    $toolbarBlock = $layout
                        ->createBlock('Magento\Catalog\Block\Product\ProductList\Toolbar')
                        ->setTemplate('Magento_Catalog::product/list/toolbar.phtml')
                        ->setCollection($collection);

                    $responseData = [
                        'errors' => false,
                        'pager' => $pagerBlock->toHtml(),
                        'toolbar' => $toolbarBlock->toHtml()
                    ];
                } else {
                    $responseData = [
                        'errors' => true
                    ];
                }
            } catch (\Throwable $e) {
                $this->_logger->critical($e);
                $responseData = [
                    'errors' => true
                ];
            }
        }

        try {
            return $this->jsonResponse($responseData);
        } catch (\Throwable $e) {
            /** The message is logged rather than handed to the caller */
            $this->_logger->critical($e);
            return $this->jsonResponse(['errors' => true]);
        }
    }

    /**
     * Read a request parameter that is about to be used as a string.
     *
     * Anything that is not a scalar becomes an empty string, so an array cannot reach a string
     * function and raise a TypeError.
     *
     * @param array $params
     * @param string $key
     * @return string
     */
    protected function getScalarParam(array $params, $key)
    {
        return isset($params[$key]) && is_scalar($params[$key]) ? (string)$params[$key] : '';
    }


    /**
     * @param $urlParam
     * @return array
     */
    protected function _getUrlFilterParams($urlParam) {
        $params = [];
        $url = explode("?", (string)$urlParam);
        $urlParamArr = (isset($url[1])) ? explode("&", $url[1]) : false;
        if(!$urlParamArr) {
            return $params;
        }
        foreach($urlParamArr as $urlParamPair) {
            $paramArr = explode('=', $urlParamPair);
            /**
             * A query part carrying no "=" has no index 1, which used to be an undefined offset and
             * therefore a 500 on an anonymous request. Such a part is skipped now, as is an empty
             * name. The loop variable no longer shadows the argument either.
             */
            if ($paramArr[0] === '' || $paramArr[0] == 'q' || !isset($paramArr[1])) {
                continue;
            }
            $params[$paramArr[0]] = explode(',', urldecode($paramArr[1]));
        }

        return $params;
    }

    /**
     * Create json response
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function jsonResponse($response = '')
    {
        return $this->getResponse()->representJson(
            $this->_jsonHelper->jsonEncode($response)
        );
    }
}
