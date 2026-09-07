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

    /**
     * How many people the segments currently chosen on the form would reach.
     *
     * Answered from the same query that fills the queue, so the number shown
     * before sending is the number that gets sent.
     */
    public function recipientCountAction()
    {
        $this->_validateFormKey();

        $response = ['count' => 0, 'formatted' => '0'];

        try {
            $included = array_map('intval', (array) $this->getRequest()->getParam('included_segment_ids', []));
            $excluded = array_map('intval', (array) $this->getRequest()->getParam('excluded_segment_ids', []));

            // Same rule the save applies, so the estimate matches the campaign
            // that would be saved rather than what was clicked
            $included = array_values(array_diff($included, $excluded));

            // Rebuilds the chosen segments first, so the answer describes the
            // list as it is now rather than as it was at the last nightly run
            $count = YSRTech_EmailCampaigns_Model_Campaign::countRecipients($included, $excluded);

            $response = ['count' => $count, 'formatted' => number_format($count)];
        } catch (Exception $e) {
            $response['error'] = $e->getMessage();
        }

        $this->getResponse()
            ->setHeader('Content-Type', 'application/json', true)
            ->setBody(Mage::helper('core')->jsonEncode($response));
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
                /*
                 * Null, not 0, when nothing is picked: both columns carry a
                 * foreign key, and there is no template or segment numbered 0
                 * for the row to point at.
                 */
                'template_id'   => !empty($data['template_id']) ? (int) $data['template_id'] : null,
                /*
                 * Multiselects post an array, and post nothing at all when the
                 * user clears every option - so the key's absence has to read
                 * as "none selected", not "leave as it was", or an exclusion
                 * could never be removed.
                 */
                'included_segment_ids' => array_map('intval', (array) ($data['included_segment_ids'] ?? [])),
                'excluded_segment_ids' => array_map('intval', (array) ($data['excluded_segment_ids'] ?? [])),
                'store_id'      => (int) ($data['store_id'] ?? 0),
                /*
                 * formatDate, because Varien_Date::toDbTimestamp() does not
                 * exist - calling it fataled the moment anyone set a date. The
                 * field posts DATETIME_INTERNAL_FORMAT already; this normalises
                 * it in the same frame the scheduler reads, which is
                 * Varien_Date::now(), i.e. server local time.
                 */
                'scheduled_at'  => !empty($data['scheduled_at'])
                    ? Varien_Date::formatDate($data['scheduled_at'], true) : null,
            ]);

            // Schedule action: queue it up.
            if (($data['action'] ?? '') === 'schedule') {
                $model->setStatus(YSRTech_EmailCampaigns_Model_Campaign::STATUS_SCHEDULED);
            } elseif ($model->getStatus() === null || $model->getStatus() === '') {
                $model->setStatus(YSRTech_EmailCampaigns_Model_Campaign::STATUS_DRAFT);
            }

            $model->save();

            if ($model->getStatus() === YSRTech_EmailCampaigns_Model_Campaign::STATUS_SCHEDULED) {
                /*
                 * A campaign due later stays scheduled and is picked up by the
                 * launchScheduledCampaigns cron when its time comes. Queueing
                 * it here regardless is what made "schedule" mean "send now".
                 */
                $due = !$model->getScheduledAt() || $model->getScheduledAt() <= Varien_Date::now();

                if ($due) {
                    $count = $model->buildQueue();
                    $model->setStatus(YSRTech_EmailCampaigns_Model_Campaign::STATUS_SENDING)->save();
                    $this->_getSession()->addSuccess(
                        $this->__('Campaign queued. %d recipients queued.', $count)
                    );
                } else {
                    $this->_getSession()->addSuccess(
                        $this->__('Campaign scheduled for %s.', $model->getScheduledAt())
                    );
                }
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
