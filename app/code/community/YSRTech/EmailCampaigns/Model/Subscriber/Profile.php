<?php
/**
 * Everything known about one subscriber, gathered for their page: what was
 * sent to them and what they did with it, the segments they are in, whether
 * anything stops mail reaching them, and what they have bought.
 *
 * Read only. Emails are matched on the address rather than the subscriber
 * id, so what an automation sent to a customer before they subscribed is
 * here too.
 */
class YSRTech_EmailCampaigns_Model_Subscriber_Profile
{
    public const PAGE_SIZE = 50;

    /** @var Mage_Newsletter_Model_Subscriber */
    protected $_subscriber;

    public function setSubscriber(Mage_Newsletter_Model_Subscriber $subscriber): self
    {
        $this->_subscriber = $subscriber;

        return $this;
    }

    public function getSubscriber(): Mage_Newsletter_Model_Subscriber
    {
        return $this->_subscriber;
    }

    public function getEmail(): string
    {
        return (string) $this->_subscriber->getSubscriberEmail();
    }

    protected function _db()
    {
        return Mage::getSingleton('core/resource')->getConnection('core_read');
    }

    protected function _table(string $entity): string
    {
        return Mage::getSingleton('core/resource')->getTableName($entity);
    }

    /**
     * The customer account behind the address, if there is one.
     */
    public function getCustomer(): ?Mage_Customer_Model_Customer
    {
        $id = (int) $this->_subscriber->getCustomerId();

        if (!$id) {
            return null;
        }

        $customer = Mage::getModel('customer/customer')->load($id);

        return $customer->getId() ? $customer : null;
    }

    /**
     * The name to show: the subscriber's own, else the customer's, tidied.
     */
    public function getName(): string
    {
        $name = trim($this->_subscriber->getSubscriberFirstname() . ' ' . $this->_subscriber->getSubscriberLastname());

        if ($name === '' && ($customer = $this->getCustomer())) {
            $name = trim($customer->getFirstname() . ' ' . $customer->getLastname());
        }

        return $name === '' ? '' : Mage::helper('ysrtech_emailcampaigns')->tidyName($name);
    }

    /**
     * @return array{code: string, label: string, note: string}
     */
    public function getStatus(): array
    {
        $h           = Mage::helper('ysrtech_emailcampaigns');
        $suppression = $this->getSuppression();

        if ($suppression) {
            $reasons = YSRTech_EmailCampaigns_Model_Suppression::reasons();

            return [
                'code'  => 'failed',
                'label' => $h->__('Suppressed'),
                'note'  => $h->__('%s. Nothing is sent to this address.', $reasons[$suppression['reason']] ?? $suppression['reason']),
            ];
        }

        switch ((int) $this->_subscriber->getSubscriberStatus()) {
            case Mage_Newsletter_Model_Subscriber::STATUS_SUBSCRIBED:
                return ['code' => 'sent', 'label' => $h->__('Subscribed'), 'note' => $h->__('Gets your campaigns.')];
            case Mage_Newsletter_Model_Subscriber::STATUS_UNSUBSCRIBED:
                return ['code' => 'paused', 'label' => $h->__('Unsubscribed'), 'note' => $h->__('Gets no campaigns. Transactional automations still reach them.')];
            case Mage_Newsletter_Model_Subscriber::STATUS_UNCONFIRMED:
                return ['code' => '', 'label' => $h->__('Unconfirmed'), 'note' => $h->__('Has not confirmed the address yet.')];
            default:
                return ['code' => '', 'label' => $h->__('Not activated'), 'note' => $h->__('Signed up but not active.')];
        }
    }

    /**
     * @return array|null the suppression row for this address
     */
    public function getSuppression(): ?array
    {
        $row = $this->_db()->fetchRow(
            $this->_db()->select()
                ->from($this->_table('ysrtech_emailcampaigns/suppression'))
                ->where('email = ?', $this->getEmail())
        );

        return $row ?: null;
    }

    /**
     * Totals over every email ever queued for the address.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        $row = $this->_db()->fetchRow(
            $this->_db()->select()
                ->from($this->_table('ysrtech_emailcampaigns/queue'), [
                    'sent'         => new Zend_Db_Expr("SUM(status = 'sent')"),
                    'waiting'      => new Zend_Db_Expr("SUM(status IN ('pending', 'processing'))"),
                    'failed'       => new Zend_Db_Expr("SUM(status = 'failed')"),
                    'delivered'    => new Zend_Db_Expr('SUM(delivered_at IS NOT NULL)'),
                    'opened'       => new Zend_Db_Expr('SUM(opened_at IS NOT NULL)'),
                    'clicked'      => new Zend_Db_Expr('SUM(clicked_at IS NOT NULL)'),
                    'bounced'      => new Zend_Db_Expr('SUM(bounced_at IS NOT NULL)'),
                    'complained'   => new Zend_Db_Expr('SUM(complained_at IS NOT NULL)'),
                    'last_sent'    => new Zend_Db_Expr('MAX(sent_at)'),
                    'last_opened'  => new Zend_Db_Expr('MAX(opened_at)'),
                    'last_clicked' => new Zend_Db_Expr('MAX(clicked_at)'),
                    'total'        => new Zend_Db_Expr('COUNT(*)'),
                ])
                ->where('email = ?', $this->getEmail())
        ) ?: [];

        foreach (['sent', 'waiting', 'failed', 'delivered', 'opened', 'clicked', 'bounced', 'complained', 'total'] as $key) {
            $row[$key] = (int) ($row[$key] ?? 0);
        }

        // Of the emails that went out, how many they opened. Not shown until
        // there is enough to mean something.
        $row['open_rate'] = $row['sent'] >= 3 ? (int) round($row['opened'] / $row['sent'] * 100) : null;

        return $row;
    }

    /**
     * The emails, newest first, each with what happened to it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMessages(int $page = 1): array
    {
        $page   = max(1, $page);
        $select = $this->_db()->select()
            ->from(['q' => $this->_table('ysrtech_emailcampaigns/queue')], [
                'queue_id', 'campaign_id', 'automation_id', 'status', 'error_message', 'sent_at', 'send_at', 'queued_at',
                'delivered_at', 'opened_at', 'open_count', 'machine_open_count', 'clicked_at', 'click_count',
                'bounced_at', 'complained_at',
                'at' => new Zend_Db_Expr('COALESCE(q.sent_at, q.send_at, q.queued_at)'),
            ])
            ->joinLeft(
                ['c' => $this->_table('ysrtech_emailcampaigns/campaign')],
                'c.campaign_id = q.campaign_id',
                ['campaign_name' => 'name', 'campaign_subject' => 'subject']
            )
            ->joinLeft(
                ['a' => $this->_table('ysrtech_emailcampaigns/automation')],
                'a.automation_id = q.automation_id',
                ['automation_name' => 'name']
            )
            ->joinLeft(
                ['t' => $this->_table('ysrtech_emailcampaigns/template')],
                't.template_id = q.template_id',
                ['template_name' => 'name', 'template_subject' => 'subject']
            )
            ->where('q.email = ?', $this->getEmail())
            ->order(['at DESC', 'q.queue_id DESC'])
            ->limitPage($page, self::PAGE_SIZE);

        $h    = Mage::helper('ysrtech_emailcampaigns');
        $rows = [];

        foreach ($this->_db()->fetchAll($select) as $row) {
            $isFlow  = (int) $row['automation_id'] > 0;
            $subject = $isFlow
                ? ($row['template_subject'] ?: $row['template_name'])
                : ($row['campaign_subject'] ?: $row['campaign_name']);

            $rows[] = $row + [
                'kind'    => $isFlow ? 'automation' : 'campaign',
                'subject' => (string) ($subject !== null && $subject !== '' ? $subject : $h->__('(no longer exists)')),
                'from'    => (string) ($isFlow ? $row['automation_name'] : $row['campaign_name']),
            ];
        }

        return $rows;
    }

    /**
     * The orders credited to emails sent to this address: totals, and each
     * by the message that earned it.
     */
    public function getEarned(): array
    {
        return Mage::getSingleton('ysrtech_emailcampaigns/conversions')->forEmail($this->getEmail());
    }

    /**
     * @return array<int, array{segment_id: string, name: string}>
     */
    public function getSegments(): array
    {
        return $this->_db()->fetchAll(
            $this->_db()->select()
                ->from(['m' => $this->_table('ysrtech_emailcampaigns/segment_subscriber')], [])
                ->join(
                    ['s' => $this->_table('ysrtech_emailcampaigns/segment')],
                    's.segment_id = m.segment_id',
                    ['segment_id', 'name']
                )
                ->where('m.subscriber_id = ?', (int) $this->_subscriber->getId())
                ->order('s.name')
        );
    }

    /**
     * What they have bought. By the customer account where there is one,
     * which the orders table can look up directly; by the address on the
     * order otherwise, so a guest checkout still counts.
     *
     * @return array{count: int, total: float, currency: string, recent: array}
     */
    public function getOrders(): array
    {
        $table = $this->_table('sales/order');
        $who   = (int) $this->_subscriber->getCustomerId()
            ? ['customer_id = ?', (int) $this->_subscriber->getCustomerId()]
            : ['customer_email = ?', $this->getEmail()];

        $sum = $this->_db()->fetchRow(
            $this->_db()->select()
                ->from($table, [
                    'count'    => new Zend_Db_Expr('COUNT(*)'),
                    'total'    => new Zend_Db_Expr('SUM(base_grand_total)'),
                    'currency' => new Zend_Db_Expr('MAX(base_currency_code)'),
                ])
                ->where($who[0], $who[1])
                ->where('state != ?', Mage_Sales_Model_Order::STATE_CANCELED)
        ) ?: [];

        $recent = $this->_db()->fetchAll(
            $this->_db()->select()
                ->from($table, ['entity_id', 'increment_id', 'created_at', 'status', 'base_grand_total', 'base_currency_code'])
                ->where($who[0], $who[1])
                ->order('entity_id DESC')
                ->limit(8)
        );

        return [
            'count'    => (int) ($sum['count'] ?? 0),
            'total'    => (float) ($sum['total'] ?? 0),
            'currency' => (string) ($sum['currency'] ?? ''),
            'recent'   => $recent,
        ];
    }
}
