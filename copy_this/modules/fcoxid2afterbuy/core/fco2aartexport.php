<?php
class fco2aartexport extends fco2abase {

    /**
     * Dictionary of value translations
     * @var array
     */
    protected $_aAfterbuy2OxidDictionary = array(
        'UserProductID' => 'oxarticles__oxid',
        'Anr' => 'oxarticles__oxartnum',
        'EAN' => 'oxarticles__oxean',
        'ProductID' => 'oxarticles__fcafterbuyid',
        'Name' => 'oxarticles__oxtitle',
        'ManufacturerPartNumber' => 'oxarticles__oxmpn',
        'Keywords' => 'oxarticles__oxkeywords',
        'Quantity' => 'oxarticles__oxstock|oxarticles__oxvarstock',
        'AuctionQuantity' => 'oxarticles__oxstock|oxarticles__oxvarstock',
        'UnitOfQuantity' => 'oxarticles__oxunitname',
        'BuyingPrice' => 'oxarticles__oxbprice',
        'Weight' => 'oxarticles__oxweight',
        'ShortDescription' => 'oxarticles__oxshortdesc',
    );

    /**
     * Executes upload of selected afterbuy articles
     *
     * @param void
     * @return void
     */
    public function execute()
    {
        $aConfig = $this->_fcGetAfterbuyConfigArray();
        $oAfterbuyApi = $this->_fcGetAfterbuyApi($aConfig);

        $aArticleIds = $this->_fcGetAffectedArticleIds();

        foreach ($aArticleIds as $sArticleOxid) {
            $oArt = $this->_fcGetAfterbuyArticleByOxid($sArticleOxid);
            $sResponse = $oAfterbuyApi->updateArticleToAfterbuy($oArt);
            $this->_fcValidateCallStatus($sResponse);
            $this->_fcAddAfterbuyIdToArticle($sArticleOxid, $sResponse);
        }
    }

    protected function _fcValidateCallStatus($sResponse) {
        $oXml = simplexml_load_string($sResponse);
        $sCallStatus = (string) $oXml->CallStatus;
        switch ($sCallStatus) {
            case 'Warning':
                $sMessage = "WARNING: ".(string)$oXml->Result->WarningList->WarningLongDescription;
                echo $sMessage;
                $this->fcWriteLog($sMessage,2);
                break;
        }
    }

    /**
     * Adds afterbuy id to article dataset
     *
     * @param $sArticleOxid
     * @param $sResponse
     * @return void
     */
    protected function _fcAddAfterbuyIdToArticle($sArticleOxid, $sResponse) {
        $oXml = simplexml_load_string($sResponse);
        $sProductId = (string) $oXml->Result->NewProducts->NewProduct->ProductID;
        if ($sProductId) {
            $oArticle = oxNew('oxarticle');
            if ($oArticle->load($sArticleOxid)) {
                $oArticle->oxarticles__fcafterbuyid = new oxField($sProductId);
                $oArticle->save();
            }

        }
    }

    /**
     * Takes an oxid of an article and creates an afterbuy article object of it
     *
     * @param $sArticleOxid
     * @return object
     */
    protected function _fcGetAfterbuyArticleByOxid($sArticleOxid) {
        $oAfterbuyArticle = $this->_fcGetAfterbuyArticle();
        $oArticle = oxNew('oxarticle');
        $oArticle->load($sArticleOxid);
        $oManufacturer = $oArticle->getManufacturer();

        $oAfterbuyArticle->Description = $oArticle->getLongDesc();
        $oAfterbuyArticle->SellingPrice = $oArticle->getPrice()->getBruttoPrice();
        $oAfterbuyArticle->TaxRate = $oArticle->getArticleVat();
        $oAfterbuyArticle->ProductBrand = $oManufacturer->getTitle();
        $oAfterbuyArticle->ItemSize = $oArticle->getSize();
        $oAfterbuyArticle->CanonicalUrl = $oArticle->getMainLink();

        // standard values will be iterated through translation array
        foreach ($this->_aAfterbuy2OxidDictionary as $sAfterbuyName=>$sOxidNamesString) {
            $aOxidNames = explode('|', $sOxidNamesString);
            foreach ($aOxidNames as $sCurrentOxidName) {
                $sValue = $oArticle->$sCurrentOxidName->value;
                $sOxidName = $sCurrentOxidName;
                // if variable filled breakout
                if ($sValue) {
                    break;
                }
            }

            $oAfterbuyArticle->$sAfterbuyName = $oArticle->$sOxidName->value;
        }

        // pictures
        for($iIndex=1;$iIndex<=12;$iIndex++) {
            $sVarName_PicNr = "ProductPicture_Nr_".$iIndex;
            $sVarName_PicUrl = "ProductPicture_Url_".$iIndex;
            $sVarName_PicAltText = "ProductPicture_AltText_".$iIndex;

            $oAfterbuyArticle->$sVarName_PicNr = $iIndex;
            $oAfterbuyArticle->$sVarName_PicUrl = $oArticle->getPictureUrl($iIndex);
            $oAfterbuyArticle->$sVarName_PicAltText = $oArticle->oxarticles__oxtitle->value;
        }

        return $oAfterbuyArticle;
    }

    /**
     * Returns an afterbuy article object
     *
     * @param void
     * @return object fcafterbuyart
     */
    protected function _fcGetAfterbuyArticle() {
        $oViewConfig = oxRegistry::get('oxViewConfig');
        $sPathToModule = $oViewConfig->getModulePath('fcoxid2afterbuy');
        $sPathToAfterbuyLib = $sPathToModule.'lib/fcafterbuyapi.php';
        include_once($sPathToAfterbuyLib);
        $oAfterbuyArticle = new fcafterbuyart();

        return $oAfterbuyArticle;
    }

    /**
     * Returns an array of article ids which have been flagged to be an afterbuy article
     *
     * @param void
     * @return array
     */
    protected function _fcGetAffectedArticleIds() {
        $aArticleIds = array();
        $oConfig = $this->getConfig();
        $blFcAfterbuyExportAll = $oConfig->getConfigParam('blFcAfterbuyExportAll');
        $sWhereConditions = "";

        if (!$blFcAfterbuyExportAll) {
            $sWhereConditions .= " AND FCAFTERBUYACTIVE='1' ";
        }

        $oDb = oxDb::getDb(oxDb::FETCH_MODE_ASSOC);
        $sQuery = "SELECT OXID FROM ".getViewName('oxarticles')." WHERE OXPARENTID='' ".$sWhereConditions;
        $aRows = $oDb->getAll($sQuery);
        foreach ($aRows as $aRow) {
            $aArticleIds[] = $aRow['OXID'];
        }

        return $aArticleIds;
    }

    /**
     * Returns needed configuration for instantiate afterbuy api object
     *
     * @param void
     * @return array
     */
    protected function _fcGetAfterbuyConfigArray() {
        $oConfig = $this->getConfig();
        $aConfig = array(
            '_sFcAfterbuyShopInterfaceBaseUrl' => $oConfig->getConfigParam('sFcAfterbuyShopInterfaceBaseUrl'),
            '_sFcAfterbuyAbiUrl' => $oConfig->getConfigParam('sFcAfterbuyAbiUrl'),
            '_sFcAfterbuyPartnerId' => $oConfig->getConfigParam('sFcAfterbuyPartnerId'),
            '_sFcAfterbuyPartnerPassword' => $oConfig->getConfigParam('sFcAfterbuyPartnerPassword'),
            '_sFcAfterbuyUsername' => $oConfig->getConfigParam('sFcAfterbuyUsername'),
            '_sFcAfterbuyUserPassword' => $oConfig->getConfigParam('sFcAfterbuyUserPassword'),
            '_iFcLogLevel' => $oConfig->getConfigParam('iFcAfterbuyLogLevel'),
        );

        return $aConfig;
    }

    /**
     * Returns afterbuy api object
     *
     * @param $aConfig
     * @return object
     */
    protected function _fcGetAfterbuyApi($aConfig) {
        $oViewConfig = oxRegistry::get('oxViewConfig');
        $sPathToModule = $oViewConfig->getModulePath('fcoxid2afterbuy');
        $sPathToAfterbuyLib = $sPathToModule.'lib/fcafterbuyapi.php';
        include_once($sPathToAfterbuyLib);
        $oAfterbuyApi = new fcafterbuyapi($aConfig);

        // directly set oxid logfilepath after instantiation
        $oAfterbuyApi->fcSetLogFilePath(getShopBasePath()."/log/fco2a_api.log");

        return $oAfterbuyApi;
    }
}