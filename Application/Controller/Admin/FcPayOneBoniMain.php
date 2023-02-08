<?php

namespace Fatchip\PayOne\Application\Controller\Admin;


use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\UtilsView;

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
class FcPayOneBoniMain extends FcPayOneAdminDetails
{

    /**
     * Current class template name
     *
     * @var string
     */
    protected $_sThisTemplate = '@fcpayone/admin/fcpayone_boni_main';

    /**
     * Definitions of multilang files
     *
     * @var array
     */
    protected $_aMultiLangFields = array(
        'sFCPOApprovalText',
        'sFCPODenialText',
    );

    /**
     * Boni default values
     *
     * @var array
     */
    protected $_aDefaultValues = array(
        'sFCPOMalusPPB' => '0',
        'sFCPOMalusPHB' => '150',
        'sFCPOMalusPAB' => '300',
        'sFCPOMalusPKI' => '250',
        'sFCPOMalusPNZ' => '400',
        'sFCPOMalusPPV' => '500',
        'sFCPOMalusPPF' => '400',
    );

    /**
     * Assignment of validation messages
     *
     * @var array
     */
    protected $_aValidateCode2Message = array(
        '1' => 'FCPO_BONI_ERROR_SET_TO_BONIVERSUM_PERSON',
        '2' => 'FCPO_BONI_ERROR_DEACTIVATED_REGULAR_ADDRESSCHECK',
        '3' => 'FCPO_BONI_ERROR_NO_BONIADDRESSCHECK_SET',
        '4' => 'FCPO_BONI_ERROR_DEACTIVATED_BONI_ADDRESSCHECK',
        '5' => 'FCPO_BONI_ERROR_SET_TO_BASIC',
        '6' => 'FCPO_BONI_ERROR_SET_TO_PERSON'
    );

    /**
     * Collection of validation codes processed via saving
     *
     * @var array
     */
    protected $_aValidationCodes = null;


    /**
     * Loads payment protection configuration and passes them to Smarty engine, returns
     * name of template file "fcpayone_boni_main.tpl".
     *
     * @return string
     */
    public function render()
    {
        $sReturn = parent::render();
        $oLang = $this->_oFcPoHelper->fcpoGetLang();

        $iLang = $this->_oFcPoHelper->fcpoGetRequestParameter("subjlang");
        if (empty($iLang)) {
            $iLang = '0';
        }

        $this->_aViewData["subjlang"] = $iLang;

        $oConfig = $this->_oFcPoHelper->fcpoGetConfig();
        $sShopId = $oConfig->getShopId();

        $aConfigs = $this->_oFcPoConfigExport->fcpoGetConfig($sShopId, $iLang);

        $this->_aViewData["confbools"] = $aConfigs['bools'];
        $this->_aViewData["confstrs"] = $aConfigs['strs'];
        $this->_aViewData['sHelpURL'] = $this->_oFcPoHelper->fcpoGetHelpUrl();

        $aConfStrs = $this->_aViewData["confstrs"];
        foreach ($this->_aDefaultValues as $sVarName => $sValue) {
            if (!array_key_exists($sVarName, $aConfStrs) || empty($aConfStrs[$sVarName])) {
                $aConfStrs[$sVarName] = $sValue;
            }
        }
        $this->_aViewData["confstrs"] = $aConfStrs;

        return $sReturn;
    }

    /**
     * Saves changed configuration parameters.
     *
     * @return void
     */
    public function save(): void
    {
        $oConfig = $this->_oFcPoHelper->fcpoGetConfig();

        $iLang = $this->_oFcPoHelper->fcpoGetRequestParameter("subjlang");
        if (empty($iLang)) {
            $iLang = '0';
        }

        $aConfBools = $this->_oFcPoHelper->fcpoGetRequestParameter("confbools");
        $aConfStrs = $this->_oFcPoHelper->fcpoGetRequestParameter("confstrs");
        if (is_array($aConfBools)) {
            foreach ($aConfBools as $sVarName => $sVarVal) {
                $oConfig->saveShopConfVar("bool", $sVarName, $sVarVal);
            }
        }

        if (is_array($aConfStrs)) {
            foreach ($aConfStrs as $sVarName => $sVarVal) {
                if (in_array($sVarName, $this->_aMultiLangFields)) {
                    $sVarName = $sVarName . '_' . $iLang;
                }
                $oConfig->saveShopConfVar("str", $sVarName, $sVarVal);
            }
        }

        $this->_fcpoValidateAddresscheckType();
    }

    /**
     * Validating addresstype. Fix setting if needed and respond with message
     * of changes
     *
     * @return void
     */
    protected function _fcpoValidateAddresscheckType()
    {
        $this->_aValidationCodes = array();
        $this->_fcpoCheckIssetBoniAddresscheck();
        $this->_fcpoValidateDuplicateAddresscheck();
        $this->_fcpoValidateAddresscheckBasic();
        $this->_fcpoValidateAddresscheckPerson();
        $this->_fcpoValidateAddresscheckBoniversum();
        $this->_fcpoDisplayValidationMessages();
    }

    /**
     * Checks if mandatory boniaddresscheck is set on active bonicheck
     * (only both or nothing is allowed)
     *
     * @return void
     */
    protected function _fcpoCheckIssetBoniAddresscheck()
    {
        $blBoniCheckActive = $this->_fcpoCheckBonicheckIsActive();
        $blBoniAddresscheckActive = $this->_fcpoBoniAddresscheckActive();

        $blSetBoniAddresscheckActive = (
            $blBoniCheckActive &&
            !$blBoniAddresscheckActive
        );

        if ($blSetBoniAddresscheckActive) {
            $oConfig = $this->_oFcPoHelper->fcpoGetConfig();
            $oConfig->saveShopConfVar("str", 'sFCPOConsumerAddresscheck', 'BA');
            $this->_aValidationCodes[] = 3;
        }
    }

    /**
     * Method returns if boni check is in active use
     */
    protected function _fcpoCheckBonicheckIsActive(): bool
    {
        $oConfig = $this->_oFcPoHelper->fcpoGetConfig();
        $sFCPOBonicheck = $oConfig->getConfigParam('sFCPOBonicheck');

        return $sFCPOBonicheck !== null &&
            $sFCPOBonicheck !== '-1';
    }

    /**
     * Checks if there is a value set for boni addresscheck
     *
     * @return bool
     */
    protected function _fcpoBoniAddresscheckActive()
    {
        $oConfig = $this->_oFcPoHelper->fcpoGetConfig();
        $sFCPOConsumerAddresscheck =
            $oConfig->getConfigParam('sFCPOConsumerAddresscheck');

        return (bool)$sFCPOConsumerAddresscheck;
    }

    /**
     * Check if bonicheck and regular adresscheck is set to active
     * simultanously and fix setting if needed
     *
     * @return void
     */
    protected function _fcpoValidateDuplicateAddresscheck()
    {
        $blBoniCheckActive = $this->_fcpoCheckBonicheckIsActive();
        $blBoniAddressCheckActive = $this->_fcpoBoniAddresscheckActive();

        $blDeactivateBoniAddressCheck = (
            !$blBoniCheckActive &&
            $blBoniAddressCheckActive
        );

        if ($blDeactivateBoniAddressCheck) {
            $this->_fcpoDeactivateBoniAdresscheck();
            $this->_aValidationCodes[] = 4;
        }

        $blRegularAddressCheckActive =
            $this->_fcpoCheckRegularAddressCheckActive();
        $blDuplicateAddressCheck = (
            $blBoniCheckActive &&
            $blRegularAddressCheckActive
        );

        if ($blDuplicateAddressCheck) {
            $this->_fcpoDeactivateRegularAddressCheck();
            $this->_aValidationCodes[] = 2;
        }
    }

    /**
     * Deactivates bonicheck addresscheck type
     *
     * @return void
     */
    protected function _fcpoDeactivateBoniAdresscheck()
    {
        $oConfig = $this->_oFcPoHelper->fcpoGetConfig();
        $oConfig->saveShopConfVar("str", 'sFCPOConsumerAddresscheck', null);
    }

    /**
     * Returns if regular addresscheck is set active
     */
    protected function _fcpoCheckRegularAddressCheckActive(): bool
    {
        $oConfig = $this->_oFcPoHelper->fcpoGetConfig();
        $sFCPOAddresscheck = $oConfig->getConfigParam('sFCPOAddresscheck');

        return $sFCPOAddresscheck !== null &&
            $sFCPOAddresscheck !== 'NO';
    }

    /**
     * Deactivates regular address check setting to 'no addresscheck'
     *
     * @return void
     */
    protected function _fcpoDeactivateRegularAddressCheck()
    {
        $oConfig = $this->_oFcPoHelper->fcpoGetConfig();
        $oConfig->saveShopConfVar("str", 'sFCPOAddresscheck', 'NO');
    }

    /**
     * Validate settings and check if this must be switched to basic addresscheck depending on
     * selected bonicheck
     *
     * @return int
     */
    protected function _fcpoValidateAddresscheckBasic()
    {
        $oConfig = $this->_oFcPoHelper->fcpoGetConfig();
        $aConfStrs = $this->_oFcPoHelper->fcpoGetRequestParameter("confstrs");
        $aMatchingBoniChecks = array('IH', 'IA', 'IB');
        $aMatchingAddressChecks = array('BB');
        $blSwitchToBasic = (
            isset($aConfStrs['sFCPOBonicheck']) &&
            isset($aConfStrs['sFCPOConsumerAddresscheck']) &&
            in_array($aConfStrs['sFCPOBonicheck'], $aMatchingBoniChecks) &&
            in_array($aConfStrs['sFCPOConsumerAddresscheck'], $aMatchingAddressChecks)
        );
        if ($blSwitchToBasic) {
            $this->_aValidationCodes[] = 5;
            $oConfig->saveShopConfVar("str", 'sFCPOConsumerAddresscheck', 'BA');
        }
    }

    /**
     * Validate settings and check if this must be switched to person addresscheck depending on
     * selected bonicheck
     *
     * @return int
     */
    protected function _fcpoValidateAddresscheckPerson()
    {
        $oConfig = $this->_oFcPoHelper->fcpoGetConfig();
        $aConfStrs = $this->_oFcPoHelper->fcpoGetRequestParameter("confstrs");
        $aMatchingBoniChecks = array('IH', 'IA', 'IB');
        $aMatchingAddressChecks = array('PB');

        $blSwitchToPerson = (
            isset($aConfStrs['sFCPOBonicheck']) &&
            isset($aConfStrs['sFCPOConsumerAddresscheck']) &&
            in_array($aConfStrs['sFCPOBonicheck'], $aMatchingBoniChecks) &&
            in_array($aConfStrs['sFCPOConsumerAddresscheck'], $aMatchingAddressChecks)
        );

        if ($blSwitchToPerson) {
            $this->_aValidationCodes[] = 6;
            $oConfig->saveShopConfVar("str", 'sFCPOConsumerAddresscheck', 'PE');
        }
    }

    /**
     * Validates addresscheck related to boniversum. Correct settings and return error
     * code for notifying user
     *

     */
    protected function _fcpoValidateAddresscheckBoniversum()
    {
        $oConfig = $this->_oFcPoHelper->fcpoGetConfig();
        $sFCPOBonicheck = $oConfig->getConfigParam('sFCPOBonicheck');
        $sFCPOConsumerAddresscheck = $oConfig->getConfigParam('sFCPOConsumerAddresscheck');
        $blCorrectSetting = (
            is_string($sFCPOBonicheck) &&
            is_string($sFCPOConsumerAddresscheck) &&
            (
                $sFCPOBonicheck == 'CE' &&
                $sFCPOConsumerAddresscheck != 'PB'
            )
        );

        if ($blCorrectSetting) {
            // addresschecktype ALWAYS has to be PB if bonichecktype is CE
            // => Set error code ...
            $this->_aValidationCodes[] = 1;
            // ... and fix setting
            $oConfig->saveShopConfVar("str", 'sFCPOConsumerAddresscheck', 'PB');
        }
    }

    /**
     * If there have been validation adjustments, cumulate and
     * present them
     *
     * @return void
     */
    protected function _fcpoDisplayValidationMessages()
    {
        // collect messages
        $sTranslatedMessage = "";
        foreach ($this->_aValidationCodes as $_aValidationCode) {
            $blSkipCode = !(
                $_aValidationCode > 0 &&
                isset($this->_aValidateCode2Message[$_aValidationCode])
            );
            if ($blSkipCode) continue;
            $sTranslateString = $this->_aValidateCode2Message[$_aValidationCode];
            $oLang = $this->_oFcPoHelper->fcpoGetLang();
            $sTranslatedMessage .= $oLang->translateString($sTranslateString) . "<br>";
        }

        if ($sTranslatedMessage !== '' && $sTranslatedMessage !== '0') {
            $oUtilsView = Registry::getUtilsView();
            $oUtilsView->addErrorToDisplay($sTranslatedMessage);
        }
    }

    /**
     * Displays a message in admin frontend if there is an error code present
     *
     * @param $iValidateCode
     */
    public function _fcpoDisplayMessage($iValidateCode): void
    {
        if ($iValidateCode > 0 && isset($this->_aValidateCode2Message[$iValidateCode])) {
            $oxUtilsView = Registry::get(UtilsView::class);
            $sTranslateString = $this->_aValidateCode2Message[$iValidateCode];
            $oLang = $this->_oFcPoHelper->fcpoGetLang();
            $sTranslatedMessage = $oLang->translateString($sTranslateString);
            $oxUtilsView->addErrorToDisplay($sTranslatedMessage);
        }
    }

    /**
     * Method decides if regular addresscheck can be used. Depends on bonicheck
     * is inactive/not in use
     *
     * @return bool
     */
    public function fcpoShowRegularAddresscheck()
    {
        $blBoniCheckActive = $this->_fcpoCheckBonicheckIsActive();
        if ($blBoniCheckActive) {
            $this->_fcpoDeactivateRegularAddressCheck();
        }

        return !$blBoniCheckActive;
    }
}
