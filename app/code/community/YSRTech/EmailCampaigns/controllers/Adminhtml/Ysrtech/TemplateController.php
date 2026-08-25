<?php
// Not autoloadable (lives under controllers/, which the classname-to-path
// convention doesn't cover), so the shared base class needs an explicit include.
require_once __DIR__ . '/../Controller/Abstract.php';

class YSRTech_EmailCampaigns_Adminhtml_Ysrtech_TemplateController
    extends YSRTech_EmailCampaigns_Adminhtml_Controller_Abstract
{
    public function indexAction()
    {
        $this->_title($this->__('Email Campaigns'))->_title($this->__('Templates'));
        $this->loadLayout();
        $this->_setActiveMenu('ysrtech_emailcampaigns/template');
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_template'));
        $this->renderLayout();
    }

    public function newAction()
    {
        $this->_forward('edit');
    }

    public function editAction()
    {
        $id = (int) $this->getRequest()->getParam('id');
        /** @var YSRTech_EmailCampaigns_Model_Template $model */
        $model = Mage::getModel('ysrtech_emailcampaigns/template');
        if ($id) {
            $model->load($id);
            if (!$model->getId()) {
                $this->_getSession()->addError($this->__('Template no longer exists.'));
                $this->_redirect('*/*/index');
                return;
            }
        }
        Mage::register('ysrtech_emailcampaigns_template', $model);

        // Editor mode loads the full-screen drag & drop editor.
        if ($this->getRequest()->getParam('editor')) {
            $this->_title($model->getName() ?: $this->__('Email Designer'));
            $this->loadLayout()
                ->_setActiveMenu('ysrtech_emailcampaigns/template')
                ->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_template_editor'))
                ->renderLayout();
            return;
        }

        $this->_title($model->getId() ? $model->getName() : $this->__('New Template'));
        $this->loadLayout();
        $this->_setActiveMenu('ysrtech_emailcampaigns/template');
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_template_edit'));
        $this->renderLayout();
    }

    public function saveAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->_redirect('*/*/index');
            return;
        }
        $data = $this->getRequest()->getPost();
        /** @var YSRTech_EmailCampaigns_Model_Template $model */
        $model = Mage::getModel('ysrtech_emailcampaigns/template');
        $id = (int) $this->getRequest()->getParam('id');
        if ($id) {
            $model->load($id);
        }
        try {
            $model->addData([
                'name'         => (string) ($data['name'] ?? ''),
                'subject'      => (string) ($data['subject'] ?? ''),
                'design_json'  => (string) ($data['design_json'] ?? ''),
                'html'         => (string) ($data['html'] ?? ''),
                'is_active'    => (int) (!empty($data['is_active'])),
            ]);
            $model->save();
            $this->_getSession()->addSuccess($this->__('Template saved.'));
        } catch (Exception $e) {
            $this->_getSession()->addError($e->getMessage());
            Mage::logException($e);
        }

        if ($this->getRequest()->getParam('back') === 'editor') {
            $this->_redirect('*/*/edit', ['id' => $model->getId(), 'editor' => 1]);
            return;
        }
        $this->_redirect('*/*/index');
    }

    public function deleteAction()
    {
        $id = (int) $this->getRequest()->getParam('id');
        try {
            Mage::getModel('ysrtech_emailcampaigns/template')->load($id)->delete();
            $this->_getSession()->addSuccess($this->__('Template deleted.'));
        } catch (Exception $e) {
            $this->_getSession()->addError($e->getMessage());
        }
        $this->_redirect('*/*/index');
    }

    public function gridAction()
    {
        $this->loadLayout();
        $this->getResponse()->setBody(
            $this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_template_grid')->toHtml()
        );
    }
}
