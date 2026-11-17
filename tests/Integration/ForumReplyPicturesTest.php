<?php
/**
 * The forum reply pictures round (OmnaBase, 2026-11-17), the red team's failing tests: a screenshot the team pasted into its answer shows in
 * the forum post, saved into the site's media library (inside wp-env's tests WordPress with bbPress loaded: the group "forum").
 *
 * Brief (OmnaBase `docs/briefs/forum-reply-pictures-round.md`) Part B, rules 6 to 10; report
 * `docs/reviews/forum-reply-pictures-round-red-team-report.md` in the OmnaBase repository, findings 10 to 22.
 *
 * The words: *media library*, WordPress's store of uploaded files (each one an "attachment" post); *webhook*, OmnaBase's signed call to the
 * site that hands over the team's answer; *the five seconds*, how long OmnaBase waits for the site's answer before it tries again;
 * *background job*, a task WordPress runs shortly after a request (WP-Cron); *the fence*, the plugin's cleaning of OmnaBase's HTML before it
 * is printed; *redirect*, an answer that says "fetch it from this other address instead".
 *
 * Shapes the red team pins (the brief's, plus the ones marked chosen; the builder may rename and tell the red team):
 * - `message.sent`'s message carries `pictures`: `[ { id, name, mime, size, width, url } ]`, and the paragraph of each reads
 *   `<p data-omnabase-picture="<id>">[Picture: <name>]</p>` (OmnaBase's side, `apps/api/src/forumReplyPictures.test.ts`).
 * - The plugin fetches each picture itself from `ApiUrl::publicApi( '/tickets/<number>/messages/<message id>/pictures/<file id>' )`, with the
 *   site's key, no redirect followed, and a download cap of at most 10 MB plus a little (WordPress's `limit_response_size`, so the cap holds
 *   while downloading); never from the entry's `url`, and never with an id that is not OmnaBase's id form (chosen: a UUID).
 * - The saved file is an attachment whose parent is the forum reply, marked with OmnaBase's file id in the post meta `_omnabase_file_id`
 *   (chosen name); its file name ends with the extension of the type its bytes prove, never one from OmnaBase's name.
 * - The reply's paragraph becomes `<p><img src="<the attachment's address>" alt="<name>" width="<width>"></p>` (only those three attributes).
 * - What could not be fetched while posting is fetched by one of the plugin's background jobs (a hook starting `omnabase_`, run here with
 *   `runPluginJobs()`), which puts it into the posted reply; a picture refused for its type or size is never fetched again.
 * - (chosen) The Activity tab says, for the ticket, that a picture was saved later or left out, with the reason in words.
 *
 * Why these fail today: the poster ignores `pictures`, fetches nothing and its fence takes every picture out of a forum reply.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

use OmnaBase\WordPress\Api\ApiUrl;
use OmnaBase\WordPress\Forum\ReplySender;

/**
 * @group forum
 */
final class ForumReplyPicturesTest extends RoundThreeTestCase {

	private const FILE_META = '_omnabase_file_id';
	private const MB        = 1048576;
	// A 1×1 PNG and a 1×1 GIF, the smallest real pictures.
	private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
	private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

	/** @var float seconds each picture call waits before it answers (a slow OmnaBase) */
	private float $delay = 0.0;

	public function set_up(): void {
		parent::set_up();
		$this->delay = 0.0;
	}

	/* ---------- helpers ---------- */

	/** A picture entry as OmnaBase lists it, with a fresh id. */
	private static function picture( string $name, string $mime = 'image/png', int $size = 68, ?int $width = 400 ): array {
		return array( 'id' => wp_generate_uuid4(), 'name' => $name, 'mime' => $mime, 'size' => $size, 'width' => $width, 'url' => 'https://app.omnabase.com/listed-but-never-used' );
	}

	/** The marked paragraph OmnaBase sends where a picture stood. */
	private static function marked( array $p ): string {
		return '<p data-omnabase-picture="' . esc_attr( $p['id'] ) . '">' . esc_html( '[Picture: ' . $p['name'] . ']' ) . '</p>';
	}

	/**
	 * A `message.sent` for a team reply whose text holds these pictures, each after "Step n.".
	 *
	 * @param int    $topic    The topic.
	 * @param string $message  OmnaBase's message id.
	 * @param array  $pictures The `pictures` entries.
	 * @param string $extra    More HTML at the end.
	 */
	private function pictureReply( int $topic, string $message, array $pictures, string $extra = '' ): array {
		$html = '<p>Here is where the button sits:</p>';
		$text = 'Here is where the button sits:';
		foreach ( $pictures as $i => $p ) {
			$html .= self::marked( $p ) . '<p>Step ' . ( $i + 1 ) . '.</p>';
			$text .= "\n\n[Picture: " . $p['name'] . "]\n\nStep " . ( $i + 1 ) . '.';
		}
		$event                        = $this->teamReply( $topic, $message, 'Ada', $html . $extra, $text );
		$event['message']['pictures'] = $pictures;
		return $event;
	}

	/** The address the plugin must build for a picture. */
	private function pictureAddress( int $topic, string $message, string $file ): string {
		return ApiUrl::publicApi( '/tickets/' . (int) get_post_meta( $topic, self::TICKET_META, true ) . '/messages/' . $message . '/pictures/' . $file );
	}

	/**
	 * OmnaBase answers a picture call with these bytes (as WordPress's own transport would: into the file when the call streams, cut at the
	 * call's download cap).
	 *
	 * @param string $id    The file id.
	 * @param string $bytes The bytes.
	 * @param string $type  The content type sent.
	 */
	private function serve( string $id, string $bytes, string $type = 'image/png' ): void {
		$this->answers[ 'GET /pictures/' . $id ] = function ( $args ) use ( $bytes, $type ) {
			if ( $this->delay > 0 ) {
				usleep( (int) ( $this->delay * 1000000 ) );
			}
			$cap  = (int) ( $args['limit_response_size'] ?? 0 );
			$body = $cap > 0 ? substr( $bytes, 0, $cap ) : $bytes;
			$file = null;
			if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
				file_put_contents( (string) $args['filename'], $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- the fake transport writes the stream.
				$file = (string) $args['filename'];
				$body = '';
			}
			return array(
				'headers'  => array( 'content-type' => $type, 'content-length' => (string) strlen( $bytes ) ),
				'body'     => $body,
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => $file,
			);
		};
	}

	/** The picture calls the plugin made, for any file. */
	private function pictureCalls(): array {
		return array_values( array_filter( $this->calls, static fn ( $c ) => str_contains( (string) $c['url'], '/pictures/' ) ) );
	}

	/** The calls to any host other than the pinned API's. */
	private function callsElsewhere(): array {
		$api = (string) wp_parse_url( ApiUrl::base(), PHP_URL_HOST );
		return array_values( array_filter( $this->calls, static fn ( $c ) => (string) wp_parse_url( (string) $c['url'], PHP_URL_HOST ) !== $api ) );
	}

	/** The media library's attachments marked with an OmnaBase file id. */
	private static function savedFor( string $file ): array {
		return get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => self::FILE_META, 'meta_value' => $file, 'suppress_filters' => true ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	/** The one forum reply on a topic. */
	private function onlyReply( int $topic ): \WP_Post {
		$replies = self::repliesOn( $topic );
		$this->assertCount( 1, $replies, 'one forum reply' );
		return $replies[0];
	}

	/** The pictures of a reply's stored content as [ src, alt, width, attribute names ]. */
	private static function picturesIn( string $content ): array {
		$out  = array();
		$tags = new \WP_HTML_Tag_Processor( $content );
		while ( $tags->next_tag( 'img' ) ) {
			$out[] = array( (string) $tags->get_attribute( 'src' ), (string) $tags->get_attribute( 'alt' ), (string) $tags->get_attribute( 'width' ), (array) $tags->get_attribute_names_with_prefix( '' ) );
		}
		return $out;
	}

	/* ---------- the tests ---------- */

	/**
	 * What it checks: The screenshot the team pasted into its answer shows in the forum post at the place it stood, from the site's own media
	 *   library, so every visitor of the public topic sees it forever.
	 * Passes when: a signed `message.sent` with one listed picture is answered 200; the plugin fetched it once from OmnaBase's own address for
	 *   that ticket, message and file, with the site's key, no redirect and a download cap of at most about 10 MB; the forum reply shows a
	 *   picture between the two sentences whose address is the saved attachment's, with the name as its words and the drawn width, and no
	 *   "[Picture: …]" words; the attachment belongs to the reply, carries OmnaBase's file id and holds exactly the bytes sent.
	 * Fails when: the forum post keeps reading "[Picture: image.png]" (the founder's report of 2026-11-17), the picture lands elsewhere in the
	 *   text, or it is linked from OmnaBase where only signed-in team members can open it.
	 */
	public function test_a_pasted_picture_shows_in_the_forum_reply(): void {
		$topic   = $this->filedTopic();
		$message = wp_generate_uuid4();
		$shot    = self::picture( 'shot.png', 'image/png', strlen( base64_decode( self::PNG ) ), 800 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$this->serve( $shot['id'], base64_decode( self::PNG ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$response = $this->deliverEvent( 'message.sent', 'dlv-frp-1', $this->pictureReply( $topic, $message, array( $shot ) ) );
		$this->assertSame( 200, $response->get_status() );

		$calls = $this->pictureCalls();
		$this->assertCount( 1, $calls, 'one picture call' );
		$this->assertSame( $this->pictureAddress( $topic, $message, $shot['id'] ), $calls[0]['url'], 'OmnaBase\'s own address for this picture' );
		$this->assertSame( 'GET', $calls[0]['method'] );
		$this->assertSame( 'Bearer ' . self::KEY, self::headerOf( $calls[0], 'Authorization' ), 'the site\'s key' );
		$this->assertSame( 0, (int) ( $calls[0]['args']['redirection'] ?? -1 ), 'no redirect followed' );
		$cap = (int) ( $calls[0]['args']['limit_response_size'] ?? 0 );
		$this->assertGreaterThan( 0, $cap, 'a download cap held while downloading' );
		$this->assertLessThanOrEqual( 10 * self::MB + 1024, $cap );
		$this->assertLessThanOrEqual( 3, (float) ( $calls[0]['args']['timeout'] ?? 99 ), 'at most three seconds' );

		$reply = $this->onlyReply( $topic );
		$saved = self::savedFor( $shot['id'] );
		$this->assertCount( 1, $saved, 'one attachment marked with the file id' );
		$this->assertSame( $reply->ID, (int) $saved[0]->post_parent, 'attached to the forum reply' );
		$this->assertSame( base64_decode( self::PNG ), (string) file_get_contents( (string) get_attached_file( $saved[0]->ID ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions, WordPress.WP.AlternativeFunctions
		$pics = self::picturesIn( $reply->post_content );
		$this->assertCount( 1, $pics, 'one picture in the post' );
		$this->assertSame( wp_get_attachment_url( $saved[0]->ID ), $pics[0][0] );
		$this->assertSame( 'shot.png', $pics[0][1] );
		$this->assertSame( '800', $pics[0][2] );
		$this->assertStringNotContainsString( '[Picture: shot.png]', $reply->post_content );
		$this->assertStringNotContainsString( 'data-omnabase-picture', $reply->post_content );
		$this->assertLessThan( strpos( $reply->post_content, 'Step 1.' ), strpos( $reply->post_content, '<img' ), 'the picture stands before "Step 1."' );
		$this->assertGreaterThan( strpos( $reply->post_content, 'Here is where' ), strpos( $reply->post_content, '<img' ) );
	}

	/**
	 * What it checks: A picture is saved once, however often OmnaBase hands the same answer over, and the picture's tag carries nothing
	 *   but its address, its words and its width.
	 * Passes when: the same message delivered again under a new delivery id leaves one forum reply and one attachment for the file; the picture
	 *   tag's attributes are exactly src, alt and width (no style, class, link, script handler or extra sizes).
	 * Fails when: a retry fills the media library with copies, or the post carries styling or code the forum's theme did not choose.
	 */
	public function test_a_redelivery_saves_nothing_twice_and_the_tag_is_bare(): void {
		$topic   = $this->filedTopic();
		$message = wp_generate_uuid4();
		$shot    = self::picture( 'shot.gif', 'image/gif', 42, 300 );
		$this->serve( $shot['id'], base64_decode( self::GIF ), 'image/gif' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$this->assertSame( 200, $this->deliverEvent( 'message.sent', 'dlv-frp-2a', $this->pictureReply( $topic, $message, array( $shot ) ) )->get_status() );
		$this->assertSame( 200, $this->deliverEvent( 'message.sent', 'dlv-frp-2b', $this->pictureReply( $topic, $message, array( $shot ) ) )->get_status() );
		$this->runPluginJobs();
		$reply = $this->onlyReply( $topic );
		$this->assertCount( 1, self::savedFor( $shot['id'] ), 'one attachment for the file' );
		$pics = self::picturesIn( $reply->post_content );
		$this->assertCount( 1, $pics );
		$names = array_map( 'strtolower', $pics[0][3] );
		sort( $names );
		$this->assertSame( array( 'alt', 'src', 'width' ), $names );
	}

	/**
	 * What it checks: The plugin never fetches from an address it was handed, and never builds an address from an id that is not one of
	 *   OmnaBase's ids, so a changed payload cannot send the site's key elsewhere or call another part of the API.
	 * Passes when: a picture whose entry names another host as its `url` is fetched from OmnaBase's own address only (no call to the other
	 *   host); a picture whose id is "../../me" is never fetched, its words stay, and no call reaches another API path.
	 * Fails when: the site's secret key is sent to a stranger's server, or a crafted id makes the plugin call (or disconnect) something else.
	 */
	public function test_the_address_is_built_by_the_plugin_never_taken_from_the_payload(): void {
		$topic        = $this->filedTopic();
		$message      = wp_generate_uuid4();
		$shot         = self::picture( 'shot.png' );
		$shot['url']  = 'https://evil.example/steal?key=1';
		$climb        = self::picture( 'climb.png' );
		$climb['id']  = '../../me';
		$climb['url'] = 'https://evil.example/me';
		$this->serve( $shot['id'], base64_decode( self::PNG ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$this->assertSame( 200, $this->deliverEvent( 'message.sent', 'dlv-frp-3', $this->pictureReply( $topic, $message, array( $shot, $climb ) ) )->get_status() );
		$this->runPluginJobs();
		$this->assertSame( array(), $this->callsElsewhere(), 'no call to another host' );
		$urls = array_map( static fn ( $c ) => (string) $c['url'], $this->pictureCalls() );
		$this->assertSame( array( $this->pictureAddress( $topic, $message, $shot['id'] ) ), array_values( array_unique( $urls ) ), 'only the built address of the real picture' );
		foreach ( $this->calls as $c ) {
			$this->assertStringNotContainsString( '..', (string) $c['url'] );
			$this->assertNotSame( 'DELETE', $c['method'] );
		}
		$content = $this->onlyReply( $topic )->post_content;
		$this->assertStringContainsString( '[Picture: climb.png]', $content );
		$this->assertCount( 1, self::picturesIn( $content ) );
	}

	/**
	 * What it checks: Only real JPEG, PNG, GIF or WebP pictures are saved, judged by their bytes: a drawing that can carry code (SVG) or a
	 *   web page dressed up as a PNG is refused, its words stay, and it is never fetched again.
	 * Passes when: of three pictures all announced as PNG, the real PNG is saved and shown (its file name ends in .png even though OmnaBase
	 *   named it "evil.php"), the SVG and the HTML are not in the media library and read "[Picture: name]" in the post, and after the
	 *   background jobs ran they were not fetched a second time.
	 * Fails when: a public forum serves a script from the site's own domain (it could act as any visitor who is signed in there), or a file
	 *   named ".php" lands in the uploads folder.
	 */
	public function test_svg_and_html_dressed_as_a_picture_are_refused(): void {
		$topic   = $this->filedTopic();
		$message = wp_generate_uuid4();
		$real    = self::picture( 'evil.php' );
		$svg     = self::picture( 'drawing.png' );
		$page    = self::picture( 'page.png' );
		$this->serve( $real['id'], base64_decode( self::PNG ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$this->serve( $svg['id'], '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' );
		$this->serve( $page['id'], '<!doctype html><html><body><script>alert(2)</script></body></html>' );
		$this->assertSame( 200, $this->deliverEvent( 'message.sent', 'dlv-frp-4', $this->pictureReply( $topic, $message, array( $real, $svg, $page ) ) )->get_status() );
		$this->runPluginJobs();
		$this->runPluginJobs();
		$saved = self::savedFor( $real['id'] );
		$this->assertCount( 1, $saved );
		$file = (string) get_attached_file( $saved[0]->ID );
		$this->assertStringEndsWith( '.png', $file );
		$this->assertStringNotContainsString( '.php', basename( $file ) );
		$this->assertSame( array(), self::savedFor( $svg['id'] ) );
		$this->assertSame( array(), self::savedFor( $page['id'] ) );
		$content = $this->onlyReply( $topic )->post_content;
		$this->assertStringContainsString( '[Picture: drawing.png]', $content );
		$this->assertStringContainsString( '[Picture: page.png]', $content );
		$this->assertStringNotContainsString( '<svg', $content );
		$this->assertStringNotContainsString( '<script', $content );
		foreach ( array( $svg, $page ) as $p ) {
			$this->assertCount( 1, array_filter( $this->pictureCalls(), static fn ( $c ) => str_ends_with( (string) $c['url'], '/' . $p['id'] ) ), $p['name'] . ' fetched once, never again' );
		}
	}

	/**
	 * What it checks: Size limits hold before and during the download, not after: a picture announced as larger than 10 MB is never
	 *   fetched, a download that turns out larger is cut and refused, and a reply with more than ten pictures saves the first ten.
	 * Passes when: an 11 MB entry gets no call and stays words; an entry announced small whose download is 10 MB and 5 bytes is not saved and
	 *   stays words; of eleven small pictures, the first ten show and the eleventh stays words.
	 * Fails when: a huge or endless download fills the site's disk or memory, or one answer floods the post and the media library.
	 */
	public function test_size_and_count_limits_hold_while_downloading(): void {
		$topic   = $this->filedTopic();
		$message = wp_generate_uuid4();
		$big     = self::picture( 'big.png', 'image/png', 11 * self::MB );
		$liar    = self::picture( 'liar.png', 'image/png', 900 );
		$this->serve( $big['id'], base64_decode( self::PNG ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$this->serve( $liar['id'], base64_decode( self::PNG ) . str_repeat( "\0", 10 * self::MB + 5 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$this->assertSame( 200, $this->deliverEvent( 'message.sent', 'dlv-frp-5a', $this->pictureReply( $topic, $message, array( $big, $liar ) ) )->get_status() );
		$this->runPluginJobs();
		$this->assertSame( array(), array_filter( $this->pictureCalls(), static fn ( $c ) => str_ends_with( (string) $c['url'], '/' . $big['id'] ) ), 'the 11 MB picture is never fetched' );
		$this->assertSame( array(), self::savedFor( $liar['id'] ), 'the oversized download is not saved' );
		$content = $this->onlyReply( $topic )->post_content;
		$this->assertStringContainsString( '[Picture: big.png]', $content );
		$this->assertStringContainsString( '[Picture: liar.png]', $content );

		$topic2  = $this->filedTopic();
		$many    = array();
		$message = wp_generate_uuid4();
		for ( $i = 1; $i <= 11; $i++ ) {
			$p = self::picture( 'shot-' . $i . '.png' );
			$this->serve( $p['id'], base64_decode( self::PNG ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			$many[] = $p;
		}
		$this->assertSame( 200, $this->deliverEvent( 'message.sent', 'dlv-frp-5b', $this->pictureReply( $topic2, $message, $many ) )->get_status() );
		$this->runPluginJobs();
		$content = $this->onlyReply( $topic2 )->post_content;
		$this->assertCount( 10, self::picturesIn( $content ), 'ten pictures' );
		$this->assertStringContainsString( '[Picture: shot-11.png]', $content );
		$this->assertStringNotContainsString( '[Picture: shot-10.png]', $content );
	}

	/**
	 * What it checks: (guard) A redirect from the picture address is not followed; the picture stays words and the reply is posted.
	 * Passes when: OmnaBase's picture call answers 302 towards another host; the plugin makes no call to that host, saves nothing and the
	 *   post reads "[Picture: moved.png]".
	 * Fails when: a hijacked answer steers the site to download from (and expose its key to) a stranger's server.
	 */
	public function test_a_redirect_is_not_followed(): void {
		$topic   = $this->filedTopic();
		$message = wp_generate_uuid4();
		$moved   = self::picture( 'moved.png' );
		$this->answers[ 'GET /pictures/' . $moved['id'] ] = self::answerWith( 302, '', array( 'location' => 'https://evil.example/x.png' ) );
		$this->assertSame( 200, $this->deliverEvent( 'message.sent', 'dlv-frp-6', $this->pictureReply( $topic, $message, array( $moved ) ) )->get_status() );
		$this->runPluginJobs();
		$this->assertSame( array(), $this->callsElsewhere() );
		$this->assertSame( array(), self::savedFor( $moved['id'] ) );
		$this->assertStringContainsString( '[Picture: moved.png]', $this->onlyReply( $topic )->post_content );
	}

	/**
	 * What it checks: Slow pictures never hold up or lose the answer: the site answers OmnaBase well inside its five seconds, and what did not
	 *   arrive in time is put into the posted reply by a background job; the edit is not sent back to OmnaBase as a new forum reply.
	 * Passes when: with each picture taking 1.2 seconds, the webhook for a reply with four pictures is answered 200 in under 4.5 seconds with
	 *   the reply posted; after the background jobs ran (OmnaBase now fast), the same reply shows all four pictures and no "[Picture: …]"
	 *   words, still ends with its closing line, has no saved revision (bbPress would print "This reply was modified …" under it), and no
	 *   forum-reply job for it waits in the plugin's outbox.
	 * Fails when: OmnaBase gives up waiting and the team sees "try 2 of 8 failed" for every answer with pictures, the pictures never arrive,
	 *   or the plugin's own edit travels back to the ticket as a duplicate post.
	 */
	public function test_slow_pictures_never_hold_the_reply_and_finish_later(): void {
		$topic   = $this->filedTopic();
		$message = wp_generate_uuid4();
		$four    = array();
		for ( $i = 1; $i <= 4; $i++ ) {
			$p = self::picture( 'slow-' . $i . '.png' );
			$this->serve( $p['id'], base64_decode( self::PNG ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			$four[] = $p;
		}
		$this->delay = 1.2;
		$start       = microtime( true );
		$response    = $this->deliverEvent( 'message.sent', 'dlv-frp-7', $this->pictureReply( $topic, $message, $four ) );
		$took        = microtime( true ) - $start;
		$this->assertSame( 200, $response->get_status() );
		$this->assertLessThan( 4.5, $took, 'answered inside OmnaBase\'s five seconds' );
		$this->onlyReply( $topic );

		$this->delay = 0.0;
		$this->runPluginJobs();
		$this->runPluginJobs();
		$reply = $this->onlyReply( $topic );
		$this->assertCount( 4, self::picturesIn( $reply->post_content ), 'all four pictures after the background job' );
		$this->assertStringNotContainsString( '[Picture: ', $reply->post_content );
		$this->assertStringContainsString( 'Best regards', $reply->post_content, 'the closing line stays' );
		$this->assertSame( array(), wp_get_post_revisions( $reply->ID ), 'no revision, so the forum prints no "This reply was modified" line' );
		$this->assertSame( array(), array_filter( $this->outboxRows( ReplySender::KIND ), static fn ( $r ) => (int) $r['subject_id'] === $reply->ID ), 'the edit is not queued back to OmnaBase' );
		foreach ( $four as $p ) {
			$this->assertSame( $reply->ID, (int) ( self::savedFor( $p['id'] )[0]->post_parent ?? 0 ), $p['name'] . ' belongs to the reply' );
		}
	}

	/**
	 * What it checks: (guard) A site whose upload folder cannot be written still posts the answer, with the words where the picture stood.
	 * Passes when: with WordPress reporting the upload folder as not writable, the webhook is answered 200, the reply is posted, it reads
	 *   "[Picture: shot.png]" and no attachment was made.
	 * Fails when: a hosting quirk makes every answer with a screenshot fail, so OmnaBase retries and finally mails it instead of posting.
	 */
	public function test_an_unwritable_upload_folder_keeps_the_words(): void {
		$topic   = $this->filedTopic();
		$message = wp_generate_uuid4();
		$shot    = self::picture( 'shot.png' );
		$this->serve( $shot['id'], base64_decode( self::PNG ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$blocked = static function ( $dirs ) {
			$dirs['error'] = 'Unable to create directory. Is its parent directory writable by the server?';
			return $dirs;
		};
		add_filter( 'upload_dir', $blocked );
		try {
			$this->assertSame( 200, $this->deliverEvent( 'message.sent', 'dlv-frp-8', $this->pictureReply( $topic, $message, array( $shot ) ) )->get_status() );
		} finally {
			remove_filter( 'upload_dir', $blocked );
		}
		$this->assertStringContainsString( '[Picture: shot.png]', $this->onlyReply( $topic )->post_content );
		$this->assertSame( array(), self::savedFor( $shot['id'] ) );
	}

	/**
	 * What it checks: The fence lets exactly the saved pictures of this answer through: a picture typed into the HTML, a picture from another
	 *   server, an embedded picture and a mark for a picture that was not listed all stay out.
	 * Passes when: an answer with one listed picture plus a tracking picture, an embedded picture, a picture pointing at the site's own uploads
	 *   and a marked paragraph whose id is not in the list holds exactly one picture, the saved one; the unlisted mark reads its words.
	 * Fails when: a crafted answer puts a tracking picture or any other address onto the public forum page.
	 */
	public function test_the_fence_lets_through_only_the_saved_pictures(): void {
		$topic    = $this->filedTopic();
		$message  = wp_generate_uuid4();
		$shot     = self::picture( 'shot.png' );
		$stranger = self::picture( 'stranger.png' );
		$this->serve( $shot['id'], base64_decode( self::PNG ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$uploads = wp_upload_dir();
		$extra   = '<p><img src="https://tracker.example/p.png" alt="t"></p><p><img src="data:image/png;base64,' . self::PNG . '" alt="d"></p>'
			. '<p><img src="' . esc_attr( $uploads['baseurl'] . '/2026/11/other.png' ) . '" alt="o" onerror="alert(1)"></p>' . self::marked( $stranger );
		$this->assertSame( 200, $this->deliverEvent( 'message.sent', 'dlv-frp-9', $this->pictureReply( $topic, $message, array( $shot ), $extra ) )->get_status() );
		$content = $this->onlyReply( $topic )->post_content;
		$pics    = self::picturesIn( $content );
		$this->assertCount( 1, $pics, 'only the saved picture' );
		$this->assertSame( wp_get_attachment_url( self::savedFor( $shot['id'] )[0]->ID ), $pics[0][0] );
		$this->assertStringContainsString( '[Picture: stranger.png]', $content );
		$this->assertStringNotContainsString( 'tracker.example', $content );
		$this->assertStringNotContainsString( 'onerror', $content );
		$this->assertSame( array(), array_filter( $this->pictureCalls(), static fn ( $c ) => str_ends_with( (string) $c['url'], '/' . $stranger['id'] ) ), 'an unlisted mark is never fetched' );
	}

	/**
	 * What it checks: (guard) Without a pictures list, a marked paragraph is only words: nothing is fetched and no picture appears.
	 * Passes when: a `message.sent` whose HTML holds a marked paragraph but no `pictures` key posts the words "[Picture: shot.png]", makes no
	 *   picture call and saves nothing.
	 * Fails when: the plugin guesses addresses for pictures OmnaBase did not offer.
	 */
	public function test_without_a_list_a_mark_stays_words(): void {
		$topic   = $this->filedTopic();
		$message = wp_generate_uuid4();
		$shot    = self::picture( 'shot.png' );
		$event   = $this->pictureReply( $topic, $message, array( $shot ) );
		unset( $event['message']['pictures'] );
		$this->assertSame( 200, $this->deliverEvent( 'message.sent', 'dlv-frp-10', $event )->get_status() );
		$this->runPluginJobs();
		$this->assertSame( array(), $this->pictureCalls() );
		$content = $this->onlyReply( $topic )->post_content;
		$this->assertStringContainsString( '[Picture: shot.png]', $content );
		$this->assertStringNotContainsString( '<img', $content );
	}

	/**
	 * What it checks: The Activity tab tells the administrator in plain words when a picture of an answer was saved later or left out, and why.
	 * Passes when: after one answer whose picture was a disguised SVG and another whose picture first failed to arrive and came with the
	 *   background job, the Activity tab names both tickets with the word "picture", says one was left out (with a reason in words) and one
	 *   was saved later.
	 * Fails when: the owner sees "[Picture: …]" on the forum and has no way to learn whether it is coming or why it never will.
	 */
	public function test_the_activity_tab_says_saved_later_or_left_out(): void {
		$left    = $this->filedTopic();
		$late    = $this->filedTopic();
		$svg     = self::picture( 'drawing.png' );
		$slow    = self::picture( 'slow.png' );
		$this->serve( $svg['id'], '<svg xmlns="http://www.w3.org/2000/svg"></svg>' );
		$this->answers[ 'GET /pictures/' . $slow['id'] ] = new \WP_Error( 'http_request_failed', 'Operation timed out after 3000 milliseconds' );
		$this->assertSame( 200, $this->deliverEvent( 'message.sent', 'dlv-frp-11a', $this->pictureReply( $left, wp_generate_uuid4(), array( $svg ) ) )->get_status() );
		$this->assertSame( 200, $this->deliverEvent( 'message.sent', 'dlv-frp-11b', $this->pictureReply( $late, wp_generate_uuid4(), array( $slow ) ) )->get_status() );
		$this->assertStringContainsString( '[Picture: slow.png]', $this->onlyReply( $late )->post_content, 'the failed picture waits as words' );
		$this->serve( $slow['id'], base64_decode( self::PNG ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$this->runPluginJobs();
		$this->runPluginJobs();
		$this->assertCount( 1, self::picturesIn( $this->onlyReply( $late )->post_content ), 'saved later' );
		// Builder's note, 2026-11-17: the webhooks above run logged out (as OmnaBase calls), so the administrator signs in again before opening
		// the tab, as the other forum tests do; without it the screen refuses the visitor. Nothing the test checks changed.
		wp_set_current_user( $this->admin );
		$text = self::visibleText( $this->openTab( 'activity' ) );
		foreach ( array( $left, $late ) as $t ) {
			$this->assertStringContainsString( '#' . (int) get_post_meta( $t, self::TICKET_META, true ), $text );
		}
		$this->assertMatchesRegularExpression( '/picture/i', $text );
		$this->assertMatchesRegularExpression( '/left out/i', $text );
		$this->assertMatchesRegularExpression( '/later/i', $text );
	}
}
