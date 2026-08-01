<?php
/**
 * Settings screen: status, a rebuild button, and the shortcode reference.
 *
 * @package EventON_Archive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI.
 */
class EventON_Archive_Admin {

	const PAGE_SLUG    = 'eventon-archive';
	const REBUILD_NONCE = 'eventon_archive_rebuild';

	/**
	 * Hook the admin screen.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_eventon_archive_rebuild', array( __CLASS__, 'handle_rebuild' ) );
	}

	/**
	 * Register the settings page.
	 */
	public static function add_menu() {
		add_options_page(
			__( 'EventON Archive', 'eventon_archive' ),
			__( 'EventON Archive', 'eventon_archive' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Rebuild now, from the button.
	 */
	public static function handle_rebuild() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'eventon_archive' ) );
		}

		check_admin_referer( self::REBUILD_NONCE );

		EventON_Archive_Builder::flush_cache();
		EventON_Archive_Builder::render( array() );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => self::PAGE_SLUG,
					'rebuilt'  => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$cache   = EventON_Archive_Builder::get_cache();
		$built   = isset( $cache['built'] ) ? (int) $cache['built'] : 0;
		$count   = isset( $cache['count'] ) ? (int) $cache['count'] : 0;
		$next    = wp_next_scheduled( EVENTON_ARCHIVE_CRON_HOOK );
		$soon    = wp_next_scheduled( EVENTON_ARCHIVE_CRON_SOON );
		$bytes   = 0;

		foreach ( $cache['entries'] as $entry ) {
			$bytes += isset( $entry['html'] ) ? strlen( $entry['html'] ) : 0;
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'EventON Archive', 'eventon_archive' ); ?></h1>

			<?php if ( isset( $_GET['rebuilt'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Archive rebuilt.', 'eventon_archive' ); ?></p>
				</div>
			<?php endif; ?>

			<p>
				<?php
				esc_html_e(
					'Builds a crawlable list of every published event, grouped by year and month, so past events stay reachable by search engines. Members-only events restricted by ARMember are never listed.',
					'eventon_archive'
				);
				?>
			</p>

			<h2><?php esc_html_e( 'Status', 'eventon_archive' ); ?></h2>
			<table class="widefat striped" style="max-width:44rem">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Last built', 'eventon_archive' ); ?></th>
						<td>
							<?php
							echo $built
								? esc_html( wp_date( 'Y-m-d H:i:s', $built ) )
								: esc_html__( 'never', 'eventon_archive' );
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Events listed', 'eventon_archive' ); ?></th>
						<td><?php echo esc_html( (string) $count ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Cached HTML', 'eventon_archive' ); ?></th>
						<td>
							<?php
							printf(
								/* translators: 1: size in KB, 2: number of cached variants */
								esc_html__( '%1$s KB across %2$d variant(s)', 'eventon_archive' ),
								esc_html( number_format_i18n( $bytes / 1024, 1 ) ),
								count( $cache['entries'] )
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Next daily rebuild', 'eventon_archive' ); ?></th>
						<td>
							<?php
							echo $next
								? esc_html( wp_date( 'Y-m-d H:i:s', $next ) )
								: esc_html__( 'not scheduled', 'eventon_archive' );
							?>
						</td>
					</tr>
					<?php if ( $soon ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Pending rebuild after edit', 'eventon_archive' ); ?></th>
							<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', $soon ) ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1rem">
				<input type="hidden" name="action" value="eventon_archive_rebuild" />
				<?php wp_nonce_field( self::REBUILD_NONCE ); ?>
				<?php submit_button( __( 'Rebuild now', 'eventon_archive' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Usage', 'eventon_archive' ); ?></h2>
			<p><?php esc_html_e( 'Add the archive to a page, then link that page from your footer or menu so the archive itself is not orphaned.', 'eventon_archive' ); ?></p>
			<p>
				<strong><?php esc_html_e( 'Block editor:', 'eventon_archive' ); ?></strong>
				<?php esc_html_e( 'insert the "EventON Archive" block and set the options in the sidebar. It also works inside Site Editor templates and template parts.', 'eventon_archive' ); ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Shortcode:', 'eventon_archive' ); ?></strong>
				<code>[eventon_archive]</code>
				<?php esc_html_e( 'Note that a shortcode is not expanded inside Site Editor templates, only in page and post content.', 'eventon_archive' ); ?>
			</p>
			<p><?php esc_html_e( 'The block and the shortcode take the same options and share the same cache.', 'eventon_archive' ); ?></p>

			<table class="widefat striped" style="max-width:44rem">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Attribute', 'eventon_archive' ); ?></th>
						<th><?php esc_html_e( 'Default', 'eventon_archive' ); ?></th>
						<th><?php esc_html_e( 'What it does', 'eventon_archive' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><code>order</code></td>
						<td><code>desc</code></td>
						<td><?php esc_html_e( 'desc puts the newest year first. Use asc for oldest first.', 'eventon_archive' ); ?></td>
					</tr>
					<tr>
						<td><code>show</code></td>
						<td><code>all</code></td>
						<td><?php esc_html_e( 'all, past, or future.', 'eventon_archive' ); ?></td>
					</tr>
					<tr>
						<td><code>location</code></td>
						<td><code>no</code></td>
						<td><?php esc_html_e( 'Append the venue name after each event title.', 'eventon_archive' ); ?></td>
					</tr>
					<tr>
						<td><code>category</code></td>
						<td><code>no</code></td>
						<td><?php esc_html_e( 'Append the event family: Ride, Bike Night, Track Day, MotoGP Watch Party.', 'eventon_archive' ); ?></td>
					</tr>
					<tr>
						<td><code>counters</code></td>
						<td><code>no</code></td>
						<td><?php esc_html_e( 'Totals strip above the list: all events, one per event family, and members-only. Zero figures are hidden. Animates up on scroll.', 'eventon_archive' ); ?></td>
					</tr>
					<tr>
						<td><code>nav</code></td>
						<td><code>yes</code></td>
						<td><?php esc_html_e( 'Year jump links at the top.', 'eventon_archive' ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}
}
