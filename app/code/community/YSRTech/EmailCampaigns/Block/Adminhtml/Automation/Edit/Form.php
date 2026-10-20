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

        $chain = $form->addFieldset('chain', ['legend' => $h->__('Messages')]);

        $chain->addField('steps', 'note', [
            'label' => $h->__('The Chain'),
            'text'  => $this->_stepsHtml($model),
        ]);

        $chain->addField('cancel_on', 'select', [
            'label'  => $h->__('Stop The Chain If'),
            'name'   => 'cancel_on',
            'values' => [
                ['value' => 'never',        'label' => $h->__('Nothing - send every message')],
                ['value' => 'order_placed', 'label' => $h->__('They place an order')],
            ],
            'value'  => $model->getCancelOn() ?: 'never',
            'note'   => $h->__('Checked as each message comes due, not on a schedule, so somebody who orders an hour before a nudge will not receive it. Messages already sent are unaffected.'),
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
     * The chain, as rows that can be added to and taken away.
     *
     * Its own little table rather than a fieldset per step: a chain is a list,
     * and the number of messages in it is the thing being edited.
     *
     * @param  YSRTech_EmailCampaigns_Model_Automation $model
     * @return string
     */
    protected function _stepsHtml($model): string
    {
        $h    = Mage::helper('ysrtech_emailcampaigns');
        $rows = [];

        foreach ($model->getSteps() as $step) {
            $rows[] = [
                'template_id' => (int) $step->getTemplateId(),
                'after_days'  => (int) $step->getAfterDays(),
                'after_hours' => (int) $step->getAfterHours(),
            ];
        }

        if (!$rows) {
            // A new automation starts as a chain of one, sent immediately
            $rows[] = ['template_id' => '', 'after_days' => 0, 'after_hours' => 0];
        }

        $options = '';

        foreach ($this->_templateOptions() as $option) {
            $options .= '<option value="' . (int) $option['value'] . '">'
                . $this->escapeHtml($option['label']) . '</option>';
        }

        $html = '<table class="border" id="ec-steps" cellspacing="0" style="width:auto">'
            . '<thead><tr class="headings">'
            . '<th style="padding:4px 10px">' . $h->__('#') . '</th>'
            . '<th style="padding:4px 10px">' . $h->__('Send This') . '</th>'
            . '<th style="padding:4px 10px">' . $h->__('Days') . '</th>'
            . '<th style="padding:4px 10px">' . $h->__('Hours') . '</th>'
            . '<th></th></tr></thead><tbody></tbody></table>'
            . '<button type="button" class="scalable add" id="ec-step-add" style="margin-top:8px">'
            . '<span><span><span>' . $h->__('Add a message') . '</span></span></span></button>'
            . '<p class="note"><span>'
            . $h->__('Each delay is counted from the moment the chain is triggered, not from the message before it - so 0, 2 and 7 days means day 0, day 2 and day 7.')
            . '</span></p>';

        return $html . $this->_stepsScript($options, $rows);
    }

    /**
     * @param  string $options
     * @param  array  $rows
     * @return string
     */
    protected function _stepsScript(string $options, array $rows): string
    {
        $json   = Mage::helper('core')->jsonEncode($rows);
        $remove = $this->jsQuoteEscape(Mage::helper('ysrtech_emailcampaigns')->__('Remove'));

        return <<<HTML
<script type="text/javascript">
//<![CDATA[
(function () {
    var body    = \$\$('#ec-steps tbody')[0],
        add     = \$('ec-step-add'),
        options = '{$options}',
        rows    = {$json},
        index   = 0;

    function draw(row) {
        var i = index++,
            tr = new Element('tr');

        tr.insert('<td class="ec-step-no" style="padding:4px 10px"></td>');
        tr.insert('<td style="padding:4px 10px"><select name="steps[' + i + '][template_id]" class="required-entry select">'
            + '<option value=""></option>' + options + '</select></td>');
        tr.insert('<td style="padding:4px 10px"><input type="text" class="input-text validate-digits" style="width:60px"'
            + ' name="steps[' + i + '][after_days]" value="' + (row.after_days || 0) + '" /></td>');
        tr.insert('<td style="padding:4px 10px"><input type="text" class="input-text validate-digits" style="width:60px"'
            + ' name="steps[' + i + '][after_hours]" value="' + (row.after_hours || 0) + '" /></td>');
        tr.insert('<td style="padding:4px 10px"><button type="button" class="scalable delete"><span><span><span>{$remove}</span></span></span></button></td>');

        body.appendChild(tr);

        if (row.template_id) {
            tr.down('select').value = row.template_id;
        }

        tr.down('button').observe('click', function () {
            // A chain with no messages is a rule that does nothing; keep one
            if (body.select('tr').length > 1) {
                tr.remove();
                renumber();
            }
        });

        renumber();
    }

    function renumber() {
        body.select('tr').each(function (tr, n) {
            tr.down('.ec-step-no').update(n + 1);
        });
    }

    rows.each(draw);
    add.observe('click', function () { draw({template_id: '', after_days: 0, after_hours: 0}); });
}());
//]]>
</script>
HTML;
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
