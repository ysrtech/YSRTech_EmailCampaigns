<?php
/**
 * The campaign editor: five steps down the left, the email as it will
 * arrive on the right.
 *
 * The page is drawn in the browser from the state this hands it, and
 * redrawn from the state every save returns. Nothing about whether a
 * campaign is ready is decided there.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Campaign_Editor extends Mage_Adminhtml_Block_Template
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('ysrtech_emailcampaigns/campaign_editor.phtml');
    }

    /** @return YSRTech_EmailCampaigns_Model_Campaign */
    public function getCampaign()
    {
        return Mage::registry('ysrtech_emailcampaigns_campaign');
    }

    public function getConfigJson(): string
    {
        $campaign = $this->getCampaign();
        $id       = ['id' => $campaign->getId()];
        $segments = [];

        foreach (Mage::getResourceModel('ysrtech_emailcampaigns/segment_collection')
                     ->addFieldToFilter('is_active', 1)->setOrder('name', 'ASC') as $segment) {
            $segments[] = [
                'id'    => (int) $segment->getId(),
                'name'  => (string) $segment->getName(),
                'count' => (int) $segment->getCustomerCount(),
            ];
        }

        $templates = [];

        foreach (Mage::getResourceModel('ysrtech_emailcampaigns/template_collection')
                     ->addFieldToFilter('is_active', 1)->setOrder('template_id', 'DESC') as $template) {
            $templates[] = ['id' => (int) $template->getId(), 'name' => (string) $template->getName()];
        }

        $starters = [];

        foreach (Mage::getSingleton('ysrtech_emailcampaigns/template_starter')->getStarters() as $code => $starter) {
            $starters[] = ['code' => $code, 'name' => $starter['name'], 'description' => $starter['description']];
        }

        $stores = [['id' => 0, 'name' => $this->__('All store views (default)')]];

        foreach (Mage::app()->getWebsites() as $website) {
            foreach ($website->getStores() as $store) {
                $stores[] = [
                    'id'   => (int) $store->getId(),
                    'name' => count(Mage::app()->getWebsites()) > 1
                        ? $website->getName() . ' / ' . $store->getName() : (string) $store->getName(),
                ];
            }
        }

        $stores = [['id' => 0, 'name' => $this->__('All store views (default)')]];

        foreach (Mage::app()->getWebsites() as $website) {
            foreach ($website->getStores() as $store) {
                $stores[] = [
                    'id'   => (int) $store->getId(),
                    'name' => count(Mage::app()->getWebsites()) > 1
                        ? $website->getName() . ' / ' . $store->getName() : (string) $store->getName(),
                ];
            }
        }

        $user = Mage::getSingleton('admin/session')->getUser();

        return Mage::helper('core')->jsonEncode([
            'state'     => Mage::getSingleton('ysrtech_emailcampaigns/campaign_editor')->state($campaign),
            'segments'  => $segments,
            'templates' => $templates,
            'starters'  => $starters,
            'stores'    => $stores,
            'brandUrl'  => $this->getUrl('*/system_config/edit', ['section' => 'ysrtech_emailcampaigns']),
            'stores'    => $stores,
            'brandUrl'  => $this->getUrl('*/system_config/edit', ['section' => 'ysrtech_emailcampaigns']),
            'formKey'   => Mage::getSingleton('core/session')->getFormKey(),
            // Offered only when it would work; the settings say how to turn it on
            'ai'        => Mage::getSingleton('ysrtech_emailcampaigns/ai_client')->configured(),
            'adminEmail' => $user ? (string) $user->getEmail() : '',
            'urls'      => [
                'autosave'   => $this->getUrl('*/*/autosave', $id),
                'suggest'    => $this->getUrl('*/*/suggest', $id),
                'content'    => $this->getUrl('*/*/content', $id),
                'designer'   => $this->getUrl('*/*/designer', $id),
                'send'       => $this->getUrl('*/*/send', $id),
                'unschedule' => $this->getUrl('*/*/unschedule', $id),
                'test'       => $this->getUrl('*/*/sendTest', $id),
                'duplicate'  => $this->getUrl('*/*/duplicate', $id),
                'delete'     => $this->getUrl('*/*/delete', $id),
                'pause'      => $this->getUrl('*/*/pause', $id),
                'resume'     => $this->getUrl('*/*/resume', $id),
                'cancel'     => $this->getUrl('*/*/cancel', $id),
                'back'       => $this->getUrl('*/*/index'),
                'segments'   => $this->getUrl('*/ysrtech_segment/index'),
                'dashboard'  => $this->getUrl('*/ysrtech_dashboard/index'),
            ],
            't' => $this->_labels(),
        ]);
    }

    /**
     * Every string the page shows, translated here.
     */
    protected function _labels(): array
    {
        $t = [
            'back' => 'Campaigns', 'saved' => 'Saved', 'saving' => 'Saving…', 'notSaved' => 'Not saved',
            'sendTest' => 'Send test', 'sendNow' => 'Send now', 'schedule' => 'Schedule', 'updateSchedule' => 'Update schedule',
            'backToDraft' => 'Back to draft', 'duplicate' => 'Duplicate', 'delete' => 'Delete',
            'pause' => 'Pause sending', 'resume' => 'Resume sending', 'cancel' => 'Cancel campaign',
            'ready' => 'Ready to send', 'ofReady' => '%1 of %2 ready', 'notSet' => 'Not set yet',
            'add' => 'Add', 'edit' => 'Edit', 'done' => 'Done',
            'to' => 'To', 'from' => 'From', 'subject' => 'Subject', 'content' => 'Content', 'when' => 'Send time',
            'storeView' => 'Store view',
            'storeViewNote' => 'Whose logo, colours, header, footer, name and links this email uses. Does not change who receives it: that is the segments below.',
            'useBrand' => 'Wrap in the store\'s header and footer',
            'useBrandNote' => 'Set per store view under %1. Shown in the preview on the right; the designer shows the part in between.',
            'brandSettings' => 'Settings > Brand',
            'ownDesign' => 'This is a complete design with its own header and footer; nothing is added around it.',
            'brandOff' => 'The header and footer are switched off for this store view in the settings.',
            'storeView' => 'Store view',
            'storeViewNote' => 'Whose logo, colours, header, footer, name and links this email uses. It does not change who receives it: that is the segments below.',
            'useBrand' => 'Wrap in the store\'s header and footer',
            'useBrandNote' => 'Set per store view under %1. Shown in the preview on the right; the designer shows the part in between.',
            'brandSettings' => 'Settings > Brand',
            'ownDesign' => 'This is a complete design with its own header and footer; nothing is added around it.',
            'brandOff' => 'The header and footer are switched off for this store view in the settings.',
            'include' => 'Send to people in', 'exclude' => 'But not anyone in', 'noSegments' => 'There are no segments yet.',
            'manageSegments' => 'Manage segments', 'recipients' => '%1 recipients', 'recipient' => '1 recipient',
            'recipientsNote' => 'Subscribed, and not on the suppression list. Counted again when sending starts.',
            'senderName' => 'Sender name', 'senderAddress' => 'Sender address', 'replyTo' => 'Reply-to',
            'optional' => 'optional', 'replyHint' => 'Where replies go, if not the sender',
            'defaultSender' => 'Leave empty to use the default: %1',
            'domainLocked' => 'Campaigns are sent from %1. Mail from another domain fails the checks inboxes run and lands in spam, so only the part before the @ can be changed here.',
            'suggest' => 'Suggest with AI', 'suggesting' => 'Thinking…', 'suggestSubjects' => 'Subject lines', 'suggestPreviews' => 'Preview text',
            'suggestHint' => 'Click one to use it. Written from the words of your email; check it says nothing the email does not.',
            'suggestAgain' => 'More',
            'subjectLine' => 'Subject line', 'subjectHint' => 'What’s new this month, {{ customer.firstname|there }}',
            'recommended' => '%1 / 60 recommended', 'previewText' => 'Preview text',
            'previewHint' => 'Shown after the subject in most inboxes',
            'yourDesign' => 'Your design', 'sizeNote' => '%1 KB of HTML · Gmail clips at 102 KB',
            'editDesign' => 'Edit design', 'startOver' => 'Start over',
            'startOverConfirm' => 'Start over? This removes the current design from the campaign.',
            'fromScratch' => 'Design from scratch', 'fromScratchNote' => 'A plain layout to build on in the designer',
            'fromStarter' => 'Start from a ready-made design', 'fromTemplate' => 'Or start from one of your templates',
            'copyNote' => 'Whatever you start from is copied into this campaign. Editing it here never changes the template.',
            'usesTemplate' => 'This campaign sends its template as it is. Edit design gives it a copy of its own to change.',
            'now' => 'Send now', 'nowNote' => 'Starts within a minute or two.',
            'later' => 'Schedule', 'laterNote' => 'Pick a date and time.',
            'date' => 'Date', 'time' => 'Time', 'storeTime' => 'Store time (%1)',
            'scheduledFor' => 'Scheduled for %1', 'chooseTime' => 'Choose a send time',
            'checks' => 'Pre-send check', 'passing' => '%1 of %2 passing',
            'preview' => 'Preview', 'desktop' => 'Desktop', 'mobile' => 'Mobile',
            'inbox' => 'How it looks in the inbox', 'yourSubject' => 'Your subject line',
            'previewHere' => 'Preview text appears here', 'designFirst' => 'Design your email to preview it',
            'confirmNow' => 'Send to %1 people now? It starts within a minute or two, and can be paused while it sends.',
            'confirmLater' => 'Schedule this campaign for %1 at %2 (%3)?',
            'confirmDelete' => 'Delete this campaign?',
            'confirmCancel' => 'Cancel this campaign? Nobody else gets it. Emails already sent stay sent.',
            'testTo' => 'Send a test to:', 'testSent' => 'Test sent to %1.', 'sending' => 'Sending…',
            'scheduled' => 'Campaign scheduled.', 'started' => 'Queued. Sending starts within a minute or two.',
            'unscheduled' => 'Editing put the campaign back to draft. Schedule it again when you are ready.',
            'readOnly' => 'This campaign has started, so it can no longer be edited. Duplicate it to send something like it again.',
            'viewResults' => 'See results on the Dashboard',
            'status_draft' => 'Draft', 'status_scheduled' => 'Scheduled', 'status_sending' => 'Sending',
            'status_sent' => 'Sent', 'status_paused' => 'Paused', 'status_cancelled' => 'Cancelled', 'status_failed' => 'Failed',
            'failed' => 'That did not work. Reload the page and try again.',
        ];

        foreach ($t as $key => $text) {
            $t[$key] = $this->__($text);
        }

        return $t;
    }
}
