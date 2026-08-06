<?php
/**
 * Test bootstrap: a deliberately small fake WordPress.
 *
 * These tests cover the parts of the builder whose logic is its own: attribute
 * coercion, family resolution, the date grouping keys, the default-view gate and
 * variant eviction. None of that needs a database, so none of it gets one.
 *
 * The rule for what belongs in here: a fake is allowed only when the real
 * function's behaviour is simple enough to reproduce honestly in a few lines.
 * Anything whose behaviour the plugin actually leans on — WP_Query, post meta,
 * term lookups, the options table — is deliberately absent, because a fake of it
 * would make the test assert the fake. Those surfaces need real WordPress and are
 * out of scope here; see CLAUDE.md, "Testing".
 *
 * @package EventON_Archive
 */

declare( strict_types = 1 );

// The site timezone is deliberately NOT UTC. `evcal_srow` is local wall time
// stored as a UTC timestamp, so the plugin must format it with gmdate() or an
// explicit UTC DateTimeZone. Pinning the ambient zone to a negative offset means
// any code that forgets, and lets the site offset apply, shifts the printed date
// and fails the grouping test rather than passing by luck on a UTC CI box.
date_default_timezone_set( 'America/Los_Angeles' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

define( 'EVENTON_ARCHIVE_VERSION', '0.0.0-fixture' );
define( 'EVENTON_ARCHIVE_FILE', __DIR__ . '/eventon_archive.php' );
define( 'EVENTON_ARCHIVE_DIR', dirname( __DIR__ ) . '/' );
define( 'EVENTON_ARCHIVE_URL', 'https://example.test/wp-content/plugins/eventon_archive/' );
define( 'EVENTON_ARCHIVE_CACHE_OPTION', 'eventon_archive_cache' );
define( 'EVENTON_ARCHIVE_CRON_HOOK', 'eventon_archive_rebuild' );
define( 'EVENTON_ARCHIVE_CRON_SOON', 'eventon_archive_rebuild_soon' );

/**
 * The event-type taxonomies the fake `get_object_taxonomies()` will report.
 *
 * Mutable so a test can model a site with no event types configured, or with a
 * different set, without rebuilding the bootstrap. Shaped like EventON's real
 * registration: `menu_name` is the bare family name, `label` is the same string
 * plus " Categories".
 *
 * @var array<string,string>
 */
$GLOBALS['eventon_archive_test_taxonomies'] = array(
	'event_type'   => 'Ride',
	'event_type_2' => 'Bike Night',
	'event_type_3' => 'Track Day',
	'event_type_4' => 'MotoGP Watch Party',
);

/**
 * Reset the fake taxonomy set to the drocdesmo-shaped default.
 */
function eventon_archive_test_reset_taxonomies(): void {
	$GLOBALS['eventon_archive_test_taxonomies'] = array(
		'event_type'   => 'Ride',
		'event_type_2' => 'Bike Night',
		'event_type_3' => 'Track Day',
		'event_type_4' => 'MotoGP Watch Party',
	);
}

/**
 * Stand-in for get_object_taxonomies( $type, 'objects' ).
 *
 * Only the 'objects' shape is used by the code under test, and only the
 * `labels->menu_name` and `label` properties are read.
 *
 * @param string $object_type Ignored; the fake site has one post type.
 * @param string $output      'objects' or 'names'.
 * @return array<string,object|string>
 */
function get_object_taxonomies( $object_type, $output = 'names' ) {
	$found = array();

	foreach ( $GLOBALS['eventon_archive_test_taxonomies'] as $slug => $name ) {
		if ( 'objects' !== $output ) {
			$found[ $slug ] = $slug;
			continue;
		}

		$taxonomy               = new stdClass();
		$taxonomy->name         = $slug;
		$taxonomy->label        = $name . ' Categories';
		$taxonomy->labels       = new stdClass();
		$taxonomy->labels->name = $name . ' Categories';

		// EventON sets menu_name to the bare family name. A test can blank this
		// to exercise the label-plus-suffix fallback in event_taxonomies().
		$taxonomy->labels->menu_name = $name;

		$found[ $slug ] = $taxonomy;
	}

	return $found;
}

/**
 * No filters are registered in these tests, so every filter is a passthrough.
 *
 * Kept deliberately dumb: the plugin's filters are extension points for other
 * code, and exercising them is a different test than the ones here.
 *
 * @param string $hook_name Unused.
 * @param mixed  $value     Value to return unchanged.
 * @param mixed  ...$args   Unused.
 * @return mixed
 */
function apply_filters( $hook_name, $value, ...$args ) {
	return $value;
}

/**
 * Faithful enough reproduction of shortcode_atts().
 *
 * Real behaviour: keys not present in $pairs are dropped, keys present in $pairs
 * take the supplied value, everything else falls back to the default. The
 * `shortcode_atts_{$shortcode}` filter is omitted along with all the others.
 *
 * @param array  $pairs     Defaults.
 * @param array  $atts      Supplied attributes.
 * @param string $shortcode Unused.
 * @return array
 */
function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$atts = (array) $atts;
	$out  = array();

	foreach ( $pairs as $name => $default ) {
		$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
	}

	return $out;
}

/**
 * sanitize_key(): lowercase, and only alphanumerics, underscores and dashes.
 *
 * @param string $key Raw key.
 * @return string
 */
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

/**
 * wp_date() restricted to the one call shape the plugin uses.
 *
 * The plugin always passes an explicit DateTimeZone. When it does not, this
 * falls back to the ambient zone set at the top of this file, which is not UTC,
 * so the omission shows up as a wrong date rather than passing silently.
 *
 * Month and day names are not translated; the tests assert English.
 *
 * @param string            $format    Date format.
 * @param int|null          $timestamp Unix timestamp.
 * @param DateTimeZone|null $timezone  Timezone.
 * @return string
 */
function wp_date( $format, $timestamp = null, $timezone = null ) {
	$timestamp = null === $timestamp ? time() : (int) $timestamp;
	$zone      = $timezone instanceof DateTimeZone ? $timezone : new DateTimeZone( date_default_timezone_get() );

	return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $zone )->format( $format );
}

/**
 * current_time( 'timestamp' ): local wall time expressed as a UTC timestamp.
 *
 * The same convention EventON stores evcal_srow in, which is the whole reason
 * the plugin compares against this rather than time().
 *
 * @param string $type Format or 'timestamp'.
 * @param bool   $gmt  Whether to use UTC.
 * @return int|string
 */
function current_time( $type, $gmt = false ) {
	$offset = ( new DateTimeZone( date_default_timezone_get() ) )->getOffset( new DateTimeImmutable( 'now' ) );

	if ( 'timestamp' === $type || 'U' === $type ) {
		return $gmt ? time() : time() + $offset;
	}

	return gmdate( (string) $type, $gmt ? time() : time() + $offset );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
}

function esc_html__( $text, $domain = 'default' ) {
	return esc_html( $text );
}

function esc_attr__( $text, $domain = 'default' ) {
	return esc_attr( $text );
}

function esc_html_e( $text, $domain = 'default' ) {
	echo esc_html( $text );
}

function __( $text, $domain = 'default' ) {
	return (string) $text;
}

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, (int) $decimals );
}

function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, (int) $options, (int) $depth );
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/**
 * Minimal WP_Error, only enough for is_wp_error() checks.
 */
class WP_Error {
	public function __construct( public string $code = '', public string $message = '' ) {}
}

/**
 * Minimal WP_Term, for the location_name() term-name path.
 */
class WP_Term {
	public function __construct( public int $term_id = 0, public string $name = '' ) {}
}

require_once dirname( __DIR__ ) . '/includes/class-eventon-archive-builder.php';
