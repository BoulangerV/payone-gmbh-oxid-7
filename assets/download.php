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

namespace Fatchip\PayOne;

use Fatchip\PayOne\Lib\FcPoHelper;
use Fatchip\PayOne\Lib\FcPoRequest;
use OxidEsales\Eshop\Application\Controller\FrontendController;
use OxidEsales\Eshop\Application\Model\Payment;
use OxidEsales\Eshop\Core\DatabaseProvider;

set_time_limit(0);
ini_set('memory_limit', '1024M');
ini_set('log_errors', 1);
ini_set('error_log', 'error.log');

include_once dirname(__FILE__) . "/../../../bootstrap.php";

/**
 * Description of fcPayOneMandateDownload
 *
 * @author Robert
 */
class FcPayOneMandateDownload extends FrontendController
{
    /**
     * Helper object for dealing with different shop versions
     *
     * @var object
     */
    protected $_oFcPoHelper = null;

    /**
     * init object construction
     */
    public function __construct()
    {
        parent::__construct();
        $this->_oFcPoHelper = oxNew(FcPoHelper::class);
    }

    /**
     * Render overloading
     *
     * @return void
     */
    public function render()
    {
        parent::render();
        $this->_fcpoMandateDownloadAction();
        exit();
    }

    /**
     * Triggers download action for mandate
     *
     * @return void
     */
    protected function _fcpoMandateDownloadAction()
    {
        $oDb = DatabaseProvider::getDb();
        $sQuery = $this->_fcpoGetMandateQuery();
        $aResult = $oDb->GetRow($sQuery);

        if (!is_array($aResult)) {
            echo 'Permission denied!';
            return;
        }

        $sFilename = (string)$aResult[0];
        $sOrderId = (string)$aResult[1];
        $sPaymentId = (string)$aResult[2];

        $sPath = getShopBasePath() . 'modules/fc/fcpayone/mandates/' . $sFilename;

        if (!file_exists($sPath)) {
            $this->_redownloadMandate($sFilename, $sOrderId, $sPaymentId);
        }

        if (!file_exists($sPath)) {
            echo 'Error: File not found!';#
            return;
        }

        header("Content-Type: application/pdf");
        header("Content-Disposition: attachment; filename=\"{$sFilename}\"");
        readfile($sPath);
    }

    /**
     * Return query for fetching mandate mandatory information
     *
     * @return string
     */
    protected function _fcpoGetMandateQuery()
    {
        $sOrderId = $this->_oFcPoHelper->fcpoGetRequestParameter('id');
        $sUserId = $this->_fcpoGetUserId();

        $sWhere = "
            b.oxuserid = " . DatabaseProvider::getDb()->quote($sUserId) . "
        ";
        $sOrderBy = "
            ORDER BY
                b.oxorderdate DESC        
        ";
        if ($sOrderId) {
            $sWhere = "
                b.oxid = " . DatabaseProvider::getDb()->quote($sOrderId) . " AND
                b.oxuserid = " . DatabaseProvider::getDb()->quote($sUserId) . "
            ";
            $sOrderBy = "";
        }

        $sQuery = "
            SELECT 
                a.fcpo_filename,
                b.oxid,
                b.fcpomode,
                b.oxpaymenttype
            FROM 
                fcpopdfmandates AS a
            INNER JOIN
                oxorder AS b ON a.oxorderid = b.oxid
            WHERE {$sWhere} {$sOrderBy} LIMIT 1        
        ";

        return $sQuery;
    }

    /**
     * Returns user id which has been sent directly as param
     * or fetch userid from other sources
     *
     * @return mixed
     */
    protected function _fcpoGetUserId()
    {
        $sUserId = $this->_oFcPoHelper->fcpoGetRequestParameter('uid');
        $oUser = $this->getUser();
        if ($oUser) {
            $sUserId = $oUser->getId();
        }

        return $sUserId;
    }

    /**
     * Redownload existing mandate from payone platform
     *
     * @param $sMandateFilename
     * @param $sOrderId
     * @param $sPaymentId
     */
    protected function _redownloadMandate($sMandateFilename, $sOrderId, $sPaymentId)
    {
        $sMandateIdentification = str_replace('.pdf', '', $sMandateFilename);
        $oPayment = oxNew(Payment::class);
        $oPayment->load($sPaymentId);
        $sMode = $oPayment->fcpoGetOperationMode();

        $oPORequest = oxNew(FcPoRequest::class);
        $oPORequest->sendRequestGetFile($sOrderId, $sMandateIdentification, $sMode);
    }
}

$oDownload = oxNew(FcPayOneMandateDownload::class);
$oDownload->render();
