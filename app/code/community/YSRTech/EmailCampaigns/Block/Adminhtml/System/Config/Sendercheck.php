<?php
/**
 * The Sender Check, in the module's settings: what Gmail, Yahoo and
 * Microsoft require of a bulk sender, checked against the sending domain,
 * with the fixes that can be made from here on a button each.
 *
 * It sits inside the configuration page's one big form, so its buttons are
 * not forms of their own: each posts a small form made for the purpose,
 * with the session's key.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_System_Config_Sendercheck
    extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    /**
     * The whole row: no label column, no scope note.
     */
    #[Override]
    public function render(Varien_Data_Form_Element_Abstract $element)
    {
        return '<tr id="row_' . $element->getHtmlId() . '"><td colspan="5" class="value" style="width:auto">' . $this->_panel() . '</td></tr>';
    }

    protected function _button(string $label, string $action, string $fix = '', string $class = ''): string
    {
        return '<button type="button" class="scalable ' . $class . '" data-ec-post="' . $this->escapeHtml($this->getUrl('adminhtml/ysrtech_dashboard/' . $action)) . '"'
            . ' data-ec-fix="' . $this->escapeHtml($fix) . '"><span><span><span>' . $this->escapeHtml($label) . '</span></span></span></button>';
    }

    protected function _panel(): string
    {
        $e     = fn ($text) => $this->escapeHtml((string) $text);
        $check = Mage::getSingleton('ysrtech_emailcampaigns/deliverability')->latest();
        $css   = $this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_assets')->versioned('ysrtech/emailcampaigns/admin.css');

        $html = '<link rel="stylesheet" type="text/css" href="' . $e($css) . '" media="all" />'
            . '<div id="ysrtech-ec-sender"><p style="margin:0 0 10px">'
            . $this->_button($check ? $this->__('Check Again') : $this->__('Run Check'), 'senderCheck') . '</p>';

        if (!$check) {
            $html .= '<p>' . $e($this->__('Checks what Gmail, Yahoo and Microsoft require of a bulk sender: the sending domain\'s SPF, DKIM and DMARC records, that bounces and complaints are being recorded, how links and the unsubscribe link in your emails are set up, and the bounce and complaint rates of the last 30 days. It looks the domain up in DNS and asks the provider, so it runs when asked rather than every time this page opens.')) . '</p>';
        } else {
            $grade = $check['score'] >= 85 ? 'good' : ($check['score'] >= 60 ? 'fair' : 'poor');
            $html .= '<div class="ysrtech-ec-check"><div class="score ' . $grade . '"><div class="n">' . (int) $check['score'] . '</div><div class="of">' . $e($this->__('out of 100')) . '</div></div><div class="body">'
                . '<p class="note">' . $e($this->__('Sending as %s through %s. Checked %s.', $check['from_domain'] ?: '—', $check['sending_domain'] ?: '—',
                    Mage::helper('core')->formatDate($check['checked_at'], 'medium', true))) . '</p>';

            if (!$check['findings']) {
                $html .= '<p><strong>' . $e($this->__('Nothing to fix.')) . '</strong></p>';
            }

            foreach ($check['findings'] as $finding) {
                $html .= '<div class="finding ' . $e($finding['level']) . '"><strong>' . $e($finding['title']) . '</strong>'
                    . '<span class="gain">' . $e($this->__('+%d when fixed', $finding['weight'])) . '</span><p>' . $e($finding['text']) . '</p>'
                    . ($finding['fix'] !== '' ? '<p class="fix">' . $e($finding['fix']) . '</p>' : '');

                $action = (string) ($finding['action'] ?? '');

                if ($action === 'footer') {
                    $html .= '<p class="fix-form">' . $this->_button($this->__('Remove Mailgun\'s Footer'), 'mailgunFix', 'footer', 'save') . '</p>';
                } elseif ($action === 'https') {
                    $html .= '<p class="fix-form">' . $this->_button($this->__('Send Links Straight To The Store'), 'mailgunFix', 'direct', 'save')
                        . ' ' . $this->_button($this->__('Switch To https On Mailgun'), 'mailgunFix', 'https') . '</p>';
                }

                $html .= '</div>';
            }

            foreach (['passed', 'unknown'] as $list) {
                if ($check[$list]) {
                    $html .= '<ul class="' . $list . '">' . implode('', array_map(fn ($line) => '<li>' . $e($line) . '</li>', $check[$list])) . '</ul>';
                }
            }

            $html .= '</div></div>';
        }

        return $html . '</div><script type="text/javascript">//<![CDATA[
(function () {
    var box = document.getElementById("ysrtech-ec-sender");
    if (!box) { return; }
    box.addEventListener("click", function (e) {
        var button = e.target.closest ? e.target.closest("[data-ec-post]") : null;
        if (!button) { return; }
        e.preventDefault();
        var form = document.createElement("form");
        form.method = "post";
        form.action = button.getAttribute("data-ec-post");
        [["form_key", window.FORM_KEY], ["fix", button.getAttribute("data-ec-fix")]].forEach(function (pair) {
            var input = document.createElement("input");
            input.type = "hidden"; input.name = pair[0]; input.value = pair[1] || "";
            form.appendChild(input);
        });
        document.body.appendChild(form);
        button.disabled = true;
        form.submit();
    });
}());
//]]></script>';
    }
}
