<?php
/**
 * Settings › OmnaBase › Customer data: the card of the licence kept in a user field, inside the tab's save form (its switch is among the
 * sources above). Included by `facts.php`.
 *
 * @package OmnaBase
 *
 * @var array{licence:array{key:string,product:string,unused:bool}} $omnabase_facts
 */

defined( 'ABSPATH' ) || exit;

$omnabase_licence = $omnabase_facts['licence'];
$omnabase_product = '' === $omnabase_licence['product'] ? __( 'Licence', 'omnabase' ) : $omnabase_licence['product'];
?>
<section class="ob-form__section ob-wp-settings__licence-meta">
	<div class="ob-form__head"><h2 class="ob-form__title"><?php esc_html_e( 'Licence in a user field', 'omnabase' ); ?></h2></div>
	<div class="ob-form__intro"><?php esc_html_e( 'A licence code another plugin keeps for each user, such as a purchase code checked at registration; your team sees only its last four characters.', 'omnabase' ); ?></div>
	<div class="ob-form-row">
		<div class="ob-form-row__text">
			<label class="ob-form-row__label" for="omnabase-licence-meta-key"><?php esc_html_e( 'Field name', 'omnabase' ); ?></label>
			<div class="ob-form-row__help"><?php esc_html_e( 'The name the code is stored under for each user, such as av_purchase_code.', 'omnabase' ); ?></div>
			<?php if ( $omnabase_licence['unused'] ) : ?>
				<div class="ob-form-row__help ob-wp-settings__licence-meta-unused"><?php esc_html_e( 'No user has this field yet: check the name, or wait until the first code is saved.', 'omnabase' ); ?></div>
			<?php endif; ?>
		</div>
		<div class="ob-form-row__control">
			<input type="text" id="omnabase-licence-meta-key" name="omnabase_facts[licence_meta][key]" value="<?php echo esc_attr( $omnabase_licence['key'] ); ?>" maxlength="64" autocomplete="off" spellcheck="false">
		</div>
	</div>
	<div class="ob-form-row">
		<div class="ob-form-row__text">
			<label class="ob-form-row__label" for="omnabase-licence-meta-product"><?php esc_html_e( 'Product name', 'omnabase' ); ?></label>
			<div class="ob-form-row__help">
				<?php
				/* translators: %s: the product's name, as the team sees it beside a made-up masked code. */
				echo esc_html( sprintf( __( 'Shown beside the licence in OmnaBase, for example: %s ••••-1a2b, active.', 'omnabase' ), $omnabase_product ) );
				?>
			</div>
		</div>
		<div class="ob-form-row__control">
			<input type="text" id="omnabase-licence-meta-product" name="omnabase_facts[licence_meta][product]" value="<?php echo esc_attr( $omnabase_licence['product'] ); ?>" maxlength="120" autocomplete="off">
		</div>
	</div>
</section>
