<?php
/**
 * Settings › OmnaBase: the last 20 webhook deliveries received (never content). Included by settings.php.
 *
 * @package OmnaBase
 *
 * @var array{deliveries:array<int,array{at:int,event:string,delivery:string,ticket:?int,outcome:string}>} $omnabase_health
 */

defined( 'ABSPATH' ) || exit;

if ( array() === $omnabase_health['deliveries'] ) {
	return;
}
$omnabase_outcomes = array(
	'handled' => __( 'Handled', 'omnabase' ),
	'repeat'  => __( 'Repeat, answered without acting', 'omnabase' ),
	'failed'  => __( 'Failed, OmnaBase retries', 'omnabase' ),
	'refused' => __( 'Not done yet (topic gone, no support user, or not saved), OmnaBase retries', 'omnabase' ),
);
?>
<h2><?php esc_html_e( 'Webhook deliveries', 'omnabase' ); ?></h2>
<p class="description"><?php esc_html_e( 'The last 20 calls OmnaBase made to this site, newest first.', 'omnabase' ); ?></p>
<table class="widefat striped ob-wp-settings__deliveries">
	<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'Time', 'omnabase' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Event', 'omnabase' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Ticket', 'omnabase' ); ?></th>
			<th scope="col"><?php esc_html_e( 'What the plugin did', 'omnabase' ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php foreach ( $omnabase_health['deliveries'] as $omnabase_row ) : ?>
		<tr>
			<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $omnabase_row['at'] ) ); ?></td>
			<td><code><?php echo esc_html( $omnabase_row['event'] ); ?></code></td>
			<td><?php echo esc_html( null !== $omnabase_row['ticket'] ? '#' . $omnabase_row['ticket'] : '—' ); ?></td>
			<td><?php echo esc_html( $omnabase_outcomes[ $omnabase_row['outcome'] ] ?? $omnabase_row['outcome'] ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
