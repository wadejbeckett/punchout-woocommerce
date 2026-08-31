#!/usr/bin/env bash
#
# Build a distributable plugin zip: bin/build-zip.sh [output-dir]
#
# Produces punchout-woocommerce-<version>.zip containing only runtime
# files — no tests, no git metadata, no build tooling — with the expected
# top-level punchout-woocommerce/ directory so WordPress installs it
# cleanly via Plugins > Add New > Upload.

set -euo pipefail

plugin_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
out_dir="${1:-$plugin_dir}"

version="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$plugin_dir/punchout-woocommerce.php" | head -1)"

if [ -z "$version" ]; then
    echo "Could not read the plugin version header" >&2
    exit 1
fi

stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT

mkdir -p "$stage/punchout-woocommerce"

cp "$plugin_dir/punchout-woocommerce.php" \
   "$plugin_dir/uninstall.php" \
   "$plugin_dir/readme.txt" \
   "$plugin_dir/LICENSE" \
   "$stage/punchout-woocommerce/"

cp -R "$plugin_dir/includes" "$plugin_dir/templates" "$stage/punchout-woocommerce/"

zip_file="$out_dir/punchout-woocommerce-$version.zip"
rm -f "$zip_file"

( cd "$stage" && zip -qr "$zip_file" punchout-woocommerce )

echo "Built: $zip_file"
unzip -l "$zip_file" | tail -1
