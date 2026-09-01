#!/bin/sh
# Run this ON the OPNsense box (or any FreeBSD host with pkg(8)) from inside
# this directory. Produces output/<ABI>/ipsec-watchdog-<version>.pkg for each
# ABI in $ABIS below.
#
# This plugin is pure PHP/XML/JS with zero compiled code, so the exact same
# file contents work correctly on any FreeBSD major version - the only thing
# that differs between OPNsense releases on different FreeBSD bases is the
# ABI tag pkg stamps on the package, which gates whether `pkg install` will
# even consider it. Rather than needing an actual build host per FreeBSD
# version, `pkg -o ABI=... -o OSVERSION=...` retags the build for another
# ABI from right here - verified this produces a correctly-tagged package
# (checked with `pkg info -F`) without changing a single file inside it.
#
# Add another "FreeBSD:NN:amd64|OSVERSION" pair to ABIS below to support an
# older/newer OPNsense base later; nothing else needs to change.
set -e
cd "$(dirname "$0")"

# ABI:OSVERSION pairs. OSVERSION is the lowest release of that major branch
# (e.g. 1400000 = 14.0-RELEASE) so it stays valid for every point release on
# that branch.
ABIS="FreeBSD:14:amd64:1400000 FreeBSD:15:amd64:1500000"

for entry in $ABIS; do
    ABI="${entry%:*}"
    OSVERSION="${entry##*:}"
    OUTDIR="output/${ABI}"
    mkdir -p "$OUTDIR"
    # -f txz: modern pkg(8) defaults to tzst (zstd); txz is more broadly
    # compatible with older pkg builds. Note pkg still names the output file
    # with a .pkg extension regardless of -f (only the internal compression
    # differs), so this script and the README look for *.pkg, not *.txz.
    pkg -o ABI="$ABI" -o OSVERSION="$OSVERSION" create -f txz -m manifest -r root -p plist -o "$OUTDIR"
done

echo "Built:"
find output -name '*.pkg' -exec ls -la {} \;
