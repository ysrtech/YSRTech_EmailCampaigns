<?php
/**
 * Subscribers: the newsletter list, kept here with the rest of the email
 * pages rather than under a Newsletter menu of its own.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Subscriber extends Mage_Adminhtml_Block_Widget_Grid_Container
{
    public function __construct()
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        $this->_blockGroup = 'ysrtech_emailcampaigns';
        $this->_controller = 'adminhtml_subscriber';
        $this->_headerText = $h->__('Subscribers');

        parent::__construct();

        $this->_removeButton('add');

        // Posted, with the session's key: it changes the list
        $this->_addButton('sync_names', [
            'label'   => $h->__('Sync Names With Customers'),
            'title'   => $h->__('Give every subscriber who has a customer account the name on that account'),
            'onclick' => "if (confirm('" . $this->jsQuoteEscape($h->__('Give every subscriber who has a customer account the name on that account? Subscribers with no account keep the name they have. Nobody\'s subscription changes.'))
                . "')) { var f = document.createElement('form'); f.method = 'post'; f.action = '" . $this->getUrl('*/*/syncNames')
                . "'; var k = document.createElement('input'); k.type = 'hidden'; k.name = 'form_key'; k.value = FORM_KEY; f.appendChild(k); document.body.appendChild(f); f.submit(); }",
        ]);

        if (Mage::getSingleton('admin/session')->isAllowed('ysrtech_emailcampaigns/import')) {
            $this->_addButton('import', [
                'label'   => $h->__('Import Subscribers'),
                'onclick' => "setLocation('" . $this->getUrl('*/ysrtech_import/index') . "')",
            ]);
        }
    }
}
