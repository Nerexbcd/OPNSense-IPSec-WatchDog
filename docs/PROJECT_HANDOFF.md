# Project handoff notes

This is a written summary of how this plugin was built, debugged, and
published — not a raw chat transcript. It exists so anyone (a different
person, or a fresh Claude/Claude Code session with no prior context) can
pick this project up without re-discovering everything the hard way.

If you're a Claude session reading this to get oriented: start with the
[README](../README.md) for what the plugin does and how to install it, and
[maintainer-notes.md](maintainer-notes.md) for the publish/release process.
This document is the "why does it look like this" history behind both.

No credentials are stored anywhere in this repo, including here — SSH
access to any test box, GitHub tokens, etc. belong to whoever is running
the session and are never written to files.

## What the plugin is

**OPNsense IPsec WatchDog** (`ipsec-watchdog`) is an OPNsense plugin that
watches one or more IPsec (swanctl) connection/child-SA pairs and
force-reconnects any that stay down past a per-tunnel threshold. It adds a
**VPN > IPsec Watchdog** grid page (add/edit/delete/enable tunnels, run an
on-demand check, view live status) and a `configd`-driven check that a cron
job (auto-registered on install, default every minute) runs unattended.

## Development approach

Built by iterating against a real OPNsense 26.7 box over SSH: every fix in
this project's history was installed and exercised on that real box rather
than assumed correct from reading code. Several real bugs only ever surfaced
that way (see below) — this is why the workflow throughout has been
"change → build → install on the box → click through the UI → confirm the
log/behavior", not "change → assume it works".

Things that remain genuinely **unverified** as of this writing:
- The multi-ABI GitHub Actions publish workflow
  (`.github/workflows/publish-pkg-repo.yml`) has never actually run — it
  triggers only on a published GitHub Release, and no release has been
  published yet. It's believed correct (built by carefully mirroring the
  by-hand publish steps) but should be watched closely on its first real run.
- Install on real FreeBSD 14 hardware — only FreeBSD 15 (the box actually
  available for testing) has been exercised. The FreeBSD 14 `.pkg` is built
  by ABI-retagging the same package (there's no compiled code, so this is
  safe), but nothing has installed and run it on FreeBSD 14 itself.
- Compatibility with OPNsense 26.1.10 specifically at the code/API level
  (as opposed to just the FreeBSD-base/ABI level, which *is* handled).

## Architecture, briefly

Standard OPNsense MVC plugin layout under `root/usr/local/opnsense/`:
- `mvc/app/models/OPNsense/IPsecWatchdog/` — `IPsecWatchdog.xml`/`.php`
  model (an `ArrayField` of tunnel rows), `Menu.xml`, `ACL.xml`
- `mvc/app/controllers/OPNsense/IPsecWatchdog/` — `IndexController` (Volt
  page) + `Api/TunnelController` (`ApiMutableModelControllerBase` CRUD +
  custom `run`/`status` actions), `forms/tunnel.xml`
- `mvc/app/views/OPNsense/IPsecWatchdog/index.volt` — the grid page
- `scripts/OPNsense/IPsecWatchdog/watchdog.php` — the actual check/reconnect
  logic, invoked via a `configd` action (`service/conf/actions.d/`)
- `scripts/OPNsense/IPsecWatchdog/manage_cron.php` — registers the cron job
  exactly once (identifies "its" job by `origin`, never touches it again)

Packaging: `manifest/+MANIFEST` (UCL, not YAML — matters for the heredoc
syntax used for install scripts) + `plist` + `build.sh`, following `pkg(8)`
conventions rather than the ports-style layout the official
`opnsense/plugins` repo expects (see maintainer-notes.md).

The tunnel/child-SA pickers are native `ModelRelationField`s bound to
`OPNsense\IPsec\Swanctl` (`Connections.Connection` / `children.child`) — not
custom JS dropdowns. That distinction mattered a lot; see below.

## Key bugs found during development (and what they teach)

Roughly chronological. Kept because each one reflects something non-obvious
about OPNsense specifically, not just "a bug was fixed":

1. **UCL manifest heredoc syntax.** `+MANIFEST` looks YAML-ish but is UCL;
   its heredoc form is `<<EOD ... EOD`, not YAML block scalars.
2. **`pkg create` output is `.pkg`, not `.txz`**, despite `txz` being the
   format name passed to `-t`. Any docs/scripts assuming `.txz` need fixing.
3. **`configd` swallows real errors behind a generic "Execute error"** unless
   the action config sets `errors:no`; and a called script's own stderr
   (e.g. from `swanctl`) needs explicit `2>&1` or it's lost entirely. Without
   both, failures are silent and undebuggable from the GUI.
4. **The OPNsense menu is cached** at
   `/var/lib/php/tmp/opnsense_menu_cache.xml` for about an hour. A new menu
   entry not appearing after install is almost always this, not a real bug
   — `rm -f` that file and restart the web GUI.
5. **Broken/blank dropdowns had three independent causes stacked**, not one:
   - `post-install` restarting the web server with a mechanism
     (`service lighttpd restart`) that doesn't work on this box — needed
     `configctl webgui restart` instead.
   - A PHP fatal from an API controller method signature that didn't match
     its parent class (`ApiMutableModelControllerBase` expects specific
     optional-parameter defaults, e.g. `$uuid = null` — mismatching the
     base class's signature is a hard fatal, not a warning).
   - A `'use strict'` JS bug that broke the project's own
     `ajaxGet(url = ...)`-style default-parameter idiom.
   All three had to be found and fixed before the UI actually worked — fixing
   only one or two still looked broken.
6. **Custom JS-driven dropdowns were fundamentally the wrong approach.**
   Replacing them with a native `ModelRelationField` bound to the real
   `OPNsense\IPsec\Swanctl` model is what actually made selection reliable —
   this is the standard OPNsense convention for "pick from another model's
   records" and should be preferred over hand-rolled JS from the start.
7. **`csh` (OPNsense's default root shell) can't run heredocs** — `<< 'EOF'
   ... EOF` just hangs at a `?` prompt waiting for more input. Every
   copy-pasteable shell snippet in this repo (README, gh-pages install
   guide) uses `printf '%s\n' 'line1' 'line2' ... > file` instead, which
   works identically in `csh` and `sh`/`bash`.
8. **Package names prefixed `os-` are gated from GUI installation.**
   OPNsense's `firmware/install.sh` has a check that blocks GUI-based
   install of a package unless it matches its plugin-naming convention —
   `os-ipsec-watchdog` tripped this even though `pkg add` from the CLI
   worked fine. Confirmed by reading `install.sh` and reproducing the exact
   error before fixing it. Fixed by renaming the package to
   `ipsec-watchdog` (no `os-` prefix) — a breaking change, documented in
   CHANGELOG.md as the v1.3 migration.
9. **A `.pkg` is tagged to one specific FreeBSD ABI/OSVERSION** (e.g.
   `FreeBSD:15:amd64`) and installing the wrong one fails cleanly at
   `pkg update` (`wrong architecture: ...`) rather than partially installing.
   Since this plugin has no compiled code, the fix isn't cross-compilation —
   it's building the *same* package multiple times with
   `pkg -o ABI=... -o OSVERSION=... create`, once per supported base. Two
   sub-bugs surfaced while building this:
   - Trying to template the gh-pages URL/path with `${ABI}` (which contains
     literal colons, per `pkg.conf(5)`) broke on a Windows/Git-Bash (MSYS)
     dev machine, which silently mangles literal colons in paths into
     Unicode lookalike characters. Fixed by avoiding colon-bearing paths
     entirely — plain directory names (`root/`, `freebsd14-amd64/`) instead.
   - `pkg repo` scans its target directory **recursively**, so nesting one
     ABI's catalog directory inside another's merges both ABIs into a single
     (wrong) catalog. Fixed by building each ABI's catalog in its own
     sibling directory and only merging them (as plain files, no further
     `pkg repo` re-scan) at the very last publish step. Verified by
     decompressing each catalog's `data.pkg` and grepping for ABI strings to
     confirm no cross-contamination.
10. **A binary file committed on a machine with `core.autocrlf=true` can get
    silently corrupted.** A PNG screenshot added to `docs/img/` had its
    bytes altered (CR bytes stripped from the LF sequence, shifting content
    and breaking the PNG signature itself) purely from being committed on
    Windows with `autocrlf` on and no `.gitattributes` marking it binary.
    Caught by diffing the committed blob's byte count and header against the
    on-disk original — not something `git status`/normal review would show.
    Fixed with a `.gitattributes` (`*.png binary`, `*.pkg binary`, etc.) plus
    re-adding the file. Worth checking for any future binary asset in this
    repo.

## Explicit scope decisions (respect these if continuing this project)

- **"Polish the existing plugin" was chosen over a full ports-style
  rewrite.** The official `opnsense/plugins` repo layout
  (`Makefile`/`Mk/plugins.mk`/`files/`) was considered and explicitly not
  adopted — see maintainer-notes.md for the trade-off if that ever changes.
- **Internal identifiers were deliberately NOT renamed** when the GitHub
  repo and pkg package name changed (repo: `os-ipsec-watchdog` →
  `OPNSense-IPSec-WatchDog`; package: `os-ipsec-watchdog` →
  `ipsec-watchdog`). The PHP namespace (`OPNsense\IPsecWatchdog`), menu URL,
  ACL id, and `configd` action prefix all still read "IPsecWatchdog"/
  "ipsecwatchdog" — this was a conscious choice, not an oversight, to avoid
  churn in already-working, tested code paths.
- **Cron job customizations are never clobbered.** `manage_cron.php`
  registers the auto-cron job exactly once, ever, on first install, and
  never edits or removes it again — including across upgrades — so a user's
  chosen schedule always survives.

## Current status (as of this document)

- Version: 1.3 (see CHANGELOG.md)
- Installed and manually exercised end-to-end on a real OPNsense 26.7 box:
  menu, grid CRUD, run-now, status view, auto-cron registration, both
  install methods (direct `.pkg` and the gh-pages `pkg` repo, FreeBSD 15
  path).
- README rewritten for a general/first-time audience with a real screenshot
  of a working deployment; deep build/CI/publish internals moved to
  [maintainer-notes.md](maintainer-notes.md) to keep the top of the README
  approachable.
- Outstanding before calling this "fully shipped": publish an actual GitHub
  Release to exercise the CI workflow for real (see maintainer-notes.md),
  and ideally get eyes on a real FreeBSD 14 box.
