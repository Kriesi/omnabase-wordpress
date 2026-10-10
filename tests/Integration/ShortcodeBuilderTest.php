<?php
/**
 * M14 round two, the red team's failing tests: the shortcode builder on the "Forms and messages" tab of Settings › OmnaBase, and its
 * "Create the page for me" action (inside wp-env's tests WordPress, the plain run).
 *
 * Brief (OmnaBase `docs/briefs/m14-settings-cleanup.md`, draft 2) choice 2: "On the Pages tab, inside the Shortcodes block, one button 'Build a
 * shortcode'. The dialog (the browser's own `<dialog>`, a little vanilla JS in `assets/admin.js` loaded only on the plugin's screen …): What
 * (two cards …); Project (shown only when the site's key has more than one project …); for the form Subject starts with (… `"`, `[` and `]`
 * removed as typed); for the message centre Visitors who are not signed in … The tag is shown live in a read-only field … (`project` left out
 * when the key has one project …), with a Copy button … and the line 'Paste it into any page or post.' For the message centre the dialog adds
 * 'It works only on the page picked as the message centre page' with the picker's current choice. The primary action Create the page for me:
 * its own form with a nonce; needs `manage_options` and `publish_pages`; the page's content is built on the server from checked values …,
 * never from the tag text the browser sends; the page is titled 'Support' (message centre) or 'Contact support' (form), published, picked as
 * the message centre page when it is one, and the link to edit it shown; before making one, the dialog offers a published page the person may
 * edit that already carries the tag … Without JavaScript the block still lists the two tags and their attributes in the six lines of today
 * inside `<noscript>`." Review (`docs/reviews/m14-settings-cleanup-review.md`) S4 (the server-side rules, the cleaned subject, "only
 * published pages the person may edit"), S5 (`project` left out for one project; only a hand-made key can show the picker), N7 (the script
 * only on the plugin's screen), N9 ("Contact support", offer the existing page first). The founder's answer to question 1: published at once.
 *
 * The words: *shortcode*, a tag such as `[omnabase_form]` that WordPress replaces with the plugin's output; *dialog*, a small window over the
 * page that must be closed before the page is used again; *nonce*, a short-lived form token against forged requests; *capability*, a
 * permission WordPress gives a role (`manage_options`: may change settings; `publish_pages`: may publish pages; `edit_others_pages`: may edit
 * pages other people wrote); *role*, a named bundle of capabilities (Administrator, Editor, or one a plugin adds); *`<noscript>`*, a part of
 * the page a browser shows only when JavaScript is off; *enqueue*, WordPress's list of scripts a page will load; *project*, the OmnaBase
 * project a site's tickets go to (a key from Connect has exactly one; the test fakes several by writing the kept connection record).
 *
 * Shapes the red team pins here (chosen where the brief names none; the builder may rename any of them and tell the red team):
 * - The button: a `<button type="button">` reading "Build a shortcode" inside `.ob-wp-settings__shortcodes`, with `aria-controls` naming the
 *   dialog's id (type="button", because the block sits inside the tab's save form and a plain button would submit it).
 * - The dialog: one `<dialog class="ob-wp-builder">` outside the save form. Its choices belong to the Create form (action
 *   `omnabase_create_page`, nonce of the same name in `_wpnonce`, as every action of the screen): radios `omnabase_builder[kind]` (`form`,
 *   `messages`), a text field `omnabase_builder[subject]`, radios `omnabase_builder[guest]` (`''`, `form`), a select
 *   `omnabase_builder[project]` only for several projects; the tag in a `readonly` field; Copy a `<button type="button">`; the submit "Create
 *   the page for me" primary.
 * - An existing page is offered in an element `.ob-wp-builder__existing` with `data-kind="form|messages"` holding a link to the page (its edit
 *   address or its permalink).
 * - The flash after Create: the screen's notice holds the word "published", the page's title and a link to the page's edit address.
 * - The script: any queued script whose address ends in `assets/admin.js` (no handle pinned).
 *
 * Why these fail today: the Shortcodes block holds six read-only lines, no button, no dialog, no `assets/admin.js` and no Create action.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

final class ShortcodeBuilderTest extends RoundSixTestCase {

	private const CREATE = 'omnabase_create_page';
	private const SAVE   = 'omnabase_save_pages';
	private const FIELD  = 'omnabase_builder';
	private const DIALOG = 'ob-wp-builder';

	private const FORM_CARD     = "Contact form: visitors' messages become tickets";
	private const MESSAGES_CARD = 'Message centre: signed-in customers read and answer their tickets';
	private const PICKED_LINE   = 'It works only on the page picked as the message centre page';

	/** The six lines of today (round six's Shortcodes block), each by its code. */
	private const SIX_CODES = array( '[omnabase_form]', 'project="slug"', 'subject="…"', '[omnabase_messages]', 'guest="form"', 'project="slug"' );

	/** @var string[] roles this test added */
	private array $roles = array();

	public function tear_down(): void {
		foreach ( $this->roles as $role ) {
			remove_role( $role );
		}
		$this->roles = array();
		parent::tear_down();
	}

	/* ---------- helpers ---------- */

	/** A role with exactly these capabilities, and a user holding it. */
	private function userWith( string $role, array $caps ): int {
		add_role( $role, $role, array_fill_keys( $caps, true ) );
		$this->roles[] = $role;
		return (int) self::factory()->user->create( array( 'role' => $role ) );
	}

	/** The XPath of the builder's dialog. */
	private static function dialogPath(): string {
		return '//dialog[' . self::hasClass( self::DIALOG ) . ']';
	}

	/** The dialog's HTML on a tab ('' when there is none). */
	private static function dialogIn( string $html ): string {
		$nodes = self::query( $html, self::dialogPath() );
		return 0 === $nodes->length ? '' : self::outer( $nodes->item( 0 ) );
	}

	/** The Forms and messages tab as a user sees it. */
	private function pagesTabAs( int $user ): string {
		$this->asAdminOnTheScreen( $user );
		return $this->openTab( 'pages' );
	}

	/** The Create form of a tab, or null. */
	private static function createForm( string $html ): ?array {
		foreach ( self::formsIn( $html ) as $form ) {
			if ( self::CREATE === $form['action'] ) {
				return $form;
			}
		}
		return null;
	}

	/** The words of the label of the first input matching an XPath query (a wrapping label or a `<label for>`), curly quotes made straight. */
	private static function labelOf( string $html, string $xpath ): string {
		$doc   = self::htmlDocument( $html );
		$query = new \DOMXPath( $doc );
		$input = $query->query( $xpath )->item( 0 );
		if ( ! $input instanceof \DOMElement ) {
			return '';
		}
		$words = '';
		foreach ( $query->query( 'ancestor::label', $input ) as $label ) {
			$words .= ' ' . self::wordsOf( $label );
		}
		$id = (string) $input->getAttribute( 'id' );
		if ( '' !== $id ) {
			foreach ( $query->query( "//label[@for='{$id}']" ) as $label ) {
				$words .= ' ' . self::wordsOf( $label );
			}
		}
		return trim( str_replace( array( '’', '‘' ), "'", $words ) );
	}

	/** Every page id that exists (any status but the bin). */
	private static function pageIds(): array {
		return array_map( 'intval', get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) );
	}

	/**
	 * The admin's Create form with the builder's choices set, posted as the admin: where it redirected and the pages it made.
	 *
	 * @param array<string,string> $choices The builder's fields (`kind`, `project`, `subject`, `guest`) and anything else to send inside it.
	 * @param array<string,mixed>  $extra   Fields sent beside the form's own.
	 * @return array{0:?string,1:int[]}
	 */
	private function createAsAdmin( array $choices, array $extra = array() ): array {
		$form = self::createForm( $this->pagesTabAs( $this->admin ) );
		$this->assertNotNull( $form, 'the dialog holds the Create form' );
		$fields                = $form['fields'];
		$fields[ self::FIELD ] = array_merge( is_array( $fields[ self::FIELD ] ?? null ) ? $fields[ self::FIELD ] : array(), $choices );
		$before                = self::pageIds();
		$this->asAdminOnTheScreen( $this->admin );
		$location = $this->postFields( array_merge( $fields, $extra ) );
		return array( $location, array_values( array_diff( self::pageIds(), $before ) ) );
	}

	/**
	 * Posts fields made by hand as a user, the way a forged or a scripted post would send them (the token, when sent, is made for that user
	 * after switching to them); answers the pages made.
	 *
	 * @param int                  $user    Who posts.
	 * @param array<string,string> $choices The builder's fields.
	 * @param string|null          $nonce   The action the token is made for (the Create action by default), or null for no token.
	 */
	private function postAs( int $user, array $choices, ?string $nonce = self::CREATE ): array {
		$this->asAdminOnTheScreen( $user );
		$fields = array( 'action' => self::CREATE ) + ( null === $nonce ? array() : array( '_wpnonce' => wp_create_nonce( $nonce ) ) ) + array( self::FIELD => $choices );
		$before = self::pageIds();
		$this->postFields( $fields );
		return array_values( array_diff( self::pageIds(), $before ) );
	}

	/** The OmnaBase tags in a page's content. */
	private static function tagsIn( int $page ): array {
		preg_match_all( '/\[omnabase_(?:form|messages)\b[^\]]*\]/', (string) get_post_field( 'post_content', $page ), $m );
		return $m[0];
	}

	/** The one page a Create made, with its one tag; asserts both are single. */
	private function theOnlyTag( array $made ): string {
		$this->assertCount( 1, $made, 'one page made' );
		$tags = self::tagsIn( $made[0] );
		$this->assertCount( 1, $tags, 'the page carries one OmnaBase tag' );
		return $tags[0];
	}

	/** Whether some HTML links to a page: its edit address (`post=<id>`) or its permalink. */
	private static function linksTo( string $html, int $page ): bool {
		foreach ( self::query( $html, '//a[@href]' ) as $a ) {
			$href = html_entity_decode( (string) $a->getAttribute( 'href' ) );
			if ( preg_match( '/[?&]post=' . $page . '(&|$)/', $href ) || untrailingslashit( $href ) === untrailingslashit( (string) get_permalink( $page ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** The offer of existing pages for a kind inside the dialog ('' when none). */
	private static function offerFor( string $dialog, string $kind ): string {
		$out = '';
		foreach ( self::query( $dialog, '//*[' . self::hasClass( 'ob-wp-builder__existing' ) . "][@data-kind='{$kind}']" ) as $node ) {
			$out .= self::outer( $node );
		}
		return $out;
	}

	/** Whether a script from `assets/admin.js` is queued. */
	private static function adminScriptQueued(): bool {
		$scripts = wp_scripts();
		foreach ( $scripts->queue as $handle ) {
			if ( str_ends_with( (string) wp_parse_url( (string) ( $scripts->registered[ $handle ]->src ?? '' ), PHP_URL_PATH ), 'assets/admin.js' ) ) {
				return true;
			}
		}
		return false;
	}

	/** Two projects in the kept connection record, as only a hand-made key can give. */
	private function twoProjects(): void {
		$this->connectedWith(
			array(
				array( 'id' => self::PROJECT, 'slug' => 'enfold', 'name' => 'Enfold' ),
				array( 'id' => 'project-omnalingo', 'slug' => 'omnalingo', 'name' => 'Omnalingo' ),
			)
		);
	}

	/* ---------- the button and the dialog ---------- */

	/**
	 * What it checks: The Shortcodes block has a "Build a shortcode" button that opens the builder and never saves the tab by accident.
	 * Passes when: inside the Shortcodes block there is one button reading "Build a shortcode", of the kind that does not send a form, pointing
	 *   at the builder's dialog by its id.
	 * Fails when: there is no way into the builder, or clicking the button submits the tab's settings form (the page reloads, a half-made choice
	 *   is saved and no dialog opens).
	 */
	public function test_the_shortcodes_block_has_a_build_a_shortcode_button_that_never_submits_the_save(): void {
		$html  = $this->pagesTabAs( $this->admin );
		$block = self::section( $html, 'ob-wp-settings__shortcodes' );
		$this->assertNotSame( '', $block, 'the Shortcodes block' );
		$buttons = self::query( $block, "//button[normalize-space(.)='Build a shortcode']" );
		$this->assertSame( 1, $buttons->length, 'one "Build a shortcode" button in the block' );
		$button = $buttons->item( 0 );
		$this->assertSame( 'button', strtolower( (string) $button->getAttribute( 'type' ) ), 'a button that sends no form' );
		$dialog = self::query( $html, self::dialogPath() );
		$this->assertSame( 1, $dialog->length, 'one builder dialog on the tab' );
		$this->assertNotSame( '', (string) $dialog->item( 0 )->getAttribute( 'id' ), 'the dialog has an id' );
		$this->assertSame( (string) $dialog->item( 0 )->getAttribute( 'id' ), (string) $button->getAttribute( 'aria-controls' ), 'the button names the dialog it opens' );
	}

	/**
	 * What it checks: The dialog holds every choice the brief names, the live tag, Copy, the paste line, the message centre's page and the
	 *   "Create the page for me" button.
	 * Passes when: the dialog offers the two cards "Contact form: visitors' messages become tickets" and "Message centre: signed-in customers read
	 *   and answer their tickets", a "Subject starts with" text field, "Visitors who are not signed in" with "see a sign-in link" and "see the
	 *   contact form instead", a read-only field already holding an OmnaBase tag, a Copy button, "Paste it into any page or post.", the line
	 *   "It works only on the page picked as the message centre page" with the picked page's title, and a primary "Create the page for me"; with
	 *   the site's one project there is no project choice.
	 * Fails when: an owner has to guess what a choice does, cannot see or copy the tag, puts the message centre on the wrong page, or is asked to
	 *   pick a project when there is only one.
	 */
	public function test_the_dialog_holds_the_choices_the_tag_copy_and_create(): void {
		$html   = $this->pagesTabAs( $this->admin );
		$dialog = self::dialogIn( $html );
		$this->assertNotSame( '', $dialog, 'the builder dialog' );
		$kind = self::FIELD . '[kind]';
		$this->assertStringContainsString( self::FORM_CARD, self::labelOf( $dialog, "//input[@type='radio'][@name='{$kind}'][@value='form']" ), 'the contact form card' );
		$this->assertStringContainsString( self::MESSAGES_CARD, self::labelOf( $dialog, "//input[@type='radio'][@name='{$kind}'][@value='messages']" ), 'the message centre card' );
		$this->assertMatchesRegularExpression( '/Subject starts with/i', self::labelOf( $dialog, "//input[@name='" . self::FIELD . "[subject]']" ), 'the subject field' );
		$guest = self::FIELD . '[guest]';
		$this->assertMatchesRegularExpression( '/see a sign-in link/i', self::labelOf( $dialog, "//input[@type='radio'][@name='{$guest}'][@value='']" ) );
		$this->assertMatchesRegularExpression( '/see the contact form instead/i', self::labelOf( $dialog, "//input[@type='radio'][@name='{$guest}'][@value='form']" ) );

		$text = self::wordsOf( self::htmlDocument( $dialog )->documentElement );
		$this->assertMatchesRegularExpression( '/Visitors who are not signed in/i', $text );
		$this->assertStringContainsString( 'Paste it into any page or post.', $text );
		$this->assertStringContainsString( self::PICKED_LINE, $text );
		$this->assertStringContainsString( get_the_title( $this->picked ), $text, 'the message centre page picked today' );

		$tag = self::query( $dialog, '//input[@readonly] | //textarea[@readonly]' );
		$this->assertSame( 1, $tag->length, 'one read-only field for the tag' );
		$value = 'textarea' === $tag->item( 0 )->nodeName ? (string) $tag->item( 0 )->textContent : (string) $tag->item( 0 )->getAttribute( 'value' );
		$this->assertMatchesRegularExpression( '/^\[omnabase_(form|messages)\b[^\]]*\]$/', trim( $value ), 'it already holds a tag before any click' );
		$this->assertSame( 1, self::query( $dialog, "//button[@type='button'][normalize-space(.)='Copy']" )->length, 'Copy, a button that sends no form' );

		$form = self::createForm( $html );
		$this->assertNotNull( $form, 'the Create form' );
		$this->assertSame( array( array( 'words' => 'Create the page for me', 'primary' => true ) ), self::submitsOf( $form['node'] ), 'one primary "Create the page for me"' );
		$this->assertSame( 0, self::query( $dialog, "//select[@name='" . self::FIELD . "[project]']" )->length, 'no project choice for a key with one project' );
	}

	/**
	 * What it checks: The builder's own form stands apart from the tab's save form and carries its own token, and every choice travels with it.
	 * Passes when: the dialog is not inside the tab's save form; no form opens inside another; the Create form names its action, holds a valid
	 *   token for that action, and contains both cards, the subject field and both guest choices.
	 * Fails when: the browser drops the inner form so "Create the page for me" saves the settings instead, or the server never sees what the
	 *   owner chose.
	 */
	public function test_the_dialog_sits_outside_the_save_form_and_create_has_its_own_form_and_token(): void {
		$html = $this->pagesTabAs( $this->admin );
		$save = "//form[.//input[@name='action'][@value='" . self::SAVE . "']]";
		$this->assertSame( 1, self::query( $html, $save )->length, 'the tab\'s save form is still there' );
		$this->assertSame( 0, self::query( $html, $save . self::dialogPath() )->length, 'the dialog sits outside the save form' );
		$this->assertLessThanOrEqual( 1, self::formDepth( $html ), 'no form inside another' );
		$form = self::createForm( $html );
		$this->assertNotNull( $form );
		$this->assertNotFalse( wp_verify_nonce( (string) ( $form['fields']['_wpnonce'] ?? '' ), self::CREATE ), 'its own token' );
		$xpath = new \DOMXPath( $form['node']->ownerDocument );
		$this->assertSame( 2, $xpath->query( ".//input[@type='radio'][@name='" . self::FIELD . "[kind]']", $form['node'] )->length, 'both cards inside the form' );
		$this->assertSame( 1, $xpath->query( ".//input[@name='" . self::FIELD . "[subject]']", $form['node'] )->length, 'the subject inside the form' );
		$this->assertSame( 2, $xpath->query( ".//input[@type='radio'][@name='" . self::FIELD . "[guest]']", $form['node'] )->length, 'both guest choices inside the form' );
		$this->assertSame( 1, self::query( $html, self::dialogPath() . "//form[.//input[@name='action'][@value='" . self::CREATE . "']] | //form[.//input[@name='action'][@value='" . self::CREATE . "']]" . self::dialogPath() )->length, 'the Create form and the dialog belong together' );
	}

	/**
	 * What it checks: Without JavaScript the owner still reads the two tags and their options, as today.
	 * Passes when: the Shortcodes block holds a no-script part with today's six lines ([omnabase_form], project="slug", subject="…",
	 *   [omnabase_messages], guest="form", project="slug"), and none of those lines stands outside it.
	 * Fails when: an owner with JavaScript off sees a button that does nothing and no tags, or an owner with JavaScript on sees the old list
	 *   repeated beside the builder.
	 */
	public function test_without_javascript_the_six_lines_stay_inside_noscript(): void {
		$block = self::section( $this->pagesTabAs( $this->admin ), 'ob-wp-settings__shortcodes' );
		$this->assertGreaterThan( 0, self::query( $block, '//noscript' )->length, 'a no-script part in the block' );
		$codes = array();
		foreach ( self::query( $block, '//noscript//li' ) as $li ) {
			$code    = ( new \DOMXPath( $li->ownerDocument ) )->query( './/code', $li )->item( 0 );
			$codes[] = null === $code ? '' : trim( (string) $code->textContent );
		}
		$this->assertSame( self::SIX_CODES, $codes, 'the six lines, in today\'s order' );
		$this->assertSame( 0, self::query( $block, "//li[not(ancestor::noscript)][.//code[normalize-space(.)='guest=\"form\"']]" )->length, 'the old list only inside the no-script part' );
		$this->assertSame( 0, self::query( $block, "//noscript//button[normalize-space(.)='Build a shortcode']" )->length, 'the button outside it' );
	}

	/**
	 * What it checks: The builder's small script loads on Settings › OmnaBase and on no other admin page.
	 * Passes when: on the plugin's screen a script from assets/admin.js is queued; on the Dashboard, the Posts list and Settings › General it
	 *   is not.
	 * Fails when: the builder does nothing because its script never loads, or every admin page carries the plugin's script for nothing (slower
	 *   pages, clashes with other plugins).
	 */
	public function test_the_script_loads_only_on_the_plugins_screen(): void {
		$this->asAdminOnTheScreen( $this->admin );
		$screens = array(
			'settings_page_omnabase' => array( 'settings_page_omnabase', true ),
			'index.php'              => array( 'dashboard', false ),
			'edit.php'               => array( 'edit-post', false ),
			'options-general.php'    => array( 'options-general', false ),
		);
		foreach ( $screens as $hook => [ $screen, $expected ] ) {
			self::freshQueue();
			set_current_screen( $screen );
			do_action( 'admin_enqueue_scripts', $hook );
			$this->assertSame( $expected, self::adminScriptQueued(), $hook );
		}
	}

	/* ---------- Create the page for me: the refusals ---------- */

	/**
	 * What it checks: "Create the page for me" makes nothing without the form's token.
	 * Passes when: a post with no token, and one with the token of another action, both leave the site's pages as they were; the same post
	 *   with the action's own token (only the kind chosen, nothing else filled in) makes one page.
	 * Fails when: another site can make an administrator's browser publish pages on this site.
	 */
	public function test_create_refuses_without_the_forms_token(): void {
		$this->assertSame( array(), $this->postAs( $this->admin, array( 'kind' => 'form' ), null ), 'no token' );
		$this->assertSame( array(), $this->postAs( $this->admin, array( 'kind' => 'form' ), self::SAVE ), 'another action\'s token' );
		$this->assertCount( 1, $this->postAs( $this->admin, array( 'kind' => 'form' ) ), 'the same post with its own token makes the page (so the two refusals above were about the token)' );
	}

	/**
	 * What it checks: Only a person who may both change the plugin's settings and publish pages can have a page made.
	 * Passes when: a user of a role that may change settings but not publish pages, and an Editor (may publish pages, may not change settings),
	 *   each posting with their own valid token, make no page; the administrator's same post makes one.
	 * Fails when: a settings-only helper account publishes pages it could never publish by hand, or an Editor reaches a settings action.
	 */
	public function test_create_refuses_a_person_who_may_not_publish_pages(): void {
		$settings_only = $this->userWith( 'ob_settings_only', array( 'read', 'manage_options', 'edit_pages', 'edit_published_pages' ) );
		$this->assertSame( array(), $this->postAs( $settings_only, array( 'kind' => 'form' ) ), 'manage_options without publish_pages' );
		$editor = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->assertSame( array(), $this->postAs( $editor, array( 'kind' => 'form' ) ), 'publish_pages without manage_options' );
		$this->assertCount( 1, $this->postAs( $this->admin, array( 'kind' => 'form' ) ), 'an administrator\'s same post makes the page (so the refusals above were about the permissions)' );
	}

	/**
	 * What it checks: The server accepts only the two kinds and only the site's own projects.
	 * Passes when: a kind other than "form" or "messages" (and none at all) makes no page; with two projects kept, a project slug the key does
	 *   not have makes no page, for the form and for the message centre.
	 * Fails when: a crafted post publishes a page with a tag the plugin does not know, or a form that sends to a project the site may not use
	 *   (visitors' messages are refused or lost).
	 */
	public function test_create_refuses_an_unknown_kind_or_project(): void {
		foreach ( array( 'gallery', '', 'form"]' ) as $kind ) {
			$this->assertSame( array(), $this->createAsAdmin( array( 'kind' => $kind ) )[1], "kind {$kind}" );
		}
		$this->twoProjects();
		$this->assertSame( array(), $this->createAsAdmin( array( 'kind' => 'form', 'project' => 'nope' ) )[1], 'an unknown project for the form' );
		$this->assertSame( array(), $this->createAsAdmin( array( 'kind' => 'messages', 'project' => 'nope' ) )[1], 'an unknown project for the message centre' );
	}

	/* ---------- Create the page for me: what it makes ---------- */

	/**
	 * What it checks: The subject is cleaned on the server, so it can never close the tag early and smuggle another shortcode onto a page.
	 * Passes when: the subject `x"] [other` makes a page whose only tag is `[omnabase_form subject="x other"]`, with one opening and one closing
	 *   bracket in the whole content; a subject made only of `"[]"` makes `[omnabase_form]`.
	 * Fails when: a pasted subject publishes a page that runs a second, unintended shortcode, or shows a broken tag to visitors.
	 */
	public function test_the_subject_is_cleaned_on_the_server(): void {
		$made = $this->createAsAdmin( array( 'kind' => 'form', 'subject' => 'x"] [other' ) )[1];
		$this->assertSame( '[omnabase_form subject="x other"]', $this->theOnlyTag( $made ) );
		$content = (string) get_post_field( 'post_content', $made[0] );
		$this->assertSame( 1, substr_count( $content, '[' ), 'one opening bracket' );
		$this->assertSame( 1, substr_count( $content, ']' ), 'one closing bracket' );
		$this->assertStringNotContainsString( '[other', $content );
		wp_trash_post( $made[0] );

		$this->assertSame( '[omnabase_form]', $this->theOnlyTag( $this->createAsAdmin( array( 'kind' => 'form', 'subject' => '"[]"' ) )[1] ) );
	}

	/**
	 * What it checks: "Create the page for me" with the contact form publishes a "Contact support" page carrying the form's tag at once.
	 * Passes when: one page is made, published, titled "Contact support", written by the administrator, carrying only `[omnabase_form
	 *   subject="Billing"]` (no project: the key has one, even though the post named it); the message centre's page stays as it was; the browser
	 *   returns to the Forms and messages tab.
	 * Fails when: the owner gets a draft nobody sees, a page titled "Contact-2", a tag that breaks once the project is renamed in OmnaBase, or
	 *   their message centre page silently replaced by a contact page.
	 */
	public function test_create_makes_the_contact_page_published_with_its_tag_and_title(): void {
		[ $location, $made ] = $this->createAsAdmin( array( 'kind' => 'form', 'project' => 'enfold', 'subject' => 'Billing', 'guest' => '' ) );
		$this->assertSame( '[omnabase_form subject="Billing"]', $this->theOnlyTag( $made ) );
		$page = get_post( $made[0] );
		$this->assertSame( 'publish', $page->post_status, 'published at once' );
		$this->assertSame( 'page', $page->post_type );
		$this->assertSame( 'Contact support', $page->post_title );
		$this->assertSame( $this->admin, (int) $page->post_author );
		$this->assertSame( $this->picked, (int) ( get_option( 'omnabase_portal' )['page'] ?? 0 ), 'the message centre page is untouched' );
		$this->assertSame( 'pages', $this->tabIn( $location ) );
	}

	/**
	 * What it checks: "Create the page for me" with the message centre publishes a "Support" page, picks it as the message centre page, and the
	 *   next view says so with a link to edit it.
	 * Passes when: with no other page carrying the message centre, one page is made, published, titled "Support", carrying only
	 *   `[omnabase_messages guest="form"]`; it is now the picked message centre page; the Forms and messages tab then shows a notice with the
	 *   word "published", the title "Support" and a link to edit that page.
	 * Fails when: the new message centre page is not picked (so it only shows the "works only on the picked page" note and is cached), the
	 *   owner cannot find the page they just made, or visitors get the sign-in link although the owner chose the form.
	 */
	public function test_create_makes_the_message_centre_page_and_picks_it(): void {
		wp_trash_post( $this->picked );
		[ $location, $made ] = $this->createAsAdmin( array( 'kind' => 'messages', 'guest' => 'form', 'subject' => '' ) );
		$this->assertSame( '[omnabase_messages guest="form"]', $this->theOnlyTag( $made ) );
		$page = get_post( $made[0] );
		$this->assertSame( 'publish', $page->post_status );
		$this->assertSame( 'Support', $page->post_title );
		$this->assertSame( $made[0], (int) ( get_option( 'omnabase_portal' )['page'] ?? 0 ), 'picked as the message centre page' );
		$this->assertSame( 'pages', $this->tabIn( $location ) );

		$this->followQuery( (string) $location );
		$notice = self::section( $this->pagesTabAs( $this->admin ), 'ob-wp-settings__notice' );
		$this->assertNotSame( '', $notice, 'a notice on the next view' );
		$this->assertMatchesRegularExpression( '/published/i', self::wordsOf( self::htmlDocument( $notice )->documentElement ) );
		$this->assertStringContainsString( 'Support', $notice );
		$this->assertMatchesRegularExpression( '/[?&]post=' . $made[0] . '&(amp;)?action=edit/', $notice, 'a link to edit the page' );
	}

	/**
	 * What it checks: The page's content comes only from the checked choices, never from tag text or content the browser sends.
	 * Passes when: a contact-form post that also sends a tag field `[omnabase_messages][evil]`, a content field and a guest choice makes a page
	 *   whose only tag is `[omnabase_form]`; a message-centre post with a subject makes `[omnabase_messages]` (no subject); a guest value other
	 *   than "form" either makes no page or a page whose tag carries no guest option.
	 * Fails when: anyone who can post the form can publish any shortcode or text they like, or options of the other kind leak into the tag.
	 */
	public function test_the_page_is_built_from_the_choices_never_from_tag_text_sent(): void {
		$sent = array( 'tag' => '[omnabase_messages][evil]', 'content' => '[evil] <script>alert(1)</script>' );
		$made = $this->createAsAdmin( array( 'kind' => 'form', 'subject' => '', 'guest' => 'form' ) + $sent, array( 'content' => '[evil]', 'post_content' => '[evil]', 'omnabase_tag' => '[evil]' ) )[1];
		$this->assertSame( '[omnabase_form]', $this->theOnlyTag( $made ) );
		$content = (string) get_post_field( 'post_content', $made[0] );
		$this->assertStringNotContainsString( 'evil', $content );
		$this->assertStringNotContainsString( '<script', $content );
		wp_trash_post( $made[0] );

		wp_trash_post( $this->picked );
		$made = $this->createAsAdmin( array( 'kind' => 'messages', 'subject' => 'Billing', 'guest' => '' ) )[1];
		$this->assertSame( '[omnabase_messages]', $this->theOnlyTag( $made ) );
		wp_trash_post( $made[0] );

		$made = $this->createAsAdmin( array( 'kind' => 'messages', 'guest' => 'form" project="x' ) )[1];
		if ( array() !== $made ) {
			$this->assertSame( '[omnabase_messages]', $this->theOnlyTag( $made ), 'an unknown guest value never reaches the tag' );
		}
	}

	/**
	 * What it checks: With a key that has several projects, the dialog asks which one, and the tag and the page name it.
	 * Passes when: with two projects kept (Enfold, Omnalingo), the Create form holds a project choice offering "enfold" and "omnalingo"; the
	 *   contact form for "omnalingo" makes a page whose tag names project omnalingo; the message centre for "enfold" with the sign-in link makes
	 *   `[omnabase_messages project="enfold"]`.
	 * Fails when: a site with several projects gets a form that only shows an administrator's "name a project" note, or messages go to the wrong
	 *   project.
	 */
	public function test_with_several_projects_the_dialog_offers_them_and_the_tag_names_one(): void {
		$this->twoProjects();
		$form = self::createForm( $this->pagesTabAs( $this->admin ) );
		$this->assertNotNull( $form );
		$values = array();
		foreach ( ( new \DOMXPath( $form['node']->ownerDocument ) )->query( ".//select[@name='" . self::FIELD . "[project]']/option", $form['node'] ) as $option ) {
			$values[] = (string) $option->getAttribute( 'value' );
		}
		$this->assertSame( array( 'enfold', 'omnalingo' ), array_values( array_filter( $values, static fn ( $v ) => '' !== $v ) ), 'both projects offered' );

		$tag = $this->theOnlyTag( $this->createAsAdmin( array( 'kind' => 'form', 'project' => 'omnalingo', 'subject' => '' ) )[1] );
		$this->assertSame( array( 'project' => 'omnalingo' ), shortcode_parse_atts( (string) preg_replace( '/^\[omnabase_form|\]$/', '', $tag ) ) );

		wp_trash_post( $this->picked );
		$this->assertSame( '[omnabase_messages project="enfold"]', $this->theOnlyTag( $this->createAsAdmin( array( 'kind' => 'messages', 'project' => 'enfold', 'guest' => '' ) )[1] ) );
	}

	/* ---------- the offer of an existing page ---------- */

	/**
	 * What it checks: Before making a new page, the dialog offers a published page that already carries the tag; drafts are not offered.
	 * Passes when: with a published page carrying `[omnabase_form subject="Hi"]`, a draft carrying `[omnabase_form]` and the picked message
	 *   centre page (published, carrying `[omnabase_messages]`), the dialog's offer for the contact form links to the published form page and
	 *   shows its title, its offer for the message centre links to the picked page, and nothing in the dialog links to the draft.
	 * Fails when: an owner who already has a contact page gets a second one (and a "contact-2" address), or is pointed at a draft visitors
	 *   cannot see.
	 */
	public function test_an_existing_published_page_with_the_tag_is_offered(): void {
		$published = $this->page( 'Contact us', '[omnabase_form subject="Hi"]' );
		$draft     = (int) self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Draft contact', 'post_content' => '[omnabase_form]' ) );
		$dialog    = self::dialogIn( $this->pagesTabAs( $this->admin ) );
		$this->assertNotSame( '', $dialog );

		$form_offer = self::offerFor( $dialog, 'form' );
		$this->assertTrue( self::linksTo( $form_offer, $published ), 'the published contact page is offered for the form' );
		$this->assertStringContainsString( get_the_title( $published ), $form_offer );
		$this->assertFalse( self::linksTo( $form_offer, $this->picked ), 'the message centre page is not offered as a contact page' );
		$this->assertTrue( self::linksTo( self::offerFor( $dialog, 'messages' ), $this->picked ), 'the picked message centre page is offered for the message centre' );
		$this->assertFalse( self::linksTo( $dialog, $draft ), 'a draft is never offered' );
	}

	/**
	 * What it checks: The offer lists only pages the person may edit.
	 * Passes when: for a user of a role that may change settings and publish and edit their own pages but not other people's, the dialog offers
	 *   their own published contact page and not the administrator's.
	 * Fails when: the dialog points a person at a page they cannot open in the editor, or reveals the edit address of pages they may not touch.
	 */
	public function test_a_page_the_person_may_not_edit_is_not_offered(): void {
		$maker  = $this->userWith( 'ob_page_maker', array( 'read', 'manage_options', 'edit_pages', 'publish_pages', 'edit_published_pages' ) );
		$theirs = (int) self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_author' => $this->admin, 'post_title' => 'Admin contact', 'post_content' => '[omnabase_form]' ) );
		$mine   = (int) self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_author' => $maker, 'post_title' => 'My contact', 'post_content' => '[omnabase_form]' ) );
		$dialog = self::dialogIn( $this->pagesTabAs( $maker ) );
		$this->assertNotSame( '', $dialog, 'the dialog for this user' );
		$offer = self::offerFor( $dialog, 'form' );
		$this->assertTrue( self::linksTo( $offer, $mine ), 'their own page is offered' );
		$this->assertFalse( self::linksTo( $dialog, $theirs ), 'the administrator\'s page is not' );
	}
}
