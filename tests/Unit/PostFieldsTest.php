<?php
/**
 * The site-fields round (2026-10-29), the red team's failing tests: the "Post fields" settings rows and a field's value, cleaned (no
 * WordPress loaded: the few functions the cleaning needs are faked). Replaces the earlier round's parked `SiteFieldsTest.php` (a filter's
 * label-and-value pairs for private topics; that round is dropped).
 *
 * Brief (OmnaBase `docs/briefs/site-fields-round.md`) rule 5: "a small table of rows, each a Field input (the post meta key …) and a Name
 * input (the label the team sees, at most 60 characters) … at most 10 rows … A key is a-z 0-9 _ - (lower-cased); a row with a bad key or no
 * name is dropped with a notice naming it"; "the value through the filter …, then tags stripped, entities decoded, trimmed, cut at 10,000".
 * Report `docs/reviews/site-fields-round-red-team-report.md` in the OmnaBase repository, findings 15 to 20.
 *
 * The words: *post meta*, a named extra value WordPress keeps beside a post (AviaSupport's `_av_supp_private_info`); *entity*, HTML's escaped
 * form of a character (`&amp;` for `&`).
 *
 * Shapes the red team pins (chosen; the builder may rename and tell the red team):
 * - One class `OmnaBase\WordPress\Forum\PostFields` (the declared fields: the setting's rows and the filter, their limits, a value's
 *   cleaning). `PostFields::cleanRows( mixed $rows ): array{ fields: array<string,string>, dropped: string[] }`: the rows as posted
 *   (`[ [ 'key' => …, 'label' => … ], … ]`) become a map key ⇒ name, in order; a row with both inputs empty is skipped silently (the empty
 *   row the screen always offers); a row with a bad key, no name, a name over 60 characters, a key seen before, or one of the site's own
 *   keys (`_bbp_…`, bbPress's; `_omnabase_…`, the plugin's own marks; `_wp_…` and `_edit_…`, WordPress's) is dropped and named in
 *   `dropped`; at most 10 are kept, the rest named in `dropped`.
 * - (chosen, finding 16) A key with capital letters is dropped, not lower-cased: WordPress reads post meta by its exact name, so a lower-cased
 *   `MyField` would read and write another field than the one the site uses.
 * - `PostFields::value( string $stored ): string`: a post meta value as OmnaBase takes it, the way a post's text is made plain
 *   (`PlainText::of`: paragraphs and `<br>` become line breaks, tags and scripts go, entities are decoded), trimmed, cut at 10,000
 *   characters. (AviaSupport stores its private data through bbPress's input filters: `&` as `&amp;`, `<` as `&lt;`.)
 *
 * Why these fail today: there is no `PostFields` class.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Unit;

use Brain\Monkey\Functions;

final class PostFieldsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn ( $s ) => trim( strip_tags( (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $s ) ) ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $s ) => trim( (string) preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ) );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
	}

	/** The class, or a failed test naming it. */
	private static function fields(): string {
		$class = 'OmnaBase\\WordPress\\Forum\\PostFields';
		self::assertTrue( class_exists( $class ), 'the class OmnaBase\\WordPress\\Forum\\PostFields' );
		return $class;
	}

	/**
	 * What it checks: The administrator's rows become the fields that travel, and a mistake is named instead of swallowed.
	 * Passes when: AviaSupport's key with the name "Private data" is kept; the always-offered empty row is skipped silently; a key with a
	 *   space, a key with capitals, a row without a name, a 61-character name, a key given twice and bbPress's, the plugin's and WordPress's own
	 *   keys are dropped, each named in the dropped list.
	 * Fails when: a typo silently sends nothing, the plugin lets OmnaBase overwrite its own marks or bbPress's counters, or a capitalised key
	 *   quietly reads the wrong field.
	 */
	public function test_the_rows_kept_and_the_ones_dropped(): void {
		$class = self::fields();
		$out   = $class::cleanRows(
			array(
				array( 'key' => '_av_supp_private_info', 'label' => 'Private data' ),
				array( 'key' => '', 'label' => '' ),
				array( 'key' => 'private data', 'label' => 'Spaces' ),
				array( 'key' => 'MyField', 'label' => 'Capitals' ),
				array( 'key' => 'no_name', 'label' => '' ),
				array( 'key' => 'long_name', 'label' => str_repeat( 'N', 61 ) ),
				array( 'key' => '_av_supp_private_info', 'label' => 'Again' ),
				array( 'key' => '_bbp_forum_id', 'label' => 'Forum' ),
				array( 'key' => '_omnabase_message_id', 'label' => 'Ours' ),
				array( 'key' => '_wp_page_template', 'label' => 'Template' ),
				array( 'key' => '_edit_lock', 'label' => 'Lock' ),
				array( 'key' => 'licence', 'label' => '  Licence  ' ),
			)
		);
		$this->assertSame( array( '_av_supp_private_info' => 'Private data', 'licence' => 'Licence' ), $out['fields'] );
		foreach ( array( 'private data', 'MyField', 'no_name', 'long_name', '_bbp_forum_id', '_omnabase_message_id', '_wp_page_template', '_edit_lock' ) as $named ) {
			$this->assertContains( $named, $out['dropped'], $named . ' is named as dropped' );
		}
		$this->assertNotContains( '', $out['dropped'], 'the empty row is skipped without a word' );
	}

	/**
	 * What it checks: At most ten fields travel, as OmnaBase takes, and nothing that is not a row breaks the save.
	 * Passes when: twelve good rows keep the first ten in order and name the other two as dropped; a string, null or a list of strings give
	 *   no fields.
	 * Fails when: an eleventh field gets every topic refused by OmnaBase, or a broken form post stops the forum settings from saving.
	 */
	public function test_ten_at_most_and_junk_ignored(): void {
		$class = self::fields();
		$rows  = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$rows[] = array( 'key' => 'field_' . $i, 'label' => 'Field ' . $i );
		}
		$out = $class::cleanRows( $rows );
		$this->assertCount( 10, $out['fields'] );
		$this->assertSame( 'field_1', array_key_first( $out['fields'] ) );
		$this->assertSame( 'field_10', array_key_last( $out['fields'] ) );
		$this->assertContains( 'field_11', $out['dropped'] );
		$this->assertContains( 'field_12', $out['dropped'] );
		foreach ( array( 'nope', null, array( 'a', 'b' ) ) as $junk ) {
			$this->assertSame( array(), $class::cleanRows( $junk )['fields'] );
		}
	}

	/**
	 * What it checks: AviaSupport's stored private data reaches the team as the plain lines the customer typed, within OmnaBase's bound.
	 * Passes when: a value stored as HTML with paragraphs, a line break, a link, escaped characters and a script comes out as plain lines with
	 *   the characters as typed (a password with < and & intact), the link's address as text and no tag left; a 12,000-character value is cut
	 *   to 10,000.
	 * Fails when: the team reads `&amp;` or `<br />` inside a password, the lines run together, or one long value gets the topic refused.
	 */
	public function test_a_value_as_omnabase_takes_it(): void {
		$class = self::fields();
		$this->assertSame(
			"Site: https://shop.test/wp-admin\nUser: admin\nPass: a<b&c",
			$class::value( '<p>Site: <a href="https://shop.test/wp-admin">https://shop.test/wp-admin</a><br />User: admin</p><p>Pass: a&lt;b&amp;c</p><script>alert(1)</script>' )
		);
		$this->assertSame( 10000, mb_strlen( $class::value( str_repeat( 'v', 12000 ) ) ) );
		$this->assertSame( '', $class::value( '<p></p>' ) );
	}
}
