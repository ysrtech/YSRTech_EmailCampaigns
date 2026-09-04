<?php
/*
 * Magento 1 does not autoload controller classes - they sit outside the
 * autoloader's class path - so a controller extending another one has to
 * pull it in itself, or the class is simply not there when the router
 * instantiates this file.
 */
require_once Mage::getModuleDir('controllers', 'YSRTech_EmailCampaigns')
    . DS . 'Adminhtml' . DS . 'Controller' . DS . 'Abstract.php';

class YSRTech_EmailCampaigns_Adminhtml_Ysrtech_CampaignController
    extends YSRTech_EmailCampaigns_Adminhtml_Controller_Abstract
{
    public function indexAction()
    {
        $this->_title($this->__('Email Campaigns'))->_title($this->__('Campaigns'));
        $this->loadLayout();
        $this->_setActiveMenu('ysrtech_emailcampaigns/campaign');
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_campaign'));
        $this->renderLayout();
    }

    public function newAction()
    {
        $this->_forward('edit');
    }

    public function editAction()
    {
        $id = (int) $this->getRequest()->getParam('id');
        /** @var YSRTech_EmailCampaigns_Model_Campaign $model */
        $model = Mage::getModel('ysrtech_emailcampaigns/campaign');
        if ($id) {
            $model->load($id);
            if (!$model->getId()) {
                $this->_getSession()->addError($this->__('Campaign no longer exists.'));
                $this->_redirect('*/*/index');
                return;
            }
        }
        Mage::register('ysrtech_emailcampaigns_campaign', $model);

        $this->_title($model->getId() ? $model->getName() : $this->__('New Campaign'));
        $this->loadLayout();
        $this->_setActiveMenu('ysrtech_emailcampaigns/campaign');
        $this->getLayout()->getBlock('head')->setCanLoadExtJs(true);
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_campaign_edit'));
        $this->renderLayout();
    }

    public function saveAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->_redirect('*/*/index');
            return;
        }
        $data = $this->getRequest()->getPost();
        /** @var YSRTech_EmailCampaigns_Model_Campaign $model */
        $model = Mage::getModel('ysrtech_emailcampaigns/campaign');
        $id = (int) $this->getRequest()->getParam('id');
        if ($id) {
            $model->load($id);
        }

        try {
            $model->addData([
                'name'          => (string) $data['name'],
                'subject'       => (string) ($data['subject'] ?? ''),
                'template_id'   => (int) ($data['template_id'] ?? 0),
                'segment_id'    => (int) ($data['segment_id'] ?? 0),
                'store_id'      => (int) ($data['store_id'] ?? 0),
                'scheduled_at'  => !empty($data['scheduled_at'])
                    ? Varien_Date::toDbTimestamp($data['scheduled_at']) : null,
            ]);

            // Schedule action: queue it up.
            if (($data['action'] ?? '') === 'schedule') {
                $model->setStatus(YSRTech_EmailCampaigns_Model_Campaign::STATUS_SCHEDULED);
            } elseif ($model->getStatus() === null || $model->getStatus() === '') {
                $model->setStatus(YSRTech_EmailCampaigns_Model_Campaign::STATUS_DRAFT);
            }

            $model->save();

            if ($model->getStatus() === YSRTech_EmailCampaigns_Model_Campaign::STATUS_SCHEDULED) {
                $count = $model->buildQueue();
                $model->setStatus(YSRTech_EmailCampaigns_Model_Campaign::STATUS_SENDING)->save();
                $this->_getSession()->addSuccess(
                    $this->__('Campaign scheduled. %d recipients queued.', $count)
                );
            } else {
                $this->_getSession()->addSuccess($this->__('Campaign saved.'));
            }
        } catch (Exception $e) {
            $this->_getSession()->addError($e->getMessage());
            Mage::logException($e);
        }
        $this->_redirect('*/*/index');
    }

    public function pauseAction()
    {
        $this->_changeStatus(YSRTech_EmailCampaigns_Model_Campaign::STATUS_PAUSED, 'Campaign paused.');
    }

    public function resumeAction()
    {
        $this->_changeStatus(YSRTech_EmailCampaigns_Model_Campaign::STATUS_SENDING, 'Campaign resumed.');
    }

    public function cancelAction()
    {
        $this->_changeStatus(YSRTech_EmailCampaigns_Model_Campaign::STATUS_CANCELLED, 'Campaign cancelled.');
    }

    private function _changeStatus(string $status, string $message)
    {
        $id = (int) $this->getRequest()->getParam('id');
        try {
            Mage::getModel('ysrtech_emailcampaigns/campaign')
                ->load($id)
                ->setStatus($status)
                ->save();
            $this->_getSession()->addSuccess($this->__($message));
        } catch (Exception $e) {
            $this->_getSession()->addError($e->getMessage());
        }
        $this->_redirect('*/*/index');
    }

    public function gridAction()
    {
        $this->loadLayout();
        $this->getResponse()->setBody(
            $this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_campaign_grid')->toHtml()
        );
    }
}
