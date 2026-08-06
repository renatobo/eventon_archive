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
	 * Short-lived cache for "which pages render the archive".
	 *
	 * The lookup is one indexed-free `LIKE` over post_content, which is fine once
	 * but not worth repeating on every load of a screen an admin may refresh a few
	 * times while reading it. Cleared explicitly after a rebuild, so someone who
	 * has just added the block and pressed the button sees the truth immediately.
	 */
	const USAGE_TRANSIENT = 'eventon_archive_usage';

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

		// Builds the replacement before dropping the old variants, so a rebuild
		// that times out leaves the previous archive serving. Returns what it
		// listed, which is what the notice reports: a rebuild that finds nothing
		// is the interesting case, and it used to look identical to a good one.
		$count = EventON_Archive_Builder::rebuild_now();

		delete_transient( self::USAGE_TRANSIENT );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'rebuilt' => (string) $count,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Published posts that actually render the archive.
	 *
	 * The one figure this screen could never show before: "Events listed: 376"
	 * looks identical whether the archive is on a linked page or on no page at
	 * all, and an archive nobody links to is the exact problem this plugin was
	 * written to solve.
	 *
	 * This is the only direct `$wpdb` query in the plugin, and it is here because
	 * the alternatives are worse: `WP_Query`'s `s` parameter matches a page merely
	 * *mentioning* the shortcode in prose, and loading every published post to run
	 * `has_shortcode()` in PHP does not scale. Both `LIKE` values are literals run
	 * through `esc_like()` and `prepare()`, so no caller input reaches the query.
	 * `has_shortcode()` and `has_block()` then re-check each hit, because `LIKE`
	 * alone would count a page documenting the shortcode as a page using it.
	 *
	 * @return array<int,array{id:int,title:string}>
	 */
	private static function pages_using_archive() {
		$cached = get_transient( self::USAGE_TRANSIENT );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_status = 'publish'
					AND post_type NOT IN ( 'revision', 'nav_menu_item' )
					AND ( post_content LIKE %s OR post_content LIKE %s )
				ORDER BY post_title ASC
				LIMIT 20",
				'%' . $wpdb->esc_like( '[eventon_archive' ) . '%',
				'%' . $wpdb->esc_like( 'wp:eventon-archive/archive' ) . '%'
			)
		);

		$found = array();

		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );

			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			// Authoritative, unlike the LIKE above. Note this is deliberately
			// false for a page carrying only [eventon_archive_count]: a figure in
			// a sentence is not the archive.
			$renders = has_shortcode( $post->post_content, 'eventon_archive' )
				|| has_block( 'eventon-archive/archive', $post );

			if ( $renders ) {
				$found[] = array(
					'id'    => (int) $id,
					'title' => get_the_title( $post ),
				);
			}
		}

		set_transient( self::USAGE_TRANSIENT, $found, 5 * MINUTE_IN_SECONDS );

		return $found;
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
			// `bytes` is recorded at save time. The strlen() fallback is for rows
			// written before that field existed, which survive a plugin update
			// because the cache option does.
			if ( isset( $entry['bytes'] ) ) {
				$bytes += (int) $entry['bytes'];
			} elseif ( isset( $entry['html'] ) ) {
				$bytes += strlen( $entry['html'] );
			}
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'EventON Archive', 'eventon_archive' ); ?></h1>

			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag on a redirect the handler already verified.
			$rebuilt = isset( $_GET['rebuilt'] ) ? absint( wp_unslash( $_GET['rebuilt'] ) ) : null;

			if ( null !== $rebuilt ) :
				?>
				<div class="notice <?php echo $rebuilt > 0 ? 'notice-success' : 'notice-warning'; ?> is-dismissible">
					<p>
						<?php
						if ( $rebuilt > 0 ) {
							printf(
								/* translators: %s: number of events listed */
								esc_html__( 'Archive rebuilt. %s events listed.', 'eventon_archive' ),
								esc_html( number_format_i18n( $rebuilt ) )
							);
						} else {
							esc_html_e(
								'Rebuild finished, but the archive lists no events. Check that EventON is active and has published events.',
								'eventon_archive'
							);
						}
						?>
					</p>
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
						<td>
							<?php echo esc_html( (string) $count ); ?>
							<p class="description">
								<?php esc_html_e( 'The whole archive: no family filter, no cap. Filtered or capped blocks are cached as their own variants and never move this figure.', 'eventon_archive' ); ?>
							</p>
						</td>
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
					<tr>
						<th scope="row"><?php esc_html_e( 'Shown on', 'eventon_archive' ); ?></th>
						<td>
							<?php
							$pages = self::pages_using_archive();

							if ( $pages ) {
								$links = array();

								foreach ( $pages as $page ) {
									$links[] = sprintf(
										'<a href="%s">%s</a>',
										esc_url( (string) get_permalink( $page['id'] ) ),
										esc_html( '' !== $page['title'] ? $page['title'] : __( '(no title)', 'eventon_archive' ) )
									);
								}

								echo wp_kses_post( implode( ', ', $links ) );
								?>
								<p class="description">
									<?php esc_html_e( 'Link one of these from your footer or menu, or the archive is orphaned too.', 'eventon_archive' ); ?>
								</p>
								<?php
							} else {
								?>
								<strong><?php esc_html_e( 'No published page uses the archive yet.', 'eventon_archive' ); ?></strong>
								<p class="description">
									<?php esc_html_e( 'The cache can build and report a healthy count while nothing renders it. Add the block or the shortcode to a page, then link that page.', 'eventon_archive' ); ?>
								</p>
								<?php
							}
							?>
						</td>
					</tr>
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
						<td><?php esc_html_e( 'Year jump links at the top. Ignored when group is no, because the links target the year headings.', 'eventon_archive' ); ?></td>
					</tr>
					<tr>
						<td><code>family</code></td>
						<td><em><?php esc_html_e( 'every family', 'eventon_archive' ); ?></em></td>
						<td>
							<?php
							$families = EventON_Archive_Builder::event_taxonomies();

							if ( $families ) {
								echo esc_html__( 'Limit to one event family. Accepts the name or the taxonomy slug:', 'eventon_archive' ) . ' ';

								$pairs = array();

								foreach ( $families as $slug => $label ) {
									$pairs[] = sprintf( '%s (<code>%s</code>)', esc_html( $label ), esc_html( $slug ) );
								}

								echo wp_kses_post( implode( ', ', $pairs ) ) . '.';
							} else {
								esc_html_e( 'Limit to one event family. No EventON event types are configured on this site yet.', 'eventon_archive' );
							}
							?>
						</td>
					</tr>
					<tr>
						<td><code>limit</code></td>
						<td><code>0</code></td>
						<td><?php esc_html_e( 'Most events to list. 0 is no cap. The counters are unaffected: they describe the window, not the slice rendered from it.', 'eventon_archive' ); ?></td>
					</tr>
					<tr>
						<td><code>group</code></td>
						<td><code>yes</code></td>
						<td><?php esc_html_e( 'Year and month headings. no gives one flat list with the year on every row, for a short list inside a page whose outline you do not want to disturb.', 'eventon_archive' ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'One figure in a sentence', 'eventon_archive' ); ?></h2>
			<p>
				<?php
				esc_html_e(
					'A count on its own, so a sentence stating how many events the club has run stays true without anyone editing it. Reads the same tallies as the counters strip, so the two can never disagree.',
					'eventon_archive'
				);
				?>
			</p>
			<p><code>[eventon_archive_count family="Bike Night"]</code></p>
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
						<td><code>family</code></td>
						<td><em><?php esc_html_e( 'every family', 'eventon_archive' ); ?></em></td>
						<td><?php esc_html_e( 'Same values as above.', 'eventon_archive' ); ?></td>
					</tr>
					<tr>
						<td><code>show</code></td>
						<td><code>all</code></td>
						<td><?php esc_html_e( 'all, past, or future.', 'eventon_archive' ); ?></td>
					</tr>
					<tr>
						<td><code>of</code></td>
						<td><code>total</code></td>
						<td><?php esc_html_e( 'total counts everything in the window, members-only included. listed counts only what the archive is allowed to show. members_only counts what is withheld.', 'eventon_archive' ); ?></td>
					</tr>
					<tr>
						<td><code>format</code></td>
						<td><code>html</code></td>
						<td><?php esc_html_e( 'html wraps the number in a span. plain returns bare digits, for use inside an attribute.', 'eventon_archive' ); ?></td>
					</tr>
				</tbody>
			</table>
			<p><?php esc_html_e( 'There is no block for this one: it belongs inside a paragraph, and a shortcode expands there. Like any shortcode it does not expand in Site Editor templates.', 'eventon_archive' ); ?></p>
		</div>
		<?php
	}
}
