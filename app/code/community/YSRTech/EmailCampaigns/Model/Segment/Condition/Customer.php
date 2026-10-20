<?php
/**
 * Segment rule condition: the customer account behind the subscriber.
 *
 * Deliberately alongside Segment_Condition_Subscriber rather than instead of
 * it. Most of these attributes resolve to the same merged data the subscriber
 * condition reads, so "Customer > Email" and "Subscriber > Email Address"
 * behave identically; what only this one can express is Customer Group, and
 * how long the account has existed. Both come from the customer_entity join
 * in Segment::_recipientRows(), and neither has any answer for a subscriber
 * who never created an account.
 *
 * Validated against a plain Varien_Object wrapping that merged data, so the
 * inherited validate() - which reads $object->getData($this->getAttribute())
 * - is enough without an override.
 */
class YSRTech_EmailCampaigns_Model_Segment_Condition_Customer extends Mage_Rule_Model_Condition_Abstract
{
    public function loadAttributeOptions()
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        $this->setAttributeOption([
            'group_id'            => $h->__('Customer Group'),
            'customer_days_old'   => $h->__('Days Since Account Created'),
            'email'               => $h->__('Email'),
            'firstname'           => $h->__('First Name'),
            'lastname'            => $h->__('Last Name'),
            'order_count'         => $h->__('Number Of Orders'),
            'total_spent'         => $h->__('Total Spent (Base Currency)'),
            'average_order_value' => $h->__('Average Order Value'),
            'last_order_days_ago' => $h->__('Days Since Last Order'),
        ]);

        return $this;
    }

    public function getInputType()
    {
        return match ($this->getAttribute()) {
            'order_count', 'total_spent', 'average_order_value',
            'last_order_days_ago', 'customer_days_old' => 'numeric',
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
                // This collection overrides toOptionArray() to read
                // customer_group_id/customer_group_code, so unlike the plain
                // Varien_Data_Collection it does return usable options.
                $options = Mage::getResourceModel('customer/group_collection')->toOptionArray();
            }

            $this->setData('value_select_options', $options);
        }

        return $this->getDataByKey('value_select_options');
    }
}
