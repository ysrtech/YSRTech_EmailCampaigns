<?php
class YSRTech_EmailCampaigns_Block_Adminhtml_Import_Provider_Form extends Mage_Adminhtml_Block_Widget_Form
{
    #[Override]
    protected function _prepareForm()
    {
        $h = Mage::helper('ysrtech_emailcampaigns');
        $I = YSRTech_EmailCampaigns_Model_Import::class;

        $form = new Varien_Data_Form([
            'id'     => 'edit_form',
            'action' => $this->getUrl('*/*/connect'),
            'method' => 'post',
        ]);
        $form->setUseContainer(true);

        $fieldset = $form->addFieldset('base', ['legend' => $h->__('Connect the account')]);

        $help   = [];
        $extras = [];

        foreach ($I::providers() as $code => $name) {
            $client      = $I::providerClient($code);
            $help[$code] = $client->getKeyHelp();

            foreach ($client->getCredentialFields() as $field => $definition) {
                $extras[$field]['definition']  = $definition;
                $extras[$field]['providers'][] = $code;
            }
        }

        $fieldset->addField('provider', 'select', [
            'label'    => $h->__('Provider'),
            'name'     => 'provider',
            'required' => true,
            'values'   => $I::providers(),
            'onchange' => 'ysrtechImportProvider()',
        ]);

        $fieldset->addField('api_key', 'password', [
            'label'        => $h->__('API Key'),
            'name'         => 'api_key',
            'required'     => true,
            'autocomplete' => 'off',
            'note'         => '<span id="ysrtech-ec-keyhelp"></span>',
        ]);

        foreach ($extras as $field => $extra) {
            $fieldset->addField('credential_' . $field, 'text', [
                'label' => $extra['definition']['label'],
                'name'  => 'credentials[' . $field . ']',
                'note'  => $extra['definition']['note'] ?? '',
            ]);
        }

        $fieldset->addField('promise', 'note', [
            'text' => $this->escapeHtml($h->__('Read-only access is enough. Nothing is sent or changed at the provider. The key is stored encrypted while the migration runs and deleted when it finishes.')),
        ]);

        $json  = Mage::helper('core');
        $shown = [];

        foreach ($extras as $field => $extra) {
            $shown['credential_' . $field] = $extra['providers'];
        }

        // Text nodes only: the help strings are ours, but nothing here needs
        // to be markup.
        $fieldset->addField('script', 'note', [
            'text' => '<script type="text/javascript">
//<![CDATA[
function ysrtechImportProvider() {
    var help = ' . $json->jsonEncode($help) . ', shown = ' . $json->jsonEncode((object) $shown) . ';
    var code = $("provider").value;
    $("ysrtech-ec-keyhelp").update("").appendChild(document.createTextNode(help[code] || ""));
    for (var id in shown) {
        if (shown.hasOwnProperty(id) && $(id)) {
            $(id).up("tr")[shown[id].indexOf(code) >= 0 ? "show" : "hide"]();
        }
    }
}
document.observe("dom:loaded", ysrtechImportProvider);
//]]>
</script>',
        ]);

        $this->setForm($form);

        return parent::_prepareForm();
    }
}
