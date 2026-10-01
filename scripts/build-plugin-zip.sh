#!/usr/bin/env bash
# Build the merchant-ready Изпрати.БГ Marketplace zip under dist/.
# Excludes development, test, dependency, and internal contributor files.

set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
PLUGIN_SLUG="izprati-bg-shipping"
PLUGIN_FILE="$DIR/octavawms-woocommerce.php"
CHANGELOG_FILE="$DIR/changelog.txt"
README_FILE="$DIR/readme.txt"
DIST_FILE="$DIR/src/Distribution.php"
VERSION="${1:-}"
if [[ -z "$VERSION" ]]; then
	VERSION="$(sed -n -E 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' "$PLUGIN_FILE" | head -1)"
fi

HEADER_VERSION="$(sed -n -E 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' "$PLUGIN_FILE" | head -1)"
if [[ -z "$HEADER_VERSION" || "$HEADER_VERSION" != "$VERSION" ]]; then
	echo "❌ Plugin header version '${HEADER_VERSION:-<missing>}' does not match requested version '$VERSION'." >&2
	exit 1
fi
README_VERSION="$(sed -n -E 's/^Stable tag:[[:space:]]*([^[:space:]]+).*/\1/p' "$README_FILE" | head -1)"
DIST_VERSION="$(sed -n -E "s/^[[:space:]]*public const VERSION = '([^']+)';/\1/p" "$DIST_FILE" | head -1)"
if [[ "$README_VERSION" != "$VERSION" || "$DIST_VERSION" != "$VERSION" ]]; then
	echo "❌ Version mismatch: header=$HEADER_VERSION readme=${README_VERSION:-<missing>} distribution=${DIST_VERSION:-<missing>} requested=$VERSION." >&2
	exit 1
fi
if ! grep -q -E "^[0-9]{4}-[0-9]{2}-[0-9]{2} - version ${VERSION//./\\.}$" "$CHANGELOG_FILE"; then
	echo "❌ changelog.txt has no release entry for version '$VERSION'." >&2
	exit 1
fi

DEST="$DIR/dist/${PLUGIN_SLUG}-${VERSION}.zip"
mkdir -p "$DIR/dist"

STAGE="$(mktemp -d "${TMPDIR:-/tmp}/izprati-bg-pkg.XXXXXX")"
cleanup() { rm -rf "$STAGE"; }
trap cleanup EXIT

TARGET="$STAGE/$PLUGIN_SLUG"
mkdir -p "$TARGET"

rsync -a \
	--exclude='.git/' \
	--exclude='.gitkeep' \
	--exclude='vendor/' \
	--exclude='tests/' \
	--exclude='dist/' \
	--exclude='dev/' \
	--exclude='docs/' \
	--exclude='marketplace/' \
	--exclude='scripts/' \
	--exclude='release.sh' \
	--exclude='README.md' \
	--exclude='CHANGELOG.md' \
	--exclude='composer.json' \
	--exclude='composer.lock' \
	--exclude='.gitignore' \
	--exclude='.cursor/' \
	--exclude='node_modules/' \
	--exclude='.vscode/' \
	--exclude='.phpunit.cache/' \
	--exclude='.php_cs*' \
	--exclude='.DS_Store' \
	--exclude='*.tmp' \
	--exclude='*.log' \
	--exclude='phpunit.xml.dist' \
	--exclude='languages/source/' \
	"$DIR/" "$TARGET/"

# Not shipped to merchants (AI / internal workflow); keep in git only.
rm -f "$TARGET/AGENTS.md"

if [[ ! -f "$TARGET/octavawms-woocommerce.php" ]]; then
	echo "❌ Staging failed: octavawms-woocommerce.php missing" >&2
	exit 1
fi
if [[ ! -f "$TARGET/readme.txt" || ! -f "$TARGET/changelog.txt" || ! -f "$TARGET/LICENSE" ]]; then
	echo "❌ Marketplace metadata or license is missing from the staged package." >&2
	exit 1
fi

rm -f "$DEST"
( cd "$STAGE" && zip -qr "$DEST" "$PLUGIN_SLUG" )
echo "✅ Distribution zip: $DEST"
