<?php
/**
 * The root of a segment's condition tree.
 *
 * Offers nested combinations and subscriber conditions, and nothing else - the
 * rule engine will render any class named here, so this list is what keeps an
 * admin from building a segment out of attributes the matcher cannot answer.
 */
class YSRTech_EmailCampaigns_Model_Segment_Condition_Combine
    extends Mage_Rule_Model_Condition_Combine
{
    public function __construct()
    {
        parent::__construct();
        $this->setType('ysrtech_emailcampaigns/segment_condition_combine');
    }

    /**
     * @return array
     */
    public function getNewChildSelectOptions()
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        $attributes = [];

        foreach (Mage::getModel('ysrtech_emailcampaigns/segment_condition_subscriber')
                     ->loadAttributeOptions()->getAttributeOption() as $code => $label) {
            $attributes[] = [
                'value' => 'ysrtech_emailcampaigns/segment_condition_subscriber|' . $code,
                'label' => $label,
            ];
        }

        return array_merge_recursive(
            parent::getNewChildSelectOptions(),
            [
                [
                    'value' => 'ysrtech_emailcampaigns/segment_condition_combine',
                    'label' => $h->__('Conditions Combination'),
                ],
                [
                    'label' => $h->__('Subscriber'),
                    'value' => $attributes,
                ],
            ]
        );
    }
}
