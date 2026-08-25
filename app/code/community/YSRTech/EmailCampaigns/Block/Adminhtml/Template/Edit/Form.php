<?php
/** Template edit form (metadata; content editing happens in the drag & drop editor). */
class YSRTech_EmailCampaigns_Block_Adminhtml_Template_Edit_Form extends Mage_Adminhtml_Block_Widget_Form
{
    protected function _prepareForm()
    {
        /** @var YSRTech_EmailCampaigns_Model_Template $model */
        $model = Mage::registry('ysrtech_emailcampaigns_template');
        $h = Mage::helper('ysrtech_emailcampaigns');

        $form = new Varien_Data_Form([
            'id'     => 'edit_form',
            'action' => $this->getUrl('*/*/save', ['id' => $model->getId()]),
            'method' => 'post',
        ]);
        $form->setUseContainer(true);

        $fieldset = $form->addFieldset('base', ['legend' => $h->__('Template Information')]);
        $fieldset->addField('name', 'text', [
            'label'    => $h->__('Template Name'),
            'name'     => 'name',
            'required' => true,
            'value'    => $model->getName(),
        ]);
        $fieldset->addField('subject', 'text', [
            'label'    => $h->__('Default Subject'),
            'name'     => 'subject',
            'value'    => $model->getSubject(),
        ]);
        $fieldset->addField('is_active', 'select', [
            'label'  => $h->__('Active'),
            'name'   => 'is_active',
            'values' => Mage::getSingleton('adminhtml/system_config_source_yesno')->toOptionArray(),
            'value'  => $model->getIsActive() !== null ? (int) $model->getIsActive() : 1,
        ]);

        $this->setForm($form);
        return parent::_prepareForm();
    }
}
