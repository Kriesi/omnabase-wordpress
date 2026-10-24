<?php
/**
 * Settings › OmnaBase: the Shortcodes card ("Build a shortcode", which opens the builder's dialog; both shortcodes and their attributes for
 * a browser without JavaScript; the My Account switch as a row of the kit's; "Theme styles only" is on the Appearance tab), inside the Forms and messages tab's one form.
 *
 * @package OmnaBase
 *
 * @var array{myAccount:bool,shop:bool} $omnabase_shortcodes
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="ob-form__section ob-wp-settings__shortcodes">
	<div class="ob-form__head"><h2 class="ob-form__title"><?php esc_html_e( 'Shortcodes', 'omnabase' ); ?></h2></div>
	<div class="ob-form__intro"><?php esc_html_e( 'Put a shortcode on a page to show the contact form or the message centre there.', 'omnabase' ); ?></div>
	<p class="ob-wp-settings__builder" hidden>
		<button type="button" class="ob-btn ob-btn--primary" aria-controls="ob-wp-builder" aria-haspopup="dialog"><?php esc_html_e( 'Build a shortcode', 'omnabase' ); ?></button>
	</p>
	<noscript>
		<ul class="ob-help-list ob-wp-settings__shortcode-list">
			<li class="ob-help-list__item"><code>[omnabase_form]</code> <?php esc_html_e( 'a form built in OmnaBase, on any page: visitors\' messages become tickets. id="slug" picks the form; without it, the project\'s default form shows.', 'omnabase' ); ?></li>
			<li class="ob-help-list__item"><code>project="slug"</code> <?php esc_html_e( 'which of this site\'s projects gets the messages; needed when the site has several.', 'omnabase' ); ?></li>
			<li class="ob-help-list__item"><code>subject="…"</code> <?php esc_html_e( 'the words the form\'s subject field starts with, when it has one.', 'omnabase' ); ?></li>
			<li class="ob-help-list__item"><code>[omnabase_messages]</code> <?php esc_html_e( 'the message centre for signed-in customers; it works only on the message centre page picked above.', 'omnabase' ); ?></li>
			<li class="ob-help-list__item"><code>guest="form"</code> <?php esc_html_e( 'visitors who are not signed in get the contact form instead of a login link.', 'omnabase' ); ?></li>
			<li class="ob-help-list__item"><code>project="slug"</code> <?php esc_html_e( 'in the message centre: the project new questions go to.', 'omnabase' ); ?></li>
		</ul>
	</noscript>
	<?php if ( $omnabase_shortcodes['shop'] ) : ?>
		<div class="ob-form-row">
			<div class="ob-form-row__text">
				<label class="ob-form-row__label" for="omnabase-my-account"><?php esc_html_e( 'My Account menu', 'omnabase' ); ?></label>
				<div class="ob-form-row__help"><?php esc_html_e( 'Add "Support" to WooCommerce\'s My Account menu, leading to the message centre page.', 'omnabase' ); ?></div>
			</div>
			<div class="ob-form-row__control">
				<input type="checkbox" role="switch" class="ob-switch" id="omnabase-my-account" name="omnabase_my_account" value="1" <?php checked( $omnabase_shortcodes['myAccount'] ); ?>>
			</div>
		</div>
	<?php endif; ?>
</section>
