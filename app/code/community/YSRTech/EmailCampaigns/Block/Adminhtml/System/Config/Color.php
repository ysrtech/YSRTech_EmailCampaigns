<?php
/**
 * A colour, picked or typed.
 *
 * The browser's own colour picker beside the text field, each keeping the
 * other up to date. No colour library: the admin has none worth loading for
 * seven fields.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_System_Config_Color
    extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        $id    = $element->getHtmlId();
        $value = (string) $element->getEscapedValue();
        $valid = preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : '#000000';

        $element->setStyle('width:110px');
        $element->setClass(trim($element->getClass() . ' validate-ysrtech-color'));

        $pickerDisabled = $element->getDisabled() ? ' disabled="disabled"' : '';

        return '<input type="color" id="' . $id . '_picker" value="' . $valid . '"' . $pickerDisabled
            . ' style="width:38px;height:26px;padding:0;border:1px solid #c8c8c8;vertical-align:middle;margin-right:6px" '
            . 'oninput="$(\'' . $id . '\').value = this.value" />'
            . $element->getElementHtml()
            . '<script type="text/javascript">
//<![CDATA[
if (!window.ysrtechColorValidation) {
    window.ysrtechColorValidation = true;
    Validation.add("validate-ysrtech-color", ' . Mage::helper('core')->jsonEncode($this->__('A colour as # and six digits or letters a-f, e.g. #1f4e79.')) . ', function (v) {
        return v === "" || /^#[0-9a-fA-F]{6}$/.test(v);
    });
}
$("' . $id . '").observe("input", function () {
    if (/^#[0-9a-fA-F]{6}$/.test(this.value)) { $("' . $id . '_picker").value = this.value; }
});
//]]>
</script>';
    }
}
