<?php
/**
 * The plugin's forms round (2026-10-25), the red team's failing structure tests (file-level guards, no WordPress, seconds).
 *
 * Brief (OmnaBase `docs/briefs/plugin-forms-round.md`): "'Classic' is gone"; "The old routes: the plugin's time-token and form routes go, with
 * their tests; `Forms.php` keeps only the shortcode and the block"; "the Development override points at the local app, as the connect does";
 * "The Appearance tab's look applies: light, dark or following the device, the colour and the font, passed to the embed as CSS variables";
 * "Version 0.2.0 stays (unreleased), the plugin's changelog gets the lines". Report findings 8, 9 and 12 with the pinned defaults: the look
 * reaches the embed through the plugin's own stylesheet (the embed's variables set from OmnaBase's tokens under the wrapper, so the dark blocks
 * and the visitor's device setting apply), never through fixed colours on the container.
 *
 * The words: *embed*, OmnaBase's script `forms.js` that draws a form inside a container on any page; *the embed's variables*, the named colours
 * and sizes the embed reads (`--omnabase-form-accent`, `-accent-text`, `-border`, `-background`, `-field`, `-text`, `-muted`, `-error`,
 * `-radius`); *token*, one of OmnaBase's named design values (`--ob-…`) whose light and dark values the build copies in; *REST route*, an address
 * under /wp-json/ a program calls; *transient*, a value WordPress keeps for a while and then forgets.
 *
 * Why these fail today: the Classic form's classes, routes and script are all there, the stylesheet sets none of the embed's variables, and
 * the 0.2.0 notes still promise the Classic form's "Send another" card.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Structure;

use PHPUnit\Framework\TestCase;

final class FormEmbedStructureTest extends TestCase {

	/** The Classic form's own parts (round four), which the embed replaces. */
	private const CLASSIC_FILES = array(
		'src/Forms/Token.php',
		'src/Forms/Guard.php',
		'src/Forms/Intake.php',
		'src/Forms/Sender.php',
		'src/Forms/Answer.php',
		'src/Forms/FormJob.php',
		'src/Forms/Fields.php',
		'src/Forms/Settings.php',
		'src/Rest/Form.php',
		'assets/form.js',
	);

	/** The embed's variables the look must set (the embed falls back to a neutral light look for each one left out). */
	private const EMBED_VARIABLES = array( 'accent', 'accent-text', 'border', 'background', 'field', 'text', 'muted', 'error', 'radius' );

	/**
	 * What it checks: The Classic form's own parts are gone, so no second way of taking messages lingers in the plugin.
	 * Passes when: none of the Classic files (its time token, bot guard, intake, sender, answers, outbox job, field checks, settings reader, its
	 *   REST route class and its script) exists.
	 * Fails when: dead code for a retired OmnaBase route stays in every site's plugin, still reachable and still to be maintained.
	 */
	public function test_the_classic_forms_parts_are_gone(): void {
		$left = array_values( array_filter( self::CLASSIC_FILES, static fn ( string $f ) => file_exists( OMNABASE_REPO . '/' . $f ) ) );
		$this->assertSame( array(), $left, 'still there: ' . implode( ', ', $left ) );
	}

	/**
	 * What it checks: No code registers the Classic form's two addresses or mentions the time token any more.
	 * Passes when: nothing under src/, templates/ or assets/ registers a REST route named /form or /form-token, or holds "form-token".
	 * Fails when: a forgotten route keeps answering bots on every connected site.
	 */
	public function test_no_classic_route_is_registered(): void {
		$code = $this->codeUnder( array( 'src', 'templates', 'assets' ) );
		$this->assertDoesNotMatchRegularExpression( '#register_rest_route\s*\([^;]*[\'"]/form(-token)?[\'"]#s', $code );
		$this->assertStringNotContainsString( 'form-token', $code );
	}

	/**
	 * What it checks: The embed's script address is built from OmnaBase's app address (the one the visitor's browser can reach), never from the
	 *   address WordPress itself uses for OmnaBase's API.
	 * Passes when: the code under src/ that names forms.js takes its host from the app address (`ApiUrl::app()` or its development override)
	 *   and not from the API's (`ApiUrl::base()` or `ApiUrl::override()`).
	 * Fails when: on a development site the browser is sent to a server-side address it cannot reach (host.docker.internal), or production pages
	 *   load the script from a host that does not serve it.
	 */
	public function test_the_embeds_address_comes_from_the_app_address(): void {
		$files = array_values( array_filter( $this->filesUnder( 'src', 'php' ), fn ( string $f ) => str_contains( $this->read( $f ), 'forms.js' ) ) );
		$this->assertNotSame( array(), $files, 'some code under src/ names the embed\'s script' );
		foreach ( $files as $file ) {
			$code = $this->read( $file );
			$this->assertMatchesRegularExpression( '/ApiUrl::(app|appOverride)\s*\(/', $code, "{$file}: the app address" );
			$this->assertDoesNotMatchRegularExpression( '/ApiUrl::(base|override|publicApi)\s*\(/', $code, "{$file}: never the API's address" );
		}
	}

	/**
	 * What it checks: The Appearance tab's look reaches the embed through the plugin's stylesheet, from OmnaBase's tokens, so light, dark, the
	 *   visitor's device setting and the owner's colour all apply to forms built in OmnaBase.
	 * Passes when: the built front-end stylesheet sets each of the embed's variables (accent, accent-text, border, background, field, text,
	 *   muted, error, radius) in a rule under the plugin's wrapper (.ob-wp), each from one of OmnaBase's tokens (`var(--ob-…)`), never a written
	 *   colour.
	 * Fails when: the owner picks Dark and the form built in OmnaBase stays white, the owner's colour never reaches the Send button, or the
	 *   form's colours drift from the product's because someone typed them by hand.
	 */
	public function test_the_stylesheet_sets_the_embeds_variables_from_the_tokens(): void {
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $this->read( 'assets/form.css' ) );
		preg_match_all( '/([^{}]+)\{([^{}]*)\}/', (string) preg_replace( '/@media[^{]*\{/', '', $css ), $rules, PREG_SET_ORDER );
		$found = array();
		foreach ( $rules as $rule ) {
			$selectors = array_map( 'trim', explode( ',', $rule[1] ) );
			if ( array() === array_filter( $selectors, static fn ( string $s ) => str_starts_with( $s, '.ob-wp' ) ) ) {
				continue;
			}
			preg_match_all( '/--omnabase-form-([a-z-]+)\s*:\s*([^;]+);?/', $rule[2], $decls, PREG_SET_ORDER );
			foreach ( $decls as $d ) {
				$found[ $d[1] ][] = trim( $d[2] );
			}
		}
		foreach ( self::EMBED_VARIABLES as $name ) {
			$this->assertArrayHasKey( $name, $found, "--omnabase-form-{$name} is set under .ob-wp" );
			foreach ( $found[ $name ] as $value ) {
				$this->assertMatchesRegularExpression( '/^var\(\s*--ob-[a-z0-9-]+/', $value, "--omnabase-form-{$name}: from a token, not a written value" );
			}
		}
	}

	/**
	 * What it checks (guard): Whatever the form keeps for a while (the list of forms, the signed-in visitors' prefill notes) is kept under the prefix
	 *   that deleting the plugin sweeps away.
	 * Passes when: every place under src/Forms (and Forms.php) that keeps a temporary value names the form's prefix (`Options::FORM_TRANSIENT`),
	 *   and uninstall.php still sweeps that prefix.
	 * Fails when: a customer's prefill note or the cached list stays in the site's database after the plugin is deleted.
	 */
	public function test_what_the_form_keeps_is_swept_on_uninstall(): void {
		$files = array_merge( array( 'src/Forms.php' ), $this->filesUnder( 'src/Forms', 'php' ) );
		foreach ( $files as $file ) {
			if ( ! file_exists( OMNABASE_REPO . '/' . $file ) ) {
				continue;
			}
			preg_match_all( '/set_(?:site_)?transient\s*\(([^,]+),/', $this->read( $file ), $m );
			foreach ( $m[1] as $name ) {
				$this->assertMatchesRegularExpression( '/FORM_TRANSIENT|\$this->name|self::name|\$name|\$key/', $name, "{$file}: kept under the form's prefix" );
			}
		}
		$this->assertMatchesRegularExpression( '/omnabase_form_/', $this->read( 'uninstall.php' ) );
	}

	/**
	 * What it checks: The customer release notes of 0.2.0 (still unreleased) tell site owners that the contact form now comes from OmnaBase's
	 *   form builder, and no longer describe the retired form's success card.
	 * Passes when: the 0.2.0 section of changelog.txt is still marked unreleased, has a line naming forms built in OmnaBase, and holds no line
	 *   promising "Send another".
	 * Fails when: owners update without learning that their form is now built in OmnaBase, or the notes describe a card that no longer exists.
	 */
	public function test_the_unreleased_notes_describe_the_new_form(): void {
		$changelog = $this->read( 'changelog.txt' );
		$this->assertMatchesRegularExpression( '/^= 0\.2\.0 — unreleased =$/m', $changelog, '0.2.0 stays unreleased' );
		$section = (string) preg_replace( '/^.*?^= 0\.2\.0 — unreleased =$(.*?)(?:^= |\z).*$/ms', '$1', $changelog );
		$lines   = array_filter( explode( "\n", $section ), static fn ( string $l ) => str_starts_with( $l, '* ' ) );
		$this->assertNotSame( array(), array_filter( $lines, static fn ( string $l ) => (bool) preg_match( '/\bforms?\b/i', $l ) && (bool) preg_match( '/\bbuil(t|d|der)\b/i', $l ) && str_contains( $l, 'OmnaBase' ) ), 'a line about forms built in OmnaBase' );
		$this->assertSame( array(), array_values( array_filter( $lines, static fn ( string $l ) => str_contains( $l, 'Send another' ) ) ), 'no promise of the retired card' );
	}

	/* ---------- helpers ---------- */

	private function read( string $file ): string {
		return (string) file_get_contents( OMNABASE_REPO . '/' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * The files under a folder with an extension, relative to the repository.
	 *
	 * @return string[]
	 */
	private function filesUnder( string $dir, string $ext ): array {
		$root = OMNABASE_REPO . '/' . $dir;
		if ( ! is_dir( $root ) ) {
			return array();
		}
		$out = array();
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) ) as $f ) {
			if ( '' === $ext || $ext === $f->getExtension() ) {
				$out[] = $dir . '/' . substr( $f->getPathname(), strlen( $root ) + 1 );
			}
		}
		sort( $out );
		return $out;
	}

	/** Every file's text under some folders (PHP, JavaScript and CSS). */
	private function codeUnder( array $dirs ): string {
		$text = '';
		foreach ( $dirs as $dir ) {
			foreach ( $this->filesUnder( $dir, '' ) as $file ) {
				if ( preg_match( '/\.(php|js|css)$/', $file ) ) {
					$text .= "\n" . $this->read( $file );
				}
			}
		}
		return $text;
	}
}
