<?php
/**
 * One condition about one newsletter subscriber.
 *
 * The attributes offered here are exactly the ones Segment::_matches() puts on
 * the object it validates - the subscriber's own fields plus the order-history
 * aggregates computed alongside them. That correspondence is the whole point:
 * the form used to offer salesrule's cart attributes (Subtotal, Shipping
 * Method, Payment Method), which describe a basket going through checkout.
 * None of them exist on a subscriber, so a segment built from them matched
 * nobody and, worse, threw on validate() the moment a reindex ran.
 */
class YSRTech_EmailCampaigns_Model_Segment_Condition_Subscriber
    extends Mage_Rule_Model_Condition_Abstract
{
    public function __construct()
    {
        parent::__construct();
        $this->setType('ysrtech_emailcampaigns/segment_condition_subscriber');
    }

    /**
     * @return $this
     */
    public function loadAttributeOptions()
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        $this->setAttributeOption([
            'email'               => $h->__('Email Address'),
            'firstname'           => $h->__('First Name'),
            'lastname'            => $h->__('Last Name'),
            'is_customer'         => $h->__('Has A Customer Account'),
            'store_id'            => $h->__('Store View'),
            'order_count'         => $h->__('Number Of Orders'),
            'total_spent'         => $h->__('Total Spent'),
            'average_order_value' => $h->__('Average Order Value'),
            'last_order_days_ago' => $h->__('Days Since Last Order'),
        ]);

        return $this;
    }

    /**
     * @return string
     */
    public function getInputType()
    {
        switch ($this->getAttribute()) {
            case 'order_count':
            case 'total_spent':
            case 'average_order_value':
            case 'last_order_days_ago':
                return 'numeric';
            case 'is_customer':
            case 'store_id':
                return 'select';
            default:
                return 'string';
        }
    }

    /**
     * @return string
     */
    public function getValueElementType()
    {
        switch ($this->getAttribute()) {
            case 'is_customer':
            case 'store_id':
                return 'select';
            default:
                return 'text';
        }
    }

    /**
     * @return array
     */
    public function getValueSelectOptions()
    {
        if ($this->hasData('value_select_options')) {
            return $this->getData('value_select_options');
        }

        $options = [];

        if ($this->getAttribute() === 'is_customer') {
            $options = Mage::getSingleton('adminhtml/system_config_source_yesno')->toOptionArray();
        } elseif ($this->getAttribute() === 'store_id') {
            foreach (Mage::app()->getStores() as $store) {
                $options[] = ['value' => $store->getId(), 'label' => $store->getName()];
            }
        }

        $this->setData('value_select_options', $options);

        return $options;
    }

    /**
     * "Days Since Last Order" reads naturally but is stored as a large sentinel
     * for anyone who has never ordered, so that "has not ordered in 90 days"
     * includes them and "ordered in the last 30" does not. Spell that out
     * rather than leaving a reader to discover 99999 in a segment count.
     *
     * @return string
     */
    public function getValueAfterElementHtml()
    {
        if ($this->getAttribute() === 'last_order_days_ago') {
            return '<span class="rule-param-tip"> '
                . Mage::helper('ysrtech_emailcampaigns')->__('Subscribers who have never ordered count as a very large number of days.')
                . '</span>';
        }

        return parent::getValueAfterElementHtml();
    }
}
