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

namespace CheckoutCom\Magento2\Model\Config\Backend\Source;

/**
 * Class ConfigAlternativePayments
 */
class ConfigAlternativePayments implements \Magento\Framework\Option\ArrayInterface
{

    /**
     * @var Config
     */
    protected $config;

    /**
     * ConfigAlternativePayments constructor
     */
    public function __construct(
        \CheckoutCom\Magento2\Gateway\Config\Loader $configLoader
    ) {
        $this->configLoader = $configLoader->init();
    }

    /**
     * {@inheritdoc}
     */
    public function toOptionArray()
    {
        return $this->configLoader->data['settings']['checkoutcom_configuration']['apm_list'];
    }
}
