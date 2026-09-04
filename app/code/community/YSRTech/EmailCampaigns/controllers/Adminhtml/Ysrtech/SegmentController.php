<?php
/*
 * Magento 1 does not autoload controller classes - they sit outside the
 * autoloader's class path - so a controller extending another one has to
 * pull it in itself, or the class is simply not there when the router
 * instantiates this file.
 */
require_once Mage::getModuleDir('controllers', 'YSRTech_EmailCampaigns')
    . DS . 'Adminhtml' . DS . 'Controller' . DS . 'Abstract.php';

class YSRTech_EmailCampaigns_Adminhtml_Ysrtech_SegmentController
    extends YSRTech_EmailCampaigns_Adminhtml_Controller_Abstract
{
    public function indexAction()
    {
        $this->_title($this->__('Email Campaigns'))->_title($this->__('Segments'));
        $this->loadLayout();
        $this->_setActiveMenu('ysrtech_emailcampaigns/segment');
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_segment'));
        $this->renderLayout();
    }

    public function newAction()
    {
        $this->_forward('edit');
    }

    public function editAction()
    {
        $id = (int) $this->getRequest()->getParam('id');
        /** @var YSRTech_EmailCampaigns_Model_Segment $model */
        $model = Mage::getModel('ysrtech_emailcampaigns/segment');
        if ($id) {
            $model->load($id);
            if (!$model->getId()) {
                $this->_getSession()->addError($this->__('Segment no longer exists.'));
                $this->_redirect('*/*/index');
                return;
            }
        }
        Mage::register('ysrtech_emailcampaigns_segment', $model);

        $this->_title($model->getId() ? $model->getName() : $this->__('New Segment'));
        $this->loadLayout();
        $this->_setActiveMenu('ysrtech_emailcampaigns/segment');
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_segment_edit'));
        $this->renderLayout();
    }

    public function saveAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->_redirect('*/*/index');
            return;
        }
        $data = $this->getRequest()->getPost();
        /** @var YSRTech_EmailCampaigns_Model_Segment $model */
        $model = Mage::getModel('ysrtech_emailcampaigns/segment');
        $id = (int) $this->getRequest()->getParam('id');
        if ($id) {
            $model->load($id);
        }
        try {
            // Conditions arrive in Mage_Rule serialized format from the rule builder form.
            $model->addData([
                'name'      => (string) ($data['name'] ?? ''),
                'is_active' => (int) (!empty($data['is_active'])),
            ]);
            if (isset($data['rule']['conditions'])) {
                $model->setData('conditions', $data['rule']['conditions']);
                $model->setData('conditions_serialized', json_encode($data['rule']['conditions']));
            }
            $model->save();

            if (!empty($data['reindex'])) {
                $count = $model->reindex();
                $this->_getSession()->addSuccess(
                    $this->__('Segment saved. %d customers matched.', $count)
                );
            } else {
                $this->_getSession()->addSuccess($this->__('Segment saved.'));
            }
        } catch (Exception $e) {
            $this->_getSession()->addError($e->getMessage());
            Mage::logException($e);
        }
        $this->_redirect('*/*/index');
    }

    public function reindexAction()
    {
        $id = (int) $this->getRequest()->getParam('id');
        try {
            $count = Mage::getModel('ysrtech_emailcampaigns/segment')->load($id)->reindex();
            $this->_getSession()->addSuccess($this->__('Reindexed: %d customers matched.', $count));
        } catch (Exception $e) {
            $this->_getSession()->addError($e->getMessage());
        }
        $this->_redirect('*/*/index');
    }

    public function deleteAction()
    {
        $id = (int) $this->getRequest()->getParam('id');
        try {
            Mage::getModel('ysrtech_emailcampaigns/segment')->load($id)->delete();
            $this->_getSession()->addSuccess($this->__('Segment deleted.'));
        } catch (Exception $e) {
            $this->_getSession()->addError($e->getMessage());
        }
        $this->_redirect('*/*/index');
    }

    public function gridAction()
    {
        $this->loadLayout();
        $this->getResponse()->setBody(
            $this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_segment_grid')->toHtml()
        );
    }
}
