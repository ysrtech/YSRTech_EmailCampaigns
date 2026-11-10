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
    protected $_aclNode = 'campaign';

    public function indexAction()
    {
        $this->_title($this->__('Email Campaigns'))->_title($this->__('Campaigns'));
        $this->loadLayout();
        $this->_setActiveMenu('ysrtech_emailcampaigns/campaign');
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_campaign'));
        $this->renderLayout();
    }

    /**
     * A new campaign is a draft from the first moment, so that everything
     * typed into it is being saved from the first keystroke.
     */
    public function newAction()
    {
        $campaign = Mage::getModel('ysrtech_emailcampaigns/campaign')->setData([
            'name'     => $this->__('Untitled campaign'),
            'status'   => YSRTech_EmailCampaigns_Model_Campaign::STATUS_DRAFT,
            'store_id' => 0,
        ])->save();

        $this->_redirect('*/*/edit', ['id' => $campaign->getId()]);
    }

    public function editAction()
    {
        $campaign = $this->_campaign();

        if (!$campaign) {
            $this->_getSession()->addError($this->__('Campaign no longer exists.'));
            $this->_redirect('*/*/index');

            return;
        }

        Mage::register('ysrtech_emailcampaigns_campaign', $campaign);

        $this->_title($this->__('Email Campaigns'))->_title($campaign->getName());
        $this->loadLayout();
        $this->_setActiveMenu('ysrtech_emailcampaigns/campaign');
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_campaign_editor'));
        $this->renderLayout();
    }

    /**
     * Save whichever fields the editor sends, and answer with the campaign
     * as it now stands: summaries, recipient count, checks, what is missing.
     */
    public function autosaveAction()
    {
        $this->_json(function ($campaign) {
            $post = $this->getRequest()->getPost();
            $h    = Mage::helper('ysrtech_emailcampaigns');

            foreach (['name' => 255, 'subject' => 500, 'preheader' => 255, 'from_name' => 255] as $field => $max) {
                if (array_key_exists($field, $post)) {
                    $campaign->setData($field, mb_substr(trim((string) $post[$field]), 0, $max));
                }
            }

            if (array_key_exists('name', $post) && $campaign->getName() === '') {
                $campaign->setName($this->__('Untitled campaign'));
            }

            if (array_key_exists('from_local', $post)) {
                $local = strtolower(trim((string) $post['from_local']));

                // The part before the @ and nothing else: the domain is not
                // this page's to choose
                if ($local !== '' && !preg_match('/^[a-z0-9][a-z0-9._+-]{0,63}$/', $local)) {
                    Mage::throwException($h->__('The sender address can only have letters, digits, dots, dashes and underscores before the @.'));
                }

                $campaign->setFromLocal($local ?: null);
            }

            if (array_key_exists('reply_to', $post)) {
                $reply = trim((string) $post['reply_to']);

                if ($reply !== '' && !filter_var($reply, FILTER_VALIDATE_EMAIL)) {
                    Mage::throwException($h->__('"%s" is not an email address.', $reply));
                }

                $campaign->setReplyTo($reply ?: null);
            }

            if (array_key_exists('store_id', $post)) {
                $campaign->setStoreId((int) $post['store_id']);
            }

            if (array_key_exists('use_brand', $post)) {
                $campaign->setUseBrand(max(0, min(2, (int) $post['use_brand'])));
            }

            if (array_key_exists('segments', $post)) {
                /*
                 * Sent as one field, so that clearing every box still sends
                 * something: a multiselect with nothing chosen posts nothing,
                 * and nothing would read as "leave them as they were".
                 */
                $segments = json_decode((string) $post['segments'], true) ?: [];

                $campaign->setData('included_segment_ids', array_map('intval', (array) ($segments['included'] ?? [])));
                $campaign->setData('excluded_segment_ids', array_map('intval', (array) ($segments['excluded'] ?? [])));
            }

            $campaign->save();
        });
    }

    /**
     * Where the campaign's email comes from: a copy of a template, a
     * starter, or nothing again.
     *
     * A copy, always. From here on the campaign edits its own email, and
     * the template it started from is not changed by anything done to it.
     */
    public function contentAction()
    {
        $this->_json(function ($campaign) {
            $request = $this->getRequest();
            $source  = (string) $request->getPost('source');
            $h       = Mage::helper('ysrtech_emailcampaigns');

            /** @var YSRTech_EmailCampaigns_Model_Template_Starter $starter */
            $starter = Mage::getSingleton('ysrtech_emailcampaigns/template_starter');

            if ($source === 'clear') {
                $campaign->addData(['html' => null, 'template_id' => null]);
            } elseif ($source === 'template') {
                $template = Mage::getModel('ysrtech_emailcampaigns/template')->load((int) $request->getPost('template_id'));

                if (!$template->getId() || trim((string) $template->getHtml()) === '') {
                    Mage::throwException($h->__('That template has nothing in it.'));
                }

                $campaign->addData([
                    'html'        => (string) $template->getHtml(),
                    'template_id' => (int) $template->getId(),
                    // As the template was designed: with or without the
                    // store's header and footer around it
                    'use_brand'   => (int) $template->getUseBrand(),
                ]);
                $this->_fillFrom($campaign, (string) $template->getSubject(), (string) $template->getPreheader());
            } elseif ($source === 'starter') {
                $code     = (string) $request->getPost('starter');
                $starters = $starter->getStarters();

                if (!isset($starters[$code])) {
                    Mage::throwException($h->__('Choose a starting point.'));
                }

                $campaign->addData([
                    'html'        => $starter->build($code, $campaign->getStoreId() ?: null),
                    'template_id' => null,
                    'use_brand'   => $starter->getFrame($code),
                ]);
                $this->_fillFrom($campaign, $starters[$code]['subject'], $starters[$code]['preheader']);
            } else {
                Mage::throwException($h->__('Choose a starting point.'));
            }

            $campaign->save();
        });
    }

    /**
     * A starting point brings its subject and preview text, but only into
     * fields that are still empty: what somebody typed is theirs.
     */
    protected function _fillFrom($campaign, string $subject, string $preheader): void
    {
        if (trim((string) $campaign->getSubject()) === '' && $subject !== '') {
            $campaign->setSubject($subject);
        }

        if (trim((string) $campaign->getPreheader()) === '' && $preheader !== '') {
            $campaign->setPreheader($preheader);
        }
    }

    /**
     * The designer, on this campaign's own copy of the email.
     */
    public function designerAction()
    {
        $campaign = $this->_campaign();

        if (!$campaign || !$this->_editable($campaign)) {
            $this->_getSession()->addError($this->__('This campaign can no longer be edited.'));
            $this->_redirect('*/*/index');

            return;
        }

        // A campaign still pointing at a template gets its copy now: the
        // designer must never be editing the shared one from here.
        if (!$campaign->hasOwnContent()) {
            $template = $campaign->getContentTemplate();

            $campaign->setData('html', $template ? (string) $template->getHtml()
                : Mage::getSingleton('ysrtech_emailcampaigns/template_starter')->build('plain'))->save();
        }

        Mage::register('ysrtech_emailcampaigns_campaign', $campaign);
        Mage::register('ysrtech_emailcampaigns_template', $campaign->getContentTemplate());

        $this->_title($this->__('Email Campaigns'))->_title($this->__('Designer'))->_title($campaign->getName());
        $this->loadLayout();
        $this->_setActiveMenu('ysrtech_emailcampaigns/campaign');
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_campaign_designer'));
        $this->renderLayout();
    }

    /**
     * What the designer posts when Save Content is pressed.
     */
    public function saveDesignAction()
    {
        $campaign = $this->_campaign();

        if (!$campaign || !$this->getRequest()->isPost() || !$this->_editable($campaign)) {
            $this->_redirect('*/*/index');

            return;
        }

        try {
            $html = (string) $this->getRequest()->getPost('html');

            if (trim($html) === '') {
                Mage::throwException($this->__('There is nothing in the design to save.'));
            }

            $campaign->setData('html', $html);

            // What it sends has changed, so sending it is a decision to
            // make again
            if ($campaign->getStatus() === YSRTech_EmailCampaigns_Model_Campaign::STATUS_SCHEDULED) {
                $campaign->setStatus(YSRTech_EmailCampaigns_Model_Campaign::STATUS_DRAFT)->setScheduledAt(null);
                $this->_getSession()->addNotice($this->__('The campaign was scheduled; it is a draft again until you schedule it.'));
            }

            $campaign->save();
            $this->_getSession()->addSuccess($this->__('Design saved.'));
        } catch (Exception $e) {
            Mage::logException($e);
            $this->_getSession()->addError($e->getMessage());
        }

        $this->_redirect('*/*/designer', ['id' => $campaign->getId()]);
    }

    /**
     * The pre-send checks for this campaign, for the designer's Check button.
     */
    public function checksAction()
    {
        $campaign = $this->_campaign();
        $template = $campaign ? $campaign->getContentTemplate() : null;

        $this->getResponse()
            ->setHeader('Content-Type', 'application/json', true)
            ->setBody(Mage::helper('core')->jsonEncode([
                'checks' => $template
                    ? Mage::getSingleton('ysrtech_emailcampaigns/template_checks')
                        ->run($template, (string) $campaign->getSubject(), $campaign->getStoreId())
                    : [],
            ]));
    }

    /**
     * Send now, or at a date and time.
     *
     * Either way this only marks the campaign scheduled. The cron job that
     * launches scheduled campaigns queues the recipients in the background,
     * so "now" means within a minute or two, and nothing heavy happens in
     * this request.
     */
    public function sendAction()
    {
        $this->_json(function ($campaign) {
            $C      = YSRTech_EmailCampaigns_Model_Campaign::class;
            $editor = Mage::getSingleton('ysrtech_emailcampaigns/campaign_editor');
            $state  = $editor->state($campaign);

            if ($state['problems']) {
                Mage::throwException(reset($state['problems']));
            }

            if ($this->getRequest()->getPost('mode') === 'scheduled') {
                $at = $editor->toUtc((string) $this->getRequest()->getPost('date'), (string) $this->getRequest()->getPost('time'));

                if ($at <= Varien_Date::now()) {
                    Mage::throwException($this->__('That time has already passed.'));
                }
            } else {
                $at = Varien_Date::now();
            }

            $campaign->setStatus($C::STATUS_SCHEDULED)->setScheduledAt($at)->save();
        }, false);
    }

    /**
     * Take a scheduled campaign back to a draft, while it has not started.
     */
    public function unscheduleAction()
    {
        $this->_json(function ($campaign) {
            $C = YSRTech_EmailCampaigns_Model_Campaign::class;

            if ($campaign->getStatus() !== $C::STATUS_SCHEDULED) {
                Mage::throwException($this->__('This campaign has already started.'));
            }

            $campaign->setStatus($C::STATUS_DRAFT)->setScheduledAt(null)->save();
        }, false);
    }

    /**
     * A copy of the campaign as a new draft: same audience, sender, subject
     * and email, no schedule and no history.
     */
    public function duplicateAction()
    {
        $campaign = $this->_campaign();

        if (!$campaign || !$this->getRequest()->isPost()) {
            $this->_redirect('*/*/index');

            return;
        }

        $copy = Mage::getModel('ysrtech_emailcampaigns/campaign')->setData([
            'name'        => $this->__('%s (copy)', $campaign->getName()),
            'status'      => YSRTech_EmailCampaigns_Model_Campaign::STATUS_DRAFT,
            'subject'     => $campaign->getSubject(),
            'preheader'   => $campaign->getPreheader(),
            'from_name'   => $campaign->getFromName(),
            'from_local'  => $campaign->getFromLocal(),
            'reply_to'    => $campaign->getReplyTo(),
            'template_id' => $campaign->getTemplateId(),
            'html'        => $campaign->getData('html'),
            'store_id'    => $campaign->getStoreId(),
            'use_brand'   => (int) $campaign->getUseBrand(),
            'included_segment_ids' => $campaign->getIncludedSegmentIds(),
            'excluded_segment_ids' => $campaign->getExcludedSegmentIds(),
        ])->save();

        $this->_getSession()->addSuccess($this->__('Duplicated as a draft.'));
        $this->_redirect('*/*/edit', ['id' => $copy->getId()]);
    }

    public function deleteAction()
    {
        $campaign = $this->_campaign();
        $C        = YSRTech_EmailCampaigns_Model_Campaign::class;

        if ($campaign && $this->getRequest()->isPost()) {
            if (in_array($campaign->getStatus(), [$C::STATUS_SCHEDULED, $C::STATUS_SENDING, $C::STATUS_PAUSED], true)) {
                $this->_getSession()->addError($this->__('Cancel the campaign before deleting it.'));
                $this->_redirect('*/*/edit', ['id' => $campaign->getId()]);

                return;
            }

            try {
                $campaign->delete();
                $this->_getSession()->addSuccess($this->__('Campaign deleted.'));
            } catch (Exception $e) {
                Mage::logException($e);
                $this->_getSession()->addError($e->getMessage());
            }
        }

        $this->_redirect('*/*/index');
    }

    /**
     * The email in a tab of its own, as a recipient would see it.
     */
    public function previewAction()
    {
        $campaign = $this->_campaign();

        if (!$campaign) {
            $this->_getSession()->addError($this->__('Campaign no longer exists.'));
            $this->_redirect('*/*/index');
            return;
        }

        try {
            $template = $campaign->getContentTemplate();

            if ($template === null) {
                Mage::throwException($this->__('This campaign has no content to preview yet.'));
            }

            $this->_renderPreviewPage(
                Mage::getSingleton('ysrtech_emailcampaigns/sender')
                    ->renderPreview($template, $campaign->getStoreId()),
                (string) $campaign->getSubject(),
                sprintf('%s <%s>', $campaign->getFromNameOrDefault(), $campaign->getFromEmail() ?: $this->__('(no from address configured)'))
            );
        } catch (Exception $e) {
            $this->_getSession()->addError($e->getMessage());
            $this->_redirect('*/*/edit', ['id' => $campaign->getId()]);
        }
    }

    /**
     * Send one copy to an address, exactly as recipients will get it.
     */
    public function sendTestAction()
    {
        $this->_json(function ($campaign) {
            Mage::getSingleton('ysrtech_emailcampaigns/sender')
                ->sendTest($campaign, (string) $this->getRequest()->getPost('test_email'));
        }, false, false);
    }

    /**
     * @return YSRTech_EmailCampaigns_Model_Campaign|null
     */
    protected function _campaign()
    {
        $campaign = Mage::getModel('ysrtech_emailcampaigns/campaign')->load((int) $this->getRequest()->getParam('id'));

        return $campaign->getId() ? $campaign : null;
    }

    protected function _editable($campaign): bool
    {
        return in_array($campaign->getStatus(), [
            YSRTech_EmailCampaigns_Model_Campaign::STATUS_DRAFT,
            YSRTech_EmailCampaigns_Model_Campaign::STATUS_SCHEDULED,
        ], true);
    }

    /**
     * Run a change to a campaign and answer with its new state as JSON.
     *
     * @param callable $work       Given the campaign; throws to refuse
     * @param bool     $draftOnly  Refuse unless the campaign is still editable
     * @param bool     $needsEdit  False for actions allowed on any campaign
     */
    protected function _json(callable $work, bool $draftOnly = true, bool $needsEdit = true): void
    {
        $response = ['ok' => false];

        try {
            if (!$this->getRequest()->isPost() || !$this->_validateFormKey()) {
                Mage::throwException($this->__('This page has been open a while. Reload it and try again.'));
            }

            $campaign = $this->_campaign();

            if (!$campaign) {
                Mage::throwException($this->__('Campaign no longer exists.'));
            }

            if ($needsEdit && !$this->_editable($campaign)) {
                Mage::throwException($this->__('This campaign has started and can no longer be changed.'));
            }

            /*
             * Editing a scheduled campaign is allowed, but anything that
             * changes what or whom it sends puts the decision to send back
             * in front of a person: it returns to draft.
             */
            if ($draftOnly && $campaign->getStatus() === YSRTech_EmailCampaigns_Model_Campaign::STATUS_SCHEDULED) {
                $campaign->setStatus(YSRTech_EmailCampaigns_Model_Campaign::STATUS_DRAFT)->setScheduledAt(null);
                $response['unscheduled'] = true;
            }

            $work($campaign);

            $response['ok']    = true;
            $response['state'] = Mage::getSingleton('ysrtech_emailcampaigns/campaign_editor')
                ->state(Mage::getModel('ysrtech_emailcampaigns/campaign')->load($campaign->getId()));
        } catch (Mage_Core_Exception $e) {
            $response['message'] = $e->getMessage();
        } catch (Throwable $e) {
            Mage::logException($e);
            $response['message'] = $e->getMessage();
        }

        $this->getResponse()
            ->setHeader('Content-Type', 'application/json', true)
            ->setBody(Mage::helper('core')->jsonEncode($response));
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
