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
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_automation_cards'));
        $this->renderLayout();
    }

    /**
     * A new flow opens straight on the canvas. Nothing is stored until
     * something is put on it.
     */
    public function newAction()
    {
        $this->_redirect('*/*/canvas');
    }

    /**
     * There is no separate form any more: a flow is made, and its settings
     * set, on the canvas.
     */
    public function editAction()
    {
        $id = (int) $this->getRequest()->getParam('id');

        $this->_redirect('*/*/canvas', $id ? ['id' => $id] : []);
    }

    /**
     * The flow canvas.
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

        // A page of its own: the canvas is not drawn inside the admin's frame
        $this->getResponse()->setBody(
            $this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_automation_canvas')->toHtml()
        );
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

            $fields = [
                'name'     => is_string($data['name'] ?? null) ? $data['name'] : '',
                'store_id' => max(0, min(65535, (int) ($data['store_id'] ?? 0))),
            ];

            // The flow's settings, which the canvas now carries. Only what
            // was sent is written: a page that sends none changes none.
            if (isset($data['cancel_on'])) {
                $fields['cancel_on'] = $data['cancel_on'] === 'order_placed' ? 'order_placed' : 'never';
            }

            if (isset($data['respect_subscription'])) {
                $fields['respect_subscription'] = (int) !empty($data['respect_subscription']);
            }

            foreach (['active_from', 'active_to'] as $date) {
                if (isset($data[$date])) {
                    $fields[$date] = is_string($data[$date]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data[$date]) ? $data[$date] : null;
                }
            }

            if (!empty($fields['active_from']) && !empty($fields['active_to']) && $fields['active_to'] < $fields['active_from']) {
                Mage::throwException($this->__('"Runs until" is before "Runs from".'));
            }

            $result = $model->saveFlow($graph, $fields, $wantActive, true);

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

        if (!$this->getRequest()->isPost() || !$this->_validateFormKey()) {
            $this->_redirect('*/*/index');

            return;
        }

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
}
