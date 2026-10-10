<?php
/**
 * The Connection tab's closed "Advanced" block: how the stored connection is protected, and the line to add when that protection is weak.
 * Included by the Connection tab, last.
 *
 * @package OmnaBase
 *
 * @var array{keySource:string} $omnabase_health
 */

defined( 'ABSPATH' ) || exit;
?>
<details class="ob-wp-settings__advanced">
	<summary><?php esc_html_e( 'Advanced', 'omnabase' ); ?></summary>
	<?php if ( 'database' === $omnabase_health['keySource'] ) : ?>
		<p class="ob-wp-settings__keysource" data-key-source="database">
			<?php esc_html_e( 'The stored connection is locked with a key kept in your database, which protects it little. Add this line to wp-config.php, then click Connect again:', 'omnabase' ); ?>
		</p>
		<p><code><?php echo esc_html( "define( 'OMNABASE_ENCRYPTION_KEY', '" . base64_encode( random_bytes( 32 ) ) . "' );" ); ?></code></p>
	<?php else : ?>
		<p class="description ob-wp-settings__keysource" data-key-source="<?php echo esc_attr( $omnabase_health['keySource'] ); ?>">
			<?php echo esc_html( 'constant' === $omnabase_health['keySource'] ? __( 'The stored connection is locked with OMNABASE_ENCRYPTION_KEY from wp-config.php.', 'omnabase' ) : __( 'The stored connection is locked with the secret keys in wp-config.php.', 'omnabase' ) ); ?>
		</p>
	<?php endif; ?>
</details>
