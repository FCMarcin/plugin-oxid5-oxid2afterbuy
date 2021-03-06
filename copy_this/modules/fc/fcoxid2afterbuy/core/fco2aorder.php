<?php

/**
 * Description of fcafterbuyorder
 *
 * @author andre
 */
class fco2aorder extends fco2abase {

    /**
     * Handle to send order to Afterbuy
     * 
     * @param object $oOrder 
     * @param object $oUser
     * @return bool True if order was successfully sent, False if problems occurred
     */
    public function fcSendOrderToAfterbuy($oOrder, $oUser) {
        $oConfig = $this->getConfig();
        // build request url
        $sActionParameter = $this->_fcGetInterfaceAction();
        $sDeliveryFlagParameter = ($oOrder->oxorder__oxdelstreet->value != "") ? 1 : 0;
        $sDeliveryAddressFlagParameter = "&Lieferanschrift={$sDeliveryFlagParameter}";
        $sAfterbuyCredentialParameters = $this->_fcGetAfterbuyCredentialParameter();
        $sCustomerInfoParameters = $this->_fcGetCustomerInfoParameters($oOrder, $oUser);
        $sOrderArticleParameters = $this->_fcGetOrderarticleParameters($oOrder);
        $sGenericOrderParameters = $this->_fcGetGenericOrderParameters($oOrder);
        $sConfigParameters = $this->_fcGetConfigParameters();

        $sRequest = $oConfig->getConfigParam('sFcAfterbuyShopInterfaceBaseUrl');
        $sRequest .= $sActionParameter . $sDeliveryAddressFlagParameter . $sAfterbuyCredentialParameters;
        $sRequest .= $sCustomerInfoParameters . $sOrderArticleParameters . $sGenericOrderParameters . $sConfigParameters;

        $oAfterbuyApi = $this->_fcGetAfterbuyApi();

        $sOutput = $oAfterbuyApi->requestShopInterfaceAPI($sRequest);
        $this->fcWriteLog("DEBUG: Requesting shopinterface for sending order:\n".$sRequest,4);
        $this->fcWriteLog("DEBUG: Response:\n".$sOutput,4);
        $this->_fcHandleShopInterfaceAnswer($sOutput, $oOrder);
    }

    /**
     * Return the article information parameters of current 
     * 
     * @param object $oOrder
     * @return string Basket parameters of all ordered articles
     */
    protected function _fcGetOrderarticleParameters($oOrder)
    {
        $aOrderArticles = $oOrder->getOrderArticles();
        $sOrderarticleParameters = "";
        $iSuffix = 1;
        $iPositions = count($aOrderArticles);
        $sOrderarticleParameters .= "&PosAnz=" . $iPositions;
        foreach ($aOrderArticles as $oOrderArticle) {
            $oArticle = $oOrderArticle->getArticle();
            $ArtikelStammID = $oArticle->oxarticles__oxartnum->value;
            $sOrderarticleParameters .= "&ArtikelStammID_{$iSuffix}=" . $ArtikelStammID;
            $sOrderarticleParameters .= "&Artikelnr_{$iSuffix}=" . preg_replace("/[^0-9]/", "", $oOrderArticle->oxorderarticles__oxartnum->value); // we need to offer a numeric artnum so we replace non numeric characters
            $sOrderarticleParameters .= "&AlternArtikelNr1_{$iSuffix}=" . urlencode($oOrderArticle->oxorderarticles__oxartnum->value);
            $sOrderarticleParameters .= "&Artikelname_{$iSuffix}=" . urlencode(utf8_decode($oOrderArticle->oxorderarticles__oxtitle->value . " " . $oOrderArticle->oxorderarticles__oxselvariant->value));
            $sOrderarticleParameters .= "&ArtikelEpreis_{$iSuffix}=" . str_replace(".", ",", $oOrderArticle->oxorderarticles__oxbprice->value);
            $sOrderarticleParameters .= "&ArtikelMwSt_{$iSuffix}=" . str_replace(".", ",",$oOrderArticle->oxorderarticles__oxvat->value);
            $sOrderarticleParameters .= "&ArtikelMenge_{$iSuffix}=" . $oOrderArticle->oxorderarticles__oxamount->value;
            $sOrderarticleParameters .= "&ArtikelGewicht_{$iSuffix}=" . $oOrderArticle->oxorderarticles__oxweight->value;
            $sOrderarticleParameters .= "&ArtikelLink_{$iSuffix}=" . urlencode($oArticle->getLink());
            $aAttributes = $oArticle->getAttributes();
            $sAttributeParameters = "&Attribute_{$iSuffix}=";
            $iAttributeSuffix = 1;
            $iAmountAttributes = count($aAttributes);
            foreach ($aAttributes as $oAttribute) {
                /**
                 * @todo This code will possibly not work needs to be debugged
                 */
                $sAttributeParameters .= urlencode($oAttribute->oxattribute__oxtitle->value) . ":"; //."{$iAttributeSuffix}:";
                $sAttributeParameters .= urlencode($oAttribute->oxobject2attribute__oxvalue->value); // ."{$iAttributeSuffix};
                if ($iAttributeSuffix < $iAmountAttributes) {
                    $sAttributeParameters .= "|";
                }
                $iAttributeSuffix++;
            }

            $iSuffix++;
        }

        return $sOrderarticleParameters;
    }
    
    /**
     * Returns the action string for shop interface request
     * 
     * @param string $sAction Optional
     * @return string Action part of REST request
     */
    protected function _fcGetInterfaceAction($sAction = 'new') {
        $sActionParameter = "?Action={$sAction}";
        return $sActionParameter;
    }

    /**
     * Returns the delivery address flag string for shop interface request
     * 
     * @param object $oOrder
     *
     * @return string Delivery flag part of REST request
     */
    protected function _fcGetDeliveryAddressFlagParameter($oOrder)
    {
        if ($oOrder->getDelAddressInfo()) {
            $sDeliveryAddressFlag = "1";
        } else {
            $sDeliveryAddressFlag = "0";
        }
        $sDeliveryFlagParameter = "&Lieferanschrift={$sDeliveryAddressFlag}";

        return $sDeliveryFlagParameter;
    }

    /**
     * Returns credential parameters needed for REST request
     * 
     * @return string Credential parameters
     */
    protected function _fcGetAfterbuyCredentialParameter()
    {
        $aConfig = $this->_fcGetAfterbuyConfigArray();

        $sCredentialParameters = "&Partnerid=" . trim($aConfig['afterbuyPartnerId']);
        $sCredentialParameters .= "&PartnerPass=" . trim($aConfig['afterbuyPartnerPassword']);
        $sCredentialParameters .= "&UserID=" . trim($aConfig['afterbuyUsername']);

        return $sCredentialParameters;
    }

    /**
     * Returns customer information like address, name etc as urlencoded parameter string
     * 
     * @param object $oOrder
     * @param object $oUser
     *
     * @return string Encoded customer parameters
     */
    protected function _fcGetCustomerInfoParameters($oOrder, $oUser) {
        // get information
        $oConfig = $this->getConfig();
        $sBillSal = $oOrder->oxorder__oxbillsal->value;
        $sBillSalutation = ( $sBillSal == "MR" || $sBillSal == "Herr" ) ? "Herr" : "Frau";
        $sDelSal = $oOrder->oxorder__oxdelsal->value;
        $sDelSalutation = ( $sDelSal == "MR" || $sDelSal == "Herr" ) ? "Herr" : "Frau";
        $blUseCustNr = $oConfig->getConfigParam('blFcAfterbuyUseOwnCustNr');

        // country
        $sBillCountry = $this->_fcGetCarPlateCountryTag($oOrder->oxorder__oxbillcountryid->value);
        $sDelCountry = $this->_fcGetCarPlateCountryTag($oOrder->oxorder__oxdelcountryid->value);

        /// state
        $oState = oxNew('oxstate');
        $oState->load($oOrder->oxorder__oxbillstateid->value);
        $sBillState = $oState->oxstates__oxtitle->value;
        $oState->load($oOrder->oxorder__oxdelstateid->value);
        $sDelState = $oState->oxstates__oxtitle->value;

        /**
         * @todo There is also a merchant Flag &Haendler= available this should be implemented by 
         * defining a Group via config. If user belongs to this group the flag is true
         */
        $sCustomerParameters = "&Kbenutzername=" . trim(urlencode($oUser->oxuser__oxusername->value));
        // bill parameters
        $sCustomerParameters .= "&Kanrede=" . trim(urlencode(utf8_decode($sBillSalutation)));
        $sCustomerParameters .= "&KFirma=" . trim(urlencode(utf8_decode($oOrder->oxorder__oxbillcompany->value)));
        $sCustomerParameters .= "&KVorname=" . trim(urlencode(utf8_decode($oOrder->oxorder__oxbillfname->value)));
        $sCustomerParameters .= "&KNachname=" . trim(urlencode(utf8_decode($oOrder->oxorder__oxbilllname->value)));
        $sCustomerParameters .= "&KStrasse=" . trim(urlencode(utf8_decode($oOrder->oxorder__oxbillstreet->value . " " . trim($oOrder->oxorder__oxbillstreetnr->value))));
        $sCustomerParameters .= "&KStrasse2=" . trim(urlencode(utf8_decode($oOrder->oxorder__oxbilladdinfo->value)));
        $sCustomerParameters .= "&KPLZ=" . trim(urlencode($oOrder->oxorder__oxbillzip->value));
        $sCustomerParameters .= "&KOrt=" . trim(urlencode(utf8_decode($oOrder->oxorder__oxbillcity->value)));
        $sCustomerParameters .= "&KBundesland=" . trim(urlencode(utf8_decode($sBillState)));
        $sCustomerParameters .= "&Ktelefon=" . trim(urlencode($oOrder->oxorder__oxbillfon->value));
        $sCustomerParameters .= "&Kfax=" . trim(urlencode($oOrder->oxorder__oxbillfax->value));
        $sCustomerParameters .= "&Kemail=" . trim(urlencode($oOrder->oxorder__oxbillemail->value));
        $sCustomerParameters .= "&KLand=" . trim(urlencode($sBillCountry));
        $sCustomerParameters .= "&KBirthday=" . trim(urlencode($oUser->oxuser__oxbirthdate->value));
        $sCustomerParameters .= "&UsStID=" . trim(urlencode($oOrder->oxorder__oxbillustid->value));
        $sCustNr = ($blUseCustNr) ? $oUser->oxuser__oxcustnr->value : '';
        $sCustomerParameters .= "&EKundenNr=" . trim(urlencode($sCustNr));

        // delivery parameters
        $sCustomerParameters .= "&KLanrede=" . trim(urlencode(utf8_decode($sDelSalutation)));
        $sCustomerParameters .= "&KLFirma=" . trim(urlencode(utf8_decode($oOrder->oxorder__oxdelcompany->value)));
        $sCustomerParameters .= "&KLVorname=" . trim(urlencode(utf8_decode($oOrder->oxorder__oxdelfname->value)));
        $sCustomerParameters .= "&KLNachname=" . trim(urlencode(utf8_decode($oOrder->oxorder__oxdellname->value)));
        $sCustomerParameters .= "&KLStrasse=" . trim(urlencode(utf8_decode($oOrder->oxorder__oxdelstreet->value . " " . trim($oOrder->oxorder__oxdelstreetnr->value))));
        $sCustomerParameters .= "&KLStrasse2=" . trim(urlencode(utf8_decode($oOrder->oxorder__oxdeladdinfo->value)));
        $sCustomerParameters .= "&KLPLZ=" . trim(urlencode($oOrder->oxorder__oxdelzip->value));
        $sCustomerParameters .= "&KLOrt=" . trim(urlencode(utf8_decode($oOrder->oxorder__oxdelcity->value)));
        $sCustomerParameters .= "&KLBundesland=" . trim(urlencode(utf8_decode($sDelState)));
        $sCustomerParameters .= "&KLtelefon=" . trim(urlencode($oOrder->oxorder__oxdelfon->value));
        $sCustomerParameters .= "&KLfax=" . trim(urlencode($oOrder->oxorder__oxdelfax->value));
        $sCustomerParameters .= "&KLemail=" . trim(urlencode($oOrder->oxorder__oxdelemail->value));
        $sCustomerParameters .= "&KLLand=" . trim(urlencode($sDelCountry));

        return $sCustomerParameters;
    }

    /**
     * This method returns generic order parameters like orderdate etc.
     * 
     * @param object $oOrder
     * @return string Order Parameters
     */
    protected function _fcGetGenericOrderParameters($oOrder)
    {
        $sPaymentId = $oOrder->oxorder__oxpaymenttype->value;
        $oPayment = oxNew('oxpayment');
        $oPayment->load($sPaymentId);
        $oDeliverySet = $oOrder->getDelSet();
        $dOrderDeliveryCosts = $oOrder->getOrderDeliveryPrice()->getBruttoPrice();
        $dOrderPaymentCosts = $oOrder->getOrderPaymentPrice()->getBruttoPrice();

        $sBuyDateRaw = $oOrder->oxorder__oxorderdate->value;
        $iTimeBuyDate = strtotime($sBuyDateRaw);
        $sBuyDateFormatted = date("d.m.Y H:i:s", $iTimeBuyDate);
        $sBuyDateUrlFormatted = trim(urlencode($sBuyDateFormatted));
        $sRemark = trim(urlencode($oOrder->oxorder__oxremark->value));
        $sDeliveryType = trim(urlencode($oDeliverySet->oxdeliveryset__oxtitle->value));
        $sDeliveryCosts = trim (urlencode(str_replace(".", ",", $dOrderDeliveryCosts)));
        $sPaymentName = trim(urlencode($oPayment->oxpayments__oxdesc->value));
        $sZFunktionsID = trim(urlencode($this->_fcGetAfterbuyPaymentId($sPaymentId)));
        $sPaymentCosts = trim(urlencode(number_format($dOrderPaymentCosts,2,',','.')));
        $sOrderId = trim(urlencode($oOrder->getId()));
        $sOrderCurrency = trim(urlencode($oOrder->getOrderCurrency()->name));

        $sOrderParameters = "&BuyDate=" . $sBuyDateUrlFormatted;
        $sOrderParameters .= "&Kommentar=" . $sRemark;
        $sOrderParameters .= "&Versandart=" . $sDeliveryType;
        $sOrderParameters .= "&Versandkosten=" . $sDeliveryCosts;
        $sOrderParameters .= "&Zahlart=" . $sPaymentName;
        $sOrderParameters .= "&ZFunktionsID=" . $sZFunktionsID;
        $sOrderParameters .= "&ZahlartenAufschlag=" . $sPaymentCosts;
        $sOrderParameters .= $this->_fcGetBankData($oOrder);
        $sOrderParameters .= "&VID=" . $sOrderId;
        $sOrderParameters .= "&SoldCurrency=" . $sOrderCurrency;
        $sOrderParameters .= $this->_fcGetPayState($oOrder);
        $sOrderParameters .= "&VMemo=";
        $sOrderParameters .= "&CheckVID=1";
        $sOrderParameters .= "&Bestandart=shop";
        $sOrderParameters .= "&Versandgruppe=shop";
        $sOrderParameters .= "&Artikelerkennung=2";

        return $sOrderParameters;
    }

    /**
     * Adds paid state to order
     *
     * @param $oOrder
     * @return string
     */
    protected function _fcGetPayState($oOrder) {
        $sPayDateRaw = $oOrder->oxorder__oxpaid->value;
        $iTimePayDate = strtotime($sPayDateRaw);
        $sPayDateFormatted = date("d.m.Y H:i:s", $iTimePayDate);

        $sOrderParameters = '';
        /**
         * Check state of paypal order due to comment
         * https://tickets.fatchip.de/view.php?id=30656#c95629
         */
        $blPaypalPaidState = (
            $oOrder->oxorder__oxpaymenttype->value == "oxidpaypal" &&
            $oOrder->oxorder__oxtranstatus->value == "NOT_FINISHED"
        ) ? false : true;

        $iPaid = (int) (
            $iTimePayDate &&
            $oOrder->oxorder__oxpaymenttype->value != "trosofortgateway_su" &&
            $blPaypalPaidState
        );

        $sPaydateUrlFormatted = ($iTimePayDate) ? trim(urlencode($sPayDateFormatted)) : '';

        $sOrderParameters .= "&SetPay=".(string) $iPaid;
        $sOrderParameters .= "&PayDate=" . $sPaydateUrlFormatted;

        return $sOrderParameters;
    }

    /**
     * Returns bankdata if needed
     *
     * @param $oOrder
     * @return string
     */
    protected function _fcGetBankData($oOrder) {
        $oConfig = $this->getConfig();
        $oPaymentType = $oOrder->getPaymentType();
        $sOrderPaymentId = $oOrder->oxorder__oxpaymenttype->value;

        $aFcAfterbuyDebitPayments =
            $oConfig->getConfigParam('aFcAfterbuyDebitPayments');
        $aFcAfterbuyDebitDynBankname =
            $oConfig->getConfigParam('aFcAfterbuyDebitDynBankname');
        $aFcAfterbuyDebitDynBankzip =
            $oConfig->getConfigParam('aFcAfterbuyDebitDynBankzip');
        $aFcAfterbuyDebitDynAccountNr =
            $oConfig->getConfigParam('aFcAfterbuyDebitDynAccountNr');
        $aFcAfterbuyDebitDynAccountOwner =
            $oConfig->getConfigParam('aFcAfterbuyDebitDynAccountOwner');

        $blValid = (
            in_array($sOrderPaymentId, $aFcAfterbuyDebitPayments) &&
            isset($aFcAfterbuyDebitDynBankname[$sOrderPaymentId]) &&
            isset($aFcAfterbuyDebitDynBankzip[$sOrderPaymentId]) &&
            isset($aFcAfterbuyDebitDynAccountNr[$sOrderPaymentId]) &&
            isset($aFcAfterbuyDebitDynAccountOwner[$sOrderPaymentId])
        );

        if (!$blValid) return;

        $sOrderParameters = '';
        $aDynValues = $oPaymentType->getDynValues();
        foreach ($aDynValues as $oValue) {
            $sValue = trim(urlencode($oValue->value));
            $sName = $oValue->name;

            $blBankName =
                ($sName == $aFcAfterbuyDebitDynBankname[$sOrderPaymentId]);
            $blBankZip =
                ($sName == $aFcAfterbuyDebitDynBankzip[$sOrderPaymentId]);
            $blBankAccountNr =
                ($sName == $aFcAfterbuyDebitDynAccountNr[$sOrderPaymentId]);
            $blBankAccountOwner =
                ($sName == $aFcAfterbuyDebitDynAccountOwner[$sOrderPaymentId]);

            if ($blBankName) {
                $sOrderParameters .= "&Bankname=" . $sValue;
                continue;
            }
            if ($blBankZip) {
                $sOrderParameters .= "&BLZ=" . $sValue;
                continue;
            }
            if ($blBankAccountNr) {
                $sOrderParameters .= "&Kontonummer=" . $sValue;
                continue;
            }
            if ($blBankAccountOwner) {
                $sOrderParameters .= "&Kontoinhaber=" . $sValue;
            }
        }

        return $sOrderParameters;
    }

    /**
     * Returns values set by configuraton
     *
     * @param void
     * @return string
     */
    protected function _fcGetConfigParameters() {
        $sConfigParameters = "&NoFeedback=" . $this->_fcGetEncodedConfigParameter('sFcAfterbuyFeedbackType');
        $sConfigParameters .= "&NoVersandCalc=" . $this->_fcGetEncodedConfigParameter('sFcAfterbuyDeliveryCalculation');
        $sConfigParameters .= "&MwStNichtAusweisen=" . $this->_fcGetEncodedConfigParameter('sFcAfterbuySendVat');
        $sConfigParameters .= "&MarkierungID=" . $this->_fcGetEncodedConfigParameter('sFcAfterbuyMarkId');
        $sConfigParameters .= "&Kundenerkennung=" . $this->_fcGetEncodedConfigParameter('sFcAfterbuyCustIdent');
        $sConfigParameters .= "&NoeBayNameAktu=" . $this->_fcGetEncodedConfigParameter('sFcAfterbuyOverwriteEbayName');

        return $sConfigParameters;
    }

    /**
     * Returns ready encoded config parameter
     *
     * @param $sConfigName
     * @return string
     */
    protected function _fcGetEncodedConfigParameter($sConfigName) {
        $oConfig = $this->getConfig();
        $sValue = $oConfig->getConfigParam($sConfigName);
        $sValue = trim(urlencode($sValue));

        return $sValue;
    }

    /**
     * Returns the assigned Afterbuy Payment Id for Shop payment type
     * 
     * @param string $sPaymentId Shop PaymentId
     * @return string AfterbuyPaymentId
     */
    protected function _fcGetAfterbuyPaymentId($sPaymentId)
    {
        $sQuery = "SELECT FCAFTERBUYPAYMENTID FROM fcafterbuypayments WHERE OXPAYMENTID='{$sPaymentId}'";
        $sAfterbuyPaymentId = oxDb::getDb()->getOne($sQuery);

        return (string) $sAfterbuyPaymentId;
    }

    /**
     * Due Afterbuy uses car plate country tags we will need to ask our custom table
     * 
     * @param string $sCountryId
     * @return string Car plate sign
     */
    protected function _fcGetCarPlateCountryTag($sCountryId)
    {
        $oDb = oxDb::getDb();
        $sQuery = "SELECT FCCARPLATE FROM fcafterbuycountry WHERE OXID='{$sCountryId}'";

        $sCarPlateTag = $oDb->getOne($sQuery);

        return $sCarPlateTag;
    }

    /**
     * Handles answer for requesting afterbuy order. Saving answer data into table etc.
     * 
     * @param string $sResult
     * @param oxOrder $oOrder
     * @return bool Success
     */
    protected function _fcHandleShopInterfaceAnswer($sResult, $oOrder)
    {
        $aParsedResponse = $this->_fcParseOrderRequestResult($sResult);
        if ($aParsedResponse) {
            $this->_fcAssignAfterbuyParametersToOrder($aParsedResponse, $oOrder);
        }
        else {
            /**
             * @todo deeper inspection, mark order as problematic
             */
        }
    }
    
    /**
     * Method handles xml string, transform it to array for better handling. 
     * Returns false, if xml has error or is invalid
     * 
     * @param string $sResult
     * @return mixed array/boolean
     */
    protected function _fcParseOrderRequestResult($sResult)
    {
        $mReturn = false;
        try {
            $oXml = simplexml_load_string($sResult);
            if (isset($oXml->data)) {
                $sAfterbuyAID = (string)$oXml->data->AID[0];
                $sAfterbuyVID = (string)$oXml->data->VID[0];
                $sAfterbuyUID = (string)$oXml->data->UID[0];
                $sAfterbuyCustomerNumber = (string)$oXml->data->KundenNr[0];
                $sAfterbuyECustomerNumber = (string)$oXml->data->EKundenNr[0];

                $mReturn = array(
                    'AID' => $sAfterbuyAID,
                    'VID' => $sAfterbuyVID,
                    'UID' => $sAfterbuyUID,
                    'KundenNr' => $sAfterbuyCustomerNumber,
                    'EKundenNr' => $sAfterbuyECustomerNumber,
                );
            }
        } catch (oxException $ex) {
            // this will mean either that xml could not be loaded or xml could not be parsed
            $this->fcWriteLog($ex->getMessage(), 1);
        }
        
        return $mReturn;
    }
    
    /**
     * Assigns afterbuy parameters to current order
     * 
     * @param array $aResponse
     * @param oxOrder $oOrder
     * @return void
     */
    protected function _fcAssignAfterbuyParametersToOrder($aResponse, $oOrder)
    {
        $oOrder->oxorder__fcafterbuy_aid = new oxField($aResponse['AID'], oxField::T_RAW);
        $oOrder->oxorder__fcafterbuy_vid = new oxField($aResponse['VID'], oxField::T_RAW);
        $oOrder->oxorder__fcafterbuy_uid = new oxField($aResponse['UID'], oxField::T_RAW);
        $oOrder->oxorder__fcafterbuy_customnr = new oxField($aResponse['KundenNr'], oxField::T_RAW);
        $oOrder->oxorder__fcafterbuy_ecustomnr = new oxField($aResponse['EKundenNr'], oxField::T_RAW);
        // set order as NOT fulfilled
        $oOrder->oxorder__fcafterbuy_fulfilled = new oxField('0', oxField::T_RAW);
        
        $oOrder->save();
    }

}
