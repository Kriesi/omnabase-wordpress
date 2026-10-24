<?php
/**
 * Settings › OmnaBase: the Forms card on the Forms and messages tab (the plugin's forms round): the workspace's forms built in OmnaBase,
 * each with its shortcode, and the link to OmnaBase's Settings › Forms. Only a list and one link: the forms are made and changed in OmnaBase.
 *
 * @package OmnaBase
 *
 * @var array{connected:bool,reachable:bool,forms:array<int,array{name:string,tag:string,default:bool}>,link:string} $omnabase_forms
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="ob-form__section ob-wp-settings__forms">
	<div class="ob-form__head"><h2 class="ob-form__title"><?php esc_html_e( 'Forms', 'omnabase' ); ?></h2></div>
	<div class="ob-form__intro"><?php esc_html_e( 'Your forms are built in OmnaBase. Paste a form\'s shortcode into any page to show it there.', 'omnabase' ); ?></div>
	<?php if ( ! $omnabase_forms['connected'] ) : ?>
		<p class="ob-form__intro"><?php esc_html_e( 'Connect this site to list your forms here.', 'omnabase' ); ?></p>
	<?php elseif ( ! $omnabase_forms['reachable'] ) : ?>
		<p class="ob-form__intro"><?php esc_html_e( 'OmnaBase could not be asked for your forms just now. Open this tab again in a moment.', 'omnabase' ); ?></p>
	<?php elseif ( array() === $omnabase_forms['forms'] ) : ?>
		<p class="ob-form__intro"><?php esc_html_e( 'Your workspace has no forms yet. Build one in OmnaBase and publish it.', 'omnabase' ); ?></p>
	<?php else : ?>
		<ul class="ob-help-list ob-wp-settings__form-list">
			<?php foreach ( $omnabase_forms['forms'] as $omnabase_form_row ) : ?>
				<li class="ob-help-list__item">
					<strong><?php echo esc_html( $omnabase_form_row['name'] ); ?></strong>
					<code><?php echo esc_html( $omnabase_form_row['tag'] ); ?></code>
					<?php if ( $omnabase_form_row['default'] ) : ?>
						<span class="ob-wp-settings__form-default"><?php esc_html_e( 'the project\'s default form', 'omnabase' ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<?php if ( '' !== $omnabase_forms['link'] ) : ?>
		<p class="ob-wp-settings__actions"><a class="ob-btn ob-btn--outline ob-btn--sm" href="<?php echo esc_url( $omnabase_forms['link'] ); ?>"><?php esc_html_e( 'Build forms in OmnaBase', 'omnabase' ); ?></a></p>
	<?php endif; ?>
</section>
