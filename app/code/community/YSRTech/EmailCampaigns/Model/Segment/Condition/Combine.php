<?php
/**
 * The root of a segment's condition tree.
 *
 * Offers nested combinations, subscriber conditions and customer conditions,
 * and nothing else - the rule engine will render any class named here, so this
 * list is what keeps an admin from building a segment out of attributes the
 * matcher cannot answer.
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

        $groups = [];

        foreach ([
            'subscriber' => $h->__('Subscriber'),
            'customer'   => $h->__('Customer Account'),
        ] as $condition => $label) {
            $model      = 'ysrtech_emailcampaigns/segment_condition_' . $condition;
            $attributes = [];

            foreach (Mage::getModel($model)->loadAttributeOptions()->getAttributeOption() as $code => $attributeLabel) {
                $attributes[] = [
                    'value' => $model . '|' . $code,
                    'label' => $attributeLabel,
                ];
            }

            $groups[] = ['label' => $label, 'value' => $attributes];
        }

        return array_merge_recursive(
            parent::getNewChildSelectOptions(),
            array_merge(
                [
                    [
                        'value' => 'ysrtech_emailcampaigns/segment_condition_combine',
                        'label' => $h->__('Conditions Combination'),
                    ],
                ],
                $groups
            )
        );
    }
}
