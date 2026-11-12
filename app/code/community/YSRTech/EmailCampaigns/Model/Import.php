<?php
/**
 * One CSV import or provider migration, and how far it has got.
 */
class YSRTech_EmailCampaigns_Model_Import extends Mage_Core_Model_Abstract
{
    public const TYPE_CSV      = 'csv';
    public const TYPE_PROVIDER = 'provider';

    /** Uploaded or connected, not yet told to start */
    public const STATUS_DRAFT      = 'draft';
    /** Started, waiting for cron to pick it up */
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE       = 'done';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_CANCELLED  = 'cancelled';

    /** What a CSV column can be mapped to */
    public const FIELD_SKIP         = 'skip';
    public const FIELD_EMAIL        = 'email';
    public const FIELD_FIRST_NAME   = 'first_name';
    public const FIELD_LAST_NAME    = 'last_name';
    public const FIELD_FULL_NAME    = 'full_name';
    public const FIELD_UNSUBSCRIBED = 'unsubscribed';

    /** Counted for every import that brings contacts, in the order shown */
    public const CONTACT_STATS = [
        'created', 'updated', 'unchanged', 'duplicates', 'invalid', 'typo', 'role',
        'no_mail_server', 'status_skipped', 'not_found_skipped', 'suppressed', 'marked_unsubscribed', 'extras_skipped',
    ];

    protected function _construct()
    {
        $this->_init('ysrtech_emailcampaigns/import');
    }

    protected function _beforeSave()
    {
        parent::_beforeSave();

        if (!$this->getCreatedAt()) {
            $this->setCreatedAt(Varien_Date::now());
        }

        $this->setUpdatedAt(Varien_Date::now());

        return $this;
    }

    public static function statuses(): array
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        return [
            self::STATUS_DRAFT      => $h->__('Not started'),
            self::STATUS_PENDING    => $h->__('Waiting'),
            self::STATUS_PROCESSING => $h->__('Importing'),
            self::STATUS_DONE       => $h->__('Done'),
            self::STATUS_FAILED     => $h->__('Failed'),
            self::STATUS_CANCELLED  => $h->__('Cancelled'),
        ];
    }

    /**
     * @return array<string, string> stat => what it means, in the order shown
     */
    public static function statLabels(): array
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        return [
            'suppressions_added'   => $h->__('Suppressions added'),
            'suppressions_already' => $h->__('Suppressions already on the list'),
            'created'              => $h->__('Subscribers created'),
            'updated'              => $h->__('Subscribers updated'),
            'unchanged'            => $h->__('Already subscribers, left as they were'),
            'duplicates'           => $h->__('Duplicates within the import'),
            'suppressed'           => $h->__('On the suppression list: imported as unsubscribed, never mailed'),
            'marked_unsubscribed'  => $h->__('Marked unsubscribed in the source: added to suppressions'),
            'invalid'              => $h->__('Skipped: not a valid address'),
            'typo'                 => $h->__('Skipped: looks like a typo'),
            'role'                 => $h->__('Skipped: role address (a shared inbox)'),
            'no_mail_server'       => $h->__('Skipped: domain cannot receive email'),
            'status_skipped'       => $h->__('Skipped: their status in the file was one you chose not to import'),
            'not_found_skipped'    => $h->__('Skipped: marked "unsubscribe if found", and not a subscriber here'),
            'extras_skipped'       => $h->__('Contacts whose extra fields or tags were not carried over'),
            'templates_created'    => $h->__('Templates created'),
            'templates_skipped'    => $h->__('Templates already here by that name'),
            'templates_leftover_tags' => $h->__('Templates with placeholders that have no equivalent here: edit them before sending, or those words go out blank'),
        ];
    }

    public function isFinished(): bool
    {
        return in_array($this->getStatus(), [self::STATUS_DONE, self::STATUS_FAILED, self::STATUS_CANCELLED], true);
    }

    public function isRunning(): bool
    {
        return in_array($this->getStatus(), [self::STATUS_PENDING, self::STATUS_PROCESSING], true);
    }

    public function getStats(): array
    {
        return $this->_json('stats_json');
    }

    public function setStats(array $stats): self
    {
        return $this->setData('stats_json', json_encode($stats));
    }

    public function getOptions(): array
    {
        return $this->_json('options_json');
    }

    public function setOptions(array $options): self
    {
        return $this->setData('options_json', json_encode($options));
    }

    /** @return array<int, string> column index => field */
    public function getMapping(): array
    {
        return $this->_json('mapping_json');
    }

    public function setMapping(array $mapping): self
    {
        return $this->setData('mapping_json', json_encode($mapping));
    }

    public function getCursor(): ?array
    {
        $json = (string) $this->getData('cursor_json');

        if ($json === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function setCursor(?array $cursor): self
    {
        return $this->setData('cursor_json', $cursor === null ? null : json_encode($cursor));
    }

    /**
     * Provider credentials, kept encrypted with the store's own key and only
     * while the migration needs them.
     */
    public function setCredentials(array $credentials): self
    {
        return $this->setData('credentials_enc', Mage::helper('core')->encrypt(json_encode($credentials)));
    }

    public function getCredentials(): array
    {
        $stored = (string) $this->getData('credentials_enc');

        if ($stored === '') {
            return [];
        }

        $decoded = json_decode((string) Mage::helper('core')->decrypt($stored), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Mark the import over, and drop what only a running import needs: the
     * provider's key, and the list of addresses it had seen.
     */
    public function finish(string $status, string $error = ''): self
    {
        $this->addData([
            'status'          => $status,
            'error'           => $error !== '' ? mb_substr($error, 0, 1000) : null,
            'credentials_enc' => null,
            'retry_at'        => null,
            'finished_at'     => Varien_Date::now(),
        ])->save();

        // The uploaded file is a list of people. Once it has been read, or
        // will not be, there is no reason to keep a copy of it on the server.
        if ($this->getType() === self::TYPE_CSV && $this->getFilePath() && is_file($this->getFile())) {
            @unlink($this->getFile());
        }

        // A SendGrid migration keeps the contact export it downloaded on
        // disk between batches; one that stops early leaves it behind.
        if ($this->getProvider() === 'sendgrid') {
            foreach (glob(self::dir() . DS . 'sendgrid-*.jsonl') ?: [] as $file) {
                @unlink($file);
            }
        }

        $resource = Mage::getSingleton('core/resource');
        $resource->getConnection('core_write')->delete(
            $resource->getTableName('ysrtech_emailcampaigns/import_seen'),
            ['import_id = ?' => (int) $this->getId()]
        );

        return $this;
    }

    /**
     * The provider client for a migration, with this import's credentials.
     *
     * @return YSRTech_EmailCampaigns_Model_Import_Provider_Interface
     */
    public function getProviderClient()
    {
        return self::providerClient((string) $this->getProvider())->setCredentials($this->getCredentials());
    }

    /**
     * @return array<string, string> code => name
     */
    public static function providers(): array
    {
        return [
            'mailchimp' => 'Mailchimp',
            'klaviyo'   => 'Klaviyo',
            'brevo'     => 'Brevo',
            'kit'       => 'Kit',
            'sendgrid'  => 'SendGrid',
            'mailgun'   => 'Mailgun',
        ];
    }

    /**
     * @return YSRTech_EmailCampaigns_Model_Import_Provider_Interface
     */
    public static function providerClient(string $code)
    {
        if (!isset(self::providers()[$code])) {
            Mage::throwException(Mage::helper('ysrtech_emailcampaigns')->__('Unknown provider "%s".', $code));
        }

        $client = Mage::getModel('ysrtech_emailcampaigns/import_provider_' . $code);

        if (!$client instanceof YSRTech_EmailCampaigns_Model_Import_Provider_Interface) {
            Mage::throwException(Mage::helper('ysrtech_emailcampaigns')->__('Unknown provider "%s".', $code));
        }

        return $client;
    }

    /** Absolute path of the uploaded file */
    public function getFile(): string
    {
        return Mage::getBaseDir('var') . DS . $this->getFilePath();
    }

    /** Absolute path of the file that collects the rows not imported */
    public function getSkippedFile(): string
    {
        return self::dir() . DS . 'import-' . (int) $this->getId() . '-skipped.csv';
    }

    /** Where uploads and skipped-row files are kept: under var/, outside the web root's reach */
    public static function dir(): string
    {
        $dir = Mage::getBaseDir('var') . DS . 'ysrtech_emailcampaigns' . DS . 'import';

        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }

        return $dir;
    }

    private function _json(string $field): array
    {
        $decoded = json_decode((string) $this->getData($field), true);

        return is_array($decoded) ? $decoded : [];
    }
}
