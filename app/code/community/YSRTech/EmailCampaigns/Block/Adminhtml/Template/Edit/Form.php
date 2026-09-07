<?php
/**
 * Template edit form: what the email says, and what it is called.
 *
 * The content field used to be absent, on the basis that a drag and drop
 * editor would own it. That editor was never built, so the html column could
 * only ever be set from code and there was no way to write an email from the
 * admin at all.
 *
 * The field is a plain textarea rather than a WYSIWYG on purpose. Email HTML
 * is table-based and fragile, and a rich editor rewrites markup it does not
 * recognise - including the %recipient.firstname% and %unsubscribe_url%
 * tokens Mailgun substitutes, which have to survive to the wire exactly as
 * typed.
 */
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
        $fieldset->addField('html', 'textarea', [
            'label'    => $h->__('Email Content (HTML)'),
            'name'     => 'html',
            'required' => true,
            'value'    => $model->getHtml(),
            'style'    => 'width:90%; height:420px; font-family:Menlo,Consolas,monospace; font-size:12px',
            'note'     => $this->_mergeVarNote(),
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

    /**
     * What can be written into the content, spelled out where it is typed.
     *
     * @return string
     */
    protected function _mergeVarNote(): string
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        $rows = [
            '{{ customer.firstname }}' => $h->__("The recipient's first name. Give it a fallback for the many subscribers who have no name on file: {{ customer.firstname|there }}"),
            '{{ customer.lastname }}'  => $h->__('Last name, same fallback syntax.'),
            '{{ customer.email }}'     => $h->__('Their email address.'),
            '{{ store.name }}'         => $h->__('The store name.'),
            '{{ unsubscribe_url }}'    => $h->__('The opt-out link, for wording your own sentence around it.'),
            '{{unsubscribe}}'          => $h->__('The standard unsubscribe footer, placed exactly here. Leave both out and the footer is added at the end for you - every email carries one way out either way, and only ever one.'),
        ];

        $out = '<span>' . $h->__('Available placeholders:') . '</span>'
             . '<table style="margin-top:6px" cellpadding="0" cellspacing="0">';

        foreach ($rows as $token => $description) {
            $out .= '<tr>'
                . '<td style="padding:2px 12px 2px 0;vertical-align:top;white-space:nowrap">'
                . '<code>' . $this->escapeHtml($token) . '</code></td>'
                . '<td style="padding:2px 0;vertical-align:top">' . $this->escapeHtml($description) . '</td>'
                . '</tr>';
        }

        return $out . '</table>';
    }
}
