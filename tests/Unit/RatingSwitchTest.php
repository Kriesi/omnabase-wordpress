<?php
/**
 * The customer rating round (2026-11-17), part C, the red team's failing tests: the forum switch and the mark on a posted reply, without
 * WordPress (the few WordPress functions they read are faked).
 *
 * OmnaBase brief docs/briefs/customer-rating-round.md, "Part C, the plugin"; report docs/reviews/customer-rating-round-red-team-report.md in the
 * OmnaBase repository, findings C1, C4 and C5.
 *
 * The words: *option*, a stored setting (the forum settings are one option, `omnabase_forum`); *post meta*, a named value WordPress keeps
 * beside a post; *guest*, a forum visitor who wrote without an account (a topic whose author is user 0); *password-protected topic*, a topic
 * only people with its password may read.
 *
 * Shapes the red team pins (chosen where the brief is silent; the builder may rename and tell the red team):
 * - `Forum\Settings::askRating()`: whether OmnaBase may ask this site's forum customers for a rating; the key `ask_rating` inside the forum
 *   option, ON when never stored (the founder's decision 2: asked by default).
 * - (chosen, finding C1) `Forum\RatingAsk::decide( int $reply_id, \WP_Post $topic )` marks a reply the plugin posted with the post meta
 *   `_omnabase_rating_ask` = '1' when the switch is on, the topic's author has an account and the topic has no password: decided at posting,
 *   like the answer mail's mark; `RatingAsk::isMarked( $reply_id )` reads it. `PostedAnswer` then adds `rating: "ask"` to its answer.
 *
 * Why these fail today: neither the switch nor the mark exists.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Unit;

use Brain\Monkey\Functions;
use OmnaBase\WordPress\Forum\Settings;

final class RatingSwitchTest extends TestCase {

	/** @var array<int, array<string, string>> post meta written by the code under test, per post */
	private array $meta = array();

	protected function setUp(): void {
		parent::setUp();
		$this->meta = array();
		Functions\when( 'update_post_meta' )->alias( function ( $id, $key, $value ) { $this->meta[ (int) $id ][ (string) $key ] = (string) $value; return true; } );
		Functions\when( 'get_post_meta' )->alias( fn ( $id, $key ) => $this->meta[ (int) $id ][ (string) $key ] ?? '' );
	}

	/** The forum option as stored. */
	private function stored( array $forum ): void {
		Functions\when( 'get_option' )->alias( fn ( $name, $fallback = false ) => 'omnabase_forum' === $name ? $forum : $fallback );
	}

	/** A topic as bbPress keeps it. */
	private function topic( int $author, string $password = '' ): \WP_Post {
		$t                = \Mockery::mock( 'WP_Post' ); // the unit layer's way (OldTopicReplyTest)
		$t->ID            = 700;
		$t->post_author   = $author;
		$t->post_password = $password;
		return $t;
	}

	/** The decision class under its pinned name. */
	private function rating_ask(): object {
		$class = 'OmnaBase\\WordPress\\Forum\\RatingAsk';
		$this->assertTrue( class_exists( $class ), 'Forum\\RatingAsk exists' );
		return new $class( new Settings() );
	}

	/**
	 * What it checks: [C] A site's forum customers are asked by default, and a site owner can switch it off.
	 * Passes when: with no stored choice the switch reads on; with `ask_rating` stored as false it reads off.
	 * Fails when: existing sites never ask anyone after the update (against the founder's decision), or the owner's "off" is ignored.
	 */
	public function test_on_by_default_off_when_stored(): void {
		$this->stored( array() );
		$this->assertTrue( method_exists( Settings::class, 'askRating' ), 'Settings::askRating exists' );
		$this->assertTrue( ( new Settings() )->askRating() );
		$this->stored( array( 'ask_rating' => false ) );
		$this->assertFalse( ( new Settings() )->askRating() );
	}

	/**
	 * What it checks: [C] Only a signed-in author of a topic without a password is ever marked for a rating question, and only while the switch
	 *   is on.
	 * Passes when: a member's open topic gets the mark; a guest's topic, a password-protected topic and any topic with the switch off do not.
	 * Fails when: OmnaBase mails a forum guest, or the author of a private topic, or a site that switched the question off.
	 */
	public function test_the_mark_follows_the_switch_the_author_and_the_password(): void {
		$this->stored( array( 'ask_rating' => true ) );
		$ask = $this->rating_ask();
		$ask->decide( 801, $this->topic( 42 ) );
		$ask->decide( 802, $this->topic( 0 ) );
		$ask->decide( 803, $this->topic( 42, 'secret' ) );
		$this->stored( array( 'ask_rating' => false ) );
		$this->rating_ask()->decide( 804, $this->topic( 42 ) );
		$class = get_class( $ask );
		$this->assertTrue( $class::isMarked( 801 ), 'a member\'s open topic' );
		$this->assertFalse( $class::isMarked( 802 ), 'a guest' );
		$this->assertFalse( $class::isMarked( 803 ), 'a password-protected topic' );
		$this->assertFalse( $class::isMarked( 804 ), 'the switch off' );
		$this->assertSame( '1', $this->meta[801]['_omnabase_rating_ask'] ?? '' );
	}
}
