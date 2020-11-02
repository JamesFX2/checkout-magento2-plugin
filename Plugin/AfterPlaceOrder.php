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
 * @copyright 2010-2019 Checkout.com
 * @license   https://opensource.org/licenses/mit-license.html MIT License
 * @link      https://docs.checkout.com/
 */

namespace CheckoutCom\Magento2\Plugin;

use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Class AfterPlaceOrder.
 */
class AfterPlaceOrder
{
    /**
     * @var Session
     */
    public $backendAuthSession;

    /**
     * @var Config
     */
    public $config;

    /**
     * @var WebhookHandlerService
     */
    public $webhookHandler;

    /**
     * AfterPlaceOrder constructor.
     */
    public function __construct(
        \Magento\Backend\Model\Auth\Session $backendAuthSession,
        \CheckoutCom\Magento2\Gateway\Config\Config $config,
        \CheckoutCom\Magento2\Model\Service\WebhookHandlerService $webhookHandler
    ) {
        $this->backendAuthSession = $backendAuthSession;
        $this->config = $config;
        $this->webhookHandler = $webhookHandler;
    }

    /**
     * Disable order email sending on order creation
     */
    public function afterPlace(OrderManagementInterface $subject, OrderInterface $order)
    {
        // Get the method ID
        $methodId = $order->getPayment()->getMethodInstance()->getCode();

        // If can proceed
        if (in_array($methodId, $this->config->getMethodsList())) {
            // Disable the email sending
            $order->setCanSendNewEmailFlag(false);
        }

        return $order;
    }
}
