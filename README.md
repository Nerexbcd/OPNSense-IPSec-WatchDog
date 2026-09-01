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
over every enabled row, so it schedules as one parameter-less cron job no
matter how many tunnels you're watching.

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

Pick **one** of the two methods below. Both end with the same result — the
choice is really "how do I want to redo this on my *next* box".

### Method A — direct install (quickest, manual every time)

Good for a single box, or trying it out before committing to hosting a repo.

1. Get the `.pkg` file onto the box, either:
   - build it there: `git clone` (or `scp`) this repo onto the box, then
     `cd OPNSense-IPSec-WatchDog && sh build.sh` — produces
     `output/os-ipsec-watchdog-1.1.pkg`; or
   - build it elsewhere and `scp` just the `.pkg` file over.
2. Install it:
   ```sh
   pkg add os-ipsec-watchdog-1.1.pkg
   ```
3. Continue at [Post-install configuration](#post-install-configuration) below.

To upgrade later with this method: build the newer version's `.pkg` and run
`pkg add` again (or `pkg delete os-ipsec-watchdog` first, then `pkg add`).

### Method B — your own pkg repo (repeatable, `pkg upgrade` works)

Good for multiple boxes or repeat deployments — install/upgrade becomes a
normal `pkg install`/`pkg upgrade`, no file copying.

**On a build machine** (can be the same OPNsense box, or any FreeBSD host
with `pkg`):

```sh
git clone https://github.com/Nerexbcd/OPNSense-IPSec-WatchDog.git
cd OPNSense-IPSec-WatchDog
sh build.sh                              # -> output/os-ipsec-watchdog-1.1.pkg

mkdir -p /root/pkgrepo
cp output/os-ipsec-watchdog-1.1.pkg /root/pkgrepo/
pkg repo /root/pkgrepo                   # generates the repo catalog files
```

Publish `/root/pkgrepo`'s contents somewhere served over plain HTTP(S) — a
`gh-pages` branch/GitHub Pages is the easiest option:

```sh
cd /root/pkgrepo
git init
git add .
git commit -m "pkg repo catalog"
git branch -M gh-pages
git remote add origin https://github.com/Nerexbcd/OPNSense-IPSec-WatchDog.git
git push -u origin gh-pages
```

Then enable GitHub Pages for the `gh-pages` branch in the repo's Settings,
giving you a URL like `https://Nerexbcd.github.io/OPNSense-IPSec-WatchDog/`.

**On every OPNsense box you want it on**, point `pkg` at that repo:

```sh
cat > /usr/local/etc/pkg/repos/ipsecwatchdog.conf << 'EOF'
ipsecwatchdog: {
  url: "https://Nerexbcd.github.io/OPNSense-IPSec-WatchDog/",
  enabled: yes
}
EOF
pkg update
pkg install os-ipsec-watchdog
```

Continue at [Post-install configuration](#post-install-configuration) below.

To upgrade later with this method: bump `version:` in `manifest/+MANIFEST`,
re-run `build.sh`, copy the new `.pkg` into `/root/pkgrepo`, re-run
`pkg repo /root/pkgrepo`, re-push the `gh-pages` branch. Every box then just
needs `pkg update && pkg upgrade os-ipsec-watchdog`.

Optionally sign the repo with a key (`pkg repo /root/pkgrepo <keyfile>`) for
integrity checking — recommended since this pulls over plain GitHub Pages/raw
URLs rather than a signed official repo.

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
4. **Add the cron job — this step is required, it is not automatic.**
   Installing the package only registers "IPsec Tunnel Watchdog" as an
   available Command; nothing runs on a schedule until you add it yourself:
   **System > Settings > Cron > +**
   - Command: **IPsec Tunnel Watchdog**
   - Minute: `*/1`, rest: `*`
   - Parameters: leave blank (settings come from the GUI page above, not cron
     args — one cron job covers every tunnel you've added)

Check logs any time with:

```sh
grep ipsec-watchdog /var/log/system/latest.log
```

To remove entirely: `pkg delete os-ipsec-watchdog` (also removes the cron
Command option — delete the actual cron job entry first if you added one).

## What's in here

```
root/usr/local/opnsense/mvc/app/models/OPNsense/IPsecWatchdog/     # model, menu, ACL
root/usr/local/opnsense/mvc/app/controllers/OPNsense/IPsecWatchdog/ # index + API controllers, form def
root/usr/local/opnsense/mvc/app/views/OPNsense/IPsecWatchdog/       # settings page template
root/usr/local/opnsense/scripts/OPNsense/IPsecWatchdog/watchdog.php # the actual check/reconnect logic
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
