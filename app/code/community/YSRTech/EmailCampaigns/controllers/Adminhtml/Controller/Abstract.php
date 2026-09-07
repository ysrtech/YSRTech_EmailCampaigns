<?php
/**
 * Shared admin controller helpers: ACL checks + grid registration.
 */
abstract class YSRTech_EmailCampaigns_Adminhtml_Controller_Abstract
    extends Mage_Adminhtml_Controller_Action
{
    /**
     * OpenMage 20 types this on Mage_Adminhtml_Controller_Action, and PHP 8
     * treats an override that drops the return type as an incompatible
     * signature - a fatal, not a notice.
     */
    protected function _isAllowed(): bool
    {
        return Mage::getSingleton('admin/session')->isAllowed('ysrtech_emailcampaigns');
    }

    /**
     * Show rendered email HTML without letting it run in the admin.
     *
     * The body goes into a fully sandboxed iframe: no scripts, no forms, no
     * navigation. Template HTML is written by admins rather than the public,
     * but it is still content being echoed back into an authenticated origin,
     * and an email client would not run its scripts either - so the safer
     * frame is also the more faithful one.
     *
     * @param  string $html
     * @param  string $subject
     * @param  string $from
     * @return void
     */
    protected function _renderPreviewPage(string $html, string $subject, string $from): void
    {
        $h = Mage::helper('ysrtech_emailcampaigns');
        $e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $page = '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<title>' . $e($h->__('Preview: %s', $subject)) . '</title>'
            . '<style>body{margin:0;background:#f3f3f3;font:13px/1.5 Arial,sans-serif;color:#333}'
            . '.hdr{background:#fff;border-bottom:1px solid #ddd;padding:14px 20px}'
            . '.hdr dl{margin:0;display:grid;grid-template-columns:max-content 1fr;gap:4px 14px}'
            . '.hdr dt{color:#888}.hdr dd{margin:0}'
            . '.note{padding:10px 20px;background:#fffbe6;border-bottom:1px solid #f0e0a0;color:#7a6a20}'
            . '.frame{display:block;width:100%;height:calc(100vh - 150px);border:0;background:#fff}'
            . '</style></head><body>'
            . '<div class="hdr"><dl>'
            . '<dt>' . $e($h->__('From')) . '</dt><dd>' . $e($from) . '</dd>'
            . '<dt>' . $e($h->__('Subject')) . '</dt><dd><strong>' . $e($subject) . '</strong></dd>'
            . '</dl></div>'
            . '<p class="note">'
            . $e($h->__('Sample recipient details. Subscribers with no name on file see whatever fallback the template gives, and the unsubscribe link is inert here.'))
            . '</p>'
            . '<iframe class="frame" sandbox="" srcdoc="' . $e($html) . '"></iframe>'
            . '</body></html>';

        $this->getResponse()
            ->setHeader('Content-Type', 'text/html; charset=UTF-8', true)
            ->setHeader('X-Frame-Options', 'SAMEORIGIN', true)
            ->setBody($page);
    }
}
