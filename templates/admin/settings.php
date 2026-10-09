<?php
/**
 * Settings › OmnaBase: the screen's markup. Rendered by SettingsScreen::render() with `$view`.
 *
 * @package OmnaBase
 *
 * @var array{version:string,apiUrl:?string,connection:array{state:string,workspace:string,site:string,message:string}} $view
 */

defined( 'ABSPATH' ) || exit;

$omnabase_connection = $view['connection'];
?>
<div class="wrap ob-wp-settings">
	<h1><?php esc_html_e( 'OmnaBase', 'omnabase' ); ?></h1>

	<?php if ( null !== $view['apiUrl'] ) : ?>
		<div class="notice notice-warning inline ob-wp-settings__dev">
			<p>
				<?php
				/* translators: %s: the development address of OmnaBase. */
				echo esc_html( sprintf( __( 'Development: this site talks to %s instead of app.omnabase.com.', 'omnabase' ), $view['apiUrl'] ) );
				?>
			</p>
		</div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Connection', 'omnabase' ); ?></h2>
	<div class="ob-wp-settings__connection" data-state="<?php echo esc_attr( $omnabase_connection['state'] ); ?>">
		<?php if ( 'connected' === $omnabase_connection['state'] ) : ?>
			<p class="ob-wp-settings__connected">
				<?php
				/* translators: %s: the OmnaBase workspace's name. */
				echo esc_html( sprintf( __( 'Connected to %s', 'omnabase' ), $omnabase_connection['workspace'] ) );
				?>
			</p>
			<?php if ( '' !== $omnabase_connection['site'] ) : ?>
				<p class="description">
					<?php
					/* translators: %s: the site's name in OmnaBase. */
					echo esc_html( sprintf( __( 'This site is known to OmnaBase as %s.', 'omnabase' ), $omnabase_connection['site'] ) );
					?>
				</p>
			<?php endif; ?>
		<?php elseif ( 'not_connected' === $omnabase_connection['state'] ) : ?>
			<p><?php echo esc_html( $omnabase_connection['message'] ); ?></p>
			<p class="description"><?php esc_html_e( 'Connecting your site arrives in the next version.', 'omnabase' ); ?></p>
		<?php else : ?>
			<p class="ob-wp-settings__error"><?php echo esc_html( $omnabase_connection['message'] ); ?></p>
		<?php endif; ?>
	</div>

	<p class="description ob-wp-settings__version">
		<?php
		/* translators: %s: the plugin's version. */
		echo esc_html( sprintf( __( 'OmnaBase for WordPress %s', 'omnabase' ), $view['version'] ) );
		?>
	</p>
</div>
