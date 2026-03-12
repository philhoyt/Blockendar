<?php
/**
 * Importer for The Events Calendar (tribe_events) WXR exports.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Import;

use Blockendar\CPT\EventPostType;
use Blockendar\Taxonomy\EventType;
use Blockendar\DB\IndexBuilder;

/**
 * Parses a WordPress WXR file exported from The Events Calendar and creates
 * Blockendar events from tribe_events items.
 */
class TribeImporter {

	/**
	 * Import events from raw WXR XML.
	 *
	 * @param string $xml     Raw XML content.
	 * @param bool   $dry_run If true, parse and validate without writing anything.
	 * @return array{ imported: int, skipped: int, errors: string[], events: list<array> }
	 */
	public function import( string $xml, bool $dry_run = false ): array {
		$results = [
			'imported' => 0,
			'skipped'  => 0,
			'errors'   => [],
			'events'   => [],
		];

		libxml_use_internal_errors( true );
		$dom = new \DOMDocument();
		$ok  = $dom->loadXML( $xml, LIBXML_NOCDATA );

		if ( ! $ok ) {
			$errors              = libxml_get_errors();
			$results['errors'][] = ! empty( $errors )
				? $errors[0]->message
				: 'Failed to parse XML.';
			return $results;
		}

		$xpath = new \DOMXPath( $dom );
		$xpath->registerNamespace( 'wp', 'http://wordpress.org/export/1.2/' );
		$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
		$xpath->registerNamespace( 'dc', 'http://purl.org/dc/elements/1.1/' );

		$items = $xpath->query( '//item[wp:post_type[normalize-space()="tribe_events"]]' );

		if ( ! $items || 0 === $items->length ) {
			$results['errors'][] = 'No tribe_events items found in the XML file.';
			return $results;
		}

		$builder = new IndexBuilder();

		foreach ( $items as $item ) {
			$result = $this->import_item( $xpath, $item, $builder, $dry_run );

			$results['events'][] = $result;

			if ( 'imported' === $result['status'] ) {
				++$results['imported'];
			} elseif ( 'skipped' === $result['status'] ) {
				++$results['skipped'];
			} else {
				$results['errors'][] = $result['message'];
			}
		}

		return $results;
	}

	/**
	 * Import a single WXR item.
	 *
	 * @param \DOMXPath    $xpath   XPath evaluator.
	 * @param \DOMElement  $item    The <item> element.
	 * @param IndexBuilder $builder Index builder for post-insert indexing.
	 * @param bool         $dry_run Skip writes when true.
	 * @return array{ title: string, status: string, message: string }
	 */
	private function import_item(
		\DOMXPath $xpath,
		\DOMElement $item,
		IndexBuilder $builder,
		bool $dry_run
	): array {
		$title   = $this->node_text( $xpath, 'title', $item );
		$slug    = $this->node_text( $xpath, 'wp:post_name', $item );
		$status  = $this->node_text( $xpath, 'wp:status', $item );
		$content = $this->node_text( $xpath, 'content:encoded', $item );
		$pub_gmt = $this->node_text( $xpath, 'wp:post_date_gmt', $item );

		$post_status = in_array( $status, [ 'publish', 'draft', 'private' ], true )
			? $status
			: 'publish';

		// Build meta map.
		$meta = $this->extract_meta( $xpath, $item );

		$start_raw = $meta['_EventStartDate'] ?? '';
		$end_raw   = $meta['_EventEndDate'] ?? '';
		// TEC v5+ stores 'yes'; older versions stored '1'.
		$all_day_raw = strtolower( trim( $meta['_EventAllDay'] ?? '' ) );
		$all_day     = in_array( $all_day_raw, [ '1', 'yes', 'true' ], true );
		$timezone    = $meta['_EventTimezone'] ?? '';
		$cost        = $meta['_EventCost'] ?? '';
		$url         = $meta['_EventURL'] ?? '';

		if ( ! $start_raw ) {
			return [
				'title'   => $title,
				'status'  => 'error',
				'message' => "Missing start date: {$title}",
			];
		}

		// Parse via DateTime so the format (space or T separator) doesn't matter.
		$start_dt = date_create( $start_raw );
		$end_dt   = $end_raw ? date_create( $end_raw ) : null;

		if ( ! $start_dt ) {
			return [
				'title'   => $title,
				'status'  => 'error',
				'message' => "Could not parse start date \"{$start_raw}\": {$title}",
			];
		}

		$start_date = $start_dt->format( 'Y-m-d' );
		$end_date   = $end_dt ? $end_dt->format( 'Y-m-d' ) : $start_date;

		// For all-day events don't store a time. For timed events keep whatever TEC stored,
		// except strip the 23:59:59 TEC all-day sentinel on the end time.
		if ( $all_day ) {
			$start_time = '';
			$end_time   = '';
		} else {
			$start_time   = $start_dt->format( 'H:i' );
			$raw_end_time = $end_dt ? $end_dt->format( 'H:i' ) : '';
			$end_time     = ( '23:59' === $raw_end_time ) ? '' : $raw_end_time;
		}

		// Check for existing post by slug — update it rather than skip.
		$existing_id = null;
		if ( $slug ) {
			$existing = get_page_by_path( $slug, OBJECT, EventPostType::POST_TYPE );
			if ( $existing ) {
				$existing_id = (int) $existing->ID;
			}
		}

		if ( $dry_run ) {
			return [
				'title'   => $title,
				'status'  => 'imported',
				'message' => $existing_id ? '(dry run — would update)' : '(dry run)',
			];
		}

		if ( $existing_id ) {
			$post_id = wp_update_post(
				[
					'ID'           => $existing_id,
					'post_title'   => wp_strip_all_tags( $title ),
					'post_content' => $content,
					'post_status'  => $post_status,
				],
				true
			);
		} else {
			$post_id = wp_insert_post(
				[
					'post_title'    => wp_strip_all_tags( $title ),
					'post_name'     => $slug,
					'post_content'  => $content,
					'post_status'   => $post_status,
					'post_type'     => EventPostType::POST_TYPE,
					'post_date_gmt' => $pub_gmt ?: current_time( 'mysql', true ),
				],
				true
			);
		}

		if ( is_wp_error( $post_id ) ) {
			return [
				'title'   => $title,
				'status'  => 'error',
				'message' => $post_id->get_error_message(),
			];
		}

		// Set event meta.
		update_post_meta( $post_id, 'blockendar_start_date', $start_date );
		update_post_meta( $post_id, 'blockendar_end_date', $end_date );
		update_post_meta( $post_id, 'blockendar_start_time', $start_time );
		update_post_meta( $post_id, 'blockendar_end_time', $end_time );
		update_post_meta( $post_id, 'blockendar_all_day', $all_day ? '1' : '' );

		if ( $timezone ) {
			update_post_meta( $post_id, 'blockendar_timezone', $timezone );
		}
		if ( $cost ) {
			update_post_meta( $post_id, 'blockendar_cost', sanitize_text_field( $cost ) );
		}
		if ( $url ) {
			update_post_meta( $post_id, 'blockendar_registration_url', esc_url_raw( $url ) );
		}

		// Map tribe_events_cat → event_type.
		$this->assign_categories( $xpath, $item, $post_id );

		// Build index with complete meta now set.
		$builder->build_for_post( $post_id );

		return [
			'title'   => $title,
			'status'  => 'imported',
			'message' => '',
		];
	}

	/**
	 * Build a key→value map of all wp:postmeta for an item.
	 *
	 * @param \DOMXPath   $xpath XPath evaluator.
	 * @param \DOMElement $item  The <item> element.
	 * @return array<string,string>
	 */
	private function extract_meta( \DOMXPath $xpath, \DOMElement $item ): array {
		$map   = [];
		$nodes = $xpath->query( 'wp:postmeta', $item );

		if ( ! $nodes ) {
			return $map;
		}

		foreach ( $nodes as $node ) {
			$key   = $this->node_text( $xpath, 'wp:meta_key', $node );
			$value = $this->node_text( $xpath, 'wp:meta_value', $node );
			if ( $key ) {
				$map[ $key ] = $value;
			}
		}

		return $map;
	}

	/**
	 * Read tribe_events_cat category elements and assign to event_type taxonomy.
	 * Terms are created if they don't already exist.
	 *
	 * @param \DOMXPath   $xpath   XPath evaluator.
	 * @param \DOMElement $item    The <item> element.
	 * @param int         $post_id Target post ID.
	 */
	private function assign_categories( \DOMXPath $xpath, \DOMElement $item, int $post_id ): void {
		$nodes = $xpath->query( 'category[@domain="tribe_events_cat"]', $item );

		if ( ! $nodes || 0 === $nodes->length ) {
			return;
		}

		$term_ids = [];

		foreach ( $nodes as $node ) {
			$slug = $node->getAttribute( 'nicename' );
			$name = trim( $node->nodeValue ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

			if ( ! $slug || ! $name ) {
				continue;
			}

			$term = get_term_by( 'slug', $slug, EventType::TAXONOMY );

			if ( $term ) {
				$term_ids[] = (int) $term->term_id;
			} else {
				$inserted = wp_insert_term( $name, EventType::TAXONOMY, [ 'slug' => $slug ] );
				if ( ! is_wp_error( $inserted ) ) {
					$term_ids[] = (int) $inserted['term_id'];
				}
			}
		}

		if ( $term_ids ) {
			wp_set_object_terms( $post_id, $term_ids, EventType::TAXONOMY );
		}
	}

	/**
	 * Get the trimmed text content of the first matching XPath node.
	 *
	 * @param \DOMXPath $xpath   XPath evaluator.
	 * @param string    $query   XPath expression.
	 * @param \DOMNode  $context Context node.
	 * @return string
	 */
	private function node_text( \DOMXPath $xpath, string $query, \DOMNode $context ): string {
		$nodes = $xpath->query( $query, $context );
		return ( $nodes && $nodes->length > 0 ) ? trim( $nodes->item( 0 )->nodeValue ) : '';
	}
}
