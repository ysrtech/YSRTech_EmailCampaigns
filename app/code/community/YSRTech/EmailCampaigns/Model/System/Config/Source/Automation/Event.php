<?php
class YSRTech_EmailCampaigns_Model_System_Config_Source_Automation_Event
{
    public const NEW_ORDER         = 'new_order';
    public const ORDER_STATUS      = 'order_status';
    public const PRODUCT_PURCHASED = 'product_purchased';
    public const ABANDONED_CART    = 'abandoned_cart';
    public const SHIPMENT          = 'shipment';
    public const INVOICE           = 'invoice';
    public const CREDITMEMO        = 'creditmemo';

    /**
     * @return array
     */
    public function toArray(): array
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        return [
            self::NEW_ORDER         => $h->__('Order placed'),
            self::ORDER_STATUS      => $h->__('Order reached a status'),
            self::PRODUCT_PURCHASED => $h->__('Bought a particular product'),
            self::ABANDONED_CART    => $h->__('Left a cart behind'),
            self::SHIPMENT          => $h->__('Order shipped'),
            self::INVOICE           => $h->__('Invoice raised'),
            self::CREDITMEMO        => $h->__('Refunded'),
        ];
    }

    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        $out = [];

        foreach ($this->toArray() as $value => $label) {
            $out[] = ['value' => $value, 'label' => $label];
        }

        return $out;
    }

    /**
     * Which of these read as marketing rather than transactional.
     *
     * Only the default for a new rule. Whether a shipment notice counts as
     * marketing is a judgement about its wording, not something a list of
     * event names can settle, so the form lets it be overridden.
     *
     * @return string[]
     */
    public static function marketingEvents(): array
    {
        return [self::ABANDONED_CART, self::PRODUCT_PURCHASED];
    }
}
