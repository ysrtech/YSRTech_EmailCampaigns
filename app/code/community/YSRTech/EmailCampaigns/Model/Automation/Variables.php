<?php
/**
 * The values a triggered email can talk about.
 *
 * A campaign only knows who it is writing to. A triggered message knows why
 * it is being sent - which order, which cart - and that context is the reason
 * anybody opens it. This turns the queue row back into those values.
 */
class YSRTech_EmailCampaigns_Model_Automation_Variables
{
    /**
     * @param  YSRTech_EmailCampaigns_Model_Queue $item
     * @param  int|null                           $storeId
     * @return array
     */
    public function forQueueItem($item, $storeId = null): array
    {
        $vars = [
            'customer' => ['firstname' => '', 'lastname' => '', 'email' => (string) $item->getEmail()],
            'store'    => ['name' => Mage::getSingleton('ysrtech_emailcampaigns/sender')->storeName($storeId)],
            /*
             * Triggered mail is usually transactional, and an unsubscribe link
             * in an order confirmation invites somebody to opt out of their
             * own receipts. The token is still there for the rules that want
             * it - a cart nudge should carry one - and the renderer only adds
             * a footer when the value is set.
             */
            'unsubscribe_url' => $this->_unsubscribeUrl($item, $storeId),
        ];

        $type = (string) $item->getObjectType();

        // Order status rows carry the status in the type, e.g. order_status_complete
        if (str_starts_with($type, 'order_status_')) {
            $type = 'order';
        }

        switch ($type) {
            case 'order':
                $vars = $this->_withOrder($vars, (int) $item->getObjectId());
                break;
            case 'quote':
                $vars = $this->_withQuote($vars, (int) $item->getObjectId());
                break;
            case 'shipment':
            case 'invoice':
            case 'creditmemo':
                $vars = $this->_withDocument($vars, $type, (int) $item->getObjectId());
                break;
        }

        return $vars;
    }

    /**
     * @param  array $vars
     * @param  int   $orderId
     * @return array
     */
    protected function _withOrder(array $vars, int $orderId): array
    {
        /** @var Mage_Sales_Model_Order $order */
        $order = Mage::getModel('sales/order')->load($orderId);

        if (!$order->getId()) {
            return $vars;
        }

        $vars['customer']['firstname'] = (string) $order->getCustomerFirstname();
        $vars['customer']['lastname']  = (string) $order->getCustomerLastname();

        $vars['order'] = [
            'increment_id' => (string) $order->getIncrementId(),
            'status'       => (string) $order->getStatusLabel(),
            'created_at'   => $this->_date($order->getCreatedAt(), $order->getStoreId()),
            'grand_total'  => $this->_money($order->getGrandTotal(), $order),
            'items_count'  => (int) $order->getTotalQtyOrdered(),
            'url'          => $this->_url('sales/order/view', ['order_id' => $order->getId()], $order->getStoreId()),
        ];

        $names = [];

        foreach ($order->getAllVisibleItems() as $item) {
            $names[] = $item->getName();
        }

        $vars['order']['item_names'] = implode(', ', $names);

        return $vars;
    }

    /**
     * @param  array $vars
     * @param  int   $quoteId
     * @return array
     */
    protected function _withQuote(array $vars, int $quoteId): array
    {
        /** @var Mage_Sales_Model_Quote $quote */
        $quote = Mage::getModel('sales/quote')->load($quoteId);

        if (!$quote->getId()) {
            return $vars;
        }

        $vars['customer']['firstname'] = (string) $quote->getCustomerFirstname();
        $vars['customer']['lastname']  = (string) $quote->getCustomerLastname();

        $names = [];

        foreach ($quote->getAllVisibleItems() as $item) {
            $names[] = $item->getName();
        }

        $vars['cart'] = [
            'items_count' => (int) $quote->getItemsCount(),
            'item_names'  => implode(', ', $names),
            'grand_total' => $this->_money($quote->getGrandTotal(), null, $quote->getStoreId()),
            'url'         => $this->_url('checkout/cart', [], $quote->getStoreId()),
            'checkout_url'=> $this->_url('checkout/onepage', [], $quote->getStoreId()),
        ];

        return $vars;
    }

    /**
     * @param  array  $vars
     * @param  string $type
     * @param  int    $id
     * @return array
     */
    protected function _withDocument(array $vars, string $type, int $id): array
    {
        $document = Mage::getModel('sales/order_' . $type)->load($id);

        if (!$document->getId()) {
            return $vars;
        }

        $vars = $this->_withOrder($vars, (int) $document->getOrderId());

        $vars[$type] = [
            'increment_id' => (string) $document->getIncrementId(),
            'created_at'   => $this->_date($document->getCreatedAt(), $document->getStoreId()),
        ];

        if ($type !== 'shipment') {
            $vars[$type]['grand_total'] = $this->_money($document->getGrandTotal(), null, $document->getStoreId());

            return $vars;
        }

        // A shipment's whole point is the tracking
        $numbers = [];
        $carriers = [];

        foreach ($document->getAllTracks() as $track) {
            $numbers[]  = $track->getNumber();
            $carriers[] = $track->getTitle();
        }

        $vars['shipment']['tracking_number'] = implode(', ', array_filter($numbers));
        $vars['shipment']['tracking_title']  = implode(', ', array_filter(array_unique($carriers)));

        return $vars;
    }

    /**
     * @param  YSRTech_EmailCampaigns_Model_Queue $item
     * @param  int|null                           $storeId
     * @return string
     */
    protected function _unsubscribeUrl($item, $storeId): string
    {
        $transport = Mage::getSingleton('ysrtech_emailcampaigns/transport_factory')->get();

        if ($transport->supportsRecipientVariables()) {
            return '%unsubscribe_url%';
        }

        return $this->_url('emailcampaigns/preferences/unsubscribe', ['token' => $item->getTrackingToken()], $storeId);
    }

    /**
     * @param  string   $route
     * @param  array    $params
     * @param  int|null $storeId
     * @return string
     */
    protected function _url(string $route, array $params, $storeId): string
    {
        // _nosid because this runs from cron, where reaching for a frontend
        // session dies with "Unable to start session"
        return Mage::getUrl($route, $params + ['_store' => $storeId, '_nosid' => true]);
    }

    /**
     * @param  string|null $value
     * @param  int|null    $storeId
     * @return string
     */
    protected function _date($value, $storeId): string
    {
        if (!$value) {
            return '';
        }

        return Mage::app()->getLocale()->storeDate($storeId, strtotime($value), true)->toString(Zend_Date::DATE_LONG);
    }

    /**
     * @param  float                        $amount
     * @param  Mage_Sales_Model_Order|null  $order
     * @param  int|null                     $storeId
     * @return string
     */
    protected function _money($amount, $order = null, $storeId = null): string
    {
        if ($order) {
            return $order->formatPrice((float) $amount, false);
        }

        return Mage::app()->getStore($storeId)->formatPrice((float) $amount, false);
    }
}
