<?php
/**
 * "Test Connection" in the AI group, with what the last 30 days used.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_System_Config_Aitest
    extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        $id  = $element->getHtmlId();
        $url = $this->getUrl('adminhtml/ysrtech_sending/testAi');

        $button = $this->getLayout()->createBlock('adminhtml/widget_button')
            ->setData([
                'id'      => $id,
                'label'   => $this->__('Test Connection'),
                'class'   => 'scalable',
                'type'    => 'button',
                'onclick' => 'ysrtechAiTest()',
            ])
            ->toHtml();

        $usage = Mage::getSingleton('ysrtech_emailcampaigns/ai_client')->usage(30);
        $spent = $usage['calls'] === 0
            ? $this->__('No AI requests in the last 30 days.')
            : $this->__(
                'Last 30 days: %d request(s), %s tokens%s.',
                $usage['calls'],
                number_format($usage['tokens']),
                $usage['cost'] !== null ? ', $' . number_format($usage['cost'], 2) : ''
            );

        $failed = $this->jsQuoteEscape($this->__('The test could not be run. Reload the page and try again.'));

        // Results are built as text nodes, never as markup: what comes back
        // can include fragments of the provider's own error bodies.
        return $button . '<p class="note"><span>' . $this->escapeHtml($spent) . '</span></p>'
            . '<ul id="' . $id . '_result" class="messages" style="display:none;margin-top:8px"></ul>'
            . '<script type="text/javascript">
//<![CDATA[
function ysrtechAiTest() {
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
