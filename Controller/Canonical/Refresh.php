<?php


namespace WeltPixel\AjaxInfiniteScroll\Controller\Canonical;

use Magento\Framework\App\Action\Context;
use Magento\Framework\Json\Helper\Data;
use WeltPixel\AjaxInfiniteScroll\Helper\Data as IasData;
use Psr\Log\LoggerInterface;


class Refresh extends \Magento\Framework\App\Action\Action
{

    /**
     * @var Data
     */
    protected $_jsonHelper;

    /**
     * @var IasData
     */
    protected $_iasHelper;

    /**
     * @var LoggerInterface
     */
    protected $_logger;

    /**
     * Refresh constructor.
     * @param Context $context
     * @param Data $jsonHelper
     * @param IasData $iasHelper
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        Data $jsonHelper,
        IasData $iasHelper,
        LoggerInterface $logger
    ) {
        $this->_jsonHelper = $jsonHelper;
        $this->_iasHelper = $iasHelper;
        $this->resultFactory = $context->getResultFactory();
        $this->_logger = $logger;

        parent::__construct($context);
    }

    protected $resultFactory;

    /**
     * Execute view action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $responseData = ['errors' => true];
        $params = $this->getRequest()->getParams();

        $isAjax = isset($params['is_ajax']) && is_scalar($params['is_ajax']) && $params['is_ajax'];

        /**
         * The urls derived from this value are echoed back and the script puts them into a link
         * element, so only a url belonging to this store is accepted and its fragment is dropped.
         * Without that check any host, and any scheme including javascript:, was reflected, and the
         * fragment is attacker controlled from another origin. A non scalar value also used to
         * reach parse_url() and raise a TypeError, which is an Error and so escaped the catch.
         */
        $currentUrl = $this->_iasHelper->sanitizeRequestUrl($this->getScalarParam($params, 'current_url'));
        $currentCategoryId = (int)$this->getScalarParam($params, 'category_id');

        if ($isAjax && $currentCategoryId && $currentUrl !== '') {
            try {
                $currentPageNo = $this->_iasHelper->getCurrentPageNo($currentUrl);

                $responseData = [
                    'errors' => false,
                    'prev' => $this->_iasHelper->getPrevPageUrl($currentPageNo, $currentUrl),
                    'next' => $this->_iasHelper->getNextPageUrl($currentPageNo, $currentUrl, $currentCategoryId)
                ];
            } catch (\Throwable $e) {
                $this->_logger->critical($e);
                $responseData = ['errors' => true];
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
