<?php
/**
 * Settings › OmnaBase: the status block (one plain line from the last "Check connection", the timer warning, and behind the tab's one
 * "Details" what the key may do, its last four, the three directions and the last customer-data call). Included by the Connection tab.
 *
 * @package OmnaBase
 *
 * @var array{state:string,scopes:string[],keyHint:string,liveError:string} $omnabase_connection
 * @var array{checked:bool,at:int,results:array<string,array{result:string,message:string}>,waiting:bool,keySource:string,factsAt:int,cronOff:bool} $omnabase_health
 */

defined( 'ABSPATH' ) || exit;

$omnabase_status = \OmnaBase\WordPress\Admin\StatusLine::of( $omnabase_health );
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
<div class="ob-wp-settings__health" data-status="<?php echo esc_attr( $omnabase_status['tone'] ); ?>">
	<p class="ob-wp-settings__status"><strong><?php echo esc_html( $omnabase_status['words'] ); ?></strong></p>

	<?php if ( $omnabase_health['cronOff'] ) : ?>
		<div class="notice notice-warning inline ob-wp-settings__cron">
			<p><?php esc_html_e( 'Your host turned WordPress\'s timer off: ask them to run it every minute, or messages to OmnaBase wait for visitors.', 'omnabase' ); ?></p>
		</div>
	<?php endif; ?>

	<details class="ob-wp-settings__details">
		<summary><?php esc_html_e( 'Details', 'omnabase' ); ?></summary>
		<?php
		if ( 'connected' === $omnabase_connection['state'] ) {
			require __DIR__ . '/connection-details.php';
		}
		?>
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
		<?php endif; ?>
		<p class="ob-wp-settings__facts-call">
			<?php
			echo esc_html(
				$omnabase_health['factsAt'] > 0
					/* translators: %s: date and time of OmnaBase's last customer-data call. */
					? sprintf( __( 'Last customer-data call from OmnaBase: %s.', 'omnabase' ), wp_date( 'Y-m-d H:i', $omnabase_health['factsAt'] ) )
					: __( 'No customer-data call from OmnaBase yet.', 'omnabase' )
			);
			?>
		</p>
	</details>

	<?php if ( $omnabase_health['waiting'] ) : ?>
		<?php wp_print_inline_script_tag( 'setTimeout(function(){window.location.reload();},3000);' ); ?>
	<?php endif; ?>
</div>
