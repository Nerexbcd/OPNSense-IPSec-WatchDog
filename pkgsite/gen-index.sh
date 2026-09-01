#!/bin/sh
# Generates the gh-pages guide page (pkgsite/index.template.html) listing the
# packages built for each supported FreeBSD base. Kept as plain POSIX sh (no
# bash-isms) since it runs both in CI (Linux) and via the manual-publish
# instructions in the README (FreeBSD).
#
# usage: gen-index.sh <fb15-dir> <fb14-dir> <template-file> <version> <out-file>
#   <fb15-dir>  directory holding the FreeBSD 15 build (published at gh-pages root)
#   <fb14-dir>  directory holding the FreeBSD 14 build (published under freebsd14-amd64/)
set -e

FB15_DIR="$1"
FB14_DIR="$2"
TEMPLATE="$3"
VERSION="$4"
OUT_FILE="$5"

if [ -z "$FB15_DIR" ] || [ -z "$FB14_DIR" ] || [ -z "$TEMPLATE" ] || [ -z "$VERSION" ] || [ -z "$OUT_FILE" ]; then
    echo "usage: gen-index.sh <fb15-dir> <fb14-dir> <template-file> <version> <out-file>" >&2
    exit 1
fi

# builds "<li>" rows for the actual plugin package(s) in a directory - not
# pkg's own internal catalog files (data.pkg, meta.conf, etc.) - with hrefs
# prefixed by $2 (empty for the root/fb15 listing, "freebsd14-amd64/" for
# the other, since both listings live in the one index.html at gh-pages root)
build_rows() {
    dir="$1"
    href_prefix="$2"
    rows=""
    for f in "$dir"/ipsec-watchdog-*.pkg; do
        [ -f "$f" ] || continue
        name=$(basename "$f")
        bytes=$(wc -c < "$f" | tr -d ' ')
        rows="${rows}<li><a href=\"${href_prefix}${name}\">${href_prefix}${name}</a><span class=\"size\">${bytes} bytes</span></li>\n"
    done
    printf '%s' "$rows"
}

ROWS_15=$(build_rows "$FB15_DIR" "")
ROWS_14=$(build_rows "$FB14_DIR" "freebsd14-amd64/")

PUBLISHED_AT=$(date -u '+%Y-%m-%d %H:%M UTC')

awk -v rows15="$ROWS_15" -v rows14="$ROWS_14" -v ver="$VERSION" -v pub="$PUBLISHED_AT" '
{
    gsub(/__FILE_ROWS_15__/, rows15);
    gsub(/__FILE_ROWS_14__/, rows14);
    gsub(/__VERSION__/, ver);
    gsub(/__PUBLISHED_AT__/, pub);
    print
}' "$TEMPLATE" > "$OUT_FILE"
