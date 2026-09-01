# Changelog

All notable changes to this plugin are documented here. Versions match the
`version:` field in `manifest/+MANIFEST`.

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
