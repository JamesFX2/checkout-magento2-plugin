<?php
/**
 * Checkout.com
 * Authorized and regulated as an electronic money institution
 * by the UK Financial Conduct Authority (FCA) under number 900816.
 *
 * PHP version 7
 *
 * @category  Magento2
 * @package   Checkout.com
 * @author    Platforms Development Team <platforms@checkout.com>
 * @copyright 2010-present Checkout.com
 * @license   https://opensource.org/licenses/mit-license.html MIT License
 * @link      https://docs.checkout.com/
 */

namespace CheckoutCom\Magento2\Observer\Backend;

use CheckoutCom\Magento2\Gateway\Config\Config;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;

/**
 * Class OrderAfterCancel
 *
 * @category  Magento2
 * @package   Checkout.com
 */
class OrderAfterCancel implements ObserverInterface
{
    /**
     * $backendAuthSession field
     *
     * @var Session $backendAuthSession
     */
    public $backendAuthSession;
    /**
     * $request field
     *
     * @var RequestInterface $request
     */
    public $request;
    /**
     * $config field
     *
     * @var Config $config
     */
    public $config;
    /**
     * $orderManagement field
     *
     * @var OrderManagementInterface $orderManagement
     */
    public $orderManagement;
    /**
     * $orderModel field
     *
     * @var Order $orderModel
     */
    public $orderModel;
    /**
     * $orderStatusHistoryRepository field
     *
     * @var OrderStatusHistoryRepositoryInterface $orderStatusHistoryRepository
     */
    private $orderStatusHistoryRepository;

    /**
     * OrderAfterCancel constructor
     *
     * @param Session                               $backendAuthSession
     * @param RequestInterface                      $request
     * @param Config                                $config
     * @param OrderManagementInterface              $orderManagement
     * @param Order                                 $orderModel
     * @param OrderStatusHistoryRepositoryInterface $orderStatusHistoryRepository
     */
    public function __construct(
        Session $backendAuthSession,
        RequestInterface $request,
        Config $config,
        OrderManagementInterface $orderManagement,
        Order $orderModel,
        OrderStatusHistoryRepositoryInterface $orderStatusHistoryRepository
    ) {
        $this->backendAuthSession           = $backendAuthSession;
        $this->request                      = $request;
        $this->config                       = $config;
        $this->orderManagement              = $orderManagement;
        $this->orderModel                   = $orderModel;
        $this->orderStatusHistoryRepository = $orderStatusHistoryRepository;
    }

    /**
     * Run the observer
     *
     * @param Observer $observer
     *
     * @return $this|void
     * @throws LocalizedException
     */
    public function execute(Observer $observer)
    {
        if ($this->backendAuthSession->isLoggedIn()) {
            /** @var Payment $payment */
            $payment  = $observer->getEvent()->getPayment();
            $order    = $payment->getOrder();
            $methodId = $order->getPayment()->getMethodInstance()->getCode();

            if (in_array($methodId, $this->config->getMethodsList())) {
                $orderComments = $order->getStatusHistories();
                $orderComment  = array_pop($orderComments);
                $orderComment->setData('status', 'canceled');

                $this->orderStatusHistoryRepository->save($orderComment);
            }

            return $this;
        }
    }
}
