<?php
/**
 * Something a provider's API said no to, in words that can be shown to the
 * person running the import.
 *
 * Retryable ones - a rate limit, a 5xx, a timeout - leave the import where
 * it is and the next cron run tries again after the wait. The rest stop it.
 */
class YSRTech_EmailCampaigns_Model_Import_Provider_Exception extends Mage_Core_Exception
{
    /** @var bool */
    protected $_retryable = false;

    /** @var int Seconds to wait before trying again */
    protected $_retryAfter = 0;

    public function __construct(string $message, bool $retryable = false, int $retryAfter = 0)
    {
        parent::__construct($message);
        $this->_retryable  = $retryable;
        $this->_retryAfter = $retryAfter;
    }

    public function isRetryable(): bool
    {
        return $this->_retryable;
    }

    public function getRetryAfter(): int
    {
        return $this->_retryAfter;
    }
}
