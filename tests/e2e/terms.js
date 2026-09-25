/**
 * Idempotent term fixtures for end-to-end tests.
 *
 * Both of the plugin's filterable taxonomies are hierarchical, and WordPress
 * refuses a second term with the same name at the same level — so a spec that
 * simply calls `wp term create` strands every later run the moment one run is
 * interrupted before its afterAll. Look the term up first, by the slug the spec
 * chose and then by name, and only create when neither exists.
 *
 * Slugs are always set explicitly so `wp post term set` and cleanup are
 * unambiguous, and so the lookup does not depend on how WordPress happened to
 * slugify the name.
 */

const { wpCli, wpCliId } = require( './wp-cli' );

/**
 * Return the ID of a term, creating it if it does not exist.
 *
 * @param {string} taxonomy Taxonomy name, e.g. 'blockendar_event_type'.
 * @param {string} name     Human-readable term name.
 * @param {string} slug     Slug to create it with, and to find it by.
 * @return {string} The term ID.
 */
function ensureTerm( taxonomy, name, slug ) {
	for ( const filter of [ `--slug=${ slug }`, `--name=${ name }` ] ) {
		const existing = wpCli( [
			'term',
			'list',
			taxonomy,
			filter,
			'--field=term_id',
		] )
			.split( '\n' )[ 0 ]
			.trim();

		if ( existing ) {
			return existing;
		}
	}

	return wpCliId( [
		'term',
		'create',
		taxonomy,
		name,
		`--slug=${ slug }`,
		'--porcelain',
	] );
}

/**
 * Delete a term by ID. Tolerates a term that is already gone.
 *
 * @param {string} taxonomy Taxonomy name.
 * @param {string} termId   Term ID, as returned by ensureTerm().
 */
function deleteTerm( taxonomy, termId ) {
	try {
		wpCli( [ 'term', 'delete', taxonomy, termId ] );
	} catch {
		// Already deleted, or never created: either way the desired state holds.
	}
}

module.exports = { ensureTerm, deleteTerm };
