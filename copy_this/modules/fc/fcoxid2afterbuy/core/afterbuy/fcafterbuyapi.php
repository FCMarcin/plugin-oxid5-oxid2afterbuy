<?php

/**
 * @see Afterbuy API documentation http://xmldoku.afterbuy.de/dokued/
 */

/**
 * fcafterbuy core class
 *
 * @author andre
 */
class fcafterbuyapi {

    public static $ARTICLE_TYPE_VARIATIONSETS = 'variationsets';
    public static $ARTICLE_TYPE_NONSETS = 'nonsets';
    public static $ARTICLE_TYPE_SINGLES = 'singles';

    /**
     * Error log level 1=Only errors, 2= Errors and warnings, 3=Output all
     * @var int
     */
    protected $logLevel;

    /**
     * Filename for logfile
     * @var string
     */
    protected $afterbuyLogFilepath = null;

    /**
     * Ident for last requested order
     * @var string
     */
    protected $lastOrderId = null;

    /**
     * Ident for order which will be searched for
     * @var string
     */
    protected $searchOrderId = null;

    /**
     * ShopInterface Base URL of Afterbuy
     * https://www.afterbuy.de/afterbuy/ShopInterface.aspx
     * @var string
     */
    protected $afterbuyShopInterfaceBaseUrl = "";

    /**
     * ABI Url of Afterbuy
     * http://api.afterbuy.de/afterbuy/ABInterface.aspx
     * @var string
     */
    protected $afterbuyAbiUrl = "";

    /**
     * Partner ID of Afterbuy
     * @var string
     */
    protected $afterbuyPartnerId = "";

    /**
     * Partner Password for Afterbuy
     * @var string
     */
    protected $afterbuyPartnerPassword = "";

    /**
     * Username for Afterbuy
     * @var string
     */
    protected $afterbuyUsername = "";

    /**
     * User password for Afterbuy
     * @var string
     */
    protected $afterbuyUserPassword = "";


    /**
     * fcafterbuyapi constructor.
     *
     * The foreseen configuration that is needed has to be a filled array like this
     * $aConfig = array(
     *      'afterbuyShopInterfaceBaseUrl' => <AfterbuyShopInterfaceBaseUrl>,
     *      'afterbuyAbiUrl' => <AfterbuyAbiUrl>,
     *      'afterbuyPartnerId' => <AfterbuyPartnerId>,
     *      'afterbuyPartnerPassword' => <AfterbuyPartnerPassword>,
     *      'afterbuyUsername' => <AfterbuyUsername>,
     *      'afterbuyUserPassword' => <AfterbuyUserPassword>,
     *      'logLevel' => <LogLevel>,
     * );
     *
     * @param $aConfig
     * @throws Exception
     */
    function __construct($aConfig) {
        try {
            $this->setConfig($aConfig);
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Central api logging method. Timestamp will be added automatically.
     * Logs only if loglevel matches
     *
     * @param string $sMessage
     * @param int $iLogLevel
     * @return void
     * @access protected
     */
    public function writeLog($sMessage, $iLogLevel = 1) {
        // it is mandatory that a logfilepath has to be set
        if ($this->afterbuyLogFilepath === null) return;

        $sTime = date("Y-m-d H:i:s");
        $sFullMessage = "[" . $sTime . "] " . $sMessage . "\n";
        if ($iLogLevel <= $this->logLevel) {
            file_put_contents($this->afterbuyLogFilepath, $sFullMessage, FILE_APPEND);
        }
    }

    /**
     * Sets the path for api logs
     *
     * @param $sPath
     * @return void
     */
    public function setLogFilePath($sPath) {
        $this->afterbuyLogFilepath = $sPath;
    }

    /**
     * Setter for last orderid
     *
     * @param $sLastOrderId
     * @return void
     */
    public function setLastOrderId($sLastOrderId) {
        $this->lastOrderId = $sLastOrderId;
    }

    /**
     * Setter for orderid to be requested
     *
     * @param $sSearchOrderId
     * @return void
     */
    public function setSearchOrderId($sSearchOrderId) {
        $this->searchOrderId = $sSearchOrderId;
    }

    /**
     * Request Afterbuy API with given XML Request
     *
     * @param string $sXmlData
     * @return string API answer
     * @access protected
     */
    public function requestAPI($sXmlData) {
        $this->writeLog("DEBUG: Requesting Afterbuy API:\n".$sXmlData."\n",4);
        $ch = curl_init($this->afterbuyAbiUrl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: text/xml'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, "$sXmlData");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $sOutput = curl_exec($ch);
        curl_close($ch);
        $this->writeLog("DEBUG: RESPONSE of Afterbuy API:\n".$sOutput."\n",4);
        return $sOutput;
    }

    /**
     * Updates or inserts article to afterbuy and returns API answer
     *
     * @param $oArt
     * @return string
     */
    public function updateArticleToAfterbuy($oArt) {
        $this->writeLog("MESSAGE: Transfer article to afterbuy:".print_r($oArt,true));
        $sXmlData = $this->getUpdateArticleXml($oArt);
        $sOutput = $this->requestAPI($sXmlData);

        return $sOutput;
    }

    /**
     * Calls API for updating orderstate (senddate, paymentdate)
     *
     * @param $oOrderState
     * @return string
     */
    public function updateSoldItemsOrderState($oOrderState) {
        $sXmlData = $this->getXmlHead('UpdateSoldItems', 0);
        $sXmlData .= "<Orders>";
        $sXmlData .= "<Order>";
        $sXmlData .= "<OrderID>".$oOrderState->OrderID."</OrderID>";
        $sXmlData .= "<OrderExported>1</OrderExported>";
        if (isset($oOrderState->PaymentInfo->PaymentDate)) {
            $sXmlData .= "<PaymentInfo><PaymentDate>".$oOrderState->PaymentInfo->PaymentDate."</PaymentDate></PaymentInfo>";
        }
        if (isset($oOrderState->ShippingInfo->DeliveryDate)) {
            $sXmlData .= "<ShippingInfo><DeliveryDate>".$oOrderState->ShippingInfo->DeliveryDate."</DeliveryDate></ShippingInfo>";
        }
        $sXmlData .= "</Order>";
        $sXmlData .= "</Orders>";
        $sXmlData .= $this->getXmlFoot();

        $sOutput = $this->requestAPI($sXmlData);
        return $sOutput;
    }

    /**
     * Requesting afterbuy api for sold products (orders)
     *
     * @param void
     * @return string
     */
    public function getSoldItemsFromAfterbuy() {
        $sXmlData = $this->getXmlHead('GetSoldItems', 30);
        $sXmlData .= "<MaxSoldItems>99</MaxSoldItems>";
        $sXmlData .= "<OrderDirection>1</OrderDirection>";
        $sXmlData .= "<RequestAllItems>1</RequestAllItems>";
        $sXmlData .= $this->getNewOrderFilter();
        $sXmlData .= $this->getXmlFoot();
        $sOutput = $this->requestAPI($sXmlData);

        return $sOutput;
    }

    /**
     * Requesting afterbuy api for items. If no page is given
     * first page will be used default
     *
     *
     * @param int $iPage
     * @param string $sType
     * @return int
     */
    public function getShopProductsFromAfterbuy($iPage=1, $sType) {
        $blValidType = $this->isValidProductRequestType($sType);
        if (!$blValidType) return 0;

        $sXmlData = $this->getXmlHead('GetShopProducts', 30);
        $sXmlData .= "<MaxShopItems>250</MaxShopItems>";
        $sXmlData .= $this->_fcGetSuppressBaseProductData($sType);
        $sXmlData .= "<PaginationEnabled>1</PaginationEnabled>";
        $sXmlData .= "<PageNumber>".$iPage."</PageNumber>";
        $sXmlData .= $this->getShopProductsFilter($sType);
        $sXmlData .= $this->getXmlFoot();
        $sOutput = $this->requestAPI($sXmlData);

        return $sOutput;
    }

    /**
     * Returns Information about a given catalogue id
     *
     * @param void
     * @return string
     */
    public function getShopCatalogsById($sCatalogId)
    {
        $sXmlData = $this->getXmlHead('GetShopCatalogs', 30);
        $sXmlData .= "<MaxCatalogs>1</MaxCatalogs>";
        $sXmlData .= $this->getShopCatalogsFilterId($sCatalogId);
        $sXmlData .= $this->getXmlFoot();
        $sOutput = $this->requestAPI($sXmlData);

        return $sOutput;
    }

    /**
     * Adds id filter
     *
     * @param $sCatalogId
     * @return string
     */
    protected function getShopCatalogsFilterId($sCatalogId)
    {
        $sXmlData = "
          <DataFilter>
           <Filter>
            <FilterName>CatalogID</FilterName>
            <FilterValue>".$sCatalogId."</FilterValue>
           </Filter>
          </DataFilter>        
        ";

        return $sXmlData;
    }

    /**
     * Checks if product request type is valid
     *
     * @param string $sType
     * @return bool
     */
    protected function isValidProductRequestType($sType)
    {
        $blValidType = in_array(
            $sType,
            array(
                self::$ARTICLE_TYPE_SINGLES,
                self::$ARTICLE_TYPE_VARIATIONSETS,
                self::$ARTICLE_TYPE_NONSETS,
            )
        );

        return $blValidType;
    }

    /**
     * Returns surpress base products value if call type is singles
     *
     * @param $sType
     * @return string
     */
    protected function _fcGetSuppressBaseProductData($sType)
    {
        $iSuppress = (int) ($sType==='singles');
        if (!$iSuppress) return '';

        $sXmlData = "
            <SuppressBaseProductRelatedData>".$iSuppress."</SuppressBaseProductRelatedData>
        ";

        return $sXmlData;
    }

    /**
     * Requesting afterbuy api for sold products (orders)
     *
     * @param string $sSearchOrderId
     * @return string
     */
    public function getSoldItemsStatus($sSearchOrderId) {
        $this->setSearchOrderId($sSearchOrderId);

        $sXmlData = $this->getXmlHead('GetSoldItems', 30);
        $sXmlData .= "<MaxSoldItems>1</MaxSoldItems>";
        $sXmlData .= "<OrderDirection>1</OrderDirection>";
        $sXmlData .= "<RequestAllItems>1</RequestAllItems>";
        $sXmlData .= $this->getOrderByIdFilter();
        $sXmlData .= $this->getXmlFoot();
        $sOutput = $this->requestAPI($sXmlData);

        return $sOutput;
    }


    /**
     * Returns filter for requesting only new orders
     *
     * @param void
     * @return string
     */
    protected function getShopProductsFilter($sType) {
        $blNoFilter = ($sType==='singles');
        if ($blNoFilter) return '';

        $sFilterMode =
            ($sType=='variationsets') ? 'VariationsSets' : 'not_VariationsSets';

        $sXmlData = "";
        $sXmlData .= "<DataFilter>";
        $sXmlData .= "<Filter>";
        $sXmlData .= "<FilterName>DefaultFilter</FilterName>";
        $sXmlData .= "<FilterValue>".$sFilterMode."</FilterValue>";
        $sXmlData .= "</Filter>";
        $sXmlData .= "</DataFilter>";

        return $sXmlData;
    }


    /**
     * Returns filter for requesting only new orders
     *
     * @param void
     * @return string
     */
    protected function getNewOrderFilter() {
        $sXmlData = "";

        if ($this->lastOrderId) {
            $sXmlData .= "<DataFilter>";
            $sXmlData .= "<Filter>";
            $sXmlData .= "<FilterName>RangeID</FilterName>";
            $sXmlData .= "<FilterValues>";
            $sXmlData .= "<ValueFrom>".$this->lastOrderId."</ValueFrom>";
            $sXmlData .= "<ValueTo>9999999999</ValueTo>";
            $sXmlData .= "</FilterValues>";
            $sXmlData .= "</Filter>";
            $sXmlData .= "</DataFilter>";
        }

        return $sXmlData;
    }

    /**
     * Returns xml filter for requesting certain
     *
     * @param void
     * @return string
     */
    protected function getOrderByIdFilter() {
        $sXmlData = "";

        if ($this->searchOrderId) {
            $sXmlData .= "<DataFilter>";
            $sXmlData .= "<Filter>";
            $sXmlData .= "<FilterName>OrderID</FilterName>";
            $sXmlData .= "<FilterValue>".$this->searchOrderId."</FilterValue>";
            $sXmlData .= "</Filter>";
            $sXmlData .= "</DataFilter>";
        }

        return $sXmlData;
    }

    /**
     * Set configuration for afterbuy connection
     *
     * @param $aConfig
     * @return void
     */
    protected function setConfig($aConfig) {
        foreach ($aConfig as $sConfigName=>$sConfigValue) {
            $this->$sConfigName = $sConfigValue;
        }
    }

    /**
     * Request Afterbuy shop interface with REST URL
     *
     * @param string $sRequest
     * @return string API answer
     * @access public
     */
    public function requestShopInterfaceAPI($sRequest) {
        // prepare parameters for post call
        $aRequest = explode("?", $sRequest);
        $sParamString = $aRequest[1];
        $aParamsWithValues = explode("&", $sParamString);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $aRequest[0]);
        curl_setopt($ch, CURLOPT_POST, count($aParamsWithValues));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $sParamString);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $sOutput = curl_exec($ch);
        curl_close($ch);

        return $sOutput;
    }

    /**
     * Returns xml for requesting aftberbuy abi
     *
     * @param $oArt
     * @param $sAbId
     * @return string
     * @todo add overview by providing submethods
     */
    protected function getUpdateArticleXml($oArt) {
        $sXmlData = $this->getXmlHead('UpdateShopProducts');
        $sXmlData .= '<Products><Product>';

        $sXmlData = $this->_fcAddProductIdent($oArt, $sXmlData);
        $sXmlData = $this->_fcAddProductBaseValues($oArt, $sXmlData);
        $sXmlData = $this->_fcAddManufacturer($oArt, $sXmlData);
        $sXmlData = $this->_fcAddBaseProducts($oArt, $sXmlData);
        $sXmlData = $this->_fcAddCatalogs($oArt, $sXmlData);
        $sXmlData = $this->_fcAddAttributes($oArt, $sXmlData);
        $sXmlData = $this->_fcAddPictures($oArt, $sXmlData);

        $sXmlData .= '</Product></Products>';
        $sXmlData .= $this->getXmlFoot();

        return $sXmlData;
    }

    /**
     * Adding pictures to xml
     *
     * @param $oArt
     * @param $sXmlData
     * @return string
     */
    protected function _fcAddPictures($oArt, $sXmlData)
    {
        $sXmlData .= '<ProductPictures>';
        for($iIndex=1;$iIndex<=12;$iIndex++) {
            $sPictureAttribute = 'ProductPicture_Url_'.$iIndex;
            $sPictureUrl = $oArt->$sPictureAttribute;
            if (!$sPictureUrl) continue;

            $sXmlData .= '
                <ProductPicture>
                    <Nr>'.$iIndex.'</Nr>
                    <Url>' . $sPictureUrl . '</Url>
                    <AltText><![CDATA[' . $oArt->Name . ']]></AltText>        
                </ProductPicture>
            ';
        }
        $sXmlData .= '</ProductPictures>';

        return $sXmlData;
    }

    /**
     * Adds manufacturer related values to xml
     *
     * @param $oArt
     * @param $sXmlData
     * @return string
     */
    protected function _fcAddManufacturer($oArt, $sXmlData) {
        if ($oArt->EAN != "") {
            $sXmlData .= '
                <ManufacturerStandardProductIDType><![CDATA[EAN]]></ManufacturerStandardProductIDType>
                <ManufacturerStandardProductIDValue><![CDATA[' . $oArt->EAN . ']]></ManufacturerStandardProductIDValue>
            ';
        }

        return $sXmlData;
    }

    /**
     * Adding xml base values of product
     *
     * @param $oArt
     * @param $sXmlData
     * @return string
     */
    protected function _fcAddProductBaseValues($oArt, $sXmlData)
    {
        $sXmlData .= '
            <UserProductID>' . $oArt->UserProductID . '</UserProductID>
            <Anr>' . $oArt->Anr . '</Anr>
            <EAN>' . $oArt->EAN . '</EAN>
            <Name><![CDATA[' . $oArt->Name . ']]></Name>
            <ShortDescription><![CDATA[' . $oArt->ShortDescription . ']]></ShortDescription>
            <Description><![CDATA[' . $oArt->Description . ']]></Description>
            <Quantity>' . $oArt->Quantity . '</Quantity>
            <Stock>1</Stock>
            <Discontinued>1</Discontinued>
            <MergeStock>1</MergeStock>
            <SellingPrice>' . str_replace('.',',',$oArt->SellingPrice) . '</SellingPrice>
            <ImageSmallURL>'.$oArt->ImageSmallURL.'</ImageSmallURL>
            <ImageLargeURL>'.$oArt->ImageLargeURL.'</ImageLargeURL>
            <ProductBrand>'.$oArt->ProductBrand.'</ProductBrand>
            <TaxRate>'.$oArt->TaxRate.'</TaxRate>
            <ItemSize>'.$oArt->ItemSize.'</ItemSize>
            <CanonicalUrl>'.$oArt->CanonicalUrl.'</CanonicalUrl>
            <ManufacturerPartNumber>'.$oArt->ManufacturerPartNumber.'</ManufacturerPartNumber>
            <Keywords>'.$oArt->Keywords.'</Keywords>
            <BuyingPrice>'.str_replace('.',',',$oArt->BuyingPrice).'</BuyingPrice>
            <Weight>'.$oArt->Weight.'</Weight>
        ';

        return $sXmlData;
    }

    /**
     * Adds xml part for product identification
     *
     * @param $oArt
     * @param $sXmlData
     * @return string
     */
    protected function _fcAddProductIdent($oArt, $sXmlData) {
        $sXmlData .= '<ProductIdent>';
        if (!$oArt->ProductID) {
            $sXmlData .= '<ProductInsert>1</ProductInsert>
                                <BaseProductType>' . $oArt->BaseProductType . '</BaseProductType>
                                <UserProductID><![CDATA[' . $oArt->UserProductID . ']]></UserProductID>
                                <Anr>' . $oArt->Anr . '</Anr>
                                <EAN>' . $oArt->EAN . '</EAN>';
        } else {
            $sXmlData .= '<ProductID>' . $oArt->ProductID . '</ProductID>';
        }
        $sXmlData .= '      </ProductIdent>';

        return $sXmlData;
    }

    /**
     * Adds product catalogs
     *
     * @param $oArt
     * @param $sXmlData
     * @return string
     */
    protected function _fcAddCatalogs($oArt, $sXmlData)
    {
        if (!is_array($oArt->AddAttributes)) return $sXmlData;

        $sXmlData .= "<AddCatalogs>";
        $sXmlData .= "<UpdateAction>1</UpdateAction>";
        foreach ($oArt->AddCatalogs as $oAddCatalog) {
            $sXmlData .= "
                <AddCatalog>
                  <CatalogID><![CDATA[".$oAddCatalog->CatalogID."]]></CatalogID>
                  <CatalogName><![CDATA[".$oAddCatalog->CatalogName."]]></CatalogName>
                  <CatalogLevel>".$oAddCatalog->CatalogLevel."</CatalogLevel>
                </AddCatalog>                
            ";
        }
        $sXmlData .= "</AddCatalogs>";

        return $sXmlData;
    }


    /**
     * Adds product attributes
     *
     * @param $oArt
     * @param $sXmlData
     * @return string
     */
    protected function _fcAddAttributes($oArt, $sXmlData)
    {
        if (!is_array($oArt->AddAttributes)) return $sXmlData;

        $sXmlData .= "<AddAttributes>";
        $sXmlData .= "<UpdateAction>1</UpdateAction>";
        foreach ($oArt->AddAttributes as $oAddAttribute) {
            $sXmlData .= "
                <AddAttribut>
                  <AttributName><![CDATA[".$oAddAttribute->AttributName."]]></AttributName>
                  <AttributValue><![CDATA[".$oAddAttribute->AttributValue."]]></AttributValue>
                  <AttributTyp>".$oAddAttribute->AttributTyp."</AttributTyp>
                  <AttributPosition>".$oAddAttribute->AttributPosition."</AttributPosition>
                  <AttributRequired>".$oAddAttribute->AttributRequired."</AttributRequired>
                </AddAttribut>                
            ";
        }
        $sXmlData .= "</AddAttributes>";

        return $sXmlData;
    }

    /**
     * Adds base-product-/variant- assigments
     *
     * @param $oArt
     * @param $sXmlData
     * @return string
     */
    protected function _fcAddBaseProducts($oArt, $sXmlData)
    {
        if (!is_array($oArt->AddBaseProducts)) return $sXmlData;

        $sXmlData .= "<AddBaseProducts>";
        $sXmlData .= "<UpdateAction>1</UpdateAction>";
        foreach ($oArt->AddBaseProducts as $oAddBaseProduct) {
            $sXmlData .= "
                <AddBaseProduct>
                  <ProductID>".$oAddBaseProduct->ProductID."</ProductID>
                  <ProductLabel><![CDATA[".$oAddBaseProduct->ProductLabel."]]></ProductLabel>
                  <ProductPos>".$oAddBaseProduct->ProductPos."</ProductPos>
                  <DefaultProduct>".$oAddBaseProduct->DefaultProduct."</DefaultProduct>
                  <ProductQuantity>".$oAddBaseProduct->ProductQuantity."</ProductQuantity>
                </AddBaseProduct>                
            ";
        }
        $sXmlData .= "</AddBaseProducts>";

        return $sXmlData;
    }

    /**
     * Returns head part of xml request including auth information
     * 
     * @param $sCallName
     * @param int $iDetailLevel
     * @return string
     */
    protected function getXmlHead($sCallName, $iDetailLevel = 0) {
        $sXml = '<?xml version="1.0" encoding="utf-8"?>
                <Request>
                    <AfterbuyGlobal>
                        <PartnerID>' . $this->afterbuyPartnerId . '</PartnerID>
                        <PartnerPassword><![CDATA[' . $this->afterbuyPartnerPassword . ']]></PartnerPassword>
                        <UserID><![CDATA[' . $this->afterbuyUsername . ']]></UserID>
                        <UserPassword><![CDATA[' . $this->afterbuyUserPassword . ']]></UserPassword>
                        <CallName>' . $sCallName . '</CallName>
                        <DetailLevel>' . $iDetailLevel . '</DetailLevel>
                        <ErrorLanguage>DE</ErrorLanguage>
                    </AfterbuyGlobal>';
        return $sXml;
    }

    /**
     * Foot of xml request
     * 
     * @param void
     * @return string
     */
    protected function getXmlFoot() {
        $sXml = '</Request>';
        return $sXml;
    }

}
