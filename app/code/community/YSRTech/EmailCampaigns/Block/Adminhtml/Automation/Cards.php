<?php
/**
 * The automations, as cards: what starts each one, a small picture of its
 * steps, whether it is on, and what it has done lately.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Automation_Cards extends Mage_Adminhtml_Block_Template
{
    /** @var array<int, array>|null */
    private $_cards;

    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('ysrtech_emailcampaigns/automation_cards.phtml');
    }

    public function getSearch(): string
    {
        return trim((string) $this->getRequest()->getParam('q'));
    }

    /**
     * @return array<int, array{id:int, name:string, active:bool, trigger:string, steps:array<int, array{type:string, label:string}>,
     *                          messages:int, sent:int, waiting:int, kind:string, stops:bool}>
     */
    public function getCards(): array
    {
        if ($this->_cards !== null) {
            return $this->_cards;
        }

        $h          = Mage::helper('ysrtech_emailcampaigns');
        $collection = Mage::getResourceModel('ysrtech_emailcampaigns/automation_collection')->setOrder('automation_id', 'DESC');

        if ($this->getSearch() !== '') {
            $collection->addFieldToFilter('name', ['like' => '%' . str_replace(['%', '_'], ['\\%', '\\_'], $this->getSearch()) . '%']);
        }

        $events = Mage::getSingleton('ysrtech_emailcampaigns/system_config_source_automation_event')->toArray();
        $ids    = $collection->getAllIds();
        $counts = [];

        if ($ids) {
            // What each has sent in the last thirty days and has waiting now,
            // in one query for the page
            $resource = Mage::getSingleton('core/resource');
            $adapter  = $resource->getConnection('core_read');

            foreach ($adapter->fetchAll(
                $adapter->select()
                    ->from($resource->getTableName('ysrtech_emailcampaigns/queue'), [
                        'automation_id',
                        'sent'    => new Zend_Db_Expr($adapter->quoteInto("SUM(status = 'sent' AND sent_at >= ?)", gmdate('Y-m-d H:i:s', time() - 30 * 86400))),
                        'waiting' => new Zend_Db_Expr("SUM(status IN ('pending', 'sending'))"),
                    ])
                    ->where('automation_id IN (?)', $ids)
                    ->group('automation_id')
            ) as $row) {
                $counts[(int) $row['automation_id']] = ['sent' => (int) $row['sent'], 'waiting' => (int) $row['waiting']];
            }
        }

        /** @var YSRTech_EmailCampaigns_Model_Automation_Graph $translator */
        $translator = Mage::getSingleton('ysrtech_emailcampaigns/automation_graph');
        $this->_cards = [];

        foreach ($collection as $automation) {
            $steps    = [];
            $messages = 0;

            try {
                $graph = $automation->getCanvasGraph();
                $byId  = array_column($graph['nodes'], null, 'id');
                $next  = array_column($graph['edges'], 'target', 'source');
                $at    = null;

                foreach ($graph['nodes'] as $node) {
                    if ($node['type'] === $translator::NODE_TRIGGER) {
                        $at = $node['id'];
                        break;
                    }
                }

                // The drawing walked from its trigger, as the canvas walks it
                for ($guard = 0; $at !== null && isset($next[$at]) && isset($byId[$next[$at]]) && $guard < 60; $guard++) {
                    $at   = $next[$at];
                    $node = $byId[$at];

                    if ($node['type'] === $translator::NODE_DELAY) {
                        $unit    = (string) $node['config']['unit'];
                        $steps[] = ['type' => 'wait', 'label' => (int) $node['config']['value'] . ' ' . $h->__((int) $node['config']['value'] === 1 ? rtrim($unit, 's') : $unit)];
                    } else {
                        $steps[] = ['type' => 'email', 'label' => $h->__('Email')];
                        $messages++;
                    }
                }
            } catch (Throwable $e) {
                $steps = [];
            }

            $id = (int) $automation->getId();

            $this->_cards[] = [
                'id'       => $id,
                'name'     => (string) $automation->getName(),
                'active'   => (bool) $automation->getIsActive(),
                'trigger'  => (string) ($events[$automation->getEvent()] ?? $automation->getEvent()),
                'steps'    => $steps,
                'messages' => $messages,
                'sent'     => $counts[$id]['sent'] ?? 0,
                'waiting'  => $counts[$id]['waiting'] ?? 0,
                'kind'     => (int) $automation->getRespectSubscription() ? $h->__('Marketing') : $h->__('Transactional'),
                'stops'    => $automation->getCancelOn() === 'order_placed',
            ];
        }

        return $this->_cards;
    }

    /**
     * A small picture of a flow: a pill for the trigger, a box a step, joined
     * left to right, as on the canvas.
     *
     * @param array<int, array{type:string, label:string}> $steps
     */
    public function getMap(array $steps): string
    {
        $shown = array_slice($steps, 0, 7);
        $more  = count($steps) - count($shown);
        $w     = 34;
        $gap   = 14;
        $x     = 4;
        $svg   = '<rect x="' . $x . '" y="12" width="' . $w . '" height="20" rx="10" fill="#eeeefe" stroke="#5b5bf7" stroke-opacity=".5"/>';

        foreach ($shown as $step) {
            $from = $x + $w;
            $x   += $w + $gap;
            $svg .= '<path d="M' . $from . ',22 L' . $x . ',22" stroke="#d4d4d8" stroke-width="1.5"/>'
                . '<rect x="' . $x . '" y="12" width="' . $w . '" height="20" rx="5" fill="' . ($step['type'] === 'wait' ? '#fef5e6' : '#eaf1fe')
                . '" stroke="' . ($step['type'] === 'wait' ? '#b45309' : '#2563eb') . '" stroke-opacity=".5"><title>' . $this->escapeHtml($step['label']) . '</title></rect>';
        }

        if ($more > 0) {
            $svg .= '<text x="' . ($x + $w + 8) . '" y="26" font-size="11" fill="#8a8a93" font-family="sans-serif">+' . $more . '</text>';
        }

        $width = $x + $w + ($more > 0 ? 34 : 4);

        return '<svg viewBox="0 0 ' . $width . ' 44" width="' . $width . '" height="44" role="img" aria-label="'
            . $this->escapeHtml($this->__('%d step(s)', count($steps))) . '">' . $svg . '</svg>';
    }
}
