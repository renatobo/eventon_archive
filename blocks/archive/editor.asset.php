<?php
/**
 * Asset manifest for the block editor script.
 *
 * `@wordpress/scripts` generates one of these next to every compiled block
 * script. This plugin has no build step on purpose, so it is written by hand.
 *
 * It is not optional metadata. Core's `register_block_script_handle()` falls back
 * to `array()` dependencies and a `false` version when this file is absent
 * (`wp-includes/blocks.php`), and three things follow from that:
 *
 *   1. `wp_set_script_translations()` is skipped entirely, because core only
 *      calls it when `wp-i18n` appears in the declared dependencies. Every
 *      `__()` string in `editor.js` becomes untranslatable.
 *   2. The script URL carries no `?ver=`, so a browser keeps its cached copy of
 *      `editor.js` across plugin updates.
 *   3. Nothing orders the script after the `wp.*` globals it reads. They happen
 *      to be present today because `wp-editor` and `wp-block-library` both pull
 *      in `wp-server-side-render`, and because `enqueue_block_editor_assets`
 *      fires after core's own enqueues. That is enqueue-order luck, not a
 *      guarantee.
 *
 * The dependency list mirrors the IIFE arguments at the bottom of `editor.js`,
 * in the same order. Keep the two in step: a global added there needs its handle
 * added here.
 *
 * `version` reads the plugin constant rather than repeating the number, so the
 * version still lives in only the two places CLAUDE.md documents. Safe because
 * core `require`s this file from `register_block_type_from_metadata()`, long
 * after `eventon_archive.php` defines it.
 *
 * @package EventON_Archive
 */

// Core requires this file with ABSPATH defined, so the return below is reached
// normally. A direct hit on the file would otherwise fatal on the undefined
// constant and put a stack trace in the log.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'dependencies' => array(
		'wp-blocks',
		'wp-element',
		'wp-block-editor',
		'wp-components',
		'wp-server-side-render',
		'wp-i18n',
	),
	'version'      => EVENTON_ARCHIVE_VERSION,
);
