<?php
/**
 * One subscriber, all round: who they are, what was sent to them and what
 * they did with it, where they sit in the lists, and what they have bought.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Subscriber_View extends Mage_Adminhtml_Block_Template
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('ysrtech_emailcampaigns/subscriber_view.phtml');
    }

    public function getProfile(): YSRTech_EmailCampaigns_Model_Subscriber_Profile
    {
        return Mage::registry('ysrtech_emailcampaigns_subscriber_profile');
    }

    public function getPage(): int
    {
        return max(1, (int) $this->getRequest()->getParam('p', 1));
    }

    public function getPageUrl(int $page): string
    {
        return $this->getUrl('*/*/view', ['id' => (int) $this->getProfile()->getSubscriber()->getId(), 'p' => $page]);
    }

    public function isAllowed(string $node): bool
    {
        return Mage::getSingleton('admin/session')->isAllowed($node);
    }

    /**
     * Two letters for the round badge beside the name.
     */
    public function getInitials(): string
    {
        $profile = $this->getProfile();
        $source  = $profile->getName() !== '' ? $profile->getName() : $profile->getEmail();
        $parts   = preg_split('/[\s._@-]+/u', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $letters = mb_substr($parts[0] ?? '?', 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : '');

        return mb_strtoupper($letters);
    }

    /**
     * A stored UTC time in the admin's locale, or a dash.
     */
    public function when(?string $utc, bool $time = true): string
    {
        if ($utc === null || $utc === '' || str_starts_with($utc, '0000')) {
            return '—';
        }

        return (string) $this->formatDate($utc, 'medium', $time);
    }

    /**
     * What happened to one email, as the chips shown on its row.
     *
     * @return array<int, array{0: string, 1: string, 2: string}> class, label, title
     */
    public function getMarks(array $m): array
    {
        $h     = Mage::helper('ysrtech_emailcampaigns');
        $marks = [];

        if ($m['status'] === 'failed') {
            $marks[] = ['failed', $h->__('Not sent'), (string) $m['error_message']];
        } elseif ($m['status'] === 'cancelled') {
            $marks[] = ['', $h->__('Stopped'), (string) $m['error_message']];
        } elseif ($m['status'] !== 'sent') {
            $marks[] = ['scheduled', $h->__('Waiting'), $h->__('Due %s', $this->when($m['send_at'] ?: $m['queued_at']))];
        } else {
            $marks[] = ['', $m['delivered_at'] ? $h->__('Delivered') : $h->__('Sent'), $this->when($m['delivered_at'] ?: $m['sent_at'])];
        }

        if ($m['bounced_at']) {
            $marks[] = ['failed', $h->__('Bounced'), $this->when($m['bounced_at'])];
        }

        if ($m['opened_at']) {
            $count   = (int) $m['open_count'];
            $marks[] = ['sent', $count > 1 ? $h->__('Opened ×%d', $count) : $h->__('Opened'), $h->__('First opened %s', $this->when($m['opened_at']))];
        } elseif ((int) $m['machine_open_count'] > 0) {
            $marks[] = ['', $h->__('Opened by a mail app'), $h->__('Loaded automatically by their mail provider, which does not tell us whether a person read it.')];
        }

        if ($m['clicked_at']) {
            $count   = (int) $m['click_count'];
            $marks[] = ['sent', $count > 1 ? $h->__('Clicked ×%d', $count) : $h->__('Clicked'), $h->__('First clicked %s', $this->when($m['clicked_at']))];
        }

        if ($m['complained_at']) {
            $marks[] = ['failed', $h->__('Marked as spam'), $this->when($m['complained_at'])];
        }

        return $marks;
    }

    public function money(float $amount, string $currency): string
    {
        return $currency !== ''
            ? (string) Mage::app()->getLocale()->currency($currency)->toCurrency($amount)
            : number_format($amount, 2);
    }
}
