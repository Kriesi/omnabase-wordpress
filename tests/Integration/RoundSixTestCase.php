<?php
/**
 * M14 ROUND ONE, THE RED TEAM: ADJUSTED, NOT WEAKENED. What changed and why: the screen renders only the open tab (brief
 * `docs/briefs/m14-settings-cleanup.md`, choice 1; review S1). `screen()` takes the tab to open (`connection` by default, as the bare address
 * opens), and `flip()` reads its switch from the "Forms and messages" tab (`pages`), where both of round six's switches (theme styles only,
 * My Account) now live inside the Shortcodes block of that tab's one save form. `flip()` still posts every field of the switch's form, so the
 * merged form's page pick travels with it unchanged. `postAdmin()` now nests bracketed field names the way PHP reads a browser's post
 * (`omnabase_portal[page]` arrives as `$_POST['omnabase_portal']['page']`): before M14 no switch's form held such a field, and without it
 * the merged form would post the page pick under a name no handler reads. Nothing else changed.
 *
 * M13 round six, the red team's integration helpers (inside wp-env's tests WordPress): round five's connected site with its message centre,
 * plus the settings screen read and sent the way an administrator's browser does it: a section found by its class, a checkbox found by the
 * words of its label, a form found by its button, every field the form holds posted to `admin-post.php` (so hidden fields, action names and
 * nonces need no names here).
 *
 * The words: *settings screen*, Settings › OmnaBase in wp-admin; *nonce*, a short-lived form token against forged requests; *admin-post*,
 * WordPress's address that hands a posted admin form to the plugin's handler; *enqueue*, WordPress's list of stylesheets a page will load;
 * *outbox*, the plugin's lasting list of calls still to make.
 *
 * Shapes the red team pins here (chosen where the brief names none; the builder may rename and tell the red team; every name sits here):
 * - The screen's sections by class: `.ob-wp-settings__shortcodes` (round six's own section, with an h2 "Shortcodes"), `.ob-wp-settings__outbox`,
 *   `.ob-wp-settings__health` (rounds one and two).
 * - A switch is a checkbox whose label (a `<label for>` or a wrapping `<label>`) holds its words: "theme styles only", "My Account".
 * - "Send now" is a button (or submit input) with those words inside the outbox section.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

abstract class RoundSixTestCase extends RoundFiveTestCase {

	public function set_up(): void {
		parent::set_up();
		// the connection view asks OmnaBase who the key is; round five's catch-all answers come after this one
		$this->answers = array( 'GET /api/public/v1/me' => self::answer( 200, self::meAnswer() ) ) + $this->answers;
	}

	/**
	 * The settings screen as the administrator sees it, on one tab (M14: only the open tab is rendered).
	 *
	 * @param string $tab The tab's slug; `connection` is what the bare address opens.
	 */
	protected function screen( string $tab = 'connection' ): string {
		$this->asAdminOnTheScreen( $this->admin );
		return $this->openTab( $tab );
	}

	/** The HTML of the first element whose class holds the name, or ''. */
	protected static function section( string $html, string $class ): string {
		$nodes = self::nodes( $html, "//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]" );
		if ( 0 === $nodes->length ) {
			return '';
		}
		return (string) $nodes->item( 0 )->ownerDocument->saveHTML( $nodes->item( 0 ) );
	}

	/**
	 * The first checkbox whose label matches, with its form: the checkbox's name and value, and every other field its form would send.
	 *
	 * @param string $html  The screen.
	 * @param string $label A regular expression for the label's words.
	 * @return array{name:string,value:string,checked:bool,fields:array<string,string>}|null
	 */
	protected static function checkbox( string $html, string $label ): ?array {
		$boxes = self::nodes( $html, "//input[@type='checkbox']" );
		foreach ( $boxes as $box ) {
			$xpath = new \DOMXPath( $box->ownerDocument );
			$id    = (string) $box->getAttribute( 'id' );
			$words = '';
			foreach ( $xpath->query( 'ancestor::label', $box ) as $l ) {
				$words .= ' ' . $l->textContent;
			}
			if ( '' !== $id ) {
				foreach ( $xpath->query( "//label[@for='{$id}']" ) as $l ) {
					$words .= ' ' . $l->textContent;
				}
			}
			if ( ! preg_match( $label, (string) preg_replace( '/\s+/', ' ', $words ) ) ) {
				continue;
			}
			$form = $xpath->query( 'ancestor::form', $box )->item( 0 );
			if ( ! $form instanceof \DOMElement ) {
				return null;
			}
			$fields = self::fieldsOf( $form );
			$name   = (string) $box->getAttribute( 'name' );
			unset( $fields[ $name ] );
			return array( 'name' => $name, 'value' => $box->hasAttribute( 'value' ) ? (string) $box->getAttribute( 'value' ) : 'on', 'checked' => $box->hasAttribute( 'checked' ), 'fields' => $fields );
		}
		return null;
	}

	/**
	 * Turns a switch on or off through its form, as the administrator does (the form's own fields, the box ticked or not).
	 *
	 * @param string $label A regular expression for the switch's label.
	 * @param bool   $on    Ticked or not.
	 * @param bool   $nonce Whether the form's nonce is sent.
	 * @param string $tab   The tab the switch lives on (M14: round six's switches sit on Forms and messages).
	 */
	protected function flip( string $label, bool $on, bool $nonce = true, string $tab = 'pages' ): void {
		$box = self::checkbox( $this->screen( $tab ), $label );
		$this->assertNotNull( $box, 'the settings screen offers the switch ' . $label . ' inside a form' );
		$fields = $box['fields'];
		if ( $on ) {
			$fields[ $box['name'] ] = $box['value'];
		}
		$this->postAdmin( $fields, $nonce );
	}

	/**
	 * Every field a form would send (unticked boxes left out, as a browser does).
	 *
	 * @param \DOMElement $form The form.
	 * @return array<string,string>
	 */
	protected static function fieldsOf( \DOMElement $form ): array {
		$fields = array();
		foreach ( ( new \DOMXPath( $form->ownerDocument ) )->query( './/input | .//textarea | .//select', $form ) as $el ) {
			$name = (string) $el->getAttribute( 'name' );
			$type = strtolower( (string) $el->getAttribute( 'type' ) );
			if ( '' === $name || in_array( $type, array( 'submit', 'button' ), true ) || ( in_array( $type, array( 'checkbox', 'radio' ), true ) && ! $el->hasAttribute( 'checked' ) ) ) {
				continue;
			}
			if ( 'select' === $el->nodeName ) {
				$picked = ( new \DOMXPath( $form->ownerDocument ) )->query( './/option[@selected]', $el )->item( 0 );
				$fields[ $name ] = $picked instanceof \DOMElement ? (string) $picked->getAttribute( 'value' ) : '';
				continue;
			}
			$fields[ $name ] = 'textarea' === $el->nodeName ? (string) $el->textContent : (string) $el->getAttribute( 'value' );
		}
		return $fields;
	}

	/**
	 * The form holding a button with these words, inside a section (or the whole screen), with every field it sends.
	 *
	 * @param string $html  The screen or a section.
	 * @param string $words The button's words, e.g. "Send now".
	 * @return array<string,string>|null
	 */
	protected static function formWithButton( string $html, string $words ): ?array {
		$lower = strtolower( $words );
		$cond  = "contains(translate(normalize-space(.),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'{$lower}')";
		$forms = self::nodes( $html, "//form[.//button[{$cond}] or .//input[@type='submit'][" . str_replace( 'normalize-space(.)', 'normalize-space(@value)', $cond ) . ']]' );
		return 0 === $forms->length ? null : self::fieldsOf( $forms->item( 0 ) );
	}

	/**
	 * Posts an admin form's fields to its admin-post handler as the administrator (the handler named by the form's `action` field).
	 *
	 * @param array<string,string> $fields The fields.
	 * @param bool                 $nonce  Whether the nonce goes with them.
	 * @return string|null Where the handler redirected, or null.
	 */
	protected function postAdmin( array $fields, bool $nonce = true ): ?string {
		$this->asAdminOnTheScreen( $this->admin );
		if ( ! $nonce ) {
			$fields = array_filter( $fields, static fn ( $k ) => ! preg_match( '/nonce/i', (string) $k ), ARRAY_FILTER_USE_KEY );
		}
		parse_str( http_build_query( $fields ), $fields ); // M14: as PHP reads a browser's post (`omnabase_portal[page]` becomes a nested field)
		$action = (string) ( $fields['action'] ?? '' );
		$this->assertNotSame( '', $action, 'the form names its admin-post action' );
		$_POST    = $fields;
		$_REQUEST = $fields;
		try {
			do_action( 'admin_post_' . $action );
		} catch ( Redirected $r ) {
			return $r->location;
		} catch ( \WPDieException $e ) {
			return null;
		} finally {
			$_POST    = array();
			$_REQUEST = array();
		}
		return null;
	}

	/** The words of every element under a section that has no element child of its own (each line once). */
	protected static function lines( string $html ): array {
		$out = array();
		foreach ( self::nodes( $html, '//*[not(*)] | //tr | //li | //p' ) as $n ) {
			$t = trim( (string) preg_replace( '/\s+/', ' ', (string) $n->textContent ) );
			if ( '' !== $t ) {
				$out[] = $t;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Whether the form stylesheet is queued for the page, and printed with it. */
	protected function formCssQueued(): bool {
		return wp_style_is( 'omnabase-form', 'enqueued' ) || str_contains( $this->pageAssets(), 'assets/form.css' );
	}

	/** A fresh stylesheet and script queue (as a new page load has). */
	protected static function freshQueue(): void {
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$GLOBALS['wp_styles']  = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	}
}
