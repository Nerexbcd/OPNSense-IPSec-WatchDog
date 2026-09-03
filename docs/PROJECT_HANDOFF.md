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
11. **Testing against a real tunnel's real identifiers can have real side
    effects, even for a "read-only" check.** While verifying the recovery
    ("back up") notification path, a test script called
    `ipsec_watchdog_check_tunnel()` against the actual production
    connection/child UUIDs (reasoning: `swanctl --list-sas` is read-only, so
    this felt safe). It turned out the "On-Prem" tunnel was, coincidentally,
    genuinely down at that exact moment (its peer, a placeholder-looking IP,
    wasn't responding) - so the function did what it correctly does for a
    real down tunnel: it called `swanctl --initiate` for real. That one
    extra attempt was harmless here (the box's own real cron was already
    retrying this same tunnel every minute regardless, so it changed nothing
    that wasn't already happening), but it was still an unplanned mutating
    action against production, not something genuinely read-only. Lesson:
    "this call is read-only" needs verifying for the *specific* inputs being
    used, not assumed from the function's typical behavior - a fake/inert
    connection name (like `test-nonexistent-conn` used safely elsewhere in
    this project's testing) doesn't have this risk; a real one does. Caught
    immediately by checking `ps`/logs/live SA state right after, confirmed
    it was a pre-existing real outage (not caused by this session's changes),
    and any settings temporarily changed for testing (two notification
    toggles) were restored to the user's actual saved values before moving
    on - see the 1.4 sections below for what was actually verified this way.
12. **No guard against overlapping runs let a real outage snowball into a
    "Run watchdog now" outage of its own.** Reported as "the Run watchdog
    now button stopped working." Root cause: that same real tunnel from
    entry 11, still genuinely down, had its threshold set to 1 minute. A
    `swanctl --initiate` against an unreachable peer can take up to ~45s
    (strongSwan retries 5 times before giving up) - so with the cron firing
    every 60s and every single cycle qualifying for a retry (threshold so
    low it's basically always past due), new `watchdog.php` processes kept
    starting before the previous one finished. Confirmed via `ps aux`: 6+
    concurrent `watchdog.php`/`swanctl` processes piled up, and `configctl
    ipsecwatchdog watchdog` (the exact call the button makes server-side)
    genuinely timed out past 120s with "error in configd communication."
    This wasn't caused by any of this session's changes - it's a gap that's
    existed since v1.0 - it just took a 1-minute threshold plus a
    persistently unreachable peer to surface it. Fixed with a `flock()`
    guard at the top of `watchdog.php`: a second overlapping invocation now
    exits immediately with a clear log line instead of piling up. Verified
    by launching two runs back-to-back (the second correctly skipped) and
    timing `configctl ipsecwatchdog watchdog` before/after (120s+ timeout
    → consistently ~0.2s). Side effect worth knowing: with this specific
    combination (very low threshold + a peer that's still down), the button
    will now often and correctly report "a previous run is still in
    progress" rather than hang - that's the fix working, not a new bug; a
    less aggressive threshold gives the lock more idle time to click into.

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

- Version: 1.4 (see CHANGELOG.md) - **tagged and pushed** (`git tag v1.4` at
  commit `e2b8031`, `git push origin v1.4`), so the tag exists on GitHub and
  is ready to attach to a Release. Release notes for it have been drafted
  (in conversation, not as a repo file - their content matches CHANGELOG.md's
  1.4 entry, just reformatted for a GitHub Release body) and handed to the
  user to paste in.
- Installed and manually exercised end-to-end on a real OPNsense 26.7 box:
  menu, grid CRUD, run-now, status view, auto-cron registration, both
  install methods (direct `.pkg` and the gh-pages `pkg` repo, FreeBSD 15
  path), and the 1.4 webhook feature (see below).
- README rewritten for a general/first-time audience with a real screenshot
  of a working deployment; deep build/CI/publish internals moved to
  [maintainer-notes.md](maintainer-notes.md) to keep the top of the README
  approachable.
- Outstanding before calling this "fully shipped": the tag exists, but no
  GitHub Release has actually been **published** from it yet - that's a
  manual step only the repo owner can do (Releases > Draft a new release >
  pick tag v1.4 > Publish release), and it's what actually triggers
  `.github/workflows/publish-pkg-repo.yml` (see maintainer-notes.md) for
  its first real run ever. Until that happens, the gh-pages `pkg` repo still
  serves 1.3 - only this box (installed via direct `.pkg`, not the repo) has
  1.4. Also still outstanding: eyes on a real FreeBSD 14 box.

### 1.4: webhook notifications — how it was verified

Adds a global + per-tunnel-override webhook URL, an "attempts before
notifying" threshold, and an optional HMAC signing secret (see README's
[Notifications](../README.md#notifications-optional) section for the
user-facing description). A tunnel can override the URL and the attempts
threshold independently of each other - added as two separate optional
fields rather than one "use custom settings" toggle, since a follow-up ask
was specifically "can a tunnel override just the attempts count too, not
only the URL". Two design points worth knowing if you touch this code:

- **State lives in `/tmp/ipsec_watchdog_<key>_state.json`** (JSON:
  `down_since`/`attempts`/`notified`), not in `config.xml` — it's
  reconnect-attempt bookkeeping, not user config, and writing it to
  `config.xml` every minute would spam config backups. `attempts` persists
  across each reconnect try (only `down_since` resets per attempt, so the
  next attempt still waits a full threshold) so it can count "3 tries" as
  the feature requires; the whole file is dropped the moment the tunnel
  comes back up, which re-arms the next outage's alert (and, since 1.4's
  follow-up below, is also the trigger for the recovery notification).
- The **general (non-array) settings node** needed its own controller
  (`Api/GeneralController.php`) — `ApiMutableModelControllerBase`'s
  `getBase()`/`setBase()` helpers are for a specific *array item* (calling
  `getBase()` with no uuid actually returns a blank `Add()` template, which
  fatals on a non-array node). The working pattern, confirmed by reading the
  actual framework source on the test box and mirrored from OPNsense's own
  `Wireguard/general.volt`, is: override `getAction()`/`setAction()`
  directly against `$this->getModel()->general` using its generic
  `getNodes()`/`setNodes()`, and scope validation with
  `$this->validate($mdl->general, 'general')` so a tunnel-row validation
  issue elsewhere in the model never bleeds onto this form.

Verified end-to-end on the real box, not just read/reasoned about: `php -l`
on every changed file; the exact validation-scoping logic traced through by
hand with a throwaway PHP CLI script before touching the controller, to
confirm the field-name output would actually match the form's `general.*`
ids; a temporary API key generated and later deleted to drive the real
`general/get`/`general/set` HTTP endpoints end-to-end (including an
intentionally invalid value, to see the validation error surface with the
right field name); and the attempt-counting state machine run 4 times in a
row against a real `nc` listener, confirming attempts increment correctly,
the webhook fires exactly once at the configured threshold, and further
attempts don't re-fire it. The HMAC signature on the captured request was
independently recomputed and matched. All test files, the temporary API
key, and the test webhook config were cleaned up afterward — nothing from
this testing was left on the box or committed to the repo.

Original scope decision (later revisited, see below): no "recovered"
notification, only "still stuck down" — asked and decided explicitly rather
than assumed at the time.

### 1.4 follow-up: test button + independent down/stuck/up event toggles

Two follow-up asks: a way to test a webhook without waiting for a real
outage, and a take-back of the "no recovery notification" decision above -
the user wanted the choice, not a fixed answer, so it became three
independent `BooleanField`s (`general.notifyOnDown`/`notifyOnStuck`/
`notifyOnUp`) rather than a single fixed event. `notifyOnStuck` defaults to
`1` (matching the plugin's pre-existing only behavior before this), the
other two default to `0` - so an upgrade from the first 1.4 cut changes
nothing for an existing installation until the new checkboxes are touched.

- **`OPNsense\IPsecWatchdog\Webhook` (`models/.../Webhook.php`)** is a new
  shared static helper (`Webhook::send($url, $secret, $payload)`) factored
  out of watchdog.php's own webhook-sending code, so both the scheduled
  check and the new "Test webhook" button call the same implementation
  instead of two copies drifting apart. It lives under `models/` (not
  `scripts/`) specifically so it autoloads the same way for both a CLI
  script (`watchdog.php`) and an MVC controller
  (`Api\ServiceController::testwebhookAction`) - confirmed by loading it
  from both contexts before wiring it in anywhere.
- **The test button intentionally does not go through configd.** Sending an
  HTTP POST needs no elevated privilege (unlike `swanctl`), so
  `testwebhookAction()` calls `Webhook::send()` directly in the API request
  and reads `url`/`secret` from the POST body - i.e. whatever's currently
  typed in the form, not the saved config - so a URL can be tried before
  clicking Save.
- **Down/up notification payloads reuse the same shape** as the existing
  "still down" one (`event` becomes `ipsec_watchdog_down`/
  `ipsec_watchdog_up`); the down event just fires from the branch that used
  to only start the downtime tracker (which already ran exactly once per
  outage, so it needed no new "already sent" bookkeeping), and the up event
  fires from the branch that already existed to clear that tracker.

Verified end-to-end on the real box: `php -l` on every changed file; the
`Webhook` class loaded and exercised from a throwaway CLI script before
being wired into either caller; `general/get`/`general/set` driven for real
over HTTP with all three checkboxes, confirming they persist; the test
button's endpoint hit twice for real - once against a URL engineered to
fail (got back `{"result":"failed","http_code":403,...}` from a real
external endpoint) and once against a local listener rigged to answer 200
(got back `{"result":"ok","http_code":200}`); and the down/stuck event
logic (with all three toggles on, then with down/stuck both off) run
against a fake, harmless connection name through the real function code,
confirming each event fires exactly when it should and not otherwise. See
the "testing against real identifiers" entry above for what happened (and
was caught and fixed) when this same verification pass reached the
recovery/"up" path against real production identifiers - that path was
exercised structurally (no fatal, reached the intended branch) but its
notify-on-recovery behavior specifically was not empirically confirmed
end-to-end the way the other two events were, since the real tunnel could
not be made to go "up" on demand for the test.

### 1.4 follow-up: friendly tunnel name in every payload

Every payload now carries `tunnel_name` instead of the old bare
`description` field (the tunnel row's own optional label, often blank).
Resolution order, in `ipsec_watchdog_friendly_name()`: the row's own label
if set, else the connection's (and child's, if it adds information) own
description pulled from the `OPNsense\IPsec\Swanctl` model, else the raw
`connection`/`child` identifiers as a last resort - mirroring
`Api\ServiceController::getDescriptionLabels()`'s approach for the GUI
status table (including the same fallback to a child's traffic selectors
when it has no description of its own), but re-implemented locally in
watchdog.php rather than shared, since the two versions diverge slightly
(the controller's is `private` and lacks the traffic-selector fallback).
The label lookup itself (`ipsec_watchdog_load_labels()`) runs once before
the tunnel loop, not once per tunnel - one `Swanctl` model load already
covers every row.

Also added: [docs/notifications.md](notifications.md), a user-facing "mini
explanation" of the event system (the timeline table, per-event payload
reference, override precedence, signature verification) - written because
the README's own Notifications section was getting long carrying all of
that inline; it now stays as a shorter overview with a link out.

Verified read-only against the real box's actual Swanctl data (own test
script calling just the label-loading/name-resolution functions, not
`check_tunnel` itself - see the earlier lesson about that): confirmed the
real connection's description and the real child's traffic-selector
fallback both resolve correctly, and that a blank per-row label correctly
falls through to them.
