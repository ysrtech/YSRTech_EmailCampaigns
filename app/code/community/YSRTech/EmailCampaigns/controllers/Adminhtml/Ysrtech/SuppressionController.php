<?php
require_once Mage::getModuleDir('controllers', 'YSRTech_EmailCampaigns')
    . DS . 'Adminhtml' . DS . 'Controller' . DS . 'Abstract.php';

class YSRTech_EmailCampaigns_Adminhtml_Ysrtech_SuppressionController
    extends YSRTech_EmailCampaigns_Adminhtml_Controller_Abstract
{
    protected $_aclNode = 'suppression';

    /** Above this many, a removal is not also replayed against Mailgun */
    private const MAILGUN_LIFT_LIMIT = 100;

    public function indexAction()
    {
        $this->_title($this->__('Email Campaigns'))->_title($this->__('Suppressions'));
        $this->loadLayout();
        $this->_setActiveMenu('ysrtech_emailcampaigns/suppression');
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_suppression'));
        $this->renderLayout();
    }

    public function gridAction()
    {
        $this->loadLayout();
        $this->getResponse()->setBody(
            $this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_suppression_grid')->toHtml()
        );
    }

    public function exportCsvAction()
    {
        $grid = $this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_suppression_grid');
        $this->_prepareDownloadResponse('suppressions.csv', $grid->getCsvFile());
    }

    public function exportXmlAction()
    {
        $grid = $this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_suppression_grid');
        $this->_prepareDownloadResponse('suppressions.xml', $grid->getExcelFile());
    }

    public function addAction()
    {
        $this->_title($this->__('Email Campaigns'))->_title($this->__('Add Suppressions'));
        $this->loadLayout();
        $this->_setActiveMenu('ysrtech_emailcampaigns/suppression');
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_suppression_add'));
        $this->renderLayout();
    }

    public function addPostAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->_redirect('*/*/index');

            return;
        }

        $text = (string) $this->getRequest()->getPost('emails');

        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            if ((int) $_FILES['file']['size'] > 5 * 1024 * 1024) {
                $this->_getSession()->addError($this->__('Files up to 5 MB, please.'));
                $this->_redirect('*/*/add');

                return;
            }

            $text .= "\n" . (string) file_get_contents($_FILES['file']['tmp_name']);
        }

        [$emails, $invalid] = YSRTech_EmailCampaigns_Model_Suppression::extractEmails($text);

        if (!$emails) {
            $this->_getSession()->addError($this->__('No email addresses were found in that.'));
            $this->_redirect('*/*/add');

            return;
        }

        if (count($emails) > YSRTech_EmailCampaigns_Model_Suppression::MAX_BULK) {
            $this->_getSession()->addError($this->__(
                'That is %s addresses. Up to %s at a time; for more, use Import / Migrate.',
                number_format(count($emails)),
                number_format(YSRTech_EmailCampaigns_Model_Suppression::MAX_BULK)
            ));
            $this->_redirect('*/*/add');

            return;
        }

        try {
            $detail = trim((string) $this->getRequest()->getPost('detail'));
            $result = Mage::getResourceSingleton('ysrtech_emailcampaigns/suppression')->addMany(
                array_fill_keys($emails, $detail),
                YSRTech_EmailCampaigns_Model_Suppression::REASON_MANUAL,
                'admin'
            );

            $message = $this->__('%s added.', number_format($result['added']));

            if ($result['already']) {
                $message .= ' ' . $this->__('%s already there.', number_format($result['already']));
            }

            if ($invalid) {
                $message .= ' ' . $this->__('%s not valid.', number_format($invalid));
            }

            $this->_getSession()->addSuccess($message);
        } catch (Exception $e) {
            Mage::logException($e);
            $this->_getSession()->addError($e->getMessage());
        }

        $this->_redirect('*/*/index');
    }

    /**
     * Remove the selected rows, or one row from its Remove link.
     */
    public function massRemoveAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->_redirect('*/*/index');

            return;
        }

        $ids = $this->getRequest()->getPost('suppression_ids');
        $ids = is_array($ids) ? $ids : explode(',', (string) $ids);
        $ids = array_filter(array_map('intval', $ids));

        try {
            /** @var YSRTech_EmailCampaigns_Model_Resource_Suppression $resource */
            $resource = Mage::getResourceSingleton('ysrtech_emailcampaigns/suppression');
            $session  = Mage::getSingleton('admin/session');
            $kept     = 0;

            if ($ids && !$session->isAllowed('ysrtech_emailcampaigns/suppression/remove_complaint')) {
                $complaints = Mage::getResourceModel('ysrtech_emailcampaigns/suppression_collection')
                    ->addFieldToFilter('suppression_id', ['in' => $ids])
                    ->addFieldToFilter('reason', YSRTech_EmailCampaigns_Model_Suppression::REASON_COMPLAINT)
                    ->getAllIds();

                $kept = count($complaints);
                $ids  = array_diff($ids, array_map('intval', $complaints));
            }

            $removed = $resource->removeByIds($ids);

            if ($removed) {
                $this->_getSession()->addSuccess($this->__('%s removed from suppressions.', number_format(count($removed))));
            }

            if ($kept) {
                $this->_getSession()->addNotice($this->__(
                    '%s spam complaint(s) were left in place: removing those needs the "Remove Spam Complaints" permission.',
                    number_format($kept)
                ));
            }

            if (!$removed && !$kept) {
                $this->_getSession()->addError($this->__('Nothing was selected.'));
            }

            $this->_liftOnMailgun($removed);
        } catch (Exception $e) {
            Mage::logException($e);
            $this->_getSession()->addError($e->getMessage());
        }

        if ($this->getRequest()->isXmlHttpRequest()) {
            // The row link reloads the page itself; the messages are waiting
            // in the session when it does.
            $this->getResponse()->setBody('{}');

            return;
        }

        $this->_redirect('*/*/index');
    }

    /**
     * @param array<int, array{email:string, reason:string}> $removed
     */
    protected function _liftOnMailgun(array $removed): void
    {
        $helper = Mage::helper('ysrtech_emailcampaigns');

        if (!$removed || strtolower((string) $helper->getConfig('sending/transport')) !== 'mailgun') {
            return;
        }

        if (count($removed) > self::MAILGUN_LIFT_LIMIT) {
            $this->_getSession()->addNotice($this->__(
                'These addresses were not taken off Mailgun\'s own suppression lists, which is done for up to %d at a time. Mailgun will go on refusing mail to any that are on them, and the hourly sync will add those back here.',
                self::MAILGUN_LIFT_LIMIT
            ));

            return;
        }

        $failed = Mage::getSingleton('ysrtech_emailcampaigns/suppressions')->liftOnMailgun($removed);

        if ($failed) {
            $this->_getSession()->addNotice($this->__(
                '%d could not be taken off Mailgun\'s own suppression lists (that needs the Suppression API Key). Mailgun will go on refusing mail to them, and the hourly sync will add them back here.',
                $failed
            ));
        }
    }
}
