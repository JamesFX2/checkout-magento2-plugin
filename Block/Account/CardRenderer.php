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

namespace CheckoutCom\Magento2\Block\Account;

use CheckoutCom\Magento2\Gateway\Config\Config;
use CheckoutCom\Magento2\Model\Service\CardHandlerService;
use Magento\Framework\View\Element\Template\Context;
use Magento\Payment\Model\CcConfigProvider;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Block\AbstractCardRenderer;

/**
 * Class CardRenderer
 *
 * @category  Magento2
 * @package   Checkout.com
 */
class CardRenderer extends AbstractCardRenderer
{
    /**
     * $config field
     *
     * @var Config $config
     */
    public $config;
    /**
     * $cardHandler field
     *
     * @var CardHandlerService $cardHandler
     */
    public $cardHandler;

    /**
     * CardRenderer constructor
     *
     * @param Context            $context
     * @param CcConfigProvider   $iconsProvider
     * @param Config             $config
     * @param CardHandlerService $cardHandler
     * @param array              $data
     */
    public function __construct(
        Context $context,
        CcConfigProvider $iconsProvider,
        Config $config,
        CardHandlerService $cardHandler,
        array $data
    ) {
        parent::__construct($context, $iconsProvider, $data);

        $this->config      = $config;
        $this->cardHandler = $cardHandler;
    }

    /**
     * Returns 4 last digits from the credit card number.
     *
     * @return string
     */
    public function getNumberLast4Digits()
    {
        return $this->getTokenDetails()['maskedCC'];
    }

    /**
     * Returns the credit card expiration date.
     *
     * @return string
     */
    public function getExpDate()
    {
        return $this->getTokenDetails()['expirationDate'];
    }

    /**
     * Determines if can render the given token.
     *
     * @param PaymentTokenInterface $token
     *
     * @return boolean
     */
    public function canRender(PaymentTokenInterface $token)
    {
        return $token->getPaymentMethodCode() === 'checkoutcom_vault';
    }

    /**
     * Returns the credit card type.
     *
     * @return string
     */
    public function getCardType()
    {
        return $this->getTokenDetails()['type'];
    }

    /**
     * Returns the url to the CC icon.
     *
     * @return string
     */
    public function getIconUrl()
    {
        return $this->getIconForType($this->getCardType())['url'];
    }

    /**
     * Returns the icon height in pixels.
     *
     * @return int
     */
    public function getIconHeight()
    {
        return $this->getIconForType($this->getCardType())['height'];
    }

    /**
     * Returns the icon width in pixels.
     *
     * @return int
     */
    public function getIconWidth()
    {
        return $this->getIconForType($this->getCardType())['width'];
    }
}
