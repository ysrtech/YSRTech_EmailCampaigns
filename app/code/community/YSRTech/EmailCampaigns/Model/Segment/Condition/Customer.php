<?php
/**
 * Segment rule condition: customer attributes plus the order-history
 * aggregates Segment::_matchesCustomer() computes (order_count, total_spent,
 * average_order_value, last_order_days_ago). Validated against a plain
 * Varien_Object wrapping that merged data, so the inherited validate() —
 * which just reads $object->getData($this->getAttribute()) — is enough
 * without an override.
 */
class YSRTech_EmailCampaigns_Model_Segment_Condition_Customer extends Mage_Rule_Model_Condition_Abstract
{
    public function loadAttributeOptions()
    {
        $h = Mage::helper('ysrtech_emailcampaigns');
        $this->setAttributeOption([
            'group_id'             => $h->__('Customer Group'),
            'email'                => $h->__('Email'),
            'firstname'            => $h->__('First Name'),
            'lastname'             => $h->__('Last Name'),
            'order_count'          => $h->__('Number of Orders'),
            'total_spent'          => $h->__('Total Spent (Base Currency)'),
            'average_order_value'  => $h->__('Average Order Value'),
            'last_order_days_ago'  => $h->__('Days Since Last Order'),
        ]);

        return $this;
    }

    public function getInputType()
    {
        return match ($this->getAttribute()) {
            'order_count', 'total_spent', 'average_order_value', 'last_order_days_ago' => 'numeric',
            'group_id' => 'select',
            default => 'string',
        };
    }

    public function getValueElementType()
    {
        return $this->getAttribute() === 'group_id' ? 'select' : 'text';
    }

    public function getValueSelectOptions()
    {
        if (!$this->hasData('value_select_options')) {
            $options = [];
            if ($this->getAttribute() === 'group_id') {
                $options = Mage::getResourceModel('customer/group_collection')->toOptionArray();
            }
            $this->setData('value_select_options', $options);
        }

        return $this->getDataByKey('value_select_options');
    }
}
