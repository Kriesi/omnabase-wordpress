<?php
/**
 * Settings › OmnaBase: the forum section (a tick per forum, who answers on the forum, the line under each answer, who may send topics,
 * closing on resolve). Included by the Forums tab.
 *
 * @package OmnaBase
 *
 * @var array{active:bool,forums:array<int,array{id:int,title:string,visibility:string,public:bool,marked:bool}>,moderators:array<int,array{id:int,name:string}>,supportUser:int,closingLine:string,guests:bool,closeResolved:bool} $omnabase_forum
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="ob-wp-settings__forum">
	<h2><?php esc_html_e( 'Forums', 'omnabase' ); ?></h2>
	<?php if ( ! $omnabase_forum['active'] ) : ?>
		<p class="description"><?php esc_html_e( 'Install and activate bbPress to send forum topics to OmnaBase.', 'omnabase' ); ?></p>
	<?php else : ?>
		<p class="description"><?php esc_html_e( 'Topics in the ticked forums become tickets, and your team\'s answers appear on the forum under the name you choose below.', 'omnabase' ); ?></p>
		<ul class="ob-wp-settings__forum-rules">
			<li><?php esc_html_e( 'Topics in hidden forums are not public; every other forum is public to OmnaBase.', 'omnabase' ); ?></li>
			<li><?php esc_html_e( 'Replies by the topic\'s author and by moderators are sent.', 'omnabase' ); ?></li>
			<li><?php esc_html_e( 'Edits and deletions on the forum are not sent.', 'omnabase' ); ?></li>
		</ul>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="omnabase_save_forum">
			<?php wp_nonce_field( 'omnabase_save_forum' ); ?>
			<?php if ( array() === $omnabase_forum['forums'] ) : ?>
				<p><?php esc_html_e( 'No forums yet.', 'omnabase' ); ?></p>
			<?php else : ?>
				<fieldset class="ob-wp-settings__forums">
					<legend class="screen-reader-text"><?php esc_html_e( 'Forums', 'omnabase' ); ?></legend>
					<?php foreach ( $omnabase_forum['forums'] as $omnabase_item ) : ?>
						<label>
							<input type="checkbox" name="omnabase_forum[forums][]" value="<?php echo esc_attr( (string) $omnabase_item['id'] ); ?>" <?php checked( $omnabase_item['marked'] ); ?>>
							<?php echo esc_html( $omnabase_item['title'] ); ?>
							<span class="description">
								<?php echo esc_html( $omnabase_item['public'] ? __( '(public)', 'omnabase' ) : __( '(hidden: not public)', 'omnabase' ) ); ?>
							</span>
						</label><br>
					<?php endforeach; ?>
				</fieldset>
			<?php endif; ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="omnabase-support-user"><?php esc_html_e( 'Who answers on the forum', 'omnabase' ); ?></label></th>
					<td>
						<select id="omnabase-support-user" name="omnabase_forum[support_user]">
							<option value=""><?php esc_html_e( '— Choose a moderator —', 'omnabase' ); ?></option>
							<?php foreach ( $omnabase_forum['moderators'] as $omnabase_moderator ) : ?>
								<option value="<?php echo esc_attr( (string) $omnabase_moderator['id'] ); ?>" <?php selected( $omnabase_moderator['id'], $omnabase_forum['supportUser'] ); ?>><?php echo esc_html( $omnabase_moderator['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'The forum moderator whose name your team\'s answers appear under.', 'omnabase' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="omnabase-closing-line"><?php esc_html_e( 'Line under each answer', 'omnabase' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="omnabase-closing-line" name="omnabase_forum[closing_line]" value="<?php echo esc_attr( $omnabase_forum['closingLine'] ); ?>">
						<p class="description"><?php esc_html_e( '{agent} becomes the team member\'s name and {workspace} your workspace\'s.', 'omnabase' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Who may send topics', 'omnabase' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Who may send topics', 'omnabase' ); ?></legend>
							<label>
								<input type="radio" name="omnabase_forum[guests]" value="0" <?php checked( ! $omnabase_forum['guests'] ); ?>>
								<?php esc_html_e( 'Members only', 'omnabase' ); ?>
							</label><br>
							<label>
								<input type="radio" name="omnabase_forum[guests]" value="1" <?php checked( $omnabase_forum['guests'] ); ?>>
								<?php esc_html_e( 'Guests too', 'omnabase' ); ?>
							</label>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Guests\' email addresses are unchecked: anyone can type any address.', 'omnabase' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Resolved tickets', 'omnabase' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="omnabase_forum[close_resolved]" value="1" <?php checked( $omnabase_forum['closeResolved'] ); ?>>
							<?php esc_html_e( 'Close the topic when its ticket is resolved', 'omnabase' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<?php if ( 0 === $omnabase_forum['supportUser'] ) : ?>
				<p class="ob-wp-settings__forum-warning"><?php esc_html_e( 'Choose who answers on the forum: until then your team\'s answers wait at OmnaBase.', 'omnabase' ); ?></p>
			<?php endif; ?>
			<?php submit_button( __( 'Save', 'omnabase' ), 'primary', 'omnabase_forum_submit', true ); ?>
		</form>
	<?php endif; ?>
</div>
