#!/usr/bin/env bash
# Re-link the module's design files into the staging core. setup.sh links them
# one file at a time, so a template or layout added since it ran is not there
# until this does. Safe to run as often as you like.
set -euo pipefail
MODULE_DIR=$(cd "$(dirname "$0")/../.." && pwd)
CORE_DIR=${OPENMAGE_CORE_DIR:-"$(dirname "$MODULE_DIR")/openmage-core"}
find "$CORE_DIR/app/design" -type l -lname "$MODULE_DIR/*" -delete
while IFS= read -r design_file; do
    rel=${design_file#"$MODULE_DIR/app/design/"}
    target="$CORE_DIR/app/design/$rel"
    mkdir -p "$(dirname "$target")"
    ln -sfn "$design_file" "$target"
done < <(find "$MODULE_DIR/app/design" -type f)
rm -rf "$CORE_DIR/var/cache"/*
echo "linked; cache cleared"
