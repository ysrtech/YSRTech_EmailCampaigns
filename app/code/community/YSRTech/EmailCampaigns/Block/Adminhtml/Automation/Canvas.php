<?php
/**
 * Hosts the flow canvas.
 *
 * A page of its own rather than one inside the admin's frame. The canvas
 * (skin/.../flow/flow.js) is handed everything it needs as one object - the
 * automation as a graph, what can be picked in it, its settings, how each
 * message has fared - and after that only calls back to save.
 *
 * There is no separate settings form any more. What ends a flow early,
 * whether it counts as marketing, its store view and its dates are a panel
 * on the canvas.
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

    public function getSkinFile(string $file): string
    {
        $path = Mage::getBaseDir('skin') . '/adminhtml/default/default/ysrtech/emailcampaigns/flow/' . $file;

        // The file's own time as a version, so a deploy is seen at once
        return $this->getSkinUrl('ysrtech/emailcampaigns/flow/' . $file) . '?v=' . (is_file($path) ? filemtime($path) : 0);
    }

    /**
     * Everything the canvas starts with, as one object.
     */
    public function getConfig(): array
    {
        $automation = $this->getAutomation();
        $decode     = static fn (string $json) => json_decode($json, true);
        $graph      = $automation->getCanvasGraph();

        /** @var YSRTech_EmailCampaigns_Model_Automation_Graph $translator */
        $translator = Mage::getSingleton('ysrtech_emailcampaigns/automation_graph');

        try {
            $problems = array_map(
                static fn (array $problem): array => ['node' => $problem['node'], 'message' => $problem['message']],
                $automation->getId() ? $translator->validate($translator->normalise($graph), $automation->validationContext()) : []
            );
        } catch (Throwable $e) {
            $problems = [];
        }

        return [
            'id'             => (int) $automation->getId(),
            'name'           => (string) $automation->getName(),
            'status'         => $this->getStatus(),
            'version'        => max(1, (int) $automation->getVersion()),
            'publishedLabel' => $this->getPublishedLabel(),
            'storeId'        => (int) $automation->getStoreId(),
            'graph'          => $graph,
            'problems'       => $problems,
            'templates'      => $decode($this->getTemplatesJson()),
            'storeOptions'   => $decode($this->getStoreOptionsJson()),
            'events'         => $decode($this->getEventsJson()),
            'orderStatuses'  => $decode($this->getOrderStatusesJson()),
            'counts'         => json_decode($this->getCountsJson()) ?: new stdClass(),
            'limits'         => $decode($this->getLimitsJson()),
            'settings'       => [
                'cancel_on'            => (string) ($automation->getCancelOn() ?: 'never'),
                // A new flow is marketing until somebody says otherwise
                'respect_subscription' => $automation->getId() ? (bool) $automation->getRespectSubscription() : true,
                'active_from'          => substr((string) $automation->getActiveFrom(), 0, 10),
                'active_to'            => substr((string) $automation->getActiveTo(), 0, 10),
            ],
            'formKey'        => Mage::getSingleton('core/session')->getFormKey(),
            'urls'           => [
                'save'             => $this->getUrl('*/*/canvasSave', ['id' => '__ID__']),
                'canvas'           => $this->getCanvasUrlTemplate(),
                'back'             => $this->getUrl('*/*/index'),
                'templateDesigner' => $this->getUrl('*/ysrtech_template/designer', ['id' => '__ID__']),
                'templateNew'      => $this->getUrl('*/ysrtech_template/new'),
            ],
        ];
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
