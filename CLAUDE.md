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
blocks/archive/editor.asset.php            hand-written asset manifest: deps + version for editor.js
assets/eventon-archive.css                 deliberately thin, no layout opinions
assets/eventon-archive-counters.js         count-up animation, progressive enhancement only
```

Eight shipped files. No npm, no build step, no runtime dependencies. PHP 8.0+, WordPress 6.5+.

Composer and `tests/` exist but ship nothing: they hold the PHPStan setup and the constant stubs it needs. See "Verify and Package". Do not add a runtime dependency or a build step without a much better reason than convenience.

## Verify and Package

```bash
cd ~/development/my_wordpress_plugins/eventon_archive
for f in eventon_archive.php includes/*.php; do php -l "$f"; done
node --check blocks/archive/editor.js
python3 -c "import json; json.load(open('blocks/archive/block.json')); print('ok')"

composer install                                    # first run only
composer check                                      # phpstan + phpunit, expect both clean

cd .. && rm -f eventon_archive-<version>.zip
zip -qr eventon_archive-<version>.zip eventon_archive \
  -x '*.DS_Store' '*.zip' \
     'eventon_archive/.git/*' 'eventon_archive/.gitignore' 'eventon_archive/.claude/*' \
     'eventon_archive/.phpunit.cache/*' \
     'eventon_archive/vendor/*' 'eventon_archive/tests/*' \
     'eventon_archive/composer.*' 'eventon_archive/phpstan.neon*' \
     'eventon_archive/phpunit.xml*' 'eventon_archive/CLAUDE.md'

unzip -l eventon_archive-<version>.zip   # expect ~10 files, no .git, no vendor
```

Renato installs the zip by hand through Plugins → Add New → Upload. There is no deploy step and no CI.

**The zip must exclude the tooling, and `.git`.** `composer.json`, `phpstan.neon.dist`, `phpunit.xml.dist`, `tests/` and `vendor/` exist only for analysis and tests; `vendor/` alone is ~63 MB. The plugin still has no runtime dependencies and no build step, and nothing under `includes/` or `blocks/` may ever `require` anything from `vendor/`.

The `.git/*` exclusion is not new caution about the tooling: the release command before this shipped the whole repository, 92 files, into every zip. That put the full history on the webserver under `wp-content/plugins/`, readable by anyone who guessed the path. Always run the `unzip -l` line and read it before uploading.

Notes on the PHPStan setup, all of them things that will look wrong later otherwise:

- **`--memory-limit=2G` is not optional.** `php-stubs/wordpress-stubs` is one ~9 MB file and the default 512M crashes the parallel workers mid-run, reporting a spurious "Result is incomplete".
- **Level 5, and the config says why.** Level 6 adds 42 `missingType.iterableValue` findings that all mean "annotate this array" and none of which are bugs. Raising it is a worthwhile separate pass that gives the ~15 array signatures real shapes (`array{slug:string,label:string,known:bool}` already exists on `$family` and is the model), not a config bump.
- **`treatPhpDocTypesAsCertain: false`.** WordPress's own docblocks are not precise enough to delete code over. Without this, PHPStan calls the `instanceof WP_Term` guard in `location_name()` and the `$registry ?` guard in `eventon_archive_editor_data()` dead code, because the stubs promise those can never fail. They are defence against EventON, and satisfying a stub is not a reason to drop them.
- **No baseline and no `ignoreErrors`.** `droc_is_arm_restricted()` and `get_arm_term_meta()` need no suppression: both sit behind `function_exists()` and PHPStan follows that on its own. An ignore written for them came back as `ignore.unmatched`, which is the tool saying the entry was unnecessary.

## Testing

`composer test`, or `vendor/bin/phpunit`. 54 unit tests, no database, no WordPress install, no Docker, ~13ms.

`tests/bootstrap.php` is a deliberately small fake WordPress: faithful reimplementations of `shortcode_atts()`, `sanitize_key()`, `wp_date()`, `current_time()`, the `esc_*` family, and a `get_object_taxonomies()` that reports EventON-shaped `event_type*` taxonomies from `$GLOBALS['eventon_archive_test_taxonomies']`, which a test can rewrite to model a site with different or no event types.

**The rule for what may be faked:** only functions whose real behaviour can be reproduced honestly in a few lines. `WP_Query`, post meta, term lookups and the options table are deliberately absent, so `collect_events()`, the exclusion rules and cache persistence are **not** covered. Faking those would make the tests assert the fake. They need real WordPress, and that harness is not worth its cost yet: it means Docker plus npm against a repo that has neither, for a plugin with no CI that is installed by hand. Revisit if this grows a second consumer site.

Two things in the bootstrap that look arbitrary and are not:

- **`date_default_timezone_set( 'America/Los_Angeles' )`.** Not incidental, and not for realism. `evcal_srow` is wall-time-as-UTC, so the ambient zone must be something other than UTC for a missing `gmdate()` or a dropped UTC `DateTimeZone` to actually shift a date and fail. On a UTC CI box the timezone tests would pass no matter what the code did.
- **`wp_date()` falls back to the ambient zone when no `DateTimeZone` is passed**, matching real WordPress. That is what makes "forgot the third argument" a test failure rather than a silent pass.

Tests reach private statics through `ReflectionMethod` rather than widening the real visibility, because tests should not reshape the production API to suit themselves. No `setAccessible()` call: it has had no effect since PHP 8.1 and is deprecated as of 8.5.

The suite is mutation-checked, not just green. Six deliberate breakages were each confirmed to turn it red: `gmdate` → `date` on the year key, dropping the UTC zone in `build_item()`, removing the `limit` clamp, `is_default_view()` ignoring `limit`, an unknown `family` falling back to no filter, and `nav` no longer being forced off for flat lists. **If you add a test, break the thing it covers and watch it fail before you trust it.**

PHPUnit 12 notes, both of which will bite on a copy-paste from older WordPress projects: `@dataProvider` docblock annotations are gone, use `#[DataProvider]`; `@covers` is now `#[CoversClass]`.

## Releasing

**The version lives in two places and both must move together:** the `Version:` header in `eventon_archive.php` and the `EVENTON_ARCHIVE_VERSION` constant right below it. The constant is the cache-buster on every asset, so a stale one serves stale assets after an update.

It reaches them by two different routes, which matters if either ever stops working:

- **The stylesheet and the counters script** are registered by hand in `eventon_archive_init()` and are passed the constant directly.
- **The editor script** is registered by core from `block.json`, which never sees the constant. It picks it up from `blocks/archive/editor.asset.php`, the hand-written stand-in for the file `@wordpress/scripts` would generate. Without that file core registers `editor.js` with a `false` version and no dependencies, which means an unversioned URL browsers cache across updates *and* no `wp_set_script_translations()` call, because core only wires translations up when `wp-i18n` is a declared dependency. Both were live defects until 1.8.0.

```bash
sed -i '' "s/1\.8\.0/1.9.0/g" eventon_archive.php   # hits both
grep -n "1\.9\.0" eventon_archive.php               # expect 2 hits
```

Name the zip for the version and delete the previous one, so there is never any doubt which file is current.

## Gotchas

- **`evcal_srow` is local wall time stored as a UTC timestamp.** EventON does not store an instant. Format it with `gmdate()`. Plain `date()`, or `wp_date()` without an explicit UTC `DateTimeZone`, applies the site offset on top and shifts the printed date: a midnight event lands on the previous day, and a January 1st midnight event lands under the previous **year** heading. `current_time( 'timestamp' )` uses the same wall-as-UTC convention, which is why it is the right thing to compare against for past/future. This was a real bug in 1.0.0.
- **The taxonomy is the category, not the term.** EventON registers one taxonomy per activated event-type slot: `event_type`, `event_type_2`, `event_type_3`, `event_type_4`. On drocdesmo those are Ride, Bike Night, Track Day and MotoGP Watch Party. Their *terms* are venues and sub-types (Chuckwalla, MotoDoffo, Short ride, Ducati Newport Beach), which mostly duplicate the location column. `event_taxonomies()` discovers the set and takes the display name from each taxonomy's `menu_name` label, which EventON sets to the bare name; `name` is the same string plus " Categories". Version 1.2.0 got this wrong by counting terms inside `event_type` alone.
- **The window is `show` plus `family`; exclusions sit below it.** `collect_events()` applies the date filter and the family filter first, then tallies `$total_in_window`, `$family_totals` and `$members_only_count`, and only then runs the exclusion check. So members-only events are counted but never listed, and a family-filtered render counts only that family. Moving a tally below the exclusion `continue`, or moving the family check below the tallies, silently changes what the page claims about the club. `$listed_count` is recorded after exclusions but **before** `limit`, because `limit` is a rendering cap and must never reach a figure.
- **`counts()` hashes only `family` and `show`.** Anything else in the signature would fragment the tally cache for no reason, and putting `limit` in it would let a five-row block report "5 bike nights". `build_counters()` reads `current_counts()` off the statics; the public `counts()` collects (or reads cache) and then does the same, so the strip and `[eventon_archive_count]` cannot disagree.
- **Only `is_default_view()` renders may write `$cache['built']` and `$cache['count']`.** Those back "Events listed" on the settings screen, which the verification section below treats as checkable against the whole event set. A filtered or capped variant writing them makes that check lie. Per-variant counts live in `$cache['entries'][$sig]['count']`.
- **An unknown `family` lists nothing, on purpose.** Falling back to "no filter" would turn one typo in a shortcode into a hub page quietly republishing the entire archive. `build_html()` leaves the reason in an HTML comment instead.
- **`event_taxonomies()` must not be called at `init`.** EventON registers its `event_type*` taxonomies on `init` too and load order is not guaranteed, so the editor's family list is built on `enqueue_block_editor_assets` instead. `normalize_atts()` does call it, which is safe because rendering only ever happens on `the_content`, long after `init`.
- **Never collect events through EventON.** `EVO()` and the calendar generator re-apply `evcal_cal_hide_past` and the `evo_settings_query_type` rolling `post_date` window. Use the plain `WP_Query` in `collect_events()`, which is immune to both.
- **Exclusions are viewer-independent on purpose.** The cache is built once, often by cron running as an admin, and served to everyone. Anything resembling `current_user_can()` in the exclusion path is a bug: it would leak members-only events into a cache that anonymous visitors read.
- **The block preview makes the render path reachable with arbitrary attributes at `edit_posts`.** `editor.js` previews through `wp.serverSideRender`, which POSTs to core's `/wp/v2/block-renderer/` route; its permission callback requires `edit_post` on the post, or `edit_posts` with no post context. So the real trust boundary on `$atts` is Contributor, not `manage_options`. `block.json`'s `RangeControl` max of 50 is editor UI and constrains nothing server-side, so `limit` should stay clamped in `normalize_atts()` — each distinct value mints a cache variant, and every miss costs a full `posts_per_page => -1` query plus an option write. `trim_variants()` caps the option's *size* at 24 entries, not the work.
- **ARMember checks defer to `droc_is_arm_restricted()`** when DROC Noindex Restricted is active, so the two plugins cannot drift on what "restricted" means. Keep the fallback in sync with that plugin if its rule changes.
- **The style handle is registered in `init`, before `register_block_type_from_metadata()`.** `block.json` refers to it by handle (`"style": "eventon-archive"`), not by path. Registering it later means the block silently ships no CSS.
- **A shortcode is not expanded inside Site Editor templates or template parts.** `render_block_core_shortcode()` only runs `wpautop()`; expansion rides on `do_shortcode` hooked to `the_content`, which templates never apply. The block is the answer there, and that is half of why the block exists (the other half: shortcodes never appear in the inserter).
- **Style enqueue timing.** Under a block theme the shortcode expands from inside `core/post-content`, long after `wp_enqueue_scripts`, so enqueuing there defers the CSS to the footer. `eventon_archive_maybe_enqueue_early()` checks the queried post for the shortcode and enqueues up front. The builder's own enqueue is a fallback, not the main path.
- **Prime caches before per-event lookups.** `collect_events()` calls `update_meta_cache()` always, and `update_object_term_cache()` when `location` or `category` is on. Adding any new per-event `get_the_terms()` or meta read without priming turns one query into one query per event, ~400 on this site.
- **Cache option is written with autoload `false`.** It holds tens of kilobytes of HTML and has no business loading on every request. Pass the boolean, not the string `'no'`: core still maps `'no'` to `'off'` in `wp_determine_option_autoload_value()`, but that is the legacy-compat branch and the parameter has been typed `bool|null` since 6.6. `save_cache()` handles the add-vs-update distinction because `update_option()` alone will not change an existing row's autoload flag on older WordPress.
- **No JSON-LD, deliberately.** Event pages already emit their own `Event` schema, and EventON's emitter is fragile enough (see the drocdesmo repo's `seo/eventon-schema-bug-report.md`). A second emitter here would be one more thing to keep valid.
- **`editor.js` is plain ES5 against `wp.*` globals.** No JSX, no bundler. Keep it that way: adding a build step means `node_modules` and a release pipeline for a plugin that is otherwise eight files with no runtime dependencies. **A new `wp.*` global needs its handle added to `blocks/archive/editor.asset.php`** as well as to the IIFE arguments. Miss that and it still appears to work, because core loads most editor packages anyway and `enqueue_block_editor_assets` fires late: it breaks only once something changes enqueue order, and then it breaks as a block missing from the inserter with a console error, which is a long way from the edit that caused it.

## Adding an Option

Four places, all of which must agree or the block and shortcode diverge:

1. `normalize_atts()` in the builder: the default, plus coercion (`is_yes()` for booleans)
2. `blocks/archive/block.json`: matching attribute with the same default, typed (`boolean`, not `"no"`)
3. `blocks/archive/editor.js`: a control in the Inspector panel
4. `README.md` and the usage table in `class-eventon-archive-admin.php`

Then use it in `collect_events()` and/or `build_item()`. Booleans round-trip correctly between the two front doors: the block sends real booleans, `is_yes()` accepts PHP's `(string) true === '1'`.

Note that the cache key is `md5( wp_json_encode( $atts ) )` **after** normalization, so a new attribute automatically invalidates nothing that already exists and gets its own variant. Variants are capped at `MAX_CACHED_VARIANTS` (24), oldest evicted, filterable through `eventon_archive_max_variants`.

If the new option changes *which* events are counted rather than how they are presented, it also belongs in `is_default_view()` and in the `counts()` signature. If it only affects presentation, it belongs in neither.

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
