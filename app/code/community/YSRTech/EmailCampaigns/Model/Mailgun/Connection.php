<?php
/**
 * "Does this configuration actually work", answered without sending anything.
 *
 * Each line it returns is one thing checked, because the failures here look
 * alike from the outside and have different fixes: a sending key that cannot
 * read the domain, a domain in the other region, unsubscribe tracking left
 * off, a suppression key that is really a sending key.
 */
class YSRTech_EmailCampaigns_Model_Mailgun_Connection
{
    /**
     * @return array{ok:bool, lines:array<int, array{level:string, text:string}>}
     */
    public function test(): array
    {
        $helper = Mage::helper('ysrtech_emailcampaigns');
        $lines  = [];
        $ok     = true;

        $add = static function (string $level, string $text) use (&$lines, &$ok): void {
            $lines[] = ['level' => $level, 'text' => $text];

            if ($level === 'error') {
                $ok = false;
            }
        };

        $key    = trim((string) $helper->getSecret('sending/api_key'));
        $domain = trim((string) $helper->getConfig('sending/mailgun_domain'));
        $region = strtolower((string) $helper->getConfig('sending/mailgun_region')) === 'eu' ? 'eu' : 'us';

        if ($key === '') {
            $add('error', $helper->__('No API key is saved.'));
        }

        if ($domain === '') {
            $add('error', $helper->__('No sending domain is saved.'));
        }

        if (!$ok) {
            return ['ok' => false, 'lines' => $lines];
        }

        /** @var YSRTech_EmailCampaigns_Model_Mailgun_Api $api */
        $api = Mage::getSingleton('ysrtech_emailcampaigns/mailgun_api');

        try {
            /*
             * The tracking settings rather than the domain record itself: a
             * domain sending key - the kind recommended for the key field -
             * is allowed to read these and not much else, and they are the
             * thing a send checks before it posts.
             */
            $path     = 'domains/' . rawurlencode($domain) . '/tracking';
            $response = $api->request('GET', $path, $key, $region);

            if ($response['status'] === 401 || $response['status'] === 403) {
                $add('error', $helper->__('Mailgun refused the API key (HTTP %d). Check the key, and that it belongs to this domain.', $response['status']));
            } elseif ($response['status'] === 404) {
                $other = $region === 'eu' ? 'us' : 'eu';
                $there = $api->request('GET', $path, $key, $other);

                if ($there['status'] === 200) {
                    $add('error', $helper->__('%s was found in the %s region, not %s. Change Mailgun Region and save.', $domain, strtoupper($other), strtoupper($region)));
                } else {
                    $add('error', $helper->__('Mailgun has no domain %s in the %s region for this key.', $domain, strtoupper($region)));
                }
            } elseif ($response['status'] !== 200) {
                $add('error', $helper->__('Mailgun answered HTTP %d: %s', $response['status'], substr($response['body'], 0, 200)));
            } else {
                $add('ok', $helper->__('The API key reaches %s in the %s region.', $domain, strtoupper($region)));

                if (empty($response['json']['tracking']['unsubscribe']['active'])) {
                    $add('error', $helper->__('Unsubscribe tracking is off for this domain. Campaigns refuse to send until it is on, because the unsubscribe link would be delivered as literal text.'));
                } else {
                    $add('ok', $helper->__('Unsubscribe tracking is on.'));
                }

                foreach (['open' => $helper->__('Open'), 'click' => $helper->__('Click')] as $kind => $label) {
                    if (empty($response['json']['tracking'][$kind]['active'])) {
                        $add('warn', $helper->__('%s tracking is off for this domain, so the dashboard will show none.', $label));
                    }
                }
            }
        } catch (Exception $e) {
            $add('error', $e->getMessage());
        }

        if ($ok) {
            $own     = trim((string) $helper->getSecret('sending/suppression_api_key'));
            $listKey = $own !== '' ? $own : $key;

            try {
                $response = $api->request('GET', rawurlencode($domain) . '/bounces?limit=1', $listKey, $region);

                if ($response['status'] === 200) {
                    $add('ok', $helper->__('The suppression lists can be read, so the hourly sync will work.'));
                } elseif ($own === '') {
                    $add('warn', $helper->__('The sending key cannot read the suppression lists (HTTP %d). That is normal for a domain sending key. Webhooks still report bounces and complaints; set a Suppression API Key for the hourly sync as well.', $response['status']));
                } else {
                    $add('error', $helper->__('Mailgun refused the Suppression API Key (HTTP %d).', $response['status']));
                }
            } catch (Exception $e) {
                $add('warn', $e->getMessage());
            }
        }

        if (trim((string) $helper->getSecret('sending/mailgun_webhook_signing_key')) === '') {
            $add('warn', $helper->__('No webhook signing key is saved, so every webhook Mailgun sends is refused and opens, clicks, bounces and complaints are not recorded.'));
        }

        return ['ok' => $ok, 'lines' => $lines];
    }
}
