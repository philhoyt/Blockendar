<?php
/**
 * blockendar/query-filters — server-side render callback.
 *
 * Renders a wrapper div with the queryId as a data attribute, then outputs
 * inner blocks. The wrapper carries no query logic of its own — it exists
 * purely to provide the blockendar/queryId block context to child blocks
 * (filter blocks and the events-query block).
 *
 * @package Blockendar
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$query_id = sanitize_key( $attributes['queryId'] ?? '' );

$wrapper_attrs = [];
if ( '' !== $query_id ) {
	$wrapper_attrs['data-blockendar-query-id'] = $query_id;
}

?>
<div <?php echo get_block_wrapper_attributes( $wrapper_attrs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inner block output ?>
</div>
