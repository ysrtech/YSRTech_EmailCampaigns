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
    protected $_aclNode = 'automation';

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

            /*
             * The form has no box for minutes, so a step keeps the minutes
             * the canvas gave it. Carried here rather than left to the row
             * that happens to be reused, so the flow that is checked is the
             * flow that is stored.
             */
            $current = $model->getStepRows();

            foreach ($steps as $position => $step) {
                $steps[$position]['after_minutes'] = (int) ($current[$position]['after_minutes'] ?? 0);
            }

            /*
             * Through the same door as the canvas. The form's rows are drawn
             * as the chain they describe and that drawing is what gets
             * checked and saved, so the rules for going live and for changing
             * a live flow are one set of rules, in the model, and neither
             * editor can be used to get round the other.
             */
            $graph = Mage::getSingleton('ysrtech_emailcampaigns/automation_graph')->toGraph($event, $steps, [
                'order_status' => (string) ($data['order_status'] ?? ''),
                'product_id'   => (int) ($data['product_id'] ?? 0),
            ]);

            $result = $model->saveFlow($graph, [
                'name'                 => (string) ($data['name'] ?? ''),
                'cancel_on'            => ($data['cancel_on'] ?? 'never') === 'order_placed' ? 'order_placed' : 'never',
                'respect_subscription' => (int) !empty($data['respect_subscription']),
                'store_id'             => (int) ($data['store_id'] ?? 0),
                'active_from'          => !empty($data['active_from']) ? $data['active_from'] : null,
                'active_to'            => !empty($data['active_to']) ? $data['active_to'] : null,
            ], !empty($data['is_active']), false);

            if ($result['demoted']) {
                /*
                 * Saved, but not switched on. Said as an error and not a
                 * notice because the person asked for Active and did not get
                 * it, and a green "saved" above a flow that is quietly off is
                 * how a seasonal campaign fails to go out.
                 */
                $this->_getSession()->addError($this->__(
                    'Saved as a draft, not switched on: there is something to fix first.'
                ));

                foreach ($result['problems'] as $problem) {
                    $this->_getSession()->addError($problem['message']);
                }

                $this->_redirect('*/*/edit', ['id' => $model->getId()]);
                return;
            }

            $this->_getSession()->addSuccess($this->__('Automation saved.'));
        } catch (Mage_Core_Exception $e) {
            $this->_getSession()->addError($e->getMessage());
            $this->_redirect('*/*/edit', ['id' => $id]);
            return;
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
     * The visual editor, on the same automation the form edits.
     */
    public function canvasAction()
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

        $this->_title($model->getId() ? $model->getName() : $this->__('New Flow'));
        $this->loadLayout();
        $this->_setActiveMenu('ysrtech_emailcampaigns/automation');
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_automation_canvas'));
        $this->renderLayout();
    }

    /**
     * Save from the canvas.
     *
     * Answers JSON rather than redirecting: the editor posts with fetch() and
     * never navigates, which is also why the new automation's id goes back in
     * the response - without it every later save on a new flow would insert
     * another row instead of updating the one just made.
     *
     * What the canvas draws is what is written: the trigger and its
     * condition, the waits and the messages. The cancellation rule, the
     * marketing/transactional choice and the active dates have no nodes in
     * the editor, so they are left exactly as the form left them; a canvas
     * save must not quietly reset what it cannot show.
     *
     * Asking for "active" is asking to publish, and may be answered with a
     * saved draft and a list of problems instead. The response always says
     * which happened.
     */
    public function canvasSaveAction()
    {
        $this->getResponse()->setHeader('Content-Type', 'application/json', true);

        if (!$this->getRequest()->isPost()) {
            $this->_canvasAnswer(['success' => false, 'message' => $this->__('Expected a POST.')]);
            return;
        }

        if (!$this->_validateFormKey()) {
            $this->_canvasAnswer([
                'success' => false,
                'message' => $this->__('This page has been open a while. Reload it and try again.'),
            ]);
            return;
        }

        $data = $this->getRequest()->getPost();
        $id   = (int) $this->getRequest()->getParam('id');

        /** @var YSRTech_EmailCampaigns_Model_Automation $model */
        $model = Mage::getModel('ysrtech_emailcampaigns/automation');

        if ($id) {
            $model->load($id);

            if (!$model->getId()) {
                $this->_canvasAnswer(['success' => false, 'message' => $this->__('Automation no longer exists.')]);
                return;
            }
        }

        try {
            // Refuses anything that is not a drawing of a sane size before
            // any of it is looked at.
            $graph = Mage::getSingleton('ysrtech_emailcampaigns/automation_graph')
                ->decode($data['graph_json'] ?? null);

            $wantActive = is_string($data['status'] ?? null) && $data['status'] === 'active';

            $result = $model->saveFlow($graph, [
                'name'     => is_string($data['name'] ?? null) ? $data['name'] : '',
                'store_id' => max(0, min(65535, (int) ($data['store_id'] ?? 0))),
            ], $wantActive, true);

            if ($result['demoted']) {
                $message = $this->__(
                    'Saved as a draft, not switched on: %d thing(s) to fix first.',
                    count($result['problems'])
                );
            } elseif ($result['active']) {
                $message = $this->__('Saved. This flow is live.');
            } elseif ($result['paused']) {
                $message = $this->__('Paused. Nothing new will enter this flow; messages already queued are still sent.');
            } else {
                $message = $this->__('Draft saved.');
            }

            $this->_canvasAnswer([
                'success'         => true,
                'id'              => (int) $model->getId(),
                'status'          => $result['active'] ? 'active' : 'draft',
                'demoted'         => $result['demoted'],
                'version'         => (int) $model->getVersion(),
                'published_label' => $this->_publishedLabel($model),
                'steps'           => $result['steps'],
                'problems'        => $result['problems'],
                'message'         => $message,
            ]);
        } catch (Mage_Core_Exception $e) {
            $this->_canvasAnswer(['success' => false, 'message' => $e->getMessage()]);
        } catch (Exception $e) {
            Mage::logException($e);
            $this->_canvasAnswer(['success' => false, 'message' => $this->__('Could not save. See the exception log.')]);
        }
    }

    /**
     * @param  array $answer
     * @return void
     */
    protected function _canvasAnswer(array $answer): void
    {
        $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($answer));
    }

    /**
     * @param  YSRTech_EmailCampaigns_Model_Automation $model
     * @return string when it last went live, in the admin's locale, or ''
     */
    protected function _publishedLabel($model): string
    {
        return $model->getPublishedAt()
            ? (string) Mage::helper('core')->formatDate($model->getPublishedAt(), 'medium', true)
            : '';
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
