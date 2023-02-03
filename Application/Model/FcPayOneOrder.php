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

namespace Fatchip\PayOne\Application\Model;

use Exception;
use Fatchip\PayOne\Lib\FcPoHelper;
use Fatchip\PayOne\Lib\FcPoRequest;
use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\Country;
use OxidEsales\Eshop\Application\Model\Payment;
use OxidEsales\Eshop\Application\Model\PaymentGateway;
use OxidEsales\Eshop\Application\Model\Shop;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Application\Model\UserPayment;
use OxidEsales\Eshop\Core\Base;
use OxidEsales\Eshop\Core\Counter;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Email;
use OxidEsales\Eshop\Core\Exception\ArticleInputException;
use OxidEsales\Eshop\Core\Exception\NoArticleException;
use OxidEsales\Eshop\Core\Exception\OutOfStockException;
use OxidEsales\Eshop\Core\Field;
use OxidEsales\Eshop\Core\Language;
use OxidEsales\Eshop\Core\Model\ListModel;
use OxidEsales\Eshop\Core\Str;
use OxidEsales\Eshop\Core\ViewConfig;

class FcPayOneOrder extends FcPayOneOrder_parent
{
    const FCPO_AMAZON_ERROR_TRANSACTION_TIMED_OUT = 980;
    const FCPO_AMAZON_ERROR_INVALID_PAYMENT_METHOD = 981;
    const FCPO_AMAZON_ERROR_REJECTED = 982;
    const FCPO_AMAZON_ERROR_PROCESSING_FAILURE = 983;
    const FCPO_AMAZON_ERROR_BUYER_EQUALS_SELLER = 984;
    const FCPO_AMAZON_ERROR_PAYMENT_NOT_ALLOWED = 985;
    const FCPO_AMAZON_ERROR_PAYMENT_PLAN_NOT_SET = 986;
    const FCPO_AMAZON_ERROR_SHIPPING_ADDRESS_NOT_SET = 987;
    const FCPO_AMAZON_ERROR_900 = 900;

    /**
     * Helper object for dealing with different shop versions
     *
     * @var object
     */
    protected $_oFcpoHelper = null;

    /**
     * Database instance
     *
     * @var object
     */
    protected $_oFcpoDb = null;

    /**
     * Array with all reponse paramaters from the API order request
     *
     * @var array
     */
    protected $_aResponse = null;

    /**
     * Array with all request parameters from API order request
     *
     * @var array
     */
    protected $_aRequest = null;

    /**
     * Flag for redirecting after save
     *
     * @var bool
     */
    protected $_blIsRedirectAfterSave = null;

    /**
     * Variable for flagging payment as payone payment
     *
     * @var bool
     */
    protected $_blIsPayonePayment = false;

    /**
     * Appointed error
     *
     * @var bool
     */
    protected $_blFcPayOneAppointedError = false;

    /**
     * List of Payment IDs which need to save workorderid
     *
     * @var array
     */
    protected $_aPaymentsWorkorderIdSave = [
        'fcpopo_bill',
        'fcpopo_debitnote',
        'fcpopo_installment',
        'fcpoklarna_invoice',
        'fcpoklarna_directdebit',
        'fcpoklarna_installments',
    ];

    /**
     * List of Payment IDs which are foreseen for saving clearing reference
     *
     * @var array
     */
    protected $_aPaymentsClearingReferenceSave = [
        'fcporp_bill',
        'fcpopo_bill',
        'fcpopo_debitnote',
        'fcpopo_installment',
        'fcpoklarna_invoice',
        'fcpoklarna_directdebit',
        'fcpoklarna_installments',
    ];

    /**
     * List of Payment IDs which are foreseen for saving external shopid
     *
     * @var array
     */
    protected $_aPaymentsProfileIdentSave = ['fcporp_bill'];

    /**
     * PaymentId of order
     *
     * @var string
     */
    protected $_sFcpoPaymentId = null;

    /**
     * Flag that indicates that payone payment of this order is flagged as redirect payment
     * Flag for marking order as generally problematic
     *
     * @var bool
     */
    protected $_blOrderHasProblems = false;

    /** Flag that indicates that payone payment of this order is flagged as redirect payment
     *
     * @var boolean
     */
    protected $_blOrderPaymentFlaggedAsRedirect = null;

    /**
     * Flag for finishing order completely
     *
     * @var bool
     */
    protected $_blFinishingSave = true;

    /**
     * Indicator if loading basket from session has been triggered
     *
     * @var bool
     */
    protected $_blFcPoLoadFromSession = false;

    /**
     * init object construction
     *
     * @return null
     */
    public function __construct()
    {
        parent::__construct();
        $this->_oFcpoHelper = oxNew(FcPoHelper::class);
        $this->_oFcpoDb = DatabaseProvider::getDb();
    }

    /**
     * Checks if the selected payment method for this order is a PAYONE payment method
     *
     * @param string $sPaymenttype payment id. Default is null
     *
     * @return bool
     */
    public function isPayOnePaymentType($sPaymenttype = null)
    {
        if (!$sPaymenttype) {
            $sPaymenttype = $this->oxorder__oxpaymenttype->value;
        }
        return $this->_fcpoIsPayonePaymentType($sPaymenttype);
    }

    /**
     * Method validates if given payment-type is an payone iframe payment
     *
     * @param string $sPaymenttype
     * @return bool
     */
    public function isPayOneIframePayment($sPaymenttype = null)
    {
        if (!$sPaymenttype) {
            $sPaymenttype = $this->oxorder__oxpaymenttype->value;
        }
        return $this->_fcpoIsPayonePaymentType($sPaymenttype, true);
    }

    /**
     * Checks if user already exists
     *
     * @param string $sEmail
     * @return mixed
     * @todo Should be moved to User
     */
    public function fcpoDoesUserAlreadyExist($sEmail)
    {
        $sQuery = "SELECT oxid FROM oxuser WHERE oxusername = " . DatabaseProvider::getDb()->quote($sEmail) . " AND oxpassword != ''";
        $sUserId = $this->_oFcpoDb->GetOne($sQuery);
        return ($sUserId) ? $sUserId : false;
    }

    /**
     * Returns user id by given username
     *
     * @param string $sUserName
     * @return string
     */
    public function fcpoGetIdByUserName($sUserName)
    {
        $oConfig = $this->_oFcpoHelper->fcpoGetConfig();
        $sQuery = "SELECT oxid FROM oxuser WHERE oxusername = " . DatabaseProvider::getDb()->quote($sUserName);

        if (!$oConfig->getConfigParam('blMallUsers')) {
            $sQuery .= " AND oxshopid = '{$oConfig->getShopId()}'";
        }

        return $this->_oFcpoDb->GetOne($sQuery);
    }

    /**
     * Returns countryid by given countrycode
     *
     * @param string $sCode
     * @return mixed
     */
    public function fcpoGetIdByCode($sCode)
    {
        $sQuery = "SELECT oxid FROM oxcountry WHERE oxisoalpha2 = " . DatabaseProvider::getDb()->quote($sCode);
        return $this->_oFcpoDb->GetOne($sQuery);
    }

    /**
     * Returns salutation stored in database by firstname
     *
     * @param string $sFirstname
     * @return string
     */
    public function fcpoGetSalByFirstName($sFirstname)
    {
        $sQuery = "SELECT oxsal FROM oxuser WHERE oxfname = " . DatabaseProvider::getDb()->quote($sFirstname) . " AND oxsal != '' LIMIT 1";
        return $this->_oFcpoDb->GetOne($sQuery);
    }

    /**
     * Checks address database for receiving a address matching to response
     *
     * @param array $aResponse
     * @return mixed
     */
    public function fcpoGetAddressIdByResponse($aResponse, $sStreet, $sStreetNr)
    {
        $sQuery = " SELECT
                        oxid
                    FROM
                        oxaddress
                    WHERE
                        oxfname = {$this->_oFcpoDb->quote($aResponse['add_paydata[shipping_firstname]'])} AND
                        oxlname = {$this->_oFcpoDb->quote($aResponse['add_paydata[shipping_lastname]'])} AND
                        oxstreet = {$this->_oFcpoDb->quote($sStreet)} AND
                        oxstreetnr = {$this->_oFcpoDb->quote($sStreetNr)} AND
                        oxcity = {$this->_oFcpoDb->quote($aResponse['add_paydata[shipping_city]'])} AND
                        oxzip = {$this->_oFcpoDb->quote($aResponse['add_paydata[shipping_zip]'])} AND
                        oxcountryid = {$this->_oFcpoDb->quote($this->fcpoGetIdByCode($aResponse['add_paydata[shipping_country]']))}";

        return $this->_oFcpoDb->getOne($sQuery);
    }

    /**
     * Removes MSIE(\s)?(\S)*(\s) from browser agent information
     *
     * @param string $sAgent browser user agent idenfitier
     *
     * @return string
     */
    protected function _fcProcessUserAgentInfo($sAgent)
    {
        if ($sAgent) {
            $sAgent = Str::getStr()->preg_replace("/MSIE(\s)?(\S)*(\s)/", "", (string)$sAgent);
        }
        return $sAgent;
    }

    /**
     * Compares the HTTP user agent before and after the redirect payment method.
     * If HTTP user agent is diffenrent it checks if the remote tokens match.
     * If so, the current user agent is updated in the user session.
     *
     * @return null
     */
    protected function _fcpoCheckUserAgent()
    {
        $oUtils = $this->_oFcpoHelper->fcpoGetUtilsServer();

        $sAgent = $oUtils->getServerVar('HTTP_USER_AGENT');
        $sExistingAgent = $this->_oFcpoHelper->fcpoGetSessionVariable('sessionagent');
        $sAgent = $this->_fcProcessUserAgentInfo($sAgent);
        $sExistingAgent = $this->_fcProcessUserAgentInfo($sExistingAgent);

        if ($this->_fcGetCurrentVersion() >= 4310 && $sAgent && $sAgent != $sExistingAgent) {
            $oSession = $this->_oFcpoHelper->fcpoGetSession();
            $sInputToken = $this->_oFcpoHelper->fcpoGetRequestParameter('rtoken');
            $sToken = $oSession->getRemoteAccessToken(false);
            $blValid = $this->_fcpoValidateToken($sInputToken, $sToken);
            if ($blValid == true) {
                $this->_oFcpoHelper->fcpoGetSessionVariable("sessionagent", $oUtils->getServerVar('HTTP_USER_AGENT'));
            }
        }
    }

    /**
     * Compares tokens and returns if they are valid
     *
     * @param $sInputToken
     * @param $sToken
     * @return bool
     */
    protected function _fcpoValidateToken($sInputToken, $sToken)
    {
        $blTokenEqual = !strcmp($sInputToken, $sToken);
        return $sInputToken && $blTokenEqual;
    }

    /**
     * Get current version number as 4 digit integer e.g. Oxid 4.5.9 is 4590
     *
     * @return integer
     */
    protected function _fcGetCurrentVersion()
    {
        return $this->_oFcpoHelper->fcpoGetIntShopVersion();
    }

    /**
     * Returns true if this request is the return to the shop from a payment provider where the user has been
     * redirected to
     *
     * @return bool
     */
    protected function _isRedirectAfterSave()
    {
        if ($this->_blIsRedirectAfterSave == null) {
            $this->_blIsRedirectAfterSave = false;

            $blUseRedirectAfterSave = (
                $this->_oFcpoHelper->fcpoGetRequestParameter('fcposuccess') &&
                $this->_oFcpoHelper->fcpoGetRequestParameter('refnr') &&
                $this->_oFcpoHelper->fcpoGetSessionVariable('fcpoTxid')
            );

            if ($blUseRedirectAfterSave) {
                $this->_blIsRedirectAfterSave = true;
            }
        }
        return $this->_blIsRedirectAfterSave;
    }

    /**
     * Overrides standard oxid finalizeOrder method
     *
     * Order checking, processing and saving method.
     * Before saving performed checking if order is still not executed (checks in
     * database oxorder table for order with know ID), if yes - returns error code 3,
     * if not - loads payment data, assigns all info from basket to new oxorder object
     * and saves full order with error status. Then executes payment. On failure -
     * deletes order and returns error code 2. On success - saves order (oxorder::save()),
     * removes article from wishlist (oxorder::_updateWishlist()), updates voucher data
     * (oxorder::_markVouchers()). Finally sends order confirmation email to customer
     * (oxemail::SendOrderEMailToUser()) and shop owner (oxemail::SendOrderEMailToOwner()).
     * If this is order recalculation, skipping payment execution, marking vouchers as used
     * and sending order by email to shop owner and user
     * Mailing status (1 if OK, 0 on error) is returned.
     *
     * @param Basket $oBasket              Shopping basket object
     * @param object $oUser                Current user object
     * @param bool   $blRecalculatingOrder Order recalculation
     *
     * @return integer
     * @throws Exception
     *
     */
    public function finalizeOrder(Basket $oBasket, $oUser, $blRecalculatingOrder = false)
    {
        $sPaymentId = $oBasket->getPaymentId();
        $this->_sFcpoPaymentId = $sPaymentId;
        $blPayonePayment = $this->isPayOnePaymentType($sPaymentId);

        // OXID-219 If payone method, the order will be completed by this method
        // If overloading is needed, the _fcpoFinalizeOrder have to be overloaded
        // Otherwise, the execution goes over, to the normal flow from parent class
        if ($blPayonePayment) {
            return $this->_fcpoFinalizeOrder($oBasket, $oUser, $blRecalculatingOrder);
        }

        return parent::finalizeOrder($oBasket, $oUser, $blRecalculatingOrder);
    }

    /**
     * Overloading of basket load method for handling
     * basket loading from session => avoiding loading it twice
     *
     * @param Basket $oBasket
     * @return mixed
     * @see https://integrator.payone.de/jira/browse/OXID-263
     */
    protected function loadFromBasket(Basket $oBasket)
    {
        $sSessionChallenge =
            $this->_oFcpoHelper->fcpoGetSessionVariable('sess_challenge');

        $blTriggerLoadingFromSession = (
            $this->_blFcPoLoadFromSession &&
            $sSessionChallenge
        );

        if (!$blTriggerLoadingFromSession) {
            return parent::loadFromBasket($oBasket);
        }

        return $this->load($sSessionChallenge);
    }

    /**
     * Assigns data, stored in oxorderarticles to oxorder object .
     *
     * @param bool $blExcludeCanceled excludes canceled items from list
     *
     * FATCHIP MOD:
     * load articles from db if order already exists
     *
     * @return ListModel
     */
    public function getOrderArticles($blExcludeCanceled = false): ListModel
    {
        $sSessionChallenge =
            $this->_oFcpoHelper->fcpoGetSessionVariable('sess_challenge');

        $blSetArticlesNull = (
            $this->_blFcPoLoadFromSession &&
            $sSessionChallenge
        );

        if ($blSetArticlesNull) {
            //null trigger orderarticles getter from db
            $this->_oArticles = null;
        }

        return parent::getOrderArticles($blExcludeCanceled);
    }

    /**
     * Payone handling on finalizing order
     *
     * @param Basket $oBasket
     * @param User   $oUser
     * @param bool   $blRecalculatingOrder
     * @return bool|int
     * @throws Exception
     */
    protected function _fcpoFinalizeOrder(Basket $oBasket, User $oUser, bool $blRecalculatingOrder)
    {
        $blSaveAfterRedirect = $this->_isRedirectAfterSave();

        $mRet = $this->_fcpoEarlyValidation($blSaveAfterRedirect, $oBasket, $oUser, $blRecalculatingOrder);
        if ($mRet != null) {
            return $mRet;
        }
        // copies user info
        $this->setUser($oUser);

        // copies basket info if no basket injection or presave order is inactive
        $this->_fcpoHandleBasket($blSaveAfterRedirect, $oBasket);

        // payment information
        $oUserPayment = $this->setPayment($oBasket->getPaymentId());

        // set folder information, if order is new
        // #M575 in recalcualting order case folder must be the same as it was
        if (!$blRecalculatingOrder) {
            $this->setFolder();
        }

        $mRet = $this->_fcpoExecutePayment($blSaveAfterRedirect, $oBasket, $oUserPayment, $blRecalculatingOrder);
        if ($mRet != null) {
            return $mRet;
        }

        //saving all order data to DB
        $this->_blFinishingSave = true;
        $this->save();

        $this->_fcpoSaveAfterRedirect($blSaveAfterRedirect);

        // deleting remark info only when order is finished
        $this->_oFcpoHelper->fcpoDeleteSessionVariable('ordrem');
        $this->_oFcpoHelper->fcpoDeleteSessionVariable('stsprotection');

        //#4005: Order creation time is not updated when order processing is complete
        if (method_exists($this, '_updateOrderDate') && !$blRecalculatingOrder) {
            $this->_updateOrderDate();
        }

        $this->_fcpoSetOrderStatus();

        // store orderid
        $oBasket->setOrderId($this->getId());

        // updating wish lists
        $this->updateWishlist($oBasket->getContents(), $oUser);

        // updating users notice list
        $this->updateNoticeList($oBasket->getContents(), $oUser);

        // marking vouchers as used and sets them to $this->_aVoucherList (will be used in order email)
        // skipping this action in case of order recalculation
        $this->_fcpoMarkVouchers($blRecalculatingOrder, $oUser, $oBasket);

        if (!$this->oxorder__oxordernr->value) {
            $this->setNumber();
        } else {
            oxNew(Counter::class)->update($this->getCounterIdent(), $this->oxorder__oxordernr->value);
        }

        $this->_oFcpoHelper->fcpoDeleteSessionVariable('fcpoordernotchecked');
        $this->_oFcpoHelper->fcpoDeleteSessionVariable('fcpoWorkorderId');

        // send order by email to shop owner and current user
        // skipping this action in case of order recalculation
        $iRet = $this->_fcpoFinishOrder($blRecalculatingOrder, $oUser, $oBasket, $oUserPayment);

        // OXID-233 : handle amazon different login
        $this->_fcpoAdjustAmazonPayUserDetails($oUserPayment);

        return $iRet;
    }

    /**
     * OXID-233: If the user was logged in during order,
     * its ID is set back as order__userid, to link back the order to that user
     *
     * ONLY during AmazonPay process, and with logged user
     * (i.e session 'sOxidPreAmzUser' is set)
     *
     * @param UserPayment $oUserPayment
     * @throws Exception
     */
    protected function _fcpoAdjustAmazonPayUserDetails(UserPayment $oUserPayment)
    {
        $sUserId = $this->_oFcpoHelper->fcpoGetSessionVariable('sOxidPreAmzUser');
        if (!empty($sUserId)) {
            $this->oxorder__oxuserid = new Field($sUserId);
            $this->save();

            $oUserPayment->oxuserpayments__oxuserid = new Field($sUserId);
            $oUserPayment->save();

            $this->_oFcpoHelper->fcpoSetSessionVariable('usr', $sUserId);
            $this->_oFcpoHelper->fcpoDeleteSessionVariable('sOxidPreAmzUser');
        }
    }


    /**
     * Overriding setUser for correcting email-address
     *
     * @return void
     */
    public function setUser($oUser)
    {
        parent::assignUserInformation($oUser);

        if ($this->_sFcpoPaymentId == 'fcpoamazonpay') {
            $oViewConf = $this->_oFcpoHelper->getFactoryObject(ViewConfig::class);
            $sPrefixEmail = $oUser->oxuser__oxusername->value;
            $sEmail = $oViewConf->fcpoAmazonEmailDecode($sPrefixEmail);
            $this->oxorder__oxbillemail = new Field($sEmail);
        }
    }

    /**
     * Triggers steps to execute payment
     *
     * @param bool        $blSaveAfterRedirect
     * @param Basket      $oBasket
     * @param UserPayment $oUserPayment
     * @return mixed
     */
    protected function _fcpoExecutePayment($blSaveAfterRedirect, $oBasket, $oUserPayment, $blRecalculatingOrder)
    {
        if ($blSaveAfterRedirect == true) {
            $sRefNrCheckResult = $this->_fcpoCheckRefNr();
            $sTxid = $this->_oFcpoHelper->fcpoGetSessionVariable('fcpoTxid');

            if ($sRefNrCheckResult != '') {
                return $sRefNrCheckResult;
            }
            $this->_fcpoProcessOrder($sTxid);
        } else {
            if (!$blRecalculatingOrder) {
                $blRet = $this->executePayment($oBasket, $oUserPayment);
                if ($blRet != true) {
                    return $blRet;
                }
            }
        }

        return null;
    }

    /**
     * Returns oxuser object of this user
     * Adjustment for prefixed email (currently amazon)
     *
     * @return User
     */
    public function getOrderUser()
    {
        $oUser = parent::getOrderUser();

        $sPaymenttype = $this->oxorder__oxpaymenttype->value;
        if ($sPaymenttype == 'fcpoamazonpay') {
            $oViewConf = $this->_oFcpoHelper->getFactoryObject(ViewConfig::class);
            $sPrefixEmail = $oUser->oxuser__oxusername->value;
            $sEmail = $oViewConf->fcpoAmazonEmailDecode($sPrefixEmail);
            $oUser->oxuser__oxusername = new Field($sEmail);
        }

        return $oUser;
    }

    /**
     * Sends clearing data mail to customer after a capture.
     * This currently is only for payment fcpoinvoice
     *
     *
     */
    public function fcpoSendClearingDataAfterCapture()
    {
        $sPaymentId = $this->oxorder__oxpaymenttype->value;
        $sAuthMode = $this->oxorder__fcpoauthmode->value;

        $blSendMail = (
            in_array($sPaymentId, ['fcpoinvoice', 'fcpopayadvance']) &&
            $sAuthMode == 'preauthorization'
        );

        if (!$blSendMail) {
            return;
        };

        $sTo = $this->oxorder__oxbillemail->value;
        $sSubject = $this->_fcpoGetClearingDataEmailSubject();
        $sBody = $this->_fcpoGetClearingDataEmailBody();

        $oEmail = $this->_oFcpoHelper->getFactoryObject(Email::class);
        $oEmail->sendEmail($sTo, $sSubject, $sBody);
    }

    /**
     * Returns translated subject for clearing mail
     *
     * @return string
     */
    protected function _fcpoGetClearingDataEmailSubject()
    {
        $oLang = $this->_oFcpoHelper->getFactoryObject(Language::class);
        $oShop = $this->_oFcpoHelper->getFactoryObject(Shop::class);
        $oShop->load($this->oxorder__oxshopid->value);
        $sSubject = $oShop->oxshops__oxname->value . " - ";
        $sSubject .= $oLang->translateString('FCPO_EMAIL_CLEARING_SUBJECT') . " ";
        $sSubject .= $this->oxorder__oxordernr->value;

        return $sSubject;
    }

    /**
     * Returns translated body content for clearing mail
     *
     * @return string
     */
    protected function _fcpoGetClearingDataEmailBody()
    {
        $oLang = $this->_oFcpoHelper->getFactoryObject(Language::class);
        $oShop = $this->_oFcpoHelper->getFactoryObject(Shop::class);
        $oShop->load($this->oxorder__oxshopid->value);
        $sBody = $oLang->translateString('FCPO_EMAIL_CLEARING_BODY_WELCOME');
        $sBody = str_replace('%NAME%', $this->oxorder__oxbillfname->value, $sBody);
        $sBody = str_replace('%SURNAME%', $this->oxorder__oxbilllname->value, $sBody);
        $sBody .= $oLang->translateString("FCPO_BANKACCOUNTHOLDER") . ": " . $this->getFcpoBankaccountholder() . "\n";
        $sBody .= $oLang->translateString("FCPO_EMAIL_BANK") . " " . $this->getFcpoBankname() . "\n";
        $sBody .= $oLang->translateString("FCPO_EMAIL_ROUTINGNUMBER") . " " . $this->getFcpoBankcode() . "\n";
        $sBody .= $oLang->translateString("FCPO_EMAIL_ACCOUNTNUMBER") . " " . $this->getFcpoBanknumber() . "\n";
        $sBody .= $oLang->translateString("FCPO_EMAIL_BIC") . " " . $this->getFcpoBiccode() . "\n";
        $sBody .= $oLang->translateString("FCPO_EMAIL_IBAN") . " " . $this->getFcpoIbannumber() . "\n";
        $sBody .= $oLang->translateString("FCPO_EMAIL_USAGE") . ": " . $this->oxorder__fcpotxid->value . "\n";
        $sBody .= "\n\n";
        $sThankyou = $oLang->translateString('FCPO_EMAIL_CLEARING_BODY_THANKYOU');
        $sBody .= str_replace('%SHOPNAME%', $oShop->oxshops__oxname->value, $sThankyou);

        return $sBody;
    }

    /**
     * Handles basket loading into order
     *
     * @param bool   $blSaveAfterRedirect
     * @param Basket $oBasket
     * @return void
     */
    protected function _fcpoHandleBasket($blSaveAfterRedirect, $oBasket)
    {
        $sGetChallenge = $this->_oFcpoHelper->fcpoGetSessionVariable('sess_challenge');
        $oConfig = $this->_oFcpoHelper->fcpoGetConfig();
        $blFCPOPresaveOrder = $oConfig->getConfigParam('blFCPOPresaveOrder');
        if ($blFCPOPresaveOrder == false || $blSaveAfterRedirect == false) {
            $this->loadFromBasket($oBasket);
        } else {
            $this->load($sGetChallenge);
        }
    }

    /**
     *
     *
     * @param bool   $blSaveAfterRedirected
     * @param Basket $oBasket
     * @param User   $oUser
     * @return mixed
     */
    protected function _fcpoEarlyValidation($blSaveAfterRedirect, $oBasket, $oUser, $blRecalculatingOrder)
    {
        // check if this order is already stored
        $sGetChallenge = $this->_oFcpoHelper->fcpoGetSessionVariable('sess_challenge');

        $this->_blFcPoLoadFromSession = (
            $blSaveAfterRedirect &&
            !$blRecalculatingOrder &&
            $sGetChallenge &&
            $oBasket &&
            $oUser &&
            $this->checkOrderExist($sGetChallenge)
        );

        if ($blSaveAfterRedirect == false && $this->checkOrderExist($sGetChallenge)) {
            $oUtils = $this->_oFcpoHelper->fcpoGetUtils();
            $oUtils->logger('BLOCKER');
            // we might use this later, this means that somebody klicked like mad on order button
            return self::ORDER_STATE_ORDEREXISTS;
        }

        // if not recalculating order, use sess_challenge id, else leave old order id
        if (!$blRecalculatingOrder) {
            // use this ID
            $this->setId($sGetChallenge);

            // validating various order/basket parameters before finalizing
            if (($iOrderState = $this->validateOrder($oBasket, $oUser))) {
                return $iOrderState;
            }
        }

        return null;
    }

    /**
     * Finishes order and returns state
     *
     * @param bool        $blRecalculatingOrder
     * @param User        $oUser
     * @param Basket      $oBasket
     * @param UserPayment $oUserPayment
     * @return int
     */
    protected function _fcpoFinishOrder($blRecalculatingOrder, $oUser, $oBasket, $oUserPayment)
    {
        if (!$blRecalculatingOrder) {
            $iRet = $this->sendOrderByEmail($oUser, $oBasket, $oUserPayment);
        } else {
            $iRet = self::ORDER_STATE_OK;
        }

        return $iRet;
    }

    /**
     * Mathod triggers saving after redirect if this option has been configured
     *
     * @param bool $blSaveAfterRedirect
     * @return void
     */
    protected function _fcpoSaveAfterRedirect($blSaveAfterRedirect)
    {
        if ($blSaveAfterRedirect == true) {
            $sQuery = "UPDATE fcpotransactionstatus SET fcpo_ordernr = '{$this->oxorder__oxordernr->value}' WHERE fcpo_txid = '" . $this->_oFcpoHelper->fcpoGetSessionVariable('fcpoTxid') . "'";
            $this->_oFcpoDb->execute($sQuery);
        }
    }

    /**
     * Sets order status depending on having an appointed error
     *
     * @return void
     */
    protected function _fcpoSetOrderStatus(): void
    {
        $blIsAmazonPending = $this->_oFcpoHelper->fcpoGetSessionVariable('fcpoAmazonPayOrderIsPending');
        $blOrderOk = $this->_fcpoValidateOrderAgainstProblems();

        if ($blIsAmazonPending) {
            $this->setOrderStatus('PENDING');
            $this->oxorder__oxfolder = new Field('ORDERFOLDER_PROBLEMS', Field::T_RAW);
            $this->save();
        } elseif ($blOrderOk) {
            $this->setOrderStatus('OK');
        } else {
            $this->setOrderStatus('ERROR');
        }
    }

    /**
     * Validates order for checking if there were any occuring problems
     *
     * @return bool
     */
    protected function _fcpoValidateOrderAgainstProblems()
    {
        $blOrderOk = (
            $this->_fcpoGetAppointedError() == false &&
            $this->_blOrderHasProblems == false
        );

        return $blOrderOk;
    }

    /**
     * Method triggers marking vouchers if order hasn't been set for recalculation
     *
     * @param bool $blRecalculatingOrder
     * @return void
     */
    protected function _fcpoMarkVouchers($blRecalculatingOrder, $oUser, $oBasket)
    {
        if (!$blRecalculatingOrder) {
            $this->markVouchers($oBasket, $oUser);
        }
    }

    /**
     * Summed steps to process a payone order
     *
     * @param string $sTxid
     * @return void
     */
    protected function _fcpoProcessOrder($sTxid)
    {
        $this->_fcpoCheckTxid();
        $iOrderNotChecked = $this->_oFcpoHelper->fcpoGetSessionVariable('fcpoordernotchecked');
        if (!$iOrderNotChecked || $iOrderNotChecked != 1) {
            $iOrderNotChecked = 0;
        }
        $this->_fcpoSaveOrderValues($sTxid, $iOrderNotChecked);
        $this->_fcpoCheckUserAgent();
    }

    /**
     * Saves payone specific orderlines
     *
     * @param string $sTxid
     * @param int    $iOrderNotChecked
     * @return void
     */
    protected function _fcpoSaveOrderValues($sTxid, $iOrderNotChecked)
    {
        $oConfig = $this->_oFcpoHelper->fcpoGetConfig();
        $blPresaveOrder = (bool)$oConfig->getConfigParam('blFCPOPresaveOrder');
        if ($blPresaveOrder == true) {
            $this->oxorder__oxordernr = new Field($this->_oFcpoHelper->fcpoGetSessionVariable('fcpoOrderNr'), Field::T_RAW);
        }
        $this->oxorder__fcpotxid = new Field($sTxid, Field::T_RAW);
        $this->oxorder__fcporefnr = new Field($this->_oFcpoHelper->fcpoGetRequestParameter('refnr'), Field::T_RAW);
        $this->oxorder__fcpoauthmode = new Field($this->_oFcpoHelper->fcpoGetSessionVariable('fcpoAuthMode'), Field::T_RAW);
        $this->oxorder__fcpomode = new Field($this->_oFcpoHelper->fcpoGetSessionVariable('fcpoMode'), Field::T_RAW);
        $this->oxorder__fcpoordernotchecked = new Field($iOrderNotChecked, Field::T_RAW);
        $sWorkorderId = $this->_oFcpoHelper->fcpoGetSessionVariable('payolution_workorderid');
        if ($sWorkorderId) {
            $this->oxorder__fcpoworkorderid = new Field($sWorkorderId, Field::T_RAW);
        }
        $this->_oFcpoDb->execute("UPDATE fcporefnr SET fcpo_txid = '" . $sTxid . "' WHERE fcpo_refnr = '" . $this->_oFcpoHelper->fcpoGetRequestParameter('refnr') . "'");
        $this->_oFcpoHelper->fcpoDeleteSessionVariable('fcpoOrderNr');
        $this->_oFcpoHelper->fcpoDeleteSessionVariable('fcpoTxid');
        $this->_oFcpoHelper->fcpoDeleteSessionVariable('fcpoRefNr');
        $this->_oFcpoHelper->fcpoDeleteSessionVariable('fcpoAuthMode');
        $this->_oFcpoHelper->fcpoDeleteSessionVariable('fcpoRedirectUrl');
    }

    /**
     * Check Txid against transactionstatus table and set resulting order values
     *
     * @return boolean
     */
    protected function _fcpoCheckTxid()
    {
        $blAppointedError = false;
        $sTxid = $this->_oFcpoHelper->fcpoGetSessionVariable('fcpoTxid');

        $sTestOxid = '';
        if ($sTxid) {
            $sQuery = "SELECT oxid FROM fcpotransactionstatus WHERE FCPO_TXACTION = 'appointed' AND fcpo_txid = '" . $sTxid . "'";
            $sTestOxid = $this->_oFcpoDb->getOne($sQuery);
        }

        if (!$sTestOxid) {
            $blAppointedError = true;
            $this->oxorder__oxfolder = new Field('ORDERFOLDER_PROBLEMS', Field::T_RAW);
            $oLang = $this->_oFcpoHelper->fcpoGetLang();
            $sCurrentRemark = $this->oxorder__oxremark->value;
            $sAddErrorRemark = $oLang->translateString('FCPO_REMARK_APPOINTED_MISSING');
            $sNewRemark = $sCurrentRemark . " " . $sAddErrorRemark;
            $this->oxorder__oxremark = new Field($sNewRemark, Field::T_RAW);
        }
        $this->_fcpoSetAppointedError($blAppointedError);

        return $blAppointedError;
    }

    /**
     * Checks the reference number and returns a string in case of check failed
     *
     * @return string
     */
    protected function _fcpoCheckRefNr()
    {
        $oPORequest = $this->_oFcpoHelper->getFactoryObject(FcPoRequest::class);
        $sSessRefNr = $oPORequest->getRefNr(false, true);
        $sRequestRefNr = $this->_oFcpoHelper->fcpoGetRequestParameter('refnr');

        $blValid = ($sRequestRefNr == $sSessRefNr);

        if ($blValid) {
            return '';
        }

        $this->_oFcpoHelper->fcpoDeleteSessionVariable('fcpoRefNr');
        $oLang = $this->_oFcpoHelper->fcpoGetLang();
        return $oLang->translateString('FCPO_MANIPULATION');
    }

    /**
     * Overrides standard oxid save method
     * Save orderarticles only when not already existing
     * Updates/inserts order object and related info to DB
     *
     * @return null
     */
    public function save()
    {
        $oConfig = $this->_oFcpoHelper->fcpoGetConfig();
        $blPresaveOrder = (bool)$oConfig->getConfigParam('blFCPOPresaveOrder');
        if (!$blPresaveOrder || !$this->isPayOnePaymentType()) {
            return parent::save();
        }

        if ($this->oxorder__oxshopid->value == false) {
            $oShop = $oConfig->getActiveShop();
            $this->oxorder__oxshopid = new Field($oShop->getId());
        }

        if (($blSave = parent::save())) {
            // saving order articles
            $oOrderArticles = $this->getOrderArticles();
            if ($oOrderArticles && count($oOrderArticles) > 0) {
                foreach ($oOrderArticles as $oOrderArticle) {
                    $oOrderArticle->fcpoSetFinishingSave($this->_blFinishingSave);
                    $oOrderArticle->save();
                }
            }
        }

        return $blSave;
    }

    /**
     * Checks based on the transaction status received by PAYONE whether
     * the capture request is available for this order at the moment.
     *
     * @return bool
     */
    public function allowCapture()
    {
        $blReturn = true;
        if ($this->oxorder__fcpoauthmode->value == 'authorization') {
            $blReturn = false;
        }

        if ($blReturn) {
            $iCount = $this->_oFcpoDb->GetOne("SELECT COUNT(*) FROM fcpotransactionstatus WHERE fcpo_txid = '{$this->oxorder__fcpotxid->value}'");
            $blReturn = !(($iCount == 0));
        }

        return $blReturn;
    }

    /**
     * Checks based on the transaction status received by PAYONE whether
     * the debit request is available for this order at the moment.
     *
     * @return bool
     */
    public function allowDebit()
    {
        $blIsAuthorization =
            ($this->oxorder__fcpoauthmode->value == 'authorization');

        if ($blIsAuthorization) {
            return true;
        }

        $sQuery = "
            SELECT 
                COUNT(*) 
            FROM 
                fcpotransactionstatus 
            WHERE 
                fcpo_txid = '{$this->oxorder__fcpotxid->value}' AND 
                fcpo_txaction = 'appointed'
        ";

        $iCount = (int)$this->_oFcpoDb->GetOne($sQuery);

        return ($iCount == 1);
    }

    /**
     * Checks based on the payment method whether
     * the settleaccount checkbox should be shown.
     *
     * @return bool
     */
    public function allowAccountSettlement()
    {
        $blReturn = (
            $this->oxorder__oxpaymenttype->value == 'fcpopayadvance' ||
            FcPayOnePayment::fcIsPayOneOnlinePaymentType($this->oxorder__oxpaymenttype->value)
        );

        return $blReturn;
    }

    /**
     * Checks based on the selected payment method for this order whether
     * the users bank data has to be transferred for the debit request.
     *
     * @return bool
     */
    public function debitNeedsBankData()
    {
        $blReturn = (
            $this->oxorder__oxpaymenttype->value == 'fcpoinvoice' ||
            $this->oxorder__oxpaymenttype->value == 'fcpopayadvance' ||
            $this->oxorder__oxpaymenttype->value == 'fcpocashondel' ||
            FcPayOnePayment::fcIsPayOneOnlinePaymentType($this->oxorder__oxpaymenttype->value)
        );

        return $blReturn;
    }

    /**
     * Checks based on the payment method whether
     * the detailed product list is needed.
     *
     * @return bool
     */
    public function isDetailedProductInfoNeeded()
    {
        $blForcedByPaymentMethod = in_array(
            $this->oxorder__oxpaymenttype->value,
            [
                'fcpobillsafe',
                'fcpoklarna',
                'fcpoklarna_invoice',
                'fcpoklarna_installments',
                'fcpoklarna_directdebit',
                'fcpo_secinvoice',
                'fcporp_bill',
                'fcpopaydirekt_express',
            ]
        );

        if ($blForcedByPaymentMethod) {
            return true;
        }

        $oConfig = $this->_oFcpoHelper->fcpoGetConfig();
        return $oConfig->getConfigParam('blFCPOSendArticlelist');
    }

    /**
     * Get the current sequence number of the order
     *
     * @return int
     */
    public function getSequenceNumber()
    {
        $iCount = $this->_oFcpoDb->GetOne("SELECT MAX(fcpo_sequencenumber) FROM fcpotransactionstatus WHERE fcpo_txid = '{$this->oxorder__fcpotxid->value}'");

        return ($iCount == null) ? 0 : $iCount + 1;
    }

    /**
     * Get the last transaction status the shop received from PAYONE
     *
     * @return object
     */
    public function getLastStatus()
    {
        $sOxid = $this->_oFcpoDb->GetOne("SELECT * FROM fcpotransactionstatus WHERE fcpo_txid = '{$this->oxorder__fcpotxid->value}' ORDER BY fcpo_sequencenumber DESC, oxtimestamp DESC");
        if ($sOxid) {
            $oStatus = $this->_oFcpoHelper->getFactoryObject(FcPoTransactionStatus::class);
            $oStatus->load($sOxid);
        }

        return (isset($oStatus)) ? $oStatus : false;
    }

    /**
     * Get the API log entry from the (pre)authorization request of this order
     *
     * @return array
     */
    protected function getResponse()
    {
        if ($this->_aResponse == null) {
            $sQuery = $this->_fcpoGetResponseQuery();
            $sOxidRequest = $this->_oFcpoDb->GetOne($sQuery);
            if ($sOxidRequest) {
                $oRequestLog = $this->_oFcpoHelper->getFactoryObject(FcPoRequestLog::class);
                $oRequestLog->load($sOxidRequest);
                $aResponse = $oRequestLog->getResponseArray();
                if ($aResponse) {
                    $this->_aResponse = $aResponse;
                }
            }
        }
        return $this->_aResponse;
    }

    /**
     * Returns request array of last authorization call
     *
     * @return array|null
     */
    protected function getRequest()
    {
        if ($this->_aRequest == null) {
            $sSelect = "
                SELECT oxid 
                FROM fcporequestlog 
                WHERE fcpo_refnr = '{$this->oxorder__fcporefnr->value}' 
                AND (
                    fcpo_requesttype = 'preauthorization' OR 
                    fcpo_requesttype = 'authorization'
                )
                AND FCPO_RESPONSESTATUS = 'APPROVED'
                ORDER BY oxtimestamp DESC
            ";
            $sOxidRequest = $this->_oFcpoDb->GetOne($sSelect);

            if ($sOxidRequest) {
                $oRequestLog = $this->_oFcpoHelper->getFactoryObject(FcPoRequestLog::class);
                $oRequestLog->load($sOxidRequest);
                $aRequest = $oRequestLog->getRequestArray();
                if ($aRequest) {
                    $this->_aRequest = $aRequest;
                }
            }
        }

        return $this->_aRequest;
    }

    /**
     * @param $sParameter
     * @return string
     */
    protected function getRequestParameter($sParameter)
    {
        $aRequest = $this->getRequest();
        $sReturn = (isset($aRequest[$sParameter])) ?
            $aRequest[$sParameter] : '';

        return $sReturn;
    }

    /*
     * Returns matching query for fetching response needed for current state
     *
     * @return string
     */
    protected function _fcpoGetResponseQuery()
    {
        $blFetchCaptureResponse = (
            $this->oxorder__fcpoauthmode == 'preauthorization' &&
            $this->oxorder__oxpaymenttype == 'fcpoinvoice'
        );

        if ($blFetchCaptureResponse) {
            $sWhere = "fcpo_request LIKE '%" . $this->oxorder__fcpotxid->value . "%'";
            $sAnd = "
                fcpo_requesttype = 'capture'
            ";
        } else {
            $sWhere = "fcpo_refnr = '{$this->oxorder__fcporefnr->value}' ";
            $sAnd = "
                fcpo_requesttype = 'preauthorization' OR 
                fcpo_requesttype = 'authorization'
            ";
        }

        $sQuery = "
            SELECT oxid 
            FROM fcporequestlog 
            WHERE {$sWhere}
            AND (
                {$sAnd}
            )
        ";

        return $sQuery;
    }

    /**
     * Get a certain parameter out of the response array
     *
     * @return string
     */
    protected function getResponseParameter($sParameter)
    {
        $aResponse = $this->getResponse();
        $mReturn = ($aResponse) ? $aResponse[$sParameter] : '';

        return $mReturn;
    }

    /**
     * Returns shopid used for ratepay payment
     */
    public function getFcpoRatepayShopId()
    {
        return $this->getRequestParameter('add_paydata[shop_id]');
    }


    /**
     * Get the bankaccount holder of this order out of the response array
     *
     * @return string
     */
    public function getFcpoBankaccountholder()
    {
        return $this->getResponseParameter('clearing_bankaccountholder');
    }

    /**
     * Get the bankname of this order out of the response array
     *
     * @return string
     */
    public function getFcpoBankname()
    {
        return $this->getResponseParameter('clearing_bankname');
    }

    /**
     * Get the bankcode of this order out of the response array
     *
     * @return string
     */
    public function getFcpoBankcode()
    {
        return $this->getResponseParameter('clearing_bankcode');
    }

    /**
     * Get the banknumber of this order out of the response array
     *
     * @return string
     */
    public function getFcpoBanknumber()
    {
        return $this->getResponseParameter('clearing_bankaccount');
    }

    /**
     * Get the BIC code of this order out of the response array
     *
     * @return string
     */
    public function getFcpoBiccode()
    {
        return $this->getResponseParameter('clearing_bankbic');
    }

    /**
     * Get the IBAN number of this order out of the response array
     *
     * @return string
     */
    public function getFcpoIbannumber()
    {
        return $this->getResponseParameter('clearing_bankiban');
    }

    /**
     * Get the capturable amount left
     * Returns order sum if the was no capture before
     * Returns order sum minus prior captures if there were captures before
     *
     * @return double
     */
    public function getFcpoCapturableAmount()
    {
        $oTransaction = $this->getLastStatus();
        $dReceivable = 0;
        if ($oTransaction != false) {
            $dReceivable = $oTransaction->fcpotransactionstatus__fcpo_receivable->value;
        }
        return $this->oxorder__oxtotalordersum->value - $dReceivable;
    }

    /**
     * Function whitch cheks if article stock is valid.
     * If not displays error and returns false.
     *
     * @param object $oBasket basket object
     *
     * @return null
     * @throws Exception
     *
     */
    public function validateStock($oBasket)
    {
        $oConfig = $this->_oFcpoHelper->fcpoGetConfig();
        $blReduceStockBefore = !(bool)$oConfig->getConfigParam('blFCPOReduceStock');
        $blCheckProduct = (
            $blReduceStockBefore &&
            $this->_isRedirectAfterSave()
        ) ? false : true;

        if ($blCheckProduct) {
            parent::validateStock($oBasket);
        }

        foreach ($oBasket->getContents() as $key => $oContent) {
            try {
                $oProd = $oContent->getArticle($blCheckProduct);
            } catch (NoArticleException $oEx) {
                $oBasket->removeItem($key);
                throw $oEx;
            } catch (ArticleInputException $oEx) {
                $oBasket->removeItem($key);
                throw $oEx;
            }

            if ($blCheckProduct == true) {
                // check if its still available
                $dArtStockAmount = $oBasket->getArtStockInBasket($oProd->getId(), $key);
                $iOnStock = $oProd->checkForStock($oContent->getAmount(), $dArtStockAmount);
                if ($iOnStock != true) {
                    $oEx = oxNew(OutOfStockException::class);
                    $oEx->setMessage('EXCEPTION_OUTOFSTOCK_OUTOFSTOCK');
                    $oEx->setArticleNr($oProd->oxarticles__oxartnum->value);
                    $oEx->setProductId($oProd->getId());
                    $oEx->setBasketIndex($key);

                    if (!is_numeric($iOnStock)) {
                        $iOnStock = 0;
                    }
                    $oEx->setRemainingAmount($iOnStock);
                    throw $oEx;
                }
            }
        }
    }

    /**
     * Returns stock of article in basket, including bundle article
     *
     * @param object $oBasket       basket object
     * @param string $sArtId        article id
     * @param string $sExpiredArtId item id of updated article
     *
     * @return double
     */
    public function fcGetArtStockInBasket($oBasket, $sArtId, $sExpiredArtId = null)
    {
        $dArtStock = 0;

        $aContents = $oBasket->getContents();
        foreach ($aContents as $sItemKey => $oOrderArticle) {
            if ($oOrderArticle && ($sExpiredArtId == null || $sExpiredArtId != $sItemKey)) {
                $oArticle = $oOrderArticle->getArticle(true);
                if ($oArticle->getId() == $sArtId) {
                    $dArtStock += $oOrderArticle->getAmount();
                }
            }
        }

        return $dArtStock;
    }

    /**
     * Returns mandate filename if existing for this order
     *
     * @return mixed
     */
    public function fcpoGetMandateFilename()
    {
        $sOxid = $this->getId();
        $sQuery = "SELECT fcpo_filename FROM fcpopdfmandates WHERE oxorderid = '{$sOxid}'";
        $sFile = $this->_oFcpoDb->GetOne($sQuery);

        return $sFile;
    }

    /**
     * Returns transaction status of order
     *
     * @return array
     */
    public function fcpoGetStatus()
    {
        $sQuery = "SELECT oxid FROM fcpotransactionstatus WHERE fcpo_txid = '{$this->oxorder__fcpotxid->value}' ORDER BY oxid ASC";
        $aRows = $this->_oFcpoDb->getAll($sQuery);

        $aStatus = [];
        foreach ($aRows as $aRow) {
            $oTransactionStatus = $this->_oFcpoHelper->getFactoryObject(FcPoTransactionStatus::class);
            $sTransactionStatusOxid = (isset($aRow[0])) ? $aRow[0] : $aRow['oxid'];
            $oTransactionStatus->load($sTransactionStatusOxid);
            $aStatus[] = $oTransactionStatus;
        }

        return $aStatus;
    }

    /**
     * Method checks via current paymenttype is of payone paypal type
     *
     * @return boolean
     */
    public function fcIsPayPalOrder()
    {
        $blReturn = false;
        if ($this->oxorder__oxpaymenttype->value == 'fcpopaypal' || $this->oxorder__oxpaymenttype->value == 'fcpopaypal_express') {
            $blReturn = true;
        }
        return $blReturn;
    }

    /**
     * Handle authorization of current order
     *
     * @param bool           $blReturnRedirectUrl
     * @param PaymentGateway $oPayGateway
     * @return boolean
     */
    public function fcHandleAuthorization($blReturnRedirectUrl = false, $oPayGateway = null)
    {
        $oConfig = $this->_oFcpoHelper->fcpoGetConfig();
        $aDynvalueForm = $this->_oFcpoHelper->fcpoGetRequestParameter('dynvalue');
        if ($this->oxorder__oxpaymenttype->value == 'fcpoklarna_directdebit' && $this->_oFcpoHelper->fcpoGetSessionVariable('klarna_authorization_token') == '') {
            $this->_oFcpoHelper->fcpoSetSessionVariable('klarna_authorization_token', $aDynvalueForm['klarna_authorization_token']);
        }
        $aDynvalue = $this->_oFcpoHelper->fcpoGetSessionVariable('dynvalue');
        $aDynvalue = $aDynvalue ? $aDynvalue : $this->_oFcpoHelper->fcpoGetRequestParameter('dynvalue');

        $blPresaveOrder = (bool)$oConfig->getConfigParam('blFCPOPresaveOrder');
        if ($blPresaveOrder == true) {
            $sOrderNr = $this->_fcpoGetNextOrderNr();
            $this->oxorder__oxordernr = new Field($sOrderNr, Field::T_RAW);
        }

        $oPORequest = $this->_oFcpoHelper->getFactoryObject(FcPoRequest::class);
        $oPayment = $this->_oFcpoHelper->getFactoryObject(Payment::class);
        $oPayment->load($this->oxorder__oxpaymenttype->value);
        $sAuthorizationType = $oPayment->oxpayments__fcpoauthmode->value;

        $sRefNr = $oPORequest->getRefNr($this);

        $aResponse = $oPORequest->sendRequestAuthorization($sAuthorizationType, $this, $this->getOrderUser(), $aDynvalue, $sRefNr);
        $sMode = $oPayment->fcpoGetMode($aDynvalue);
        $mResult = $this->_fcpoHandleAuthorizationResponse($aResponse, $oPayGateway, $sRefNr, $sMode, $sAuthorizationType, $blReturnRedirectUrl);

        return $mResult;
    }

    /**
     * Method will be usually called at the end of an order and decides wether
     * clearingdata should be offered or not
     *
     * @param $oOrder
     * @return bool
     */
    public function fcpoShowClearingData()
    {
        $sPaymentId = $this->oxorder__oxpaymenttype->value;

        $blShow = (
            ($this->oxorder__fcpoauthmode == 'authorization' && $sPaymentId == 'fcpoinvoice') ||
            ($sPaymentId == 'fcpopayadvance')
        );

        return $blShow;
    }

    /**
     * Returns new valid ordernr. Method depends on shop version
     *
     * @return string
     */
    protected function _fcpoGetNextOrderNr(): string
    {
        $oCounter = $this->_oFcpoHelper->getFactoryObject(Counter::class);
        return $oCounter->getNext($this->getCounterIdent());
    }

    /**
     * Returns the numeric code which determines if order has not beeing checked
     *
     * @return int
     */
    protected function _fcpoGetOrderNotChecked()
    {
        $iOrderNotChecked = $this->_oFcpoHelper->fcpoGetSessionVariable('fcpoordernotchecked');
        if (!$iOrderNotChecked || $iOrderNotChecked != 1) {
            $iOrderNotChecked = 0;
        }

        return $iOrderNotChecked;
    }

    /**
     * Cares about validation of authorization request
     *
     * @param array  $aResponse
     * @param object $oPayGateway
     * @param string $sRefNr
     * @param string $sMode
     * @param string $sAuthorizationType
     * @param bool   $blReturnRedirectUrl
     * @return boolean
     */
    protected function _fcpoHandleAuthorizationResponse($aResponse, $oPayGateway, $sRefNr, $sMode, $sAuthorizationType, $blReturnRedirectUrl)
    {
        $mReturn = false;

        if ($aResponse['status'] == 'ERROR') {
            $this->_fcpoHandleAuthorizationError($aResponse, $oPayGateway);
            $mReturn = false;
        } elseif (in_array($aResponse['status'], ['APPROVED', 'PENDING'])) {
            $this->_fcpoHandleAuthorizationApproved($aResponse, $sRefNr, $sAuthorizationType, $sMode);
            $mReturn = true;
        } elseif ($aResponse['status'] == 'REDIRECT') {
            $mReturn = $this->_fcpoHandleAuthorizationRedirect($aResponse, $sRefNr, $sAuthorizationType, $sMode, $blReturnRedirectUrl);
        }

        return $mReturn;
    }

    /**
     * Set flag for dynamic set as redirect payment into session
     *
     * @param bool $blFlaggedAsRedirect
     * @return void
     */
    protected function _fcpoFlagOrderPaymentAsRedirect($blFlaggedAsRedirect = true)
    {
        $this->_oFcpoHelper->fcpoSetSessionVariable('blDynFlaggedAsRedirectPayment', $blFlaggedAsRedirect);
    }

    /**
     * Handles case of redirect type authorization
     *
     * @param array  $aResponse
     * @param string $sRefNr
     * @param string $sAuthorizationType
     * @param string $sMode
     * @param bool   $blReturnRedirectUrl
     * @return void
     */
    protected function _fcpoHandleAuthorizationRedirect($aResponse, $sRefNr, $sAuthorizationType, $sMode, $blReturnRedirectUrl)
    {
        $this->_fcpoFlagOrderPaymentAsRedirect();
        $oConfig = $this->_oFcpoHelper->fcpoGetConfig();
        $oUtils = $this->_oFcpoHelper->fcpoGetUtils();
        $iOrderNotChecked = $this->_fcpoGetOrderNotChecked();

        $blPresaveOrder = (bool)$oConfig->getConfigParam('blFCPOPresaveOrder');
        if ($blPresaveOrder == true) {
            $this->_blFinishingSave = false;
            $this->save();
        }

        $this->_oFcpoHelper->fcpoSetSessionVariable('fcpoTxid', $aResponse['txid']);
        $this->_oFcpoHelper->fcpoSetSessionVariable('fcpoAuthMode', $sAuthorizationType);
        $this->_oFcpoHelper->fcpoSetSessionVariable('fcpoMode', $sMode);

        $this->oxorder__fcpotxid = new Field($aResponse['txid'], Field::T_RAW);
        $this->oxorder__fcporefnr = new Field($sRefNr, Field::T_RAW);
        $this->oxorder__fcpoauthmode = new Field($sAuthorizationType, Field::T_RAW);
        $this->oxorder__fcpomode = new Field($sMode, Field::T_RAW);
        $this->oxorder__fcpoordernotchecked = new Field($iOrderNotChecked, Field::T_RAW);

        if ($blPresaveOrder == true) {
            $this->oxorder__oxtransstatus = new Field('INCOMPLETE');
            $this->oxorder__oxfolder = new Field('ORDERFOLDER_PROBLEMS');
            $this->_blFinishingSave = false;
            $this->save();
            $this->_oFcpoHelper->fcpoSetSessionVariable('fcpoOrderNr', $this->oxorder__oxordernr->value);
            $this->_fcpoCheckReduceBefore();
        }

        if ($blReturnRedirectUrl == true) {
            return $aResponse['redirecturl'];
        } else {
            if ($this->isPayOneIframePayment()) {
                $this->_oFcpoHelper->fcpoSetSessionVariable('fcpoRedirectUrl', $aResponse['redirecturl']);
                $sRedirectUrl = $oConfig->getCurrentShopUrl() . 'index.php?cl=fcpayoneiframe';
            } else {
                $sRedirectUrl = $aResponse['redirecturl'];
            }
            $oUtils->redirect($sRedirectUrl, false);
        }
    }

    /**
     * Reduces stock of article before if its configured this way and a redirect payment has been used
     *
     * @return void
     */
    protected function _fcpoCheckReduceBefore()
    {
        $oConfig = $this->_oFcpoHelper->fcpoGetConfig();
        $sPaymentId = $this->oxorder__oxpaymenttype->value;
        $blReduceStockBefore = !(bool)$oConfig->getConfigParam('blFCPOReduceStock');
        $blIsRedirectPayment = FcPayOnePayment::fcIsPayOneRedirectType($sPaymentId);

        if ($blReduceStockBefore && $blIsRedirectPayment) {
            $aOrderArticles = $this->getOrderArticles();
            foreach ($aOrderArticles as $oOrderArticle) {
                $oOrderArticle->updateArticleStock($oOrderArticle->oxorderarticles__oxamount->value * (-1), $oConfig->getConfigParam('blAllowNegativeStock'));
            }
        }
    }

    /**
     * Handles case of approved authorization
     *
     * @param array  $aResponse
     * @param string $sRefNr
     * @param string $sAuthorizationType
     * @param string $sMode
     * @return void
     */
    protected function _fcpoHandleAuthorizationApproved($aResponse, $sRefNr, $sAuthorizationType, $sMode)
    {
        $this->_fcpoFlagOrderPaymentAsRedirect(null);
        $iOrderNotChecked = $this->_fcpoGetOrderNotChecked();
        $sPaymentId = $this->oxorder__oxpaymenttype->value;

        $this->oxorder__fcpotxid = new Field($aResponse['txid'], Field::T_RAW);
        $this->oxorder__fcporefnr = new Field($sRefNr, Field::T_RAW);
        $this->oxorder__fcpoauthmode = new Field($sAuthorizationType, Field::T_RAW);
        $this->oxorder__fcpomode = new Field($sMode, Field::T_RAW);
        $this->oxorder__fcpoordernotchecked = new Field($iOrderNotChecked, Field::T_RAW);
        $this->_oFcpoDb->execute("UPDATE fcporefnr SET fcpo_txid = '{$aResponse['txid']}' WHERE fcpo_refnr = '" . $sRefNr . "'");
        if ($sPaymentId == 'fcpobarzahlen' && isset($aResponse['add_paydata[instruction_notes]'])) {
            $sBarzahlenHtml = urldecode($aResponse['add_paydata[instruction_notes]']);
            $this->_oFcpoHelper->fcpoSetSessionVariable('sFcpoBarzahlenHtml', $sBarzahlenHtml);
        }

        $this->_fcpoSaveWorkorderId($sPaymentId, $aResponse);
        $this->_fcpoSaveClearingReference($sPaymentId, $aResponse);
        $this->_fcpoSaveProfileIdent($sPaymentId, $aResponse);
        $this->save();
    }

    /**
     * For certain payments it's mandatory to save workorderid
     *
     * @param string $sPaymentId
     * @param array  $aResponse
     * @return void
     */
    protected function _fcpoSaveWorkorderId($sPaymentId, $aResponse)
    {
        if (in_array($sPaymentId, $this->_aPaymentsWorkorderIdSave)) {
            $sWorkorderId = (
            isset($aResponse['add_paydata[workorderid]'])
            ) ?
                $aResponse['add_paydata[workorderid]'] :
                $this->_oFcpoHelper->fcpoGetSessionVariable('fcpoWorkorderId');

            if ($sWorkorderId) {
                $this->oxorder__fcpoworkorderid = new Field($sWorkorderId, Field::T_RAW);
                $this->_oFcpoHelper->fcpoDeleteSessionVariable('payolution_workorderid');
                $this->_oFcpoHelper->fcpoDeleteSessionVariable('fcpoWorkorderId');
                $this->_oFcpoHelper->fcpoDeleteSessionVariable('klarna_workorderid');
            }
        }
    }

    /**
     * For certain payments it's mandatory to save clearing reference
     *
     * @param string $sPaymentId
     * @param array  $aResponse
     * @return void
     */
    protected function _fcpoSaveClearingReference($sPaymentId, $aResponse)
    {
        if (in_array($sPaymentId, $this->_aPaymentsClearingReferenceSave)) {
            $sClearingReference = (isset($aResponse['add_paydata[clearing_reference]'])) ? $aResponse['add_paydata[clearing_reference]'] : false;
            if ($sClearingReference) {
                $this->oxorder__fcpoclearingreference = new Field($sClearingReference, Field::T_RAW);
            }
        }
    }

    /**
     * For certain payments it's mandatory to save (external) shopid/userid (e. g- ratepay payments)
     *
     * @param string $sPaymentId
     * @param array  $aResponse
     * @return void
     */
    protected function _fcpoSaveProfileIdent($sPaymentId, $aResponse)
    {
        if (in_array($sPaymentId, $this->_aPaymentsProfileIdentSave)) {
            $oRatePay = oxNew(FcPoRatepay::class);
            $sProfileId = $this->_oFcpoHelper->fcpoGetSessionVariable('ratepayprofileid');
            $aProfileData = $oRatePay->fcpoGetProfileData($sProfileId);
            $sRatePayShopId = $aProfileData['shopid'];
            $this->oxorder__fcpoprofileident = new Field($sRatePayShopId, Field::T_RAW);
        }
    }

    /**
     * Handles case of Authorization error
     *
     * @param array  $aResponse
     * @param object $oPayGateway
     * @return mixed int|bool
     */
    protected function _fcpoHandleAuthorizationError($aResponse, $oPayGateway)
    {
        $mReturn = false;
        $this->_fcpoFlagOrderPaymentAsRedirect(null);

        $sResponseErrorCode = (string)trim($aResponse['errorcode']);
        $sResponseCustomerMessage = (string)trim($aResponse['customermessage']);
        $sPaymenttype = $this->oxorder__oxpaymenttype->value;
        if ($sPaymenttype == 'fcpoamazonpay') {
            $sResponseErrorCode = $this->fcpoGetAmazonErrorMessage($aResponse['errorcode']);
            $sResponseCustomerMessage = $this->_fcpoGetAmazonSuccessCode($aResponse['errorcode']);
        }
        $this->_fcpoSetPayoneUserFlagsByAuthResponse($sResponseErrorCode, $sResponseCustomerMessage, $oPayGateway);
    }

    /**
     * Adds flag to user if there is one matching
     *
     * @param string $sResponseErrorCode
     * @param string $sResponseCustomerMessage
     * @param object $oPayGateway
     * @return void
     */
    protected function _fcpoSetPayoneUserFlagsByAuthResponse($sResponseErrorCode, $sResponseCustomerMessage, $oPayGateway)
    {
        $oUserFlag = oxNew(FcPoUserFlag::class);
        $blSuccess = $oUserFlag->fcpoLoadByErrorCode($sResponseErrorCode);

        if ($blSuccess) {
            $oUser = $this->getOrderUser();
            $oUser->fcpoAddPayoneUserFlag($oUserFlag);
        }
        $oPayGateway->fcSetLastErrorNr($sResponseErrorCode);
        $oPayGateway->fcSetLastError($sResponseCustomerMessage);
    }

    /**
     * Returns translated amazon specific error message
     *
     * @param $sErrorCode
     * @return string
     */
    public function fcpoGetAmazonErrorMessage($sErrorCode)
    {
        $sTranslateString = $this->fcpoGetAmazonErrorTranslationString($sErrorCode);
        $oLang = $this->_oFcpoHelper->fcpoGetLang();
        $sMessage = $oLang->translateString($sTranslateString);

        return $sMessage;
    }

    /**
     * Method returns (un)success code
     *
     * @param $aResponse
     * @param $sMessage
     * @return mixed int|bool
     */
    protected function _fcpoGetAmazonSuccessCode($sErrorCode)
    {
        $mRet = false;
        if ($sErrorCode) {
            $mRet = (int)$sErrorCode;
        }
        return $mRet;
    }

    /**
     * Returns translation string matching to errorcode
     *
     * @param $iSuccess
     * @return string
     */
    public function fcpoGetAmazonErrorTranslationString($iSuccess)
    {
        $iSuccess = (int)$iSuccess;

        switch ($iSuccess) {
            case self::FCPO_AMAZON_ERROR_INVALID_PAYMENT_METHOD:
                $sReturn = 'FCPO_AMAZON_ERROR_INVALID_PAYMENT_METHOD';
                break;
            case '109':
            case self::FCPO_AMAZON_ERROR_REJECTED:
                $sReturn = 'FCPO_AMAZON_ERROR_REJECTED';
                break;
            case self::FCPO_AMAZON_ERROR_PROCESSING_FAILURE:
                $sReturn = 'FCPO_AMAZON_ERROR_PROCESSING_FAILURE';
                break;
            case self::FCPO_AMAZON_ERROR_BUYER_EQUALS_SELLER:
                $sReturn = 'FCPO_AMAZON_ERROR_BUYER_EQUALS_SELLER';
                break;
            case self::FCPO_AMAZON_ERROR_PAYMENT_NOT_ALLOWED:
                $sReturn = 'FCPO_AMAZON_ERROR_PAYMENT_NOT_ALLOWED';
                break;
            case self::FCPO_AMAZON_ERROR_PAYMENT_PLAN_NOT_SET:
                $sReturn = 'FCPO_AMAZON_ERROR_PAYMENT_PLAN_NOT_SET';
                break;
            case self::FCPO_AMAZON_ERROR_SHIPPING_ADDRESS_NOT_SET:
                $sReturn = 'FCPO_AMAZON_ERROR_SHIPPING_ADDRESS_NOT_SET';
                break;
            case self::FCPO_AMAZON_ERROR_TRANSACTION_TIMED_OUT:
                $sReturn = 'FCPO_AMAZON_ERROR_TRANSACTION_TIMED_OUT';
                break;
            default:
                $sReturn = 'FCPO_AMAZON_ERROR_900';
        }

        return $sReturn;
    }

    /**
     * Returns wether given paymentid is of payone type
     *
     * @param string $sId
     * @param bool   $blIFrame
     * @return bool
     */
    protected function _fcpoIsPayonePaymentType($sId, $blIFrame = false)
    {
        if ($blIFrame) {
            $blReturn = FcPayOnePayment::fcIsPayOneIframePaymentType($sId);
        } else {
            $blReturn = FcPayOnePayment::fcIsPayOnePaymentType($sId);
        }

        return $blReturn;
    }

    /**
     * Returns true if appointed error occured
     *
     * @return bool
     */
    protected function _fcpoGetAppointedError()
    {
        return $this->_blFcPayOneAppointedError;
    }

    /**
     * Sets appointed error
     *
     * @param bool $blError appointed error indicator
     * @return void
     */
    protected function _fcpoSetAppointedError($blError = false)
    {
        $this->_blFcPayOneAppointedError = $blError;
    }
}
