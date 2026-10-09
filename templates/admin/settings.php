<?php
/**
 * Settings › OmnaBase: the screen's markup. Rendered by SettingsScreen::render() with `$view`.
 *
 * @package OmnaBase
 *
 * @var array{version:string,apiUrl:?string,appUrl:?string,notice:?array{type:string,message:string},connection:array<string,mixed>,health:array<string,mixed>,forum:array<string,mixed>,portal:array<string,mixed>,facts:array<string,mixed>,outbox:array<string,mixed>} $view
 */

defined( 'ABSPATH' ) || exit;

$omnabase_connection = $view['connection'];
$omnabase_health     = $view['health'];
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
	<?php if ( null !== $view['appUrl'] ) : ?>
		<div class="notice notice-warning inline ob-wp-settings__dev">
			<p>
				<?php
				/* translators: %s: the development address of OmnaBase's connect page. */
				echo esc_html( sprintf( __( 'Development: Connect opens %s instead of app.omnabase.com.', 'omnabase' ), $view['appUrl'] ) );
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( null !== $view['notice'] ) : ?>
		<div class="notice notice-<?php echo esc_attr( $view['notice']['type'] ); ?> inline ob-wp-settings__notice">
			<p><?php echo esc_html( $view['notice']['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Connection', 'omnabase' ); ?></h2>
	<?php require __DIR__ . '/partials/connection.php'; ?>

	<?php if ( 'not_connected' !== $omnabase_connection['state'] ) : ?>
		<h2><?php esc_html_e( 'Health', 'omnabase' ); ?></h2>
		<?php require __DIR__ . '/partials/health.php'; ?>
	<?php endif; ?>

	<?php
	$omnabase_forum  = $view['forum'];
	$omnabase_facts  = $view['facts'];
	$omnabase_outbox = $view['outbox'];
	$omnabase_portal = $view['portal'];
	require __DIR__ . '/partials/forum.php';
	require __DIR__ . '/partials/portal.php';
	require __DIR__ . '/partials/facts.php';
	require __DIR__ . '/partials/outbox.php';
	require __DIR__ . '/partials/deliveries.php';
	?>

	<p class="description ob-wp-settings__version">
		<?php
		/* translators: %s: the plugin's version. */
		echo esc_html( sprintf( __( 'OmnaBase for WordPress %s', 'omnabase' ), $view['version'] ) );
		?>
	</p>
</div>
