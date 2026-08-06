<?php
/**
 * PHPStan bootstrap: declares the plugin's own constants.
 *
 * `eventon_archive.php` defines these before requiring the two include files, so
 * at runtime they always exist. PHPStan analyses each file on its own and has no
 * way to know that, so without this it reports every use inside `includes/` as an
 * undefined constant.
 *
 * Values are irrelevant to the analysis; only the names and types matter.
 *
 * Not shipped, and not loaded by WordPress. Analysis tooling only.
 *
 * @package EventON_Archive
 */

define( 'EVENTON_ARCHIVE_VERSION', '0.0.0-fixture' );
define( 'EVENTON_ARCHIVE_FILE', __FILE__ );
define( 'EVENTON_ARCHIVE_DIR', __DIR__ . '/' );
define( 'EVENTON_ARCHIVE_URL', 'https://example.com/wp-content/plugins/eventon_archive/' );
define( 'EVENTON_ARCHIVE_CACHE_OPTION', 'eventon_archive_cache' );
define( 'EVENTON_ARCHIVE_CRON_HOOK', 'eventon_archive_rebuild' );
define( 'EVENTON_ARCHIVE_CRON_SOON', 'eventon_archive_rebuild_soon' );
