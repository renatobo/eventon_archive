# EventON Archive

[![WordPress](https://img.shields.io/badge/WordPress-Plugin-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![EventON](https://img.shields.io/badge/EventON-Required-1f1f1f)](https://www.myeventon.com/)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D%208.0-777bb4?logo=php&logoColor=white)](https://www.php.net/)
[![License: GPL v2 or later](https://img.shields.io/badge/License-GPL%20v2%20or%20later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

A cached, crawlable archive of every published EventON event, grouped by year and month, so past events stop being invisible to search engines.

## Why

EventON hides past events from crawlers three separate ways, and they compound:

1. **`evcal_cal_hide_past`**, a global setting, drops every past event from every calendar. Enforced in `class-calendar_generator.php` at `if ( ! $_is_in_visible_range ) continue;`. No shortcode attribute overrides it: not `event_past_future`, not `hide_past`, not `month_incre`, not `focus_start_date_range`.
2. **`evo_settings_query_type`**, another global, adds a `date_query` on `post_date_gmt`, so a rolling window is the only thing the calendar can query at all. It filters on when the *post was created*, not on the event date.
3. **The yearly view loads over AJAX**, so its initial HTML contains no event rows.

On the site this was written for, that left **373 orphan event pages**: present in the sitemap, linked from nothing, reachable by nobody. Turning both globals off fixes the calendars for humans, but an EventON list of 400 events renders roughly **27 KB of markup per event**, about 10 MB on one page, because it emits a full event card per row.

This plugin answers the crawler's question instead of the visitor's: one page, one anchor per event, roughly 45 KB for 400 events. It reads event dates straight from post meta, so it is immune to all three mechanisms whether they are on or off.

## Features

- Every published event as a plain `<a href>`, grouped by year then month, with year jump links
- Optional totals strip with count-up animation: all events, one figure per event family, and members-only
- Never lists membership-gated events, and says how many exist instead
- Cached to a non-autoloaded option; rebuilt daily, five minutes after any event edit, or on demand
- Block **and** shortcode, sharing one renderer and one cache
- Headings, lists and anchors only. No wrappers to fight, no inline styles, no JSON-LD
- No npm, no Composer, no build step, nothing to configure beyond the block options

## Requirements

- WordPress 6.5+
- PHP 8.0+
- EventON installed and active
- ARMember optional. When present, gated events are excluded and counted separately

## Installation

Upload the zip in **Plugins → Add New → Upload Plugin**, or clone into `wp-content/plugins/eventon_archive/`. Activation schedules the daily rebuild.

Then add the archive to a page and **link that page from your footer or a menu**, or the archive is itself an orphan and the whole exercise is pointless.

## Usage

**Block editor.** Insert the **EventON Archive** block. Options live in the sidebar. It is server-rendered, so it also works inside Site Editor templates and template parts.

**Shortcode.** `[eventon_archive]` in page or post content.

Both take the same options and share the same cache.

| Attribute | Default | Effect |
|---|---|---|
| `order` | `desc` | `desc` newest year first, `asc` oldest first |
| `show` | `all` | `all`, `past`, or `future` |
| `location` | `no` | Append the venue name after each title |
| `category` | `no` | Append the event family, e.g. "Bike Night" |
| `counters` | `no` | Totals strip above the list |
| `nav` | `yes` | Year jump links at the top |

Default order is `desc` because the point of the page is surfacing past events, and the recent past is the part anyone actually wants.

## What it renders

```html
<div class="eventon-archive">
  <div class="eventon-archive__stats">…counters…</div>
  <nav class="eventon-archive__nav">…year jump links…</nav>
  <h2 id="eventon-archive-2026">2026</h2>
  <h3>July</h3>
  <ul class="eventon-archive__list">
    <li><time datetime="2026-07-18">Jul 18</time> <a href="…">Event title</a></li>
  </ul>
</div>
```

The theme supplies typography, colour and spacing. The bundled stylesheet is about seventy lines and only exists to strip the list bullets, align the dates, and lay out the counters. Disable it with `add_filter( 'eventon_archive_load_styles', '__return_false' );`.

**No JSON-LD, deliberately.** Event pages already emit their own `Event` schema, and EventON's emitter is fragile enough without a second one to keep valid.

## What it never lists

| Reason | Signal |
|---|---|
| Membership-gated | ARMember `arm_access_plan` post meta, or an ARMember-protected term |
| EventON members-only | `_onlyloggedin` meta |
| Author excluded it | `evo_exclude_ev` meta |
| Password protected | `post_password` |

Anything not `publish` is out by definition.

The ARMember check defers to `droc_is_arm_restricted()` when [DROC Noindex Restricted](https://github.com/renatobo/droc-noindex-restricted) is active, so both plugins always agree on what "restricted" means, and falls back to reading ARMember's meta directly.

The check is deliberately **viewer-independent**. The cache is generated once, often by cron running as an administrator, and served to everyone, so "can the current user see this" would be exactly the wrong question.

## Counters

```
   435       181         147           38            75             59
  EVENTS     RIDE     BIKE NIGHT   TRACK DAY  MOTOGP WATCH PARTY  MEMBERS ONLY
```

**Counting is per taxonomy, not per term.** EventON registers one taxonomy per activated event-type slot: `event_type`, `event_type_2`, `event_type_3`, `event_type_4`. **The taxonomy is the category**; its terms are the venue or sub-type underneath (Chuckwalla, MotoDoffo, Short ride), which mostly duplicate the location column. Family names come from each taxonomy's `menu_name` label, so renaming an event type in EventON relabels the counter. Order follows EventON's slot order, so it does not reshuffle between rebuilds. Override the set with `eventon_archive_taxonomies`.

An event counts toward a family if it has at least one term in that taxonomy, and it can count toward more than one.

**The counters include members-only events; the list does not.** An archive claiming "38 track days" while quietly omitting the members-only ones under-reports what the club actually did. The separate **Members only** figure says how many are withheld from the list below.

**Any figure that would render as zero is dropped**, including one added through the `eventon_archive_counters` filter. A counter reading 0 draws the eye to say nothing.

**The real figures are server-rendered.** `assets/eventon-archive-counters.js` only winds them back to about 15% and runs them forward again, easing out over 1.1s, triggered by an `IntersectionObserver` when the strip scrolls into view. Crawlers, no-JS readers, and anyone with `prefers-reduced-motion: reduce` see the true number, and if the script fails to load nothing is lost. Digits use `tabular-nums` and the element's width is locked before the first frame, so nothing reflows while it counts.

## Caching

Rendered HTML lives in the `eventon_archive_cache` option, **autoload off**, keyed by a hash of the attributes so several variants coexist (capped at six).

Rebuilt **daily at 03:20 local**, **five minutes after any event is saved, trashed or deleted** (debounced, so a bulk edit rebuilds once rather than once per post), and **on demand** from Settings → EventON Archive. Cron discards the cache and immediately re-warms the default view, so the first visitor after a rebuild never pays for the query.

## Filters

| Filter | Purpose |
|---|---|
| `eventon_archive_query_args` | Adjust the `WP_Query` used to collect events |
| `eventon_archive_events` | Modify the collected rows before rendering |
| `eventon_archive_exclude_event` | Override the per-event exclusion decision |
| `eventon_archive_taxonomies` | Override the event-type taxonomies treated as families |
| `eventon_archive_counters` | Add, remove or relabel counter figures |
| `eventon_archive_rebuild_delay` | Seconds to wait after an edit before rebuilding (minimum 60) |
| `eventon_archive_load_styles` | Return false to skip the bundled stylesheet |

## Block theme (FSE) notes

**In page or post content the shortcode works.** WordPress runs `do_blocks` (9) → `wpautop` (10) → `shortcode_unautop` (10) → `do_shortcode` (11) on `the_content`, so the wrapping `<p>` is stripped before expansion and the plugin's markup never passes through `wpautop`.

**In a Site Editor template or template part a shortcode does not work.** `render_block_core_shortcode()` only calls `wpautop()`; expansion rides on `do_shortcode` hooked to `the_content`, which templates never apply. Use the block there. That is one of the two reasons the block exists, the other being that a shortcode never appears in the inserter.

**Styling.** The markup carries no `wp-block-*` classes. `theme.json` output targets element selectors (`h1, h2, h3 { … }`), so headings inherit the theme's typography without them.

**Stylesheet timing.** Under a block theme the shortcode expands from inside `core/post-content`, long after `wp_enqueue_scripts`, and enqueuing there would defer the CSS to the footer. The plugin checks the queried post on `wp_enqueue_scripts` and enqueues up front, keeping it in `wp_head`.

## Verifying

```bash
# how many events the archive is rendering
curl -s "https://example.com/event-archive/?nc=$RANDOM" | grep -c 'eventon-archive__item'

# what the build thinks it did
curl -s "https://example.com/event-archive/?nc=$RANDOM" | grep -o '<!-- eventon_archive:[^>]*-->'
```

Use a cache buster if the site runs a page cache. Settings → EventON Archive reports the same count, the last build time, and the cache size.

## Notes

- **`evcal_srow` is local wall time stored as a UTC timestamp**, not an instant. Dates are formatted with `gmdate()`. Using `date()`, or `wp_date()` without an explicit UTC timezone, shifts the printed date and can move a midnight event into the previous day, or a January 1st event into the previous year.
- Events are collected with a plain `WP_Query`, never through EventON's generator, which would re-apply both globals described above.
- Deactivating clears both cron hooks and deletes the cache option. Nothing else is written.

## License

GPLv2 or later.
