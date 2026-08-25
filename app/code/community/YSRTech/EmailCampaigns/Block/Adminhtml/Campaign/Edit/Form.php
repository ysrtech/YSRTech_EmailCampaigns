<?php
/** Campaign edit form: name, subject, template, segment, schedule. */
class YSRTech_EmailCampaigns_Block_Adminhtml_Campaign_Edit_Form extends Mage_Adminhtml_Block_Widget_Form
{
    protected function _prepareForm()
    {
        /** @var YSRTech_EmailCampaigns_Model_Campaign $model */
        $model = Mage::registry('ysrtech_emailcampaigns_campaign');
        $h = Mage::helper('ysrtech_emailcampaigns');

        $form = new Varien_Data_Form([
            'id'     => 'edit_form',
            'action' => $this->getUrl('*/*/save', ['id' => $model->getId()]),
            'method' => 'post',
        ]);
        $form->setUseContainer(true);

        $fieldset = $form->addFieldset('base', ['legend' => $h->__('Campaign Information')]);
        $fieldset->addField('name', 'text', [
            'label'    => $h->__('Campaign Name'),
            'name'     => 'name',
            'required' => true,
            'value'    => $model->getName(),
        ]);
        $fieldset->addField('subject', 'text', [
            'label'    => $h->__('Email Subject'),
            'name'     => 'subject',
            'required' => true,
            'value'    => $model->getSubject(),
        ]);

        $templates = Mage::getResourceModel('ysrtech_emailcampaigns/template_collection')
            ->addFieldToFilter('is_active', 1)
            ->toOptionArray('template_id', 'name');
        $fieldset->addField('template_id', 'select', [
            'label'    => $h->__('Template'),
            'name'     => 'template_id',
            'required' => true,
            'values'   => $templates,
            'value'    => $model->getTemplateId(),
        ]);

        $segments = Mage::getResourceModel('ysrtech_emailcampaigns/segment_collection')
            ->addFieldToFilter('is_active', 1)
            ->toOptionArray('segment_id', 'name');
        $fieldset->addField('segment_id', 'select', [
            'label'    => $h->__('Target Segment'),
            'name'     => 'segment_id',
            'required' => true,
            'values'   => $segments,
            'value'    => $model->getSegmentId(),
        ]);

        $fieldset->addField('store_id', 'select', [
            'label' => $h->__('Store View'),
            'name'  => 'store_id',
            'values'=> Mage::getSingleton('adminhtml/system_store')->getStoreValuesForForm(false, true),
            'value' => $model->getStoreId() ?: 0,
        ]);

        $fieldset->addField('scheduled_at', 'datetime', [
            'label'  => $h->__('Schedule Send (leave empty to save as draft)'),
            'name'   => 'scheduled_at',
            'image'  => $this->getSkinUrl('images/grid-cal.gif'),
            'format' => Varien_Date::DATETIME_INTERNAL_FORMAT,
            'time'   => true,
            'value'  => $model->getScheduledAt(),
        ]);

        if ($model->getStatus() === YSRTech_EmailCampaigns_Model_Campaign::STATUS_DRAFT
            || !$model->getId()
        ) {
            $fieldset->addField('action', 'select', [
                'label'  => $h->__('On Save'),
                'name'   => 'action',
                'values' => [
                    ['value' => 'draft',    'label' => $h->__('Save as draft')],
                    ['value' => 'schedule', 'label' => $h->__('Schedule & queue for sending')],
                ],
            ]);
        }

        $this->setForm($form);
        return parent::_prepareForm();
    }
}
