<?php
/**
 * The shortcode form `[omnabase_form]`: the look's wrapper around the empty container OmnaBase's embed (`forms.js`, loaded from OmnaBase's
 * app address) draws the form in. Rendered by `Forms\Shortcode`; everything printed is escaped. The wrapper carries the chosen look's marks
 * (`data-theme`, the owner's accent), which the plugin's stylesheet turns into the embed's colours. The container names the form and the
 * site's project; only a signed-in visitor's view carries OmnaBase's prefill token (such a view is kept out of page caches). The site's own
 * privacy page, when it has one, is linked in one small line under the form.
 *
 * @package OmnaBase
 *
 * @var array{wrapper:array<string,string>,form:string,project:string,subject:string,token:string,privacyUrl:string} $omnabase_form
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="ob-wp ob-wp-form-wrap"<?php foreach ( $omnabase_form['wrapper'] as $omnabase_attr => $omnabase_value ) : ?> <?php echo esc_attr( $omnabase_attr ); ?>="<?php echo esc_attr( $omnabase_value ); ?>"<?php endforeach; ?>>
	<div class="ob-wp-form" data-omnabase-form="<?php echo esc_attr( $omnabase_form['form'] ); ?>" data-project="<?php echo esc_attr( $omnabase_form['project'] ); ?>"<?php if ( '' !== $omnabase_form['subject'] ) : ?> data-prefill-subject="<?php echo esc_attr( $omnabase_form['subject'] ); ?>"<?php endif; ?><?php if ( '' !== $omnabase_form['token'] ) : ?> data-prefill-token="<?php echo esc_attr( $omnabase_form['token'] ); ?>"<?php endif; ?>></div>
	<?php if ( '' !== $omnabase_form['privacyUrl'] ) : ?>
		<p class="ob-wp-form__privacy"><a href="<?php echo esc_url( $omnabase_form['privacyUrl'] ); ?>"><?php esc_html_e( 'Privacy policy', 'omnabase' ); ?></a></p>
	<?php endif; ?>
</div>
