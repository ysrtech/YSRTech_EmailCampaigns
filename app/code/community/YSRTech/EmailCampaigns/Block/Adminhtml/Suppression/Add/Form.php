<?php
class YSRTech_EmailCampaigns_Block_Adminhtml_Suppression_Add_Form extends Mage_Adminhtml_Block_Widget_Form
{
    #[Override]
    protected function _prepareForm()
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        $form = new Varien_Data_Form([
            'id'      => 'edit_form',
            'action'  => $this->getUrl('*/*/addPost'),
            'method'  => 'post',
            'enctype' => 'multipart/form-data',
        ]);
        $form->setUseContainer(true);

        $fieldset = $form->addFieldset('base', ['legend' => $h->__('Addresses never to be mailed')]);

        $fieldset->addField('emails', 'textarea', [
            'label' => $h->__('Email Addresses'),
            'name'  => 'emails',
            'note'  => $h->__('One per line, or separated by commas. Up to %s at a time.', number_format(YSRTech_EmailCampaigns_Model_Suppression::MAX_BULK)),
            'style' => 'width:500px;height:200px',
        ]);

        $fieldset->addField('file', 'file', [
            'label' => $h->__('Or a CSV / Text File'),
            'name'  => 'file',
            'note'  => $h->__('Every email address found anywhere in the file is added, whatever its columns are. Up to 5 MB.'),
        ]);

        $fieldset->addField('detail', 'text', [
            'label' => $h->__('Note'),
            'name'  => 'detail',
            'note'  => $h->__('Optional. Shown in the Detail column, e.g. "asked by phone".'),
        ]);

        $this->setForm($form);

        return parent::_prepareForm();
    }
}
