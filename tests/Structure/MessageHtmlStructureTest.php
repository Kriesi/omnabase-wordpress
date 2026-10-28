<?php
/**
 * The plugin's message-centre HTML round (2026-10-28), the red team's failing structure tests (file-level guards, no WordPress, seconds).
 *
 * Brief (OmnaBase `docs/briefs/plugin-message-centre-html-round.md`) rule 2: "One helper (suggested `src/Look/MessageHtml.php`, one public
 * function) turns OmnaBase's message into the HTML to print … The template echoes it through `wp_kses` with the same list"; "The stylesheet:
 * `.ob-wp-messages__body` loses `white-space: pre-line` … and gets rules, through the kit's tokens only and under `.ob-wp` … for paragraphs …,
 * lists (markers back …), links …, quotes …, code and pre …, tables …, rules, headings … Built into `assets/form.css` in the same change";
 * "The forum poster … uses the same helper … instead of `wp_kses_post`"; rule 3: "The plugin's `changelog.txt` 0.2.0 unreleased section: one
 * line for the shaped messages, one for the forum post's tighter cleaning"; the brief's tests: "the helper is the one place that names the
 * allow-list".
 *
 * The words: *allow-list*, the tags and attributes a message may keep; *built stylesheet*, `assets/form.css`, made by `bin/build-css.php`
 * from `styles/form.css` and OmnaBase's tokens; *token*, one of OmnaBase's named design values (`--ob-…`); *pre-line*, the setting that kept
 * a text's line breaks on screen while the thread printed plain text.
 *
 * Shapes the red team pins (chosen; the builder may change them and tell the red team): the helper's class is `MessageHtml` under `src/Look/`
 * (the brief's suggestion); "names the allow-list" is read as "holds the word colspan" (the list's one attribute no other code needs).
 *
 * Why these fail today: there is no helper, the poster calls `wp_kses_post`, the thread's template prints the text through `esc_html`, the
 * body keeps `pre-line` and has no rules for lists, tables, quotes or code, and the 0.2.0 notes say nothing about shaped messages.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Structure;

use PHPUnit\Framework\TestCase;

final class MessageHtmlStructureTest extends TestCase {

	private const HELPER = 'src/Look/MessageHtml.php';

	/**
	 * What it checks: One place in the plugin decides which tags a message may keep, so the message centre and the forum can never drift apart.
	 * Passes when: exactly one file under src/ names the allow-list (it holds the word colspan) and it is the helper under src/Look/; the forum
	 *   poster calls the helper and no longer calls `wp_kses_post`; the thread's template prints a message through `wp_kses` with the helper's
	 *   list and no longer through `esc_html`; the message centre no longer strips a message's tags into text.
	 * Fails when: a second, slightly different list grows somewhere, and a tag the message centre removes reaches the forum (or the other way).
	 */
	public function test_the_helper_is_the_one_place_that_names_the_allow_list(): void {
		$naming = array_values( array_filter( $this->filesUnder( 'src', 'php' ), fn ( string $f ) => str_contains( $this->read( $f ), 'colspan' ) ) );
		$this->assertSame( array( self::HELPER ), $naming, 'files naming the allow-list: ' . implode( ', ', $naming ) );
		$poster = $this->read( 'src/Forum/ReplyPoster.php' );
		$this->assertMatchesRegularExpression( '/\bMessageHtml::/', $poster, 'the poster uses the helper' );
		$this->assertDoesNotMatchRegularExpression( '/\bwp_kses_post\s*\(/', $poster, 'no longer WordPress\'s broad cleaner' );
		$template = $this->read( 'templates/messages.php' );
		$this->assertMatchesRegularExpression( '/echo\s+wp_kses\s*\(\s*\$omnabase_message\[\s*[\'"]html[\'"]\s*\]\s*,[^;]*MessageHtml::/', $template, 'the body is printed through wp_kses with the helper\'s list' );
		$this->assertDoesNotMatchRegularExpression( '/esc_html\s*\(\s*\$omnabase_message\[\s*[\'"]text[\'"]\s*\]/', $template, 'not as escaped text' );
		$this->assertStringNotContainsString( 'wp_strip_all_tags', $this->read( 'src/Portal/Centre.php' ), 'the message centre keeps the markup' );
	}

	/**
	 * What it checks: The thread's message box no longer keeps the text's line breaks by itself; the HTML carries its own breaks.
	 * Passes when: neither the source stylesheet nor the built one sets `white-space: pre-line` in a rule for `.ob-wp-messages__body`.
	 * Fails when: every line break in a message's HTML shows twice (the HTML's own and the kept one), so replies look double-spaced.
	 */
	public function test_the_message_body_has_no_pre_line(): void {
		foreach ( array( 'styles/form.css', 'assets/form.css' ) as $file ) {
			foreach ( $this->rules( $file ) as $rule ) {
				if ( str_contains( $rule['selector'], 'ob-wp-messages__body' ) ) {
					$this->assertDoesNotMatchRegularExpression( '/white-space\s*:\s*pre-line/', $rule['body'], "{$file}: {$rule['selector']}" );
				}
			}
		}
	}

	/**
	 * What it checks: The built stylesheet gives a message's lists, tables, quotes, code and headings the plugin's own look inside the message
	 *   box, under the plugin's wrapper, from OmnaBase's tokens only, so a site's theme cannot strip list markers or squash a table.
	 * Passes when: assets/form.css has rules whose selector sits under `.ob-wp` and `.ob-wp-messages__body` for each of `ul`/`ol` (the list
	 *   markers), `li`, `table`, `td`/`th`, `pre`, `code`, `blockquote`, `p` and a heading; a link inside the message is styled by a rule under
	 *   `.ob-wp` (the wrapper's own link rule counts); every colour in the body's rules is a token (`var(--ob-…)`) or `inherit`/`currentColor`/
	 *   `transparent`.
	 * Fails when: a theme that removes list markers makes a numbered list unreadable, tables run off the box without borders, or colours are
	 *   typed by hand and break in dark mode.
	 */
	public function test_the_built_stylesheet_holds_the_bodys_rules(): void {
		$body = array_values( array_filter( $this->rules( 'assets/form.css' ), static fn ( array $r ) => str_contains( $r['selector'], '.ob-wp' ) && str_contains( $r['selector'], 'ob-wp-messages__body' ) ) );
		$wants = array(
			'lists'    => '/\b(ul|ol)\b/',
			'items'    => '/\bli\b/',
			'tables'   => '/\btable\b/',
			'cells'    => '/\b(td|th)\b/',
			'pre'      => '/\bpre\b/',
			'code'     => '/\bcode\b/',
			'quotes'   => '/\bblockquote\b/',
			'p'        => '/\bp\b/',
			'headings' => '/\bh[1-6]\b/',
		);
		foreach ( $wants as $what => $pattern ) {
			$hits = array_filter( $body, static fn ( array $r ) => (bool) preg_match( $pattern, (string) preg_replace( '/\.ob-wp[a-z_-]*/', '', $r['selector'] ) ) );
			$this->assertNotSame( array(), $hits, "a rule for the message's {$what}" );
		}
		$lists = array_filter( $body, static fn ( array $r ) => (bool) preg_match( '/\b(ul|ol)\b/', (string) preg_replace( '/\.ob-wp[a-z_-]*/', '', $r['selector'] ) ) && (bool) preg_match( '/list-style/', $r['body'] ) );
		$this->assertNotSame( array(), $lists, 'the list markers are set back' );
		$links = array_filter( $this->rules( 'assets/form.css' ), static fn ( array $r ) => (bool) preg_match( '/(^|,)\s*\.ob-wp(\s+\.ob-wp-messages__body)?\s+a\s*(,|$)/', $r['selector'] ) );
		$this->assertNotSame( array(), $links, 'links inside the message are styled under the wrapper' );
		foreach ( $body as $rule ) {
			preg_match_all( '/(?:^|;)\s*(color|background(?:-color)?|border(?:-[a-z]+)*-color|border(?:-left|-top|-right|-bottom)?)\s*:\s*([^;]+)/', $rule['body'], $decls, PREG_SET_ORDER );
			foreach ( $decls as $d ) {
				$this->assertDoesNotMatchRegularExpression( '/#[0-9a-f]{3,8}\b|\brgba?\(|\bhsla?\(/i', $d[2], "{$rule['selector']} {$d[1]}: a token, not a written colour" );
			}
		}
	}

	/**
	 * What it checks: The customer release notes of 0.2.0 tell site owners that the message centre now shows messages in
	 *   their shape and that the forum reply is cleaned more tightly.
	 * Passes when: the 0.2.0 section (unreleased until 2026-10-29, dated since) has a line naming the message centre with its paragraphs, lists, bold words,
	 *   tables or formatting (the shape), and a line naming the forum with pictures or styles (the tighter cleaning).
	 * Fails when: owners update and do not learn why their customers' messages look different, or why a forum answer lost its picture.
	 */
	public function test_the_unreleased_notes_name_both_changes(): void {
		$changelog = $this->read( 'changelog.txt' );
		// Adjusted, not weakened (the 0.2.0 release, 2026-10-29): 0.2.0 was released and dated that day, so the heading now carries its date; the
		// section's lines are checked exactly as before.
		$this->assertMatchesRegularExpression( '/^= 0\.2\.0 — (unreleased|\d{4}-\d{2}-\d{2}) =$/m', $changelog, 'the 0.2.0 section exists' );
		$section = (string) preg_replace( '/^.*?^= 0\.2\.0 — (?:unreleased|\d{4}-\d{2}-\d{2}) =$(.*?)(?:^= |\z).*$/ms', '$1', $changelog );
		$lines   = array_filter( explode( "\n", $section ), static fn ( string $l ) => str_starts_with( $l, '* ' ) );
		$this->assertNotSame( array(), array_filter( $lines, static fn ( string $l ) => (bool) preg_match( '/message centre/i', $l ) && (bool) preg_match( '/\b(lists?|paragraphs?|bold|tables?|formatting)\b/i', $l ) ), 'a line about the shaped messages' );
		$this->assertNotSame( array(), array_filter( $lines, static fn ( string $l ) => (bool) preg_match( '/forum/i', $l ) && (bool) preg_match( '/pictures?|images?|styles?/i', $l ) ), 'a line about the forum reply\'s cleaning' );
	}

	/* ---------- helpers ---------- */

	private function read( string $file ): string {
		$path = OMNABASE_REPO . '/' . $file;
		return is_file( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * A stylesheet's rules (comments and media queries' own lines left out), each as its selector and its declarations.
	 *
	 * @return array<int,array{selector:string,body:string}>
	 */
	private function rules( string $file ): array {
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $this->read( $file ) );
		preg_match_all( '/([^{}]+)\{([^{}]*)\}/', (string) preg_replace( '/@media[^{]*\{/', '', $css ), $m, PREG_SET_ORDER );
		return array_map( static fn ( array $r ) => array( 'selector' => trim( (string) preg_replace( '/\s+/', ' ', $r[1] ) ), 'body' => $r[2] ), $m );
	}

	/**
	 * The files under a folder with an extension, relative to the repository.
	 *
	 * @return string[]
	 */
	private function filesUnder( string $dir, string $ext ): array {
		$root = OMNABASE_REPO . '/' . $dir;
		$out  = array();
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) ) as $f ) {
			if ( $ext === $f->getExtension() ) {
				$out[] = $dir . '/' . substr( $f->getPathname(), strlen( $root ) + 1 );
			}
		}
		sort( $out );
		return $out;
	}
}
