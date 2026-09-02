# Maintainer notes

Notes for whoever is publishing new versions of this plugin or considering
its future — not needed to install or use it. See the main
[README](../README.md) for that.

## Publishing a new version

**Automated:** bump `version:` in `manifest/+MANIFEST`, commit, then:

```sh
git tag v1.3
git push origin v1.3
```

On GitHub: **Releases > Draft a new release**, pick that tag, **Publish
release**. That triggers
[`.github/workflows/publish-pkg-repo.yml`](../.github/workflows/publish-pkg-repo.yml),
which builds the `.pkg` for every FreeBSD base on a real FreeBSD VM
(`pkg create`/`pkg repo` have no Linux/macOS/Windows equivalent) and pushes
the refreshed catalogs to `gh-pages` — watch the **Actions** tab. Drafting
or editing release notes never triggers it, only actually clicking
**Publish release** does.

**By hand instead** (no CI, e.g. while testing changes to the workflow
itself):

```sh
sh build.sh   # after bumping version: in manifest/+MANIFEST - builds every ABI in build.sh's ABIS list

# build each ABI's catalog in its own directory - pkg repo scans recursively,
# so nesting one inside the other would mix both ABIs into the same catalog
rm -rf /tmp/pkgrepo-root /tmp/pkgrepo-fb14
mkdir -p /tmp/pkgrepo-root /tmp/pkgrepo-fb14
cp "output/FreeBSD:15:amd64"/ipsec-watchdog-*.pkg /tmp/pkgrepo-root/
cp "output/FreeBSD:14:amd64"/ipsec-watchdog-*.pkg /tmp/pkgrepo-fb14/
pkg repo /tmp/pkgrepo-root
pkg repo /tmp/pkgrepo-fb14
sh pkgsite/gen-index.sh /tmp/pkgrepo-root /tmp/pkgrepo-fb14 pkgsite/index.template.html 1.3 /tmp/pkgrepo-root/index.html

# now merge into the actual publish layout: fb14's catalog nests under
# freebsd14-amd64/ only at this final step, as plain files pkg repo never
# has to scan again
mkdir -p /tmp/pkgrepo-root/freebsd14-amd64
cp /tmp/pkgrepo-fb14/* /tmp/pkgrepo-root/freebsd14-amd64/

cd /tmp/pkgrepo-root
git init && git add -A && git commit -m "pkg repo catalogs"
git push https://github.com/Nerexbcd/OPNSense-IPSec-WatchDog.git master:gh-pages --force
```

`--force` is expected — `gh-pages` only ever holds the latest catalogs, no
history worth keeping. Every box with either repo config from the
[README's Installation section](../README.md#installation) then just needs
`pkg update && pkg upgrade ipsec-watchdog`.

Adding a third FreeBSD base later just means one more `ABI:OSVERSION` entry
in `build.sh`'s `ABIS` list and one more `cp`/`pkg repo`/directory here (and
in the CI workflow) — nothing else about this scheme changes.

Optionally sign each repo with a key (`pkg repo /tmp/pkgrepo-root
<keyfile>`) for integrity checking — recommended since this pulls over
plain GitHub Pages rather than a signed official repo.

## Getting into the official plugin list (out of scope here)

OPNsense's **Firmware > Plugins** list only shows packages from OPNsense's
own official repo — a custom repo like this one, however it's hosted, will
never appear there. Getting listed means submitting to
`github.com/opnsense/plugins` and going through their review process, which
uses a different, ports-style build (`Makefile` + `Mk/plugins.mk` + `files/`
instead of this repo's `+MANIFEST`/`plist`/`root/`) and comes with an ongoing
maintenance commitment. That's a separate, materially bigger undertaking from
everything above and isn't attempted in this repo.
