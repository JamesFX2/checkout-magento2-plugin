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

namespace CheckoutCom\Magento2\Plugin;

use Magento\Framework\View\Asset\Minification;

/**
 * Class MinificationExclude
 *
 * @category  Magento2
 * @package   Checkout.com
 */
class MinificationExclude
{
    /**
     * Exclude remote URLs from minification
     *
     * @param Minification $subject
     * @param array        $result
     * @param              $contentType
     *
     * @return array
     */
    public function afterGetExcludes(Minification $subject, array $result, $contentType)
    {
        if ($contentType == 'js') {
            $result[] = 'https://cdn.checkout.com/js/framesv2.min.js';
            $result[] = 'https://x.klarnacdn.net/kp/lib/v1/api.js';
            $result[] = 'https://pay.google.com/gp/p/js/pay.js';
        }

        return $result;
    }
}
