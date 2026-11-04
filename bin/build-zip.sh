#!/usr/bin/env bash
# Builds build/florank.zip with a single florank/ folder that contains only
# the files WordPress needs (allow-list below), no dev tooling.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="florank"
OUT="$ROOT/build"
STAGE="$OUT/$SLUG"

# Files and folders shipped in the plugin zip.
INCLUDE=(florank.php uninstall.php readme.txt LICENSE src languages)

rm -rf "$OUT"
mkdir -p "$STAGE"
for item in "${INCLUDE[@]}"; do
	cp -R "$ROOT/$item" "$STAGE/"
done

VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$STAGE/florank.php" | head -n1)"
STABLE="$(sed -n 's/^Stable tag:[[:space:]]*//p' "$STAGE/readme.txt" | head -n1)"
CONSTANT="$(sed -n "s/^define( 'FLORANK_VERSION', '\([^']*\)' );/\1/p" "$STAGE/florank.php")"
if [ "$VERSION" != "$STABLE" ] || [ "$VERSION" != "$CONSTANT" ]; then
	echo "Version mismatch: header=$VERSION FLORANK_VERSION=$CONSTANT readme Stable tag=$STABLE" >&2
	exit 1
fi
if [ "${GITHUB_REF_TYPE:-}" = "tag" ] && [ "v$VERSION" != "${GITHUB_REF_NAME:-}" ]; then
	echo "Tag ${GITHUB_REF_NAME:-} does not match plugin version $VERSION" >&2
	exit 1
fi

(cd "$OUT" && zip -qr "$SLUG.zip" "$SLUG")
echo "Built $OUT/$SLUG.zip (version $VERSION)"
(cd "$OUT" && unzip -l "$SLUG.zip")
