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
?>
<form method="post" action="<?php echo esc_url( $omnabase_action_url ); ?>" class="ob-wp-settings__form">
	<input type="hidden" name="action" value="omnabase_connect">
	<?php wp_nonce_field( 'omnabase_connect' ); ?>
	<?php
	$omnabase_again = 'secondary' === ( $omnabase_connect_style ?? 'primary' );
	submit_button( __( 'Connect', 'omnabase' ), $omnabase_again ? 'secondary' : 'primary', 'submit', false, $omnabase_again ? array( 'title' => __( 'Connect again: OmnaBase gives the site a new key and stops the old one.', 'omnabase' ) ) : null );
	?>
</form>
