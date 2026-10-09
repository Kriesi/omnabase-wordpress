<?php
/**
 * Settings › OmnaBase: the outbox section (what waits, what needs attention, Retry and Discard). Included by settings.php.
 *
 * @package OmnaBase
 *
 * @var array{counts:array{waiting:int,attention:int,sent:int},topics:array{count:int,next:int,because:int},rows:array<int,array<string,mixed>>} $omnabase_outbox
 */

defined( 'ABSPATH' ) || exit;

$omnabase_counts = $omnabase_outbox['counts'];
$omnabase_topics = $omnabase_outbox['topics'];
?>
<div class="ob-wp-settings__outbox">
	<h2><?php esc_html_e( 'Outbox', 'omnabase' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Calls to OmnaBase still to make, tried every minute until they go; nothing is dropped without you.', 'omnabase' ); ?></p>
	<ul class="ob-wp-settings__outbox-counts">
		<li>
			<?php
			/* translators: %d: number of calls. */
			echo esc_html( sprintf( _n( '%d waiting', '%d waiting', $omnabase_counts['waiting'], 'omnabase' ), $omnabase_counts['waiting'] ) );
			?>
		</li>
		<li>
			<?php
			/* translators: %d: number of calls. */
			echo esc_html( sprintf( _n( '%d needs attention', '%d need attention', $omnabase_counts['attention'], 'omnabase' ), $omnabase_counts['attention'] ) );
			?>
		</li>
		<li>
			<?php
			/* translators: %d: number of calls. */
			echo esc_html( sprintf( _n( '%d sent today', '%d sent today', $omnabase_counts['sent'], 'omnabase' ), $omnabase_counts['sent'] ) );
			?>
		</li>
	</ul>
	<?php if ( $omnabase_topics['count'] > 0 ) : ?>
		<p class="ob-wp-settings__outbox-topics">
			<?php
			/* translators: %d: number of forum topics. */
			$omnabase_line = sprintf( _n( '%d topic waiting', '%d topics waiting', $omnabase_topics['count'], 'omnabase' ), $omnabase_topics['count'] );
			if ( $omnabase_topics['because'] > 0 ) {
				/* translators: 1: "12 topics waiting", 2: date and time of the next try. */
				$omnabase_line = sprintf( __( '%1$s: OmnaBase\'s daily limit for new tickets, next try %2$s', 'omnabase' ), $omnabase_line, wp_date( 'Y-m-d H:i', $omnabase_topics['next'] ) );
			}
			echo esc_html( $omnabase_line );
			?>
		</p>
	<?php endif; ?>
	<?php if ( array() !== $omnabase_outbox['rows'] ) : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Call', 'omnabase' ); ?></th>
					<th scope="col"><?php esc_html_e( 'About', 'omnabase' ); ?></th>
					<th scope="col"><?php esc_html_e( 'State', 'omnabase' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last answer', 'omnabase' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'omnabase' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $omnabase_outbox['rows'] as $omnabase_row ) : ?>
				<tr>
					<td><?php echo esc_html( $omnabase_row['kind'] ); ?></td>
					<td><?php echo esc_html( $omnabase_row['user'] ); ?></td>
					<td>
						<?php
						echo esc_html(
							'attention' === $omnabase_row['state']
								? __( 'Needs attention', 'omnabase' )
								/* translators: %s: date and time of the next try. */
								: sprintf( __( 'Waiting, next try %s', 'omnabase' ), wp_date( 'Y-m-d H:i', (int) $omnabase_row['nextAt'] ) )
						);
						?>
					</td>
					<td><?php echo esc_html( '' !== $omnabase_row['reason'] ? $omnabase_row['reason'] : '—' ); ?></td>
					<td>
						<?php if ( 'attention' === $omnabase_row['state'] ) : ?>
							<?php foreach ( array( 'omnabase_outbox_retry' => __( 'Retry', 'omnabase' ), 'omnabase_outbox_discard' => __( 'Discard', 'omnabase' ) ) as $omnabase_action => $omnabase_label ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
									<input type="hidden" name="action" value="<?php echo esc_attr( $omnabase_action ); ?>">
									<input type="hidden" name="row" value="<?php echo esc_attr( (string) $omnabase_row['id'] ); ?>">
									<?php wp_nonce_field( $omnabase_action ); ?>
									<button type="submit" class="button button-small"><?php echo esc_html( $omnabase_label ); ?></button>
								</form>
							<?php endforeach; ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
