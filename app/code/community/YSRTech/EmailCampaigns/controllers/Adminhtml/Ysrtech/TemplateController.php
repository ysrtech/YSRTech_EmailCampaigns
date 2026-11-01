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
    public function indexAction()
    {
        $this->_title($this->__('Email Campaigns'))->_title($this->__('Templates'));
        $this->loadLayout();
        $this->_setActiveMenu('ysrtech_emailcampaigns/template');
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_template'));
        $this->renderLayout();
    }

    public function newAction()
    {
        $this->_forward('edit');
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

    /**
     * Search-as-you-type for the designer's Product/Category blocks.
     *
     * Only the display data a block's canvas preview needs (image/name/price
     * or image/name/url) — not the merge-tag markers the block actually
     * saves. Renderer.php re-resolves those against the live catalog at send
     * time (see injectCatalogData()), so a price or stock change between
     * designing a campaign and it going out is never frozen into the saved
     * HTML.
     */
    public function catalogSearchAction()
    {
        $type  = (string) $this->getRequest()->getParam('type');
        $query = trim((string) $this->getRequest()->getParam('q'));
        // Reopening a saved template needs this too: the saved HTML only
        // carries the picked id (inside its merge-tag markers), not the
        // name/price/image a canvas preview needs, so the designer looks
        // those back up by id rather than showing a blank or stale block.
        $id    = (int) $this->getRequest()->getParam('id');

        if ($type !== 'product' && $type !== 'category') {
            $results = [];
        } elseif ($id > 0) {
            $results = array_filter([$this->_catalogItem($type, $id)]);
        } elseif ($query !== '') {
            $results = $this->_catalogSearch($type, $query);
        } else {
            $results = [];
        }

        $this->getResponse()
            ->setHeader('Content-Type', 'application/json', true)
            ->setBody(Mage::helper('core')->jsonEncode(array_values($results)));
    }

    /** @return array[] */
    protected function _catalogSearch(string $type, string $query): array
    {
        $results = [];

        if ($type === 'product') {
            $collection = Mage::getResourceModel('catalog/product_collection')
                ->addAttributeToSelect(['name', 'price', 'small_image'])
                ->addAttributeToFilter('name', ['like' => "%{$query}%"])
                ->setPageSize(20);

            foreach ($collection as $product) {
                $results[] = $this->_productData($product);
            }
        } else {
            $collection = Mage::getResourceModel('catalog/category_collection')
                ->addAttributeToSelect(['name', 'image'])
                ->addAttributeToFilter('name', ['like' => "%{$query}%"])
                ->setPageSize(20);

            foreach ($collection as $category) {
                $results[] = $this->_categoryData($category);
            }
        }

        return $results;
    }

    /** @return array|null */
    protected function _catalogItem(string $type, int $id)
    {
        if ($type === 'product') {
            $product = Mage::getModel('catalog/product')->load($id);

            return $product->getId() ? $this->_productData($product) : null;
        }

        $category = Mage::getModel('catalog/category')->load($id);

        return $category->getId() ? $this->_categoryData($category) : null;
    }

    /** @return array */
    protected function _productData($product): array
    {
        $hasImage = $product->getSmallImage() && $product->getSmallImage() !== 'no_selection';

        return [
            'id'    => (int) $product->getId(),
            'name'  => $product->getName(),
            'price' => Mage::helper('core')->currency($product->getPrice(), true, false),
            // Mage_Catalog_Helper_Image throws "Image file was not found"
            // when a product has none set, rather than returning an
            // empty/placeholder string — guarded here and again in
            // Renderer.php's send-time resolution.
            'image' => $hasImage
                ? (string) Mage::helper('catalog/image')->init($product, 'small_image')->resize(120)
                : '',
            'url'   => $product->getProductUrl(),
        ];
    }

    /** How many products the Category block's grid shows, 2 per row. */
    private const CATEGORY_GRID_SIZE = 4;

    /** @return array */
    protected function _categoryData($category): array
    {
        $products = Mage::getResourceModel('catalog/product_collection')
            ->addCategoryFilter($category)
            ->addAttributeToSelect(['name', 'price', 'small_image'])
            ->setPageSize(self::CATEGORY_GRID_SIZE);

        return [
            'id'       => (int) $category->getId(),
            'name'     => $category->getName(),
            'image'    => (string) $category->getImageUrl(),
            'url'      => (string) $category->getUrl(),
            // The canvas-preview sample for the block's product grid —
            // Renderer.php's send-time resolution re-queries this fresh
            // rather than trusting what was cached here at design time.
            // array_values(): a Magento collection's iterator_to_array() is
            // keyed by entity id, not 0,1,2…, and json_encode() serializes a
            // non-sequential-from-zero array as a JSON *object* — the
            // frontend's `.length` check on it would then silently see
            // `undefined`, not an empty-but-real array.
            'products' => array_values(array_map([$this, '_productData'], iterator_to_array($products))),
        ];
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
