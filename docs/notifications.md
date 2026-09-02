# Notifications: how the webhook events work

This is the deeper walkthrough for the **Notifications** box on the IPsec
Watchdog settings page — what each event actually means, when it fires, and
exactly what gets sent. See the main [README](../README.md#notifications-optional)
for the quick version and how to turn it on.

## The three events, on a timeline

Say you've got a tunnel with the default 10-minute threshold and 3-attempt
notify count, and its peer goes unreachable at 12:00. Here's what happens,
and which checkbox controls which moment:

| Time  | What the watchdog does                              | Event that could fire      |
|-------|------------------------------------------------------|-----------------------------|
| 12:00 | First check that finds the tunnel down               | **goes down**  |
| 12:10 | 10 minutes down → reconnect attempt #1 (fails)        | *(nothing — not stuck yet)* |
| 12:20 | Attempt #2 (fails)                                    | *(nothing yet)*             |
| 12:30 | Attempt #3 (fails) — this is the 3rd attempt          | **still down after N attempts** |
| 12:40, 12:50, ... | Keeps retrying every 10 minutes forever    | *(nothing more — already notified once)* |
| 13:15 | Peer comes back, tunnel reconnects on its own         | **comes back up** |

Three independent checkboxes, one per row above:

- **Notify when a tunnel goes down** — the earliest possible signal, fires
  the moment a check first finds the tunnel down, before any reconnect
  attempt has even been tried yet. Good if you want to know the instant
  something's wrong, at the cost of possibly getting alerted about a blip
  that clears itself before the threshold is even reached.
- **Notify when still down after failed attempts** — the "this isn't
  clearing on its own" signal. This is the original alert and the only one
  **on by default**; the other two default to off. Controlled by the
  **Notify after this many failed attempts** field (default 3) — that field
  only affects this event.
- **Notify when a tunnel comes back up** — closes the loop: lets you (or
  whatever's on the other end of the webhook) know the outage is over,
  whether or not it ever reached the "still down" alert.

Turn on any combination — just one, all three, or leave all three off
(which disables notifications entirely, no matter what URL is set).

Every event fires **once** per outage/recovery, never repeatedly while the
same outage continues.

## Where the webhook URL comes from

Two layers, resolved independently for the URL and for the attempts count:

1. A tunnel's own **Webhook URL override** / **Notify-after-attempts
   override** (set in that tunnel's edit dialog), if not blank.
2. Otherwise, the **global** Webhook URL / attempts count from the
   Notifications box.

So a tunnel can override just one of the two, both, or neither — e.g. keep
the global Slack channel but alert after 1 attempt instead of 3, or keep the
default attempts count but point a single critical tunnel at a different
webhook entirely (a PagerDuty integration instead of Slack, say).

The three event checkboxes themselves are **global only** — every tunnel
that has a resolved webhook URL uses the same set of enabled events.

## Testing it

Click **Test webhook** any time — it sends a small payload to whatever URL
is currently typed into the box, even before you click Save, so you can
confirm your Slack/Discord/n8n/etc. endpoint actually receives it (and that
you copy-pasted the URL correctly) without waiting for a real outage.

```json
{
  "event": "ipsec_watchdog_test",
  "message": "This is a test notification from OPNsense IPsec WatchDog.",
  "timestamp": "2026-09-02T10:26:14+00:00"
}
```

## Payload reference

Every request is an HTTP `POST` with a JSON body and `Content-Type:
application/json`. Every event (except the test one above) includes a
`tunnel_name` — a human name for the tunnel, resolved in this order:

1. The tunnel row's own optional **Description** field, if you set one.
2. Otherwise, the connection's description from VPN > IPsec > Connections
   (plus the child SA's, in parentheses, if that adds anything not already
   obvious from the connection name).
3. Otherwise, the raw `connection/child` identifiers, as a last resort.

**`ipsec_watchdog_down`** — a tunnel was just detected down:

```json
{
  "event": "ipsec_watchdog_down",
  "tunnel_name": "On-Prem",
  "connection": "1925b723-1745-4d53-b2cd-9830050e5542",
  "child": "854b6cb3-9ecb-4379-826a-738042d6852a",
  "timestamp": "2026-09-02T12:00:00+00:00"
}
```

**`ipsec_watchdog_still_down`** — a tunnel failed its configured number of
reconnect attempts in a row:

```json
{
  "event": "ipsec_watchdog_still_down",
  "tunnel_name": "On-Prem",
  "connection": "1925b723-1745-4d53-b2cd-9830050e5542",
  "child": "854b6cb3-9ecb-4379-826a-738042d6852a",
  "attempts": 3,
  "threshold_minutes": 10,
  "timestamp": "2026-09-02T12:30:00+00:00"
}
```

**`ipsec_watchdog_up`** — a tunnel that had been tracked as down recovered:

```json
{
  "event": "ipsec_watchdog_up",
  "tunnel_name": "On-Prem",
  "connection": "1925b723-1745-4d53-b2cd-9830050e5542",
  "child": "854b6cb3-9ecb-4379-826a-738042d6852a",
  "attempts": 5,
  "timestamp": "2026-09-02T13:15:00+00:00"
}
```

(`attempts` here is however many reconnect attempts were made total during
that outage, in case that's useful context on the recovery message.)

## Verifying it's really from this plugin (optional)

If you set a **Webhook signing secret**, every request also carries:

```
X-Watchdog-Signature: sha256=<hex-encoded HMAC-SHA256 of the raw request body, using your secret>
```

A receiver can recompute the same HMAC over the exact bytes of the body it
received and compare — if they match, the request really came from a box
that knows your secret. In pseudocode:

```
expected = hex(hmac_sha256(secret, raw_request_body))
valid = (expected == header["X-Watchdog-Signature"].removeprefix("sha256="))
```

This is optional but recommended if your webhook receiver is reachable by
anyone else, since without it, anyone who guesses/finds the URL could POST
fake events to it.
