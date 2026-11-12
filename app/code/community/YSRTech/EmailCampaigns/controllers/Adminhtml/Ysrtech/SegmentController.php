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
    protected $_aclNode = 'segment';

    public function indexAction()
    {
        $this->_title($this->__('Email Campaigns'))->_title($this->__('Segments'));
        $this->loadLayout();
        $this->_setActiveMenu('ysrtech_emailcampaigns/segment');
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_segment'));
        $this->renderLayout();
    }

    /**
     * A segment, opened: the subscribers in it.
     */
    public function viewAction()
    {
        $model = $this->_initSegment();

        if (!$model) {
            return;
        }

        $this->_title($this->__('Email Campaigns'))->_title($this->__('Segments'))->_title($model->getName());
        $this->loadLayout();
        $this->_setActiveMenu('ysrtech_emailcampaigns/segment');
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_segment_view'));
        $this->renderLayout();
    }

    public function membersGridAction()
    {
        if (!$this->_initSegment()) {
            return;
        }

        $this->loadLayout();
        $this->getResponse()->setBody(
            $this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_segment_view_grid')->toHtml()
        );
    }

    public function exportMembersCsvAction()
    {
        $model = $this->_initSegment();

        if (!$model) {
            return;
        }

        $this->_prepareDownloadResponse(
            'segment-' . (int) $model->getId() . '-subscribers.csv',
            $this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_segment_view_grid')->getCsvFile()
        );
    }

    /**
     * What the rules in the editor would match right now, before saving.
     */
    public function previewAction()
    {
        $result = ['ok' => false];

        try {
            $data = $this->getRequest()->getPost();

            /** @var YSRTech_EmailCampaigns_Model_Segment $model */
            $model = Mage::getModel('ysrtech_emailcampaigns/segment');
            $model->loadPost(['conditions' => $data['rule']['conditions'] ?? []]);

            $result = ['ok' => true] + $model->preview();
        } catch (Exception $e) {
            Mage::logException($e);
            $result['message'] = $this->__('Could not work out who matches these rules.');
        }

        $this->getResponse()
            ->setHeader('Content-Type', 'application/json', true)
            ->setBody(Mage::helper('core')->jsonEncode($result));
    }

    /**
     * @return YSRTech_EmailCampaigns_Model_Segment|null
     */
    protected function _initSegment()
    {
        /** @var YSRTech_EmailCampaigns_Model_Segment $model */
        $model = Mage::getModel('ysrtech_emailcampaigns/segment')->load((int) $this->getRequest()->getParam('id'));

        if (!$model->getId()) {
            $this->_getSession()->addError($this->__('Segment no longer exists.'));
            $this->_redirect('*/*/index');

            return null;
        }

        Mage::register('ysrtech_emailcampaigns_segment', $model);

        return $model;
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

    /**
     * The rule builder asks for each newly added condition row over ajax. The
     * form pointed the "+" button here, but the action did not exist, so
     * adding a condition returned the admin 404 page.
     */
    public function newConditionHtmlAction()
    {
        $id = $this->getRequest()->getParam('id');

        $type = explode('|', str_replace('-', '/', $this->getRequest()->getParam('type')));

        $model = Mage::getModel(strtolower($type[0]))
            ->setId($id)
            ->setType($type[0])
            ->setRule(Mage::getModel('ysrtech_emailcampaigns/segment'))
            ->setPrefix('conditions');

        if (!empty($type[1])) {
            $model->setAttribute($type[1]);
        }

        if ($model instanceof Mage_Rule_Model_Condition_Abstract) {
            $model->setJsFormObject($this->getRequest()->getParam('form'));
            $this->getResponse()->setBody($model->asHtmlRecursive());
        } else {
            $this->getResponse()->setBody('');
        }
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

            /*
             * loadPost() is what the rule builder's flat rule[conditions][...]
             * post is shaped for: it rebuilds the condition tree, and the
             * model's own _beforeSave() then serializes it into
             * conditions_serialized. Writing that column here by hand fed JSON
             * to an unserialize() and the save died on it.
             */
            $model->loadPost(['conditions' => $data['rule']['conditions'] ?? []]);
            $model->save();

            if (!empty($data['reindex'])) {
                $count = $model->reindex();
                $this->_getSession()->addSuccess(
                    $this->__('Segment saved. %d subscribers matched.', $count)
                );
            } else {
                $this->_getSession()->addSuccess($this->__('Segment saved.'));
            }
        } catch (Exception $e) {
            $this->_getSession()->addError($e->getMessage());
            Mage::logException($e);
        }

        // Back to the segment itself, so what was just saved can be seen
        if ($model->getId()) {
            $this->_redirect('*/*/view', ['id' => $model->getId()]);
        } else {
            $this->_redirect('*/*/index');
        }
    }

    public function reindexAction()
    {
        $id = (int) $this->getRequest()->getParam('id');
        try {
            $count = Mage::getModel('ysrtech_emailcampaigns/segment')->load($id)->reindex();
            $this->_getSession()->addSuccess($this->__('Recalculated: %d subscribers matched.', $count));
        } catch (Exception $e) {
            $this->_getSession()->addError($e->getMessage());
        }

        if ($this->getRequest()->getParam('back') === 'view') {
            $this->_redirect('*/*/view', ['id' => $id]);
        } else {
            $this->_redirect('*/*/index');
        }
    }

    public function deleteAction()
    {
        $id = (int) $this->getRequest()->getParam('id');

        // An address alone deletes nothing: the button posts, with the session's key
        if (!$this->getRequest()->isPost() || !$this->_validateFormKey()) {
            $this->_redirect('*/*/index');

            return;
        }

        try {
            /** @var YSRTech_EmailCampaigns_Model_Segment $segment */
            $segment = Mage::getModel('ysrtech_emailcampaigns/segment')->load($id);

            if (!$segment->getId()) {
                Mage::throwException($this->__('Segment no longer exists.'));
            }

            /*
             * Deleting a segment a campaign points at used to cascade the link
             * away, so a scheduled campaign's audience silently became
             * something else. Say which campaigns are in the way instead and
             * let somebody decide; the foreign key refuses it regardless, but
             * a constraint violation is not an explanation.
             */
            // Only campaigns that have not gone out yet stand in the way. One
            // that has been sent is finished with the segment, and holding
            // on to it would make every segment ever used impossible to delete.
            $usedBy = $segment->getCampaignUsage(true);

            if ($usedBy) {
                Mage::throwException($this->__(
                    'Cannot delete "%s": it is used by %s. Remove it from those campaigns first.',
                    $segment->getName(),
                    implode(', ', $usedBy)
                ));
            }

            $resource = Mage::getSingleton('core/resource');
            $resource->getConnection('core_write')->delete(
                $resource->getTableName('ysrtech_emailcampaigns/campaign_segment'),
                ['segment_id = ?' => (int) $segment->getId()]
            );

            $segment->delete();
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
