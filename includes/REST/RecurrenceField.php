<?php
/**
 * The event's repeat rule as a field of the event itself, for the editor.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\CPT\EventPostType;
use Blockendar\Recurrence\Rule;
use Blockendar\Recurrence\RuleRepository;
use WP_Error;
use WP_Post;

/**
 * Carries the repeat rule in the same request that saves the event.
 *
 * The rule lives in its own table, so the editor used to save it with a
 * request of its own. That request went out the moment a control changed —
 * before the event was saved, and whether or not it ever was — and again after
 * every save, where a failure was swallowed. As a field of the post the rule
 * is edited, dirtied, saved and discarded with everything else on the screen,
 * and it is in place before rest_after_insert rebuilds the index.
 *
 * The field is offered in the edit context only. The blockendar/v1 recurrence
 * routes are unchanged for other clients.
 */
class RecurrenceField {

	const FIELD = 'blockendar_recurrence';

	/**
	 * How an event that does not repeat is written.
	 *
	 * Not null: core skips a field whose value is null, so null could never
	 * remove a rule. An object with this frequency reads and writes the same
	 * way, which also keeps an untouched event from looking edited.
	 */
	const NONE = [ 'frequency' => 'none' ];

	private RuleRepository $rules;

	public function __construct() {
		$this->rules = new RuleRepository();
	}

	/**
	 * Attach hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_field' ] );
	}

	/**
	 * Register the field on the event post type.
	 */
	public function register_field(): void {
		register_rest_field(
			EventPostType::POST_TYPE,
			self::FIELD,
			[
				'get_callback'    => [ $this, 'get' ],
				'update_callback' => [ $this, 'update' ],
				'schema'          => [
					'description' => __( 'The event\'s repeat rule. A frequency of "none" means it does not repeat.', 'blockendar' ),
					'type'        => 'object',
					'context'     => [ 'edit' ],
					'properties'  => [
						'frequency'    => [
							'type' => 'string',
							'enum' => array_merge( [ 'none' ], Rule::ALLOWED_FREQUENCIES ),
						],
						'interval_val' => [
							'type'    => 'integer',
							'minimum' => 1,
						],
						'byday'        => [ 'type' => [ 'string', 'null' ] ],
						'bymonthday'   => [ 'type' => [ 'string', 'null' ] ],
						'bysetpos'     => [ 'type' => [ 'string', 'null' ] ],
						'until_date'   => [ 'type' => [ 'string', 'null' ] ],
						'count'        => [ 'type' => [ 'integer', 'null' ] ],
					],
				],
			]
		);
	}

	/**
	 * The stored rule, in the shape the editor writes it back in.
	 *
	 * The lists are comma-separated strings, as the table holds them, so a rule
	 * read and written back unchanged compares equal and does not mark the
	 * event as edited.
	 *
	 * @param array $post Prepared post data.
	 * @return array
	 */
	public function get( array $post ): array {
		$rule = $this->rules->get( (int) $post['id'] );

		if ( null === $rule ) {
			return self::NONE;
		}

		$csv = static fn( array $values ): ?string => $values ? implode( ',', array_map( 'strval', $values ) ) : null;

		return [
			'frequency'    => $rule->frequency,
			'interval_val' => $rule->interval,
			'byday'        => $csv( $rule->byday ),
			'bymonthday'   => $csv( $rule->bymonthday ),
			'bysetpos'     => $csv( $rule->bysetpos ),
			'until_date'   => $rule->until_date?->format( 'Y-m-d' ),
			'count'        => $rule->count,
		];
	}

	/**
	 * Store, replace or remove the rule.
	 *
	 * Core calls this only when the request carries the field, and only after
	 * it has checked the caller may edit the event.
	 *
	 * @param mixed   $value Rule fields; a frequency of "none" removes the rule.
	 * @param WP_Post $post  Event being saved.
	 * @return true|WP_Error
	 */
	public function update( mixed $value, WP_Post $post ): bool|WP_Error {
		if ( ! is_array( $value ) || empty( $value['frequency'] ) || 'none' === $value['frequency'] ) {
			$this->rules->delete( $post->ID );

			return true;
		}

		// The editor has no controls for skipped and added dates and does not
		// send them; upsert() leaves alone what it is not given.
		if ( ! $this->rules->upsert( $post->ID, $value ) ) {
			return new WP_Error(
				'blockendar_save_failed',
				__( 'Failed to save recurrence rule.', 'blockendar' ),
				[ 'status' => 500 ]
			);
		}

		return true;
	}
}
