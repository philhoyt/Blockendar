<?php
/**
 * Creates and removes the demo content.
 *
 * @package BlockendarDemo
 */

declare( strict_types=1 );

namespace Blockendar\Demo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\DB\EventIndex;
use Blockendar\DB\IndexBuilder;
use Blockendar\Recurrence\Generator;
use Blockendar\Recurrence\RuleRepository;
use Blockendar\Taxonomy\EventType;
use Blockendar\Taxonomy\Venue;

/**
 * Seeds and tears down the demo dataset.
 *
 * Everything this class creates is recorded by ID in Plugin::STATE_OPTION, and
 * reset() deletes only those recorded IDs. It never deletes by slug or by meta
 * lookup: a site that already has a "calendar" page or a "music" term must come
 * through seed and reset untouched.
 */
class Seeder {

	/**
	 * Meta key marking an object as demo-created. Informational only — reset()
	 * works from the recorded ID list, not from this flag.
	 */
	public const MARKER = '_blockendar_demo';

	/**
	 * Event statuses the main plugin accepts.
	 */
	private const STATUSES = [ 'scheduled', 'cancelled', 'postponed', 'sold_out' ];

	/**
	 * Collected non-fatal problems, surfaced to the caller.
	 *
	 * @var string[]
	 */
	private array $errors = [];

	/**
	 * Create the full demo dataset.
	 *
	 * Seeding over an existing demo does not create a second one. It rewrites
	 * the tour pages instead, so an install made before a markup change picks
	 * up the current layout without a reset — a reset would take the events
	 * with it, and their dates are generated relative to the day they were
	 * created. The events themselves are left exactly as they are.
	 *
	 * @return array{created: int, pages: int, errors: string[], skipped: bool, refreshed: int}
	 */
	public function seed(): array {
		if ( $this->is_seeded() ) {
			$state = get_option( Plugin::STATE_OPTION );

			return [
				'created'   => 0,
				'pages'     => 0,
				'errors'    => [],
				'skipped'   => true,
				'refreshed' => is_array( $state ) ? ( new Pages() )->refresh( $state ) : 0,
			];
		}

		$this->errors = [];
		$this->ensure_generator();

		$state = [
			'events'      => [],
			'pages'       => [],
			'attachments' => [],
			'terms'       => [],
			'front'       => [
				'show_on_front' => get_option( 'show_on_front' ),
				'page_on_front' => (int) get_option( 'page_on_front' ),
			],
		];

		$created = 0;
		$pages   = 0;

		// Whatever happens, record what was created. A seed that dies part-way
		// through would otherwise leave orphaned events and terms that reset()
		// has no way to find.
		try {
			$type_ids  = $this->seed_event_types( $state );
			$venue_ids = $this->seed_venues( $state );

			foreach ( $this->event_fixtures() as $fixture ) {
				$post_id = $this->create_event( $fixture, $type_ids, $venue_ids, $state );

				if ( $post_id ) {
					++$created;
				}
			}

			foreach ( Fixtures::recurring() as $series ) {
				$post_id = $this->create_event( $series['event'], $type_ids, $venue_ids, $state );

				if ( ! $post_id ) {
					continue;
				}

				$this->create_recurrence( $post_id, $series['rule'] );
				++$created;
			}

			$pages = ( new Pages() )->create( $state );
		} finally {
			update_option( Plugin::STATE_OPTION, $state, false );
		}

		flush_rewrite_rules();

		/**
		 * Fires after the demo dataset has been created.
		 *
		 * @param array $state Recorded object IDs and prior front-page config.
		 */
		do_action( 'blockendar_demo_seeded', $state );

		return [
			'created'   => $created,
			'pages'     => $pages,
			'errors'    => $this->errors,
			'skipped'   => false,
			'refreshed' => 0,
		];
	}

	/**
	 * Remove everything seed() created.
	 *
	 * @return array{events: int, pages: int, terms: int}
	 */
	public function reset(): array {
		$state = get_option( Plugin::STATE_OPTION );

		if ( ! is_array( $state ) ) {
			return [
				'events' => 0,
				'pages'  => 0,
				'terms'  => 0,
			];
		}

		$index = new EventIndex();
		$rules = new RuleRepository();

		$events = 0;
		foreach ( (array) ( $state['events'] ?? [] ) as $post_id ) {
			$post_id = (int) $post_id;

			// Clears the occurrence rows AND the event_type_terms junction rows.
			$index->delete_by_post_id( $post_id );
			$rules->delete( $post_id );

			if ( wp_delete_post( $post_id, true ) ) {
				++$events;
			}
		}

		foreach ( (array) ( $state['attachments'] ?? [] ) as $attachment_id ) {
			wp_delete_attachment( (int) $attachment_id, true );
		}

		$pages = 0;
		foreach ( (array) ( $state['pages'] ?? [] ) as $page_id ) {
			if ( wp_delete_post( (int) $page_id, true ) ) {
				++$pages;
			}
		}

		$terms = 0;
		foreach ( (array) ( $state['terms'] ?? [] ) as $term ) {
			$term_id  = (int) ( $term['id'] ?? 0 );
			$taxonomy = (string) ( $term['taxonomy'] ?? '' );

			if ( $term_id && $taxonomy && true === wp_delete_term( $term_id, $taxonomy ) ) {
				++$terms;
			}
		}

		$front = (array) ( $state['front'] ?? [] );
		if ( isset( $front['show_on_front'] ) ) {
			update_option( 'show_on_front', $front['show_on_front'] );
		}
		if ( isset( $front['page_on_front'] ) ) {
			update_option( 'page_on_front', (int) $front['page_on_front'] );
		}

		delete_option( Plugin::STATE_OPTION );

		flush_rewrite_rules();

		return [
			'events' => $events,
			'pages'  => $pages,
			'terms'  => $terms,
		];
	}

	/**
	 * Whether a demo dataset is currently installed.
	 */
	public function is_seeded(): bool {
		return is_array( get_option( Plugin::STATE_OPTION ) );
	}

	/**
	 * Event fixtures, after the extension filter.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function event_fixtures(): array {
		/**
		 * Filters the single-event fixtures before they are inserted.
		 *
		 * Every field is validated after this filter runs — see create_event().
		 *
		 * @param array $events Event definitions.
		 */
		$events = apply_filters( 'blockendar_demo_event_fixtures', Fixtures::events() );

		return is_array( $events ) ? $events : Fixtures::events();
	}

	/**
	 * Make sure the recurrence generator is listening.
	 *
	 * IndexBuilder::build_for_post() delegates recurring events to the
	 * blockendar_generate_recurrence_index action. If nothing is hooked to it,
	 * a recurring event gets a rule row but no occurrence rows, and silently
	 * renders nowhere.
	 */
	private function ensure_generator(): void {
		if ( ! has_action( 'blockendar_generate_recurrence_index' ) ) {
			( new Generator() )->register();
		}
	}

	/**
	 * Create the event type terms.
	 *
	 * @param array $state Seed state, by reference.
	 * @return array<string, int> Fixture slug => term ID.
	 */
	private function seed_event_types( array &$state ): array {
		$ids = [];

		foreach ( Fixtures::event_types() as $type ) {
			$term_id = $this->ensure_term( $type['name'], $type['slug'], EventType::TAXONOMY, $state );

			if ( ! $term_id ) {
				continue;
			}

			update_term_meta( $term_id, 'blockendar_type_color', $type['color'] );
			$ids[ $type['slug'] ] = $term_id;
		}

		return $ids;
	}

	/**
	 * Create the venue terms and their meta.
	 *
	 * @param array $state Seed state, by reference.
	 * @return array<string, int> Fixture slug => term ID.
	 */
	private function seed_venues( array &$state ): array {
		$ids = [];

		foreach ( Fixtures::venues() as $venue ) {
			$term_id = $this->ensure_term( $venue['name'], $venue['slug'], Venue::TAXONOMY, $state );

			if ( ! $term_id ) {
				continue;
			}

			foreach ( $venue['meta'] as $key => $value ) {
				update_term_meta( $term_id, $key, $value );
			}

			$ids[ $venue['slug'] ] = $term_id;
		}

		return $ids;
	}

	/**
	 * Create a term, working around a slug already taken by real content.
	 *
	 * If the slug exists and was not created by this demo, a "-demo" suffixed
	 * slug is used instead and the pre-existing term is left completely alone.
	 *
	 * @param string $name     Term name.
	 * @param string $slug     Desired slug.
	 * @param string $taxonomy Taxonomy name.
	 * @param array  $state    Seed state, by reference.
	 * @return int Term ID, or 0 on failure.
	 */
	private function ensure_term( string $name, string $slug, string $taxonomy, array &$state ): int {
		$existing = get_term_by( 'slug', $slug, $taxonomy );

		if ( $existing instanceof \WP_Term ) {
			// Someone else's term. Claim a different slug rather than touch it.
			$slug = $slug . '-demo';
			$name = $name . ' (Demo)';
		}

		$result = wp_insert_term( $name, $taxonomy, [ 'slug' => $slug ] );

		if ( is_wp_error( $result ) ) {
			// A previous demo run left this behind; adopt it rather than fail.
			$term_id = (int) ( $result->error_data['term_exists'] ?? 0 );

			if ( ! $term_id ) {
				$this->errors[] = sprintf( 'Could not create %s term "%s": %s', $taxonomy, $name, $result->get_error_message() );
				return 0;
			}
		} else {
			$term_id = (int) $result['term_id'];
		}

		update_term_meta( $term_id, self::MARKER, 1 );

		$state['terms'][] = [
			'id'       => $term_id,
			'taxonomy' => $taxonomy,
		];

		return $term_id;
	}

	/**
	 * Create one event post, its meta, its terms and its index rows.
	 *
	 * @param array              $fixture   Event definition.
	 * @param array<string, int> $type_ids  Type slug => term ID.
	 * @param array<string, int> $venue_ids Venue slug => term ID.
	 * @param array              $state     Seed state, by reference.
	 * @return int Post ID, or 0 on failure.
	 */
	private function create_event( array $fixture, array $type_ids, array $venue_ids, array &$state ): int {
		$title = sanitize_text_field( (string) ( $fixture['title'] ?? '' ) );

		if ( '' === $title ) {
			$this->errors[] = 'Skipped an event fixture with no title.';
			return 0;
		}

		$post_id = wp_insert_post(
			[
				'post_type'    => 'blockendar_event',
				'post_title'   => $title,
				'post_content' => wp_kses_post( (string) ( $fixture['content'] ?? '' ) ),
				'post_status'  => 'publish',
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			$this->errors[] = sprintf( 'Could not create "%s": %s', $title, $post_id->get_error_message() );
			return 0;
		}

		$post_id = (int) $post_id;
		$all_day = ! empty( $fixture['all_day'] );
		$start   = $this->valid_date( $fixture['start_date'] ?? '' );

		// A missing end date means a single-day event, not "today".
		$end_raw = (string) ( $fixture['end_date'] ?? '' );
		$end     = '' === $end_raw ? $start : $this->valid_date( $end_raw );

		$meta = [
			'blockendar_start_date'         => $start,
			'blockendar_end_date'           => $end,
			'blockendar_start_time'         => $all_day ? '' : $this->valid_time( $fixture['start_time'] ?? '09:00' ),
			'blockendar_end_time'           => $all_day ? '' : $this->valid_time( $fixture['end_time'] ?? '10:00' ),
			'blockendar_all_day'            => $all_day ? '1' : '0',
			'blockendar_timezone'           => wp_timezone_string() ?: 'UTC',
			'blockendar_status'             => $this->valid_status( $fixture['status'] ?? 'scheduled' ),
			'blockendar_cost'               => sanitize_text_field( (string) ( $fixture['cost'] ?? '' ) ),
			'blockendar_cost_min'           => $this->valid_number( $fixture['cost_min'] ?? '' ),
			'blockendar_cost_max'           => $this->valid_number( $fixture['cost_max'] ?? '' ),
			'blockendar_currency'           => sanitize_text_field( (string) ( $fixture['currency'] ?? 'USD' ) ),
			'blockendar_registration_url'   => esc_url_raw( (string) ( $fixture['registration_url'] ?? '' ) ),
			'blockendar_capacity'           => $this->valid_number( $fixture['capacity'] ?? '' ),
			'blockendar_featured'           => ! empty( $fixture['featured'] ) ? '1' : '0',
			'blockendar_hide_from_listings' => ! empty( $fixture['hide'] ) ? '1' : '0',
		];

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		update_post_meta( $post_id, self::MARKER, 1 );

		$venue_slug = (string) ( $fixture['venue'] ?? '' );
		if ( '' !== $venue_slug && isset( $venue_ids[ $venue_slug ] ) ) {
			wp_set_object_terms( $post_id, [ (int) $venue_ids[ $venue_slug ] ], Venue::TAXONOMY );
		}

		$types = [];
		foreach ( (array) ( $fixture['types'] ?? [] ) as $type_slug ) {
			if ( isset( $type_ids[ (string) $type_slug ] ) ) {
				$types[] = (int) $type_ids[ (string) $type_slug ];
			}
		}
		if ( $types ) {
			wp_set_object_terms( $post_id, $types, EventType::TAXONOMY );
		}

		$image_key = (string) ( $fixture['image'] ?? '' );
		if ( '' !== $image_key ) {
			$attachment_id = ( new Images() )->attach( $post_id, $image_key );

			if ( $attachment_id ) {
				$state['attachments'][] = $attachment_id;
			}
		}

		// Writes the single occurrence row; recurring events replace it below.
		( new IndexBuilder() )->build_for_post( $post_id );

		$state['events'][] = $post_id;

		return $post_id;
	}

	/**
	 * Attach a recurrence rule and expand it into occurrence rows.
	 *
	 * @param int   $post_id Event post ID.
	 * @param array $rule    Rule fields.
	 */
	private function create_recurrence( int $post_id, array $rule ): void {
		( new RuleRepository() )->upsert( $post_id, $rule );

		// Drop the single row build_for_post() just wrote, then let the
		// generator materialise the full series.
		( new EventIndex() )->delete_by_post_id( $post_id );

		do_action( 'blockendar_generate_recurrence_index', $post_id );
	}

	/**
	 * Y-m-d or today.
	 */
	private function valid_date( mixed $value ): string {
		$value = sanitize_text_field( (string) $value );
		$date  = \DateTimeImmutable::createFromFormat( 'Y-m-d', $value );

		return ( $date && $date->format( 'Y-m-d' ) === $value ) ? $value : gmdate( 'Y-m-d' );
	}

	/**
	 * H:i or empty.
	 */
	private function valid_time( mixed $value ): string {
		$value = sanitize_text_field( (string) $value );
		$time  = \DateTimeImmutable::createFromFormat( 'H:i', $value );

		return ( $time && $time->format( 'H:i' ) === $value ) ? $value : '';
	}

	/**
	 * One of the statuses the main plugin accepts.
	 */
	private function valid_status( mixed $value ): string {
		$value = sanitize_text_field( (string) $value );

		return in_array( $value, self::STATUSES, true ) ? $value : 'scheduled';
	}

	/**
	 * A non-negative numeric string, or ''.
	 */
	private function valid_number( mixed $value ): string {
		$value = sanitize_text_field( (string) $value );

		return is_numeric( $value ) && (float) $value >= 0 ? $value : '';
	}
}
