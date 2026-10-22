<?php
/**
 * The front-end design round (2026-10-22), the red team's integration helpers (inside wp-env's tests WordPress, the plain run): the settings
 * screen's new Appearance tab read and sent the way an administrator's browser does it (each choice found by the words of its label, every
 * field of the tab's one form posted to `admin-post.php`), and the two shortcodes rendered for a visitor and a signed-in customer afterwards.
 *
 * Brief (OmnaBase `docs/briefs/plugin-frontend-design.md`) rule 2: "The Appearance tab (the sixth tab, "Appearance", after "Forms and
 * messages" …): Theme (Light, Dark, Follow the visitor's system; default Light), Accent (OmnaBase's blue, or a colour the owner picks; the plugin
 * writes it as `--ob-accent-user` and computes the text-on-accent as the app does), Font (Inherit the theme's font, default; or Load OmnaBase's
 * font …), and the moved switch "Theme styles only" (the plugin's stylesheet off entirely). One Save; the options in one `Options::APPEARANCE`
 * entry; the shortcodes' wrapper carries `data-theme` and the accent as the app does."
 *
 * The words: *tab*, one of the named panels of Settings › OmnaBase, its slug in the address (`&tab=appearance`); *radio button*, one of a group
 * of round choices of which exactly one is picked; *admin-post*, WordPress's address that hands a posted admin form to the plugin's handler;
 * *nonce*, a short-lived form token against forged requests; *wrapper*, the outermost element the shortcode prints (class `ob-wp`), where the
 * look's variables live; *enqueue*, WordPress's list of stylesheets a page will load; *as the app does*, OmnaBase's own web app: no mark at all
 * for its own blue, else `data-accent="custom"` with `--ob-accent-user` and `--ob-on-accent-user` (white below a brightness of one half, else
 * #1a1a1a: `apps/web/src/lib/accent.ts`).
 *
 * Shapes the red team pins here (chosen where the brief names none; the builder may rename and tell the red team; every name sits here): the
 * tab's slug `appearance`, its card's class `ob-wp-settings__appearance`, its save action `omnabase_save_appearance`; the choices by their
 * label words (the brief's: "Light", "Dark", "Follow the visitor's system", "Inherit the theme's font", "Load OmnaBase's font", "Theme styles
 * only"; the accent's two: one naming OmnaBase's blue, one naming the owner's own colour, with a colour field `input[type=color]` in the same
 * form); the row labels "Theme", "Accent", "Font"; field names are read from the form, never named here.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

abstract class AppearanceTestCase extends RoundSixTestCase {

	protected const TAB     = 'appearance';
	protected const SAVE    = 'omnabase_save_appearance';
	protected const SECTION = 'ob-wp-settings__appearance';

	/** The three theme choices by their words (an apostrophe may be straight or curly). */
	protected const THEMES = array(
		'light'  => '/^\s*Light\s*$/i',
		'dark'   => '/^\s*Dark\s*$/i',
		'system' => '/^\s*Follow the visitor[\'’]s system\s*$/i',
	);
	protected const ACCENT_BLUE  = '/OmnaBase[\'’]?s? blue/i';
	protected const ACCENT_OWN   = '/\b(own|your|pick)\b.*colou?r|colou?r.*\b(own|your|pick)\b/i';
	protected const FONT_INHERIT = '/Inherit the theme[\'’]s font/i';
	protected const FONT_LOAD    = '/Load OmnaBase[\'’]s font/i';
	protected const THEME_ONLY   = '/theme([\'’]s)? styles only/i';

	/** The owner's colour used across the tests (the app's Teal preset), its text (white), and a light one whose text is #1a1a1a. */
	protected const TEAL       = '#0f7c8a';
	protected const PALE       = '#f5e663';
	protected const ON_DARK    = '#ffffff';
	protected const ON_LIGHT   = '#1a1a1a';

	/** The Appearance tab as the administrator sees it. */
	protected function appearanceTab(): string {
		return $this->screen( self::TAB );
	}

	/**
	 * The tab's save form (the one whose admin-post action is the Appearance save), with its node and every field it would send; or null.
	 *
	 * @return array{action:string,fields:array,node:\DOMElement}|null
	 */
	protected function appearanceForm( string $html = '' ): ?array {
		foreach ( self::formsIn( '' === $html ? $this->appearanceTab() : $html ) as $form ) {
			if ( self::SAVE === $form['action'] ) {
				return $form;
			}
		}
		return null;
	}

	/**
	 * The input a label's words name inside a scope: a `<label>` wrapping it, or one whose `for` names its id.
	 *
	 * @param \DOMElement $scope Where to look.
	 * @param string      $words A pattern for the label's words.
	 * @param string      $type  The input's type (radio, checkbox).
	 */
	protected static function controlFor( \DOMElement $scope, string $words, string $type ): ?\DOMElement {
		$xpath = new \DOMXPath( $scope->ownerDocument );
		foreach ( $xpath->query( './/label', $scope ) as $label ) {
			if ( ! preg_match( $words, self::wordsOf( $label ) ) ) {
				continue;
			}
			$inside = $xpath->query( ".//input[@type='{$type}']", $label )->item( 0 );
			if ( $inside instanceof \DOMElement ) {
				return $inside;
			}
			$for = (string) $label->getAttribute( 'for' );
			if ( '' !== $for ) {
				$named = $xpath->query( "//input[@type='{$type}'][@id='{$for}']" )->item( 0 );
				if ( $named instanceof \DOMElement ) {
					return $named;
				}
			}
		}
		return null;
	}

	/** Whether the input a label names is picked (ticked). */
	protected static function picked( \DOMElement $scope, string $words, string $type = 'radio' ): ?bool {
		$input = self::controlFor( $scope, $words, $type );
		return null === $input ? null : $input->hasAttribute( 'checked' );
	}

	/**
	 * Saves the Appearance tab as the administrator's browser would after making these choices; where the save redirected (or null).
	 *
	 * @param array{theme?:string,accent?:string,font?:string,themeOnly?:bool} $choice `theme` light|dark|system, `accent` 'blue' or a colour
	 *                                                                             (any text: the browser's colour field sends what it holds),
	 *                                                                             `font` inherit|load, `themeOnly`.
	 * @param bool                                                              $nonce  Whether the form's nonce goes with it.
	 */
	protected function saveAppearance( array $choice, bool $nonce = true ): ?string {
		$form = $this->appearanceForm();
		$this->assertNotNull( $form, 'the Appearance tab has its save form' );
		$fields = $form['fields'];
		$node   = $form['node'];
		if ( isset( $choice['theme'] ) ) {
			$this->choose( $fields, $node, self::THEMES[ $choice['theme'] ], 'radio' );
		}
		if ( isset( $choice['accent'] ) ) {
			if ( 'blue' === $choice['accent'] ) {
				$this->choose( $fields, $node, self::ACCENT_BLUE, 'radio' );
			} else {
				$this->choose( $fields, $node, self::ACCENT_OWN, 'radio' );
				$colour = ( new \DOMXPath( $node->ownerDocument ) )->query( ".//input[@type='color']", $node )->item( 0 );
				$this->assertInstanceOf( \DOMElement::class, $colour, 'a colour field for the owner\'s own colour' );
				self::setField( $fields, (string) $colour->getAttribute( 'name' ), $choice['accent'] );
			}
		}
		if ( isset( $choice['font'] ) ) {
			$this->choose( $fields, $node, 'load' === $choice['font'] ? self::FONT_LOAD : self::FONT_INHERIT, 'radio' );
		}
		if ( isset( $choice['themeOnly'] ) ) {
			$box = self::controlFor( $node, self::THEME_ONLY, 'checkbox' );
			$this->assertInstanceOf( \DOMElement::class, $box, 'the "Theme styles only" switch inside the Appearance form' );
			if ( $choice['themeOnly'] ) {
				self::setField( $fields, (string) $box->getAttribute( 'name' ), $box->hasAttribute( 'value' ) ? (string) $box->getAttribute( 'value' ) : 'on' );
			} else {
				self::unsetField( $fields, (string) $box->getAttribute( 'name' ) );
			}
		}
		return $this->postAdmin( $fields, $nonce );
	}

	/** Picks the radio (or ticks the box) a label names, in the fields a browser would send. */
	protected function choose( array &$fields, \DOMElement $form, string $words, string $type ): void {
		$input = self::controlFor( $form, $words, $type );
		$this->assertInstanceOf( \DOMElement::class, $input, "a {$type} labelled {$words} in the Appearance form" );
		self::setField( $fields, (string) $input->getAttribute( 'name' ), $input->hasAttribute( 'value' ) ? (string) $input->getAttribute( 'value' ) : 'on' );
	}

	/** Puts a field where PHP would (`a[b]` nested), replacing what was there. */
	protected static function setField( array &$fields, string $name, string $value ): void {
		parse_str( $name . '=' . rawurlencode( $value ), $one );
		$fields = array_replace_recursive( $fields, $one );
	}

	/** Takes a field out (an unticked box sends nothing). */
	protected static function unsetField( array &$fields, string $name ): void {
		preg_match_all( '/[^\[\]]+/', $name, $m );
		$keys = $m[0];
		$last = array_pop( $keys );
		$here = &$fields;
		foreach ( $keys as $k ) {
			if ( ! isset( $here[ $k ] ) || ! is_array( $here[ $k ] ) ) {
				return;
			}
			$here = &$here[ $k ];
		}
		unset( $here[ $last ] );
	}

	/** The outermost element of a shortcode's output that carries the wrapper class `ob-wp`, or null. */
	protected static function wrapperOf( string $html ): ?\DOMElement {
		$node = self::nodes( $html, "//*[contains(concat(' ', normalize-space(@class), ' '), ' ob-wp ')]" )->item( 0 );
		return $node instanceof \DOMElement ? $node : null;
	}

	/** The contact form as a visitor gets it, on a fresh page load (a fresh stylesheet queue). */
	protected function formAsVisitor( string $atts = '' ): string {
		self::freshQueue();
		$this->asVisitor();
		return $this->render( $atts );
	}

	/** The message centre as a signed-in customer OmnaBase knows (confirmed or not), on a fresh page load. */
	protected function centreAs( int $user, array $query = array(), bool $confirmed = true ): string {
		self::freshQueue();
		if ( ! isset( $this->visitors[ self::siteIdOf( $user ) ] ) ) {
			$this->omnabaseKnows( $user, $confirmed );
		}
		return $this->view( $user, $query );
	}

	/**
	 * The addresses of the stylesheets queued from the plugin's own folder (`…/omnabase/assets/…`), as the page would print them.
	 *
	 * @return string[]
	 */
	protected function pluginStyles(): array {
		$styles = wp_styles();
		$out    = array();
		foreach ( (array) $styles->queue as $handle ) {
			$src = (string) ( $styles->registered[ $handle ]->src ?? '' );
			if ( str_contains( $src, '/omnabase/assets/' ) ) {
				$out[ $handle ] = $src;
			}
		}
		preg_match_all( '#href=[\'"]([^\'"]*/omnabase/assets/[^\'"]+\.css[^\'"]*)#', $this->pageAssets(), $printed );
		foreach ( $printed[1] as $src ) {
			if ( ! in_array( $src, $out, true ) ) {
				$out[] = $src;
			}
		}
		return array_values( $out );
	}

	/** The plugin's stylesheets that declare a font (their file holds `@font-face`), by address. */
	protected function fontStyles(): array {
		$root = dirname( __DIR__, 2 );
		return array_values(
			array_filter(
				$this->pluginStyles(),
				static function ( string $src ) use ( $root ): bool {
					$path = $root . '/assets/' . preg_replace( '#^.*/omnabase/assets/#', '', (string) strtok( $src, '?' ) );
					return is_readable( $path ) && (bool) preg_match( '/@font-face/i', (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				}
			)
		);
	}

	/** The style attribute's custom properties, name => value (`--ob-accent-user: #0f7c8a` …). */
	protected static function inlineVariables( \DOMElement $el ): array {
		preg_match_all( '/(--[a-z0-9-]+)\s*:\s*([^;]+)/i', (string) $el->getAttribute( 'style' ), $m, PREG_SET_ORDER );
		$out = array();
		foreach ( $m as $d ) {
			$out[ strtolower( $d[1] ) ] = strtolower( trim( $d[2] ) );
		}
		return $out;
	}
}
