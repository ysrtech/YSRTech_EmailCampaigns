#!/usr/bin/env bash
#
# Build a throwaway OpenMage instance with this module installed, inside a Claude
# Code container (or any Debian box with PHP 8.1+), so changes can be checked
# against a real Magento runtime instead of just reading the code. Takes a few
# minutes. Nothing here touches production — this module repo has no OpenMage
# core of its own, so one is cloned into a sibling directory and this module is
# symlinked into it (the same approach the README describes for a local install).
#
#   ./dev/staging/setup.sh          # clone core (if needed) + install + seed + serve
#
# Env vars:
#   OPENMAGE_CORE_DIR   where to clone/reuse the OpenMage core (default: sibling
#                       "openmage-core" directory next to this repo)
#   PORT                port to serve on (default: 8080)
#
# Containers are ephemeral, so this has to be re-run per session.
set -euo pipefail

MODULE_DIR=$(cd "$(dirname "$0")/../.." && pwd)
CORE_DIR=${OPENMAGE_CORE_DIR:-"$(dirname "$MODULE_DIR")/openmage-core"}
PORT=${PORT:-8080}
DB_NAME=ysrtech_emailcampaigns_staging

echo "==> MariaDB"
if ! command -v mariadbd >/dev/null; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq && apt-get install -y -qq mariadb-server
fi
mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
mysqladmin ping >/dev/null 2>&1 || { mariadbd-safe >/tmp/mysqld.log 2>&1 & }
for _ in $(seq 1 30); do mysqladmin ping >/dev/null 2>&1 && break; sleep 1; done

echo "==> OpenMage core ($CORE_DIR)"
if [ ! -f "$CORE_DIR/app/Mage.php" ]; then
    mkdir -p "$CORE_DIR"
    git clone --depth 1 https://github.com/OpenMage/magento-lts.git "$CORE_DIR"
fi

echo "==> Composer dependencies (core, production only)"
# --no-dev: phpstan/phpunit/ecs pull large VCS mirrors that regularly time out
# through a sandboxed proxy and aren't needed to run the app.
# --ignore-platform-req=ext-soap: core requires it, but the php8.4-soap package
# frequently isn't reachable through a sandboxed apt proxy either; SOAP API is
# unused here.
[ -d "$CORE_DIR/vendor" ] || (
    cd "$CORE_DIR"
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-progress --no-dev --ignore-platform-req=ext-soap
)

echo "==> Symlinking module into core"
mkdir -p "$CORE_DIR/app/code/community"
ln -sfn "$MODULE_DIR/app/code/community/YSRTech" "$CORE_DIR/app/code/community/YSRTech"
ln -sfn "$MODULE_DIR/app/etc/modules/YSRTech_EmailCampaigns.xml" \
    "$CORE_DIR/app/etc/modules/YSRTech_EmailCampaigns.xml"
mkdir -p "$CORE_DIR/app/design/adminhtml/default/default/template"
ln -sfn "$MODULE_DIR/app/design/adminhtml/default/default/template/ysrtech" \
    "$CORE_DIR/app/design/adminhtml/default/default/template/ysrtech"
mkdir -p "$CORE_DIR/skin/adminhtml/default/default"
ln -sfn "$MODULE_DIR/skin/adminhtml/default/default/ysrtech" \
    "$CORE_DIR/skin/adminhtml/default/default/ysrtech"

echo "==> Database"
mysql -e "DROP DATABASE IF EXISTS $DB_NAME; CREATE DATABASE $DB_NAME DEFAULT CHARACTER SET utf8mb4;"
rm -f "$CORE_DIR/app/etc/local.xml"
find "$CORE_DIR/var" "$CORE_DIR/media" -type d -exec chmod 777 {} + 2>/dev/null || true

echo "==> Magento install"
(cd "$CORE_DIR" && PORT="$PORT" DB_NAME="$DB_NAME" php "$MODULE_DIR/dev/staging/install.php")

echo "==> Seed test data (customers, template, segment, draft campaign)"
(cd "$CORE_DIR" && php "$MODULE_DIR/dev/staging/seed.php")

echo "==> Reindex"
(cd "$CORE_DIR" && php shell/indexer.php --reindexall)

echo "==> Serving"
pkill -f "php -S 127.0.0.1:$PORT" 2>/dev/null || true
(cd "$CORE_DIR" && php -S "127.0.0.1:$PORT" -t "$CORE_DIR" "$MODULE_DIR/dev/staging/router.php" \
    >/tmp/staging-server.log 2>&1 &)
sleep 2
echo
echo "Storefront : http://127.0.0.1:$PORT/index.php"
echo "Admin      : http://127.0.0.1:$PORT/index.php/admin  (admin / StagingAdmin12345)"
echo "Campaigns  : http://127.0.0.1:$PORT/index.php/admin/ysrtech_campaign/index"
echo "Templates  : http://127.0.0.1:$PORT/index.php/admin/ysrtech_template/index"
echo "Segments   : http://127.0.0.1:$PORT/index.php/admin/ysrtech_segment/index"
