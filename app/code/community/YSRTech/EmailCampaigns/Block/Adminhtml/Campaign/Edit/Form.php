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

        $segments = $this->_segmentOptions();

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

        /*
         * The one number that matters, and the one nobody can work out by
         * reading the two lists: segments overlap, and people who have
         * unsubscribed drop out of all of them. Computed by the same query
         * that fills the queue.
         */
        $fieldset->addField('recipient_estimate', 'note', [
            'label' => $h->__('Will Be Sent To'),
            'text'  => $this->_estimateHtml($model),
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
    /**
     * Segments to choose from, each carrying its own size.
     *
     * The count belongs on the option because it is what the choice is
     * actually about - "Past Customers" says nothing about whether this is a
     * send to four hundred people or four thousand.
     *
     * @return array
     */
    protected function _segmentOptions(): array
    {
        $options = [];

        $collection = Mage::getResourceModel('ysrtech_emailcampaigns/segment_collection')
            ->addFieldToFilter('is_active', 1)
            ->setOrder('name', 'ASC');

        foreach ($collection as $segment) {
            $options[] = [
                'value' => $segment->getSegmentId(),
                'label' => sprintf(
                    '%s (%s)',
                    $segment->getName(),
                    $segment->getLastReindexedAt()
                        ? number_format((int) $segment->getCustomerCount())
                        : Mage::helper('ysrtech_emailcampaigns')->__('not counted yet')
                ),
            ];
        }

        return $options;
    }

    /**
     * @param  YSRTech_EmailCampaigns_Model_Campaign $model
     * @return string
     */
    protected function _estimateHtml($model): string
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        $count = 0;

        if ($model->getIncludedSegmentIds()) {
            // Read as it stands. Merely opening a campaign should not rewrite
            // membership tables; Recalculate is the button that does that.
            $count = YSRTech_EmailCampaigns_Model_Campaign::countRecipients(
                $model->getIncludedSegmentIds(),
                $model->getExcludedSegmentIds(),
                false
            );
        }

        return '<strong id="recipient_estimate_value" style="font-size:1.3em">'
            . number_format($count)
            . '</strong> '
            . '<span id="recipient_estimate_label">' . $h->__('subscribers') . '</span> '
            . '<button type="button" class="scalable" id="recipient_estimate_refresh" style="margin-left:10px">'
            . '<span><span><span>' . $h->__('Recalculate') . '</span></span></span></button>'
            . '<p class="note"><span>'
            . $h->__('Everyone in the included segments, minus the excluded ones, minus anybody unsubscribed. Overlaps counted once.')
            . '</span></p>'
            . '<p class="note"><span>'
            . $h->__('The figure above is from the last nightly rebuild. Recalculate refreshes the chosen segments first, so it counts the list as it stands right now - as the send itself will.')
            . '</span></p>'
            . $this->_estimateScript();
    }

    /**
     * @return string
     */
    protected function _estimateScript(): string
    {
        $url     = $this->getUrl('*/*/recipientCount');
        $working = Mage::helper('ysrtech_emailcampaigns')->__('counting...');

        return <<<HTML
<script type="text/javascript">
//<![CDATA[
(function () {
    var button = $('recipient_estimate_refresh'),
        value  = $('recipient_estimate_value');

    if (!button || !value) {
        return;
    }

    function chosen(id) {
        var el = $(id), out = [], i;
        if (!el) {
            return out;
        }
        for (i = 0; i < el.options.length; i++) {
            if (el.options[i].selected) {
                out.push(el.options[i].value);
            }
        }
        return out;
    }

    button.observe('click', function () {
        if (button.disabled) {
            return;
        }
        // Rebuilding membership before counting takes a moment; a second
        // click would start the same work again alongside the first
        button.disabled = true;
        button.addClassName('disabled');
        value.update('{$working}');
        new Ajax.Request('{$url}', {
            method: 'post',
            parameters: {
                form_key: FORM_KEY,
                // Prototype serializes an array parameter under name[] itself
                'included_segment_ids[]': chosen('included_segment_ids'),
                'excluded_segment_ids[]': chosen('excluded_segment_ids')
            },
            onSuccess: function (response) {
                var data;
                try {
                    data = response.responseJSON || response.responseText.evalJSON();
                } catch (e) {
                    value.update('?');
                    return;
                }
                value.update(data.error ? '?' : data.formatted);
                if (data.error) {
                    alert(data.error);
                }
            },
            onFailure: function () {
                value.update('?');
            },
            onComplete: function () {
                button.disabled = false;
                button.removeClassName('disabled');
            }
        });
    });
}());
//]]>
</script>
HTML;
    }

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
