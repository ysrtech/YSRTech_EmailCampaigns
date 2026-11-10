# YSRTech_EmailCampaigns

Klaviyo-style email marketing for OpenMage (Magento 1 LTS).

## What is in the admin menu

**Email Campaigns** has seven pages and a shortcut. Each page has its own
permission under System > Permissions > Roles, so a role can be given some
and not others.

| Page | What it is |
|---|---|
| **Dashboard** | A verdict on sender reputation, then sent, delivered, real opens, clicks, bounce rate and spam rate for the last 7, 14 or 30 days, with trends, a funnel, and a breakdown by mailbox provider. Per-campaign figures underneath. |
| **Segments** | Rule-based groups of newsletter subscribers and customers. |
| **Campaigns** | Create, check, schedule and send to segments. A campaign is checked before it can be scheduled. |
| **Templates** | A gallery of starters and a drag-and-drop designer (GrapesJS), with reusable sections and placeholders. |
| **Automations** | Triggered chains - new order, order status, abandoned cart and so on - drawn as a flow. |
| **Suppressions** | Every address that must not be mailed, with why: hard bounce, spam complaint, unsubscribed, added by hand. |
| **Import / Migrate** | Subscribers from a CSV, or a whole account from Mailchimp, Klaviyo, Brevo, Kit, SendGrid or Mailgun. |
| **Settings** | A shortcut to System > Configuration > Email Campaigns. |

Contacts are OpenMage's own: newsletter subscribers and customers. There are
no separate lists.

## Sending

Set up under **System > Configuration > Email Campaigns > Sending**.
Transports: Mailgun, Resend, or the store's own mail settings. For Mailgun:
region (US or EU), sending domain, API key, webhook signing key, and
optionally a separate key for reading the suppression lists. The page shows
the webhook URL to paste into Mailgun, and **Test Connection** checks the
saved settings without sending anything.

Bounces, complaints, unsubscribes, opens and clicks come back by webhook, and
Mailgun's suppression lists are read hourly as a safety net.

## Cron

The module relies on OpenMage's cron running every minute: it launches
scheduled campaigns, sends the queue in batches, moves imports forward,
finds abandoned carts and syncs suppressions. If cron is not running,
nothing sends and imports only move when pushed by hand from their page.

## Installation

### Composer (recommended)
```bash
composer require ysrtech/emailcampaigns
```

### Manual
Copy `app/` into your OpenMage root, then clear cache and log out/in of admin.

## Development
Open in a dev container (Codespaces or VS Code Dev Containers) for a ready PHP 8.3 + MySQL + Redis environment.

### Linking into a local OpenMage install (Windows)
```powershell
mklink /D C:\path\to\openmage\app\code\community\YSRTech C:\path\to\ysrtech-emailcampaigns\app\code\community\YSRTech
```

### Staging install

`./dev/staging/setup.sh` builds a throwaway OpenMage with this module in it;
see `dev/staging/README.md`. `dev/staging/seed-volume.php` then fills it with
synthetic subscribers, customers, orders, suppressions and sent mail, every
address under `example.invalid`.

### Tests

Standalone scripts, run from the OpenMage root of an install that has the
module (the staging one will do). None of them sends mail or calls a provider.

```bash
php <module>/dev/tests/suppressions.php   # suppression list, Mailgun sync, webhook
php <module>/dev/tests/import.php         # a provider migration against a stand-in
php <module>/dev/tests/providers.php      # the six provider clients, on fixtures
php <module>/dev/tests/automation.php     # flow validation, versions, queueing
```

## License
Proprietary — © YSRTech
