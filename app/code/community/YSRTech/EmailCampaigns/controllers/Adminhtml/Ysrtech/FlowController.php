<?php
require_once __DIR__ . '/../Controller/Abstract.php';

class YSRTech_EmailCampaigns_Adminhtml_Ysrtech_FlowController
    extends YSRTech_EmailCampaigns_Adminhtml_Controller_Abstract
{
    public function indexAction()
    {
        $this->_title($this->__('Email Campaigns'))->_title($this->__('Automation Flows'));
        $this->loadLayout();
        $this->_setActiveMenu('ysrtech_emailcampaigns/flow');
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_flow'));
        $this->renderLayout();
    }

    public function newAction()
    {
        $this->_forward('edit');
    }

    public function editAction()
    {
        $id = (int) $this->getRequest()->getParam('id');
        /** @var YSRTech_EmailCampaigns_Model_Flow $model */
        $model = Mage::getModel('ysrtech_emailcampaigns/flow');
        if ($id) {
            $model->load($id);
            if (!$model->getId()) {
                $this->_getSession()->addError($this->__('Flow no longer exists.'));
                $this->_redirect('*/*/index');
                return;
            }
        }
        Mage::register('ysrtech_emailcampaigns_flow', $model);

        $this->_title($model->getName() ?: $this->__('New Flow'));
        $this->loadLayout()
            ->_setActiveMenu('ysrtech_emailcampaigns/flow')
            ->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_flow_editor'))
            ->renderLayout();
    }

    /**
     * The canvas saves via fetch(), not a form POST, and never navigates —
     * so a brand-new flow's save URL still has id=0 baked in from page load.
     * Responding with the assigned id (rather than a redirect the fetch call
     * would just discard) lets the client remember it and update the same
     * row on every subsequent save instead of inserting a duplicate each time.
     */
    public function saveAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->_redirect('*/*/index');
            return;
        }
        $data = $this->getRequest()->getPost();
        /** @var YSRTech_EmailCampaigns_Model_Flow $model */
        $model = Mage::getModel('ysrtech_emailcampaigns/flow');
        $id = (int) $this->getRequest()->getParam('id');
        if ($id) {
            $model->load($id);
        }
        try {
            $status = (string) ($data['status'] ?? YSRTech_EmailCampaigns_Model_Flow::STATUS_DRAFT);
            if (!in_array($status, [
                YSRTech_EmailCampaigns_Model_Flow::STATUS_DRAFT,
                YSRTech_EmailCampaigns_Model_Flow::STATUS_ACTIVE,
                YSRTech_EmailCampaigns_Model_Flow::STATUS_PAUSED,
            ], true)) {
                $status = YSRTech_EmailCampaigns_Model_Flow::STATUS_DRAFT;
            }
            $model->addData([
                'name'         => (string) ($data['name'] ?? ''),
                'status'       => $status,
                'trigger_type' => (string) ($data['trigger_type'] ?? 'order_placed'),
                'store_id'     => (int) ($data['store_id'] ?? 0),
                'graph_json'   => (string) ($data['graph_json'] ?? ''),
            ]);
            $model->save();
            $this->getResponse()->setBody(Mage::helper('core')->jsonEncode([
                'success' => true,
                'id'      => (int) $model->getId(),
            ]));
        } catch (Exception $e) {
            Mage::logException($e);
            $this->getResponse()->setBody(Mage::helper('core')->jsonEncode([
                'success' => false,
                'message' => $e->getMessage(),
            ]));
        }
    }

    public function deleteAction()
    {
        $id = (int) $this->getRequest()->getParam('id');
        try {
            Mage::getModel('ysrtech_emailcampaigns/flow')->load($id)->delete();
            $this->_getSession()->addSuccess($this->__('Flow deleted.'));
        } catch (Exception $e) {
            $this->_getSession()->addError($e->getMessage());
        }
        $this->_redirect('*/*/index');
    }

    public function gridAction()
    {
        $this->loadLayout();
        $this->getResponse()->setBody(
            $this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_flow_grid')->toHtml()
        );
    }
}
