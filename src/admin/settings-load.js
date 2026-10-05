/**
 * What the settings page makes of the settings endpoint's answer.
 *
 * WordPress answers null for a setting whose stored value does not fit its
 * schema. That is not "nothing saved yet": the settings exist and could not be
 * shown. Treating it as empty left the page showing the defaults as though
 * they were the saved values, and Save then wrote them over the real ones. So a
 * missing value is a failure to load, and the page must not save.
 *
 * Kept apart from the page so it can be tested without rendering it.
 */

/**
 * Read the plugin's settings out of the endpoint's response.
 *
 * @param {Object|null|undefined} data       Response from /wp/v2/settings.
 * @param {string}                optionName The setting's name.
 * @return {{ok: boolean, settings: Object}} Whether they loaded, and what they are.
 */
export function readSettings( data, optionName ) {
	const value = data?.[ optionName ];

	if ( ! value || typeof value !== 'object' || Array.isArray( value ) ) {
		return { ok: false, settings: {} };
	}

	return { ok: true, settings: value };
}
