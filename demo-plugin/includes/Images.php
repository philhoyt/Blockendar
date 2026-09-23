<?php
/**
 * Featured image sideloading for demo events.
 *
 * @package BlockendarDemo
 */

declare( strict_types=1 );

namespace Blockendar\Demo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attaches bundled images as event featured images.
 *
 * Everything is local. The old bin/ seeder pulled from picsum.photos or the
 * Unsplash API, which meant ~15 proxied HTTP requests during a Playground boot
 * and silent failure offline.
 */
class Images {

	/**
	 * Image keys that may be requested, mapped to their bundled file.
	 *
	 * An allowlist rather than a path built from caller input: the key can come
	 * from a fixture array that the blockendar_demo_event_fixtures filter has
	 * touched, so it must never reach the filesystem unchecked.
	 *
	 * The "-alt" entries are second variants so that 31 events do not have to
	 * share eight thumbnails. They are abstract gradients, so their colours are
	 * picked to sit in the gaps between the original eight hues rather than to
	 * suit the category they are named for; the name only says which slot a
	 * fixture draws from.
	 *
	 * Keys and filenames are append-only. Existing demo installs record
	 * attachments by ID in the seed state, so renaming or removing a bundled
	 * file leaves them pointing at media that no longer exists.
	 */
	private const ALLOWED = [
		'music'         => 'music.jpg',
		'community'     => 'community.jpg',
		'sports'        => 'sports.jpg',
		'arts'          => 'arts.jpg',
		'food'          => 'food.jpg',
		'tech'          => 'tech.jpg',
		'gala'          => 'gala.jpg',
		'market'        => 'market.jpg',
		'music-alt'     => 'music-alt.jpg',
		'community-alt' => 'community-alt.jpg',
		'sports-alt'    => 'sports-alt.jpg',
		'arts-alt'      => 'arts-alt.jpg',
		'food-alt'      => 'food-alt.jpg',
		'tech-alt'      => 'tech-alt.jpg',
		'gala-alt'      => 'gala-alt.jpg',
		'market-alt'    => 'market-alt.jpg',
	];

	/**
	 * Sideload a bundled image and set it as $post_id's featured image.
	 *
	 * @param int    $post_id Event post ID.
	 * @param string $key     Image key from self::ALLOWED.
	 * @return int Attachment ID, or 0 if nothing was attached.
	 */
	public function attach( int $post_id, string $key ): int {
		if ( ! isset( self::ALLOWED[ $key ] ) ) {
			return 0;
		}

		$source = BLOCKENDAR_DEMO_DIR . 'images/' . self::ALLOWED[ $key ];

		if ( ! file_exists( $source ) ) {
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// media_handle_sideload() moves the file it is given, so hand it a copy.
		$tmp = wp_tempnam( self::ALLOWED[ $key ] );

		if ( ! $tmp || ! copy( $source, $tmp ) ) {
			if ( $tmp ) {
				wp_delete_file( $tmp );
			}

			return 0;
		}

		$attachment_id = media_handle_sideload(
			[
				'name'     => self::ALLOWED[ $key ],
				'tmp_name' => $tmp,
			],
			$post_id
		);

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			return 0;
		}

		$attachment_id = (int) $attachment_id;

		update_post_meta( $attachment_id, Seeder::MARKER, 1 );
		set_post_thumbnail( $post_id, $attachment_id );

		return $attachment_id;
	}
}
