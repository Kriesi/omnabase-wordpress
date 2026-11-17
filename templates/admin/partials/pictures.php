<?php
/**
 * Settings › OmnaBase: "Pictures in forum replies", the last 20 lines about pictures of OmnaBase's answers that were saved later or left
 * out, each with the ticket and the reason in words (never the picture). Included by the Activity tab (the forum reply pictures round).
 *
 * @package OmnaBase
 *
 * @var array<int,array{at:int,ticket:int,outcome:string,reason:string,count:int}> $omnabase_pictures
 */

defined( 'ABSPATH' ) || exit;

$omnabase_picture_reasons = array(
	'type'   => __( 'not a JPEG, PNG, GIF or WebP picture', 'omnabase' ),
	'size'   => __( 'larger than 10 MB', 'omnabase' ),
	'gone'   => __( 'OmnaBase no longer has it', 'omnabase' ),
	'upload' => __( 'the upload folder cannot be written', 'omnabase' ),
	'time'   => __( 'OmnaBase did not answer in time', 'omnabase' ),
	'edited' => __( 'the reply was edited on the site meanwhile', 'omnabase' ),
);
?>
<?php if ( array() !== $omnabase_pictures ) : ?>
<section class="ob-form__section ob-wp-settings__pictures">
	<div class="ob-form__head"><h2 class="ob-form__title"><?php esc_html_e( 'Pictures in forum replies', 'omnabase' ); ?></h2></div>
	<div class="ob-form__intro"><?php esc_html_e( 'Pictures of OmnaBase\'s answers that were saved later or left out, newest first.', 'omnabase' ); ?></div>
	<div class="ob-dtable-card ob-wp-settings__table">
		<table class="ob-dtable">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Time', 'omnabase' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Ticket', 'omnabase' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What happened', 'omnabase' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $omnabase_pictures as $omnabase_row ) : ?>
				<tr>
					<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $omnabase_row['at'] ) ); ?></td>
					<td><?php echo esc_html( '#' . (int) $omnabase_row['ticket'] ); ?></td>
					<td>
						<?php
						if ( 'later' === $omnabase_row['outcome'] ) {
							/* translators: %d: how many pictures. */
							echo esc_html( sprintf( _n( '%d picture saved later', '%d pictures saved later', (int) $omnabase_row['count'], 'omnabase' ), (int) $omnabase_row['count'] ) );
						} else {
							/* translators: 1: how many pictures, 2: the reason in words. */
							echo esc_html( sprintf( _n( '%1$d picture left out: %2$s', '%1$d pictures left out: %2$s', (int) $omnabase_row['count'], 'omnabase' ), (int) $omnabase_row['count'], $omnabase_picture_reasons[ $omnabase_row['reason'] ] ?? $omnabase_picture_reasons['time'] ) );
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>
<?php endif; ?>
