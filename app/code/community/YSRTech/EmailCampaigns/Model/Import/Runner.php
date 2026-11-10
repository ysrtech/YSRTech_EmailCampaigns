<?php
/**
 * Moves imports forward a batch at a time.
 *
 * Called from cron every minute, and from the "Process a batch now" button
 * for a store whose cron is slow or not running. Either way it works for a
 * bounded number of seconds and stops; the import row remembers where it was.
 */
class YSRTech_EmailCampaigns_Model_Import_Runner
{
    /** Seconds one run may keep going before it leaves the rest to the next */
    public const TIME_BUDGET = 40;

    /** Provider pages are small, so several are done per run */
    private const LOCK_NAME = 'ysrtech_emailcampaigns_import';

    /**
     * @param  int|null $onlyId Run this import alone
     * @return int How many batches were processed
     */
    public function run(int $budget = self::TIME_BUDGET, ?int $onlyId = null): int
    {
        $adapter = Mage::getSingleton('core/resource')->getConnection('core_write');

        /*
         * One runner at a time. Two would read the same offset or the same
         * cursor and import the same rows twice; a named lock is released by
         * the server if this process dies, which a flag in a table is not.
         */
        if (!$adapter->fetchOne('SELECT GET_LOCK(?, 0)', [self::LOCK_NAME])) {
            return 0;
        }

        $deadline = microtime(true) + $budget;
        $batches  = 0;

        try {
            while (microtime(true) < $deadline) {
                $collection = Mage::getResourceModel('ysrtech_emailcampaigns/import_collection')
                    ->addFieldToFilter('status', ['in' => [
                        YSRTech_EmailCampaigns_Model_Import::STATUS_PENDING,
                        YSRTech_EmailCampaigns_Model_Import::STATUS_PROCESSING,
                    ]])
                    ->setOrder('import_id', 'ASC')
                    ->setPageSize(1);

                $collection->getSelect()->where('retry_at IS NULL OR retry_at <= ?', Varien_Date::now());

                if ($onlyId !== null) {
                    $collection->addFieldToFilter('import_id', $onlyId);
                }

                /** @var YSRTech_EmailCampaigns_Model_Import $import */
                $import = $collection->getFirstItem();

                if (!$import->getId()) {
                    break;
                }

                $this->_step($import);
                $batches++;
            }
        } finally {
            $adapter->query('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
        }

        return $batches;
    }

    /**
     * One batch of one import.
     */
    protected function _step(YSRTech_EmailCampaigns_Model_Import $import): void
    {
        $I = YSRTech_EmailCampaigns_Model_Import::class;

        $import->addData(['status' => $I::STATUS_PROCESSING, 'retry_at' => null, 'error' => null]);

        try {
            $done = $import->getType() === $I::TYPE_CSV
                ? Mage::getModel('ysrtech_emailcampaigns/import_csv')->processChunk($import)
                : $this->_providerPage($import);

            if ($done) {
                $import->finish($I::STATUS_DONE);
            } else {
                $import->save();
            }
        } catch (YSRTech_EmailCampaigns_Model_Import_Provider_Exception $e) {
            if ($e->isRetryable()) {
                // Not a failure: wait, and say what is being waited on
                $import->addData([
                    'retry_at' => gmdate('Y-m-d H:i:s', time() + max(10, $e->getRetryAfter())),
                    'error'    => $e->getMessage(),
                ])->save();
            } else {
                $import->finish($I::STATUS_FAILED, $e->getMessage());
            }
        } catch (Throwable $e) {
            Mage::logException($e);
            $import->finish($I::STATUS_FAILED, Mage::helper('ysrtech_emailcampaigns')->__(
                'Something went wrong while importing: %s What was imported so far has been kept.',
                $e->getMessage()
            ));
        }
    }

    /**
     * One page of a provider migration's current stage.
     *
     * @return bool True when every chosen stage is finished
     */
    protected function _providerPage(YSRTech_EmailCampaigns_Model_Import $import): bool
    {
        $P        = YSRTech_EmailCampaigns_Model_Import_Provider_Interface::class;
        $client   = $this->_client($import);
        $options  = $import->getOptions();
        $chosen   = (array) ($options['stages'] ?? []);
        // The provider's order, never the form's: suppressions come first
        $stages   = array_values(array_filter($client->stages(), static fn ($s) => in_array($s, $chosen, true)));
        $stage    = (string) $import->getStage();
        $stats    = $import->getStats() + array_fill_keys(YSRTech_EmailCampaigns_Model_Import::CONTACT_STATS, 0)
            + ['suppressions_added' => 0, 'suppressions_already' => 0, 'templates_created' => 0,
                'templates_skipped' => 0, 'templates_leftover_tags' => 0];

        if ($stage === '' || !in_array($stage, $stages, true)) {
            $stage = $stages[0] ?? '';

            if ($stage === '') {
                return true;
            }

            $import->setStage($stage)->setCursor(null);
        }

        $page = $client->fetch($stage, $import->getCursor(), $options);

        switch ($stage) {
            case $P::STAGE_SUPPRESSIONS:
                $this->_saveSuppressions($page['items'], $stats);
                break;
            case $P::STAGE_CONTACTS:
                $this->_saveContacts($import, $page['items'], $stats, $options);
                break;
            case $P::STAGE_TEMPLATES:
                $this->_saveTemplates($import, $client, $page['items'], $stats);
                break;
        }

        $import->setStats($stats)->setProcessedRows((int) $import->getProcessedRows() + count($page['items']));

        if ($page['cursor'] !== null) {
            $import->setCursor($page['cursor']);

            return false;
        }

        $next = $stages[array_search($stage, $stages, true) + 1] ?? null;

        if ($next === null) {
            return true;
        }

        $import->setStage($next)->setCursor(null);

        return false;
    }

    /**
     * The provider client for an import. Its own method so that a test can
     * hand back one that talks to nobody.
     *
     * @return YSRTech_EmailCampaigns_Model_Import_Provider_Interface
     */
    protected function _client(YSRTech_EmailCampaigns_Model_Import $import)
    {
        return $import->getProviderClient();
    }

    protected function _saveSuppressions(array $items, array &$stats): void
    {
        $byReason = [];

        foreach ($items as $item) {
            $email = strtolower(trim((string) ($item['email'] ?? '')));

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $reason = [
                'bounce'    => YSRTech_EmailCampaigns_Model_Suppression::REASON_HARD_BOUNCE,
                'complaint' => YSRTech_EmailCampaigns_Model_Suppression::REASON_COMPLAINT,
            ][(string) ($item['reason'] ?? '')] ?? YSRTech_EmailCampaigns_Model_Suppression::REASON_UNSUBSCRIBE;

            $byReason[$reason][$email] = (string) ($item['detail'] ?? '');
        }

        // Strongest first, so an address on two of the provider's lists
        // lands with the reason that matters most
        uksort($byReason, static fn ($a, $b) => YSRTech_EmailCampaigns_Model_Suppression::rank($b)
            <=> YSRTech_EmailCampaigns_Model_Suppression::rank($a));

        foreach ($byReason as $reason => $addresses) {
            $result = Mage::getResourceSingleton('ysrtech_emailcampaigns/suppression')
                ->addMany($addresses, $reason, 'import');

            $stats['suppressions_added']   += $result['added'];
            $stats['suppressions_already'] += $result['already'];
        }
    }

    protected function _saveContacts($import, array $items, array &$stats, array $options): void
    {
        /** @var YSRTech_EmailCampaigns_Model_Import_Addresscheck $check */
        $check  = Mage::getSingleton('ysrtech_emailcampaigns/import_addresscheck');
        $people = [];

        foreach ($items as $item) {
            $email   = strtolower(trim((string) ($item['email'] ?? '')));
            // The provider was mailing these, so its domains are not looked
            // up again; the shape of the address still is.
            $problem = $check->problem($email, !empty($options['include_roles']), false);

            if ($problem) {
                $stats[$problem[0]]++;
                continue;
            }

            $people[$email] = [
                'first_name' => $item['first_name'] ?? null,
                'last_name'  => $item['last_name'] ?? null,
                'status'     => (string) ($item['status'] ?? 'subscribed'),
                'dup'        => isset($people[$email]),
                // Lists, tags and custom fields have nowhere to go here yet;
                // counted, so the result says so instead of implying they came
                'extras'     => !empty($item['fields']) || !empty($item['tags']),
            ];
        }

        Mage::getSingleton('ysrtech_emailcampaigns/import_contacts')->save($import, $people, $stats, $options);
    }

    protected function _saveTemplates($import, $client, array $items, array &$stats): void
    {
        foreach ($items as $item) {
            $name = mb_substr(trim((string) ($item['name'] ?? '')) ?: 'Untitled', 0, 200);
            $name = sprintf('%s (%s)', $name, $client->getName());

            // By name, so that a page fetched twice after a retry does not
            // leave two of everything
            $existing = Mage::getModel('ysrtech_emailcampaigns/template')->load($name, 'name');

            if ($existing->getId()) {
                $stats['templates_skipped']++;
                continue;
            }

            Mage::getModel('ysrtech_emailcampaigns/template')->setData([
                'name'      => $name,
                'subject'   => $client->convertMergeTags((string) ($item['subject'] ?? '')),
                'html'      => $client->convertMergeTags((string) ($item['html'] ?? '')),
                'is_active' => 1,
            ])->save();

            $stats['templates_created']++;

            /*
             * A placeholder the provider had and this module does not is left
             * in the template as it was written - and then sent as nothing,
             * because an unknown {{ tag }} renders empty. Counted, so the
             * result says to go and look before one of these is sent.
             */
            $html = $client->convertMergeTags((string) ($item['html'] ?? '') . ' ' . (string) ($item['subject'] ?? ''));

            if (preg_match('/{{\s*(?!customer\.|store\.|order\.|cart\.|unsubscribe)[^}]+}}|\*\|[A-Z_:]+\|\*|{%[^%]+%}/', $html)) {
                $stats['templates_leftover_tags']++;
            }
        }
    }
}
