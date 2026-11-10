<?php
/**
 * "Remove" per row, with the warning that fits the row.
 *
 * A link that posts, not one that gets: removing is a change, and a GET that
 * changes things can be triggered by an image tag in somebody's inbox.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Suppression_Grid_Renderer_Remove
    extends Mage_Adminhtml_Block_Widget_Grid_Column_Renderer_Abstract
{
    #[Override]
    public function render(Varien_Object $row)
    {
        $h           = Mage::helper('ysrtech_emailcampaigns');
        $isComplaint = $row->getData('reason') === YSRTech_EmailCampaigns_Model_Suppression::REASON_COMPLAINT;

        if ($isComplaint
            && !Mage::getSingleton('admin/session')->isAllowed('ysrtech_emailcampaigns/suppression/remove_complaint')
        ) {
            return '';
        }

        $confirm = $isComplaint
            ? $h->__('%s reported your email as spam. Mailing them again can damage your sender reputation and is against Mailgun\'s terms. Only remove this if they have asked to hear from you again.', $row->getData('email'))
            : $h->__('%s will be mailed again. If it bounces or complains again it is added back automatically.', $row->getData('email'));

        $url = $this->getUrl('*/*/massRemove');

        return '<a href="#" onclick="' . $this->escapeHtml(
            'if (confirm(' . Mage::helper('core')->jsonEncode($confirm) . ')) { '
            . 'new Ajax.Request(' . Mage::helper('core')->jsonEncode($url) . ', {'
            . 'method: \'post\', parameters: {form_key: FORM_KEY, suppression_ids: \'' . (int) $row->getId() . '\'},'
            . 'onComplete: function () { window.location.reload(); }}); } return false;'
        ) . '">' . $this->escapeHtml($h->__('Remove')) . '</a>';
    }
}
