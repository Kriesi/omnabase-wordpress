<?php
/**
 * Settings › OmnaBase: the Shortcodes block ("Build a shortcode", which opens the builder's dialog; both shortcodes and their attributes for
 * a browser without JavaScript; the front-end switches), inside the Forms and messages tab's one form.
 *
 * @package OmnaBase
 *
 * @var array{themeOnly:bool,myAccount:bool,shop:bool} $omnabase_shortcodes
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="ob-wp-settings__shortcodes">
	<h2><?php esc_html_e( 'Shortcodes', 'omnabase' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Put a shortcode on a page to show the contact form or the message centre there.', 'omnabase' ); ?></p>
	<p class="ob-wp-settings__builder" hidden>
		<button type="button" class="button" aria-controls="ob-wp-builder" aria-haspopup="dialog"><?php esc_html_e( 'Build a shortcode', 'omnabase' ); ?></button>
	</p>
	<noscript>
		<ul class="ob-wp-settings__shortcode-list">
			<li><code>[omnabase_form]</code> <?php esc_html_e( 'the contact form, on any page: visitors\' messages become tickets.', 'omnabase' ); ?></li>
			<li><code>project="slug"</code> <?php esc_html_e( 'which of this site\'s projects gets the messages; needed when the site has several.', 'omnabase' ); ?></li>
			<li><code>subject="…"</code> <?php esc_html_e( 'the words the subject field starts with.', 'omnabase' ); ?></li>
			<li><code>[omnabase_messages]</code> <?php esc_html_e( 'the message centre for signed-in customers; it works only on the message centre page picked above.', 'omnabase' ); ?></li>
			<li><code>guest="form"</code> <?php esc_html_e( 'visitors who are not signed in get the contact form instead of a login link.', 'omnabase' ); ?></li>
			<li><code>project="slug"</code> <?php esc_html_e( 'in the message centre: the project new questions go to.', 'omnabase' ); ?></li>
		</ul>
	</noscript>
	<p>
		<label for="omnabase-theme-only">
			<input type="checkbox" id="omnabase-theme-only" name="omnabase_theme_only" value="1" <?php checked( $omnabase_shortcodes['themeOnly'] ); ?>>
			<?php esc_html_e( 'Use the theme\'s styles only: the form and the message centre load no OmnaBase stylesheet.', 'omnabase' ); ?>
		</label>
	</p>
	<?php if ( $omnabase_shortcodes['shop'] ) : ?>
		<p>
			<label for="omnabase-my-account">
				<input type="checkbox" id="omnabase-my-account" name="omnabase_my_account" value="1" <?php checked( $omnabase_shortcodes['myAccount'] ); ?>>
				<?php esc_html_e( 'Add "Support" to WooCommerce\'s My Account menu, leading to the message centre page.', 'omnabase' ); ?>
			</label>
		</p>
	<?php endif; ?>
</div>
