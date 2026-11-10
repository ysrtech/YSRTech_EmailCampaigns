<?php
/**
 * Twilio SendGrid v3.
 *
 * Suppressions are five separate collections: bounces, spam reports, global
 * unsubscribes, invalid emails and unsubscribe-group opt-outs. Blocks are
 * temporary rejections and are left behind.
 *
 * Contacts can only be listed in bulk through an export job, so that stage
 * takes several calls to fetch() before any contact comes out: start the
 * export, wait for it, download its files, then read them a page at a time.
 * SendGrid contacts carry no status of their own; who may not be mailed is
 * what the suppression stage brought across.
 */
class YSRTech_EmailCampaigns_Model_Import_Provider_Sendgrid
    extends YSRTech_EmailCampaigns_Model_Import_Provider_Abstract
{
    /** Suppression collections, in order => our reason. "asm" is the group opt-outs. */
    public const KINDS = [
        'bounces'        => 'bounce',
        'spam_reports'   => 'complaint',
        'unsubscribes'   => 'unsubscribe',
        'invalid_emails' => 'bounce',
        'asm'            => 'unsubscribe',
    ];

    /** Suppressions per request; SendGrid's maximum */
    public const SUPPRESSION_PAGE = 500;

    /** Group opt-outs come back in one piece and are handed out this many at a time */
    public const GROUP_PAGE = 1000;

    /** Contacts read from a downloaded export file per page */
    public const ROWS = 1000;

    /** Templates per page: each one costs a second request for its HTML */
    public const TEMPLATE_PAGE = 25;

    /** Seconds before asking again whether the export is ready */
    public const EXPORT_WAIT = 30;

    /** Export columns that are not custom fields */
    public const CONTACT_COLUMNS = [
        'email', 'first_name', 'last_name', 'list_ids', 'list_id', 'segment_ids',
        'contact_id', 'id', 'created_at', 'updated_at',
    ];

    public function getName(): string
    {
        return 'SendGrid';
    }

    public function getCredentialFields(): array
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        return [
            'region' => [
                'label' => $h->__('Region'),
                'note'  => $h->__('"eu" for an account on SendGrid\'s EU data residency; leave empty or "us" otherwise.'),
            ],
        ];
    }

    public function getKeyHelp(): string
    {
        return Mage::helper('ysrtech_emailcampaigns')->__(
            'In SendGrid: Settings → API Keys → Create API Key → Restricted Access, with read access to Suppressions, Marketing and Template Engine.'
        );
    }

    protected function _host(): string
    {
        $region = strtolower(trim((string) ($this->_credentials['region'] ?? '')));

        return $region === 'eu' ? 'api.eu.sendgrid.com' : 'api.sendgrid.com';
    }

    protected function _authHeaders(): array
    {
        return ['Authorization: Bearer ' . $this->_key()];
    }

    public function account(): array
    {
        $r    = $this->_get('/v3/user/profile');
        $name = trim((string) ($r['company'] ?? ''));

        if ($name === '') {
            $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        }

        return ['name' => $name !== '' ? $name : 'SendGrid account'];
    }

    public function discover(): array
    {
        $h     = Mage::helper('ysrtech_emailcampaigns');
        $count = null;
        $notes = [
            $h->__('Bounces, spam reports, global unsubscribes, invalid emails and unsubscribe-group opt-outs are imported first. Group opt-outs become full unsubscribes, to be safe.'),
            $h->__('Blocks are temporary rejections and are not imported.'),
            $h->__('Contacts come through a SendGrid export, which can take a few minutes to prepare.'),
        ];

        try {
            $count = (int) ($this->_get('/v3/marketing/contacts/count')['contact_count'] ?? 0);
        } catch (YSRTech_EmailCampaigns_Model_Import_Provider_Exception $e) {
            if ($e->isRetryable()) {
                throw $e;
            }

            // A key without Marketing access still brings suppressions and templates
            $notes[] = $h->__('This key cannot read Marketing contacts, so no contacts will be imported.');
        }

        $templates = $this->_get('/v3/templates', ['generations' => 'dynamic', 'page_size' => 200]);

        return [
            'contacts'     => $count,
            'suppressions' => null,
            // More than one page of templates: the total is not known without reading them all
            'templates'    => empty($templates['_metadata']['next']) ? count((array) ($templates['result'] ?? [])) : null,
            'notes'        => $notes,
        ];
    }

    public function stages(): array
    {
        return [self::STAGE_SUPPRESSIONS, self::STAGE_CONTACTS, self::STAGE_TEMPLATES];
    }

    public function fetch(string $stage, ?array $cursor, array $options = []): array
    {
        $cursor = $cursor ?? [];

        switch ($stage) {
            case self::STAGE_SUPPRESSIONS:
                return $this->_suppressionPage($cursor);
            case self::STAGE_CONTACTS:
                return $this->_contactPage($cursor);
            case self::STAGE_TEMPLATES:
                return $this->_templatePage($cursor);
        }

        return ['items' => [], 'cursor' => null];
    }

    /**
     * @param array $c k = index into KINDS, o = offset
     */
    protected function _suppressionPage(array $c): array
    {
        $kinds = array_keys(static::KINDS);
        $k     = (int) ($c['k'] ?? 0);
        $o     = (int) ($c['o'] ?? 0);

        if (!isset($kinds[$k])) {
            return ['items' => [], 'cursor' => null];
        }

        $kind  = $kinds[$k];
        $items = [];

        if ($kind === 'asm') {
            /*
             * This endpoint has no paging: it answers with every group
             * opt-out at once. It is asked again for each page and sliced, so
             * that no single page handed to the importer is unbounded.
             */
            $all = $this->_get('/v3/asm/suppressions');

            foreach (array_slice($all, $o, static::GROUP_PAGE) as $row) {
                $items[] = [
                    'email'  => (string) ($row['email'] ?? ''),
                    'reason' => static::KINDS[$kind],
                    'detail' => 'group: ' . ($row['group_name'] ?? ''),
                ];
            }

            $more = $o + static::GROUP_PAGE < count($all);
            $size = static::GROUP_PAGE;
        } else {
            $rows = $this->_get('/v3/suppression/' . $kind, ['limit' => static::SUPPRESSION_PAGE, 'offset' => $o]);

            foreach ($rows as $row) {
                $items[] = [
                    'email'  => (string) ($row['email'] ?? ''),
                    'reason' => static::KINDS[$kind],
                    'detail' => $kind,
                ];
            }

            $more = count($rows) >= static::SUPPRESSION_PAGE;
            $size = static::SUPPRESSION_PAGE;
        }

        if ($more) {
            return ['items' => $items, 'cursor' => ['k' => $k, 'o' => $o + $size]];
        }

        return ['items' => $items, 'cursor' => isset($kinds[$k + 1]) ? ['k' => $k + 1] : null];
    }

    /**
     * The export, one step per call.
     *
     * @param array $c export = job id, n = files downloaded, f = file being read, line = lines of it already read
     */
    protected function _contactPage(array $c): array
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        if (empty($c['export'])) {
            $id = (string) ($this->_post('/v3/marketing/contacts/exports', ['file_type' => 'json'])['id'] ?? '');

            if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
                throw new YSRTech_EmailCampaigns_Model_Import_Provider_Exception(
                    $h->__('SendGrid did not start the contact export.')
                );
            }

            return ['items' => [], 'cursor' => ['export' => $id]];
        }

        $id = (string) $c['export'];

        // The id becomes part of a file name, so it is held to what SendGrid issues
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            throw new YSRTech_EmailCampaigns_Model_Import_Provider_Exception(
                $h->__('SendGrid did not start the contact export.')
            );
        }

        if (!isset($c['n'])) {
            $ready = $this->_downloadExport($id);

            return [
                'items'  => [],
                'cursor' => ['export' => $id, 'n' => $ready['files'], 'f' => 0, 'line' => 0],
                'total'  => $ready['total'],
            ];
        }

        $n    = (int) $c['n'];
        $f    = (int) ($c['f'] ?? 0);
        $line = (int) ($c['line'] ?? 0);

        if ($f >= $n) {
            // Every file is read: the addresses do not stay on disk
            for ($i = 0; $i < $n; $i++) {
                @unlink($this->_exportFile($id, $i));
            }

            return ['items' => [], 'cursor' => null];
        }

        /*
         * The files are on this server's disk. If they are gone - var/ was
         * cleared, or another web node picked the import up - they are
         * fetched again, and the position in the cursor still holds.
         */
        if (!is_file($this->_exportFile($id, $f))) {
            $this->_downloadExport($id);
        }

        $file = new SplFileObject($this->_exportFile($id, $f));
        $file->seek($line);

        $items = [];
        $read  = 0;

        while (!$file->eof() && $read < static::ROWS) {
            $text = trim((string) $file->current());
            $file->next();
            $read++;

            $row = $text !== '' ? json_decode($text, true) : null;

            if (is_array($row)) {
                $items[] = $this->_contactRow($row);
            }
        }

        $next = $file->eof()
            ? ['export' => $id, 'n' => $n, 'f' => $f + 1, 'line' => 0]
            : ['export' => $id, 'n' => $n, 'f' => $f, 'line' => $line + $read];

        return ['items' => $items, 'cursor' => $next];
    }

    /**
     * Ask whether the export is ready and, when it is, bring its files down.
     *
     * @return array{files:int, total:int|null}
     * @throws YSRTech_EmailCampaigns_Model_Import_Provider_Exception Retryable while SendGrid is still preparing it
     */
    protected function _downloadExport(string $id): array
    {
        $h      = Mage::helper('ysrtech_emailcampaigns');
        $status = $this->_get('/v3/marketing/contacts/exports/' . $id);

        if (($status['status'] ?? '') === 'failure') {
            throw new YSRTech_EmailCampaigns_Model_Import_Provider_Exception($h->__(
                'SendGrid could not export the contacts: %s',
                mb_substr((string) ($status['message'] ?? 'unknown error'), 0, 200)
            ));
        }

        if (($status['status'] ?? '') !== 'ready') {
            throw new YSRTech_EmailCampaigns_Model_Import_Provider_Exception(
                $h->__('SendGrid is still preparing the contact export.'),
                true,
                static::EXPORT_WAIT
            );
        }

        $urls = array_values((array) ($status['urls'] ?? []));

        foreach ($urls as $i => $url) {
            $this->_downloadFile((string) $url, $this->_exportFile($id, $i));
        }

        return [
            'files' => count($urls),
            'total' => isset($status['contact_count']) ? (int) $status['contact_count'] : null,
        ];
    }

    /**
     * Download one export file and store it as one JSON object per line,
     * whatever SendGrid sent: gzip or plain, JSON lines, a JSON array or CSV.
     *
     * The link is presigned and lives on a storage host, not on SendGrid's
     * API. That is why this goes to _send() directly instead of through
     * _call(), which would refuse the host - and why no header of ours,
     * the key least of all, goes with it.
     */
    protected function _downloadFile(string $url, string $path): void
    {
        if (stripos($url, 'https://') !== 0) {
            throw new YSRTech_EmailCampaigns_Model_Import_Provider_Exception(
                Mage::helper('ysrtech_emailcampaigns')->__('SendGrid returned an unexpected export link.')
            );
        }

        $raw = $this->_check($this->_send('GET', $url, [], null))['body'];

        if (strncmp($raw, "\x1f\x8b", 2) === 0) {
            $raw = (string) gzdecode($raw);
        }

        $raw  = ltrim($raw, "\xEF\xBB\xBF \n\r\t");
        $rows = [];

        if (strncmp($raw, '[', 1) === 0) {
            $rows = array_filter((array) json_decode($raw, true), 'is_array');
        } elseif (strncmp($raw, '{', 1) === 0) {
            foreach (preg_split('/\r?\n/', $raw) ?: [] as $text) {
                $row = trim($text) !== '' ? json_decode($text, true) : null;

                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        } else {
            $lines = preg_split('/\r?\n/', $raw) ?: [];
            $head  = str_getcsv((string) array_shift($lines), ',', '"', '');

            foreach ($lines as $text) {
                if (trim($text) !== '') {
                    // Pad short rows and trim long ones so the columns line up with the header
                    $cells  = array_slice(array_pad(str_getcsv($text, ',', '"', ''), count($head), ''), 0, count($head));
                    $rows[] = array_combine($head, $cells);
                }
            }
        }

        $out = [];

        foreach ($rows as $row) {
            $out[] = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0770, true);
        }

        if (file_put_contents($path, implode("\n", $out)) === false) {
            throw new YSRTech_EmailCampaigns_Model_Import_Provider_Exception(
                Mage::helper('ysrtech_emailcampaigns')->__('The SendGrid contact export could not be saved under var/.')
            );
        }

        // Every address in the account is in this file
        @chmod($path, 0600);
    }

    /** Where export files wait between pages. var/ is not served to the web. */
    protected function _exportDir(): string
    {
        return Mage::getBaseDir('var') . DS . 'ysrtech_emailcampaigns' . DS . 'import';
    }

    protected function _exportFile(string $id, int $i): string
    {
        return $this->_exportDir() . DS . 'sendgrid-' . $id . '-' . $i . '.jsonl';
    }

    protected function _contactRow(array $row): array
    {
        $pick = static function (array $keys) use ($row) {
            foreach ($keys as $key) {
                if (isset($row[$key]) && $row[$key] !== '') {
                    return $row[$key];
                }
            }

            return null;
        };

        $fields = (array) ($row['custom_fields'] ?? []);

        // A CSV export flattens custom fields into columns of their own
        foreach ($row as $key => $value) {
            if (is_scalar($value)
                && !in_array(strtolower((string) $key), static::CONTACT_COLUMNS, true)
                && !isset($fields[$key])
            ) {
                $fields[$key] = $value;
            }
        }

        return [
            'email'      => (string) ($pick(['email', 'EMAIL', 'Email']) ?? ''),
            'first_name' => $pick(['first_name', 'FIRST_NAME']),
            'last_name'  => $pick(['last_name', 'LAST_NAME']),
            'fields'     => $fields,
            'tags'       => [],
            // No status in SendGrid: the suppression stage already took care of the opted-out
            'status'     => 'subscribed',
        ];
    }

    /**
     * @param array $c t = SendGrid's page token
     */
    protected function _templatePage(array $c): array
    {
        $query = ['generations' => 'dynamic', 'page_size' => static::TEMPLATE_PAGE];

        if (isset($c['t'])) {
            $query['page_token'] = (string) $c['t'];
        }

        $r     = $this->_get('/v3/templates', $query);
        $items = [];

        foreach ((array) ($r['result'] ?? []) as $template) {
            $full    = $this->_get('/v3/templates/' . rawurlencode((string) $template['id']));
            $version = null;

            // The active version is the one being sent; failing that, the first there is
            foreach ((array) ($full['versions'] ?? []) as $candidate) {
                if ($version === null || !empty($candidate['active'])) {
                    $version = $candidate;
                }

                if (!empty($candidate['active'])) {
                    break;
                }
            }

            if ($version && !empty($version['html_content'])) {
                $items[] = [
                    'name'    => (string) ($template['name'] ?? 'SendGrid template'),
                    'subject' => (string) ($version['subject'] ?? ''),
                    'html'    => (string) $version['html_content'],
                ];
            }
        }

        /*
         * SendGrid gives the next page as a whole URL. Only its token is
         * taken, so the request is still built here, for our own host.
         */
        $next = (string) ($r['_metadata']['next'] ?? '');

        if ($next !== '' && preg_match('/[?&]page_token=([^&]+)/', $next, $m)) {
            return ['items' => $items, 'cursor' => ['t' => urldecode($m[1])]];
        }

        return ['items' => $items, 'cursor' => null];
    }

    /**
     * Handlebars: {{first_name}}, {{insert first_name "default=there"}},
     * {{Sender_Name}}, {{{unsubscribe}}} and the <%asm_..._raw_url%> tags.
     * Custom fields, helpers and the preferences link stay as they are.
     */
    public function convertMergeTags(string $html): string
    {
        $map = ['first_name' => 'customer.firstname', 'last_name' => 'customer.lastname', 'email' => 'customer.email'];

        $html = str_replace(
            ['<%asm_group_unsubscribe_raw_url%>', '<%asm_global_unsubscribe_raw_url%>'],
            '{{unsubscribe_url}}',
            $html
        );

        $html = (string) preg_replace_callback(
            '/\{\{\{?\s*insert\s+(first_name|last_name|email)\s+(["\'])default=([^"\'}]*)\2\s*\}\}\}?/',
            static function (array $m) use ($map) {
                $fallback = trim($m[3]);

                return '{{' . $map[$m[1]] . ($fallback !== '' ? '|' . $fallback : '') . '}}';
            },
            $html
        );

        $map += ['unsubscribe' => 'unsubscribe_url', 'Sender_Name' => 'store.name'];

        return (string) preg_replace_callback(
            '/(\{\{\{?)\s*(first_name|last_name|email|unsubscribe|Sender_Name)\s*(\}\}\}?)/',
            static function (array $m) use ($map) {
                // {{{x}} is not a tag; leave whatever it is alone
                return strlen($m[1]) === strlen($m[3]) ? '{{' . $map[$m[2]] . '}}' : $m[0];
            },
            $html
        );
    }
}
