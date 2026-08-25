<?php
/**
 * Segment edit form using Magento's rule condition tree UI
 * (same widget as catalog price rules).
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Segment_Edit_Form extends Mage_Adminhtml_Block_Widget_Form
{
    protected function _prepareForm()
    {
        /** @var YSRTech_EmailCampaigns_Model_Segment $model */
        $model = Mage::registry('ysrtech_emailcampaigns_segment');
        $h = Mage::helper('ysrtech_emailcampaigns');

        $form = new Varien_Data_Form([
            'id'     => 'edit_form',
            'action' => $this->getUrl('*/*/save', ['id' => $model->getId()]),
            'method' => 'post',
        ]);
        $form->setUseContainer(true);

        $fieldset = $form->addFieldset('base', ['legend' => $h->__('Segment Information')]);
        $fieldset->addField('name', 'text', [
            'label'    => $h->__('Segment Name'),
            'name'     => 'name',
            'required' => true,
            'value'    => $model->getName(),
        ]);
        $fieldset->addField('is_active', 'select', [
            'label'   => $h->__('Active'),
            'name'    => 'is_active',
            'values'  => Mage::getSingleton('adminhtml/system_config_source_yesno')->toOptionArray(),
            'value'   => $model->getIsActive() !== null ? (int) $model->getIsActive() : 1,
        ]);

        // ---- Rule conditions tree ----
        $ruleFieldset = $form->addFieldset('rule', ['legend' => $h->__('Conditions')]);
        $ruleFieldset->addField('reindex', 'checkbox', [
            'label'  => $h->__('Recalculate matching customers after save'),
            'name'   => 'reindex',
            'value'  => 1,
            'checked'=> true,
        ]);

        /** @var Mage_SalesRule_Model_Rule_Condition_Combine $conditions */
        $conditions = $model->getConditions();
        if ($conditions instanceof Mage_Rule_Model_Condition_Interface) {
            $renderer = Mage::getBlockSingleton('adminhtml/widget_form_renderer_fieldset')
                ->setTemplate('promo/fieldset.phtml')
                ->setNewChildUrl($this->getUrl('*/*/newConditionHtml/form/rule_conditions_fieldset'));
            $ruleFieldset->setRenderer($renderer);

            $element = $ruleFieldset->addField('conditions', 'text', [
                'name'     => 'rule[conditions]',
                'label'    => $h->__('Apply to customers matching'),
                'title'    => $h->__('Conditions'),
                'required' => true,
            ]);
            $element->setRule($model)->setElement($renderer);
        }

        $this->setForm($form);
        return parent::_prepareForm();
    }
}
