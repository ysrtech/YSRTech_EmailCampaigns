<?php
require_once Mage::getModuleDir('controllers', 'YSRTech_EmailCampaigns')
    . DS . 'Adminhtml' . DS . 'Controller' . DS . 'Abstract.php';

/**
 * Import / Migrate.
 *
 * Nothing here imports anything. These actions take the file or the key,
 * record what was chosen, and leave a row for the runner; the work is done a
 * batch at a time from cron, or from "Process a batch now" on the progress
 * page, which runs the same runner for a few seconds.
 */
class YSRTech_EmailCampaigns_Adminhtml_Ysrtech_ImportController
    extends YSRTech_EmailCampaigns_Adminhtml_Controller_Abstract
{
    public const MAX_UPLOAD_MB = 25;

    /** Seconds one press of "Process a batch now" may run */
    private const MANUAL_BUDGET = 15;

    protected $_aclNode = 'import';

    public function indexAction()
    {
        $this->_page($this->__('Import / Migrate'), 'ysrtech_emailcampaigns/adminhtml_import');
    }

    public function gridAction()
    {
        $this->loadLayout();
        $this->getResponse()->setBody(
            $this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_import_grid')->toHtml()
        );
    }

    // ------------------------------------------------------------- CSV

    public function newAction()
    {
        $this->_page($this->__('Import a CSV File'), 'ysrtech_emailcampaigns/adminhtml_import_new');
    }

    public function uploadAction()
    {
        $file = $_FILES['file'] ?? null;

        if (!$this->getRequest()->isPost() || !$file || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return $this->_fail($this->__('Choose a file to upload.'), '*/*/new');
        }

        if (!$this->getRequest()->getPost('consent')) {
            return $this->_fail($this->__('Confirm that the people in the file asked to receive email.'), '*/*/new');
        }

        if ((int) $file['size'] > self::MAX_UPLOAD_MB * 1024 * 1024) {
            return $this->_fail($this->__('Files up to %d MB, please.', self::MAX_UPLOAD_MB), '*/*/new');
        }

        if (!in_array(strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)), ['csv', 'txt', 'tsv'], true)) {
            return $this->_fail($this->__('That is not a CSV file.'), '*/*/new');
        }

        try {
            $I = YSRTech_EmailCampaigns_Model_Import::class;

            // A name of ours, never the uploaded one: it is only ever read
            // back as a CSV, but there is no reason to let a browser choose
            // a path on the server.
            $name = 'upload-' . date('Ymd-His') . '-' . Mage::helper('core')->getRandomString(8) . '.csv';
            $path = $I::dir() . DS . $name;

            if (!move_uploaded_file($file['tmp_name'], $path)) {
                Mage::throwException($this->__('The file could not be saved to var/ysrtech_emailcampaigns/import.'));
            }

            /** @var YSRTech_EmailCampaigns_Model_Import_Csv $csv */
            $csv     = Mage::getModel('ysrtech_emailcampaigns/import_csv');
            $preview = $csv->preview($path);

            if (count($preview['columns']) < 1 || $preview['rows'] < 1) {
                @unlink($path);
                Mage::throwException($this->__('That file has no rows under its header.'));
            }

            $import = Mage::getModel('ysrtech_emailcampaigns/import')->setData([
                'type'        => $I::TYPE_CSV,
                'source_name' => mb_substr(basename((string) $file['name']), 0, 255),
                'status'      => $I::STATUS_DRAFT,
                'file_path'   => 'ysrtech_emailcampaigns' . DS . 'import' . DS . $name,
                'total_rows'  => $preview['rows'],
                'admin_user'  => $this->_adminName(),
            ]);
            $import->setMapping($csv->guessMapping($preview['columns'], $preview['sample']))
                ->setOptions(['consent' => 1])
                ->save();

            $this->_redirect('*/*/map', ['id' => $import->getId()]);
        } catch (Exception $e) {
            Mage::logException($e);
            $this->_fail($e->getMessage(), '*/*/new');
        }
    }

    public function mapAction()
    {
        $import = $this->_load(YSRTech_EmailCampaigns_Model_Import::TYPE_CSV, true);

        if (!$import) {
            return;
        }

        try {
            Mage::register('ysrtech_emailcampaigns_import_preview',
                Mage::getModel('ysrtech_emailcampaigns/import_csv')->preview($import->getFile()));
        } catch (Exception $e) {
            return $this->_fail($e->getMessage(), '*/*/index');
        }

        $this->_page($this->__('Match Columns'), 'ysrtech_emailcampaigns/adminhtml_import_page', 'ysrtech_emailcampaigns/import_map.phtml');
    }

    public function startAction()
    {
        $import = $this->_load(YSRTech_EmailCampaigns_Model_Import::TYPE_CSV, true);

        if (!$import || !$this->getRequest()->isPost()) {
            return;
        }

        $I       = YSRTech_EmailCampaigns_Model_Import::class;
        $allowed = [$I::FIELD_SKIP, $I::FIELD_EMAIL, $I::FIELD_FIRST_NAME, $I::FIELD_LAST_NAME, $I::FIELD_FULL_NAME, $I::FIELD_UNSUBSCRIBED];
        $mapping = [];

        foreach ((array) $this->getRequest()->getPost('mapping') as $index => $field) {
            $mapping[(int) $index] = in_array($field, $allowed, true) ? $field : $I::FIELD_SKIP;
        }

        if (count(array_keys($mapping, $I::FIELD_EMAIL, true)) !== 1) {
            return $this->_fail($this->__('Exactly one column has to be the email address.'), '*/*/map', ['id' => $import->getId()]);
        }

        $post = $this->getRequest()->getPost();

        $import->setMapping($mapping)
            ->setOptions([
                'consent'         => 1,
                'update_existing' => !empty($post['update_existing']) ? 1 : 0,
                'include_roles'   => !empty($post['include_roles']) ? 1 : 0,
                'check_domains'   => !empty($post['check_domains']) ? 1 : 0,
                'offset'          => 0,
            ])
            ->setStatus($I::STATUS_PENDING)
            ->save();

        $this->_redirect('*/*/view', ['id' => $import->getId()]);
    }

    // -------------------------------------------------------- provider

    public function providerAction()
    {
        $this->_page($this->__('Migrate from a Provider'), 'ysrtech_emailcampaigns/adminhtml_import_provider');
    }

    /**
     * Check the key, look at what the account holds, and keep both for the
     * next screen. Two or three quick reads; nothing is imported yet.
     */
    public function connectAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->_redirect('*/*/provider');
        }

        $I    = YSRTech_EmailCampaigns_Model_Import::class;
        $code = (string) $this->getRequest()->getPost('provider');
        $key  = trim((string) $this->getRequest()->getPost('api_key'));

        try {
            $client = $I::providerClient($code);

            // Only the fields this provider asks for, whatever was posted
            $credentials = ['api_key' => $key];
            $posted      = (array) $this->getRequest()->getPost('credentials');

            foreach ($client->getCredentialFields() as $field => $definition) {
                $value = trim((string) ($posted[$field] ?? ''));

                if ($value === '' && !empty($definition['required'])) {
                    Mage::throwException($this->__('%s is needed for %s.', $definition['label'], $client->getName()));
                }

                $credentials[$field] = $value;
            }

            $client->setCredentials($credentials);

            $account  = $client->account();
            $found    = $client->discover();

            $import = Mage::getModel('ysrtech_emailcampaigns/import')->setData([
                'type'        => $I::TYPE_PROVIDER,
                'provider'    => $code,
                'source_name' => mb_substr((string) ($account['name'] ?? ''), 0, 255),
                'status'      => $I::STATUS_DRAFT,
                'admin_user'  => $this->_adminName(),
            ]);
            $import->setCredentials($credentials)->setOptions(['found' => $found])->save();

            $this->_redirect('*/*/choose', ['id' => $import->getId()]);
        } catch (Exception $e) {
            // The provider's own words, already made presentable by the client
            $this->_fail($e->getMessage(), '*/*/provider');
        }
    }

    public function chooseAction()
    {
        if ($this->_load(YSRTech_EmailCampaigns_Model_Import::TYPE_PROVIDER, true)) {
            $this->_page($this->__('Choose What to Bring'), 'ysrtech_emailcampaigns/adminhtml_import_page', 'ysrtech_emailcampaigns/import_choose.phtml');
        }
    }

    public function startProviderAction()
    {
        $import = $this->_load(YSRTech_EmailCampaigns_Model_Import::TYPE_PROVIDER, true);

        if (!$import || !$this->getRequest()->isPost()) {
            return;
        }

        $P      = YSRTech_EmailCampaigns_Model_Import_Provider_Interface::class;
        $client = $import->getProviderClient();
        $stages = array_values(array_intersect($client->stages(), (array) $this->getRequest()->getPost('stages')));

        /*
         * Suppressions are not optional when contacts are coming. Bringing
         * people across without the record of who must not be mailed is the
         * one way a migration does real damage.
         */
        if (in_array($P::STAGE_CONTACTS, $stages, true) && in_array($P::STAGE_SUPPRESSIONS, $client->stages(), true)
            && !in_array($P::STAGE_SUPPRESSIONS, $stages, true)
        ) {
            array_unshift($stages, $P::STAGE_SUPPRESSIONS);
        }

        if (!$stages) {
            return $this->_fail($this->__('Choose at least one thing to bring across.'), '*/*/choose', ['id' => $import->getId()]);
        }

        if (in_array($P::STAGE_CONTACTS, $stages, true) && !$this->getRequest()->getPost('consent')) {
            return $this->_fail($this->__('Confirm that these contacts asked to receive email.'), '*/*/choose', ['id' => $import->getId()]);
        }

        $options = $import->getOptions();
        $found   = (array) ($options['found'] ?? []);
        $total   = 0;
        $known   = true;

        foreach ($stages as $stage) {
            if (isset($found[$stage]) && $found[$stage] !== null) {
                $total += (int) $found[$stage];
            } else {
                $known = false;
            }
        }

        $import->setOptions($options + [
            'stages'          => $stages,
            'consent'         => 1,
            'update_existing' => 1,
            'include_roles'   => !empty($this->getRequest()->getPost('include_roles')) ? 1 : 0,
        ])
            ->setTotalRows($known ? $total : null)
            ->setStatus(YSRTech_EmailCampaigns_Model_Import::STATUS_PENDING)
            ->save();

        $this->_redirect('*/*/view', ['id' => $import->getId()]);
    }

    // ------------------------------------------------------- watching

    public function viewAction()
    {
        $import = $this->_load();

        if (!$import) {
            return;
        }

        // Left half-way: back to the step it was on
        if ($import->getStatus() === YSRTech_EmailCampaigns_Model_Import::STATUS_DRAFT) {
            return $this->_redirect(
                $import->getType() === YSRTech_EmailCampaigns_Model_Import::TYPE_CSV ? '*/*/map' : '*/*/choose',
                ['id' => $import->getId()]
            );
        }

        $this->_page($this->__('Import #%d', $import->getId()), 'ysrtech_emailcampaigns/adminhtml_import_page', 'ysrtech_emailcampaigns/import_view.phtml');
    }

    /**
     * Run the runner for a few seconds from this request, for a store whose
     * cron is slow or not running.
     */
    public function processAction()
    {
        $import = $this->_load();

        if (!$import || !$this->getRequest()->isPost()) {
            return;
        }

        if ($import->isRunning()) {
            // Waiting out a rate limit is the provider's instruction, not
            // something a button should override
            if (!$import->getRetryAt() || strtotime((string) $import->getRetryAt() . ' UTC') <= time()) {
                Mage::getSingleton('ysrtech_emailcampaigns/import_runner')->run(self::MANUAL_BUDGET, (int) $import->getId());
            }
        }

        $this->_redirect('*/*/view', ['id' => $import->getId()]);
    }

    public function cancelAction()
    {
        $import = $this->_load();

        if (!$import || !$this->getRequest()->isPost()) {
            return;
        }

        if (!$import->isFinished()) {
            $import->finish(YSRTech_EmailCampaigns_Model_Import::STATUS_CANCELLED);
            $this->_getSession()->addSuccess($this->__('Cancelled. What was imported so far has been kept.'));
        }

        $this->_redirect('*/*/view', ['id' => $import->getId()]);
    }

    /**
     * The rows a CSV import left out, with why.
     */
    public function skippedAction()
    {
        $import = $this->_load();

        if (!$import) {
            return;
        }

        $path = $import->getSkippedFile();

        if (!is_file($path)) {
            return $this->_fail($this->__('No rows were skipped.'), '*/*/view', ['id' => $import->getId()]);
        }

        $this->_prepareDownloadResponse('import-' . (int) $import->getId() . '-skipped.csv', ['type' => 'filename', 'value' => $path]);
    }

    // -------------------------------------------------------- helpers

    protected function _page(string $title, string $block, ?string $template = null): void
    {
        $this->_title($this->__('Email Campaigns'))->_title($title);
        $this->loadLayout();
        $this->_setActiveMenu('ysrtech_emailcampaigns/import');

        $instance = $this->getLayout()->createBlock($block);

        if ($template !== null) {
            $instance->setTemplate($template);
        }

        $this->_addContent($instance);
        $this->renderLayout();
    }

    /**
     * @return YSRTech_EmailCampaigns_Model_Import|null Null after a redirect
     */
    protected function _load(?string $type = null, bool $draftOnly = false)
    {
        $import = Mage::getModel('ysrtech_emailcampaigns/import')->load((int) $this->getRequest()->getParam('id'));

        if (!$import->getId() || ($type !== null && $import->getType() !== $type)) {
            $this->_fail($this->__('That import no longer exists.'), '*/*/index');

            return null;
        }

        if ($draftOnly && $import->getStatus() !== YSRTech_EmailCampaigns_Model_Import::STATUS_DRAFT) {
            $this->_redirect('*/*/view', ['id' => $import->getId()]);

            return null;
        }

        Mage::register('ysrtech_emailcampaigns_import', $import);

        return $import;
    }

    protected function _fail(string $message, string $route, array $params = []): void
    {
        $this->_getSession()->addError($message);
        $this->_redirect($route, $params);
    }

    protected function _adminName(): string
    {
        $user = Mage::getSingleton('admin/session')->getUser();

        return $user ? (string) $user->getUsername() : '';
    }
}
