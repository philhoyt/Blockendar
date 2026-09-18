/**
 * Tests for the "Ongoing, no end date" toggle logic in DateTimePanel.jsx.
 *
 * The panel passes the result straight to editPost( { meta } ), so the shape
 * returned here is exactly what marks the post dirty and what gets saved.
 */
import { getOngoingMetaUpdates } from '../ongoing';

const baseMeta = {
	blockendar_start_date: '2025-09-13',
	blockendar_start_time: '10:00',
	blockendar_end_date: '2025-09-20',
	blockendar_end_time: '17:00',
	blockendar_ongoing: false,
};

describe( 'getOngoingMetaUpdates', () => {
	test( 'turning on sets the flag and clears the end fields', () => {
		expect( getOngoingMetaUpdates( baseMeta, true ) ).toEqual( {
			blockendar_ongoing: true,
			blockendar_end_date: '',
			blockendar_end_time: '',
		} );
	} );

	test( 'turning off with an end date keeps it', () => {
		expect( getOngoingMetaUpdates( baseMeta, false ) ).toEqual( {
			blockendar_ongoing: false,
		} );
	} );

	test( 'turning off with an empty end seeds it from the start (smart default)', () => {
		const meta = {
			...baseMeta,
			blockendar_end_date: '',
			blockendar_end_time: '',
		};

		expect( getOngoingMetaUpdates( meta, false ) ).toEqual( {
			blockendar_ongoing: false,
			blockendar_end_date: '2025-09-13',
			blockendar_end_time: '11:00',
		} );
	} );

	test( 'the seeded end time wraps at midnight', () => {
		const meta = {
			...baseMeta,
			blockendar_start_time: '23:30',
			blockendar_end_date: '',
			blockendar_end_time: '',
		};

		expect( getOngoingMetaUpdates( meta, false ).blockendar_end_time ).toBe(
			'00:30'
		);
	} );

	test( 'turning off without a start leaves the end fields alone', () => {
		expect( getOngoingMetaUpdates( {}, false ) ).toEqual( {
			blockendar_ongoing: false,
		} );
	} );

	test( 'every patch contains the flag so editPost marks the post dirty', () => {
		expect( getOngoingMetaUpdates( baseMeta, true ) ).toHaveProperty(
			'blockendar_ongoing',
			true
		);
		expect( getOngoingMetaUpdates( baseMeta, false ) ).toHaveProperty(
			'blockendar_ongoing',
			false
		);
	} );
} );
