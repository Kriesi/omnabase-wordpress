<?php
/**
 * The forum card's settings, each a row of the kit's (the label and its help on the left, the field on the right): the forums to send,
 * who answers on the forum, the line under each answer, who may send topics, closing on resolve. Included by the forum card.
 *
 * @package OmnaBase
 *
 * @var array{forums:array<int,array{id:int,title:string,public:bool,marked:bool}>,moderators:array<int,array{id:int,name:string}>,supportUser:int,closingLine:string,guests:bool,closeResolved:bool} $omnabase_forum
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="ob-form-row ob-form-row--stack">
	<div class="ob-form-row__text"><div class="ob-form-row__label"><?php esc_html_e( 'Forums to send', 'omnabase' ); ?></div></div>
	<div class="ob-form-row__control">
		<?php if ( array() === $omnabase_forum['forums'] ) : ?>
			<p><?php esc_html_e( 'No forums yet.', 'omnabase' ); ?></p>
		<?php else : ?>
			<fieldset class="ob-wp-settings__forums ob-wp-settings__checklist">
				<legend class="screen-reader-text"><?php esc_html_e( 'Forums', 'omnabase' ); ?></legend>
				<?php foreach ( $omnabase_forum['forums'] as $omnabase_item ) : ?>
					<label>
						<input type="checkbox" name="omnabase_forum[forums][]" value="<?php echo esc_attr( (string) $omnabase_item['id'] ); ?>" <?php checked( $omnabase_item['marked'] ); ?>>
						<?php echo esc_html( $omnabase_item['title'] ); ?>
						<span class="description">
							<?php echo esc_html( $omnabase_item['public'] ? __( '(public)', 'omnabase' ) : __( '(hidden: not public)', 'omnabase' ) ); ?>
						</span>
					</label>
				<?php endforeach; ?>
			</fieldset>
		<?php endif; ?>
	</div>
</div>
<div class="ob-form-row">
	<div class="ob-form-row__text">
		<label class="ob-form-row__label" for="omnabase-support-user"><?php esc_html_e( 'Who answers on the forum', 'omnabase' ); ?></label>
		<div class="ob-form-row__help"><?php esc_html_e( 'The forum moderator whose name your team\'s answers appear under.', 'omnabase' ); ?></div>
	</div>
	<div class="ob-form-row__control">
		<select id="omnabase-support-user" name="omnabase_forum[support_user]">
			<option value=""><?php esc_html_e( '— Choose a moderator —', 'omnabase' ); ?></option>
			<?php foreach ( $omnabase_forum['moderators'] as $omnabase_moderator ) : ?>
				<option value="<?php echo esc_attr( (string) $omnabase_moderator['id'] ); ?>" <?php selected( $omnabase_moderator['id'], $omnabase_forum['supportUser'] ); ?>><?php echo esc_html( $omnabase_moderator['name'] ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>
</div>
<div class="ob-form-row">
	<div class="ob-form-row__text">
		<label class="ob-form-row__label" for="omnabase-closing-line"><?php esc_html_e( 'Line under each answer', 'omnabase' ); ?></label>
		<div class="ob-form-row__help"><?php esc_html_e( '{agent} becomes the team member\'s name and {workspace} your workspace\'s.', 'omnabase' ); ?></div>
	</div>
	<div class="ob-form-row__control">
		<input type="text" id="omnabase-closing-line" name="omnabase_forum[closing_line]" value="<?php echo esc_attr( $omnabase_forum['closingLine'] ); ?>">
	</div>
</div>
<div class="ob-form-row">
	<div class="ob-form-row__text">
		<div class="ob-form-row__label" id="omnabase-guests-label"><?php esc_html_e( 'Who may send topics', 'omnabase' ); ?></div>
		<div class="ob-form-row__help"><?php esc_html_e( 'Guests\' email addresses are unchecked: anyone can type any address.', 'omnabase' ); ?></div>
	</div>
	<div class="ob-form-row__control">
		<fieldset class="ob-wp-settings__choices" aria-labelledby="omnabase-guests-label">
			<label>
				<input type="radio" name="omnabase_forum[guests]" value="0" <?php checked( ! $omnabase_forum['guests'] ); ?>>
				<?php esc_html_e( 'Members only', 'omnabase' ); ?>
			</label>
			<label>
				<input type="radio" name="omnabase_forum[guests]" value="1" <?php checked( $omnabase_forum['guests'] ); ?>>
				<?php esc_html_e( 'Guests too', 'omnabase' ); ?>
			</label>
		</fieldset>
	</div>
</div>
<div class="ob-form-row">
	<div class="ob-form-row__text">
		<label class="ob-form-row__label" for="omnabase-close-resolved"><?php esc_html_e( 'Resolved tickets', 'omnabase' ); ?></label>
		<div class="ob-form-row__help"><?php esc_html_e( 'Close the topic when its ticket is resolved', 'omnabase' ); ?></div>
	</div>
	<div class="ob-form-row__control">
		<input type="checkbox" role="switch" class="ob-switch" id="omnabase-close-resolved" name="omnabase_forum[close_resolved]" value="1" <?php checked( $omnabase_forum['closeResolved'] ); ?>>
	</div>
</div>
