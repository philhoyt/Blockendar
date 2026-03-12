<?php
/**
 * Venue term meta registration.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Meta;

use Blockendar\Taxonomy\Venue;
use Blockendar\Taxonomy\EventType;

/**
 * Registers term meta for the event_venue and event_type taxonomies.
 */
class VenueMeta {

	/**
	 * Attach hooks.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_meta' ] );

		// Color picker on the Event Type term screens.
		add_action( EventType::TAXONOMY . '_add_form_fields', [ $this, 'render_color_add_field' ] );
		add_action( EventType::TAXONOMY . '_edit_form_fields', [ $this, 'render_color_edit_field' ] );
		add_action( 'created_' . EventType::TAXONOMY, [ $this, 'save_color_field' ] );
		add_action( 'edited_' . EventType::TAXONOMY, [ $this, 'save_color_field' ] );
	}

	/**
	 * Register all term meta fields.
	 */
	public function register_meta(): void {
		$this->register_venue_meta();
		$this->register_event_type_meta();
	}

	/**
	 * Register venue term meta fields.
	 */
	private function register_venue_meta(): void {
		$taxonomy = Venue::TAXONOMY;

		$string_fields = [
			'blockendar_venue_address'  => 'Street address line 1.',
			'blockendar_venue_address2' => 'Suite, floor, etc.',
			'blockendar_venue_city'     => 'City.',
			'blockendar_venue_state'    => 'State / Province.',
			'blockendar_venue_postcode' => 'Postal / ZIP code.',
			'blockendar_venue_country'  => 'ISO 3166-1 alpha-2 country code.',
			'blockendar_venue_phone'    => 'Contact phone number.',
		];

		foreach ( $string_fields as $key => $description ) {
			register_term_meta(
				$taxonomy,
				$key,
				[
					'type'              => 'string',
					'description'       => $description,
					'single'            => true,
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
					'show_in_rest'      => true,
				]
			);
		}

		// URL fields.
		register_term_meta(
			$taxonomy,
			'blockendar_venue_url',
			[
				'type'              => 'string',
				'description'       => 'Venue website URL.',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => 'esc_url_raw',
				'show_in_rest'      => [
					'schema' => [
						'type'   => 'string',
						'format' => 'uri',
					],
				],
			]
		);

		register_term_meta(
			$taxonomy,
			'blockendar_venue_stream_url',
			[
				'type'              => 'string',
				'description'       => 'Stream link for virtual events.',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => 'esc_url_raw',
				'show_in_rest'      => [
					'schema' => [
						'type'   => 'string',
						'format' => 'uri',
					],
				],
			]
		);

		// Coordinate fields.
		register_term_meta(
			$taxonomy,
			'blockendar_venue_lat',
			[
				'type'              => 'number',
				'description'       => 'Latitude (decimal degrees).',
				'single'            => true,
				'default'           => 0.0,
				'sanitize_callback' => [ $this, 'sanitize_latitude' ],
				'show_in_rest'      => true,
			]
		);

		register_term_meta(
			$taxonomy,
			'blockendar_venue_lng',
			[
				'type'              => 'number',
				'description'       => 'Longitude (decimal degrees).',
				'single'            => true,
				'default'           => 0.0,
				'sanitize_callback' => [ $this, 'sanitize_longitude' ],
				'show_in_rest'      => true,
			]
		);

		// Integer fields.
		register_term_meta(
			$taxonomy,
			'blockendar_venue_capacity',
			[
				'type'              => 'integer',
				'description'       => 'Venue maximum capacity.',
				'single'            => true,
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'show_in_rest'      => true,
			]
		);

		// Boolean fields.
		register_term_meta(
			$taxonomy,
			'blockendar_venue_virtual',
			[
				'type'              => 'boolean',
				'description'       => 'Whether this is an online/virtual venue.',
				'single'            => true,
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => true,
			]
		);
	}

	/**
	 * Register event_type term meta (calendar colour).
	 */
	private function register_event_type_meta(): void {
		register_term_meta(
			EventType::TAXONOMY,
			'blockendar_type_color',
			[
				'type'              => 'string',
				'description'       => 'Hex colour for calendar display (e.g. #3B82F6).',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => [ $this, 'sanitize_hex_color' ],
				'show_in_rest'      => true,
			]
		);
	}

	/**
	 * Render color field on the Add New Event Type form.
	 */
	public function render_color_add_field(): void {
		?>
		<div class="form-field">
			<label for="blockendar_type_color"><?php esc_html_e( 'Calendar colour', 'blockendar' ); ?></label>
			<input
				type="color"
				id="blockendar_type_color"
				name="blockendar_type_color"
				value="#3788d8"
			/>
			<p><?php esc_html_e( 'Colour used to display events of this type on the calendar.', 'blockendar' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render color field on the Edit Event Type form.
	 *
	 * @param \WP_Term $term Current term object.
	 */
	public function render_color_edit_field( \WP_Term $term ): void {
		$color = get_term_meta( $term->term_id, 'blockendar_type_color', true );
		$value = ( '' !== $color ) ? $color : '#3788d8';
		?>
		<tr class="form-field">
			<th scope="row">
				<label for="blockendar_type_color"><?php esc_html_e( 'Calendar colour', 'blockendar' ); ?></label>
			</th>
			<td>
				<input
					type="color"
					id="blockendar_type_color"
					name="blockendar_type_color"
					value="<?php echo esc_attr( $value ); ?>"
				/>
				<p class="description"><?php esc_html_e( 'Colour used to display events of this type on the calendar.', 'blockendar' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save the color field when a term is created or updated.
	 *
	 * @param int $term_id Term ID.
	 */
	public function save_color_field( int $term_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WP core before firing edited/created_term actions.
		if ( ! isset( $_POST['blockendar_type_color'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified upstream; value sanitized via sanitize_hex_color().
		$color = $this->sanitize_hex_color( wp_unslash( $_POST['blockendar_type_color'] ) );
		update_term_meta( $term_id, 'blockendar_type_color', $color );
	}

	/**
	 * Sanitize a latitude value (-90 to 90).
	 */
	public function sanitize_latitude( mixed $value ): float {
		$lat = (float) $value;
		return max( -90.0, min( 90.0, $lat ) );
	}

	/**
	 * Sanitize a longitude value (-180 to 180).
	 */
	public function sanitize_longitude( mixed $value ): float {
		$lng = (float) $value;
		return max( -180.0, min( 180.0, $lng ) );
	}

	/**
	 * Sanitize a hex colour code.
	 */
	public function sanitize_hex_color( mixed $value ): string {
		$value = sanitize_text_field( (string) $value );

		return preg_match( '/^#[0-9A-Fa-f]{6}$/', $value ) ? strtoupper( $value ) : '';
	}
}
