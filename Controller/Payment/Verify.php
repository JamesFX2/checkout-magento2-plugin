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

namespace CheckoutCom\Magento2\Controller\Payment;

use Checkout\CheckoutApi;
use CheckoutCom\Magento2\Helper\Logger;
use CheckoutCom\Magento2\Helper\Utilities;
use CheckoutCom\Magento2\Model\Service\ApiHandlerService;
use CheckoutCom\Magento2\Model\Service\OrderHandlerService;
use CheckoutCom\Magento2\Model\Service\QuoteHandlerService;
use CheckoutCom\Magento2\Model\Service\TransactionHandlerService;
use CheckoutCom\Magento2\Model\Service\VaultHandlerService;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Class Verify
 *
 * @category  Magento2
 * @package   Checkout.com
 */
class Verify extends Action
{
    /**
     * $messageManager field
     *
     * @var ManagerInterface $messageManager
     */
    public $messageManager;
    /**
     * $transactionHandler field
     *
     * @var TransactionHandlerService $transactionHandler
     */
    public $transactionHandler;
    /**
     * $storeManager field
     *
     * @var StoreManagerInterface $storeManager
     */
    public $storeManager;
    /**
     * $apiHandler field
     *
     * @var CheckoutApi $apiHandler
     */
    public $apiHandler;
    /**
     * $orderHandler field
     *
     * @var OrderHandlerService $orderHandler
     */
    public $orderHandler;
    /**
     * $quoteHandler field
     *
     * @var QuoteHandlerService $quoteHandler
     */
    public $quoteHandler;
    /**
     * $vaultHandler
     *
     * @var VaultHandlerService $vaultHandler
     */
    public $vaultHandler;
    /**
     * $utilities field
     *
     * @var Utilities $utilities
     */
    public $utilities;
    /**
     * $logger field
     *
     * @var Logger $logger
     */
    public $logger;
    /**
     * $session field
     *
     * @var Session $session
     */
    protected $session;

    /**
     * Verify constructor
     *
     * @param Context                   $context
     * @param ManagerInterface          $messageManager
     * @param TransactionHandlerService $transactionHandler
     * @param StoreManagerInterface     $storeManager
     * @param ApiHandlerService         $apiHandler
     * @param OrderHandlerService       $orderHandler
     * @param QuoteHandlerService       $quoteHandler
     * @param VaultHandlerService       $vaultHandler
     * @param Utilities                 $utilities
     * @param Logger                    $logger
     * @param Session                   $session
     */
    public function __construct(
        Context $context,
        ManagerInterface $messageManager,
        TransactionHandlerService $transactionHandler,
        StoreManagerInterface $storeManager,
        ApiHandlerService $apiHandler,
        OrderHandlerService $orderHandler,
        QuoteHandlerService $quoteHandler,
        VaultHandlerService $vaultHandler,
        Utilities $utilities,
        Logger $logger,
        Session $session
    ) {
        parent::__construct($context);

        $this->messageManager     = $messageManager;
        $this->storeManager       = $storeManager;
        $this->apiHandler         = $apiHandler;
        $this->orderHandler       = $orderHandler;
        $this->quoteHandler       = $quoteHandler;
        $this->vaultHandler       = $vaultHandler;
        $this->utilities          = $utilities;
        $this->logger             = $logger;
        $this->session            = $session;
        $this->transactionHandler = $transactionHandler;
    }

    /**
     * Handles the controller method
     *
     * @return ResponseInterface
     * @throws NoSuchEntityException
     */
    public function execute()
    {
        // Return to the cart
        try {
            // Get the session id
            $sessionId = $this->getRequest()->getParam('cko-session-id', null);
            if ($sessionId) {
                // Get the store code
                $storeCode = $this->storeManager->getStore()->getCode();

                // Initialize the API handler
                $api = $this->apiHandler->init($storeCode);

                // Get the payment details
                $response = $api->getPaymentDetails($sessionId);

                // Check for zero dollar auth
                if ($response->status !== "Card Verified") {
                    // Set the method ID
                    $this->methodId = $response->metadata['methodId'];

                    // Find the order from increment id
                    $order = $this->orderHandler->getOrder([
                        'increment_id' => $response->reference,
                    ]);

                    // Process the order
                    if ($this->orderHandler->isOrder($order)) {
                        // Logging
                        $this->logger->display($response);

                        // Process the response
                        if ($api->isValidResponse($response)) {
                            if ($response->source['type'] === 'knet') {
                                $amount = $this->transactionHandler->amountFromGateway(
                                    $response->amount ?? null,
                                    $order
                                );

                                $this->messageManager->addComplexNoticeMessage('knetInfoMessage', [
                                        'postDate'      => $response->source['post_date'] ?? null,
                                        'amount'        => $amount ?? null,
                                        'paymentId'     => $response->source['knet_payment_id'] ?? null,
                                        'transactionId' => $response->source['knet_transaction_id'] ?? null,
                                        'authCode'      => $response->source['auth_code'] ?? null,
                                        'reference'     => $response->source['bank_reference'] ?? null,
                                        'resultCode'    => $response->source['knet_result'] ?? null,
                                    ]);
                            }

                            if (isset($response->metadata['successUrl']) && !str_contains(
                                    $response->metadata['successUrl'],
                                    'checkout_com/payment/verify'
                                )) {
                                return $this->_redirect($response->metadata['successUrl']);
                            } else {
                                return $this->_redirect('checkout/onepage/success', ['_secure' => true]);
                            }
                        } else {
                            // Restore the quote
                            $this->session->restoreQuote();

                            // Add and error message
                            $this->messageManager->addErrorMessage(
                                __('The transaction could not be processed or has been cancelled.')
                            );
                        }
                    } else {
                        // Add an error message
                        $this->messageManager->addErrorMessage(
                            __('Invalid request. No order found.')
                        );
                    }
                } else {
                    // Save the card
                    $this->saveCard($response);

                    // Redirect to the account
                    return $this->_redirect('vault/cards/listaction', ['_secure' => true]);
                }
            } else {
                // Add and error message
                $this->messageManager->addErrorMessage(
                    __('Invalid request. No session ID found.')
                );
            }
        } catch (\Checkout\Library\Exceptions\CheckoutHttpException $e) {
            $this->messageManager->addErrorMessage(
                __($e->getBody())
            );

            return $this->_redirect('checkout/cart', ['_secure' => true]);
        }

        return $this->_redirect('checkout/cart', ['_secure' => true]);
    }

    /**
     * Description saveCard function
     *
     * @param $response
     *
     * @return void
     */
    public function saveCard($response)
    {
        // Save the card
        $success = $this->vaultHandler->setCardToken($response->source['id'])
            ->setCustomerId()
            ->setCustomerEmail()
            ->setResponse($response)
            ->saveCard();

        // Prepare the response UI message
        if ($success) {
            $this->messageManager->addSuccessMessage(
                __('The payment card has been stored successfully.')
            );
        } else {
            $this->messageManager->addErrorMessage(
                __('The card could not be saved.')
            );
        }
    }
}
