# Changelog

All notable changes to this plugin are documented here. Versions match the
`version:` field in `manifest/+MANIFEST`.

## 1.4 — webhook notifications

- **Webhook notifications.** New "Notifications" section on the settings
  page: a global webhook URL, an optional signing secret, a "notify after N
  failed attempts" threshold (default 3), and three independent event
  toggles - notify when a tunnel goes down, when it's still down after N
  attempts (on by default; the other two are off by default), and when it
  comes back up. Any combination can be enabled at once. Any tunnel row can
  also independently override the webhook URL and/or the attempts threshold
  just for that row. A **Test webhook** button sends a small test payload to
  whatever URL is currently typed in, even before Save is clicked. Every
  event fires once per outage/recovery, not every minute. An optional
  signing secret adds an `X-Watchdog-Signature: sha256=...` header so a
  receiver can verify a request really came from this plugin. Every payload
  includes a `tunnel_name` - a human-readable name (the row's own label if
  set, otherwise the connection's/child's own description from VPN > IPsec
  > Connections) rather than just raw connection/child UUIDs. See
  [docs/notifications.md](docs/notifications.md) for the full payload
  reference and a worked example timeline.

## 1.3 — package renamed, `os-ipsec-watchdog` → `ipsec-watchdog` (breaking)

- **Renamed the package** from `os-ipsec-watchdog` to `ipsec-watchdog`.
  OPNsense's own Firmware > Plugins install flow (`firmware/install.sh`)
  treats any package name starting with `os-` as an *official-style*
  plugin and refuses to install it until the base OS itself is fully
  patched to the latest point release — reasonable for OPNsense's own
  plugins, not something a small third-party tool should impose. Plain
  `pkg install`/`pkg add` from the CLI never hit this (only the GUI's
  install button calls that script), which is why it wasn't caught
  until testing the GUI path specifically. Confirmed by reproducing the
  exact error via `firmware/install.sh` directly, then confirming it's
  gone once the package no longer starts with `os-`.
- **If you have 1.2 installed**, this isn't a normal upgrade — `pkg`
  won't rename a package in place. Remove the old one and install the
  new one:
  ```sh
  pkg delete os-ipsec-watchdog
  pkg install ipsec-watchdog
  ```
  Your tunnel configuration and the auto-added cron job both live in
  `config.xml`/the Cron model, not tied to the package name — both
  survive this switch untouched.
- No functional/behavioral changes beyond the name.

## 1.2

- **Cron job is now created automatically on first install** (System >
  Settings > Cron, every minute) — no more manual "add the cron job"
  step. Runs once ever, on the very first install: reinstalling or
  upgrading never creates a duplicate, and a schedule you've since
  customized in the GUI is never reset back to the default. Uninstalling
  the package intentionally leaves the job in place (OPNsense also blocks
  deleting it while the package is still installed) - remove it by hand
  from System > Settings > Cron if you're uninstalling for good.
- Added `categories`/`licenselogic`/`licenses` fields to
  `manifest/+MANIFEST`.

## 1.1

- **Multi-tunnel grid UI.** Replaced the single connection/child settings
  form with a grid (VPN > IPsec Watchdog): add, edit, enable/disable and
  delete any number of watched tunnels, each with its own threshold.
- **Native connection picker.** Connection/child SA fields are bound
  directly to OPNsense's own IPsec (Swanctl) model and shown by their
  configured description instead of a raw UUID, with a fallback to the
  child SA's traffic selectors when no description is set.
- **Live tunnel status table.** "Show tunnel status" now parses
  `swanctl --list-sas` into a table (one row per child SA) instead of a raw
  text dump, with the raw output still available behind a toggle.
- **Watchdog now checks every enabled row per run**, each with its own
  independent downtime tracker, instead of a single hardcoded pair.
- Fixed `pkg create` failing on `manifest/+MANIFEST` (UCL heredoc syntax,
  not YAML block scalars).
- Fixed the package's own `post-install` script silently failing to restart
  the web GUI (this OPNsense box manages it via `configctl webgui`, not the
  generic `service lighttpd` used by the original scaffold) — new installs
  now reliably show the menu entry without a manual GUI restart.
- Fixed `configd` actions swallowing real error output behind a generic
  "Execute error" (missing `errors:no`) and swanctl errors going to stderr,
  which `configd` doesn't return to the caller (missing `2>&1`).

## 1.0

- Initial release: single watched connection/child SA pair, GUI settings
  page, "Run watchdog now" / "Show tunnel status" buttons, `configd` action
  for scheduling from System > Settings > Cron.
