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
matter how many tunnels you're watching — pick "IPsec Tunnel Watchdog" as the
Command on the stock **System > Settings > Cron** page.

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

Built with real MVC files pulled from `opnsense/plugins` and `opnsense/core`
as reference, then installed and exercised end-to-end (menu, grid, add/edit/
delete/toggle, watchdog run, status table) against a live OPNsense 26.7 box.
Still worth a test box before your production firewall — every environment
has its own IPsec setup, and this hasn't been through a broader review.

## 1. Build the package

Copy this whole folder onto the OPNsense box (scp, git clone, whatever), then:

```sh
cd opnsense-ipsec-watchdog
sh build.sh
```

This runs `pkg create` and produces `output/os-ipsec-watchdog-1.1.pkg`
(modern `pkg(8)` always names its output `.pkg` regardless of the internal
compression format, so it's not a `.txz` despite `build.sh` passing `-f txz`).

## 2. Test-install it locally first

```sh
pkg add output/os-ipsec-watchdog-1.1.pkg
```

Then in the GUI: **VPN > IPsec Watchdog** should appear in the left menu. If
it doesn't show up immediately, restart the web GUI:

```sh
configctl webgui restart
```

Click **+ Add** to add a tunnel: pick the connection and child SA from the
dropdowns (populated from **VPN > IPsec > Connections**), set a threshold,
save, then click **Run watchdog now** to sanity check before relying on cron.
A connection with more than one child SA just needs one row per child SA you
want watched, all pointing at the same connection.

Check logs:

```sh
grep ipsec-watchdog /var/log/system/latest.log
```

To remove during testing:

```sh
pkg delete os-ipsec-watchdog
```

## 3. Set up the cron job

**System > Settings > Cron > +**
- Command: **IPsec Tunnel Watchdog**
- Minute: `*/1`, rest: `*`
- Parameters: leave blank

## 4. Host it on GitHub as a real installable plugin

This is what makes it show up like a normal package via `pkg add`/a custom
repo, instead of manually copying the `.pkg` file around.

**a) Push the source to GitHub** (for version history / rebuilding):

```sh
git init
git add .
git commit -m "Initial IPsec watchdog plugin"
git remote add origin https://github.com/Nerexbcd/opnsense-ipsec-watchdog.git
git push -u origin main
```

**b) Build a pkg repository catalog** (this is what OPNsense's package manager
actually reads — not the git repo itself). On the box, after building the
package:

```sh
mkdir -p /root/pkgrepo
cp output/os-ipsec-watchdog-1.1.pkg /root/pkgrepo/
pkg repo /root/pkgrepo
```

This generates `packagesite.pkg`/`meta.conf` catalog files inside
`/root/pkgrepo`. Optionally sign the repo with a key (`pkg repo /root/pkgrepo
<keyfile>`) if you want integrity checking — recommended if you'll pull this
over plain GitHub raw URLs.

**c) Publish the repo directory to GitHub** — easiest via a `gh-pages` branch
or GitHub Pages, since you need these files served over plain HTTP(S):

```sh
cd /root/pkgrepo
git init
git add .
git commit -m "pkg repo catalog"
git branch -M gh-pages
git remote add origin https://github.com/Nerexbcd/opnsense-ipsec-watchdog.git
git push -u origin gh-pages
```

Then enable GitHub Pages for that branch in the repo's Settings, giving you a
URL like `https://Nerexbcd.github.io/opnsense-ipsec-watchdog/`.

**d) Point OPNsense at it** — create a repo config on the firewall:

```sh
cat > /usr/local/etc/pkg/repos/ipsecwatchdog.conf << 'EOF'
ipsecwatchdog: {
  url: "https://Nerexbcd.github.io/opnsense-ipsec-watchdog/",
  enabled: yes
}
EOF
pkg update
pkg install os-ipsec-watchdog
```

From then on it behaves like any other plugin — `pkg upgrade` picks up new
versions when you bump `version:` in `+MANIFEST`, rebuild, and re-push the
`gh-pages` catalog.

**Caveat on OPNsense's own Firmware > Plugins list:** that page only lists
packages from OPNsense's *official* repo. A custom repo like this one won't
appear there — you install/upgrade it via `pkg` on the command line as above.
Getting it into the official list means submitting to
`github.com/opnsense/plugins` and going through their review process, which is
a materially bigger undertaking (their ports-style `Mk/plugins.mk` build
framework, code review, ongoing maintenance commitment).

## 5. Bumping the version later

Edit `watchdog.php` or any file, bump `version:` in `manifest/+MANIFEST`,
re-run `build.sh`, re-run `pkg repo /root/pkgrepo`, re-push `gh-pages`.

## License

BSD 2-Clause, see [LICENSE](LICENSE).
