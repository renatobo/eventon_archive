<?php
/**
 * Plugin Name:       EventON Archive
 * Plugin URI:        https://github.com/renatobo/eventon_archive
 * Description:       Builds a cached, crawlable archive of every EventON event, grouped by year and month. Exists so past events are reachable by search engines: EventON's calendars hide them behind AJAX, a rolling WP_Query window, and a hide-past setting, which leaves hundreds of event pages orphaned.
 * Version:           1.6.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Renato Bonomini
 * Author URI:        https://github.com/renatobo
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       eventon_archive
 *
 * GitHub Plugin URI: https://github.com/renatobo/eventon_archive
 * GitHub Branch:     main
 * Primary Branch:    main
 *
 * @package EventON_Archive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EVENTON_ARCHIVE_VERSION', '1.6.0' );
define( 'EVENTON_ARCHIVE_FILE', __FILE__ );
define( 'EVENTON_ARCHIVE_DIR', plugin_dir_path( __FILE__ ) );
define( 'EVENTON_ARCHIVE_URL', plugin_dir_url( __FILE__ ) );

/** Option holding the rendered HTML, keyed by shortcode signature. Not autoloaded. */
define( 'EVENTON_ARCHIVE_CACHE_OPTION', 'eventon_archive_cache' );

/** Daily cron hook that discards the cache and re-warms the default view. */
define( 'EVENTON_ARCHIVE_CRON_HOOK', 'eventon_archive_rebuild' );

/** Debounced rebuild fired a few minutes after an event is edited. */
define( 'EVENTON_ARCHIVE_CRON_SOON', 'eventon_archive_rebuild_soon' );

require_once EVENTON_ARCHIVE_DIR . 'includes/class-eventon-archive-builder.php';
require_once EVENTON_ARCHIVE_DIR . 'includes/class-eventon-archive-admin.php';

/**
 * Boot the plugin.
 */
function eventon_archive_init() {
	add_shortcode( 'eventon_archive', array( 'EventON_Archive_Builder', 'shortcode' ) );

	// Registered up front so block.json can reference the handle as its style,
	// and so every enqueue path below can pass the handle alone.
	wp_register_style(
		'eventon-archive',
		EVENTON_ARCHIVE_URL . 'assets/eventon-archive.css',
		array(),
		EVENTON_ARCHIVE_VERSION
	);

	// Enqueued from the builder only when the counters option is on, and only in
	// the footer: it is progressive enhancement over numbers already in the HTML.
	wp_register_script(
		'eventon-archive-counters',
		EVENTON_ARCHIVE_URL . 'assets/eventon-archive-counters.js',
		array(),
		EVENTON_ARCHIVE_VERSION,
		true
	);

	eventon_archive_register_block();

	add_action( EVENTON_ARCHIVE_CRON_HOOK, 'eventon_archive_cron_rebuild' );
	add_action( EVENTON_ARCHIVE_CRON_SOON, 'eventon_archive_cron_rebuild' );

	// Any change to an event invalidates the archive. Debounced, because a bulk
	// edit or an import would otherwise rebuild once per post.
	add_action( 'save_post_ajde_events', 'eventon_archive_schedule_soon', 10, 0 );
	add_action( 'deleted_post', 'eventon_archive_on_deleted_post', 10, 2 );
	add_action( 'trashed_post', 'eventon_archive_on_trashed_post' );

	// Enqueue early when the queried page uses the shortcode, so the stylesheet
	// lands in wp_head. Under a block theme the shortcode is expanded from inside
	// the core/post-content block, long after wp_enqueue_scripts has run, and a
	// style enqueued at that point is deferred to the footer.
	add_action( 'wp_enqueue_scripts', 'eventon_archive_maybe_enqueue_early' );

	if ( is_admin() ) {
		EventON_Archive_Admin::init();
	}
}

/**
 * Enqueue the stylesheet up front if this request will render the archive.
 *
 * Checks the queried object's own content. That covers the normal case, a page
 * holding the shortcode, in both classic and block themes. The builder enqueues
 * again as a fallback for anywhere else the shortcode might be run from, and
 * wp_enqueue_style is idempotent so the double call is harmless.
 */
function eventon_archive_maybe_enqueue_early() {
	if ( is_admin() || ! is_singular() ) {
		return;
	}

	$post = get_queried_object();

	if ( ! $post instanceof WP_Post ) {
		return;
	}

	// The block registers its own style through block.json, so only the
	// shortcode path needs this.
	if ( ! has_shortcode( $post->post_content, 'eventon_archive' ) ) {
		return;
	}

	/** This filter is documented in includes/class-eventon-archive-builder.php */
	if ( ! apply_filters( 'eventon_archive_load_styles', true ) ) {
		return;
	}

	wp_enqueue_style( 'eventon-archive' );
}
add_action( 'init', 'eventon_archive_init' );

/**
 * Register the editor block.
 *
 * The block and the shortcode are two front doors onto the same renderer. The
 * block exists because a shortcode never appears in the inserter, and because
 * `core/shortcode` is not expanded inside Site Editor templates or template
 * parts, where a real block is.
 */
function eventon_archive_register_block() {
	if ( ! function_exists( 'register_block_type_from_metadata' ) ) {
		return;
	}

	register_block_type_from_metadata(
		EVENTON_ARCHIVE_DIR . 'blocks/archive',
		array( 'render_callback' => 'eventon_archive_render_block' )
	);
}

/**
 * Server render for the block.
 *
 * @param array $attributes Block attributes.
 * @return string HTML.
 */
function eventon_archive_render_block( $attributes ) {
	$html = EventON_Archive_Builder::render( is_array( $attributes ) ? $attributes : array() );

	// Outer wrapper carries alignment and spacing classes from block supports.
	return sprintf(
		'<div %s>%s</div>',
		function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes() : '',
		$html
	);
}

/**
 * Rebuild the cache from cron: drop everything, then warm the default view so
 * the first visitor after the run does not pay for the query.
 */
function eventon_archive_cron_rebuild() {
	EventON_Archive_Builder::flush_cache();
	EventON_Archive_Builder::render( array() );
}

/**
 * Queue a rebuild a few minutes out, collapsing a burst of edits into one run.
 */
function eventon_archive_schedule_soon() {
	if ( wp_next_scheduled( EVENTON_ARCHIVE_CRON_SOON ) ) {
		return;
	}

	/**
	 * Filters how long to wait after an event edit before rebuilding.
	 *
	 * @param int $delay Seconds.
	 */
	$delay = (int) apply_filters( 'eventon_archive_rebuild_delay', 5 * MINUTE_IN_SECONDS );

	wp_schedule_single_event( time() + max( 60, $delay ), EVENTON_ARCHIVE_CRON_SOON );
}

/**
 * Rebuild when an event is deleted outright.
 *
 * @param int          $post_id Deleted post ID.
 * @param WP_Post|null $post    Deleted post object, when WordPress passes one.
 */
function eventon_archive_on_deleted_post( $post_id, $post = null ) {
	$post_type = $post instanceof WP_Post ? $post->post_type : get_post_type( $post_id );

	if ( 'ajde_events' === $post_type ) {
		eventon_archive_schedule_soon();
	}
}

/**
 * Rebuild when an event is trashed.
 *
 * @param int $post_id Trashed post ID.
 */
function eventon_archive_on_trashed_post( $post_id ) {
	if ( 'ajde_events' === get_post_type( $post_id ) ) {
		eventon_archive_schedule_soon();
	}
}

/**
 * Schedule the daily rebuild on activation.
 *
 * Fired at 03:20 local time rather than on the hour, to stay clear of whatever
 * else the host runs at midnight.
 */
function eventon_archive_activate() {
	if ( ! wp_next_scheduled( EVENTON_ARCHIVE_CRON_HOOK ) ) {
		$first = strtotime( 'tomorrow 03:20', current_time( 'timestamp' ) );
		wp_schedule_event( $first ? $first : time() + DAY_IN_SECONDS, 'daily', EVENTON_ARCHIVE_CRON_HOOK );
	}
}
register_activation_hook( __FILE__, 'eventon_archive_activate' );

/**
 * Clear the schedule and the cache on deactivation. Leaves no orphan cron rows.
 */
function eventon_archive_deactivate() {
	wp_clear_scheduled_hook( EVENTON_ARCHIVE_CRON_HOOK );
	wp_clear_scheduled_hook( EVENTON_ARCHIVE_CRON_SOON );
	delete_option( EVENTON_ARCHIVE_CACHE_OPTION );
}
register_deactivation_hook( __FILE__, 'eventon_archive_deactivate' );
