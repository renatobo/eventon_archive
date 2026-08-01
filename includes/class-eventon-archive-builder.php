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
	 * Each set of shortcode attributes produces its own HTML. In practice a site
	 * uses one or two, so this only exists to stop an attacker or a stray loop
	 * growing the option without bound.
	 */
	const MAX_CACHED_VARIANTS = 6;

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

		return $atts;
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
			'atts'  => $atts,
		);

		// Keep the option bounded: drop the oldest variants first.
		if ( count( $cache['entries'] ) > self::MAX_CACHED_VARIANTS ) {
			uasort(
				$cache['entries'],
				static function ( $a, $b ) {
					return ( $a['built'] ?? 0 ) <=> ( $b['built'] ?? 0 );
				}
			);
			$cache['entries'] = array_slice( $cache['entries'], -self::MAX_CACHED_VARIANTS, null, true );
		}

		$cache['built'] = time();
		$cache['count'] = count( $events );

		self::save_cache( $cache );
		self::enqueue_assets( $atts );

		return $html;
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
		if ( false === get_option( EVENTON_ARCHIVE_CACHE_OPTION, false ) ) {
			add_option( EVENTON_ARCHIVE_CACHE_OPTION, $cache, '', 'no' );
			return;
		}

		update_option( EVENTON_ARCHIVE_CACHE_OPTION, $cache, 'no' );
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
		$needs_terms = $atts['location'] || $atts['category'] || $atts['counters'];

		if ( $needs_terms ) {
			update_object_term_cache( $ids, 'ajde_events' );
		}

		// Resolved once, not once per event.
		$taxonomies = ( $atts['category'] || $atts['counters'] ) ? self::event_taxonomies() : array();

		// current_time('timestamp') is local-wall-time-as-UTC, the same convention
		// EventON stores evcal_srow in, so the two are directly comparable.
		$now    = (int) current_time( 'timestamp' );
		$events = array();

		self::$members_only_count = 0;
		self::$family_totals      = array();
		self::$total_in_window    = 0;

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
		return apply_filters( 'eventon_archive_events', $events, $atts );
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
				$label = (string) ( $taxonomy->label ?? $slug );
				$label = preg_replace( '/\s+Categories$/i', '', $label );
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
			return '<div class="eventon-archive eventon-archive--empty"><p>'
				. esc_html__( 'No events to show yet.', 'eventon_archive' )
				. '</p></div>';
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

		// One figure per event-type taxonomy (Ride, Bike Night, Track Day, MotoGP
		// Watch Party), not per term. Seeded from the taxonomy list so the order
		// follows EventON's own slot order rather than whichever family happens
		// to be largest this month.
		//
		// The tallies come from collect_events() rather than from $events,
		// because they include members-only events that the list itself omits.
		$by_family = array();

		foreach ( self::event_taxonomies() as $label ) {
			$by_family[ $label ] = 0;
		}

		foreach ( self::$family_totals as $family => $total ) {
			$by_family[ $family ] = $total;
		}

		$stats = array(
			array(
				'label' => __( 'Events', 'eventon_archive' ),
				'value' => self::$total_in_window,
			),
		);

		foreach ( $by_family as $label => $value ) {
			$stats[] = array( 'label' => $label, 'value' => $value );
		}

		// Members-only events are never listed, but saying how many there are is
		// worth more than hiding them: it is the one honest argument for joining
		// that this page can make.
		$stats[] = array(
			'label' => __( 'Members only', 'eventon_archive' ),
			'value' => self::$members_only_count,
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

		// UTC forced for the same reason as the month label: the timestamp is
		// already wall time, so any offset would move the printed date.
		$display = wp_date( 'M j', $event['start'], new DateTimeZone( 'UTC' ) );

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
