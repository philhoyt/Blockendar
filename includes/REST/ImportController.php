<?php
/**
 * REST controller for event imports.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\Import\TribeImporter;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles file-upload import endpoints.
 * Currently supports The Events Calendar (tribe_events) WXR exports.
 */
class ImportController extends AbstractController {

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/import/tribe',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_tribe' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);
	}

	/**
	 * Handle a tribe_events WXR import upload.
	 *
	 * @param WP_REST_Request $request The REST request (multipart/form-data with 'file').
	 */
	public function handle_tribe( WP_REST_Request $request ): WP_REST_Response {
		$files = $request->get_file_params();

		if ( empty( $files['file']['tmp_name'] ) ) {
			return $this->respond( [ 'error' => __( 'No file uploaded.', 'blockendar' ) ], 400 );
		}

		$file = $files['file'];

		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			return $this->respond( [ 'error' => __( 'File upload failed.', 'blockendar' ) ], 400 );
		}

		// 10 MB limit.
		if ( $file['size'] > 10 * 1024 * 1024 ) {
			return $this->respond( [ 'error' => __( 'File exceeds 10 MB limit.', 'blockendar' ) ], 400 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$xml = file_get_contents( $file['tmp_name'] );

		if ( false === $xml ) {
			return $this->respond( [ 'error' => __( 'Could not read uploaded file.', 'blockendar' ) ], 500 );
		}

		$dry_run  = (bool) $request->get_param( 'dry_run' );
		$importer = new TribeImporter();
		$results  = $importer->import( $xml, $dry_run );

		return $this->respond( $results );
	}
}
