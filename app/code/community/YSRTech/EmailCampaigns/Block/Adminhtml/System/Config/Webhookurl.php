<?php
/**
 * The webhook address, shown where the keys for it are entered so it can be
 * copied into Mailgun rather than assembled from memory.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_System_Config_Webhookurl
    extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        /*
         * The default store's frontend URL, not the admin's: Mailgun calls the
         * storefront, and on a store with a separate admin host the two differ.
         */
        $store = Mage::app()->getDefaultStoreView();
        $url   = Mage::getUrl('emailcampaigns/webhook/mailgun', [
            '_store'  => $store ? $store->getId() : null,
            '_nosid'  => true,
            '_secure' => true,
        ]);

        $id = $element->getHtmlId();

        return '<input type="text" id="' . $id . '" class="input-text" readonly="readonly" '
            . 'onclick="this.select()" value="' . $this->escapeHtml($url) . '" />';
    }
}
