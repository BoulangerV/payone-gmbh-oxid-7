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

namespace Fatchip\PayOne\Lib;

use Exception;
use OxidEsales\Eshop\Application\Model\BasketItem;
use OxidEsales\Eshop\Core\Config;
use OxidEsales\Eshop\Core\Database\Adapter\DatabaseInterface;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Exception\DatabaseConnectionException;
use OxidEsales\Eshop\Core\Language;
use OxidEsales\Eshop\Core\Model\BaseModel;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\Session;
use OxidEsales\Eshop\Core\ShopVersion;
use OxidEsales\Eshop\Core\Utils;
use OxidEsales\Eshop\Core\UtilsDate;
use OxidEsales\Eshop\Core\UtilsFile;
use OxidEsales\Eshop\Core\UtilsObject;
use OxidEsales\Eshop\Core\UtilsServer;
use OxidEsales\Eshop\Core\UtilsView;
use OxidEsales\Eshop\Core\ViewConfig;

class FcPoHelper extends BaseModel
{
    /**
     * oxconfig instance
     *
     * @var Config
     */
    protected static $_oConfig = null;

    /**
     * oxconfig instance
     *
     * @var Session
     */
    protected static $_oSession = null;

    /**
     * Flags if shop uses registry
     *
     * @var static boolean
     */
    protected static $_blUseRegistry = null;

    /**
     * Building essential stuff
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * oxSession instance getter
     *
     * @return Session
     */
    public function getSession()
    {
        if (self::$_oSession == null) {
            self::$_oSession = Registry::getSession();
        }

        return self::$_oSession;
    }

    /**
     * Returns a factory instance of given object
     *
     * @param string $sName
     * @return object
     */
    public function getFactoryObject($sName)
    {
        return oxNew($sName);
    }

    /**
     * Wrapper for ini get calls
     *
     * @param string $sConfigVar
     * @return mixed
     */
    public function fcpoIniGet($sConfigVar)
    {
        return ini_get($sConfigVar);
    }

    /**
     * Wrapper for returning if function with given name exists
     *
     * @param string $sFunctionName
     * @return bool
     */
    public function fcpoFunctionExists($sFunctionName)
    {
        return function_exists($sFunctionName);
    }

    /**
     * Wrapper for returning if file in given path exists
     *
     * @param string $sFilePath
     * @return bool
     */
    public function fcpoFileExists($sFilePath)
    {
        return file_exists($sFilePath);
    }

    /**
     * Creates an instance of a class
     *
     * @param string $sClassName
     * @param string $sIncludePath optional
     * @return object
     * @throws Exception
     */
    public function fcpoGetInstance($sClassName, $sIncludePath = "")
    {
        try {
            if ($sIncludePath) {
                include_once $sIncludePath;
            }
            $oObjInstance = new $sClassName();
        } catch (Exception $oEx) {
            throw $oEx;
        }

        return $oObjInstance;
    }

    /**
     * Wrapper method for getting a session variable
     *
     * @param string $sVariable
     * @return mixed
     */
    public function fcpoGetSessionVariable($sVariable)
    {
        return $this->getSession()->getVariable($sVariable);
    }

    /**
     * Wrapper method for setting a session variable
     *
     * @param string $sVariable
     * @param string $sValue
     * @return void
     */
    public function fcpoSetSessionVariable($sVariable, $sValue)
    {
        $this->getSession()->setVariable($sVariable, $sValue);
    }

    /**
     * Wrapper method for setting a session variable
     *
     * @param string $sVariable
     * @param string $sValue
     * @return void
     */
    public function fcpoDeleteSessionVariable($sVariable)
    {
        $this->getSession()->deleteVariable($sVariable);
    }

    /**
     * static Getter for config instance
     *
     * @param mixed
     * @return Config
     */
    public static function fcpoGetStaticConfig()
    {
        if (self::_useRegistry() === true) {
            $oReturn = Registry::getConfig();
        } else {
            $oReturn = Config::getInstance();
        }

        return $oReturn;
    }

    /**
     * oxConfig instance getter
     *
     * @return Config
     */
    public function fcpoGetConfig()
    {
        if (self::$_oConfig == null) {
            self::$_oConfig = Registry::getConfig();
        }

        return self::$_oConfig;
    }

    /**
     * Getter for session instance
     *
     * @return Session
     */
    public function fcpoGetSession()
    {
        return $this->getSession();
    }

    /**
     * Getter for database instance
     *
     * @param bool $blAssoc
     * @throws DatabaseConnectionException
     */
    public function fcpoGetDb(bool $blAssoc = false): ?DatabaseInterface
    {
        if ($blAssoc) {
            return DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC);
        } else {
            return DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_NUM);
        }
    }

    /**
     * Wrapper method for getting a request parameter
     *
     * @param string $sParameter
     * @return mixed
     */
    public function fcpoGetRequestParameter($sParameter)
    {
        $oRequest = Registry::get(\OxidEsales\Eshop\Core\Request::class);
        $mReturn = $oRequest->getRequestParameter($sParameter);

        return $mReturn;
    }

    /**
     * Returns a language Instance
     *
     * @return mixed
     */
    public function fcpoGetLang()
    {
        return oxNew(Language::class);
    }

    /**
     * Returns a utilsfile instance
     *
     * @return mixed
     */
    public function fcpoGetUtilsFile()
    {
        return oxNew(UtilsFile::class);
    }

    /**
     * Returns a utilsobject instance
     *
     * @return mixed
     */
    public function fcpoGetUtilsObject()
    {
        return oxNew(UtilsObject::class);
    }

    /**
     * Returns an instance of oxutils
     *
     * @return mixed
     */
    public function fcpoGetUtils()
    {
        return oxNew(Utils::class);
    }

    /**
     * Returns an instance of oxutilsview
     *
     * @return mixed
     */
    public function fcpoGetUtilsView()
    {
        return oxNew(UtilsView::class);
    }

    /**
     * Returns an instance of oxviewvonfig
     *
     * @return mixed
     */
    public function fcpoGetViewConfig()
    {
        return oxNew(ViewConfig::class);
    }

    /**
     * Returns an instance of oxutilserver
     *
     * @return mixed
     */
    public function fcpoGetUtilsServer()
    {
        return oxNew(UtilsServer::class);
    }

    /**
     * Returns an instance of oxUtilsDate
     *
     * @return mixed
     */
    public function fcpoGetUtilsDate()
    {
        return oxNew(UtilsDate::class);
    }

    /**
     * Method returns current module version
     *
     * @return string
     */
    public static function fcpoGetStaticModuleVersion()
    {
        return '1.5.0';
    }

    /**
     * Method returns current module version
     *
     * @return string
     */
    public function fcpoGetModuleVersion()
    {
        include_once __DIR__ . "/../metadata.php";
        return $aModule['version'];
    }

    /**
     * Returns the superglobal $_FILES
     *
     * @return array
     */
    public function fcpoGetFiles()
    {
        return $_FILES;
    }

    /**
     * Processing and returning result string
     *
     * @param string $sContent
     * @return string
     */
    public function fcpoProcessResultString($sContent)
    {
        return $sContent;
    }

    /**
     * Output content as header
     *
     * @param string $sContent
     * @return string
     */
    public function fcpoHeader($sContent)
    {
        header($sContent);
    }

    /**
     * Wrapper for php exit on beeing able to be mocked
     *
     * @return void
     */
    public function fcpoExit()
    {
        exit;
    }

    /**
     * Retunrs if incoming class name exists or not
     *
     * @param string $sClassName
     * @return bool
     */
    public function fcpoCheckClassExists($sClassName)
    {
        return class_exists($sClassName);
    }

    /**
     * Returns current integrator version
     *
     * @return string
     */
    public function fcpoGetIntegratorVersion()
    {
        $oConfig = $this->fcpoGetConfig();
        $sEdition = $oConfig->getActiveShop()->oxshops__oxedition->value;
        $sVersion = $oConfig->getActiveView()->getShopVersion();
        return $sEdition . $sVersion;
    }

    /**
     * Returns shopversion as integer
     *
     * @return int
     */
    public function fcpoGetIntShopVersion()
    {
        $oConfig = $this->fcpoGetConfig();
        $sVersion = $oConfig->getActiveShop()->oxshops__oxversion->value;
        $iVersion = (int)str_replace('.', '', $sVersion);
        // fix for ce/pe 4.10.0+
        if ($iVersion > 1000) {
            $iVersion *= 10;
        } else {
            while ($iVersion < 1000) {
                $iVersion = $iVersion * 10;
            }
        }
        return $iVersion;
    }

    /**
     * Returns the current shop name
     *
     * @return string
     */
    public function fcpoGetShopName()
    {
        $oConfig = $this->fcpoGetConfig();

        return $oConfig->getActiveShop()->oxshops__oxname->value;
    }

    /**
     * Returns help url
     *
     * @return string
     */
    public function fcpoGetHelpUrl()
    {
        return "https://www.payone.de";
    }

    /**
     *
     *
     * @return array
     */
    public function fcpoGetPayoneStatusList()
    {
        return [
            'appointed',
            'capture',
            'paid',
            'underpaid',
            'cancelation',
            'refund',
            'debit',
            'reminder',
            'vauthorization',
            'vsettlement',
            'transfer',
            'invoice',
        ];
    }

    /**
     * Returns a static instance of given object name
     *
     * @param $sObjectName
     * @return mixed
     */
    public function getStaticInstance($sObjectName)
    {
        return Registry::get($sObjectName);
    }

    /**
     * Loads shop version and formats it in a certain way
     *
     * @return string
     */
    public function fcpoGetIntegratorId()
    {
        $oConfig = $this->fcpoGetConfig();

        $sEdition = $oConfig->getActiveShop()->oxshops__oxedition->value;
        if ($sEdition == 'CE') {
            return '2027000';
        } elseif ($sEdition == 'PE') {
            return '2028000';
        } elseif ($sEdition == 'EE') {
            return '2029000';
        }
        return '';
    }

    /**
     * Item price in smallest available unit
     *
     * @param BasketItem/double $mValue
     * @return int
     */
    public function fcpoGetCentPrice($mValue)
    {
        $oConfig = $this->fcpoGetConfig();
        $dBruttoPrice = 0.00;
        if ($mValue instanceof BasketItem) {
            $oPrice = $mValue->getPrice();
            $dBruttoPricePosSum = $oPrice->getBruttoPrice();
            $dAmount = $mValue->getAmount();
            $dBruttoPrice = round($dBruttoPricePosSum / $dAmount, 2);
        } elseif (is_float($mValue)) {
            $dBruttoPrice = $mValue;
        }

        $oCur = $oConfig->getActShopCurrencyObject();
        $dFactor = (double)pow(10, $oCur->decimal);

        return ($dBruttoPrice * $dFactor);
    }

    /**
     * Returns shop version
     *
     * @return string
     */
    public function fcpoGetShopVersion(): string
    {
        return oxNew(ShopVersion::class)->getVersion();
    }

    /**
     * Static getter for checking newer available methods and classes in shop
     *
     * @return bool
     */
    protected static function _useRegistry()
    {
        if (self::$_blUseRegistry === null) {
            self::$_blUseRegistry = false;
            if (class_exists('Registry')) {
                $oConf = Registry::getConfig();
                if (method_exists($oConf, 'getRequestParameter')) {
                    self::$_blUseRegistry = true;
                }
            }
        }
        return self::$_blUseRegistry;
    }

    /**
     * Returns path to modules dir
     *
     * @param bool $absolute mode - absolute/relative path
     *
     * @return string
     */
    public function getModulesDir($absolute = true)
    {
        if ($absolute) {
            $oConfig = $this->fcpoGetConfig();
            return $oConfig->getConfigParam('sShopDir') . 'modules/';
        } else {
            return 'modules/';
        }
    }
}
