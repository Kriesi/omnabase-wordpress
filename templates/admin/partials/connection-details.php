<?php
/**
 * The connection's part of the Connection tab's "Details": what the key may do (in words), the key's last four, OmnaBase's own words when
 * it did not answer. Included by the status block.
 *
 * @package OmnaBase
 *
 * @var array{scopes:string[],keyHint:string,liveError:string} $omnabase_connection
 */

defined( 'ABSPATH' ) || exit;
?>
	<p><?php esc_html_e( 'What this site may do in OmnaBase:', 'omnabase' ); ?></p>
	<ul class="ob-help-list ob-wp-settings__scopes">
		<?php foreach ( $omnabase_connection['scopes'] as $omnabase_scope ) : ?>
			<li class="ob-help-list__item"><?php echo esc_html( $omnabase_scope ); ?></li>
		<?php endforeach; ?>
	</ul>
	<p class="ob-wp-settings__key">
		<?php
		/* translators: %s: the key's prefix and last four characters, such as "obw_…7Q2X". */
		echo esc_html( sprintf( __( 'Key: %s (only its last four characters are shown).', 'omnabase' ), $omnabase_connection['keyHint'] ) );
		?>
	</p>
	<?php if ( '' !== $omnabase_connection['liveError'] ) : ?>
		<p><?php echo esc_html( $omnabase_connection['liveError'] ); ?></p>
	<?php endif; ?>
