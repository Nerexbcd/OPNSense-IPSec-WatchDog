#!/bin/sh
# Generates index.html for the gh-pages pkg repo from pkgsite/index.template.html,
# listing every file already built into the repo directory. Kept as plain POSIX sh
# (no bash-isms) since it runs both in CI (Linux) and via the manual-publish
# instructions in the README (FreeBSD).
#
# usage: gen-index.sh <pkgrepo-dir> <template-file> <version>
set -e

REPO_DIR="$1"
TEMPLATE="$2"
VERSION="$3"

if [ -z "$REPO_DIR" ] || [ -z "$TEMPLATE" ] || [ -z "$VERSION" ]; then
    echo "usage: gen-index.sh <pkgrepo-dir> <template-file> <version>" >&2
    exit 1
fi

# only the actual plugin package(s) are worth a human clicking - data.pkg,
# meta.conf etc. are pkg's own internal catalog files, not anything to download
ROWS=""
for f in "$REPO_DIR"/ipsec-watchdog-*.pkg; do
    [ -f "$f" ] || continue
    name=$(basename "$f")
    bytes=$(wc -c < "$f" | tr -d ' ')
    ROWS="${ROWS}<li><a href=\"${name}\">${name}</a><span class=\"size\">${bytes} bytes</span></li>\n"
done

PUBLISHED_AT=$(date -u '+%Y-%m-%d %H:%M UTC')

awk -v rows="$ROWS" -v ver="$VERSION" -v pub="$PUBLISHED_AT" '
{
    gsub(/__FILE_ROWS__/, rows);
    gsub(/__VERSION__/, ver);
    gsub(/__PUBLISHED_AT__/, pub);
    print
}' "$TEMPLATE" > "$REPO_DIR/index.html"
