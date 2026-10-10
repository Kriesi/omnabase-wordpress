<?php
/**
 * The setup line: each step a link to its tab, ticked once done, and Dismiss. Included by the Connection tab.
 *
 * @package OmnaBase
 *
 * @var array{steps:array<int,array{step:string,label:string,url:string,done:bool}>} $omnabase_setup
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="notice notice-info inline ob-wp-settings__setup">
	<p>
		<strong><?php esc_html_e( 'Set up:', 'omnabase' ); ?></strong>
		<?php foreach ( $omnabase_setup['steps'] as $omnabase_i => $omnabase_step ) : ?>
			<?php echo 0 < $omnabase_i ? ' · ' : ''; ?>
			<span data-step="<?php echo esc_attr( $omnabase_step['step'] ); ?>" data-done="<?php echo $omnabase_step['done'] ? '1' : '0'; ?>">
				<?php
				/* translators: %s: a setup step, such as "Customer data". */
				$omnabase_aria = sprintf( __( 'Setup step: %s', 'omnabase' ), $omnabase_step['label'] );
				?>
				<a href="<?php echo esc_url( $omnabase_step['url'] ); ?>" aria-label="<?php echo esc_attr( $omnabase_aria ); ?>"><?php echo esc_html( $omnabase_step['label'] ); ?></a>
				<?php if ( $omnabase_step['done'] ) : ?>
					<span aria-hidden="true">✓</span><span class="screen-reader-text"><?php esc_html_e( '(done)', 'omnabase' ); ?></span>
				<?php endif; ?>
			</span>
		<?php endforeach; ?>
	</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ob-wp-settings__form">
		<input type="hidden" name="action" value="omnabase_dismiss_setup">
		<?php wp_nonce_field( 'omnabase_dismiss_setup' ); ?>
		<p><button type="submit" class="button-link"><?php esc_html_e( 'Dismiss', 'omnabase' ); ?></button></p>
	</form>
</div>
