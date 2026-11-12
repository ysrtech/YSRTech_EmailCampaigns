<?php
class YSRTech_EmailCampaigns_Model_Template extends Mage_Core_Model_Abstract
{
    protected function _construct()
    {
        $this->_init('ysrtech_emailcampaigns/template');
    }

    /**
     * A template a flow sends cannot go: the database refuses it, and its
     * refusal is not something to show a person. Say which flows instead.
     */
    protected function _beforeDelete()
    {
        $resource = Mage::getSingleton('core/resource');
        $db       = $resource->getConnection('core_read');

        $flows = $db->fetchCol(
            $db->select()
                ->distinct()
                ->from(['a' => $resource->getTableName('ysrtech_emailcampaigns/automation')], ['name'])
                ->join(
                    ['s' => $resource->getTableName('ysrtech_emailcampaigns/automation_step')],
                    's.automation_id = a.automation_id',
                    []
                )
                ->where('s.template_id = ?', (int) $this->getId())
                ->limit(5)
        );

        if ($flows) {
            Mage::throwException(Mage::helper('ysrtech_emailcampaigns')->__(
                'This template is sent by an automation (%s). Give that flow another template first, then delete this one.',
                implode(', ', $flows)
            ));
        }

        return parent::_beforeDelete();
    }

    /**
     * The builder document this template is made from, or null for one
     * made before the builder and never opened in it.
     */
    public function getDoc(): ?array
    {
        $doc = json_decode((string) $this->getDesignJson(), true);

        return is_array($doc) && isset($doc['rows'], $doc['settings']) ? $doc : null;
    }

    /**
     * Take a builder document: cleaned, kept, and rendered into the HTML
     * that is sent. From here on the template is the builder's.
     *
     * @return $this
     */
    public function setDoc(array $doc, $storeId = null)
    {
        $doc = Mage::getModel('ysrtech_emailcampaigns/design_doc')->clean($doc, $storeId);

        return $this->addData([
            'design_json' => json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'html'        => Mage::getSingleton('ysrtech_emailcampaigns/design_html')->render($doc, (string) $this->getSubject()),
            'use_brand'   => YSRTech_EmailCampaigns_Model_Renderer::FRAME_DOC,
        ]);
    }

    /**
     * The document to open in the builder: its own, or one built around
     * the HTML it has.
     */
    public function getDocForBuilder($storeId = null): array
    {
        /** @var YSRTech_EmailCampaigns_Model_Design_Doc $docs */
        $docs = Mage::getModel('ysrtech_emailcampaigns/design_doc');

        if ($doc = $this->getDoc()) {
            return $docs->clean($doc, $storeId);
        }

        return trim((string) $this->getHtml()) === '' ? $docs->blank($storeId) : $docs->fromLegacy($this, $storeId);
    }
}
