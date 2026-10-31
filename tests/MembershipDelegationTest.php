<?php
/**
 * Checks delegation only; does not simulate WordPress restriction rules.
 *
 * @package EventON_Archive
 */

declare( strict_types = 1 );

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class MembershipDelegationTest extends TestCase {
	#[RunInSeparateProcess]
	public function test_noindex_helper_is_authoritative_for_both_results(): void {
		// Define the optional integration only in this test's isolated process.
		function droc_noindex_is_arm_restricted( $post_id ) {
			$GLOBALS['archive_delegate_post_id'] = $post_id;
			return $GLOBALS['archive_delegate_result'];
		}

		$GLOBALS['archive_delegate_result'] = true;
		self::assertTrue( EventON_Archive_Builder::is_membership_restricted( 123 ) );
		self::assertSame( 123, $GLOBALS['archive_delegate_post_id'] );

		$GLOBALS['archive_delegate_result'] = false;
		self::assertFalse( EventON_Archive_Builder::is_membership_restricted( 456 ) );
		self::assertSame( 456, $GLOBALS['archive_delegate_post_id'] );
	}
}
