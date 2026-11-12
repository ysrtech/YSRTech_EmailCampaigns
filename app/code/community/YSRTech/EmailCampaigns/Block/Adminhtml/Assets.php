<?php
/**
 * This module's stylesheets and scripts for an admin page, each linked with
 * the time its file last changed.
 *
 * The admin's own way of adding a file to the page gives it the same address
 * for ever, so a browser - or a CDN in front of the store - goes on serving
 * the copy it kept after the file has been updated, and a page can be running
 * last month's script against this month's server. A new address for a new
 * file means an update is seen the first time the page is opened.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Assets extends Mage_Core_Block_Abstract
{
    /** @var array<string, string> file => css|js */
    protected $_files = [];

    public function addCss(string $name): self
    {
        $this->_files[$name] = 'css';

        return $this;
    }

    public function addJs(string $name): self
    {
        $this->_files[$name] = 'js';

        return $this;
    }

    /**
     * The address of one of the module's skin files, changing when the file does.
     */
    public function versioned(string $name): string
    {
        $path = Mage::getDesign()->getFilename($name, ['_type' => 'skin', '_area' => 'adminhtml']);

        return $this->getSkinUrl($name) . '?v=' . (is_file($path) ? filemtime($path) : '1');
    }

    #[Override]
    protected function _toHtml()
    {
        $html = '';

        foreach ($this->_files as $name => $type) {
            $url = $this->escapeHtml($this->versioned($name));

            $html .= $type === 'css'
                ? '<link rel="stylesheet" type="text/css" href="' . $url . '" media="all" />' . "\n"
                : '<script type="text/javascript" src="' . $url . '"></script>' . "\n";
        }

        return $html;
    }
}
