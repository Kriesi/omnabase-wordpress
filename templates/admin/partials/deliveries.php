<?php
/**
 * Settings › OmnaBase: "What OmnaBase sent here", the last 20 calls OmnaBase made to this site, each event in words (never content).
 * Included by the Activity tab.
 *
 * @package OmnaBase
 *
 * @var array<int,array{at:int,event:string,delivery:string,ticket:?int,outcome:string}> $omnabase_deliveries
 */

defined( 'ABSPATH' ) || exit;

$omnabase_events   = array(
	'message.sent'    => __( 'A reply was sent', 'omnabase' ),
	'ticket.updated'  => __( 'A ticket changed', 'omnabase' ),
	'ticket.resolved' => __( 'A ticket was resolved', 'omnabase' ),
	'ticket.created'  => __( 'A ticket was opened', 'omnabase' ),
	'ping'            => __( 'Test', 'omnabase' ),
);
$omnabase_outcomes = array(
	'handled' => __( 'Handled', 'omnabase' ),
	'repeat'  => __( 'Repeat, answered without acting', 'omnabase' ),
	'failed'  => __( 'Failed, OmnaBase retries', 'omnabase' ),
	'refused' => __( 'Not done yet (topic gone, nobody chosen to answer on the forum, or not saved), OmnaBase retries', 'omnabase' ),
);
?>
<section class="ob-form__section ob-wp-settings__deliveries">
	<div class="ob-form__head"><h2 class="ob-form__title"><?php esc_html_e( 'What OmnaBase sent here', 'omnabase' ); ?></h2></div>
	<div class="ob-form__intro"><?php esc_html_e( 'The last 20 calls OmnaBase made to this site, newest first.', 'omnabase' ); ?></div>
	<?php if ( array() === $omnabase_deliveries ) : ?>
		<p><?php esc_html_e( 'Nothing yet.', 'omnabase' ); ?></p>
	<?php else : ?>
		<div class="ob-dtable-card ob-wp-settings__table">
			<table class="ob-dtable">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Time', 'omnabase' ); ?></th>
						<th scope="col"><?php esc_html_e( 'What happened', 'omnabase' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Ticket', 'omnabase' ); ?></th>
						<th scope="col"><?php esc_html_e( 'What the plugin did', 'omnabase' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $omnabase_deliveries as $omnabase_row ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $omnabase_row['at'] ) ); ?></td>
						<td><?php echo esc_html( $omnabase_events[ $omnabase_row['event'] ] ?? __( 'Something else', 'omnabase' ) ); ?></td>
						<td><?php echo esc_html( null !== $omnabase_row['ticket'] ? '#' . $omnabase_row['ticket'] : '—' ); ?></td>
						<td><?php echo esc_html( $omnabase_outcomes[ $omnabase_row['outcome'] ] ?? $omnabase_row['outcome'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>
