<?php
/**
 * Settings › OmnaBase › Customer data: the card of the licence kept in a user field, inside the tab's save form (its switch is among the
 * sources above). Included by `facts.php`.
 *
 * @package OmnaBase
 *
 * @var array{licence:array{key:string,product:string,condition:string,condition_text:string,expiry:string,unused:bool,sample:string}} $omnabase_facts
 */

defined( 'ABSPATH' ) || exit;

$omnabase_licence    = $omnabase_facts['licence'];
$omnabase_conditions = array(
	'any'      => __( 'Any value', 'omnabase' ),
	'equals'   => __( 'Equals', 'omnabase' ),
	'contains' => __( 'Contains', 'omnabase' ),
);
?>
<section class="ob-form__section ob-wp-settings__licence-meta">
	<div class="ob-form__head"><h2 class="ob-form__title"><?php esc_html_e( 'Licence in a user field', 'omnabase' ); ?></h2></div>
	<div class="ob-form__intro"><?php esc_html_e( 'A licence code another plugin keeps for each user, such as a purchase code checked at registration; your team sees only its last four characters.', 'omnabase' ); ?></div>
	<div class="ob-form-row">
		<div class="ob-form-row__text">
			<label class="ob-form-row__label" for="omnabase-licence-meta-key"><?php esc_html_e( 'Field name', 'omnabase' ); ?></label>
			<div class="ob-form-row__help"><?php esc_html_e( 'The field holding each user\'s code, such as av_purchase_code, or a key inside a stored list, such as av_purchase_data.purchase_code.', 'omnabase' ); ?></div>
			<?php if ( $omnabase_licence['unused'] ) : ?>
				<div class="ob-form-row__help ob-wp-settings__licence-meta-unused"><?php esc_html_e( 'No user has this field yet: check the name, or wait until the first code is saved.', 'omnabase' ); ?></div>
			<?php endif; ?>
		</div>
		<div class="ob-form-row__control">
			<input type="text" id="omnabase-licence-meta-key" name="omnabase_facts[licence_meta][key]" value="<?php echo esc_attr( $omnabase_licence['key'] ); ?>" maxlength="129" autocomplete="off" spellcheck="false">
		</div>
	</div>
	<div class="ob-form-row">
		<div class="ob-form-row__text">
			<label class="ob-form-row__label" for="omnabase-licence-meta-product"><?php esc_html_e( 'Product name', 'omnabase' ); ?></label>
			<div class="ob-form-row__help"><?php esc_html_e( 'Shown beside the licence in OmnaBase.', 'omnabase' ); ?></div>
		</div>
		<div class="ob-form-row__control">
			<input type="text" id="omnabase-licence-meta-product" name="omnabase_facts[licence_meta][product]" value="<?php echo esc_attr( $omnabase_licence['product'] ); ?>" maxlength="120" autocomplete="off">
		</div>
	</div>
	<div class="ob-form-row">
		<div class="ob-form-row__text">
			<label class="ob-form-row__label" for="omnabase-licence-meta-condition"><?php esc_html_e( 'Condition', 'omnabase' ); ?></label>
			<div class="ob-form-row__help"><?php esc_html_e( 'Which stored values count as a licence; capitals and spaces around the text are ignored.', 'omnabase' ); ?></div>
		</div>
		<div class="ob-form-row__control">
			<select id="omnabase-licence-meta-condition" class="ob-wp-settings__condition" name="omnabase_facts[licence_meta][condition]">
				<?php foreach ( $omnabase_conditions as $omnabase_value => $omnabase_label ) : ?>
					<option value="<?php echo esc_attr( $omnabase_value ); ?>" <?php selected( $omnabase_licence['condition'], $omnabase_value ); ?>><?php echo esc_html( $omnabase_label ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="text" class="ob-wp-settings__condition-text" name="omnabase_facts[licence_meta][condition_text]" value="<?php echo esc_attr( $omnabase_licence['condition_text'] ); ?>" maxlength="120" autocomplete="off" aria-label="<?php esc_attr_e( 'Condition text', 'omnabase' ); ?>">
		</div>
	</div>
	<div class="ob-form-row">
		<div class="ob-form-row__text">
			<label class="ob-form-row__label" for="omnabase-licence-meta-expiry"><?php esc_html_e( 'Expiry field', 'omnabase' ); ?></label>
			<div class="ob-form-row__help"><?php esc_html_e( 'Optional: the field holding the day the licence ends, such as av_purchase_data.supported_until; after that day your team sees it as expired.', 'omnabase' ); ?></div>
		</div>
		<div class="ob-form-row__control">
			<input type="text" id="omnabase-licence-meta-expiry" name="omnabase_facts[licence_meta][expiry]" value="<?php echo esc_attr( $omnabase_licence['expiry'] ); ?>" maxlength="129" autocomplete="off" spellcheck="false">
		</div>
	</div>
	<p class="ob-form-row__help ob-wp-settings__licence-meta-sample"><?php echo esc_html( $omnabase_licence['sample'] ); ?></p>
</section>
