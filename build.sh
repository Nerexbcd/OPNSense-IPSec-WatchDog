#!/bin/sh
# Run this ON the OPNsense box (or any FreeBSD host with pkg(8)) from inside
# this directory. Produces output/os-ipsec-watchdog-<version>.txz
set -e
cd "$(dirname "$0")"
mkdir -p output
# -f txz: modern pkg(8) defaults to tzst (zstd); txz is more broadly
# compatible with older pkg builds. Note pkg still names the output file
# with a .pkg extension regardless of -f (only the internal compression
# differs), so build.sh and the README below look for *.pkg, not *.txz.
pkg create -f txz -m manifest -r root -p plist -o output
echo "Built:"
ls -la output/*.pkg
