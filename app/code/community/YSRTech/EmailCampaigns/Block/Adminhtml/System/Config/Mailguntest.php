<?php
/**
 * "Test Connection" in the Sending group.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_System_Config_Mailguntest
    extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        $id  = $element->getHtmlId();
        $url = $this->getUrl('adminhtml/ysrtech_sending/test');

        $button = $this->getLayout()->createBlock('adminhtml/widget_button')
            ->setData([
                'id'      => $id,
                'label'   => $this->__('Test Connection'),
                'class'   => 'scalable',
                'type'    => 'button',
                'onclick' => 'ysrtechMailgunTest()',
            ])
            ->toHtml();

        $failed = $this->jsQuoteEscape($this->__('The test could not be run. Reload the page and try again.'));

        /*
         * Results are built as text nodes, never as markup: what comes back
         * includes fragments of Mailgun's own error bodies.
         */
        return $button . '<ul id="' . $id . '_result" class="messages" style="display:none;margin-top:8px"></ul>'
            . '<script type="text/javascript">
//<![CDATA[
function ysrtechMailgunTest() {
    var out = $("' . $id . '_result");
    var show = function (lines) {
        out.update("");
        lines.each(function (line) {
            var cls = line.level == "ok" ? "success-msg" : (line.level == "warn" ? "notice-msg" : "error-msg");
            var li = new Element("li", {"class": cls});
            var ul = new Element("ul");
            var inner = new Element("li");
            inner.appendChild(document.createTextNode(line.text));
            ul.appendChild(inner); li.appendChild(ul); out.appendChild(li);
        });
        out.show();
    };
    new Ajax.Request("' . $url . '", {
        method: "post",
        parameters: {form_key: FORM_KEY},
        onSuccess: function (transport) {
            var data = transport.responseText.evalJSON(true);
            show(data && data.lines ? data.lines : [{level: "error", text: \'' . $failed . '\'}]);
        },
        onFailure: function () { show([{level: "error", text: \'' . $failed . '\'}]); }
    });
}
//]]>
</script>';
    }
}
