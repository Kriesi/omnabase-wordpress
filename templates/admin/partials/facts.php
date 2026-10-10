<?php
/**
 * Settings › OmnaBase: the Customer data tab's save form, two cards: the sources (a switch per detected source) and the user fields (behind
 * "Choose user fields"). Included by the Customer data tab.
 *
 * @package OmnaBase
 *
 * @var array{sources:array<int,array{id:string,label:string,on:bool,unsupported:string}>,meta:array<int,array{key:string,on:bool}>,last:?array} $omnabase_facts
 */

defined( 'ABSPATH' ) || exit;
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ob-wp-settings__facts ob-wp-settings__stack">
	<input type="hidden" name="action" value="omnabase_save_facts">
	<?php wp_nonce_field( 'omnabase_save_facts' ); ?>
	<section class="ob-form__section ob-wp-settings__sources">
		<div class="ob-form__head"><h2 class="ob-form__title"><?php esc_html_e( 'Customer data', 'omnabase' ); ?></h2></div>
		<div class="ob-form__intro"><?php esc_html_e( 'What this site tells your team about a customer when a ticket opens; every source stays off until you turn it on.', 'omnabase' ); ?></div>
		<?php foreach ( $omnabase_facts['sources'] as $omnabase_source ) : ?>
			<?php $omnabase_id = 'omnabase-source-' . sanitize_html_class( $omnabase_source['id'] ); ?>
			<div class="ob-form-row">
				<div class="ob-form-row__text">
					<label class="ob-form-row__label" for="<?php echo esc_attr( $omnabase_id ); ?>">
						<?php echo esc_html( $omnabase_source['label'] ); ?>
						<?php if ( '' !== $omnabase_source['unsupported'] ) : ?>
							<strong class="ob-wp-settings__unsupported">(<?php echo esc_html( $omnabase_source['unsupported'] ); ?>)</strong>
						<?php endif; ?>
					</label>
				</div>
				<div class="ob-form-row__control">
					<input type="checkbox" role="switch" class="ob-switch" id="<?php echo esc_attr( $omnabase_id ); ?>" name="omnabase_facts[sources][]" value="<?php echo esc_attr( $omnabase_source['id'] ); ?>" <?php checked( $omnabase_source['on'] ); ?>>
				</div>
			</div>
		<?php endforeach; ?>
		<?php if ( null !== $omnabase_facts['last'] && array() !== $omnabase_facts['last']['dropped'] ) : ?>
			<p class="ob-wp-settings__facts-dropped">
				<?php
				/* translators: 1: date and time, 2: the sections left out, comma-separated. */
				echo esc_html( sprintf( __( 'The answer at %1$s left out: %2$s (too large or not readable by OmnaBase).', 'omnabase' ), wp_date( 'Y-m-d H:i', (int) $omnabase_facts['last']['at'] ), implode( ', ', $omnabase_facts['last']['dropped'] ) ) );
				?>
			</p>
		<?php endif; ?>
	</section>
	<section class="ob-form__section ob-wp-settings__profile">
		<div class="ob-form__head"><h2 class="ob-form__title"><?php esc_html_e( 'User fields', 'omnabase' ); ?></h2></div>
		<div class="ob-form__intro"><?php esc_html_e( 'Extra fields other plugins keep for each user, such as a phone number; the ticked ones go with the customer\'s profile.', 'omnabase' ); ?></div>
		<details class="ob-more ob-wp-settings__user-fields">
			<summary class="ob-more__toggle"><?php esc_html_e( 'Choose user fields', 'omnabase' ); ?></summary>
			<div class="ob-more__body">
				<p><?php esc_html_e( 'Never offered or sent:', 'omnabase' ); ?></p>
				<ul class="ob-help-list ob-wp-settings__never">
					<li class="ob-help-list__item"><?php esc_html_e( 'Passwords', 'omnabase' ); ?></li>
					<li class="ob-help-list__item"><?php esc_html_e( 'Tokens', 'omnabase' ); ?></li>
					<li class="ob-help-list__item"><?php esc_html_e( 'Keys and other secrets', 'omnabase' ); ?></li>
					<li class="ob-help-list__item"><?php esc_html_e( 'Private fields (their names start with an underscore)', 'omnabase' ); ?></li>
				</ul>
				<?php if ( array() === $omnabase_facts['meta'] ) : ?>
					<p><?php esc_html_e( 'No user fields found yet.', 'omnabase' ); ?></p>
				<?php else : ?>
					<div class="ob-form-row ob-form-row--stack">
						<div class="ob-form-row__text"><div class="ob-form-row__label"><?php esc_html_e( 'Fields to send', 'omnabase' ); ?></div></div>
						<div class="ob-form-row__control">
							<fieldset class="ob-wp-settings__meta ob-wp-settings__checklist">
								<legend class="screen-reader-text"><?php esc_html_e( 'User fields', 'omnabase' ); ?></legend>
								<?php foreach ( $omnabase_facts['meta'] as $omnabase_meta ) : ?>
									<label>
										<input type="checkbox" name="omnabase_facts[meta][]" value="<?php echo esc_attr( $omnabase_meta['key'] ); ?>" <?php checked( $omnabase_meta['on'] ); ?>>
										<code><?php echo esc_html( $omnabase_meta['key'] ); ?></code>
									</label>
								<?php endforeach; ?>
							</fieldset>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</details>
	</section>
	<p class="ob-wp-settings__actions"><button type="submit" class="ob-btn ob-btn--primary"><?php esc_html_e( 'Save', 'omnabase' ); ?></button></p>
</form>
