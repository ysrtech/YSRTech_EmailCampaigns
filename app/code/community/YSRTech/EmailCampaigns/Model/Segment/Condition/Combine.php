<?php
/**
 * Segment rule condition tree root. Offers the customer/order-attribute
 * condition plus nested combines, the same shape core's own catalog/cart
 * price rule combines use for their own attribute conditions.
 */
class YSRTech_EmailCampaigns_Model_Segment_Condition_Combine extends Mage_Rule_Model_Condition_Combine
{
    public function __construct()
    {
        parent::__construct();
        $this->setType('ysrtech_emailcampaigns/segment_condition_combine');
    }

    public function getNewChildSelectOptions()
    {
        $customerCondition = Mage::getModel('ysrtech_emailcampaigns/segment_condition_customer');
        $attributes = [];
        foreach ($customerCondition->loadAttributeOptions()->getAttributeOption() as $code => $label) {
            $attributes[] = ['value' => 'ysrtech_emailcampaigns/segment_condition_customer|' . $code, 'label' => $label];
        }

        $h = Mage::helper('ysrtech_emailcampaigns');
        $conditions = parent::getNewChildSelectOptions();

        return array_merge_recursive($conditions, [
            ['value' => 'ysrtech_emailcampaigns/segment_condition_combine', 'label' => $h->__('Conditions Combination')],
            ['label' => $h->__('Customer Attribute'), 'value' => $attributes],
        ]);
    }
}
