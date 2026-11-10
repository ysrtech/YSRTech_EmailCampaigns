<?php
/**
 * "Apply to existing templates and drafts" in the Brand group.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_System_Config_Brandapply
    extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        $id  = $element->getHtmlId();
        $url = $this->getUrl('adminhtml/ysrtech_sending/applyBrand');
        $j   = Mage::helper('core');

        $button = $this->getLayout()->createBlock('adminhtml/widget_button')
            ->setData([
                'id'      => $id,
                'label'   => $this->__('Apply to Existing Templates and Drafts'),
                'class'   => 'scalable',
                'type'    => 'button',
                'onclick' => 'ysrtechBrandApply()',
            ])
            ->toHtml();

        return $button . '<ul id="' . $id . '_result" class="messages" style="display:none;margin-top:8px"></ul>'
            . '<script type="text/javascript">
//<![CDATA[
function ysrtechBrandApply() {
    if (!confirm(' . $j->jsonEncode($this->__('Switch the header and footer on for every template and draft campaign, and remove their old built-in footers? This edits their content.')) . ')) { return; }
    var out = $("' . $id . '_result");
    var show = function (level, text) {
        out.update("");
        var li = new Element("li", {"class": level}), ul = new Element("ul"), inner = new Element("li");
        inner.appendChild(document.createTextNode(text));
        ul.appendChild(inner); li.appendChild(ul); out.appendChild(li); out.show();
    };
    new Ajax.Request(' . $j->jsonEncode($url) . ', {
        method: "post",
        parameters: {form_key: FORM_KEY},
        onSuccess: function (transport) {
            var data = transport.responseText.evalJSON(true);
            show(data && data.ok ? "success-msg" : "error-msg", data && data.message ? data.message : ' . $j->jsonEncode($this->__('That did not work. Reload the page and try again.')) . ');
        },
        onFailure: function () { show("error-msg", ' . $j->jsonEncode($this->__('That did not work. Reload the page and try again.')) . '); }
    });
}
//]]>
</script>';
    }
}
