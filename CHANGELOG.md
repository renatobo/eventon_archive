# Changelog

All notable changes to EventON Archive. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

## [1.8.0] - 2026-08-05

Version 1.7.0 was never released. Its work is included here, so this release
covers everything since 1.6.0.

### Added

- **`family` attribute** on both the block and the shortcode: limit the list to
  one EventON event family. Accepts the display name (`family="Bike Night"`) or
  the taxonomy slug (`family="event_type_2"`), case-insensitively. An unknown
  family lists nothing rather than falling back to the whole archive, so a typo
  in a shortcode cannot turn a hub page into a duplicate of the main archive.
- **`limit` attribute**: cap the number of rows. `0` means no cap. The counters
  are unaffected — they describe the window, not the slice rendered from it.
- **`group` attribute**: set to `no` for one flat list with the year on every
  row, for a short "coming up" list inside a page whose heading outline you do
  not want disturbed.
- **`[eventon_archive_count]` shortcode**: one figure, for use inline in a
  sentence, so a claim like "we have run 119 bike nights since 2018" stays true
  without anyone editing it. Takes `family`, `show`, `of`
  (`total` | `listed` | `members_only`) and `format` (`html` | `plain`). Reads
  the same tallies as the counters strip, so the two can never disagree.
- **"Shown on" row** on Settings → EventON Archive, listing the published pages
  that actually render the archive. "Events listed: 376" previously looked
  identical whether the archive was on a linked page or on no page at all.
- The rebuild notice now reports how many events were listed.

### Fixed

- **A rebuild interrupted partway no longer empties the archive.** The old
  order discarded the cache and then rebuilt it, so a rebuild stopped by a PHP
  timeout left nothing behind, and the settings screen reported "never / 0" as
  though the plugin had broken. The replacement is now built first and written
  only once it exists. Applies to both the settings button and the daily cron
  run.
- **A rebuild that finds no events now says so.** It previously showed the same
  green "Archive rebuilt." as a successful one, which hid exactly the failures
  worth noticing: EventON deactivated, or no published events.
- **Editor sidebar strings are translatable, and the editor script is
  versioned.** WordPress was registering the block's editor script with no
  declared dependencies, which silently skipped
  `wp_set_script_translations()` and left the script URL without a version, so
  browsers kept a stale copy after an update.
- **`limit` is bounded on the server.** The editor's slider stopped at 50 but
  nothing enforced that server-side, and each distinct value built and stored
  its own cached copy of the page.
- The event-family label fallback now works. A family whose EventON menu label
  is empty falls back correctly instead of showing nothing.
- The cached HTML option is written with a boolean autoload flag rather than a
  legacy string.

### Security

- **The release zip no longer contains the plugin's `.git` directory.** Zips
  built before this release packed the entire repository — 92 files including
  full history — which ended up under `wp-content/plugins/` on the server,
  readable by anyone who guessed the path. The 1.8.0 zip is 15 files.
  **If you installed 1.6.0 or earlier from a zip, check for a readable `.git`
  directory in the plugin folder and delete it.**

### Development

Nothing in this section ships; the plugin still has no runtime dependencies and
no build step.

- PHPStan (level 5, with WordPress stubs) and PHPUnit (61 tests, no database)
  run together via `composer check`.
- Every invariant the test suite covers was verified by deliberately breaking
  the behaviour and confirming the suite failed, rather than trusting a green
  run.

## [1.6.0] - 2026-08-01

Last release before 1.8.0. The repository's history begins here as a single
commit, so per-version detail for 1.6.0 and earlier is not recoverable from
git.

Two fixes from that period are documented because they shaped the design, and
both are the kind of thing that could plausibly regress:

- **1.2.0** — Event families are the EventON *taxonomies*
  (`event_type`, `event_type_2`, …), not the terms inside them. 1.2.0 counted
  terms within `event_type` alone and so reported the wrong families.
- **1.0.0** — Event dates are stored by EventON as local wall time in a UTC
  timestamp. Reading them with the site's timezone applied shifted printed
  dates by a day, and put a 1 January event under the previous year's heading.

[1.8.0]: https://github.com/renatobo/eventon_archive/releases/tag/v1.8.0
[1.6.0]: https://github.com/renatobo/eventon_archive/releases/tag/v1.6.0
