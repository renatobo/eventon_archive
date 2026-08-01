# CLAUDE.md

Guidance for Claude Code when working on the **EventON Archive** WordPress plugin.

User-facing docs are in `README.md`. This file is about changing the code.

## What This Is

A small WordPress plugin that renders one crawlable page listing every published EventON event, grouped by year and month, cached and rebuilt daily.

It exists because EventON hides past events three ways that compound, leaving 373 orphan event pages on drocdesmo.com. The reasoning, and the evidence for each mechanism, is in `README.md` under "Why this exists". **Read that before changing how events are collected**, or you will reintroduce one of them.

The consuming site's context lives at `~/development/drocdesmo.com_posts/CLAUDE.md` (WordPress and EventON conventions) and `seo/todo.md` item 9 in that repo (the orphan problem this solves).

## Layout

```
eventon_archive.php                        bootstrap: constants, hooks, cron, block registration
includes/class-eventon-archive-builder.php everything real: query, exclusions, grouping, render, cache
includes/class-eventon-archive-admin.php   Settings → EventON Archive: status, rebuild button, usage
blocks/archive/block.json                  block metadata, attributes mirror the shortcode
blocks/archive/editor.js                   plain ES5 editor script, no build step
assets/eventon-archive.css                 ~35 lines, deliberately almost nothing
```

No npm, no Composer, no build step, no tests. PHP 8.0+, WordPress 6.5+.

## Verify and Package

```bash
cd ~/development/my_wordpress_plugins/eventon_archive
for f in eventon_archive.php includes/*.php; do php -l "$f"; done
node --check blocks/archive/editor.js
python3 -c "import json; json.load(open('blocks/archive/block.json')); print('ok')"

cd .. && rm -f eventon_archive-<version>.zip
zip -qr eventon_archive-<version>.zip eventon_archive -x '*.DS_Store' '*.zip'
```

Renato installs the zip by hand through Plugins → Add New → Upload. There is no deploy step and no CI.

## Releasing

**The version lives in two places and both must move together:** the `Version:` header in `eventon_archive.php` and the `EVENTON_ARCHIVE_VERSION` constant right below it. The constant is the cache-buster on the stylesheet and the editor script, so a stale one serves stale assets after an update.

```bash
sed -i '' "s/1\.2\.0/1.3.0/g" eventon_archive.php   # hits both
grep -n "1\.3\.0" eventon_archive.php               # expect 2 hits
```

Name the zip for the version and delete the previous one, so there is never any doubt which file is current.

## Gotchas

- **`evcal_srow` is local wall time stored as a UTC timestamp.** EventON does not store an instant. Format it with `gmdate()`. Plain `date()`, or `wp_date()` without an explicit UTC `DateTimeZone`, applies the site offset on top and shifts the printed date: a midnight event lands on the previous day, and a January 1st midnight event lands under the previous **year** heading. `current_time( 'timestamp' )` uses the same wall-as-UTC convention, which is why it is the right thing to compare against for past/future. This was a real bug in 1.0.0.
- **The taxonomy is the category, not the term.** EventON registers one taxonomy per activated event-type slot: `event_type`, `event_type_2`, `event_type_3`, `event_type_4`. On drocdesmo those are Ride, Bike Night, Track Day and MotoGP Watch Party. Their *terms* are venues and sub-types (Chuckwalla, MotoDoffo, Short ride, Ducati Newport Beach), which mostly duplicate the location column. `event_taxonomies()` discovers the set and takes the display name from each taxonomy's `menu_name` label, which EventON sets to the bare name; `name` is the same string plus " Categories". Version 1.2.0 got this wrong by counting terms inside `event_type` alone.
- **Counters count more than the list shows.** `collect_events()` tallies `$total_in_window`, `$family_totals` and `$members_only_count` **before** the exclusion check, so members-only events are counted but never listed. Anything that moves those tallies below the `continue` silently changes what the page claims about the club.
- **Never collect events through EventON.** `EVO()` and the calendar generator re-apply `evcal_cal_hide_past` and the `evo_settings_query_type` rolling `post_date` window. Use the plain `WP_Query` in `collect_events()`, which is immune to both.
- **Exclusions are viewer-independent on purpose.** The cache is built once, often by cron running as an admin, and served to everyone. Anything resembling `current_user_can()` in the exclusion path is a bug: it would leak members-only events into a cache that anonymous visitors read.
- **ARMember checks defer to `droc_is_arm_restricted()`** when DROC Noindex Restricted is active, so the two plugins cannot drift on what "restricted" means. Keep the fallback in sync with that plugin if its rule changes.
- **The style handle is registered in `init`, before `register_block_type_from_metadata()`.** `block.json` refers to it by handle (`"style": "eventon-archive"`), not by path. Registering it later means the block silently ships no CSS.
- **A shortcode is not expanded inside Site Editor templates or template parts.** `render_block_core_shortcode()` only runs `wpautop()`; expansion rides on `do_shortcode` hooked to `the_content`, which templates never apply. The block is the answer there, and that is half of why the block exists (the other half: shortcodes never appear in the inserter).
- **Style enqueue timing.** Under a block theme the shortcode expands from inside `core/post-content`, long after `wp_enqueue_scripts`, so enqueuing there defers the CSS to the footer. `eventon_archive_maybe_enqueue_early()` checks the queried post for the shortcode and enqueues up front. The builder's own enqueue is a fallback, not the main path.
- **Prime caches before per-event lookups.** `collect_events()` calls `update_meta_cache()` always, and `update_object_term_cache()` when `location` or `category` is on. Adding any new per-event `get_the_terms()` or meta read without priming turns one query into one query per event, ~400 on this site.
- **Cache option is written with autoload `no`.** It holds tens of kilobytes of HTML. `save_cache()` handles the add-vs-update distinction because `update_option()` alone will not change an existing row's autoload flag on older WordPress.
- **No JSON-LD, deliberately.** Event pages already emit their own `Event` schema, and EventON's emitter is fragile enough (see the drocdesmo repo's `seo/eventon-schema-bug-report.md`). A second emitter here would be one more thing to keep valid.
- **`editor.js` is plain ES5 against `wp.*` globals.** No JSX, no bundler. Keep it that way: adding a build step means `node_modules` and a release pipeline for a plugin that is otherwise four files.

## Adding an Option

Four places, all of which must agree or the block and shortcode diverge:

1. `normalize_atts()` in the builder: the default, plus coercion (`is_yes()` for booleans)
2. `blocks/archive/block.json`: matching attribute with the same default, typed (`boolean`, not `"no"`)
3. `blocks/archive/editor.js`: a control in the Inspector panel
4. `README.md` and the usage table in `class-eventon-archive-admin.php`

Then use it in `collect_events()` and/or `build_item()`. Booleans round-trip correctly between the two front doors: the block sends real booleans, `is_yes()` accepts PHP's `(string) true === '1'`.

Note that the cache key is `md5( wp_json_encode( $atts ) )` **after** normalization, so a new attribute automatically invalidates nothing that already exists and gets its own variant. Variants are capped at six, oldest evicted.

## Verifying a Change Against the Live Site

The expected event count is checkable independently of the plugin, which is how the 376 figure was established:

```bash
# from ~/development/drocdesmo.com_posts, using the app password in .mcp.json
# pull every published event and count those that are neither ARMember-restricted
# nor _onlyloggedin nor excluded from calendars
curl -s -u "$WP_USER:$WP_APP_PASSWORD" \
  "https://drocdesmo.com/wp-json/eventonapify/v1/events?per_page=100&page=1&status=publish"
```

`access_control.restricted`, `flags.loggedin_only` and `flags.exclude_from_calendar` on that response map one-to-one onto the plugin's exclusion rules. If Settings → EventON Archive reports a different number than that filter, one of them is wrong.

Front-end verification needs a cache buster, because the site runs Breeze plus Redis:

```bash
curl -s "https://drocdesmo.com/<archive-page>/?nc=$RANDOM" | grep -c 'eventon-archive__item'
```
