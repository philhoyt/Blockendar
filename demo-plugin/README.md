# Blockendar Demo Content

Companion plugin for [Blockendar](https://github.com/philhoyt/Blockendar). It
fills a site with demo events, venues and a six-page guided tour so the plugin
can be evaluated without authoring anything by hand.

It is what the [WordPress Playground demo](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/philhoyt/Blockendar/main/_playground/blueprint.json)
installs, and it is equally useful locally for manual QA.

## Why it exists

Demo content normally rots: a WXR export freezes its dates at export time, so a
calendar that looked full in March is empty by June. Every date here is computed
from the moment you seed, so the demo is always current.

A WXR file also cannot carry rows in Blockendar's `blockendar_events`,
`blockendar_recurrence` or `blockendar_event_type_terms` tables, which is where
occurrences actually live. Only executable code can produce those.

## What it creates

- 6 event types with inserter colours, and 5 venues (one virtual, one with no
  capacity, one with partial address meta)
- 26 single events and 5 recurring series — 31 events in total, covering every
  event status, all-day, multi-day, midnight-crossing, hidden, featured, cost
  label, cost range, capacity, registration URL, missing venue and missing type
- 8 bundled featured images, sideloaded locally with no outbound requests
- Six linked tour pages, with the landing page set as the static front page

## Usage

Activating the plugin seeds automatically. You can also drive it manually:

**Tools → Blockendar Demo** — Seed and Reset buttons.

```bash
wp blockendar-demo seed
wp blockendar-demo reset
```

## Removing it

Reset deletes only what the demo created. Every object is recorded by ID at
creation time, so content of your own is never touched — if a slug it wants is
already taken, it uses a suffixed one and leaves yours alone. Reset also restores
your previous front page setting exactly.

Deleting the plugin runs the same cleanup.

## Requirements

Blockendar 1.4.0 or newer must be active. The plugin checks at activation, in the
admin and on the CLI, and refuses to seed otherwise.
