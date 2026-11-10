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
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_template'));
        $this->renderLayout();
    }

    /**
     * A new template starts from the gallery of starters. "Blank" there
     * leads to the plain form this used to open.
     */
    public function newAction()
    {
        if ($this->getRequest()->getParam('blank')) {
            $this->_forward('edit');

            return;
        }

        $this->_title($this->__('Email Campaigns'))->_title($this->__('New Template'));
        $this->loadLayout();
        $this->_setActiveMenu('ysrtech_emailcampaigns/template');
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_template_gallery'));
        $this->renderLayout();
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
            $template = Mage::getModel('ysrtech_emailcampaigns/template')->setData([
                'name'      => $starters[$code]['name'] . ' ' . Mage::app()->getLocale()->date()->toString('yyyy-MM-dd HH:mm'),
                'subject'   => $starters[$code]['subject'],
                'preheader' => $starters[$code]['preheader'] ?: null,
                'html'      => $starter->build($code),
                'is_active' => 1,
            ])->save();

            $this->_getSession()->addSuccess($this->__('Template created from "%s". Rename it under Back when you are ready.', $starters[$code]['name']));
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
     * Saved sections: list them, save one, or delete one.
     */
    public function sectionsAction()
    {
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_write');
        $table    = $resource->getTableName('ysrtech_emailcampaigns/section');
        $request  = $this->getRequest();
        $error    = null;

        try {
            if ($request->isPost() && $this->_validateFormKey()) {
                if ($request->getPost('delete')) {
                    $adapter->delete($table, ['section_id = ?' => (int) $request->getPost('delete')]);
                } elseif (trim((string) $request->getPost('name')) !== '' && trim((string) $request->getPost('html')) !== '') {
                    if (strlen((string) $request->getPost('html')) > 500000) {
                        Mage::throwException($this->__('That section is too large to save.'));
                    }

                    $adapter->insert($table, [
                        'name'       => mb_substr(trim((string) $request->getPost('name')), 0, 255),
                        'html'       => (string) $request->getPost('html'),
                        'created_at' => Varien_Date::now(),
                    ]);
                }
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }

        $sections = $adapter->fetchAll(
            $adapter->select()->from($table, ['id' => 'section_id', 'name', 'html'])->order('name')
        );

        $this->getResponse()
            ->setHeader('Content-Type', 'application/json', true)
            ->setBody(Mage::helper('core')->jsonEncode(['sections' => $sections, 'error' => $error]));
    }

    public function editAction()
    {
        $id = (int) $this->getRequest()->getParam('id');
        /** @var YSRTech_EmailCampaigns_Model_Template $model */
        $model = Mage::getModel('ysrtech_emailcampaigns/template');
        if ($id) {
            $model->load($id);
            if (!$model->getId()) {
                $this->_getSession()->addError($this->__('Template no longer exists.'));
                $this->_redirect('*/*/index');
                return;
            }
        }
        Mage::register('ysrtech_emailcampaigns_template', $model);

        /*
         * ?editor=1 used to render a drag and drop designer here. The block it
         * created pointed at a phtml that was never written, so the branch
         * produced a blank page. Anyone still holding such a url now gets the
         * normal edit form, which can do the job.
         */

        $this->_title($model->getId() ? $model->getName() : $this->__('New Template'));
        $this->loadLayout();
        $this->_setActiveMenu('ysrtech_emailcampaigns/template');
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_template_edit'));
        $this->renderLayout();
    }

    /**
     * The visual editor for this template's content.
     */
    public function designerAction()
    {
        /** @var YSRTech_EmailCampaigns_Model_Template $template */
        $template = Mage::getModel('ysrtech_emailcampaigns/template')->load((int) $this->getRequest()->getParam('id'));

        if (!$template->getId()) {
            $this->_getSession()->addError($this->__('Save the template before designing it.'));
            $this->_redirect('*/*/index');
            return;
        }

        Mage::register('ysrtech_emailcampaigns_template', $template);

        $this->_title($this->__('Email Campaigns'))
            ->_title($this->__('Designer'))
            ->_title($template->getName());

        $this->loadLayout();
        $this->_setActiveMenu('ysrtech_emailcampaigns/template');
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_template_designer'));
        $this->renderLayout();
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

    public function saveAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->_redirect('*/*/index');
            return;
        }
        $data = $this->getRequest()->getPost();
        /** @var YSRTech_EmailCampaigns_Model_Template $model */
        $model = Mage::getModel('ysrtech_emailcampaigns/template');
        $id = (int) $this->getRequest()->getParam('id');
        if ($id) {
            $model->load($id);
        }
        try {
            $model->addData([
                'name'         => (string) ($data['name'] ?? ''),
                'subject'      => (string) ($data['subject'] ?? ''),
                'design_json'  => (string) ($data['design_json'] ?? ''),
                'html'         => (string) ($data['html'] ?? ''),
                'is_active'    => (int) (!empty($data['is_active'])),
            ]);

            // Only when the form that posted has the field: the designer
            // saves content alone, and must not blank what it does not show.
            if (array_key_exists('use_brand', $data)) {
                $model->setUseBrand((int) !empty($data['use_brand']));
            }

            if (array_key_exists('preheader', $data)) {
                $model->setPreheader(mb_substr(trim((string) $data['preheader']), 0, 255) ?: null);
            }
            $model->save();
            $this->_getSession()->addSuccess($this->__('Template saved.'));
        } catch (Exception $e) {
            $this->_getSession()->addError($e->getMessage());
            Mage::logException($e);
        }

        if ($this->getRequest()->getParam('back') === 'designer') {
            $this->_redirect('*/*/designer', ['id' => $model->getId()]);
            return;
        }

        $this->_redirect('*/*/index');
    }

    public function deleteAction()
    {
        $id = (int) $this->getRequest()->getParam('id');
        try {
            Mage::getModel('ysrtech_emailcampaigns/template')->load($id)->delete();
            $this->_getSession()->addSuccess($this->__('Template deleted.'));
        } catch (Exception $e) {
            $this->_getSession()->addError($e->getMessage());
        }
        $this->_redirect('*/*/index');
    }

    public function gridAction()
    {
        $this->loadLayout();
        $this->getResponse()->setBody(
            $this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_template_grid')->toHtml()
        );
    }
}
