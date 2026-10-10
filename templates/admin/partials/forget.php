<?php
/**
 * "Forget this connection", in the "Disconnected in OmnaBase" state: Disconnect's own action, worded for a connection OmnaBase already ended
 * (the site makes no call with the stopped key and clears what it kept). Included by the connection section.
 *
 * @package OmnaBase
 *
 * @var string $omnabase_action_url The admin-post address.
 */

defined( 'ABSPATH' ) || exit;
?>
<form method="post" action="<?php echo esc_url( $omnabase_action_url ); ?>" class="ob-wp-settings__form">
	<input type="hidden" name="action" value="omnabase_disconnect">
	<?php wp_nonce_field( 'omnabase_disconnect' ); ?>
	<button type="submit" class="ob-btn ob-btn--outline"><?php esc_html_e( 'Forget this connection', 'omnabase' ); ?></button>
</form>
