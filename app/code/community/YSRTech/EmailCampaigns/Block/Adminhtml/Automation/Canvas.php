<?php
/**
 * Hosts the visual automation editor.
 *
 * The editor is a built React Flow bundle, and everything it needs arrives
 * through one global that the template writes - it never calls back for
 * anything except to save. So this block's whole job is to assemble that
 * config: the automation as a graph, the templates that can be picked, and
 * the store views.
 *
 * The form editor is still there and still the only place some of an
 * automation can be set. The canvas draws what it understands - the trigger,
 * the waits, the messages - and the trigger's conditions, the cancellation
 * rule and the marketing/transactional choice stay on the form, because the
 * bundle has no nodes for them. Saving from the canvas leaves those alone.
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
     * The automation's steps drawn as a graph.
     */
    public function getGraphJson(): string
    {
        $automation = $this->getAutomation();
        $steps      = [];

        foreach ($automation->getSteps() as $step) {
            $steps[] = [
                'template_id'   => (int) $step->getTemplateId(),
                'after_days'    => (int) $step->getAfterDays(),
                'after_hours'   => (int) $step->getAfterHours(),
                'after_minutes' => (int) $step->getAfterMinutes(),
            ];
        }

        $graph = Mage::getSingleton('ysrtech_emailcampaigns/automation_graph')
            ->toGraph((string) $automation->getEvent(), $steps);

        return Mage::helper('core')->jsonEncode($graph);
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
