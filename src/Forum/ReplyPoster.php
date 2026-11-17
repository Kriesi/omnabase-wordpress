<?php
/**
 * OmnaBase's reply on a topic's ticket appears on the forum as a reply by the member's own moderator account, else by the support user.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

use OmnaBase\WordPress\Connect\ConnectionInfo;
use OmnaBase\WordPress\Look\MessageHtml;
use OmnaBase\WordPress\Options;
use OmnaBase\WordPress\Rest\Refusal;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to the events receiver's `omnabase_webhook` for `message.sent`: a team reply leaving OmnaBase (`direction: out`) on a ticket whose
 * site reference is one of this site's topics (`topic-<id>`). Anything else is answered 200 and posts nothing (a customer's message, a
 * ticket that is not one of our topics, a message the site itself sent). The reply goes through `bbp_insert_reply` (so the topic's
 * freshness, reply count and last reply follow, as bbPress's own form does) by the poster `ReplyAuthor` chooses (the post-as-member round:
 * the moderator with the member's email address, else the support user), always `publish` (on a closed topic
 * bbPress would otherwise store it "closed" and hide it), its HTML through the plugin's fence (`MessageHtml`), the agent's name in the
 * closing line, marked with OmnaBase's message id: one message is one reply, however often it is delivered. The receiver says 2xx only once the reply exists:
 * - the topic trashed, deleted or unpublished → 409 (OmnaBase retries, then mails; never the answer that switches the address off);
 * - nobody can post it (no moderator with the member's address and no support user chosen) → 503 (OmnaBase retries while the
 *   administrator picks one);
 * - WordPress refused to save the reply → 500.
 * The topic's subscribers are mailed afterwards by a job of the plugin's (`SubscriberMails`), never inside OmnaBase's five seconds.
 * The site-fields round: the message's `fields` whose keys are declared now are written as the reply's post meta right after it exists and
 * before it is marked as OmnaBase's (a redelivery that finds the mark posts nothing more), then `omnabase_forum_field_saved` fires.
 * The post-as-member round: the reply also keeps how its author was chosen (`_omnabase_posted_as`: `member` | `support`), and a poster who
 * was subscribed to the topic stays subscribed (bbPress before 2.6.20 drops the poster's subscription inside its insert). Since the support
 * user replies round (2026-11-04) that mark is written in the save itself (`meta_input`, before WordPress's publish moment) and counts as
 * OmnaBase's sign beside the message id, so a posting cut off before its message id never comes back as a forum post.
 * The forum reply mails round (2026-11-03): whether OmnaBase mails the topic's author is decided at posting (`CustomerMail`), and the
 * answer's `posted` is added by `PostedAnswer`; since the customer rating round (2026-11-17) the same moment decides whether OmnaBase may
 * ask the author for a rating (`RatingAsk`), both through `ReplyMarks`.
 * The forum reply pictures round (OmnaBase, 2026-11-17): the pictures OmnaBase lists with the reply are fetched and saved into the media
 * library before the reply is inserted, within a budget (`ReplyPictures`, which also finishes the rest later); the marked paragraphs of the
 * saved pictures become pictures before the fence, which then admits exactly those (`body()`).
 */
final class ReplyPoster {

	/** Whether the plugin is posting OmnaBase's reply right now (the triggers then queue nothing: the echo). */
	private static bool $posting = false;

	/**
	 * Builds the poster.
	 *
	 * @param Settings        $settings   The forum settings.
	 * @param SubscriberMails $mails      The subscriber mails, for later.
	 * @param ConnectionInfo  $connection The workspace's name.
	 * @param PostFields      $fields     The declared post fields.
	 * @param ReplyAuthor     $author     Who posts a reply.
	 * @param ReplyMarks      $marks      Whether OmnaBase mails the topic's author and may ask them for a rating.
	 * @param ReplyPictures   $pictures   The pictures of the reply's text (the forum reply pictures round).
	 */
	public function __construct( private Settings $settings, private SubscriberMails $mails, private ConnectionInfo $connection, private PostFields $fields, private ReplyAuthor $author, private ReplyMarks $marks, private ReplyPictures $pictures ) {}

	/** Whether the plugin is posting OmnaBase's reply right now. */
	public static function isPosting(): bool {
		return self::$posting;
	}

	/**
	 * A signed, new webhook.
	 *
	 * @param string              $type    The event.
	 * @param array<string,mixed> $payload The event as sent.
	 * @throws Refusal When the reply cannot be posted now.
	 */
	public function handle( $type, $payload ): void {
		$started  = ReplyPictures::clockStart();
		$data     = is_array( $payload['data'] ?? null ) ? $payload['data'] : array();
		$message  = is_array( $data['message'] ?? null ) ? $data['message'] : array();
		$topic_id = Posts::topicFromReference( $data['ticket']['external']['id'] ?? null );
		$id       = is_string( $message['id'] ?? null ) ? $message['id'] : '';
		if ( 'message.sent' !== $type || 0 === $topic_id || '' === $id || 'out' !== ( $message['direction'] ?? '' ) || ! empty( $message['external'] ) ) {
			return;
		}
		if ( Posts::postedFor( $id ) > 0 ) {
			return;
		}
		$topic = get_post( $topic_id );
		if ( ! Posts::isTopic( $topic ) || ! in_array( $topic->post_status, Posts::shownTopicStatuses(), true ) ) {
			throw new Refusal( 409, esc_html__( 'The topic of this ticket is no longer on the forum.', 'omnabase' ) );
		}
		$poster = $this->author->of( $message, $topic );
		if ( 0 === $poster ) {
			throw new Refusal( 503, esc_html__( 'No support user is chosen under Settings › OmnaBase, so the reply cannot be posted yet.', 'omnabase' ) );
		}
		$ticket = Posts::ticketOf( $topic->ID );
		$set    = $this->pictures->whilePosting( $message, $ticket, $started );
		$this->mails->later( $this->post( $topic, $poster, $message, $this->workspace( $payload ), $set ) );
	}

	/**
	 * Posts the reply and marks it.
	 *
	 * @param \WP_Post            $topic     The topic.
	 * @param int                 $poster    The user it is posted under.
	 * @param array<string,mixed> $message   OmnaBase's message.
	 * @param string              $workspace The workspace's name.
	 * @param array               $set       The pictures fetched while posting (`ReplyPictures::whilePosting()`).
	 * @return int The reply.
	 * @throws Refusal When WordPress did not save it.
	 */
	private function post( \WP_Post $topic, int $poster, array $message, string $workspace, array $set ): int {
		$closing = $this->closing( $message, $workspace );
		$data    = array(
			'post_parent'  => $topic->ID,
			'post_author'  => $poster,
			'post_title'   => sprintf( /* translators: %s: the topic's title. */ __( 'Reply To: %s', 'omnabase' ), $topic->post_title ),
			'post_content' => self::body( is_string( $message['bodyHtml'] ?? null ) ? $message['bodyHtml'] : null, (string) ( $message['bodyText'] ?? '' ), $set['entries'], $set['saved'] ) . $closing,
			'post_status'  => bbp_get_public_status_id(),
			'meta_input'   => array( Options::POSTED_AS_META => $this->postedAs( $poster ) ), // OmnaBase's sign, there before the publish moment
		);
		$subscribed = bbp_is_user_subscribed( $poster, $topic->ID );
		self::$posting = true;
		try {
			$reply = bbp_insert_reply( $data, array( 'forum_id' => Posts::forumOf( $topic->ID ), 'topic_id' => $topic->ID ) );
		} finally {
			self::$posting = false;
		}
		self::keepSubscribed( $poster, $topic->ID, $subscribed );
		if ( ! is_int( $reply ) || $reply < 1 ) {
			throw new Refusal( 500, esc_html__( 'WordPress did not save the reply; OmnaBase will try again.', 'omnabase' ) );
		}
		PostFields::write( $reply, $message['fields'] ?? null, $this->fields->declared() );
		$this->marks->decide( $reply, $topic ); // the forum reply mails round and the customer rating round: decided once, before the echo mark
		Posts::markPosted( $reply, (string) $message['id'] );
		$this->pictures->afterPosting( $reply, $set, $message, $closing, Posts::ticketOf( $topic->ID ) );
		return $reply;
	}

	/**
	 * How the poster was chosen, for the posted-as mark: the support user, or a member's own moderator account.
	 *
	 * @param int $poster The user the reply is posted under.
	 */
	private function postedAs( int $poster ): string {
		return $poster === $this->settings->supportUser() ? Posts::POSTED_AS_SUPPORT : Posts::POSTED_AS_MEMBER;
	}

	/**
	 * Gives the poster back a subscription to the topic that the insert dropped (bbPress before 2.6.20 does, when the request carries no
	 * "notify me" tick; later versions keep it, and this does nothing).
	 *
	 * @param int  $poster     The user the reply was posted under.
	 * @param int  $topic_id   The topic.
	 * @param bool $subscribed Whether they were subscribed before the insert.
	 */
	private static function keepSubscribed( int $poster, int $topic_id, bool $subscribed ): void {
		if ( $subscribed && ! bbp_is_user_subscribed( $poster, $topic_id ) ) {
			bbp_add_user_subscription( $poster, $topic_id );
		}
	}

	/**
	 * The reply's body: OmnaBase's HTML with the marked paragraphs of the saved pictures as those pictures (every other mark as its words),
	 * through the plugin's fence, which keeps only those pictures (no other picture, inline style or class; its text when it has none). Also
	 * the later picture job's, which puts late pictures into the posted reply.
	 *
	 * @param string|null                                                  $html    OmnaBase's HTML.
	 * @param string                                                       $text    OmnaBase's text.
	 * @param array<int,array{id:string,name:string,size:int,width:?int}> $entries The listed pictures.
	 * @param array<string,int>                                            $saved   The saved pictures' attachments by OmnaBase's file id.
	 */
	public static function body( ?string $html, string $text, array $entries, array $saved ): string {
		$addresses = array_filter( array_map( static fn ( int $attachment ) => (string) wp_get_attachment_url( $attachment ), $saved ) );
		$placed    = null !== $html ? PictureMarks::place( $html, $entries, $addresses ) : null;
		return MessageHtml::forReply( $placed, $text, array_values( $addresses ) );
	}

	/**
	 * The closing line as one paragraph after the body, its lines joined by `<br>` (no raw line break inside, so a theme's automatic
	 * paragraphs cannot double it), each line escaped, empty lines left out.
	 *
	 * @param array<string,mixed> $message   OmnaBase's message.
	 * @param string              $workspace The workspace's name.
	 */
	private function closing( array $message, string $workspace ): string {
		$ai   = 'ai' === ( $message['kind'] ?? null ) && '' !== trim( $workspace );
		$line = strtr( self::template( $this->settings->closingLine(), $ai ), array( '{agent}' => self::agent( $message, $workspace, $ai ), '{workspace}' => $workspace ) );
		return "\n\n<p>" . self::lines( $line ) . '</p>';
	}

	/**
	 * Who `{agent}` names (the polish round, the founder's decision of 2026-10-31): the AI's own answer signs as "The <workspace> team", a
	 * person's answer by the name OmnaBase sends (their customer-facing name), else "the team".
	 *
	 * @param array<string,mixed> $message   OmnaBase's message.
	 * @param string              $workspace The workspace's name.
	 * @param bool                $ai        Whether the AI wrote it (and the workspace has a name).
	 */
	private static function agent( array $message, string $workspace, bool $ai ): string {
		if ( $ai ) {
			/* translators: %s: the workspace's name, e.g. "Kriesi". */
			return sprintf( __( 'The %s team', 'omnabase' ), trim( $workspace ) );
		}
		return is_string( $message['author']['name'] ?? null ) && '' !== trim( $message['author']['name'] ) ? trim( $message['author']['name'] ) : __( 'the team', 'omnabase' );
	}

	/**
	 * The closing line's template for this answer: for the AI's own answer a ", {workspace}" right after `{agent}` is dropped, since the agent
	 * text already names the workspace ("Best regards / The Kriesi team", never "The Kriesi team, Kriesi").
	 *
	 * @param string $template The closing line with its placeholders.
	 * @param bool   $ai       Whether the AI wrote it.
	 */
	private static function template( string $template, bool $ai ): string {
		return $ai ? str_replace( '{agent}, {workspace}', '{agent}', $template ) : $template;
	}

	/**
	 * A closing line's lines, each escaped, joined by `<br>`, empty lines left out.
	 *
	 * @param string $line The closing line with its placeholders filled.
	 */
	private static function lines( string $line ): string {
		$lines = array_filter( array_map( 'trim', preg_split( '/\R/u', $line ) ), 'strlen' );
		return implode( '<br>', array_map( 'esc_html', $lines ) );
	}

	/**
	 * The workspace's name: the event's, else the connection's.
	 *
	 * @param array<string,mixed> $payload The event.
	 */
	private function workspace( array $payload ): string {
		$name = is_string( $payload['workspace']['name'] ?? null ) ? trim( $payload['workspace']['name'] ) : '';
		return '' !== $name ? $name : $this->connection->workspaceName();
	}
}
