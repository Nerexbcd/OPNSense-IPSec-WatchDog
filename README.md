# OPNsense IPsec WatchDog

**Your IPsec tunnels sometimes drop. This notices and reconnects them
automatically, so you don't have to.**

![IPsec Watchdog settings page, showing one watched tunnel ("On-Prem") and its live status: ESTABLISHED / INSTALLED](docs/img/Showcase.png)

## What is this?

If you run OPNsense and rely on one or more IPsec VPN tunnels — a
site-to-site link to another office, a cloud provider, whatever — you've
probably run into this: a tunnel silently goes down (the remote end
rebooted, an ISP hiccuped, whatever the reason) and it just *stays* down
until a human notices and manually reconnects it.

This plugin does the noticing and reconnecting for you. It adds a page to
OPNsense, **VPN > IPsec Watchdog**, where you list the tunnels you care
about — picked from your existing IPsec setup, not typed by hand — and a
background job checks every one of them once a minute. If a tunnel has been
down longer than the threshold you set for it, the watchdog forces it back
up. No SSH, no remembering to check, no waiting for someone to notice.

That's the whole screenshot above: one tunnel ("On-Prem"), a 5-minute
threshold, and a live status check underneath it (pulled straight from
strongSwan) showing it's currently up — `ESTABLISHED` on the IKE side,
`INSTALLED` on the child SA.

### Why this instead of just checking manually

- **It doesn't rely on you remembering.** That's the entire point — it runs
  unattended, once a minute, forever.
- **Nothing to type or keep in sync.** The connection and child SA dropdowns
  are wired directly into your real **VPN > IPsec > Connections** config.
- **Handles as many tunnels as you have.** One row per tunnel (or per child
  SA, if a tunnel carries more than one) — one background job covers all
  of them, each with its own independent threshold.
- **Still fully visible when you want it.** "Run watchdog now" and "Show
  tunnel status" give you an on-demand check without touching a shell.

### How it actually works, briefly

Every enabled row you add gets checked once a minute by a `configd` action
(`watchdog.php`), which asks strongSwan directly (`swanctl`) whether that
tunnel's child SA is actually up. If it's been down longer than the row's
threshold, it runs `swanctl --initiate` to force it back up. The one-minute
schedule is a normal OPNsense cron job — created for you automatically the
first time you install the package (see [Post-install
configuration](#post-install-configuration) for how to change it).

Built against real OPNsense MVC framework files as reference, then installed
and exercised end-to-end — menu, the tunnel grid, add/edit/delete/toggle,
running the watchdog, the live status table — against a real OPNsense 26.7
box throughout development. Still worth trying on a test box before your
production firewall; every environment's IPsec setup is a little different,
and this hasn't been through a broader public review.

This is a self-hosted/private plugin (built and distributed as a `.pkg`, or
via a small GitHub-hosted repo — see below) — it won't show up in OPNsense's
own **Firmware > Plugins** list, which only lists packages from OPNsense's
*official* repo. More on that trade-off in [Getting into the official plugin
list](#getting-into-the-official-plugin-list-out-of-scope-here) near the
bottom, if it ever matters to you.

## Requirements

- OPNsense (built and tested against 26.7; a FreeBSD 14 build is also
  published for older releases like 25.1–26.1.x — see [Installation](#installation))
- At least one connection already configured under **VPN > IPsec >
  Connections** — this plugin watches existing tunnels, it doesn't create them

## Installation

Two methods, **A** and **B** — not an either/or choice, just two independent
things:

- **A**: get a specific `.pkg` file onto a box and `pkg add` it. Always
  available, any box, any time.
- **B**: point `pkg` at this GitHub repo once, so `pkg install`/`pkg
  upgrade` work from then on.

You can do A today and add B later on the same box, or the other way
around — they don't conflict, and once a version is published to the repo,
`pkg upgrade` works regardless of which method originally installed it.

### Method A — direct install (quickest, no repo config needed)

Good for a single box, or trying it out before setting up the repo config.

1. Get the `.pkg` file onto the box, either:
   - build it there: `git clone` (or `scp`) this repo onto the box, then
     `cd OPNSense-IPSec-WatchDog && sh build.sh` — produces
     `output/<FreeBSD-ABI>/ipsec-watchdog-1.3.pkg`; or
   - build it elsewhere and `scp` just the `.pkg` file over.
2. Install it:
   ```sh
   pkg add ipsec-watchdog-1.3.pkg
   ```
3. Continue at [Post-install configuration](#post-install-configuration) below.

To upgrade later with this method: build the newer version's `.pkg` and run
`pkg add` again (or `pkg delete ipsec-watchdog` first, then `pkg add`).

### Method B — install/upgrade straight from this GitHub repo

This repo's own `gh-pages` branch *is* the pkg repo — nothing separate to
host. `main` holds the source; `gh-pages` holds the built packages and
catalogs.

**One-time, only if GitHub Pages isn't already enabled for this repo:**
Settings > Pages > Source > deploy from the `gh-pages` branch.

**On every OPNsense box you want it on**, point `pkg` at it. Two FreeBSD
bases are published, since OPNsense bumps its base periodically (26.7 runs
FreeBSD 15; older releases like 26.1.x run FreeBSD 14) and a `.pkg` is
tagged for one specific base. Run `pkg config abi` on the box first to see
which you have:

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

(That `printf` instead of a heredoc is deliberate — OPNsense's default root
shell is `csh`, which doesn't understand `<< 'EOF' ... EOF` and just hangs
waiting for input if you paste one in.)

Picking the wrong FreeBSD version fails cleanly at `pkg update` (`wrong
architecture: FreeBSD:15:amd64 instead of FreeBSD:14:amd64` or similar)
rather than installing something broken — if you see that, switch to the
other URL.

Continue at [Post-install configuration](#post-install-configuration) below.

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
   correctly — this is exactly what the screenshot at the top shows.
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

---

Everything below this line is about developing or maintaining the plugin
itself, not using it — skip it unless that's what you're here for.

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
build.sh             # builds the .pkg for every supported FreeBSD base
pkgsite/              # gh-pages install guide template + generator
.github/workflows/    # CI: auto-publish to gh-pages on every GitHub Release
```

## Publishing a new version

**Automated:** bump `version:` in `manifest/+MANIFEST`, commit, then:

```sh
git tag v1.3
git push origin v1.3
```

On GitHub: **Releases > Draft a new release**, pick that tag, **Publish
release**. That triggers
[`.github/workflows/publish-pkg-repo.yml`](.github/workflows/publish-pkg-repo.yml),
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
history worth keeping. Every box with either repo config from
[Installation](#installation) then just needs
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

## License

BSD 2-Clause, see [LICENSE](LICENSE). See [CHANGELOG.md](CHANGELOG.md) for
release notes.
