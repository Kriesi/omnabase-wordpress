<?php
/**
 * The setup line, a card at the top of the Connection tab: its steps as a list, each a link to its tab, ticked once done, and Dismiss beside
 * the heading. Included by the Connection tab.
 *
 * @package OmnaBase
 *
 * @var array{steps:array<int,array{step:string,label:string,url:string,done:bool}>} $omnabase_setup
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="ob-form__section ob-wp-settings__setup">
	<div class="ob-form__head">
		<h2 class="ob-form__title"><?php esc_html_e( 'Set up', 'omnabase' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ob-wp-settings__form ob-wp-settings__dismiss">
			<input type="hidden" name="action" value="omnabase_dismiss_setup">
			<?php wp_nonce_field( 'omnabase_dismiss_setup' ); ?>
			<button type="submit" class="ob-btn ob-btn--outline ob-btn--sm"><?php esc_html_e( 'Dismiss', 'omnabase' ); ?></button>
		</form>
	</div>
	<div class="ob-form__intro"><?php esc_html_e( 'The steps that put OmnaBase to work on this site; each one is ticked once it is done.', 'omnabase' ); ?></div>
	<ol class="ob-wp-settings__steps">
		<?php foreach ( $omnabase_setup['steps'] as $omnabase_step ) : ?>
			<?php
			/* translators: %s: a setup step, such as "Customer data". */
			$omnabase_aria = sprintf( __( 'Setup step: %s', 'omnabase' ), $omnabase_step['label'] );
			?>
			<li class="ob-wp-settings__step" data-step="<?php echo esc_attr( $omnabase_step['step'] ); ?>" data-done="<?php echo $omnabase_step['done'] ? '1' : '0'; ?>">
				<span class="ob-wp-settings__step-mark" aria-hidden="true"><?php echo $omnabase_step['done'] ? '✓' : ''; ?></span>
				<a href="<?php echo esc_url( $omnabase_step['url'] ); ?>" aria-label="<?php echo esc_attr( $omnabase_aria ); ?>"><?php echo esc_html( $omnabase_step['label'] ); ?></a>
				<?php if ( $omnabase_step['done'] ) : ?>
					<span class="screen-reader-text"><?php esc_html_e( '(done)', 'omnabase' ); ?></span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ol>
</section>
