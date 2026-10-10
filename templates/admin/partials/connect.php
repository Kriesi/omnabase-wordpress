<?php
/**
 * The Connect button. Included by the connection section.
 *
 * @package OmnaBase
 *
 * @var string $omnabase_action_url The admin-post address.
 * @var string $omnabase_connect_style `primary` before the first connect, `secondary` beside a connection (connecting again rotates the key).
 */

defined( 'ABSPATH' ) || exit;

$omnabase_again = 'secondary' === ( $omnabase_connect_style ?? 'primary' );
?>
<form method="post" action="<?php echo esc_url( $omnabase_action_url ); ?>" class="ob-wp-settings__form">
	<input type="hidden" name="action" value="omnabase_connect">
	<?php wp_nonce_field( 'omnabase_connect' ); ?>
	<?php if ( $omnabase_again ) : ?>
		<button type="submit" class="ob-btn ob-btn--outline" title="<?php esc_attr_e( 'Connect again: OmnaBase gives the site a new key and stops the old one.', 'omnabase' ); ?>"><?php esc_html_e( 'Connect', 'omnabase' ); ?></button>
	<?php else : ?>
		<button type="submit" class="ob-btn ob-btn--primary"><?php esc_html_e( 'Connect', 'omnabase' ); ?></button>
	<?php endif; ?>
</form>
