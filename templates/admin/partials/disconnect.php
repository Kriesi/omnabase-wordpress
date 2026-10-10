<?php
/**
 * The Disconnect button. Included by the connection section.
 *
 * @package OmnaBase
 *
 * @var string $omnabase_action_url The admin-post address.
 */

defined( 'ABSPATH' ) || exit;
?>
<form method="post" action="<?php echo esc_url( $omnabase_action_url ); ?>" class="ob-wp-settings__form" onsubmit="return window.confirm('<?php echo esc_js( __( 'Disconnect this site from OmnaBase? OmnaBase stops its key at once.', 'omnabase' ) ); ?>');">
	<input type="hidden" name="action" value="omnabase_disconnect">
	<?php wp_nonce_field( 'omnabase_disconnect' ); ?>
	<button type="submit" class="ob-btn ob-btn--outline"><?php esc_html_e( 'Disconnect', 'omnabase' ); ?></button>
</form>
