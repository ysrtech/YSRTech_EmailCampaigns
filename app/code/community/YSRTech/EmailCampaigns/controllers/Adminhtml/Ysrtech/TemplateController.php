<?php
/*
 * Magento 1 does not autoload controller classes - they sit outside the
 * autoloader's class path - so a controller extending another one has to
 * pull it in itself, or the class is simply not there when the router
 * instantiates this file.
 */
require_once Mage::getModuleDir('controllers', 'YSRTech_EmailCampaigns')
    . DS . 'Adminhtml' . DS . 'Controller' . DS . 'Abstract.php';

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

        /*
         * ?editor=1 used to render a drag and drop designer here. The block it
         * created pointed at a phtml that was never written, so the branch
         * produced a blank page. Anyone still holding such a url now gets the
         * normal edit form, which can do the job.
         */

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
