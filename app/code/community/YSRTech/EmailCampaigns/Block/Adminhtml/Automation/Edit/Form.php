<?php
/** Automation edit form: what triggers it, how long to wait, what to send. */
class YSRTech_EmailCampaigns_Block_Adminhtml_Automation_Edit_Form extends Mage_Adminhtml_Block_Widget_Form
{
    protected function _prepareForm()
    {
        /** @var YSRTech_EmailCampaigns_Model_Automation $model */
        $model = Mage::registry('ysrtech_emailcampaigns_automation');
        $h     = Mage::helper('ysrtech_emailcampaigns');
        $src   = Mage::getSingleton('ysrtech_emailcampaigns/system_config_source_automation_event');

        $form = new Varien_Data_Form([
            'id'     => 'edit_form',
            'action' => $this->getUrl('*/*/save', ['id' => $model->getId()]),
            'method' => 'post',
        ]);
        $form->setUseContainer(true);

        $fieldset = $form->addFieldset('base', ['legend' => $h->__('Automation')]);

        $fieldset->addField('name', 'text', [
            'label'    => $h->__('Name'),
            'name'     => 'name',
            'required' => true,
            'value'    => $model->getName(),
            'note'     => $h->__('For your own reference. Recipients never see it.'),
        ]);

        $fieldset->addField('event', 'select', [
            'label'    => $h->__('Trigger'),
            'name'     => 'event',
            'required' => true,
            'values'   => $src->toOptionArray(),
            'value'    => $model->getEvent(),
        ]);

        $fieldset->addField('order_status', 'select', [
            'label'  => $h->__('Which Status'),
            'name'   => 'order_status',
            'values' => $this->_statusOptions(),
            'value'  => $model->getOrderStatus(),
            'note'   => $h->__('Only for the "Order reached a status" trigger. Fires when the order changes into this status, not on every later save.'),
        ]);

        $fieldset->addField('product_id', 'text', [
            'label' => $h->__('Product ID'),
            'name'  => 'product_id',
            'value' => $model->getProductId(),
            'note'  => $h->__('Only for the "Bought a particular product" trigger.'),
        ]);

        $fieldset->addField('template_id', 'select', [
            'label'    => $h->__('Template'),
            'name'     => 'template_id',
            'required' => true,
            'values'   => $this->_templateOptions(),
            'value'    => $model->getTemplateId(),
        ]);

        $delay = $form->addFieldset('delay', ['legend' => $h->__('Timing')]);

        $delay->addField('send_moment', 'select', [
            'label'  => $h->__('Send'),
            'name'   => 'send_moment',
            'values' => [
                ['value' => 'immediate', 'label' => $h->__('Straight away')],
                ['value' => 'after',     'label' => $h->__('After a delay')],
            ],
            'value'  => $model->getSendMoment() ?: 'immediate',
        ]);

        $delay->addField('after_days', 'text', [
            'label' => $h->__('Days'),
            'name'  => 'after_days',
            'value' => (int) $model->getAfterDays(),
        ]);

        $delay->addField('after_hours', 'text', [
            'label' => $h->__('Hours'),
            'name'  => 'after_hours',
            'value' => (int) $model->getAfterHours(),
            'note'  => $h->__('Added to the days above. The message waits in the queue until then, so a delay costs nothing while it waits.'),
        ]);

        $scope = $form->addFieldset('scope', ['legend' => $h->__('Who And When')]);

        $scope->addField('respect_subscription', 'select', [
            'label'  => $h->__('Kind Of Message'),
            'name'   => 'respect_subscription',
            'values' => [
                ['value' => 0, 'label' => $h->__('Transactional - send regardless of newsletter status')],
                ['value' => 1, 'label' => $h->__('Marketing - hold back from anyone unsubscribed')],
            ],
            'value'  => $model->getId() ? (int) $model->getRespectSubscription() : 1,
            'note'   => $h->__('A receipt is transactional and should reach somebody who left the newsletter. A cart reminder is marketing and must not. Guests who never subscribed have refused nothing, so they still get marketing.'),
        ]);

        $scope->addField('store_id', 'select', [
            'label'  => $h->__('Store View'),
            'name'   => 'store_id',
            'values' => Mage::getSingleton('adminhtml/system_store')->getStoreValuesForForm(false, true),
            'value'  => (int) $model->getStoreId(),
        ]);

        $scope->addField('active_from', 'date', [
            'label'  => $h->__('Runs From'),
            'name'   => 'active_from',
            'image'  => $this->getSkinUrl('images/grid-cal.gif'),
            'format' => Varien_Date::DATE_INTERNAL_FORMAT,
            'value'  => $model->getActiveFrom(),
        ]);

        $scope->addField('active_to', 'date', [
            'label'  => $h->__('Runs Until'),
            'name'   => 'active_to',
            'image'  => $this->getSkinUrl('images/grid-cal.gif'),
            'format' => Varien_Date::DATE_INTERNAL_FORMAT,
            'value'  => $model->getActiveTo(),
            'note'   => $h->__('Leave both empty to run indefinitely. Useful for a seasonal message you do not want to remember to switch off.'),
        ]);

        $scope->addField('is_active', 'select', [
            'label'  => $h->__('Active'),
            'name'   => 'is_active',
            'values' => Mage::getSingleton('adminhtml/system_config_source_yesno')->toOptionArray(),
            'value'  => $model->getId() ? (int) $model->getIsActive() : 1,
        ]);

        $this->setForm($form);

        return parent::_prepareForm();
    }

    /**
     * @return array
     */
    protected function _templateOptions(): array
    {
        $options = [];

        $collection = Mage::getResourceModel('ysrtech_emailcampaigns/template_collection')
            ->addFieldToFilter('is_active', 1)
            ->setOrder('name', 'ASC');

        foreach ($collection as $template) {
            $options[] = ['value' => $template->getTemplateId(), 'label' => $template->getName()];
        }

        return $options;
    }

    /**
     * @return array
     */
    protected function _statusOptions(): array
    {
        $options = [['value' => '', 'label' => Mage::helper('ysrtech_emailcampaigns')->__('-- Select --')]];

        foreach (Mage::getSingleton('sales/order_config')->getStatuses() as $code => $label) {
            $options[] = ['value' => $code, 'label' => $label];
        }

        return $options;
    }
}
