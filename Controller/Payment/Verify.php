<?php

namespace CheckoutCom\Magento2\Controller\Payment;

use Magento\Framework\App\Action\Context;
use Magento\Checkout\Model\Session;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Exception\LocalizedException;
use CheckoutCom\Magento2\Gateway\Config\Config as GatewayConfig;
use CheckoutCom\Magento2\Model\Service\OrderService;
use CheckoutCom\Magento2\Model\Service\VerifyPaymentService;
use CheckoutCom\Magento2\Model\Service\StoreCardService;
use CheckoutCom\Magento2\Model\Factory\VaultTokenFactory;
use CheckoutCom\Magento2\Model\Ui\ConfigProvider;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;

class Verify extends AbstractAction {

    /**
     * @var Session
     */
    protected $session;

    /**
     * @var VerifyPaymentService
     */
    protected $verifyPaymentService;

    /**
     * @var OrderService
     */
    protected $orderService;

    /**
     * @var StoreCardService 
     */
    protected $storeCardService;
    
    /**
     * @var Session 
     */
    protected $customerSession;
    
    /**
     * @var VaultTokenFactory 
     */
    protected $vaultTokenFactory;
    
    /**
     * @var PaymentTokenRepository 
     */
    protected $paymentTokenRepository;

    /**
     * @var ResultRedirect 
     */
    protected $redirect;

    /**
     * @var OrderSender
     */
    private $orderSender;

    protected $quoteManagement;

    /**
     * Verify constructor.
     * @param Context $context
     * @param Session $session
     * @param GatewayConfig $gatewayConfig
     * @param VerifyPaymentService $verifyPaymentService
     * @param OrderService $orderService
     * @param StoreCardService $storeCardService
     * @param CustomerSession $customerSession
     * @param VaultTokenFactory $vaultTokenFactory
     * @param PaymentTokenRepositoryInterface $paymentTokenRepository
     * @param OrderSender $orderSender
     */
    public function __construct(
            Context $context, 
            Session $session, 
            GatewayConfig $gatewayConfig, 
            VerifyPaymentService $verifyPaymentService, 
            OrderService $orderService, 
            StoreCardService $storeCardService, 
            CustomerSession $customerSession, 
            VaultTokenFactory $vaultTokenFactory, 
            PaymentTokenRepositoryInterface $paymentTokenRepository,
            QuoteManagement $quoteManagement,
            OrderSender $orderSender
          ) 
        {
        parent::__construct($context, $gatewayConfig);

        $this->quoteManagement      = $quoteManagement;
        $this->session              = $session;
        $this->gatewayConfig        = $gatewayConfig;
        $this->verifyPaymentService = $verifyPaymentService;
        $this->orderService         = $orderService;
        $this->storeCardService     = $storeCardService;
        $this->customerSession      = $customerSession;
        $this->vaultTokenFactory    = $vaultTokenFactory;
        $this->paymentTokenRepository   = $paymentTokenRepository;
        $this->orderSender          = $orderSender;
        $this->redirect = $this->getResultRedirect();
   }

    /**
     * Handles the controller method.
     *
     * @return \Magento\Framework\Controller\Result\Redirect
     * @throws LocalizedException
     */
    public function execute() {

        // Get the payment token from response
        $paymentToken = $this->extractPaymentToken();

        // Finalize the process
        return $this->finalizeProcess($paymentToken);

    }

    public function finalizeProcess($paymentToken) {

        // Process the gateway response
        $response = $this->verifyPaymentService->verifyPayment($paymentToken);

        // If it's an alternative payment
        if ((int) $response['chargeMode'] == 3) {
            if ((int) $response['responseCode'] == 10000) {
                // Place a local payment order
                $this->placeLocalPaymentOrder();
            }
            else {
                $this->messageManager->addErrorMessage($response['responseMessage']);                
            }
        }

        // Else proceed normally for 3D Secure
        else {

            // Check for saving card
            if (isset($response['description']) && $response['description'] == 'Saving new card') {
                return $this->vaultCardAfterThreeDSecure( $response );
            }

            // Check for declined transactions
            if ($response['status'] === 'Declined') {
                throw new LocalizedException(__('The transaction has been declined.'));
            }

            // Update the order information
            try {

                // Redirect to the success page
                return $this->redirect->setPath('checkout/onepage/success', ['_secure' => true]);

            } catch (\Exception $e) {
                $this->messageManager->addExceptionMessage($e, $e->getMessage());
            }

        }

        // Redirect to cart by default if the order validation fails
        return $this->redirect->setPath('checkout/onepage/success', ['_secure' => true]);
    }

    public function placeLocalPaymentOrder() {

        // Get the quote from session
        $quote = $this->session->getQuote();

        // Prepare the quote in session (required for success page redirection)
        $this->session
        ->setLastQuoteId($quote->getId())
        ->setLastSuccessQuoteId($quote->getId())
        ->clearHelperData();

        // Set payment
        $payment = $quote->getPayment();
        $payment->setMethod(ConfigProvider::CODE);
        $quote->save();

        // Save the quote
        $quote->collectTotals()->save();

        try {

            // Create order from quote
            $order = $this->quoteManagement->submit($quote);
            
            // Prepare the order in session (required for success page redirection)
            if ($order) {
                    $this->session->setLastOrderId($order->getId())
                                       ->setLastRealOrderId($order->getIncrementId())
                                       ->setLastOrderStatus($order->getStatus());
            }

            // Update order status
            $order->setState('new');
            $order->setStatus($this->gatewayConfig->getNewOrderStatus());

            // Set email sent
            $order->setEmailSent(1);

            // Save the order
            $order->save();

            // Send email
            $this->orderSender->send($order);

            // Redirect to the success page
            return $this->redirect->setPath('checkout/onepage/success', ['_secure' => true]);

        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, $e->getMessage());
        }

    }

    public function extractPaymentToken() {

        // Get the gateway response from session if exists
        $gatewayResponse = $this->session->getGatewayResponse();

        // Check if there is a payment token sent in url
        $ckoPaymentToken = $this->getRequest()->getParam('cko-payment-token');

        // return the found payment token
        return $ckoPaymentToken ? $ckoPaymentToken : $gatewayResponse['id'];
    }

    /**
     * Performs 3-D Secure method when adding new card.
     *
     * @param array $response
     * @return void
     */
    public function vaultCardAfterThreeDSecure( array $response ){
        $cardToken = $response['card']['id'];
        
        $cardData = [];
        $cardData['expiryMonth']   = $response['card']['expiryMonth'];
        $cardData['expiryYear']    = $response['card']['expiryYear'];
        $cardData['last4']         = $response['card']['last4'];
        $cardData['paymentMethod'] = $response['card']['paymentMethod'];
        
        try {
            $paymentToken = $this->vaultTokenFactory->create($cardData, $this->customerSession->getCustomer()->getId());
            $paymentToken->setGatewayToken($cardToken);
            $paymentToken->setIsVisible(true);

            $this->paymentTokenRepository->save($paymentToken);
        } 
        catch (\Exception $ex) {
            $this->messageManager->addErrorMessage( $ex->getMessage() );
        }
        
        $this->messageManager->addSuccessMessage( __('Credit Card has been stored successfully') );
        
        return $this->_redirect('vault/cards/listaction/');
    }
}
