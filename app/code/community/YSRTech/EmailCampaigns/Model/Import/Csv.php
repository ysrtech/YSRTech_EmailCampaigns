<?php
/**
 * Reads an uploaded CSV a batch of rows at a time.
 *
 * The position kept between batches is a byte offset into the file, so a
 * batch picks up exactly where the last one stopped without rereading what
 * came before - which is what lets a file of any size be imported from a
 * cron job that runs for a few seconds at a time.
 */
class YSRTech_EmailCampaigns_Model_Import_Csv
{
    public const CHUNK = 1000;

    /** @var resource|null */
    private $_handle;

    /** @var string */
    private $_delimiter = ',';

    public function open(string $path): self
    {
        $this->_handle = @fopen($path, 'r');

        if (!$this->_handle) {
            Mage::throwException(Mage::helper('ysrtech_emailcampaigns')->__('The uploaded file is no longer there.'));
        }

        // A byte-order mark, which Excel writes, would otherwise become part
        // of the first column's name
        if (fread($this->_handle, 3) !== "\xEF\xBB\xBF") {
            rewind($this->_handle);
        }

        $start = ftell($this->_handle);
        $first = (string) fgets($this->_handle);
        fseek($this->_handle, $start);

        // Whichever of the usual separators the header line has most of
        $best = 0;

        foreach ([',', ';', "\t", '|'] as $candidate) {
            $count = substr_count($first, $candidate);

            if ($count > $best) {
                $best = $count;
                $this->_delimiter = $candidate;
            }
        }

        return $this;
    }

    /**
     * @return string[]|null The next row, or null at the end of the file
     */
    public function row(): ?array
    {
        while (($row = fgetcsv($this->_handle, 0, $this->_delimiter, '"', '')) !== false) {
            // A blank line comes back as one null cell
            if ($row !== [null]) {
                return array_map(static fn ($cell) => (string) $cell, $row);
            }
        }

        return null;
    }

    public function offset(): int
    {
        return (int) ftell($this->_handle);
    }

    public function seek(int $offset): self
    {
        fseek($this->_handle, $offset);

        return $this;
    }

    public function close(): void
    {
        if ($this->_handle) {
            fclose($this->_handle);
            $this->_handle = null;
        }
    }

    /**
     * The header, a few sample rows and a row count, for the mapping screen.
     *
     * @return array{columns:string[], sample:array<int, string[]>, rows:int}
     */
    public function preview(string $path, int $sampleRows = 4): array
    {
        $this->open($path);
        $columns = $this->row() ?? [];
        $sample  = [];
        $rows    = 0;
        $values  = array_fill(0, count($columns), []);

        while (($row = $this->row()) !== null) {
            if (count($sample) < $sampleRows) {
                $sample[] = $row;
            }

            /*
             * The different values in each column, with how many rows hold
             * each: what the mapping screen asks about when a column is the
             * subscription status. A column with more than a handful is not
             * a status column, and counting it stops there.
             */
            foreach ($values as $index => $seen) {
                if ($seen === null) {
                    continue;
                }

                $value = self::statusKey((string) ($row[$index] ?? ''));

                if (!isset($seen[$value]) && count($seen) >= self::MAX_STATUS_VALUES) {
                    $values[$index] = null;
                } else {
                    $values[$index][$value] = ($seen[$value] ?? 0) + 1;
                }
            }

            $rows++;
        }

        $this->close();

        return ['columns' => $columns, 'sample' => $sample, 'rows' => $rows, 'values' => $values];
    }

    /** A column with more different values than this is not offered as a status column's values */
    public const MAX_STATUS_VALUES = 12;

    /** What a status value in the file can be taken to mean */
    public const MEANS_SUBSCRIBED   = 'subscribed';
    public const MEANS_UNSUBSCRIBED = 'unsubscribed';
    public const MEANS_SKIP         = 'skip';

    /**
     * A cell of a status column as it is compared: trimmed, lower case, so
     * "Subscribed" and "subscribed " are one value.
     */
    public static function statusKey(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /**
     * A first guess at what a status value means, for the mapping screen to
     * start from. The person importing has the last word on every one.
     *
     * @param string $column the column's header: "unsubscribed = yes" and
     *                       "subscribed = yes" mean opposite things
     */
    public function guessMeaning(string $value, string $column): string
    {
        $value  = self::statusKey($value);
        $header = preg_replace('/[^a-z]/', '', strtolower($column));
        $about  = preg_match('/unsub|optout|optedout|donotemail|donotmail|suppress|blocked/', $header) ? 'out' : 'in';

        if (in_array($value, ['1', 'yes', 'y', 'true', 't', 'x', 'on'], true)) {
            return $about === 'out' ? self::MEANS_UNSUBSCRIBED : self::MEANS_SUBSCRIBED;
        }

        if (in_array($value, ['0', 'no', 'n', 'false', 'f', 'off'], true)) {
            return $about === 'out' ? self::MEANS_SUBSCRIBED : self::MEANS_UNSUBSCRIBED;
        }

        // An empty cell in an "unsubscribed" column is somebody who has not
        if ($value === '') {
            return self::MEANS_SUBSCRIBED;
        }

        if (preg_match('/unsub|opt.?out|opted.?out|not.?subscribed|non.?subscribed|removed|inactive|bounce|clean|complain|spam|block|suppress|declin|deleted|archiv|invalid|never|do not|reject/', $value)) {
            return self::MEANS_UNSUBSCRIBED;
        }

        if (preg_match('/pending|unconfirmed|not.?confirmed|awaiting/', $value)) {
            return self::MEANS_SKIP;
        }

        return self::MEANS_SUBSCRIBED;
    }

    /**
     * A first guess at what each column holds, from its header and what is
     * in it.
     *
     * @param  string[]                $columns
     * @param  array<int, string[]>    $sample
     * @return array<int, string> column index => one of Import::FIELD_*
     */
    public function guessMapping(array $columns, array $sample): array
    {
        $I       = YSRTech_EmailCampaigns_Model_Import::class;
        $mapping = [];
        $used    = [];

        foreach ($columns as $index => $name) {
            $key   = preg_replace('/[^a-z]/', '', strtolower($name));
            $field = $I::FIELD_SKIP;

            if (in_array($key, ['email', 'emailaddress', 'mail', 'subscriberemail', 'emailaddr'], true)) {
                $field = $I::FIELD_EMAIL;
            } elseif (in_array($key, ['firstname', 'fname', 'first', 'givenname', 'subscriberfirstname'], true)) {
                $field = $I::FIELD_FIRST_NAME;
            } elseif (in_array($key, ['lastname', 'lname', 'last', 'surname', 'familyname', 'subscriberlastname'], true)) {
                $field = $I::FIELD_LAST_NAME;
            } elseif (in_array($key, ['name', 'fullname', 'customername'], true)) {
                $field = $I::FIELD_FULL_NAME;
            } elseif (in_array($key, [
                'unsubscribed', 'optout', 'optedout', 'unsub', 'status', 'subscribed', 'subscription', 'subscriptionstatus',
                'subscriberstatus', 'emailstatus', 'emailmarketing', 'emailmarketingstatus', 'acceptsmarketing',
                'acceptsemailmarketing', 'marketingconsent', 'emailconsent', 'optin', 'optedin',
            ], true)) {
                $field = $I::FIELD_UNSUBSCRIBED;
            }

            // The first column to claim a field keeps it
            if ($field !== $I::FIELD_SKIP && isset($used[$field])) {
                $field = $I::FIELD_SKIP;
            }

            $used[$field]    = true;
            $mapping[$index] = $field;
        }

        // No header called email: take the first column that looks like one
        if (!isset($used[$I::FIELD_EMAIL])) {
            foreach ($columns as $index => $name) {
                foreach ($sample as $row) {
                    if (isset($row[$index]) && filter_var(trim($row[$index]), FILTER_VALIDATE_EMAIL)) {
                        $mapping[$index] = $I::FIELD_EMAIL;
                        break 2;
                    }
                }
            }
        }

        return $mapping;
    }

    /**
     * Import the next batch of rows.
     *
     * @param  YSRTech_EmailCampaigns_Model_Import $import
     * @return bool True when the file is finished
     */
    public function processChunk($import): bool
    {
        $I       = YSRTech_EmailCampaigns_Model_Import::class;
        $options = $import->getOptions();
        $mapping = $import->getMapping();
        $stats   = $import->getStats() + array_fill_keys($I::CONTACT_STATS, 0);

        $this->open($import->getFile());

        if ((int) ($options['offset'] ?? 0) === 0) {
            $this->row(); // the header
        } else {
            $this->seek((int) $options['offset']);
        }

        $emailColumn = array_search($I::FIELD_EMAIL, $mapping, true);

        if ($emailColumn === false) {
            Mage::throwException(Mage::helper('ysrtech_emailcampaigns')->__('No column is mapped to the email address.'));
        }

        /** @var YSRTech_EmailCampaigns_Model_Import_Addresscheck $check */
        $check    = Mage::getSingleton('ysrtech_emailcampaigns/import_addresscheck');
        $rowNo    = (int) $import->getProcessedRows() + 1;
        $people   = [];
        $skipped  = [];
        $finished = false;

        for ($n = 0; $n < self::CHUNK; $n++) {
            $row = $this->row();

            if ($row === null) {
                $finished = true;
                break;
            }

            $raw     = (string) ($row[$emailColumn] ?? '');
            $email   = strtolower(trim($raw));
            $problem = $check->problem($email, !empty($options['include_roles']), !empty($options['check_domains']));

            if ($problem) {
                $stats[$problem[0]]++;
                $skipped[] = [$rowNo, $raw, $problem[0], $problem[1]];
            } elseif (($person = $this->_person($row, $mapping, $people[$email] ?? null, $options['status_map'] ?? null)) === null) {
                $stats['status_skipped'] = ($stats['status_skipped'] ?? 0) + 1;
                $skipped[] = [$rowNo, $raw, 'status_skipped', null];
            } else {
                $people[$email] = $person;
            }

            $rowNo++;
        }

        $offset = $this->offset();

        if (!$finished && $this->row() === null) {
            $finished = true;
        }

        $this->close();

        Mage::getSingleton('ysrtech_emailcampaigns/import_contacts')->save($import, $people, $stats, $options);

        if ($skipped) {
            $this->_appendSkipped($import, $skipped);
        }

        $options['offset'] = $offset;
        $import->setStats($stats)->setOptions($options)->setProcessedRows($rowNo - 1);

        return $finished;
    }

    /**
     * @param  string[]           $row
     * @param  array<int, string> $mapping
     * @param  array|null         $previous The same address earlier in this batch
     * @param  array|null         $statusMap What each value of the status column means, as chosen on the
     *                                       mapping screen: value => subscribed|unsubscribed|skip
     * @return array|null null for a row whose status is one not to import
     */
    private function _person(array $row, array $mapping, ?array $previous, ?array $statusMap = null): ?array
    {
        $I      = YSRTech_EmailCampaigns_Model_Import::class;
        $person = $previous ?? ['status' => 'subscribed', 'dup' => false, 'extras' => false];

        if ($previous) {
            $person['dup'] = true;
        }

        foreach ($mapping as $index => $field) {
            $value = trim((string) ($row[$index] ?? ''));

            /*
             * The status column, read by the meanings chosen for its values.
             * Before the empty-cell check below: an empty cell is a value
             * too, and was given a meaning like the rest. A value nobody was
             * asked about (the file changed under the import) is not
             * imported: guessing could put somebody who opted out on the list.
             */
            if ($field === $I::FIELD_UNSUBSCRIBED && $statusMap !== null) {
                $means = $statusMap[self::statusKey($value)] ?? self::MEANS_SKIP;

                if ($means === self::MEANS_SKIP) {
                    return $previous;
                }

                if ($means === self::MEANS_UNSUBSCRIBED) {
                    $person['status'] = 'unsubscribed';
                }

                continue;
            }

            if ($value === '' || $field === $I::FIELD_EMAIL || $field === $I::FIELD_SKIP) {
                continue;
            }

            switch ($field) {
                case $I::FIELD_FIRST_NAME:
                case $I::FIELD_LAST_NAME:
                    $person[$field] = $value;
                    break;
                case $I::FIELD_FULL_NAME:
                    [$person['first_name'], $person['last_name']] = array_pad(preg_split('/\s+/', $value, 2) ?: [], 2, null);
                    break;
                case $I::FIELD_UNSUBSCRIBED:
                    if (in_array(strtolower($value), ['1', 'yes', 'y', 'true', 'unsubscribed', 'opted out', 'optout'], true)) {
                        $person['status'] = 'unsubscribed';
                    }
                    break;
            }
        }

        return $person;
    }

    /**
     * @param array<int, array{0:int, 1:string, 2:string, 3:string|null}> $rows
     */
    private function _appendSkipped($import, array $rows): void
    {
        $path   = $import->getSkippedFile();
        $isNew  = !file_exists($path);
        $handle = @fopen($path, 'a');

        if (!$handle) {
            return;
        }

        $h   = Mage::helper('ysrtech_emailcampaigns');
        $why = [
            'invalid'        => $h->__('Not a valid address'),
            'typo'           => $h->__('Looks like a typo'),
            'role'           => $h->__('Role address (a shared inbox)'),
            'no_mail_server' => $h->__('Domain cannot receive email'),
            'status_skipped' => $h->__('Status in the file set to "do not import"'),
        ];

        if ($isNew) {
            fputcsv($handle, ['row', 'email', 'reason', 'suggestion'], ',', '"', '');
        }

        foreach ($rows as [$rowNo, $email, $reason, $suggestion]) {
            // +1: row 1 of the file is its header
            fputcsv($handle, [$rowNo + 1, $this->_safeCell($email), $why[$reason] ?? $reason, (string) $suggestion], ',', '"', '');
        }

        fclose($handle);
    }

    /**
     * A cell somebody will open in a spreadsheet: one that starts with = + -
     * or @ is run as a formula, and this one came from an uploaded file.
     */
    private function _safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}
