<?php

/**
 * PAYONE OXID Connector is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * PAYONE OXID Connector is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with PAYONE OXID Connector.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @link          http://www.payone.de
 * @copyright (C) Payone GmbH
 * @version       OXID eShop CE
 */

namespace Fatchip\PayOne\Application\Helper;

class PayPal extends Base
{
    const PPE_EXPRESS = 'fcpopaypal_express';
    const PPE_V2_EXPRESS = 'fcpopaypalv2_express';

    /**
     * @var self
     */
    protected static $oInstance;

    /**
     * Locale codes supported by misc images (marks, shortcuts etc)
     *
     * @var array
     */
    protected $aSupportedLocales = [
        'de_DE',
        'en_AU',
        'en_GB',
        'en_US',
        'es_ES',
        'es_XC',
        'fr_FR',
        'fr_XC',
        'it_IT',
        'ja_JP',
        'nl_NL',
        'pl_PL',
        'zh_CN',
        'zh_XC',
    ];

    /**
     * Create instance of paypal helper singleton
     *
     * @return self
     */
    public static function getInstance()
    {
        if (self::$oInstance === null) {
            self::$oInstance = oxNew(self::class);
        }
        return self::$oInstance;
    }

    /**
     * Resets singleton class
     * Needed for unit testing
     *
     * @return void
     */
    public static function destroyInstance()
    {
        self::$oInstance = null;
    }

    /**
     * @return bool
     */
    public function showBNPLButton()
    {
        $blReturn = false;
        if ((bool)$this->getMainHelper()->fcpoGetConfig()->getConfigParam('blFCPOPayPalV2BNPL') === true) {
            $blReturn = true;
        }
        return $blReturn;
    }

    /**
     * @return string
     */
    protected function getIntent()
    {
        return "authorize"; // authorize = preauthorize // capture = authorize but Payone said to always use authorize
    }

    /**
     * @return string
     */
    protected function getCurrency()
    {
        return $this->getMainHelper()->fcpoGetSession()->getBasket()->getBasketCurrency()->name;
    }

    /**
     * @return string
     */
    protected function getMerchantId()
    {
        $sMerchantId = "3QK84QGGJE5HW"; // Default for testmode (fixed)
        if (Payment::getInstance()->isLiveMode(self::PPE_V2_EXPRESS)) {
            $sMerchantId = $this->getMainHelper()->fcpoGetConfig()->getConfigParam('blFCPOPayPalV2MerchantID');
        }
        return $sMerchantId;
    }

    /**
     * @return string
     */
    protected function getClientId()
    {
        $sClientId = "AUn5n-4qxBUkdzQBv6f8yd8F4AWdEvV6nLzbAifDILhKGCjOS62qQLiKbUbpIKH_O2Z3OL8CvX7ucZfh"; // Default for testmode (fixed)
        if (Payment::getInstance()->isLiveMode(self::PPE_V2_EXPRESS)) {
            $sClientId = "AVNBj3ypjSFZ8jE7shhaY2mVydsWsSrjmHk0qJxmgJoWgHESqyoG35jLOhH3GzgEPHmw7dMFnspH6vim"; // Livemode (fixed)
        }
        return $sClientId;
    }

    /**
     * Check whether specified locale code is supported. Fallback to en_US
     *
     * @param  string $sLocale
     * @return string
     */
    protected function getSupportedLocaleCode($sLocale = null)
    {
        if (!$sLocale || !in_array($sLocale, $this->aSupportedLocales)) {
            return 'en_US';
        }
        return $sLocale;
    }

    /**
     * @return string
     */
    protected function getLocale()
    {
        $sCurrentLocal = $this->getMainHelper()->fcpoGetLang()->translateString('FCPO_LOCALE', null, false);
        return $this->getSupportedLocaleCode($sCurrentLocal);
    }

    /**
     * @return string
     */
    public function getJavascriptUrl()
    {
        $sUrl = "https://www.paypal.com/sdk/js?client-id=".$this->getClientId()."&merchant-id=".$this->getMerchantId()."&currency=".$this->getCurrency()."&intent=".$this->getIntent()."&locale=".$this->getLocale()."&commit=false&vault=false&disable-funding=card,sepa,bancontact";
        if ($this->showBNPLButton() === true) {
            $sUrl .= "&enable-funding=paylater";
        }
        return $sUrl;
    }

    /**
     * @return string
     */
    public function getButtonColor()
    {
        return $this->getMainHelper()->fcpoGetConfig()->getConfigParam('blFCPOPayPalV2ButtonColor');
    }

    /**
     * @return string
     */
    public function getButtonShape()
    {
        return $this->getMainHelper()->fcpoGetConfig()->getConfigParam('blFCPOPayPalV2ButtonShape');
    }
}