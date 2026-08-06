<?php
/**
 * Unit tests for the parts of EventON_Archive_Builder that are its own logic.
 *
 * Scope note, so this file does not grow into the wrong thing: every test here
 * runs against the fake WordPress in bootstrap.php. That is fine for attribute
 * coercion, family resolution, date grouping, the default-view gate and variant
 * eviction, because none of those lean on WordPress behaviour beyond string
 * handling. It is NOT fine for collect_events(), the exclusion rules or the cache
 * option, which are the parts where a fake would only assert itself. Those want
 * real WordPress. See CLAUDE.md, "Testing".
 *
 * @package EventON_Archive
 */

declare( strict_types = 1 );

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass( EventON_Archive_Builder::class )]
final class BuilderTest extends TestCase {

	protected function setUp(): void {
		eventon_archive_test_reset_taxonomies();
	}

	/**
	 * Call a private or protected static method on the builder.
	 *
	 * Reflection rather than widening the real visibility. These methods are
	 * private because nothing outside the class should call them; making them
	 * public so a test can reach them would change the production API to suit the
	 * tests, which is the wrong direction.
	 *
	 * @param string $method Method name.
	 * @param mixed  ...$args Arguments.
	 * @return mixed
	 */
	private static function call( string $method, ...$args ) {
		// No setAccessible() call: it has had no effect since PHP 8.1 and is
		// deprecated as of 8.5.
		return ( new ReflectionMethod( EventON_Archive_Builder::class, $method ) )->invokeArgs( null, $args );
	}

	/**
	 * An event row shaped the way collect_events() emits them.
	 *
	 * @param int    $start Wall-time-as-UTC start timestamp.
	 * @param string $title Event title.
	 * @return array<string,mixed>
	 */
	private static function event( int $start, string $title = 'Test Event' ): array {
		return array(
			'id'        => 1,
			'title'     => $title,
			'permalink' => 'https://example.test/events/' . sanitize_key( $title ) . '/',
			'start'     => $start,
			'end'       => $start + HOUR_IN_SECONDS,
			'is_past'   => true,
			'location'  => '',
			'families'  => array(),
		);
	}

	// -------------------------------------------------------------------------
	// 1. Date grouping across the timezone boundary.
	//
	// This is the 1.0.0 regression. `evcal_srow` is local wall time stored as a
	// UTC timestamp, so a midnight-ish event must be read back with gmdate() or an
	// explicit UTC DateTimeZone. The bootstrap pins the ambient zone to
	// America/Los_Angeles precisely so that forgetting shifts the date backwards
	// and trips these assertions.
	// -------------------------------------------------------------------------

	public function test_january_first_event_groups_under_its_own_year(): void {
		// 1 Jan 2019, 00:30 wall time. Read with the site offset applied this
		// becomes 31 Dec 2018 16:30, and the row lands under the wrong year
		// heading, which is exactly what 1.0.0 did.
		$start = gmmktime( 0, 30, 0, 1, 1, 2019 );

		$atts = EventON_Archive_Builder::normalize_atts( array() );
		$html = EventON_Archive_Builder::build_html( array( self::event( $start, 'New Year Ride' ) ), $atts );

		$this->assertStringContainsString( 'id="eventon-archive-2019"', $html );
		$this->assertStringNotContainsString( 'id="eventon-archive-2018"', $html );
		$this->assertStringContainsString( '>2019</h2>', $html );
	}

	public function test_midnight_event_keeps_its_own_calendar_date(): void {
		$start = gmmktime( 0, 0, 0, 8, 13, 2025 );

		$atts = EventON_Archive_Builder::normalize_atts( array() );
		$html = EventON_Archive_Builder::build_html( array( self::event( $start ) ), $atts );

		$this->assertStringContainsString( 'datetime="2025-08-13"', $html );
		$this->assertStringContainsString( '>Aug 13</time>', $html );
		$this->assertStringContainsString( '>August</h3>', $html );
	}

	public function test_january_first_month_heading_does_not_roll_back(): void {
		$start = gmmktime( 0, 30, 0, 1, 1, 2019 );

		$atts = EventON_Archive_Builder::normalize_atts( array() );
		$html = EventON_Archive_Builder::build_html( array( self::event( $start ) ), $atts );

		$this->assertStringContainsString( '>January</h3>', $html );
		$this->assertStringNotContainsString( '>December</h3>', $html );
	}

	public function test_flat_list_carries_the_year_on_every_row(): void {
		// Without year headings above them, rows have to say which year they are,
		// or "Aug 13" is ambiguous across an eight-year archive.
		$start = gmmktime( 12, 0, 0, 8, 13, 2025 );

		$atts = EventON_Archive_Builder::normalize_atts( array( 'group' => 'no' ) );
		$html = EventON_Archive_Builder::build_html( array( self::event( $start ) ), $atts );

		$this->assertStringContainsString( '>Aug 13, 2025</time>', $html );
		$this->assertStringNotContainsString( '<h2', $html );
		$this->assertStringNotContainsString( '<h3', $html );
	}

	// -------------------------------------------------------------------------
	// 2. Boolean coercion, and block/shortcode parity.
	//
	// The block sends real booleans, the shortcode sends strings. is_yes() is the
	// seam, and a divergence here means the two front doors render differently
	// from the same intent.
	// -------------------------------------------------------------------------

	#[DataProvider( 'truthy_values' )]
	public function test_is_yes_accepts_truthy_forms( mixed $value ): void {
		$this->assertTrue( self::call( 'is_yes', $value ) );
	}

	public static function truthy_values(): array {
		return array(
			'string yes'   => array( 'yes' ),
			'string YES'   => array( 'YES' ),
			'string true'  => array( 'true' ),
			'string one'   => array( '1' ),
			'string on'    => array( 'on' ),
			// The block's real boolean: (string) true === '1'.
			'bool true'    => array( true ),
			'integer one'  => array( 1 ),
		);
	}

	#[DataProvider( 'falsy_values' )]
	public function test_is_yes_rejects_everything_else( mixed $value ): void {
		$this->assertFalse( self::call( 'is_yes', $value ) );
	}

	public static function falsy_values(): array {
		return array(
			'string no'    => array( 'no' ),
			'string false' => array( 'false' ),
			'string zero'  => array( '0' ),
			'empty string' => array( '' ),
			// (string) false === '', which must not read as yes.
			'bool false'   => array( false ),
			'integer zero' => array( 0 ),
			'nonsense'     => array( 'maybe' ),
		);
	}

	public function test_block_booleans_and_shortcode_strings_normalize_alike(): void {
		$from_block = EventON_Archive_Builder::normalize_atts(
			array(
				'location' => true,
				'category' => true,
				'counters' => true,
				'group'    => false,
			)
		);

		$from_shortcode = EventON_Archive_Builder::normalize_atts(
			array(
				'location' => 'yes',
				'category' => 'yes',
				'counters' => 'yes',
				'group'    => 'no',
			)
		);

		$this->assertSame( $from_shortcode, $from_block );

		// And the same normalized atts must produce the same cache signature,
		// or the two front doors would each build their own copy of one page.
		$this->assertSame(
			md5( wp_json_encode( $from_block ) ),
			md5( wp_json_encode( $from_shortcode ) )
		);
	}

	// -------------------------------------------------------------------------
	// 3. The limit clamp.
	//
	// block.json's RangeControl max of 50 is editor UI and constrains nothing
	// server-side: core's block-renderer route takes arbitrary attributes from any
	// edit_posts user, and every distinct limit mints its own cache variant, each
	// costing a full posts_per_page => -1 query and an option write.
	// -------------------------------------------------------------------------

	public function test_limit_is_clamped_to_the_server_side_maximum(): void {
		$max = ( new ReflectionClass( EventON_Archive_Builder::class ) )->getConstant( 'MAX_LIMIT' );

		$atts = EventON_Archive_Builder::normalize_atts( array( 'limit' => 999999 ) );

		$this->assertSame( $max, $atts['limit'] );
	}

	public function test_negative_limit_becomes_no_cap(): void {
		$atts = EventON_Archive_Builder::normalize_atts( array( 'limit' => -5 ) );

		$this->assertSame( 0, $atts['limit'] );
	}

	public function test_limit_within_range_is_untouched(): void {
		$atts = EventON_Archive_Builder::normalize_atts( array( 'limit' => 5 ) );

		$this->assertSame( 5, $atts['limit'] );
	}

	public function test_non_numeric_limit_becomes_no_cap(): void {
		$atts = EventON_Archive_Builder::normalize_atts( array( 'limit' => 'lots' ) );

		$this->assertSame( 0, $atts['limit'] );
	}

	// -------------------------------------------------------------------------
	// 4. The default-view gate.
	//
	// Only an unfiltered, uncapped render may write the top-level built/count that
	// back "Events listed" on the settings screen. A filtered variant writing them
	// would leave the screen reporting a subset as the whole archive.
	// -------------------------------------------------------------------------

	public function test_plain_render_is_the_default_view(): void {
		$atts = EventON_Archive_Builder::normalize_atts( array() );

		$this->assertTrue( self::call( 'is_default_view', $atts ) );
	}

	#[DataProvider( 'narrowing_atts' )]
	public function test_anything_that_narrows_the_window_is_not_the_default_view( array $override ): void {
		$atts = EventON_Archive_Builder::normalize_atts( $override );

		$this->assertFalse( self::call( 'is_default_view', $atts ) );
	}

	public static function narrowing_atts(): array {
		return array(
			'past only'   => array( array( 'show' => 'past' ) ),
			'future only' => array( array( 'show' => 'future' ) ),
			'one family'  => array( array( 'family' => 'Bike Night' ) ),
			'capped'      => array( array( 'limit' => 5 ) ),
		);
	}

	#[DataProvider( 'presentation_atts' )]
	public function test_presentation_options_stay_the_default_view( array $override ): void {
		// These change how the archive looks, not which events it counts, so they
		// must not disqualify a render from writing the figures.
		$atts = EventON_Archive_Builder::normalize_atts( $override );

		$this->assertTrue( self::call( 'is_default_view', $atts ) );
	}

	public static function presentation_atts(): array {
		return array(
			'ascending' => array( array( 'order' => 'asc' ) ),
			'locations' => array( array( 'location' => 'yes' ) ),
			'counters'  => array( array( 'counters' => 'yes' ) ),
			'flat'      => array( array( 'group' => 'no' ) ),
		);
	}

	// -------------------------------------------------------------------------
	// 5. Family resolution.
	//
	// family="Bike Night" and family="event_type_2" have to canonicalize to one
	// value, or the same list renders twice under two cache keys. An unresolvable
	// value must stay distinguishable from "no filter".
	// -------------------------------------------------------------------------

	#[DataProvider( 'family_spellings' )]
	public function test_family_resolves_to_the_taxonomy_slug( string $input ): void {
		$atts = EventON_Archive_Builder::normalize_atts( array( 'family' => $input ) );

		$this->assertSame( 'event_type_2', $atts['family'] );
	}

	public static function family_spellings(): array {
		return array(
			'slug'          => array( 'event_type_2' ),
			'display name'  => array( 'Bike Night' ),
			'lowercased'    => array( 'bike night' ),
			'shouted'       => array( 'BIKE NIGHT' ),
			'padded'        => array( '  Bike Night  ' ),
			// EventON's own longer label for the taxonomy.
			'long form'     => array( 'Bike Night Categories' ),
			'long lowercase'=> array( 'bike night categories' ),
		);
	}

	public function test_every_spelling_shares_one_cache_signature(): void {
		$signatures = array();

		foreach ( array( 'event_type_2', 'Bike Night', 'bike night', 'Bike Night Categories' ) as $spelling ) {
			$atts         = EventON_Archive_Builder::normalize_atts( array( 'family' => $spelling ) );
			$signatures[] = md5( wp_json_encode( $atts ) );
		}

		$this->assertCount( 1, array_unique( $signatures ) );
	}

	public function test_empty_family_means_no_filter(): void {
		$atts = EventON_Archive_Builder::normalize_atts( array( 'family' => '   ' ) );

		$this->assertSame( '', $atts['family'] );
	}

	public function test_unknown_family_is_kept_as_a_key_safe_token(): void {
		// Not dropped to '': falling back to "no filter" would turn one typo in a
		// shortcode into a hub page quietly republishing the whole archive.
		$atts = EventON_Archive_Builder::normalize_atts( array( 'family' => 'Track Days!!' ) );

		$this->assertNotSame( '', $atts['family'] );
		$this->assertSame( 'trackdays', $atts['family'] );
	}

	public function test_unknown_family_renders_nothing_and_says_why(): void {
		$atts = EventON_Archive_Builder::normalize_atts( array( 'family' => 'Nope' ) );

		// collect_events() is what resolves the family statics, and it needs real
		// WordPress. Drive build_html() with an empty set instead, which is what
		// collect_events() returns for an unknown family.
		$html = EventON_Archive_Builder::build_html( array(), $atts );

		$this->assertStringContainsString( 'eventon-archive--empty', $html );
	}

	public function test_site_with_no_event_types_resolves_nothing(): void {
		$GLOBALS['eventon_archive_test_taxonomies'] = array();

		$this->assertSame( array(), EventON_Archive_Builder::event_taxonomies() );

		$atts = EventON_Archive_Builder::normalize_atts( array( 'family' => 'Bike Night' ) );

		$this->assertSame( 'bikenight', $atts['family'] );
	}

	public function test_taxonomy_labels_drop_the_categories_suffix(): void {
		$this->assertSame(
			array(
				'event_type'   => 'Ride',
				'event_type_2' => 'Bike Night',
				'event_type_3' => 'Track Day',
				'event_type_4' => 'MotoGP Watch Party',
			),
			EventON_Archive_Builder::event_taxonomies()
		);
	}

	// -------------------------------------------------------------------------
	// 6. Other normalization invariants.
	// -------------------------------------------------------------------------

	public function test_unknown_order_and_show_fall_back_to_defaults(): void {
		$atts = EventON_Archive_Builder::normalize_atts(
			array(
				'order' => 'sideways',
				'show'  => 'someday',
			)
		);

		$this->assertSame( 'desc', $atts['order'] );
		$this->assertSame( 'all', $atts['show'] );
	}

	public function test_show_and_order_are_case_insensitive(): void {
		$atts = EventON_Archive_Builder::normalize_atts(
			array(
				'order' => 'ASC',
				'show'  => 'PAST',
			)
		);

		$this->assertSame( 'asc', $atts['order'] );
		$this->assertSame( 'past', $atts['show'] );
	}

	public function test_nav_is_forced_off_when_the_list_is_not_grouped(): void {
		// The year jump links target the year headings, so an ungrouped list has
		// nothing to jump to.
		$atts = EventON_Archive_Builder::normalize_atts(
			array(
				'group' => 'no',
				'nav'   => 'yes',
			)
		);

		$this->assertFalse( $atts['nav'] );
	}

	public function test_unknown_attributes_are_dropped_from_the_signature(): void {
		// Otherwise a stray attribute on a shortcode would mint a cache variant
		// holding a byte-identical copy of an existing one.
		$plain = EventON_Archive_Builder::normalize_atts( array() );
		$noisy = EventON_Archive_Builder::normalize_atts( array( 'colour' => 'blue' ) );

		$this->assertSame( $plain, $noisy );
	}

	// -------------------------------------------------------------------------
	// 7. The counter-tally signature.
	//
	// Only `family` and `show` may reach it. `limit` in particular must not: it
	// describes the slice a page renders, never the window being counted, and
	// hashing it would let a five-row block cache and report "5 bike nights".
	//
	// This is also what keeps rebuild_now()'s seeded tally reachable. It writes
	// one under this signature and counts() reads it back, so a change to either
	// side that is not made to the other silently orphans the seed.
	// -------------------------------------------------------------------------

	public function test_counts_signature_ignores_the_render_cap(): void {
		$this->assertSame(
			self::call( 'counts_signature', EventON_Archive_Builder::normalize_atts( array() ) ),
			self::call( 'counts_signature', EventON_Archive_Builder::normalize_atts( array( 'limit' => 5 ) ) )
		);
	}

	/**
	 * @param array<string,mixed> $override
	 */
	#[DataProvider( 'presentation_atts' )]
	public function test_counts_signature_ignores_presentation_options( array $override ): void {
		$this->assertSame(
			self::call( 'counts_signature', EventON_Archive_Builder::normalize_atts( array() ) ),
			self::call( 'counts_signature', EventON_Archive_Builder::normalize_atts( $override ) )
		);
	}

	public function test_counts_signature_distinguishes_the_window(): void {
		$signatures = array();

		foreach ( array( 'all', 'past', 'future' ) as $show ) {
			$signatures[] = self::call( 'counts_signature', EventON_Archive_Builder::normalize_atts( array( 'show' => $show ) ) );
		}

		foreach ( array( 'Bike Night', 'Track Day' ) as $family ) {
			$signatures[] = self::call( 'counts_signature', EventON_Archive_Builder::normalize_atts( array( 'family' => $family ) ) );
		}

		$this->assertCount( count( $signatures ), array_unique( $signatures ) );
	}

	public function test_counts_signature_matches_across_family_spellings(): void {
		// Same reason as the render cache: family="Bike Night" and
		// family="event_type_2" describe one window, so they share one tally.
		$this->assertSame(
			self::call( 'counts_signature', EventON_Archive_Builder::normalize_atts( array( 'family' => 'Bike Night' ) ) ),
			self::call( 'counts_signature', EventON_Archive_Builder::normalize_atts( array( 'family' => 'event_type_2' ) ) )
		);
	}

	// -------------------------------------------------------------------------
	// 8. Variant eviction.
	// -------------------------------------------------------------------------

	public function test_variants_under_the_cap_are_left_alone(): void {
		$entries = array(
			'a' => array( 'built' => 100 ),
			'b' => array( 'built' => 200 ),
		);

		$this->assertSame( $entries, self::call( 'trim_variants', $entries ) );
	}

	public function test_oldest_variants_are_evicted_first(): void {
		$max     = ( new ReflectionClass( EventON_Archive_Builder::class ) )->getConstant( 'MAX_CACHED_VARIANTS' );
		$entries = array();

		// One more than the cap, built in ascending order, so the very first is
		// the one that should go.
		for ( $i = 0; $i <= $max; $i++ ) {
			$entries[ 'sig' . $i ] = array( 'built' => 1000 + $i );
		}

		$kept = self::call( 'trim_variants', $entries );

		$this->assertCount( $max, $kept );
		$this->assertArrayNotHasKey( 'sig0', $kept );
		$this->assertArrayHasKey( 'sig' . $max, $kept );
	}

	public function test_entries_missing_a_built_timestamp_are_evicted_first(): void {
		$max     = ( new ReflectionClass( EventON_Archive_Builder::class ) )->getConstant( 'MAX_CACHED_VARIANTS' );
		$entries = array( 'undated' => array() );

		for ( $i = 0; $i < $max; $i++ ) {
			$entries[ 'sig' . $i ] = array( 'built' => 1000 + $i );
		}

		$kept = self::call( 'trim_variants', $entries );

		$this->assertCount( $max, $kept );
		$this->assertArrayNotHasKey( 'undated', $kept );
	}

	// -------------------------------------------------------------------------
	// 9. Escaping at the render boundary.
	//
	// Not a substitute for the security review, but a title carrying markup is
	// the one hostile input this layer can be handed without a database.
	// -------------------------------------------------------------------------

	public function test_event_titles_are_escaped(): void {
		$event          = self::event( gmmktime( 12, 0, 0, 6, 1, 2024 ) );
		$event['title'] = '<script>alert(1)</script>';

		$atts = EventON_Archive_Builder::normalize_atts( array() );
		$html = EventON_Archive_Builder::build_html( array( $event ), $atts );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_empty_event_set_renders_the_empty_state(): void {
		$atts = EventON_Archive_Builder::normalize_atts( array() );
		$html = EventON_Archive_Builder::build_html( array(), $atts );

		$this->assertStringContainsString( 'eventon-archive--empty', $html );
		$this->assertStringContainsString( 'No events to show yet.', $html );
	}
}
