<?php
/**
 * A segment, opened: who is in it, with the way to its rules.
 *
 * Opening a segment used to open its rule builder, which answers "how is
 * this defined" when the question was nearly always "who is this". The
 * rules are one button away.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Segment_View extends Mage_Adminhtml_Block_Widget_Grid_Container
{
    public function __construct()
    {
        $h       = Mage::helper('ysrtech_emailcampaigns');
        $segment = $this->getSegment();

        $this->_blockGroup = 'ysrtech_emailcampaigns';
        $this->_controller = 'adminhtml_segment_view';
        $this->_headerText = $h->__('Segment: %s', $this->escapeHtml($segment->getName()));

        parent::__construct();

        $this->_removeButton('add');
        $this->_addButton('back', [
            'label'   => $h->__('Back'),
            'onclick' => "setLocation('" . $this->getUrl('*/*/index') . "')",
            'class'   => 'back',
        ]);
        $this->_addButton('reindex', [
            'label'   => $h->__('Recalculate Now'),
            'onclick' => "setLocation('" . $this->getUrl('*/*/reindex', ['id' => $segment->getId(), 'back' => 'view']) . "')",
        ]);
        $this->_addButton('edit', [
            'label'   => $h->__('Edit Segment Rules'),
            'onclick' => "setLocation('" . $this->getUrl('*/*/edit', ['id' => $segment->getId()]) . "')",
            'class'   => 'save',
        ]);

        $this->setTemplate('ysrtech_emailcampaigns/segment_view.phtml');
    }

    /** @return YSRTech_EmailCampaigns_Model_Segment */
    public function getSegment()
    {
        return Mage::registry('ysrtech_emailcampaigns_segment');
    }

    /**
     * The rules in words, one condition a line.
     *
     * @return string[]
     */
    public function getRuleLines(): array
    {
        return Mage::helper('ysrtech_emailcampaigns')->segmentRuleLines($this->getSegment());
    }

    public function getMemberCount(): int
    {
        return (int) $this->getSegment()->getCustomerCount();
    }

    /**
     * How many of them a campaign would actually reach: still subscribed and
     * not on the suppression list.
     */
    public function getReachableCount(): int
    {
        return YSRTech_EmailCampaigns_Model_Campaign::countRecipients([(int) $this->getSegment()->getId()], [], false);
    }

    /** @return string[] */
    public function getUsedBy(): array
    {
        return $this->getSegment()->getCampaignUsage();
    }
}
