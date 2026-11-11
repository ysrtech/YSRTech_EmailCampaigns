<?php
/**
 * The Model field in the AI group: the box to type an id into, and beside
 * it a list fetched from the provider to choose from instead, with the few
 * that suit this work put first.
 *
 * The box stays the thing that is saved, so an id the list does not offer
 * can still be typed.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_System_Config_Aimodel
    extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        $id  = $element->getHtmlId();
        $url = $this->getUrl('adminhtml/ysrtech_sending/aiModels');

        $button = $this->getLayout()->createBlock('adminhtml/widget_button')
            ->setData([
                'id'      => $id . '_load',
                'label'   => $this->__('Choose From List'),
                'class'   => 'scalable',
                'type'    => 'button',
                'onclick' => 'ysrtechAiModels()',
            ])
            ->toHtml();

        $t = [
            'loading'     => $this->__('Loading…'),
            'choose'      => $this->__('Choose From List'),
            'pick'        => $this->__('Choose a model…'),
            'recommended' => $this->__('Recommended'),
            'all'         => $this->__('All models'),
            'failed'      => $this->__('The list could not be loaded. Reload the page and try again.'),
        ];

        // Option labels are set as text, never as markup: they come from the
        // provider's own list.
        return parent::_getElementHtml($element)
            . '<div style="margin-top:6px">' . $button
            . ' <select id="' . $id . '_list" style="display:none;max-width:420px"></select></div>'
            . '<p class="note" id="' . $id . '_msg" style="display:none"><span></span></p>'
            . '<script type="text/javascript">
//<![CDATA[
function ysrtechAiModels() {
    var t = ' . Mage::helper('core')->jsonEncode($t) . ';
    var box = $("' . $id . '"), list = $("' . $id . '_list"), msg = $("' . $id . '_msg"), button = $("' . $id . '_load");
    var provider = $("ysrtech_emailcampaigns_ai_provider");
    var label = button.down("span span span") || button;
    var say = function (text) { msg.down("span").update("").appendChild(document.createTextNode(text)); msg.show(); };
    var add = function (parent, model) {
        var option = new Element("option", {value: model.id});
        option.appendChild(document.createTextNode(model.label));
        if (model.id == box.value) { option.selected = true; }
        parent.appendChild(option);
    };

    button.disabled = true;
    label.update(t.loading);
    msg.hide();

    new Ajax.Request("' . $url . '", {
        method: "post",
        parameters: {form_key: FORM_KEY, provider: provider ? provider.value : ""},
        onComplete: function (transport) {
            button.disabled = false;
            label.update(t.choose);

            var data = null;
            try { data = transport.responseText.evalJSON(true); } catch (e) {}

            if (!data || !data.ok) {
                say(data && data.message ? data.message : t.failed);
                return;
            }

            list.update("");
            var first = new Element("option", {value: ""});
            first.appendChild(document.createTextNode(t.pick));
            list.appendChild(first);

            [[t.recommended, data.recommended], [t.all, data.all]].each(function (pair) {
                if (!pair[1].length) { return; }
                var group = new Element("optgroup", {label: pair[0]});
                pair[1].each(function (model) { add(group, model); });
                list.appendChild(group);
            });

            list.onchange = function () { if (list.value) { box.value = list.value; } };
            list.show();
            if (data.note) { say(data.note); }
        }
    });
}
//]]>
</script>';
    }
}
