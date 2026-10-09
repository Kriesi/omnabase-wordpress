<?php
/**
 * Settings › OmnaBase: the connection section (Connect, Disconnect, Check connection). Included by settings.php.
 *
 * @package OmnaBase
 *
 * @var array{state:string,workspace:string,site:string,projects:string[],scopes:string[],keyHint:string,liveError:string,problem:?string} $omnabase_connection
 */

defined( 'ABSPATH' ) || exit;

$omnabase_action_url = admin_url( 'admin-post.php' );
?>
<div class="ob-wp-settings__connection" data-state="<?php echo esc_attr( $omnabase_connection['state'] ); ?>">
	<?php if ( 'connected' === $omnabase_connection['state'] ) : ?>
		<p class="ob-wp-settings__connected">
			<strong>
				<?php
				/* translators: %s: the OmnaBase workspace's name. */
				echo esc_html( sprintf( __( 'Connected to %s', 'omnabase' ), $omnabase_connection['workspace'] ) );
				?>
			</strong>
		</p>
		<?php if ( '' !== $omnabase_connection['site'] ) : ?>
			<p class="description">
				<?php
				/* translators: %s: the site's name in OmnaBase. */
				echo esc_html( sprintf( __( 'This site is known to OmnaBase as %s.', 'omnabase' ), $omnabase_connection['site'] ) );
				?>
			</p>
		<?php endif; ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Project', 'omnabase' ); ?></th>
				<td class="ob-wp-settings__projects"><?php echo esc_html( array() !== $omnabase_connection['projects'] ? implode( ', ', $omnabase_connection['projects'] ) : __( 'Every project', 'omnabase' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Permissions', 'omnabase' ); ?></th>
				<td class="ob-wp-settings__scopes">
					<?php foreach ( $omnabase_connection['scopes'] as $omnabase_scope ) : ?>
						<code><?php echo esc_html( $omnabase_scope ); ?></code>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Key', 'omnabase' ); ?></th>
				<td class="ob-wp-settings__key"><code><?php echo esc_html( $omnabase_connection['keyHint'] ); ?></code></td>
			</tr>
		</table>
		<?php if ( '' !== $omnabase_connection['liveError'] ) : ?>
			<p class="ob-wp-settings__error"><?php echo esc_html( $omnabase_connection['liveError'] ); ?></p>
		<?php endif; ?>
		<div class="ob-wp-settings__buttons">
			<form method="post" action="<?php echo esc_url( $omnabase_action_url ); ?>" class="ob-wp-settings__form">
				<input type="hidden" name="action" value="omnabase_check">
				<?php wp_nonce_field( 'omnabase_check' ); ?>
				<?php submit_button( __( 'Check connection', 'omnabase' ), 'primary', 'submit', false ); ?>
			</form>
			<?php
			$omnabase_connect_style = 'secondary';
			require __DIR__ . '/connect.php';
			require __DIR__ . '/disconnect.php';
			?>
		</div>
	<?php elseif ( 'reconnect' === $omnabase_connection['state'] ) : ?>
		<p class="ob-wp-settings__error"><strong><?php esc_html_e( 'OmnaBase: reconnect needed', 'omnabase' ); ?></strong></p>
		<p class="description"><?php esc_html_e( 'The stored connection can no longer be read because the site\'s secret keys changed. Connect again to receive replies.', 'omnabase' ); ?></p>
		<div class="ob-wp-settings__buttons">
			<?php require __DIR__ . '/connect.php'; ?>
			<?php require __DIR__ . '/disconnect.php'; ?>
		</div>
	<?php else : ?>
		<p><?php esc_html_e( 'This site is not connected to OmnaBase yet.', 'omnabase' ); ?></p>
		<?php if ( null !== $omnabase_connection['problem'] ) : ?>
			<p class="ob-wp-settings__error"><?php echo esc_html( $omnabase_connection['problem'] ); ?></p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Connect sends you to OmnaBase to approve this site once; no key to copy.', 'omnabase' ); ?></p>
			<?php require __DIR__ . '/connect.php'; ?>
		<?php endif; ?>
	<?php endif; ?>
</div>
