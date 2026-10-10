<?php
/**
 * Settings › OmnaBase: the message-centre card (the page pick, a row of the kit's), inside the Forms and messages tab's one form.
 *
 * @package OmnaBase
 *
 * @var array{picked:int,pages:array<int,array{id:int,title:string}>} $omnabase_portal
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="ob-form__section ob-wp-settings__portal">
	<div class="ob-form__head"><h2 class="ob-form__title"><?php esc_html_e( 'Message centre', 'omnabase' ); ?></h2></div>
	<div class="ob-form__intro"><?php esc_html_e( 'The page where signed-in customers read and answer their tickets; it is never cached.', 'omnabase' ); ?></div>
	<div class="ob-form-row">
		<div class="ob-form-row__text">
			<label class="ob-form-row__label" for="omnabase-portal-page"><?php esc_html_e( 'Message centre page', 'omnabase' ); ?></label>
			<div class="ob-form-row__help"><?php esc_html_e( 'Put the shortcode [omnabase_messages] on this page.', 'omnabase' ); ?></div>
		</div>
		<div class="ob-form-row__control">
			<select id="omnabase-portal-page" name="omnabase_portal[page]">
				<option value="0"><?php esc_html_e( '— No page —', 'omnabase' ); ?></option>
				<?php foreach ( $omnabase_portal['pages'] as $omnabase_page ) : ?>
					<option value="<?php echo esc_attr( (string) $omnabase_page['id'] ); ?>" <?php selected( $omnabase_page['id'], $omnabase_portal['picked'] ); ?>><?php echo esc_html( $omnabase_page['title'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
	</div>
</section>
