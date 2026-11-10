<?php
/**
 * Hosts the visual automation editor.
 *
 * The editor is a built React Flow bundle (source in frontend/src/flowBuilder),
 * and everything it needs arrives through one global that the template
 * writes - it never calls back for anything except to save. So this block's
 * whole job is to assemble that config: the automation as a graph, what can
 * be picked in it, how each message has fared, and the editor's own strings
 * in the admin's language.
 *
 * The form editor is still there and still the only place some of an
 * automation can be set. The canvas draws what it understands - the trigger
 * and its condition, the waits, the messages - and the cancellation rule,
 * the marketing/transactional choice and the dates stay on the form, because
 * the canvas has no nodes for them. Saving from the canvas leaves those alone.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Automation_Canvas extends Mage_Adminhtml_Block_Template
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('ysrtech_emailcampaigns/automation_canvas.phtml');
    }

    /**
     * @return YSRTech_EmailCampaigns_Model_Automation
     */
    public function getAutomation()
    {
        return Mage::registry('ysrtech_emailcampaigns_automation')
            ?: Mage::getModel('ysrtech_emailcampaigns/automation');
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('*/*/canvasSave', ['id' => $this->getAutomation()->getId()]);
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('*/*/edit', ['id' => $this->getAutomation()->getId()]);
    }

    /**
     * Where a flow that has just been saved for the first time now lives.
     * The page does not navigate on save, so it rewrites its own address
     * with this - otherwise a reload would open another new flow.
     */
    public function getCanvasUrlTemplate(): string
    {
        return $this->getUrl('*/*/canvas', ['id' => '__ID__']);
    }

    /**
     * The automation drawn as a graph: the drawing it was last saved with
     * when that still matches its steps, and one made from the steps when
     * not.
     */
    public function getGraphJson(): string
    {
        return Mage::helper('core')->jsonEncode($this->getAutomation()->getCanvasGraph());
    }

    /**
     * The triggers a flow can start from - the module's own list, so the
     * canvas cannot offer one the observers do not fire.
     */
    public function getEventsJson(): string
    {
        return Mage::helper('core')->jsonEncode(
            Mage::getSingleton('ysrtech_emailcampaigns/system_config_source_automation_event')->toOptionArray()
        );
    }

    /**
     * Order statuses, for the "order reached a status" trigger.
     */
    public function getOrderStatusesJson(): string
    {
        $statuses = [];

        foreach (Mage::getSingleton('sales/order_config')->getStatuses() as $code => $label) {
            $statuses[] = ['value' => (string) $code, 'label' => (string) $label];
        }

        return Mage::helper('core')->jsonEncode($statuses);
    }

    /**
     * How each message of the chain has fared, keyed by its position.
     *
     * By position and not by step id: step rows are rewritten when a flow is
     * edited, and the position is what a queue row keeps. One grouped query
     * over the automation's rows, which the duplicate guard's index covers.
     *
     * "Waiting" is pending and sending together - a row being sent this
     * minute has not been sent yet, and a separate number that is almost
     * always zero would only be noise on the node.
     *
     * @return string {"0": {sent, waiting, failed, cancelled}, ...}
     */
    public function getCountsJson(): string
    {
        $automation = $this->getAutomation();
        $counts     = [];

        if ($automation->getId()) {
            $resource = Mage::getSingleton('core/resource');
            $adapter  = $resource->getConnection('core_read');

            $rows = $adapter->fetchAll(
                $adapter->select()
                    ->from(
                        $resource->getTableName('ysrtech_emailcampaigns/queue'),
                        ['step_order', 'status', 'total' => new Zend_Db_Expr('COUNT(*)')]
                    )
                    ->where('automation_id = ?', (int) $automation->getId())
                    ->where('step_order IS NOT NULL')
                    ->group(['step_order', 'status'])
            );

            $bucket = ['pending' => 'waiting', 'sending' => 'waiting', 'sent' => 'sent',
                       'failed'  => 'failed', 'cancelled' => 'cancelled'];

            foreach ($rows as $row) {
                $position = (int) $row['step_order'];

                if (!isset($counts[$position])) {
                    $counts[$position] = ['sent' => 0, 'waiting' => 0, 'failed' => 0, 'cancelled' => 0];
                }

                if (isset($bucket[$row['status']])) {
                    $counts[$position][$bucket[$row['status']]] += (int) $row['total'];
                }
            }
        }

        // An object even when empty: [] would arrive as a JavaScript array
        return json_encode((object) $counts);
    }

    /**
     * The bounds the canvas warns about, from the class that enforces them.
     */
    public function getLimitsJson(): string
    {
        $graph = YSRTech_EmailCampaigns_Model_Automation_Graph::class;

        return Mage::helper('core')->jsonEncode([
            'maxSends'        => $graph::MAX_SENDS,
            'maxDelayMinutes' => $graph::MAX_DELAY_MINUTES,
        ]);
    }

    /**
     * When the flow last went live, in the admin's locale.
     */
    public function getPublishedLabel(): string
    {
        $publishedAt = $this->getAutomation()->getPublishedAt();

        return $publishedAt ? (string) Mage::helper('core')->formatDate($publishedAt, 'medium', true) : '';
    }

    /**
     * The canvas's own strings, translated.
     *
     * The bundle looks each of its English strings up in this map and falls
     * back to the English, so a string missing here is untranslated rather
     * than broken. Placeholders are left for the bundle to fill. The
     * validation messages are worded exactly as Model/Automation/Graph.php
     * words them, so a problem reads the same whichever side found it.
     */
    public function getTranslationsJson(): string
    {
        $h   = Mage::helper('ysrtech_emailcampaigns');
        $map = [];

        foreach ([
            '%d problems',
            '1 problem',
            'A connection leads to a step that is not in the flow.',
            'A flow can send %d messages at most.',
            'A flow starts from one trigger. Remove this one.',
            'A wait has to be at least 1.',
            'Active',
            'Add a step after this one',
            'Add a step here',
            'Add a step',
            'All changes saved.',
            'Cancelled',
            'Choose a status…',
            'Choose a template…',
            'Choose a trigger…',
            'Choose the template this step sends.',
            'Choose what starts this flow.',
            'Choose which order status starts this flow.',
            'Could not save: %s',
            'Counted for the message at this position in the saved flow.',
            'Counted from the step before, as drawn.',
            'Discard',
            'Dismiss',
            'Draft',
            'Drag onto the canvas, or use a + on the chain.',
            'Every flow starts from one trigger, so this step cannot be removed.',
            'Failed',
            'Fires when the order changes into this status, not on every later save.',
            'Flow name',
            'Gave up after retrying',
            'Give the id of the product this flow watches for.',
            'Live · version %d',
            'Messages already queued are still sent, as they were when queued.',
            'Messages this step has sent',
            'Name',
            'Not saved yet.',
            'Nothing follows this wait. Add a message after it or remove it.',
            'Nothing to fix. This flow can go live.',
            'Only one connection can leave a step. Flows here do not branch.',
            'Pause',
            'Pausing…',
            'Product #%d',
            'Product ID',
            'Queued and not yet due, or being sent now',
            'Remove this step',
            'Restore',
            'Save Draft',
            'Save and Activate',
            'Saving…',
            'Select a step to change it. Use the + on a connection to put a step between two others, or the + under the last step to carry on.',
            'Send Email',
            'Sent',
            'Show what needs fixing',
            'Starts when',
            'Status',
            'Store view',
            'Template removed',
            'Template',
            'The flow has no trigger, so nothing would ever start it.',
            'The flow loops back on itself here, so it would never end.',
            'The flow sends nothing yet. Add a Send Email step after the trigger.',
            'The server did not answer as expected. Your session may have ended; reload the page.',
            'The template this step used no longer exists. Choose another.',
            'The waits add up to more than %d days.',
            'This browser has changes to this flow from %s that were never saved.',
            'This flow is live. Pause it to make changes.',
            'This flow',
            'This step is not connected to the chain from the trigger, so it would never run.',
            'Tidy up',
            'To fix before this can go live:',
            'Trigger',
            'Unsaved changes - kept in this browser until you save.',
            'Version %d, switched on %s.',
            'Wait for',
            'Wait',
            'Waiting',
            'Which status',
            'days',
            'failed',
            'hours',
            'minutes',
            'sent',
            'waiting',
        ] as $text) {
            $map[$text] = $h->__($text);
        }

        return Mage::helper('core')->jsonEncode($map);
    }

    /**
     * Templates the editor offers in a send node. id and name only: it is a
     * dropdown, and the bodies are large.
     */
    public function getTemplatesJson(): string
    {
        $templates = [];

        foreach (Mage::getResourceModel('ysrtech_emailcampaigns/template_collection') as $template) {
            $templates[] = [
                'id'   => (int) $template->getId(),
                'name' => (string) $template->getName(),
            ];
        }

        return Mage::helper('core')->jsonEncode($templates);
    }

    public function getStoreOptionsJson(): string
    {
        return Mage::helper('core')->jsonEncode(
            Mage::getSingleton('adminhtml/system_store')->getStoreValuesForForm(false, true)
        );
    }

    /**
     * 'active' or 'draft', which is what the editor's selector speaks, from
     * the is_active flag this module keeps.
     */
    public function getStatus(): string
    {
        return $this->getAutomation()->getIsActive() ? 'active' : 'draft';
    }
}
