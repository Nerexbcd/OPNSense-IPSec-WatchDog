# OPNsense IPsec WatchDog

**Watch one or more IPsec tunnels on OPNsense and automatically force a
reconnect on any that stay down too long.**

Adds a real GUI settings page (**VPN > IPsec Watchdog**) with a grid of
watched tunnels — add as many rows as you have connection/child SA pairs to
monitor, each with its own enable toggle and downtime threshold. Connections
and child SAs are picked from dropdowns bound directly to your existing
**VPN > IPsec > Connections** configuration (shown by their description, not
a raw UUID), so there's nothing to hand-type or keep in sync. A "Run watchdog
now" button and a live "Show tunnel status" table (one row per child SA) let
you sanity-check things from the page itself.

The actual check/reconnect logic runs as a single `configd` action that loops
over every enabled row, so one parameter-less cron job covers every tunnel
you're watching no matter how many there are — and that cron job is created
for you automatically the first time you install the package, running every
minute out of the box.

Built with real MVC files pulled from `opnsense/plugins` and `opnsense/core`
as reference, then installed and exercised end-to-end (menu, grid, add/edit/
delete/toggle, watchdog run, status table) against a live OPNsense 26.7 box.
Still worth a test box before your production firewall — every environment
has its own IPsec setup, and this hasn't been through a broader review.

This is a self-hosted/private plugin (built and distributed as a `.pkg`, or
via your own custom `pkg` repo) — it doesn't appear in OPNsense's own
**Firmware > Plugins** list, which only shows packages from OPNsense's
*official* repo. See [Getting into the official plugin list](#getting-into-the-official-plugin-list-out-of-scope-here)
at the bottom if that's ever a goal.

## Requirements

- OPNsense (built and tested against 26.7; should work on nearby versions)
- At least one connection already configured under **VPN > IPsec >
  Connections** — this plugin watches existing tunnels, it doesn't create them

## Installation

Two methods below, **A** and **B** — but they aren't an either/or choice.
They're two independent things:

- **A** is just "get this specific `.pkg` file onto a box and `pkg add` it" —
  always available, on any box, at any time, whether or not that box has
  anything else configured.
- **B** is "point `pkg` at this GitHub repo so `pkg install`/`pkg upgrade`
  work going forward" — a one-time config file per box, not a replacement
  for A.

Having both available is automatic: nothing stops you doing A on a box today
and adding B's repo config to that same box later, or vice versa. A box with
the repo configured can still `pkg add` a one-off `.pkg` file any time
(handy for testing an unreleased build before it's published); installing
that way doesn't remove or conflict with the repo config, and once that
version *is* published, `pkg upgrade` on that box works normally regardless
of how it was first installed.

### Method A — direct install (quickest, no repo config needed)

Good for a single box, or trying it out before setting up the repo config.

1. Get the `.pkg` file onto the box, either:
   - build it there: `git clone` (or `scp`) this repo onto the box, then
     `cd OPNSense-IPSec-WatchDog && sh build.sh` — produces
     `output/ipsec-watchdog-1.3.pkg`; or
   - build it elsewhere and `scp` just the `.pkg` file over.
2. Install it:
   ```sh
   pkg add ipsec-watchdog-1.3.pkg
   ```
3. Continue at [Post-install configuration](#post-install-configuration) below.

To upgrade later with this method: build the newer version's `.pkg` and run
`pkg add` again (or `pkg delete ipsec-watchdog` first, then `pkg add`).

### Method B — install/upgrade straight from this GitHub repo

This repo's own `gh-pages` branch *is* the pkg repo — there's no separate
hosting to set up. `main` holds the source, `gh-pages` holds the built
catalog (`.pkg` + `packagesite.pkg`/`meta.conf`), same repo, same GitHub
account.

**One-time, only if GitHub Pages isn't already enabled for this repo:**
Settings > Pages > Source > deploy from the `gh-pages` branch. That's the
only step that has to happen in GitHub's web UI — everything else is `pkg`
on the box.

**On every OPNsense box you want it on**, point `pkg` at it. OPNsense's
default root shell is `csh`, which doesn't understand bash-style heredocs
(`<< 'EOF' ... EOF`) — it'll just hang waiting for input if you paste one, so
this uses `printf`, which works the same in any shell.

Two FreeBSD bases are published, since a `.pkg` is tagged for one specific
base and OPNsense bumps it periodically (26.7 is FreeBSD 15; older releases
like 26.1.x are FreeBSD 14). Run `pkg config abi` on the box first to check
which one you have:

**FreeBSD 15** (current OPNsense, e.g. 26.7+):

```sh
printf '%s\n' \
  'ipsecwatchdog: {' \
  '  url: "https://nerexbcd.github.io/OPNSense-IPSec-WatchDog/",' \
  '  enabled: yes' \
  '}' \
  > /usr/local/etc/pkg/repos/ipsecwatchdog.conf
pkg update
pkg install ipsec-watchdog
```

**FreeBSD 14** (older OPNsense, e.g. 25.1–26.1.x) — same idea, different URL:

```sh
printf '%s\n' \
  'ipsecwatchdog: {' \
  '  url: "https://nerexbcd.github.io/OPNSense-IPSec-WatchDog/freebsd14-amd64/",' \
  '  enabled: yes' \
  '}' \
  > /usr/local/etc/pkg/repos/ipsecwatchdog.conf
pkg update
pkg install ipsec-watchdog
```

Picking the wrong one fails cleanly at `pkg update` (`wrong architecture:
FreeBSD:15:amd64 instead of FreeBSD:14:amd64` or similar) rather than
installing something broken — if you see that, switch to the other URL.

Continue at [Post-install configuration](#post-install-configuration) below.

**To publish a new version, the automated way:** bump `version:` in
`manifest/+MANIFEST`, commit, tag it, push the tag, then turn that tag into
a GitHub Release:

```sh
git tag v1.3
git push origin v1.3
```

Then on GitHub: **Releases > Draft a new release**, pick the tag you just
pushed, **Publish release**. That triggers
[`.github/workflows/publish-pkg-repo.yml`](.github/workflows/publish-pkg-repo.yml),
which builds the `.pkg` for every supported FreeBSD base on a real FreeBSD VM
(`pkg create`/`pkg repo` have no Linux/macOS/Windows equivalent, so it can't
run directly on GitHub's standard runners) and pushes the refreshed catalogs
to `gh-pages` for you — watch the **Actions** tab for progress. Drafting or
editing release notes never triggers it; only actually clicking **Publish
release** does.

**To publish a new version by hand instead** (no CI, e.g. while testing
changes to the workflow itself):

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

`--force` is expected here — `gh-pages` only ever holds the latest catalogs,
it has no history worth keeping. Every box with either repo config above then
just needs `pkg update && pkg upgrade ipsec-watchdog`.

Adding a third FreeBSD base later just means adding one more `ABI:OSVERSION`
entry to `build.sh`'s `ABIS` list and one more `cp`/`pkg repo`/directory here
(and in the CI workflow) — nothing else about this scheme changes.

Optionally sign each repo with a key (`pkg repo /tmp/pkgrepo <keyfile>`) for
integrity checking — recommended since this pulls over plain GitHub Pages
rather than a signed official repo.

## Post-install configuration

Neither install method does this part for you — it's config, not packaging.

1. **Confirm the menu entry appeared.** **VPN > IPsec Watchdog** should be in
   the left menu. If it isn't yet, restart the web GUI once:
   ```sh
   configctl webgui restart
   ```
2. **Add a tunnel to watch.** On the IPsec Watchdog page, click **+ Add**:
   pick the connection and child SA from the dropdowns (populated from
   **VPN > IPsec > Connections**), set a downtime threshold in minutes, save.
   Repeat for every connection/child SA pair you want watched — a connection
   with more than one child SA just needs one row per child SA, all pointing
   at the same connection.
3. **Sanity check before relying on cron.** Click **Run watchdog now** and
   **Show tunnel status** on the page to confirm it sees your tunnel(s)
   correctly.
4. **Nothing to do for cron — it's already scheduled.** Installing the
   package automatically adds a cron job (**System > Settings > Cron**,
   labeled "IPsec Tunnel Watchdog (auto-added, runs every minute)") that
   runs the check/reconnect pass for every tunnel row you've added above,
   every minute. This only happens once, the first time the package is ever
   installed — reinstalling or upgrading later won't create a second one.

   **To change how often it runs:** open that job under System > Settings >
   Cron and edit the **Minute** field (e.g. `*/5` for every 5 minutes) —
   Hour/Day/Month/Weekday work the same way if you want something less
   frequent than "every minute". Whatever you set here survives future
   upgrades of this plugin; it's never reset back to the default. The
   **Command** and **Parameters** fields are locked (OPNsense protects
   auto-registered jobs from being repointed at a different action) — that's
   expected, only the schedule/enabled/description are yours to change.

Check logs any time with:

```sh
grep ipsec-watchdog /var/log/system/latest.log
```

To remove entirely: `pkg delete ipsec-watchdog`. This does **not** remove
the cron job it created (OPNsense won't let a plugin silently delete a
schedule you may have customized) — if you're uninstalling for good, delete
that cron entry yourself from System > Settings > Cron afterwards; once the
package is gone, OPNsense will allow deleting it (it only protects jobs
belonging to a still-installed plugin).

## What's in here

```
root/usr/local/opnsense/mvc/app/models/OPNsense/IPsecWatchdog/     # model, menu, ACL
root/usr/local/opnsense/mvc/app/controllers/OPNsense/IPsecWatchdog/ # index + API controllers, form def
root/usr/local/opnsense/mvc/app/views/OPNsense/IPsecWatchdog/       # settings page template
root/usr/local/opnsense/scripts/OPNsense/IPsecWatchdog/watchdog.php # the actual check/reconnect logic
root/usr/local/opnsense/scripts/OPNsense/IPsecWatchdog/manage_cron.php # one-time cron job registration on install
root/usr/local/opnsense/service/conf/actions.d/                    # configd action registration
manifest/+MANIFEST   # pkg manifest
plist                # files the package owns
build.sh             # builds the .pkg
```

## Getting into the official plugin list (out of scope here)

OPNsense's **Firmware > Plugins** list only shows packages from OPNsense's
own official repo — a custom repo like this one, however it's hosted, will
never appear there. Getting listed means submitting to
`github.com/opnsense/plugins` and going through their review process, which
uses a different, ports-style build (`Makefile` + `Mk/plugins.mk` + `files/`
instead of this repo's `+MANIFEST`/`plist`/`root/`) and comes with an ongoing
maintenance commitment. That's a separate, materially bigger undertaking from
everything above and isn't attempted in this repo.

## License

BSD 2-Clause, see [LICENSE](LICENSE). See [CHANGELOG.md](CHANGELOG.md) for
release notes.
