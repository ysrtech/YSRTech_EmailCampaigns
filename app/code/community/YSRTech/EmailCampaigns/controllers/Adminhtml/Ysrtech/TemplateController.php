<?php
/*
 * Magento 1 does not autoload controller classes - they sit outside the
 * autoloader's class path - so a controller extending another one has to
 * pull it in itself, or the class is simply not there when the router
 * instantiates this file.
 */
require_once Mage::getModuleDir('controllers', 'YSRTech_EmailCampaigns')
    . DS . 'Adminhtml' . DS . 'Controller' . DS . 'Abstract.php';

class YSRTech_EmailCampaigns_Adminhtml_Ysrtech_TemplateController
    extends YSRTech_EmailCampaigns_Adminhtml_Controller_Abstract
{
    protected $_aclNode = 'template';

    public function indexAction()
    {
        $this->_title($this->__('Email Campaigns'))->_title($this->__('Templates'));
        $this->loadLayout();
        $this->_setActiveMenu('ysrtech_emailcampaigns/template');
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_template_cards'));
        $this->renderLayout();
    }

    /**
     * A new template starts from the gallery of starters. "Blank" there
     * leads to the plain form this used to open.
     */
    public function newAction()
    {
        // "Blank", and "my own HTML": a new template, opened in the builder
        if ($this->getRequest()->getParam('blank')) {
            /** @var YSRTech_EmailCampaigns_Model_Design_Doc $docs */
            $docs = Mage::getModel('ysrtech_emailcampaigns/design_doc');
            $doc  = $docs->blank();

            if ($this->getRequest()->getParam('blank') === 'html') {
                $doc['rows'] = [$docs->row([1], [[$docs->block('html', ['code' => "<p style=\"margin:0\">Paste your HTML here.</p>\n<p style=\"margin:16px 0 0;font-size:12px\"><a href=\"{{ unsubscribe_url }}\">Unsubscribe</a></p>"])]])];
            }

            $template = Mage::getModel('ysrtech_emailcampaigns/template')
                ->setData(['name' => $this->__('Untitled template'), 'is_active' => 1])
                ->setDoc($doc)
                ->save();

            $this->_redirect('*/*/designer', ['id' => $template->getId()]);

            return;
        }

        $this->_title($this->__('Email Campaigns'))->_title($this->__('New Template'));
        $this->loadLayout();
        $this->_setActiveMenu('ysrtech_emailcampaigns/template');
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_template_gallery'));
        $this->renderLayout();
    }

    /**
     * "Design with AI": a brief becomes a template, opened in the designer
     * like one made from a starter.
     */
    public function aiDesignAction()
    {
        if (!$this->getRequest()->isPost() || !$this->_validateFormKey()) {
            $this->_redirect('*/*/new');

            return;
        }

        $post = $this->getRequest()->getPost();

        try {
            $template = Mage::getSingleton('ysrtech_emailcampaigns/ai_designer')->design(
                (string) ($post['brief'] ?? ''),
                (string) ($post['link'] ?? ''),
                (string) ($post['tone'] ?? 'friendly')
            );

            $this->_redirect('*/*/designer', ['id' => $template->getId()]);

            return;
        } catch (Mage_Core_Exception $e) {
            $this->_getSession()->addError($e->getMessage());
        } catch (Throwable $e) {
            Mage::logException($e);
            $this->_getSession()->addError($this->__('The design could not be made. Try again.'));
        }

        // What was typed is worth keeping when it did not work
        $this->_getSession()->setData('ysrtech_ec_ai_brief', [
            'brief' => mb_substr((string) ($post['brief'] ?? ''), 0, 1500),
            'link'  => mb_substr((string) ($post['link'] ?? ''), 0, 500),
            'tone'  => (string) ($post['tone'] ?? ''),
        ]);
        $this->_redirect('*/*/new');
    }

    /**
     * Create a template from a starter and open it in the designer.
     */
    public function createAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->_redirect('*/*/new');

            return;
        }

        /** @var YSRTech_EmailCampaigns_Model_Template_Starter $starter */
        $starter  = Mage::getSingleton('ysrtech_emailcampaigns/template_starter');
        $starters = $starter->getStarters();
        $code     = (string) $this->getRequest()->getPost('starter');

        if (!isset($starters[$code])) {
            $this->_getSession()->addError($this->__('Choose a starter.'));
            $this->_redirect('*/*/new');

            return;
        }

        try {
            $template = $starter->template($code)->save();

            $this->_redirect('*/*/designer', ['id' => $template->getId()]);
        } catch (Exception $e) {
            Mage::logException($e);
            $this->_getSession()->addError($e->getMessage());
            $this->_redirect('*/*/new');
        }
    }

    /**
     * The pre-send checks for a template, as JSON, for the designer's Check
     * button. Runs on what is saved, not on what is on screen.
     */
    public function checksAction()
    {
        $template = Mage::getModel('ysrtech_emailcampaigns/template')->load((int) $this->getRequest()->getParam('id'));
        $checks   = $template->getId()
            ? Mage::getSingleton('ysrtech_emailcampaigns/template_checks')->run($template)
            : [];

        $this->getResponse()
            ->setHeader('Content-Type', 'application/json', true)
            ->setBody(Mage::helper('core')->jsonEncode(['checks' => $checks]));
    }

    /**
     * A product block as it would be sent right now, for the designer to
     * show in place of the empty element it really is.
     */
    public function widgetAction()
    {
        $html = '';

        try {
            $options = json_decode((string) $this->getRequest()->getPost('options'), true);

            if ($this->getRequest()->isPost() && $this->_validateFormKey() && is_array($options)) {
                $html = Mage::getSingleton('ysrtech_emailcampaigns/widget_products')
                    ->render(array_map('strval', $options), (int) $this->getRequest()->getPost('store_id') ?: null);
            }
        } catch (Throwable $e) {
            Mage::logException($e);
        }

        $this->getResponse()
            ->setHeader('Content-Type', 'application/json', true)
            ->setBody(Mage::helper('core')->jsonEncode(['html' => $html]));
    }

    /**
     * Saved sections: list them, save one, or delete one. A section is a
     * row of the builder's document, kept to drop into other emails.
     */
    public function sectionsAction()
    {
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_write');
        $table    = $resource->getTableName('ysrtech_emailcampaigns/section');
        $request  = $this->getRequest();
        $error    = null;

        /** @var YSRTech_EmailCampaigns_Model_Design_Doc $docs */
        $docs = Mage::getModel('ysrtech_emailcampaigns/design_doc');

        try {
            if ($request->isPost() && $this->_validateFormKey()) {
                if ($request->getPost('delete')) {
                    $adapter->delete($table, ['section_id = ?' => (int) $request->getPost('delete')]);
                } elseif (trim((string) $request->getPost('name')) !== '' && $request->getPost('row')) {
                    // Through the same cleaning as a whole design, as a design of one row
                    $clean = $docs->clean(['rows' => [json_decode((string) $request->getPost('row'), true)]]);

                    if (!$clean['rows']) {
                        Mage::throwException($this->__('That section could not be saved.'));
                    }

                    $adapter->insert($table, [
                        'name'       => mb_substr(trim((string) $request->getPost('name')), 0, 255),
                        'row_json'   => json_encode($clean['rows'][0], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'created_at' => Varien_Date::now(),
                    ]);
                }
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }

        $sections = [];

        foreach ($adapter->fetchAll($adapter->select()->from($table, ['section_id', 'name', 'row_json', 'html'])->order('name')) as $section) {
            $row = json_decode((string) $section['row_json'], true);

            // One saved before the builder is a piece of HTML: it becomes a
            // row holding that HTML
            if (!is_array($row) && trim((string) $section['html']) !== '') {
                $row = $docs->row([1], [[$docs->block('html', ['code' => (string) $section['html'], 'pad' => [0, 0, 0, 0]])]], ['pad' => [0, 0]]);
            }

            if (is_array($row)) {
                $sections[] = ['id' => (int) $section['section_id'], 'name' => (string) $section['name'], 'row' => $row];
            }
        }

        $this->getResponse()
            ->setHeader('Content-Type', 'application/json', true)
            ->setBody(Mage::helper('core')->jsonEncode(['sections' => $sections, 'error' => $error]));
    }

    /**
     * What the builder posts: the design, and the name, subject and preview
     * text that are edited beside it. Answers with the pre-send checks.
     */
    public function saveDesignAction()
    {
        $response = ['ok' => false];

        try {
            if (!$this->getRequest()->isPost() || !$this->_validateFormKey()) {
                Mage::throwException($this->__('This page has been open a while. Reload it and try again.'));
            }

            $template = Mage::getModel('ysrtech_emailcampaigns/template')->load((int) $this->getRequest()->getParam('id'));

            if (!$template->getId()) {
                Mage::throwException($this->__('Template no longer exists.'));
            }

            $post = $this->getRequest()->getPost();

            $template->addData([
                'name'      => mb_substr(trim((string) ($post['name'] ?? '')), 0, 255) ?: $this->__('Untitled template'),
                'subject'   => mb_substr(trim((string) ($post['subject'] ?? '')), 0, 500),
                'preheader' => mb_substr(trim((string) ($post['preheader'] ?? '')), 0, 255) ?: null,
            ]);
            $template->setDoc((array) json_decode((string) ($post['doc'] ?? ''), true))->save();

            $response = [
                'ok'     => true,
                'checks' => Mage::getSingleton('ysrtech_emailcampaigns/template_checks')->run($template, (string) $template->getSubject()),
            ];
        } catch (Mage_Core_Exception $e) {
            $response['message'] = $e->getMessage();
        } catch (Throwable $e) {
            Mage::logException($e);
            $response['message'] = $this->__('The design could not be saved.');
        }

        $this->getResponse()
            ->setHeader('Content-Type', 'application/json', true)
            ->setBody(Mage::helper('core')->jsonEncode($response));
    }

    /**
     * A design as the email it makes, without saving it: "html" as a sample
     * reader would see it, for the builder's preview, and "raw" as it is
     * stored, for its HTML view.
     */
    public function renderDocAction()
    {
        $response = ['html' => '', 'raw' => ''];

        try {
            if ($this->getRequest()->isPost() && $this->_validateFormKey()) {
                $storeId  = (int) $this->getRequest()->getPost('store_id') ?: null;
                $template = Mage::getModel('ysrtech_emailcampaigns/template')
                    ->setPreheader((string) $this->getRequest()->getPost('preheader'))
                    ->setDoc((array) json_decode((string) $this->getRequest()->getPost('doc'), true), $storeId);

                $response = [
                    'raw'  => (string) $template->getHtml(),
                    'html' => Mage::getSingleton('ysrtech_emailcampaigns/sender')->renderPreview($template, $storeId),
                ];
            }
        } catch (Throwable $e) {
            Mage::logException($e);
        }

        $this->getResponse()
            ->setHeader('Content-Type', 'application/json', true)
            ->setBody(Mage::helper('core')->jsonEncode($response));
    }

    /**
     * A copy of a template, opened in the builder.
     */
    public function duplicateAction()
    {
        $template = Mage::getModel('ysrtech_emailcampaigns/template')->load((int) $this->getRequest()->getParam('id'));

        if (!$template->getId() || !$this->_validateFormKey()) {
            $this->_redirect('*/*/index');

            return;
        }

        $copy = Mage::getModel('ysrtech_emailcampaigns/template')
            ->setData(array_diff_key($template->getData(), ['template_id' => 1, 'created_at' => 1, 'updated_at' => 1]))
            ->setName($this->__('%s (copy)', $template->getName()))
            ->save();

        $this->_redirect('*/*/designer', ['id' => $copy->getId()]);
    }

    /**
     * There is no separate form any more: a template is edited in the
     * builder, name and subject included.
     */
    public function editAction()
    {
        $id = (int) $this->getRequest()->getParam('id');

        $this->_redirect($id ? '*/*/designer' : '*/*/new', $id ? ['id' => $id] : []);
    }

    /**
     * The builder, on this template.
     */
    public function designerAction()
    {
        /** @var YSRTech_EmailCampaigns_Model_Template $template */
        $template = Mage::getModel('ysrtech_emailcampaigns/template')->load((int) $this->getRequest()->getParam('id'));

        if (!$template->getId()) {
            $this->_getSession()->addError($this->__('Template no longer exists.'));
            $this->_redirect('*/*/index');
            return;
        }

        Mage::register('ysrtech_emailcampaigns_template', $template);

        // A page of its own: the builder is not drawn inside the admin's frame
        $this->getResponse()->setBody(
            $this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_template_designer')->toHtml()
        );
    }

    /** Where campaign images live, under the store's public media directory */
    private const ASSET_DIR = 'ysrtech/emailcampaigns';

    /**
     * List and receive the images the designer offers.
     *
     * They go into the store's own media directory rather than anywhere
     * private, because an email client fetches them from the open internet
     * weeks after the send - a path only the admin can reach would arrive as
     * a broken image for every recipient. For the same reason the urls handed
     * back are absolute.
     */
    public function assetsAction()
    {
        $this->_validateFormKey();

        try {
            if ($this->getRequest()->isPost() && !empty($_FILES['files'])) {
                $uploaded = $this->_receiveUploads();
            } else {
                $uploaded = [];
            }

            $this->getResponse()
                ->setHeader('Content-Type', 'application/json', true)
                ->setBody(Mage::helper('core')->jsonEncode([
                    'data' => $uploaded ?: $this->_listAssets(),
                ]));
        } catch (Exception $e) {
            Mage::logException($e);
            $this->getResponse()
                ->setHeader('Content-Type', 'application/json', true)
                ->setBody(Mage::helper('core')->jsonEncode(['data' => [], 'error' => $e->getMessage()]));
        }
    }

    /**
     * @return array
     * @throws Mage_Core_Exception
     */
    protected function _receiveUploads(): array
    {
        $dir = Mage::getBaseDir('media') . DS . str_replace('/', DS, self::ASSET_DIR);
        $io  = new Varien_Io_File();
        $io->checkAndCreateFolder($dir);

        $files = $_FILES['files'];
        $names = is_array($files['name']) ? $files['name'] : [$files['name']];
        $out   = [];

        foreach (array_keys($names) as $i) {
            $key = is_array($files['name']) ? "files[{$i}]" : 'files';

            $uploader = new Varien_File_Uploader($key);
            /*
             * Images only, and the extension is checked rather than trusted
             * from the browser: this writes into a directory the whole
             * internet can read, so a .php landing there would be served.
             */
            $uploader->setAllowedExtensions(['jpg', 'jpeg', 'png', 'gif', 'webp']);
            $uploader->setAllowRenameFiles(true);
            $uploader->setFilesDispersion(false);
            $uploader->setAllowCreateFolders(true);

            $result = $uploader->save($dir);

            if (!empty($result['file'])) {
                $out[] = $this->_asset($result['file']);
            }
        }

        return $out;
    }

    /**
     * @return array
     */
    protected function _listAssets(): array
    {
        $dir = Mage::getBaseDir('media') . DS . str_replace('/', DS, self::ASSET_DIR);

        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . DS . '*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE) ?: [];

        // Newest first: the image somebody just uploaded is the one they want
        usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a));

        return array_map(fn($path) => $this->_asset(basename($path)), $files);
    }

    /**
     * @param  string $file
     * @return array
     */
    protected function _asset(string $file): array
    {
        return [
            'type' => 'image',
            'src'  => Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA) . self::ASSET_DIR . '/' . rawurlencode($file),
            'name' => $file,
        ];
    }

    /**
     * The template as a recipient would see it, with stand-in details.
     */
    public function previewAction()
    {
        /** @var YSRTech_EmailCampaigns_Model_Template $template */
        $template = Mage::getModel('ysrtech_emailcampaigns/template')->load((int) $this->getRequest()->getParam('id'));

        if (!$template->getId()) {
            $this->_getSession()->addError($this->__('Template no longer exists.'));
            $this->_redirect('*/*/index');
            return;
        }

        try {
            $sender = Mage::getSingleton('ysrtech_emailcampaigns/sender');

            $this->_renderPreviewPage(
                $sender->renderPreview($template),
                (string) ($template->getSubject() ?: $template->getName()),
                $this->_previewFrom()
            );
        } catch (Exception $e) {
            $this->_getSession()->addError($e->getMessage());
            $this->_redirect('*/*/edit', ['id' => $template->getId()]);
        }
    }

    /**
     * @return string
     */
    protected function _previewFrom(): string
    {
        $helper = Mage::helper('ysrtech_emailcampaigns');

        return sprintf(
            '%s <%s>',
            $helper->getConfig('sending/from_name') ?: Mage::app()->getStore()->getFrontendName(),
            $helper->getConfig('sending/from_email') ?: $this->__('(no from address configured)')
        );
    }

    public function deleteAction()
    {
        $id = (int) $this->getRequest()->getParam('id');

        if (!$this->getRequest()->isPost() || !$this->_validateFormKey()) {
            $this->_redirect('*/*/index');

            return;
        }

        try {
            Mage::getModel('ysrtech_emailcampaigns/template')->load($id)->delete();
            $this->_getSession()->addSuccess($this->__('Template deleted.'));
        } catch (Exception $e) {
            $this->_getSession()->addError($e->getMessage());
        }
        $this->_redirect('*/*/index');
    }
}
