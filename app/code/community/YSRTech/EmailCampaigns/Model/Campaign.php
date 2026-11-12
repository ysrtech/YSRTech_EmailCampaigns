<?php
class YSRTech_EmailCampaigns_Model_Campaign extends Mage_Core_Model_Abstract
{
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENDING   = 'sending';
    public const STATUS_SENT      = 'sent';
    public const STATUS_PAUSED    = 'paused';
    public const STATUS_CANCELLED = 'cancelled';
    /** Queued, attempted, and nobody received it */
    public const STATUS_FAILED    = 'failed';

    protected function _construct()
    {
        $this->_init('ysrtech_emailcampaigns/campaign');
    }

    /**
     * Segments whose members this campaign goes to.
     *
     * @return int[]
     */
    public function getIncludedSegmentIds(): array
    {
        if (!$this->hasData('included_segment_ids')) {
            $this->setData('included_segment_ids', $this->_loadSegmentIds(false));
        }

        return array_map('intval', (array) $this->getData('included_segment_ids'));
    }

    /**
     * Segments whose members are held back, whichever included segment also
     * holds them. Exclusion wins: that is the point of it.
     *
     * @return int[]
     */
    public function getExcludedSegmentIds(): array
    {
        if (!$this->hasData('excluded_segment_ids')) {
            $this->setData('excluded_segment_ids', $this->_loadSegmentIds(true));
        }

        return array_map('intval', (array) $this->getData('excluded_segment_ids'));
    }

    /**
     * @param  bool $excluded
     * @return int[]
     */
    protected function _loadSegmentIds(bool $excluded): array
    {
        if (!$this->getId()) {
            return [];
        }

        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_read');

        return array_map('intval', $adapter->fetchCol(
            $adapter->select()
                ->from($resource->getTableName('ysrtech_emailcampaigns/campaign_segment'), 'segment_id')
                ->where('campaign_id = ?', (int) $this->getId())
                ->where('is_excluded = ?', $excluded ? 1 : 0)
        ));
    }

    /**
     * Rewrite the campaign's segment links from what the form posted.
     *
     * Called after the row is saved, so a new campaign has an id to hang them
     * on. A segment named on both sides is treated as excluded - the safer
     * reading, and the primary key would reject the pair anyway.
     *
     * @return $this
     */
    protected function _beforeSave()
    {
        parent::_beforeSave();
        $this->setUpdatedAt(Varien_Date::now());

        return $this;
    }

    /**
     * Whether somebody has named this campaign. A new one starts without a
     * name, and nothing else about it can be saved until it has one;
     * "Untitled campaign" is what older drafts were given before that and
     * counts as no name.
     */
    public function hasName(): bool
    {
        $name = trim((string) $this->getName());

        return $name !== '' && $name !== 'Untitled campaign'
            && $name !== Mage::helper('ysrtech_emailcampaigns')->__('Untitled campaign');
    }

    /**
     * Whether this campaign is a plain-text letter rather than a designed
     * email.
     */
    public function isLetter(): bool
    {
        return (string) $this->getFormat() === YSRTech_EmailCampaigns_Model_Letter::FORMAT;
    }

    /**
     * The builder document behind this campaign's own email, if it has one.
     */
    public function getDoc(): ?array
    {
        $doc = json_decode((string) $this->getDesignJson(), true);

        return is_array($doc) && isset($doc['rows'], $doc['settings']) ? $doc : null;
    }

    /**
     * Take a builder document as this campaign's own email.
     *
     * @return $this
     */
    public function setDoc(array $doc)
    {
        $doc = Mage::getModel('ysrtech_emailcampaigns/design_doc')->clean($doc, $this->getStoreId() ?: null);

        return $this->addData([
            'design_json' => json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'html'        => Mage::getSingleton('ysrtech_emailcampaigns/design_html')->render($doc, (string) $this->getSubject()),
            'use_brand'   => YSRTech_EmailCampaigns_Model_Renderer::FRAME_DOC,
            'format'      => 'design',
        ]);
    }

    /**
     * Whether the campaign has its own copy of the email.
     */
    public function hasOwnContent(): bool
    {
        return trim((string) $this->getData('html')) !== '';
    }

    /**
     * What this campaign sends, as a template the renderer understands.
     *
     * Its own copy when it has one. Otherwise the template it points at,
     * which is how every campaign worked before campaigns could carry their
     * content - those keep sending exactly what they did.
     *
     * @return YSRTech_EmailCampaigns_Model_Template|null Null when there is nothing to send
     */
    public function getContentTemplate()
    {
        if ($this->isLetter()) {
            if (trim((string) $this->getLetterText()) === '') {
                return null;
            }

            /*
             * A stand-in, so that the preview, the checks and the subject
             * suggestions see a letter the way they see any email. Sending
             * does not go through this: Sender builds a letter's text itself.
             */
            return Mage::getModel('ysrtech_emailcampaigns/template')->setData([
                'name'      => (string) $this->getName(),
                'subject'   => (string) $this->getSubject(),
                'html'      => (string) $this->getLetterText(),
                'is_letter' => true,
            ]);
        }

        if ($this->hasOwnContent()) {
            // Never saved: a stand-in so that one renderer serves both
            return Mage::getModel('ysrtech_emailcampaigns/template')->setData([
                'name'      => (string) $this->getName(),
                'subject'   => (string) $this->getSubject(),
                'preheader' => (string) $this->getPreheader(),
                'html'        => (string) $this->getData('html'),
                'design_json' => (string) $this->getData('design_json'),
                'use_brand'   => (int) $this->getUseBrand(),
            ]);
        }

        $template = Mage::getModel('ysrtech_emailcampaigns/template')->load((int) $this->getTemplateId());

        if (!$template->getId()) {
            return null;
        }

        // Whether it wears the store's header and footer is the campaign's
        // choice, also when the email itself is still the template's
        $template->setUseBrand((int) $this->getUseBrand());

        // The campaign's own preview text wins over the template's
        if (trim((string) $this->getPreheader()) !== '') {
            $template->setPreheader((string) $this->getPreheader());
        }

        return $template;
    }

    /**
     * The domain every campaign is sent from: the configured default
     * sender's, or the Mailgun sending domain when no default is set.
     */
    public static function senderDomain(): string
    {
        $helper  = Mage::helper('ysrtech_emailcampaigns');
        $default = (string) $helper->getConfig('sending/from_email');
        $at      = strrpos($default, '@');

        return strtolower(trim($at !== false ? substr($default, $at + 1) : (string) $helper->getConfig('sending/mailgun_domain')));
    }

    /**
     * The sender address: this campaign's local part at the fixed domain, or
     * the configured default when the campaign has none.
     */
    public function getFromEmail(): string
    {
        $local  = trim((string) $this->getFromLocal());
        $domain = self::senderDomain();

        if ($local !== '' && $domain !== '') {
            return $local . '@' . $domain;
        }

        return (string) Mage::helper('ysrtech_emailcampaigns')->getConfig('sending/from_email');
    }

    public function getFromNameOrDefault(): string
    {
        return trim((string) $this->getFromName())
            ?: (string) (Mage::helper('ysrtech_emailcampaigns')->getConfig('sending/from_name')
                ?: Mage::app()->getStore($this->getStoreId())->getFrontendName());
    }

    protected function _afterSave()
    {
        parent::_afterSave();

        if (!$this->hasData('included_segment_ids') && !$this->hasData('excluded_segment_ids')) {
            return $this;
        }

        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_write');
        $table    = $resource->getTableName('ysrtech_emailcampaigns/campaign_segment');

        $excluded = array_values(array_unique(array_map('intval', (array) $this->getData('excluded_segment_ids'))));
        $included = array_values(array_diff(
            array_unique(array_map('intval', (array) $this->getData('included_segment_ids'))),
            $excluded
        ));

        $adapter->delete($table, ['campaign_id = ?' => (int) $this->getId()]);

        $rows = [];

        foreach ($included as $id) {
            $rows[] = ['campaign_id' => (int) $this->getId(), 'segment_id' => $id, 'is_excluded' => 0];
        }

        foreach ($excluded as $id) {
            $rows[] = ['campaign_id' => (int) $this->getId(), 'segment_id' => $id, 'is_excluded' => 1];
        }

        if ($rows) {
            $adapter->insertMultiple($table, $rows);
        }

        return $this;
    }

    /**
     * Recalculate membership for the segments named, so a count or a send
     * reflects the list as it is now.
     *
     * Segments reindex nightly, and between those runs people subscribe,
     * unsubscribe and place orders. Reading yesterday's membership to decide
     * today's audience is how somebody who unsubscribed this morning still
     * gets the email.
     *
     * @param  int[] $segmentIds
     * @return void
     */
    public static function reindexSegments(array $segmentIds): void
    {
        foreach (array_unique(array_filter(array_map('intval', $segmentIds))) as $segmentId) {
            $segment = Mage::getModel('ysrtech_emailcampaigns/segment')->load($segmentId);

            if ($segment->getId()) {
                /*
                 * One failing segment must not lose the rest: a count built
                 * from three fresh segments and one stale one is still far
                 * closer to the truth than no answer at all.
                 */
                try {
                    $segment->reindex();
                } catch (Throwable $e) {
                    Mage::logException($e);
                }
            }
        }
    }

    /**
     * How many people a given include/exclude pairing would actually reach.
     *
     * Built on the same select the queue is, so the number on the form and the
     * number that gets mailed cannot drift apart - which is the only reason
     * the estimate is worth showing at all.
     *
     * @param  int[] $includedIds
     * @param  int[] $excludedIds
     * @return int
     */
    public static function countRecipients(array $includedIds, array $excludedIds, bool $reindex = true): int
    {
        if (!$includedIds) {
            return 0;
        }

        if ($reindex) {
            self::reindexSegments(array_merge($includedIds, $excludedIds));
        }

        $adapter = Mage::getSingleton('core/resource')->getConnection('core_read');

        $select = self::recipientSelect($includedIds, $excludedIds, ['subscriber_id']);

        return (int) $adapter->fetchOne(
            $adapter->select()->from(['r' => $select], [new Zend_Db_Expr('COUNT(*)')])
        );
    }

    /**
     * Everyone in any included segment, less everyone in any excluded one,
     * less anybody the newsletter says may not be mailed.
     *
     * @param  int[] $includedIds
     * @param  int[] $excludedIds
     * @param  array $columns
     * @return Varien_Db_Select
     */
    public static function recipientSelect(array $includedIds, array $excludedIds, array $columns): Varien_Db_Select
    {
        if (!$includedIds) {
            Mage::throwException('Campaign has no included segments, so there is nobody to send to.');
        }

        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_read');

        $membershipTable = $resource->getTableName('ysrtech_emailcampaigns/segment_subscriber');
        $subscriberTable = $resource->getTableName('newsletter/subscriber');
        $suppressed      = $resource->getTableName('ysrtech_emailcampaigns/suppression');

        /*
         * DISTINCT because the segments may overlap: somebody in both "past
         * customers" and "spent over $100" is one recipient, not two. The
         * queue's unique key would collapse them anyway, but counting them
         * twice would misreport the size of the send.
         */
        $select = $adapter->select()
            ->distinct()
            ->from(['ns' => $subscriberTable], $columns)
            ->join(['m' => $membershipTable], 'm.subscriber_id = ns.subscriber_id', [])
            ->where('m.segment_id IN (?)', $includedIds)
            /*
             * Magento's own newsletter status is the authority on who may be
             * mailed. Reading membership without it would send to the people
             * who unsubscribed through the storefront - the module used to
             * check only its own table, and missed every one of them.
             */
            ->where('ns.subscriber_status = ?', Mage_Newsletter_Model_Subscriber::STATUS_SUBSCRIBED)
            ->where(
                "NOT EXISTS (
                    SELECT 1 FROM {$suppressed} p
                    WHERE p.email = ns.subscriber_email
                )"
            );

        if ($excludedIds) {
            // Held back whichever included segment also holds them
            $select->where(
                'NOT EXISTS (' . $adapter->select()
                    ->from(['x' => $membershipTable], [new Zend_Db_Expr('1')])
                    ->where('x.subscriber_id = ns.subscriber_id')
                    ->where('x.segment_id IN (?)', $excludedIds)
                . ')'
            );
        }

        return $select;
    }

    /**
     * Populate the send queue from the campaign's segments.
     *
     * Everyone in any included segment, minus everyone in any excluded one,
     * minus anybody the newsletter says may not be mailed.
     */
    public function buildQueue(): int
    {
        if (!$this->getId() || $this->getStatus() !== self::STATUS_SCHEDULED) {
            Mage::throwException('Campaign must be scheduled before building its queue.');
        }

        /** @var Mage_Core_Model_Resource $resource */
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_write');

        $queueTable = $resource->getTableName('ysrtech_emailcampaigns/queue');

        /*
         * Refreshed here as well as behind the form's estimate. A campaign is
         * usually queued by cron some time after it was set up, and sending to
         * the membership as it stood when somebody clicked Schedule would mail
         * everyone who unsubscribed in between.
         */
        self::reindexSegments(array_merge($this->getIncludedSegmentIds(), $this->getExcludedSegmentIds()));

        $select = self::recipientSelect(
            $this->getIncludedSegmentIds(),
            $this->getExcludedSegmentIds(),
            ['subscriber_id', 'customer_id', 'email' => 'subscriber_email']
        );

        $campaignId = (int) $this->getId();
        $inserted   = 0;
        $batch      = [];

        $statement = $adapter->query($select);

        while ($row = $statement->fetch()) {
            $batch[] = [
                'campaign_id'    => $campaignId,
                'subscriber_id'  => (int) $row['subscriber_id'],
                'customer_id'    => $row['customer_id'] ? (int) $row['customer_id'] : null,
                'email'          => $row['email'],
                'status'         => 'pending',
                'tracking_token' => Mage::helper('core')->getRandomString(32),
            ];

            // Written in blocks rather than a statement per recipient: at this
            // store's size that is 12,000 round trips reduced to a couple of
            // dozen.
            if (count($batch) >= 500) {
                $inserted += $this->_insertQueueBatch($adapter, $queueTable, $batch);
                $batch = [];
            }
        }

        if ($batch) {
            $inserted += $this->_insertQueueBatch($adapter, $queueTable, $batch);
        }

        return $inserted;
    }

    /**
     * @param  Varien_Db_Adapter_Interface $adapter
     * @param  string $table
     * @param  array  $rows
     * @return int
     */
    protected function _insertQueueBatch($adapter, $table, array $rows): int
    {
        /*
         * insertOnDuplicate against the unique key on (campaign_id, email):
         * queueing a campaign twice - which happens whenever the scheduler
         * sees one whose status has not moved on yet - must not put every
         * recipient in the queue a second time.
         */
        $adapter->insertOnDuplicate($table, $rows, ['status', 'subscriber_id']);

        return count($rows);
    }
}
