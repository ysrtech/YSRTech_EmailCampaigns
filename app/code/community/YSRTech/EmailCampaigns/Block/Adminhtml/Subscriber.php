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

        if (Mage::getSingleton('admin/session')->isAllowed('ysrtech_emailcampaigns/import')) {
            $this->_addButton('import', [
                'label'   => $h->__('Import Subscribers'),
                'onclick' => "setLocation('" . $this->getUrl('*/ysrtech_import/index') . "')",
            ]);
        }
    }
}
