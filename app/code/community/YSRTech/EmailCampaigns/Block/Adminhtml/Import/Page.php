<?php
/**
 * The import screens that are not a stock form or grid: choosing columns,
 * choosing what to migrate, and watching one run.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Import_Page extends Mage_Adminhtml_Block_Template
{
    /** @return YSRTech_EmailCampaigns_Model_Import */
    public function getImport()
    {
        return Mage::registry('ysrtech_emailcampaigns_import');
    }

    /** @return array<string, string> field => label, for a CSV column */
    public function getFieldOptions(): array
    {
        $I = YSRTech_EmailCampaigns_Model_Import::class;

        return [
            $I::FIELD_SKIP         => $this->__('Do not import'),
            $I::FIELD_EMAIL        => $this->__('Email address'),
            $I::FIELD_FIRST_NAME   => $this->__('First name'),
            $I::FIELD_LAST_NAME    => $this->__('Last name'),
            $I::FIELD_FULL_NAME    => $this->__('Full name (split into first and last)'),
            $I::FIELD_UNSUBSCRIBED => $this->__('Unsubscribed (yes / no)'),
        ];
    }

    /** @return array<string, string> stage => label */
    public function getStageLabels(): array
    {
        $P = YSRTech_EmailCampaigns_Model_Import_Provider_Interface::class;

        return [
            $P::STAGE_SUPPRESSIONS => $this->__('Unsubscribes, bounces and spam complaints'),
            $P::STAGE_CONTACTS     => $this->__('Contacts'),
            $P::STAGE_TEMPLATES    => $this->__('Templates'),
        ];
    }

    public function getProviderName(): string
    {
        $providers = YSRTech_EmailCampaigns_Model_Import::providers();

        return $providers[$this->getImport()->getProvider()] ?? (string) $this->getImport()->getProvider();
    }

    /**
     * How far along, 0 to 100, or null when the total is not known.
     */
    public function getPercent(): ?int
    {
        $import = $this->getImport();

        if ($import->getStatus() === YSRTech_EmailCampaigns_Model_Import::STATUS_DONE) {
            return 100;
        }

        $total = (int) $import->getTotalRows();

        return $total > 0 ? (int) min(99, floor((int) $import->getProcessedRows() / $total * 100)) : null;
    }

    /**
     * The counts that are not zero, labelled, in a fixed order.
     *
     * @return array<int, array{label:string, value:int}>
     */
    public function getStatRows(): array
    {
        $stats = $this->getImport()->getStats();
        $rows  = [];

        foreach (YSRTech_EmailCampaigns_Model_Import::statLabels() as $key => $label) {
            if (!empty($stats[$key])) {
                $rows[] = ['label' => $label, 'value' => (int) $stats[$key]];
            }
        }

        return $rows;
    }
}
