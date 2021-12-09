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

namespace CheckoutCom\Magento2\Controller\Webhook;

use CheckoutCom\Magento2\Gateway\Config\Config;
use CheckoutCom\Magento2\Helper\Logger;
use CheckoutCom\Magento2\Helper\Utilities;
use CheckoutCom\Magento2\Model\Service\ApiHandlerService;
use CheckoutCom\Magento2\Model\Service\OrderHandlerService;
use CheckoutCom\Magento2\Model\Service\PaymentErrorHandlerService;
use CheckoutCom\Magento2\Model\Service\ShopperHandlerService;
use CheckoutCom\Magento2\Model\Service\VaultHandlerService;
use CheckoutCom\Magento2\Model\Service\WebhookHandlerService;
use Exception;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Webapi\Exception as WebException;
use Magento\Framework\Webapi\Rest\Response as WebResponse;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Class Callback
 *
 * @category  Magento2
 * @package   Checkout.com
 */
class Callback extends Action
{
    /**
     * $storeManager field
     *
     * @var StoreManagerInterface $storeManager
     */
    public $storeManager;
    /**
     * $apiHandler field
     *
     * @var apiHandler $apiHandler
     */
    public $apiHandler;
    /**
     * $orderHandler field
     *
     * @var OrderHandlerService $orderHandler
     */
    public $orderHandler;
    /**
     * $shopperHandler field
     *
     * @var ShopperHandlerService $shopperHandler
     */
    public $shopperHandler;
    /**
     * $webhookHandler field
     *
     * @var WebhookHandlerService $webhookHandler
     */
    public $webhookHandler;
    /**
     * $vaultHandler field
     *
     * @var VaultHandlerService $vaultHandler
     */
    public $vaultHandler;
    /**
     * $paymentErrorHandler field
     *
     * @var PaymentErrorHandlerService $paymentErrorHandler
     */
    public $paymentErrorHandler;
    /**
     * $config field
     *
     * @var Config $config
     */
    public $config;
    /**
     * $utilities field
     *
     * @var Utilities $utilities
     */
    protected $utilities;
    /**
     * $scopeConfig field
     *
     * @var ScopeConfigInterface $scopeConfig
     */
    public $scopeConfig;
    /**
     * $logger field
     *
     * @var Logger $logger
     */
    public $logger;

    /**
     * Callback constructor
     *
     * @param Context                    $context
     * @param StoreManagerInterface      $storeManager
     * @param ScopeConfigInterface        $scopeConfig
     * @param ApiHandlerService          $apiHandler
     * @param OrderHandlerService        $orderHandler
     * @param ShopperHandlerService      $shopperHandler
     * @param WebhookHandlerService      $webhookHandler
     * @param VaultHandlerService        $vaultHandler
     * @param PaymentErrorHandlerService $paymentErrorHandler
     * @param Config                      $config
     * @param Utilities                  $utilities
     * @param Logger                     $logger
     */
    public function __construct(
        Context $context,
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig,
        ApiHandlerService $apiHandler,
        OrderHandlerService $orderHandler,
        ShopperHandlerService $shopperHandler,
        WebhookHandlerService $webhookHandler,
        VaultHandlerService $vaultHandler,
        PaymentErrorHandlerService $paymentErrorHandler,
        Config $config,
        Utilities $utilities,
        Logger $logger
    ) {
        parent::__construct($context);

        $this->storeManager        = $storeManager;
        $this->scopeConfig          = $scopeConfig;
        $this->apiHandler          = $apiHandler;
        $this->orderHandler        = $orderHandler;
        $this->shopperHandler      = $shopperHandler;
        $this->webhookHandler      = $webhookHandler;
        $this->vaultHandler        = $vaultHandler;
        $this->paymentErrorHandler = $paymentErrorHandler;
        $this->config               = $config;
        $this->utilities           = $utilities;
        $this->logger              = $logger;
    }

    /**
     * Handles the controller method
     *
     * @return ResponseInterface|Json|ResultInterface|void
     */
    public function execute()
    {
        // Prepare the response handler
        $resultFactory = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        try {
            // Set the payload data
            $this->payload = $this->getPayload();

            // Process the request
            if ($this->config->isValidAuth('psk')) {
                // Filter out verification requests
                if ($this->payload->type !== "card_verified") {
                    // Process the request
                    if (isset($this->payload->data->id)) {
                        // Get the store code
                        $storeCode = $this->storeManager->getStore()->getCode();

                        // Initialize the API handler
                        $api = $this->apiHandler->init($storeCode);

                        // Get the payment details
                        $response = $api->getPaymentDetails($this->payload->data->id);

                        if (isset($response->reference)) {
                            // Find the order from increment id
                            $order = $this->orderHandler->getOrder([
                                'increment_id' => $response->reference,
                            ]);

                            // Process the order
                            if ($this->orderHandler->isOrder($order)) {
                                if ($api->isValidResponse($response)) {
                                    // Handle the save card request
                                    if ($this->cardNeedsSaving()) {
                                        $this->saveCard($response);
                                    }

                                    // Clean the webhooks table
                                    $clean = $this->scopeConfig->getValue(
                                        'settings/checkoutcom_configuration/webhooks_table_clean',
                                        ScopeInterface::SCOPE_STORE
                                    );

                                    $cleanOn = $this->scopeConfig->getValue(
                                        'settings/checkoutcom_configuration/webhooks_clean_on',
                                        ScopeInterface::SCOPE_STORE
                                    );

                                    // Save the webhook
                                    $this->webhookHandler->processSingleWebhook(
                                        $order,
                                        $this->payload
                                    );

                                    if ($clean && $cleanOn == 'webhook') {
                                        $this->webhookHandler->clean();
                                    }
                                } else {
                                    // Log the payment error
                                    $this->paymentErrorHandler->logError(
                                        $this->payload,
                                        $order
                                    );
                                }
                                // Set a valid response
                                $resultFactory->setHttpResponseCode(WebResponse::HTTP_OK);

                                // Return the 200 success response
                                return $resultFactory->setData([
                                    'result' => __('Webhook and order successfully processed.'),
                                ]);
                            } else {
                                $resultFactory->setHttpResponseCode(WebException::HTTP_INTERNAL_ERROR);

                                return $resultFactory->setData([
                                    'error_message' => __(
                                        'The order creation failed. Please check the error logs.'
                                    ),
                                ]);
                            }
                        } else {
                            $resultFactory->setHttpResponseCode(WebException::HTTP_BAD_REQUEST);

                            return $resultFactory->setData(['error_message' => __('The webhook response is invalid.')]);
                        }
                    } else {
                        $resultFactory->setHttpResponseCode(WebException::HTTP_BAD_REQUEST);

                        return $resultFactory->setData(
                            ['error_message' => __('The webhook payment response is invalid.')]);
                    }
                }
            } else {
                $resultFactory->setHttpResponseCode(WebException::HTTP_UNAUTHORIZED);

                return $resultFactory->setData([
                    'error_message' => __('Unauthorized request. No matching private shared key.'),
                ]);
            }
        } catch (Exception $e) {
            // Throw 400 error for gateway retry mechanism
            $resultFactory->setHttpResponseCode(WebException::HTTP_BAD_REQUEST);
            $this->logger->write($e->getMessage());

            return $resultFactory->setData([
                'error_message' => __(
                    'There was an error processing the webhook. Please check the error logs.'
                ),
            ]);
        }
    }

    /**
     * Get the request payload
     *
     * @return mixed
     */
    public function getPayload()
    {
        $this->logger->additional($this->getRequest()->getContent(), 'webhook');

        return json_decode($this->getRequest()->getContent());
    }

    /**
     * Check if the card needs saving
     *
     * @return bool
     */
    public function cardNeedsSaving()
    {
        return isset($this->payload->data->metadata->saveCard) && (int)$this->payload->data->metadata->saveCard == 1 && isset($this->payload->data->metadata->customerId) && (int)$this->payload->data->metadata->customerId > 0 && isset($this->payload->data->source->id) && !empty($this->payload->data->source->id);
    }

    /**
     * Save a card
     *
     * @param $response
     *
     * @return bool
     */
    public function saveCard($response)
    {
        // Get the customer
        $customer = $this->shopperHandler->getCustomerData(['id' => $this->payload->data->metadata->customerId]);

        // Save the card
        $success = $this->vaultHandler->setCardToken($this->payload->data->source->id)->setCustomerId(
                $customer->getId()
            )->setCustomerEmail($customer->getEmail())->setResponse($response)->saveCard();

        return $success;
    }
}
