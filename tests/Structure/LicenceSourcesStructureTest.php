<?php
/**
 * The licence-sources round, part one (the red team's failing tests, 2026-11-03): file-level guards (no WordPress needed) for the new
 * customer-data source "Licence in a user field" (a user field: user meta, a value WordPress keeps per user under a name).
 *
 * Brief: OmnaBase `docs/briefs/licence-sources-round.md`, part one, rules 1, 3, 4 and 7. The words: *hook*, a named moment where a plugin's
 * code runs; *unreleased section*, the changelog's newest section while it is still being built (it gets its date on the release day).
 *
 * Shapes the red team pins (chosen; the builder may rename and tell the red team):
 * - The source is the class `Facts\Sources\UserMetaLicence` (id `user_meta_licence`) and is one of the sources the customer-data parts build.
 * - The licence refresh counts it as a licence source.
 * - Some class under `src/Sync/` hooks WordPress's three user-field moments: `added_user_meta`, `updated_user_meta`, `deleted_user_meta`.
 * - readme.txt's "what the plugin sends" bullet (`* **Customer data:**`) names a licence kept in a user field.
 * - changelog.txt's top section is unreleased and has a customer line about a licence from a user field.
 * - DECISIONS.md has a dated entry about the licence field that names why the user-field rule's banned words do not apply to it.
 * - (guard) The user-field rule is not weakened: every banned word of the brief is still banned, so kriesi.at's `av_purchase_code` is still
 *   never sent as an ordinary user field.
 *
 * Why these fail today: there is no such source, no hook on user fields, and no line about it in the readme, the changelog or the decisions.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Structure;

use OmnaBase\WordPress\Facts\MetaPolicy;
use PHPUnit\Framework\TestCase;

final class LicenceSourcesStructureTest extends TestCase {

	/**
	 * What it checks: The new source is built with the others and the licence refresh counts it.
	 * Passes when: src/Facts/Sources/UserMetaLicence.php implements the source interface, the customer-data parts build it among their sources,
	 *   and the licence refresh names it among the licence sources.
	 * Fails when: the source exists but is never asked (kriesi.at still answers no licence), or a changed code never reaches OmnaBase because
	 *   the refresh thinks no licence source is on.
	 */
	public function test_the_source_is_built_and_counted_by_the_refresh(): void {
		$source = $this->code( 'src/Facts/Sources/UserMetaLicence.php' );
		$this->assertMatchesRegularExpression( '/final\s+class\s+UserMetaLicence\s+implements\s+FactsSource/', $source, 'the source class' );
		$this->assertStringContainsString( 'Sources\\UserMetaLicence', $this->code( 'src/CustomerData.php' ), 'built with the other sources' );
		$this->assertMatchesRegularExpression( '/user_meta_licence|UserMetaLicence/', $this->code( 'src/Sync/LicenceRefresh.php' ), 'a licence source for the refresh' );
	}

	/**
	 * What it checks: A change of a user field can queue the refresh, through WordPress's three user-field moments.
	 * Passes when: a class under src/Sync/ hooks `added_user_meta`, `updated_user_meta` and `deleted_user_meta`.
	 * Fails when: a member's renewed purchase code shows the old licence in OmnaBase until a ticket happens to be opened.
	 */
	public function test_the_three_user_field_moments_are_hooked(): void {
		$sync = implode( "\n", array_map( array( $this, 'code' ), $this->phpUnder( 'src/Sync' ) ) );
		foreach ( array( 'added_user_meta', 'updated_user_meta', 'deleted_user_meta' ) as $hook ) {
			$this->assertMatchesRegularExpression( '/add_action\(\s*[\'"]' . $hook . '[\'"]/', $sync, $hook );
		}
	}

	/**
	 * What it checks: The readme tells site owners that the plugin may send a licence kept in a user field.
	 * Passes when: readme.txt's "Customer data:" bullet of what the plugin sends mentions a user field together with the licence.
	 * Fails when: the public description of what leaves the site is incomplete (WordPress.org's rules and the owner's privacy page rely on it).
	 */
	public function test_the_readme_names_the_new_source(): void {
		$readme = $this->read( 'readme.txt' );
		$this->assertSame( 1, preg_match( '/^\* \*\*Customer data:\*\*[^\n]*$/m', $readme, $m ), 'the "Customer data:" bullet' );
		$this->assertMatchesRegularExpression( '/user field/i', $m[0] );
	}

	/**
	 * What it checks: Customers read about the new source in the release notes still being written.
	 * Passes when: changelog.txt's first section is marked unreleased and holds a line naming a licence and a user field.
	 * Fails when: the change ships without a release note, or the note lands in notes already released.
	 */
	public function test_the_unreleased_changelog_has_the_line(): void {
		$log = $this->read( 'changelog.txt' );
		$this->assertSame( 1, preg_match( '/^(= [^\n]+ — unreleased =)$(.*?)(?=^= |\z)/msu', $log, $m ), 'an unreleased section' );
		preg_match( '/^= [^\n]+ =$/m', $log, $first );
		$this->assertSame( $m[1], $first[0] ?? null, 'the unreleased section is the top one' );
		$this->assertMatchesRegularExpression( '/^\*[^\n]*(licen[cs]e[^\n]*user field|user field[^\n]*licen[cs]e)/mi', $m[2] );
	}

	/**
	 * What it checks: The reason the licence field may hold a banned word is written down.
	 * Passes when: DECISIONS.md has an entry dated November 2026 whose heading names the licence and whose text explains the banned words
	 *   (the user-field rule) do not apply to the field named as the licence.
	 * Fails when: a later round "fixes" the exception and kriesi.at's licence disappears again, because nobody knew why it was there.
	 */
	public function test_the_decision_records_the_policy_exception(): void {
		$decisions = $this->read( 'DECISIONS.md' );
		$this->assertSame( 1, preg_match( '/^## 2026-11-\d\d — [^\n]*licen[cs]e[^\n]*$(.*?)(?=^## |\z)/msiu', $decisions, $m ), 'a dated entry about the licence' );
		$this->assertMatchesRegularExpression( '/banned|MetaPolicy|user-field rule/i', $m[1] );
	}

	/**
	 * What it checks: (guard) The user-field rule is not weakened by the new source.
	 * Passes when: every banned word of the brief still bans a name holding it, and kriesi.at's `av_purchase_code` is still banned as an
	 *   ordinary user field.
	 * Fails when: the builder opens the rule to let the licence field through, and every other site's purchase codes and keys may be ticked
	 *   and sent in clear.
	 */
	public function test_guard_the_user_field_rule_stays_as_strict(): void {
		foreach ( array( 'pass', 'token', 'secret', 'key', 'hash', 'session', 'auth', 'otp', 'nonce', 'code', 'licen', 'card', 'iban', 'backup', 'purchase', 'salt', 'capabilities' ) as $part ) {
			$this->assertTrue( MetaPolicy::isBanned( 'x_' . $part . '_y' ), $part );
		}
		$this->assertTrue( MetaPolicy::isBanned( 'av_purchase_code' ) );
		$this->assertFalse( MetaPolicy::isOffered( 'av_purchase_code' ) );
	}

	/* ---------- helpers ---------- */

	/** The repository's PHP files under a folder. */
	private function phpUnder( string $dir ): array {
		$out = array();
		if ( ! is_dir( OMNABASE_REPO . '/' . $dir ) ) {
			return $out;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( OMNABASE_REPO . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( $f->isFile() && 'php' === $f->getExtension() ) {
				$out[] = str_replace( OMNABASE_REPO . '/', '', $f->getPathname() );
			}
		}
		sort( $out );
		return $out;
	}

	/** A file's PHP without its comments. */
	private function code( string $path ): string {
		$text = $this->read( $path );
		if ( '' === $text ) {
			return '';
		}
		$out = '';
		foreach ( token_get_all( $text ) as $t ) {
			$out .= is_array( $t ) ? ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? '' : $t[1] ) : $t;
		}
		return $out;
	}

	/** A file of the repository, or '' when it is missing. */
	private function read( string $path ): string {
		$p = OMNABASE_REPO . '/' . $path;
		return is_file( $p ) ? (string) file_get_contents( $p ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
