<?php
class YSRTech_EmailCampaigns_Block_Adminhtml_Import_New_Form extends Mage_Adminhtml_Block_Widget_Form
{
    #[Override]
    protected function _prepareForm()
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        $form = new Varien_Data_Form([
            'id'      => 'edit_form',
            'action'  => $this->getUrl('*/*/upload'),
            'method'  => 'post',
            'enctype' => 'multipart/form-data',
        ]);
        $form->setUseContainer(true);

        $fieldset = $form->addFieldset('base', ['legend' => $h->__('The file')]);

        $fieldset->addField('file', 'file', [
            'label'    => $h->__('CSV File'),
            'name'     => 'file',
            'required' => true,
            'note'     => $h->__('With a header row and one person per line. Which column is which is chosen on the next screen. Up to %d MB.', YSRTech_EmailCampaigns_Adminhtml_Ysrtech_ImportController::MAX_UPLOAD_MB),
        ]);

        $fieldset->addField('consent', 'checkbox', [
            'label'    => $h->__('Permission'),
            'name'     => 'consent',
            'value'    => 1,
            'required' => true,
            'after_element_html' => ' <label for="consent">' . $this->escapeHtml($h->__('Everybody in this file asked to receive email from this store.')) . '</label>',
            'note'     => $h->__('They are added to Newsletter Subscribers as subscribed, with no confirmation email. A bought or scraped list will get the sending domain blocked on its first campaign.'),
        ]);

        $this->setForm($form);

        return parent::_prepareForm();
    }
}
