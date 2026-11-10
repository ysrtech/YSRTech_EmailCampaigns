<?php
/**
 * Import / Migrate: the two ways in, and the history of what has been run.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Import extends Mage_Adminhtml_Block_Widget_Grid_Container
{
    public function __construct()
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        $this->_controller = 'adminhtml_import';
        $this->_blockGroup = 'ysrtech_emailcampaigns';
        $this->_headerText = $h->__('Import / Migrate');

        parent::__construct();

        $this->_removeButton('add');
        $this->_addButton('csv', [
            'label'   => $h->__('Import a CSV File'),
            'onclick' => "setLocation('" . $this->getUrl('*/*/new') . "')",
            'class'   => 'add',
        ]);
        $this->_addButton('provider', [
            'label'   => $h->__('Migrate from a Provider'),
            'onclick' => "setLocation('" . $this->getUrl('*/*/provider') . "')",
            'class'   => 'add',
        ]);

        $this->setTemplate('ysrtech_emailcampaigns/import.phtml');
    }

    /** @return string[] */
    public function getProviderNames(): array
    {
        return array_values(YSRTech_EmailCampaigns_Model_Import::providers());
    }
}
