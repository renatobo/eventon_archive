<?php
/**
 * Queries EventON events, groups them, renders the archive, and caches the result.
 *
 * @package EventON_Archive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Archive builder.
 */
class EventON_Archive_Builder {

	/**
	 * How many rendered variants to keep in the cache option at once.
	 *
	 * Each set of shortcode attributes produces its own HTML. Once `family` and
	 * `limit` exist a site legitimately runs many variants: a "coming up" and a
	 * "recent" list on every event-family hub page is two per hub. This only
	 * exists to stop an attacker or a stray loop growing the option without
	 * bound, so it is set well above real usage. Filterable.
	 */
	const MAX_CACHED_VARIANTS = 24;

	/**
	 * Highest `limit` a render will honour. 0 still means "no cap".
	 *
	 * Not a display decision: it is the server-side half of the bound that
	 * `block.json`'s RangeControl only pretends to enforce. The block previews
	 * through core's `/wp/v2/block-renderer/` route, which accepts arbitrary
	 * attributes from anyone with `edit_posts`, and every distinct `limit` mints
	 * its own cache variant that costs a full `posts_per_page => -1` query plus an
	 * option write to build. `MAX_CACHED_VARIANTS` caps how much of that is kept,
	 * not how much of it runs.
	 *
	 * Set well above the RangeControl's 50 so it never interferes with a real
	 * choice. Anything wanting more rows than this wants `limit="0"`.
	 */
	const MAX_LIMIT = 200;

	/**
	 * Membership-gated events skipped by the last collect_events() run.
	 *
	 * Set during collection and read while rendering the counters, because the
	 * figure describes events that are deliberately absent from the returned
	 * list. Reset at the top of every run, so it always belongs to the most
	 * recent collection rather than accumulating.
	 *
	 * @var int
	 */
	private static $members_only_count = 0;

	/**
	 * Family tallies for every event in the window, listed or not.
	 *
	 * The counters describe the club's activity, so they count members-only
	 * events too: an archive that says "17 track days" while quietly omitting
	 * the members-only ones is under-reporting what the club actually did. The
	 * *list* still omits them. Keyed by family name.
	 *
	 * @var array<string,int>
	 */
	private static $family_totals = array();

	/**
	 * Every event in the window, listed or not. The "Events" figure.
	 *
	 * @var int
	 */
	private static $total_in_window = 0;

	/**
	 * Events the last collect_events() run would actually list.
	 *
	 * Counted after the exclusion check but **before** `limit` is applied, so it
	 * answers "how many could be shown" rather than "how many fit on the page".
	 *
	 * @var int
	 */
	private static $listed_count = 0;

	/**
	 * The family filter resolved by the last collect_events() run.
	 *
	 * `slug` is the EventON event-type taxonomy, `label` its display name, and
	 * `known` is false when the caller asked for a family this site does not
	 * have. Kept as a static for the same reason as the tallies: build_html() and
	 * build_counters() need it and re-resolving would repeat the work.
	 *
	 * @var array{slug:string,label:string,known:bool}
	 */
	private static $family = array(
		'slug'  => '',
		'label' => '',
		'known' => true,
	);

	/**
	 * Shortcode entry point.
	 *
	 * @param array|string $atts Raw shortcode attributes.
	 * @return string HTML.
	 */
	public static function shortcode( $atts ) {
		return self::render( is_array( $atts ) ? $atts : array() );
	}

	/**
	 * Normalize shortcode attributes to a canonical array.
	 *
	 * @param array $atts Raw attributes.
	 * @return array Normalized attributes.
	 */
	public static function normalize_atts( array $atts ) {
		$atts = shortcode_atts(
			array(
				// desc puts the most recent year first. That is the default because
				// the point of this page is surfacing PAST events, and the recent
				// past is the part anyone actually wants.
				'order'    => 'desc',
				// all | past | future.
				'show'     => 'all',
				// Append the venue name after each title. Off by default: minimal
				// markup, and the venue is already on the event page itself.
				'location' => 'no',
				// Append the EventON event_type terms ("Short ride", "Bike Night").
				// Off by default like location, but useful keyword context when the
				// archive is a page you actually want to rank.
				'category' => 'no',
				// Totals strip above the list: all events, then one per event type.
				'counters' => 'no',
				// Year jump links at the top.
				'nav'      => 'yes',
				// Restrict to one event family: an EventON event-type taxonomy slug
				// ("event_type_2") or its display name ("Bike Night"). Empty means
				// every family, which is the whole-archive behaviour this plugin
				// shipped with.
				'family'   => '',
				// Cap the rows. 0 is no cap. The counters are unaffected: they
				// describe the window, not the slice rendered from it.
				'limit'    => 0,
				// Year and month headings. Off gives one flat list, which is what a
				// short "coming up" block on a hub page wants: five rows do not need
				// an <h2>, and stray headings would pollute the page's outline.
				'group'    => 'yes',
			),
			array_change_key_case( (array) $atts, CASE_LOWER ),
			'eventon_archive'
		);

		$atts['order']    = 'asc' === strtolower( (string) $atts['order'] ) ? 'asc' : 'desc';
		$show             = strtolower( (string) $atts['show'] );
		$atts['show']     = in_array( $show, array( 'all', 'past', 'future' ), true ) ? $show : 'all';
		$atts['location'] = self::is_yes( $atts['location'] );
		$atts['category'] = self::is_yes( $atts['category'] );
		$atts['counters'] = self::is_yes( $atts['counters'] );
		$atts['nav']      = self::is_yes( $atts['nav'] );
		$atts['group']    = self::is_yes( $atts['group'] );
		$atts['limit']    = min( self::MAX_LIMIT, max( 0, (int) $atts['limit'] ) );

		// Canonicalized to the taxonomy slug so that family="Bike Night" and
		// family="event_type_2" share one cache entry instead of rendering the
		// same list twice. An unresolvable value is kept as a key-safe token
		// rather than dropped, so collect_events() can tell "no filter" apart
		// from "filter this site cannot satisfy" and say so.
		$family = trim( (string) $atts['family'] );

		if ( '' !== $family ) {
			$resolved       = self::resolve_family_slug( $family );
			$atts['family'] = '' !== $resolved ? $resolved : sanitize_key( $family );
		} else {
			$atts['family'] = '';
		}

		// The year anchors a nav links to only exist when the list is grouped.
		if ( ! $atts['group'] ) {
			$atts['nav'] = false;
		}

		return $atts;
	}

	/**
	 * Resolve a family argument to an EventON event-type taxonomy slug.
	 *
	 * Accepts the slug (`event_type_2`), the display name (`Bike Night`), or the
	 * taxonomy's own longer label (`Bike Night Categories`), all case-insensitively,
	 * because all three are things someone will reasonably type into a shortcode.
	 *
	 * @param string $raw Raw family argument.
	 * @return string Taxonomy slug, or '' when nothing matches.
	 */
	private static function resolve_family_slug( $raw ) {
		$raw = trim( (string) $raw );

		if ( '' === $raw ) {
			return '';
		}

		$taxonomies = self::event_taxonomies();

		if ( isset( $taxonomies[ $raw ] ) ) {
			return $raw;
		}

		// event_taxonomies() already strips " Categories" from the display name,
		// so strip it from the needle too and the long form matches as well.
		$needle = strtolower( (string) preg_replace( '/\s+categories$/i', '', $raw ) );

		foreach ( $taxonomies as $slug => $label ) {
			if ( strtolower( $label ) === $needle ) {
				return $slug;
			}
		}

		return '';
	}

	/**
	 * How many rendered variants the cache option may hold.
	 *
	 * @return int
	 */
	private static function max_cached_variants() {
		/**
		 * Filters how many rendered variants the cache option keeps.
		 *
		 * @param int $max Maximum variants.
		 */
		return max( 1, (int) apply_filters( 'eventon_archive_max_variants', self::MAX_CACHED_VARIANTS ) );
	}

	/**
	 * Drop the oldest entries until the set fits the variant cap.
	 *
	 * @param array $entries Cache entries keyed by signature, each with `built`.
	 * @return array
	 */
	private static function trim_variants( array $entries ) {
		$max = self::max_cached_variants();

		if ( count( $entries ) <= $max ) {
			return $entries;
		}

		uasort(
			$entries,
			static function ( $a, $b ) {
				return ( $a['built'] ?? 0 ) <=> ( $b['built'] ?? 0 );
			}
		);

		return array_slice( $entries, -$max, null, true );
	}

	/**
	 * Loose yes/true/1/on parser for shortcode booleans.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private static function is_yes( $value ) {
		return in_array( strtolower( (string) $value ), array( 'yes', 'true', '1', 'on' ), true );
	}

	/**
	 * Render the archive, serving from cache when possible.
	 *
	 * @param array $atts Raw shortcode attributes.
	 * @return string HTML.
	 */
	public static function render( array $atts ) {
		$atts      = self::normalize_atts( $atts );
		$signature = md5( wp_json_encode( $atts ) );
		$cache     = self::get_cache();

		if ( isset( $cache['entries'][ $signature ]['html'] ) ) {
			self::enqueue_assets( $atts );
			return $cache['entries'][ $signature ]['html'];
		}

		$events = self::collect_events( $atts );
		$html   = self::build_html( $events, $atts );

		$cache['entries'][ $signature ] = array(
			'html'  => $html,
			'built' => time(),
			'count' => count( $events ),
			'bytes' => strlen( $html ),
			'atts'  => $atts,
		);

		// Keep the option bounded: drop the oldest variants first.
		$cache['entries'] = self::trim_variants( $cache['entries'] );

		// The top-level figures back "Events listed" on the settings screen, which
		// is documented as checkable against the whole event set. Only the default
		// whole-archive view may write them: a filtered or capped variant would
		// otherwise leave the screen reporting "5 events" for the entire site.
		if ( self::is_default_view( $atts ) ) {
			$cache['built'] = time();
			$cache['count'] = count( $events );
		}

		self::save_cache( $cache );
		self::enqueue_assets( $atts );

		return $html;
	}

	/**
	 * Discard every cached variant and rebuild the default view, in that order.
	 *
	 * The order is the whole point. `flush_cache()` followed by `render()` deletes
	 * the option first, so a rebuild that times out — and on a site with hundreds
	 * of events that is a real `max_execution_time` risk — leaves nothing behind:
	 * the settings screen then reports "never / 0", which reads as a broken plugin
	 * rather than an unfinished job. Here the replacement is built in memory and
	 * only written once it exists, so a failure anywhere above `save_cache()`
	 * leaves the previous archive serving untouched.
	 *
	 * Deliberately does not enqueue anything: this runs from the settings screen
	 * and from cron, where there is no page to add a stylesheet to.
	 *
	 * @return int Events the rebuilt archive lists.
	 */
	public static function rebuild_now() {
		$atts   = self::normalize_atts( array() );
		$events = self::collect_events( $atts );
		$html   = self::build_html( $events, $atts );
		$count  = count( $events );

		// A fresh structure rather than a mutated one, which is what makes this a
		// flush: every previously cached variant is dropped, because a rebuild is
		// asked for precisely when the old ones are suspect.
		$cache = array(
			'entries' => array(
				md5( wp_json_encode( $atts ) ) => array(
					'html'  => $html,
					'built' => time(),
					'count' => $count,
					'bytes' => strlen( $html ),
					'atts'  => $atts,
				),
			),
			// Seed the tally cache from the collection that just ran, so the first
			// [eventon_archive_count] after a rebuild does not repeat the query.
			'counts'  => array(
				self::counts_signature( $atts ) => array(
					'data'  => self::current_counts(),
					'built' => time(),
				),
			),
			'built'   => time(),
			'count'   => $count,
		);

		self::save_cache( $cache );

		return $count;
	}

	/**
	 * Cache signature for a set of counter figures.
	 *
	 * Only `family` and `show` change the result, so only those are hashed. One
	 * place, because `counts()` and `rebuild_now()` both need it and a drifted
	 * copy would leave the seeded tally unreachable.
	 *
	 * @param array $atts Normalized attributes.
	 * @return string
	 */
	private static function counts_signature( array $atts ) {
		return md5(
			wp_json_encode(
				array(
					'family' => $atts['family'],
					'show'   => $atts['show'],
				)
			)
		);
	}

	/**
	 * Is this the unfiltered whole-archive view?
	 *
	 * Only the presentation options may differ. `show`, `family` and `limit` all
	 * change *which* events are counted, so any of them set means the render
	 * describes a subset and must not speak for the whole archive.
	 *
	 * @param array $atts Normalized attributes.
	 * @return bool
	 */
	private static function is_default_view( array $atts ) {
		return 'all' === $atts['show'] && '' === $atts['family'] && 0 === $atts['limit'];
	}

	/**
	 * Read the cache option.
	 *
	 * @return array
	 */
	public static function get_cache() {
		$cache = get_option( EVENTON_ARCHIVE_CACHE_OPTION, array() );

		if ( ! is_array( $cache ) ) {
			$cache = array();
		}
		if ( ! isset( $cache['entries'] ) || ! is_array( $cache['entries'] ) ) {
			$cache['entries'] = array();
		}
		if ( ! isset( $cache['counts'] ) || ! is_array( $cache['counts'] ) ) {
			$cache['counts'] = array();
		}

		return $cache;
	}

	/**
	 * Write the cache option.
	 *
	 * Written with autoload disabled: this holds tens of kilobytes of HTML and
	 * has no business loading on every request.
	 *
	 * @param array $cache Cache payload.
	 */
	private static function save_cache( array $cache ) {
		// `false`, not the string 'no'. Core still maps 'no' to 'off' in
		// wp_determine_option_autoload_value(), but that is the legacy-compat
		// branch: since 6.6 the parameter is typed bool|null and the string form
		// only survives for back compat.
		if ( false === get_option( EVENTON_ARCHIVE_CACHE_OPTION, false ) ) {
			add_option( EVENTON_ARCHIVE_CACHE_OPTION, $cache, '', false );
			return;
		}

		update_option( EVENTON_ARCHIVE_CACHE_OPTION, $cache, false );
	}

	/**
	 * Discard every cached variant.
	 */
	public static function flush_cache() {
		delete_option( EVENTON_ARCHIVE_CACHE_OPTION );
	}

	/**
	 * Collect the events that belong in the archive.
	 *
	 * Deliberately a plain WP_Query rather than anything from EventON. EventON's
	 * own generator applies a rolling `evo_settings_query_type` window on
	 * post_date and a global hide-past filter, which is exactly what makes past
	 * events unreachable in the first place.
	 *
	 * @param array $atts Normalized attributes.
	 * @return array[] List of event rows, sorted.
	 */
	public static function collect_events( array $atts ) {
		$all_families = self::event_taxonomies();

		self::$family = array(
			'slug'  => '',
			'label' => '',
			'known' => true,
		);

		if ( '' !== $atts['family'] ) {
			if ( ! isset( $all_families[ $atts['family'] ] ) ) {
				// Asked for a family this site does not have. Returning everything
				// would be the worse failure: a hub page would silently start
				// listing the whole archive. Return nothing and let build_html()
				// leave a comment saying why.
				self::$family = array(
					'slug'  => $atts['family'],
					'label' => '',
					'known' => false,
				);

				self::$members_only_count = 0;
				self::$family_totals      = array();
				self::$total_in_window    = 0;
				self::$listed_count       = 0;

				return array();
			}

			self::$family = array(
				'slug'  => $atts['family'],
				'label' => $all_families[ $atts['family'] ],
				'known' => true,
			);
		}

		$query_args = array(
			'post_type'              => 'ajde_events',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
		);

		/**
		 * Filters the WP_Query arguments used to collect archive events.
		 *
		 * @param array $query_args Query arguments.
		 * @param array $atts       Normalized shortcode attributes.
		 */
		$query_args = apply_filters( 'eventon_archive_query_args', $query_args, $atts );

		$ids = get_posts( $query_args );

		if ( empty( $ids ) ) {
			return array();
		}

		// One meta query for the whole set instead of one per event.
		update_meta_cache( 'post', $ids );

		// Same for terms, but only when something actually needs them. Without
		// this, get_the_terms() would fire one query per event, so 400 events
		// with categories on would cost 400 round trips.
		$needs_terms = $atts['location'] || $atts['category'] || $atts['counters'] || '' !== $atts['family'];

		if ( $needs_terms ) {
			update_object_term_cache( $ids, 'ajde_events' );
		}

		// Resolved once, not once per event.
		$taxonomies = ( $atts['category'] || $atts['counters'] ) ? $all_families : array();

		// current_time('timestamp') is local-wall-time-as-UTC, the same convention
		// EventON stores evcal_srow in, so the two are directly comparable.
		$now    = (int) current_time( 'timestamp' );
		$events = array();

		self::$members_only_count = 0;
		self::$family_totals      = array();
		self::$total_in_window    = 0;
		self::$listed_count       = 0;

		foreach ( $ids as $id ) {
			// Dates first, so an event held back for membership is still measured
			// against the same window as everything else. Counting a 2019
			// members-only event on a "future only" archive would be nonsense.
			$start = (int) get_post_meta( $id, 'evcal_srow', true );

			if ( $start <= 0 ) {
				continue;
			}

			$end     = (int) get_post_meta( $id, 'evcal_erow', true );
			$end     = $end > 0 ? $end : $start;
			$is_past = $end < $now;

			if ( 'past' === $atts['show'] && ! $is_past ) {
				continue;
			}
			if ( 'future' === $atts['show'] && $is_past ) {
				continue;
			}

			// The family filter sits with the date filters, above the tallies,
			// because it narrows the *window* the counters describe rather than
			// hiding events from a list. "How many bike nights have there been"
			// has to count bike nights only. Exclusions still sit below.
			if ( '' !== self::$family['slug'] ) {
				$family_terms = get_the_terms( $id, self::$family['slug'] );

				if ( empty( $family_terms ) || is_wp_error( $family_terms ) ) {
					continue;
				}
			}

			$members_only = self::is_members_only( $id );
			$families     = $taxonomies ? self::family_names( $id, $taxonomies ) : array();

			// Counters are tallied here, before the exclusion check, so they
			// describe everything the club ran in the window rather than only the
			// subset this page is allowed to list.
			++self::$total_in_window;

			foreach ( $families as $family ) {
				if ( ! isset( self::$family_totals[ $family ] ) ) {
					self::$family_totals[ $family ] = 0;
				}
				++self::$family_totals[ $family ];
			}

			if ( self::is_excluded( $id, $members_only ) ) {
				if ( $members_only ) {
					++self::$members_only_count;
				}
				continue;
			}

			$permalink = get_permalink( $id );

			if ( ! $permalink ) {
				continue;
			}

			$events[] = array(
				'id'        => $id,
				'title'     => get_the_title( $id ),
				'permalink' => $permalink,
				'start'     => $start,
				'end'       => $end,
				'is_past'   => $is_past,
				'location'  => $atts['location'] ? self::location_name( $id ) : '',
				'families'  => $families,
			);
		}

		usort(
			$events,
			static function ( $a, $b ) use ( $atts ) {
				if ( $a['start'] === $b['start'] ) {
					return strcasecmp( $a['title'], $b['title'] );
				}

				return 'asc' === $atts['order']
					? $a['start'] <=> $b['start']
					: $b['start'] <=> $a['start'];
			}
		);

		/**
		 * Filters the collected event rows before rendering.
		 *
		 * @param array[] $events Event rows.
		 * @param array   $atts   Normalized shortcode attributes.
		 */
		$events = apply_filters( 'eventon_archive_events', $events, $atts );

		// Recorded before the cap, so it answers "how many could be listed" and
		// stays comparable with the counters. Set after the filter so rows added
		// there are included.
		self::$listed_count = count( $events );

		// Capped last, so the filter above cannot be bypassed by it and the cap
		// always applies to the final ordering.
		if ( $atts['limit'] > 0 && count( $events ) > $atts['limit'] ) {
			$events = array_slice( $events, 0, $atts['limit'] );
		}

		return $events;
	}

	/**
	 * Counter figures for a window, without rendering anything.
	 *
	 * The public read side of the same tallies the counters strip uses, so a
	 * sentence like "DROC has run 119 bike nights since 2018" can be assembled
	 * from the archive rather than hand-counted and left to rot.
	 *
	 * Only `family` and `show` affect the result, so the signature is built from
	 * those two alone: `limit` describes the slice a page renders, never the
	 * window being counted, and every presentation option is irrelevant here.
	 * That also means one cached tally serves every page asking about the same
	 * window.
	 *
	 * @param array $atts Raw attributes. `family` and `show` are the only ones read.
	 * @return array{total:int,listed:int,members_only:int,families:array<string,int>,family:array}
	 */
	public static function counts( array $atts = array() ) {
		$atts      = self::normalize_atts( $atts );
		$signature = self::counts_signature( $atts );
		$cache     = self::get_cache();

		if ( isset( $cache['counts'][ $signature ]['data'] ) && is_array( $cache['counts'][ $signature ]['data'] ) ) {
			return $cache['counts'][ $signature ]['data'];
		}

		// counters on forces the term cache priming and the taxonomy resolution
		// that populate the family tallies; limit off because a cap must never
		// reach a figure that describes the window.
		self::collect_events(
			array_merge(
				$atts,
				array(
					'counters' => true,
					'limit'    => 0,
				)
			)
		);

		$data = self::current_counts();

		$cache['counts'][ $signature ] = array(
			'data'  => $data,
			'built' => time(),
		);

		$cache['counts'] = self::trim_variants( $cache['counts'] );

		self::save_cache( $cache );

		return $data;
	}

	/**
	 * The tallies left behind by the most recent collect_events() run.
	 *
	 * @return array{total:int,listed:int,members_only:int,families:array<string,int>,family:array}
	 */
	private static function current_counts() {
		return array(
			'total'        => self::$total_in_window,
			'listed'       => self::$listed_count,
			'members_only' => self::$members_only_count,
			'families'     => self::$family_totals,
			'family'       => self::$family,
		);
	}

	/**
	 * `[eventon_archive_count]` — one figure, for use inline in a sentence.
	 *
	 * @param array|string $atts Raw shortcode attributes.
	 * @return string
	 */
	public static function count_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'family' => '',
				'show'   => 'all',
				// total | listed | members_only.
				'of'     => 'total',
				// html wraps the number in a span; plain returns bare digits, for
				// use inside an attribute or a title.
				'format' => 'html',
			),
			array_change_key_case( is_array( $atts ) ? $atts : array(), CASE_LOWER ),
			'eventon_archive_count'
		);

		$counts = self::counts(
			array(
				'family' => $atts['family'],
				'show'   => $atts['show'],
			)
		);

		$of = strtolower( (string) $atts['of'] );
		$of = in_array( $of, array( 'total', 'listed', 'members_only' ), true ) ? $of : 'total';

		$value = (int) ( $counts[ $of ] ?? 0 );

		if ( 'plain' === strtolower( (string) $atts['format'] ) ) {
			return (string) $value;
		}

		return sprintf(
			'<span class="eventon-archive-count">%s</span>',
			esc_html( number_format_i18n( $value ) )
		);
	}

	/**
	 * Should this event be kept out of the archive?
	 *
	 * Four reasons, all of them "a logged-out visitor has no business seeing
	 * this", which is the same audience as a search crawler:
	 *
	 *   1. ARMember gates the post (members-only content).
	 *   2. EventON's own `_onlyloggedin` flag is set.
	 *   3. The post is password protected.
	 *   4. The author excluded it from calendars via `evo_exclude_ev`.
	 *
	 * @param int       $post_id      Event ID.
	 * @param bool|null $members_only Precomputed is_members_only() result, to
	 *                                avoid asking twice during collection.
	 * @return bool True to skip.
	 */
	public static function is_excluded( $post_id, $members_only = null ) {
		$excluded = false;

		if ( null === $members_only ) {
			$members_only = self::is_members_only( $post_id );
		}

		if ( $members_only ) {
			$excluded = true;
		} elseif ( 'yes' === get_post_meta( $post_id, 'evo_exclude_ev', true ) ) {
			$excluded = true;
		} else {
			$post = get_post( $post_id );
			if ( $post && '' !== $post->post_password ) {
				$excluded = true;
			}
		}

		/**
		 * Filters whether an event is excluded from the archive.
		 *
		 * @param bool $excluded Whether to skip the event.
		 * @param int  $post_id  Event ID.
		 */
		return (bool) apply_filters( 'eventon_archive_exclude_event', $excluded, $post_id );
	}

	/**
	 * Is this event members-only, by either mechanism?
	 *
	 * ARMember gating and EventON's own `_onlyloggedin` flag are unrelated
	 * systems that mean the same thing to a logged-out visitor, and they overlap
	 * on this site. Both are "you have to be a member", which is what the
	 * counters report, so they are collapsed into one question here.
	 *
	 * @param int $post_id Event ID.
	 * @return bool
	 */
	public static function is_members_only( $post_id ) {
		return self::is_membership_restricted( $post_id )
			|| 'yes' === get_post_meta( $post_id, '_onlyloggedin', true );
	}

	/**
	 * Is this event gated by ARMember?
	 *
	 * Defers to DROC Noindex Restricted when that plugin is active, so both
	 * plugins always agree on what "restricted" means and the rule only has to be
	 * maintained in one place. Falls back to reading ARMember's own post meta.
	 *
	 * The check is deliberately viewer-independent: the answer must be the same
	 * for a crawler, a guest, and a logged-in admin running the cron rebuild.
	 * That last case matters here, because the cache is generated once and served
	 * to everyone.
	 *
	 * @param int $post_id Event ID.
	 * @return bool
	 */
	public static function is_membership_restricted( $post_id ) {
		if ( function_exists( 'droc_is_arm_restricted' ) ) {
			return (bool) droc_is_arm_restricted( $post_id );
		}

		// ARMember stores per-post rules as one `arm_access_plan` row per allowed
		// plan. Any row at all means the post is protected.
		if ( ! empty( get_post_meta( $post_id, 'arm_access_plan' ) ) ) {
			return true;
		}

		// Term-level rules, only readable when ARMember Pro is active.
		if ( ! function_exists( 'get_arm_term_meta' ) ) {
			return false;
		}

		foreach ( get_object_taxonomies( 'ajde_events' ) as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy );

			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				if ( get_arm_term_meta( $term->term_id, 'arm_protection', true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Venue name for an event, from its event_location term.
	 *
	 * @param int $post_id Event ID.
	 * @return string
	 */
	private static function location_name( $post_id ) {
		$terms = get_the_terms( $post_id, 'event_location' );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}

		$term = reset( $terms );

		return $term instanceof WP_Term ? $term->name : '';
	}

	/**
	 * The EventON event-type taxonomies, as slug => family name.
	 *
	 * EventON registers one taxonomy per activated "event type" slot:
	 * `event_type`, `event_type_2`, `event_type_3`, `event_type_4`. On this site
	 * those are Ride, Bike Night, Track Day and MotoGP Watch Party, and **the
	 * taxonomy is the category** — its terms are the venue or sub-type below it
	 * (Chuckwalla, MotoDoffo, Short ride).
	 *
	 * The family name comes from the taxonomy's `menu_name` label, which EventON
	 * sets to the bare name. Its `name` label is the same string with
	 * " Categories" appended, so that is the fallback.
	 *
	 * @return array<string,string> Taxonomy slug => family name.
	 */
	public static function event_taxonomies() {
		$found = array();

		foreach ( get_object_taxonomies( 'ajde_events', 'objects' ) as $slug => $taxonomy ) {
			if ( ! preg_match( '/^event_type(_\d+)?$/', $slug ) ) {
				continue;
			}

			$label = '';

			if ( isset( $taxonomy->labels->menu_name ) ) {
				$label = (string) $taxonomy->labels->menu_name;
			}

			if ( '' === $label ) {
				// Falling back to `label`, which EventON sets to the family name
				// plus " Categories", so the suffix comes off. An empty label
				// falls through to the slug: a blank heading is worse than a
				// machine-readable one. Cast because preg_replace() is typed
				// ?string, and a null would land in an array of strings.
				$label = '' !== $taxonomy->label ? (string) $taxonomy->label : $slug;
				$label = (string) preg_replace( '/\s+Categories$/i', '', $label );
			}

			$found[ $slug ] = $label;
		}

		/**
		 * Filters the event-type taxonomies treated as categories.
		 *
		 * @param array<string,string> $found Taxonomy slug => display name.
		 */
		return apply_filters( 'eventon_archive_taxonomies', $found );
	}

	/**
	 * Which families this event belongs to.
	 *
	 * Membership is "has at least one term in that taxonomy". The term itself is
	 * not reported: it is usually a venue, which either duplicates the location
	 * column or adds noise.
	 *
	 * @param int                  $post_id    Event ID.
	 * @param array<string,string> $taxonomies Slug => family name.
	 * @return string[] Family names.
	 */
	private static function family_names( $post_id, array $taxonomies ) {
		$names = array();

		foreach ( $taxonomies as $slug => $label ) {
			$terms = get_the_terms( $post_id, $slug );

			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				$names[] = $label;
			}
		}

		return $names;
	}

	/**
	 * Render the grouped archive markup.
	 *
	 * Plain headings, lists and anchors. No wrappers the theme has to fight, no
	 * inline styles, no JSON-LD: the event pages already emit their own Event
	 * schema, and a second emitter here would just be another thing to keep valid.
	 *
	 * @param array[] $events Sorted event rows.
	 * @param array   $atts   Normalized attributes.
	 * @return string HTML.
	 */
	public static function build_html( array $events, array $atts ) {
		if ( empty( $events ) ) {
			$out = '<div class="eventon-archive eventon-archive--empty"><p>'
				. esc_html__( 'No events to show yet.', 'eventon_archive' )
				. '</p></div>';

			// An empty list because the family does not exist looks identical to an
			// empty list because nothing is scheduled. Say which, in a comment, so
			// a typo in a shortcode is findable from View Source.
			if ( ! self::$family['known'] ) {
				$out .= sprintf(
					"\n<!-- eventon_archive: unknown family \"%s\". Expected one of: %s -->\n",
					esc_html( self::$family['slug'] ),
					esc_html( implode( ', ', array_keys( self::event_taxonomies() ) ) )
				);
			}

			return $out;
		}

		// One flat list, no headings. Short lists on a hub page want this: five
		// rows under an <h2>2026</h2><h3>August</h3> shell reads as scaffolding,
		// and the headings would land in the host page's outline.
		if ( ! $atts['group'] ) {
			$out  = '<div class="eventon-archive eventon-archive--flat">';
			$out .= self::build_counters( $events, $atts );
			$out .= '<ul class="eventon-archive__list">';

			foreach ( $events as $event ) {
				$out .= self::build_item( $event, $atts );
			}

			$out .= '</ul></div>';
			$out .= sprintf(
				"\n<!-- eventon_archive: %d events, built %s -->\n",
				count( $events ),
				esc_html( gmdate( 'c' ) )
			);

			return $out;
		}

		// Group by year, then by month. gmdate() is correct here, not date(): the
		// stored timestamp is already local wall time expressed as UTC, so any
		// timezone conversion would shift the calendar date.
		$grouped = array();

		foreach ( $events as $event ) {
			$year  = gmdate( 'Y', $event['start'] );
			$month = gmdate( 'm', $event['start'] );

			$grouped[ $year ][ $month ][] = $event;
		}

		$out  = '<div class="eventon-archive">';
		$out .= self::build_counters( $events, $atts );
		$out .= self::build_nav( $grouped, $atts );

		foreach ( $grouped as $year => $months ) {
			$out .= sprintf(
				'<h2 class="eventon-archive__year" id="eventon-archive-%1$s">%1$s</h2>',
				esc_attr( $year )
			);

			foreach ( $months as $month => $month_events ) {
				// A timestamp inside the month, purely to get a localized month name.
				// Forced to UTC: wp_date() would otherwise apply the site offset to a
				// value that is already local wall time, rolling early-morning events
				// back into the previous day (and occasionally the previous month).
				$label = wp_date( 'F', (int) $month_events[0]['start'], new DateTimeZone( 'UTC' ) );

				$out .= sprintf(
					'<h3 class="eventon-archive__month">%s</h3>',
					esc_html( $label ? $label : $month )
				);

				$out .= '<ul class="eventon-archive__list">';

				foreach ( $month_events as $event ) {
					$out .= self::build_item( $event, $atts );
				}

				$out .= '</ul>';
			}
		}

		$out .= '</div>';
		$out .= sprintf(
			"\n<!-- eventon_archive: %d events, built %s -->\n",
			count( $events ),
			esc_html( gmdate( 'c' ) )
		);

		return $out;
	}

	/**
	 * Totals strip: all events, then one figure per event type.
	 *
	 * The real numbers are rendered server-side. The count-up script animates
	 * from a lower value up to what is already in the DOM, so a crawler, a
	 * reader with JavaScript off, and anyone who prefers reduced motion all see
	 * the true figure. Never render a placeholder zero here.
	 *
	 * @param array[] $events Filtered event rows.
	 * @param array   $atts   Normalized attributes.
	 * @return string HTML.
	 */
	private static function build_counters( array $events, array $atts ) {
		if ( ! $atts['counters'] ) {
			return '';
		}

		// Read through the same counts object the [eventon_archive_count] shortcode
		// uses, so a figure in a sentence and a figure in the strip can never
		// disagree. The tallies come from collect_events() rather than from
		// $events, because they include members-only events the list omits, and
		// because `limit` must not reach them.
		$counts = self::current_counts();

		if ( '' !== $counts['family']['slug'] && $counts['family']['known'] ) {
			// Filtered to one family, so the total already *is* that family. A
			// separate per-family row would restate the same number under a
			// second label, so the total takes the family's name instead.
			$stats = array(
				array(
					'label' => $counts['family']['label'],
					'value' => $counts['total'],
				),
			);
		} else {
			// One figure per event-type taxonomy (Ride, Bike Night, Track Day,
			// MotoGP Watch Party), not per term. Seeded from the taxonomy list so
			// the order follows EventON's own slot order rather than whichever
			// family happens to be largest this month.
			$by_family = array();

			foreach ( self::event_taxonomies() as $label ) {
				$by_family[ $label ] = 0;
			}

			foreach ( $counts['families'] as $family => $total ) {
				$by_family[ $family ] = $total;
			}

			$stats = array(
				array(
					'label' => __( 'Events', 'eventon_archive' ),
					'value' => $counts['total'],
				),
			);

			foreach ( $by_family as $label => $value ) {
				$stats[] = array( 'label' => $label, 'value' => $value );
			}
		}

		// Members-only events are never listed, but saying how many there are is
		// worth more than hiding them: it is the one honest argument for joining
		// that this page can make.
		$stats[] = array(
			'label' => __( 'Members only', 'eventon_archive' ),
			'value' => $counts['members_only'],
		);

		/**
		 * Filters the counter figures before they are rendered.
		 *
		 * @param array[] $stats  List of ['label' => string, 'value' => int].
		 * @param array[] $events Filtered event rows.
		 */
		$stats = apply_filters( 'eventon_archive_counters', $stats, $events );

		// Applied after the filter so a figure added there is held to the same
		// rule. A zero counter is noise: it draws the eye to say nothing.
		$stats = array_filter(
			(array) $stats,
			static function ( $stat ) {
				return ! empty( $stat['label'] ) && (int) ( $stat['value'] ?? 0 ) > 0;
			}
		);

		if ( empty( $stats ) ) {
			return '';
		}

		$out = '<div class="eventon-archive__stats">';

		foreach ( $stats as $stat ) {
			$value = (int) $stat['value'];

			$out .= sprintf(
				'<div class="eventon-archive__stat"><span class="eventon-archive__stat-value" data-eventon-count="%1$d">%2$s</span><span class="eventon-archive__stat-label">%3$s</span></div>',
				$value,
				esc_html( number_format_i18n( $value ) ),
				esc_html( $stat['label'] )
			);
		}

		return $out . '</div>';
	}

	/**
	 * Year jump links.
	 *
	 * @param array $grouped Events grouped by year then month.
	 * @param array $atts    Normalized attributes.
	 * @return string HTML.
	 */
	private static function build_nav( array $grouped, array $atts ) {
		if ( ! $atts['nav'] || count( $grouped ) < 2 ) {
			return '';
		}

		$links = array();

		foreach ( array_keys( $grouped ) as $year ) {
			$links[] = sprintf(
				'<a href="#eventon-archive-%1$s">%1$s</a>',
				esc_attr( $year )
			);
		}

		return '<nav class="eventon-archive__nav" aria-label="'
			. esc_attr__( 'Jump to year', 'eventon_archive' ) . '">'
			. implode( ' ', $links )
			. '</nav>';
	}

	/**
	 * One event row.
	 *
	 * The `datetime` attribute carries a bare date rather than a full timestamp.
	 * The stored value is wall time with no offset attached, so emitting it as an
	 * instant would be asserting a precision the data does not have.
	 *
	 * @param array $event Event row.
	 * @param array $atts  Normalized attributes.
	 * @return string HTML.
	 */
	private static function build_item( array $event, array $atts ) {
		$iso = gmdate( 'Y-m-d', $event['start'] );

		// A flat list has no year heading above it, so the row has to carry the
		// year itself or "Aug 13" is ambiguous across an eight-year archive.
		$format = $atts['group'] ? 'M j' : 'M j, Y';

		// UTC forced for the same reason as the month label: the timestamp is
		// already wall time, so any offset would move the printed date.
		$display = wp_date( $format, $event['start'], new DateTimeZone( 'UTC' ) );

		$item = sprintf(
			'<li class="eventon-archive__item"><time class="eventon-archive__date" datetime="%s">%s</time> <a href="%s">%s</a>',
			esc_attr( $iso ),
			esc_html( $display ? $display : $iso ),
			esc_url( $event['permalink'] ),
			esc_html( $event['title'] )
		);

		// `families` is populated whenever counters are on too, so the label is
		// gated on the attribute rather than on the array being non-empty.
		if ( $atts['category'] && ! empty( $event['families'] ) ) {
			$item .= sprintf(
				' <span class="eventon-archive__category">%s</span>',
				esc_html( implode( ', ', $event['families'] ) )
			);
		}

		if ( ! empty( $event['location'] ) ) {
			$item .= sprintf(
				' <span class="eventon-archive__location">%s</span>',
				esc_html( $event['location'] )
			);
		}

		return $item . '</li>';
	}

	/**
	 * Enqueue the (very small) stylesheet.
	 *
	 * Fallback path. The usual case is handled earlier on wp_enqueue_scripts, so
	 * the style reaches wp_head; this catches the shortcode being run from a
	 * widget, a REST render, or anywhere else the queried post content check
	 * cannot see. Enqueuing this late puts the style in the footer, which is
	 * worse but still correct.
	 *
	 * Filterable to nothing for themes that would rather style it themselves.
	 *
	 * The counter script is separate and only loads when counters are on. It is
	 * a footer script, so enqueuing it this late is correct rather than a
	 * compromise.
	 *
	 * @param array $atts Normalized attributes.
	 */
	private static function enqueue_assets( array $atts ) {
		/**
		 * Filters whether to load the plugin's stylesheet.
		 *
		 * @param bool $load Whether to enqueue.
		 */
		if ( ! apply_filters( 'eventon_archive_load_styles', true ) ) {
			return;
		}

		if ( wp_style_is( 'eventon-archive', 'registered' ) && ! wp_style_is( 'eventon-archive', 'enqueued' ) ) {
			wp_enqueue_style( 'eventon-archive' );
		}

		if ( ! empty( $atts['counters'] ) && wp_script_is( 'eventon-archive-counters', 'registered' ) ) {
			wp_enqueue_script( 'eventon-archive-counters' );
		}
	}
}
