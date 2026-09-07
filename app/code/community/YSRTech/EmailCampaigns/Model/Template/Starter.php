<?php
/**
 * The markup a new template begins with.
 *
 * A centred column with a header and a footer, built the way email is built
 * rather than the way a web page is: nested tables, widths as attributes,
 * styles inline. Every modern layout tool would express this as flexbox or
 * grid, and Outlook renders neither - so this reads like 2005 on purpose.
 *
 * Starting from something whole matters more here than in most places. An
 * empty canvas invites a bare <p>, which arrives full-width and unstyled on a
 * desktop client and is the commonest way a newsletter looks broken.
 */
class YSRTech_EmailCampaigns_Model_Template_Starter
{
    /** Wider than this and phone clients shrink the whole message to fit */
    private const CONTENT_WIDTH = 600;

    /**
     * @param  int|null $storeId
     * @return string
     */
    public function getHtml($storeId = null): string
    {
        $h     = Mage::helper('ysrtech_emailcampaigns');
        $width = self::CONTENT_WIDTH;

        $store  = $this->_escape(Mage::getSingleton('ysrtech_emailcampaigns/sender')->storeName($storeId));
        $header = $this->_escape($h->__('Your header'));
        $body   = $this->_escape($h->__('Write your newsletter here.'));
        $greet  = $h->__('Hi %s,', '{{ customer.firstname|there }}');
        $addr   = $this->_escape((string) Mage::getStoreConfig('general/store_information/address', $storeId));

        return <<<HTML
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f4f4;margin:0;padding:24px 0;">
  <tr>
    <td align="center">
      <table width="{$width}" cellpadding="0" cellspacing="0" border="0" style="width:{$width}px;max-width:100%;background-color:#ffffff;border-radius:4px;font-family:Arial,Helvetica,sans-serif;color:#333333;">
        <tr>
          <td align="center" style="padding:24px 32px;border-bottom:1px solid #eeeeee;">
            <h1 style="margin:0;font-size:22px;font-weight:normal;color:#222222;">{$header}</h1>
          </td>
        </tr>
        <tr>
          <td style="padding:28px 32px;font-size:15px;line-height:1.6;">
            <p style="margin:0 0 16px;">{$greet}</p>
            <p style="margin:0 0 16px;">{$body}</p>
          </td>
        </tr>
        <tr>
          <td align="center" style="padding:20px 32px 28px;border-top:1px solid #eeeeee;font-size:12px;line-height:1.6;color:#888888;">
            <p style="margin:0 0 6px;">{$store}</p>
            <p style="margin:0 0 10px;">{$addr}</p>
            {{unsubscribe}}
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
HTML;
    }

    /**
     * @param  string $value
     * @return string
     */
    private function _escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
