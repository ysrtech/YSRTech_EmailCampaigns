<?php
/*
 * Magento 1 does not autoload controller classes, so a controller extending
 * another one has to pull it in itself.
 */
require_once Mage::getModuleDir('controllers', 'YSRTech_EmailCampaigns')
    . DS . 'Adminhtml' . DS . 'Controller' . DS . 'Abstract.php';

class YSRTech_EmailCampaigns_Adminhtml_Ysrtech_AutomationController
    extends YSRTech_EmailCampaigns_Adminhtml_Controller_Abstract
{
    public function indexAction()
    {
        $this->_title($this->__('Email Campaigns'))->_title($this->__('Automations'));
        $this->loadLayout();
        $this->_setActiveMenu('ysrtech_emailcampaigns/automation');
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_automation'));
        $this->renderLayout();
    }

    public function newAction()
    {
        $this->_forward('edit');
    }

    public function editAction()
    {
        $id = (int) $this->getRequest()->getParam('id');
        /** @var YSRTech_EmailCampaigns_Model_Automation $model */
        $model = Mage::getModel('ysrtech_emailcampaigns/automation');

        if ($id) {
            $model->load($id);

            if (!$model->getId()) {
                $this->_getSession()->addError($this->__('Automation no longer exists.'));
                $this->_redirect('*/*/index');
                return;
            }
        }

        Mage::register('ysrtech_emailcampaigns_automation', $model);

        $this->_title($model->getId() ? $model->getName() : $this->__('New Automation'));
        $this->loadLayout();
        $this->_setActiveMenu('ysrtech_emailcampaigns/automation');
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_automation_edit'));
        $this->renderLayout();
    }

    public function saveAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->_redirect('*/*/index');
            return;
        }

        $this->_validateFormKey();

        $data = $this->getRequest()->getPost();
        $id   = (int) $this->getRequest()->getParam('id');

        /** @var YSRTech_EmailCampaigns_Model_Automation $model */
        $model = Mage::getModel('ysrtech_emailcampaigns/automation');

        if ($id) {
            $model->load($id);
        }

        try {
            $source = YSRTech_EmailCampaigns_Model_System_Config_Source_Automation_Event::class;
            $event  = (string) ($data['event'] ?? '');

            if ($event === $source::ORDER_STATUS && empty($data['order_status'])) {
                Mage::throwException($this->__('Choose which status should trigger this.'));
            }

            if ($event === $source::PRODUCT_PURCHASED && empty($data['product_id'])) {
                Mage::throwException($this->__('Give the product id this should watch for.'));
            }

            $steps = $this->_readSteps($data['steps'] ?? []);

            if (!$steps) {
                Mage::throwException($this->__('A chain needs at least one message.'));
            }

            $model->addData([
                'name'                 => (string) ($data['name'] ?? ''),
                'event'                => $event,
                'order_status'         => (string) ($data['order_status'] ?? '') ?: null,
                'product_id'           => !empty($data['product_id']) ? (int) $data['product_id'] : null,
                'cancel_on'            => ($data['cancel_on'] ?? 'never') === 'order_placed' ? 'order_placed' : 'never',
                'respect_subscription' => (int) !empty($data['respect_subscription']),
                'store_id'             => (int) ($data['store_id'] ?? 0),
                'active_from'          => !empty($data['active_from']) ? $data['active_from'] : null,
                'active_to'            => !empty($data['active_to']) ? $data['active_to'] : null,
                'is_active'            => (int) !empty($data['is_active']),
            ]);

            $model->save();

            $this->_saveSteps($model, $steps);

            $this->_getSession()->addSuccess($this->__('Automation saved.'));
        } catch (Exception $e) {
            $this->_getSession()->addError($e->getMessage());
            Mage::logException($e);
            $this->_redirect('*/*/edit', ['id' => $id]);
            return;
        }

        $this->_redirect('*/*/index');
    }

    /**
     * @param  mixed $posted
     * @return array
     */
    protected function _readSteps($posted): array
    {
        $steps = [];

        foreach ((array) $posted as $row) {
            if (empty($row['template_id'])) {
                continue;
            }

            $steps[] = [
                'template_id' => (int) $row['template_id'],
                'after_days'  => max(0, (int) ($row['after_days'] ?? 0)),
                'after_hours' => max(0, (int) ($row['after_hours'] ?? 0)),
            ];
        }

        // Earliest first, whatever order the rows were typed in
        usort(
            $steps,
            static fn($a, $b) => ($a['after_days'] * 24 + $a['after_hours']) <=> ($b['after_days'] * 24 + $b['after_hours'])
        );

        return $steps;
    }

    /**
     * Rewrite the chain's steps from the form.
     *
     * Steps that survive keep their ids, because queue rows point at them: a
     * delete-and-recreate would cascade away everything already waiting to be
     * sent, silently emptying a chain that was halfway through for hundreds of
     * people.
     *
     * @param  YSRTech_EmailCampaigns_Model_Automation $model
     * @param  array                                   $steps
     * @return void
     */
    protected function _saveSteps($model, array $steps): void
    {
        $existing = [];

        foreach (Mage::getResourceModel('ysrtech_emailcampaigns/automation_step_collection')
                     ->addAutomationFilter((int) $model->getId()) as $step) {
            $existing[] = $step;
        }

        foreach ($steps as $position => $values) {
            $step = array_shift($existing) ?: Mage::getModel('ysrtech_emailcampaigns/automation_step');

            $step->addData($values)
                ->setAutomationId($model->getId())
                ->setSortOrder($position)
                ->save();
        }

        // Anything left over was removed from the form
        foreach ($existing as $step) {
            $step->delete();
        }
    }

    public function deleteAction()
    {
        $id = (int) $this->getRequest()->getParam('id');

        try {
            /*
             * Queued rows go with it, by the cascade on the foreign key.
             * Unlike a segment, that is the right outcome: a message waiting
             * to be sent by a rule nobody wants any more should not arrive.
             */
            Mage::getModel('ysrtech_emailcampaigns/automation')->load($id)->delete();
            $this->_getSession()->addSuccess($this->__('Automation deleted, along with anything it had queued.'));
        } catch (Exception $e) {
            $this->_getSession()->addError($e->getMessage());
        }

        $this->_redirect('*/*/index');
    }

    public function gridAction()
    {
        $this->loadLayout();
        $this->getResponse()->setBody(
            $this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_automation_grid')->toHtml()
        );
    }
}
