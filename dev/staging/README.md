# Throwaway staging install

Builds a real, working OpenMage install with this module installed — database,
admin, storefront — inside a Claude Code container, so changes can be checked
against an actual Magento runtime instead of just reading the code.

```
./dev/staging/setup.sh
```

Roughly five minutes on first run (cloning OpenMage core + `composer install`),
much faster on repeat runs. It installs MariaDB, clones
[OpenMage/magento-lts](https://github.com/OpenMage/magento-lts) into a sibling
`openmage-core` directory (see `OPENMAGE_CORE_DIR` below to change this),
symlinks this module in exactly as the top-level README's manual-install
instructions describe, installs OpenMage into a fresh
`ysrtech_emailcampaigns_staging` database, seeds two customers plus a
template/segment/draft campaign, reindexes, and serves on `127.0.0.1:8080`.

| | |
| --- | --- |
| Storefront | `http://127.0.0.1:8080/index.php` |
| Admin | `http://127.0.0.1:8080/index.php/admin` — `admin` / `StagingAdmin12345` |
| Campaigns | `http://127.0.0.1:8080/index.php/admin/ysrtech_campaign/index` |
| Templates | `http://127.0.0.1:8080/index.php/admin/ysrtech_template/index` |
| Segments | `http://127.0.0.1:8080/index.php/admin/ysrtech_segment/index` |

The seeded campaign is left in `draft` — schedule it from the admin grid (or
POST to `ysrtech_campaign/save` with `action=schedule`) to build its queue,
then process the queue by running the sender model from the core directory:

```
php -r "require 'app/Mage.php'; Mage::app('admin'); Mage::getModel('ysrtech_emailcampaigns/sender')->processQueue();"
```

Sending for real requires a configured transport (SendGrid/Mailgun/Resend/SMTP
API key under **System → Email Campaigns → Sending**); without one, sending
throws (the module has no local no-op transport of its own — for a local
smoke test, drop a class implementing
`YSRTech_EmailCampaigns_Model_Transport_Interface` into the core's
`app/code/local/YSRTech/EmailCampaigns/Model/Transport/` and point
`ysrtech_emailcampaigns/sending/transport` at it; the `local` pool overrides
`community` without touching this repo).

## Realistic volume

The install seeds two customers. For data that looks like a store:

```
cd ../openmage-core && php ../YSRTech_EmailCampaigns/dev/staging/seed-volume.php
```

About ten seconds for 24,000 subscribers, 9,600 customers with orders, 1,600
suppressions and 150,000 sent messages with opens, clicks, bounces and
complaints over two months. Every address is under `example.invalid`. It also
turns the store's own mail off and turns the admin URL secret key off, so a
page can be opened by its address.

`./dev/staging/link.sh` re-links the module's templates and layout into the
core and clears the cache; run it after adding a file under `app/design`.

There is no cron here. To move things that wait on it:

```
php -r "require 'app/Mage.php'; Mage::app('admin'); Mage::getModel('ysrtech_emailcampaigns/observer')->processImports();"
```

or use **Process a Batch Now** on an import's page.

## Env vars

| | |
| --- | --- |
| `OPENMAGE_CORE_DIR` | Where to clone/reuse the OpenMage core. Default: an `openmage-core` directory next to this repo. |
| `PORT` | Port to serve on. Default: `8080`. |

## Why a router script

`php -S` serves any file that exists on disk verbatim, `.htaccess` rules
included in a real deploy notwithstanding — that means `app/etc/local.xml`
(DB credentials) is publicly readable unless something blocks it.
`dev/staging/router.php` denies `/app/`, `/lib/`, `/var/`, `/shell/`,
`/bbscripts/` and `/.git/` before falling through to normal file/`index.php`
handling. It has no effect beyond this throwaway server.

## Caveat: a fresh install is not production

The database here is a clean OpenMage install with a synthetic catalogue and
customers, not a copy of any real store. It proves the module's controllers,
models, and admin screens work against a real Magento runtime; it does not
prove anything about performance at scale, real provider credentials, or
interactions with other installed modules.

**Containers are ephemeral, so this must be re-run per session.** Nothing it
creates is committed: the cloned core, its `vendor/`, `var/`, `media/` and
`app/etc/local.xml` all live outside this repo (in `OPENMAGE_CORE_DIR`) or are
gitignored.
