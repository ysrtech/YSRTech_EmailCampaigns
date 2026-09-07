<?php
/** Campaign edit form: name, subject, template, segments, schedule. */
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

        $templates = $this->_toOptions(
            Mage::getResourceModel('ysrtech_emailcampaigns/template_collection')->addFieldToFilter('is_active', 1),
            'template_id'
        );
        $fieldset->addField('template_id', 'select', [
            'label'    => $h->__('Template'),
            'name'     => 'template_id',
            'required' => true,
            'values'   => $templates,
            'value'    => $model->getTemplateId(),
        ]);

        $segments = $this->_toOptions(
            Mage::getResourceModel('ysrtech_emailcampaigns/segment_collection')->addFieldToFilter('is_active', 1),
            'segment_id'
        );

        $fieldset->addField('included_segment_ids', 'multiselect', [
            'label'    => $h->__('Included Segments'),
            'name'     => 'included_segment_ids[]',
            'required' => true,
            'values'   => $segments,
            'value'    => $model->getIncludedSegmentIds(),
            'note'     => $h->__('Everyone in any of these segments. Overlaps are fine - a subscriber in two of them is still mailed once.'),
        ]);

        $fieldset->addField('excluded_segment_ids', 'multiselect', [
            'label'  => $h->__('Excluded Segments'),
            'name'   => 'excluded_segment_ids[]',
            'values' => $segments,
            'value'  => $model->getExcludedSegmentIds(),
            'note'   => $h->__('Held back even when an included segment also holds them. Exclusion wins.'),
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

    /**
     * Options for a select, keyed on the collection's own id column.
     *
     * Varien_Data_Collection::toOptionArray() takes no arguments - it always
     * reads 'id' and 'name' - so the two calls that passed 'template_id' and
     * 'segment_id' were silently ignored and every option came out with an
     * empty value. The labels looked right, which is what hid it: the form
     * listed every template and segment by name, and posted nothing at all
     * when one was chosen.
     *
     * @param  Varien_Data_Collection $collection
     * @param  string                 $valueField
     * @param  string                 $labelField
     * @return array
     */
    protected function _toOptions($collection, string $valueField, string $labelField = 'name'): array
    {
        $options = [];

        foreach ($collection as $item) {
            $options[] = [
                'value' => $item->getData($valueField),
                'label' => $item->getData($labelField),
            ];
        }

        return $options;
    }
}
