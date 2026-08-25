# YSRTech_EmailCampaigns

Klaviyo-style email marketing for OpenMage (Magento 1 LTS).

## Features
- **Customer segments** — rule-based dynamic segmentation of customers/subscribers
- **Drag & drop template editor** — block-based editor producing responsive table-based HTML email
- **Campaigns** — create, schedule, send to segments
- **Queue & cron sending** — batched background sending with retry/backoff
- **Pluggable transports** — SendGrid, Mailgun, Amazon SES, generic SMTP API adapters
- **Tracking** — open/click pixel + redirect tracking, per-campaign analytics
- **Unsubscribe / preferences center** — one-click unsubscribe, list-level preferences

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

## License
Proprietary — © YSRTech
