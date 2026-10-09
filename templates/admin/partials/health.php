<?php
/**
 * Settings › OmnaBase: the health section (the last "Check connection", the vault's key). Included by settings.php.
 *
 * @package OmnaBase
 *
 * @var array{checked:bool,at:int,results:array<string,array{result:string,message:string}>,waiting:bool,keySource:string} $omnabase_health
 */

defined( 'ABSPATH' ) || exit;

$omnabase_labels = array(
	'me'       => __( 'This site → OmnaBase', 'omnabase' ),
	'ping'     => __( 'OmnaBase → this site', 'omnabase' ),
	'loopback' => __( 'This site\'s receiver', 'omnabase' ),
);
$omnabase_words  = array(
	'ok'      => __( 'Working', 'omnabase' ),
	'failed'  => __( 'Not working', 'omnabase' ),
	'waiting' => __( 'Waiting', 'omnabase' ),
	'unknown' => __( 'Unknown', 'omnabase' ),
);
?>
<div class="ob-wp-settings__health">
	<?php if ( $omnabase_health['checked'] ) : ?>
		<p class="description">
			<?php
			/* translators: %s: when the last check ran. */
			echo esc_html( sprintf( __( 'Last checked %s.', 'omnabase' ), wp_date( 'Y-m-d H:i', $omnabase_health['at'] ) ) );
			?>
		</p>
		<table class="widefat striped ob-wp-settings__checks">
			<tbody>
			<?php foreach ( $omnabase_health['results'] as $omnabase_check => $omnabase_result ) : ?>
				<tr data-check="<?php echo esc_attr( $omnabase_check ); ?>" data-result="<?php echo esc_attr( $omnabase_result['result'] ); ?>">
					<th scope="row"><?php echo esc_html( $omnabase_labels[ $omnabase_check ] ?? $omnabase_check ); ?></th>
					<td><strong><?php echo esc_html( $omnabase_words[ $omnabase_result['result'] ] ?? $omnabase_result['result'] ); ?></strong></td>
					<td><?php echo esc_html( $omnabase_result['message'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $omnabase_health['waiting'] ) : ?>
			<?php wp_print_inline_script_tag( 'setTimeout(function(){window.location.reload();},3000);' ); ?>
		<?php endif; ?>
	<?php else : ?>
		<p class="description"><?php esc_html_e( 'Check connection tests both directions: this site calling OmnaBase, and OmnaBase calling this site.', 'omnabase' ); ?></p>
	<?php endif; ?>

	<?php if ( 'database' === $omnabase_health['keySource'] ) : ?>
		<p class="ob-wp-settings__keysource" data-key-source="database">
			<?php esc_html_e( 'The encryption key is stored in your database, so encrypting the connection adds little protection. Add this line to wp-config.php, then connect again:', 'omnabase' ); ?>
		</p>
		<p><code><?php echo esc_html( "define( 'OMNABASE_ENCRYPTION_KEY', '" . base64_encode( random_bytes( 32 ) ) . "' );" ); ?></code></p>
	<?php else : ?>
		<p class="description ob-wp-settings__keysource" data-key-source="<?php echo esc_attr( $omnabase_health['keySource'] ); ?>">
			<?php echo esc_html( 'constant' === $omnabase_health['keySource'] ? __( 'The connection is encrypted with OMNABASE_ENCRYPTION_KEY from wp-config.php.', 'omnabase' ) : __( 'The connection is encrypted with the secret keys in wp-config.php.', 'omnabase' ) ); ?>
		</p>
	<?php endif; ?>
</div>
