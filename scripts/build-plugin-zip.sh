#!/usr/bin/env bash
# Build a merchant-ready WordPress plugin zip under dist/.
# Excludes dev/test/vendor and AI-only contributor docs (see PACKAGE_EXCLUDES).

set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
PLUGIN_SLUG="octavawms-woocommerce"
VERSION="${1:-}"
if [[ -z "$VERSION" ]]; then
	VERSION="$(git -C "$DIR" describe --tags --abbrev=0 2>/dev/null || true)"
fi
if [[ -z "$VERSION" ]]; then
	VERSION="snapshot"
fi

DEST="$DIR/dist/${PLUGIN_SLUG}-${VERSION}.zip"
mkdir -p "$DIR/dist"

STAGE="$(mktemp -d "${TMPDIR:-/tmp}/octavawms-pkg.XXXXXX")"
cleanup() { rm -rf "$STAGE"; }
trap cleanup EXIT

TARGET="$STAGE/$PLUGIN_SLUG"
mkdir -p "$TARGET"

rsync -a \
	--exclude='.git/' \
	--exclude='vendor/' \
	--exclude='tests/' \
	--exclude='dist/' \
	--exclude='dev/' \
	--exclude='scripts/' \
	--exclude='release.sh' \
	--exclude='.cursor/' \
	--exclude='node_modules/' \
	--exclude='.vscode/' \
	--exclude='.phpunit.cache/' \
	--exclude='.php_cs*' \
	--exclude='.DS_Store' \
	--exclude='*.tmp' \
	--exclude='*.log' \
	--exclude='phpunit.xml.dist' \
	"$DIR/" "$TARGET/"

# Not shipped to merchants (AI / internal workflow). Mirrors .cursor rules; keep in git only.
rm -f "$TARGET/docs/guides/clickup-workflow.md"
rm -f "$TARGET/AGENTS.md"

if [[ ! -f "$TARGET/octavawms-woocommerce.php" ]]; then
	echo "❌ Staging failed: octavawms-woocommerce.php missing" >&2
	exit 1
fi

( cd "$STAGE" && zip -qr "$DEST" "$PLUGIN_SLUG" )
echo "✅ Distribution zip: $DEST"
