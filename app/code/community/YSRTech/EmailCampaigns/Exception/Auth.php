<?php
/**
 * The provider rejected the credential (HTTP 401/403).
 *
 * Distinguished from any other provider error because it is not worth
 * retrying within the same run: every list behind that key will answer the
 * same way, and the caller stops rather than logging the identical failure
 * once per list.
 *
 * Extends Mage_Core_Exception so existing catch blocks keep working.
 */
class YSRTech_EmailCampaigns_Exception_Auth extends Mage_Core_Exception
{
}
