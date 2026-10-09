<?php
/**
 * Settings › OmnaBase: the customer-data section (a switch per detected source, the usermeta picker). Included by settings.php.
 *
 * @package OmnaBase
 *
 * @var array{sources:array<int,array{id:string,label:string,on:bool,unsupported:string}>,meta:array<int,array{key:string,on:bool}>,last:?array} $omnabase_facts
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="ob-wp-settings__facts">
	<h2><?php esc_html_e( 'Customer data', 'omnabase' ); ?></h2>
	<p class="description"><?php esc_html_e( 'What this site tells your team about a customer when a ticket opens; every source stays off until you turn it on.', 'omnabase' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="omnabase_save_facts">
		<?php wp_nonce_field( 'omnabase_save_facts' ); ?>
		<fieldset class="ob-wp-settings__sources">
			<legend class="screen-reader-text"><?php esc_html_e( 'Sources', 'omnabase' ); ?></legend>
			<?php foreach ( $omnabase_facts['sources'] as $omnabase_source ) : ?>
				<label>
					<input type="checkbox" name="omnabase_facts[sources][]" value="<?php echo esc_attr( $omnabase_source['id'] ); ?>" <?php checked( $omnabase_source['on'] ); ?>>
					<?php echo esc_html( $omnabase_source['label'] ); ?>
					<?php if ( '' !== $omnabase_source['unsupported'] ) : ?>
						<strong class="ob-wp-settings__unsupported">(<?php echo esc_html( $omnabase_source['unsupported'] ); ?>)</strong>
					<?php endif; ?>
				</label><br>
			<?php endforeach; ?>
		</fieldset>
		<h3><?php esc_html_e( 'User fields', 'omnabase' ); ?></h3>
		<p class="description"><?php esc_html_e( 'The user fields the profile may send; private fields and names like password, token or key are never offered or sent.', 'omnabase' ); ?></p>
		<?php if ( array() === $omnabase_facts['meta'] ) : ?>
			<p><?php esc_html_e( 'No user fields found yet.', 'omnabase' ); ?></p>
		<?php else : ?>
			<fieldset class="ob-wp-settings__meta">
				<legend class="screen-reader-text"><?php esc_html_e( 'User fields', 'omnabase' ); ?></legend>
				<?php foreach ( $omnabase_facts['meta'] as $omnabase_meta ) : ?>
					<label>
						<input type="checkbox" name="omnabase_facts[meta][]" value="<?php echo esc_attr( $omnabase_meta['key'] ); ?>" <?php checked( $omnabase_meta['on'] ); ?>>
						<code><?php echo esc_html( $omnabase_meta['key'] ); ?></code>
					</label><br>
				<?php endforeach; ?>
			</fieldset>
		<?php endif; ?>
		<?php submit_button( __( 'Save customer data settings', 'omnabase' ), 'primary', 'submit', true ); ?>
	</form>
	<?php if ( null !== $omnabase_facts['last'] && array() !== $omnabase_facts['last']['dropped'] ) : ?>
		<p class="ob-wp-settings__facts-dropped">
			<?php
			/* translators: 1: date and time, 2: the sections left out, comma-separated. */
			echo esc_html( sprintf( __( 'The answer at %1$s left out: %2$s (too large or not readable by OmnaBase).', 'omnabase' ), wp_date( 'Y-m-d H:i', (int) $omnabase_facts['last']['at'] ), implode( ', ', $omnabase_facts['last']['dropped'] ) ) );
			?>
		</p>
	<?php endif; ?>
</div>
