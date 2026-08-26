<?php
// Not autoloadable (lives under controllers/, which the classname-to-path
// convention doesn't cover), so the shared base class needs an explicit include.
require_once __DIR__ . '/../Controller/Abstract.php';

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
        // Without this flag core's base layout never adds mage/adminhtml/rules.js, so
        // VarienRulesForm (the JS that turns the rule field into the interactive tree) is
        // undefined and the "Add" link never appears — same flag catalog/cart price rule
        // edit pages set via their own layout XML.
        $this->getLayout()->getBlock('head')->setCanLoadRulesJs(true);
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
            $model->addData([
                'name'      => (string) ($data['name'] ?? ''),
                'is_active' => (int) (!empty($data['is_active'])),
            ]);
            // Conditions arrive from the rule builder form as rule[conditions]; loadPost()
            // is the same Mage_Rule entry point core's CatalogRule/SalesRule controllers use.
            if (isset($data['rule']['conditions'])) {
                $data['conditions'] = $data['rule']['conditions'];
                $model->loadPost($data);
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

    /**
     * Serves one condition row's HTML when the rule builder's "+" is clicked.
     * Same entry point core's own CatalogRule/SalesRule promo controllers use.
     */
    public function newConditionHtmlAction()
    {
        $id = $this->getRequest()->getParam('id');
        $typeArr = explode('|', str_replace('-', '/', $this->getRequest()->getParam('type')));
        $type = $typeArr[0];

        $model = Mage::getModel($type)
            ->setId($id)
            ->setType($type)
            ->setRule(Mage::getModel('ysrtech_emailcampaigns/segment'))
            ->setPrefix('conditions');
        if (!empty($typeArr[1])) {
            $model->setAttribute($typeArr[1]);
        }

        $html = $model instanceof Mage_Rule_Model_Condition_Abstract
            ? $model->setJsFormObject($this->getRequest()->getParam('form'))->asHtmlRecursive()
            : '';

        $this->getResponse()->setBody($html);
    }
}
