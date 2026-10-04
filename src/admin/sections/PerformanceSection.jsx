import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Notice,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { runRebuild } from '../rebuild';

const { statsUrl, rebuildUrl } = window.blockendarSettings ?? {};

/** How often to look again while a background rebuild runs, in milliseconds. */
const POLL_INTERVAL = 5000;

export function PerformanceSection() {
	const [ stats, setStats ] = useState( null );
	const [ rebuilding, setRebuilding ] = useState( false );
	const [ handled, setHandled ] = useState( null );
	const [ notice, setNotice ] = useState( null );

	const loadStats = () => {
		apiFetch( { url: statsUrl } )
			.then( setStats )
			.catch( () => {} );
	};

	useEffect( loadStats, [] );

	// A rebuild queued by an upgrade, or one this page started and left, runs
	// in the background. Keep looking until it is done.
	const inBackground = ! rebuilding && !! stats?.rebuild_in_progress;

	useEffect( () => {
		if ( ! inBackground ) {
			return undefined;
		}

		const timer = setInterval( loadStats, POLL_INTERVAL );

		return () => clearInterval( timer );
	}, [ inBackground ] );

	const handleRebuild = async () => {
		setRebuilding( true );
		setNotice( null );

		try {
			const result = await runRebuild(
				() => apiFetch( { url: rebuildUrl, method: 'POST' } ),
				{
					onProgress: ( pass ) =>
						setHandled( pass.rebuilt + pass.skipped ),
				}
			);
			setNotice( {
				type: 'success',
				/*
				 * One sentence with positional placeholders, not concatenated
				 * fragments: a translator handed "events indexed," and
				 * "skipped." separately has no sentence to place them in and
				 * no way to reorder the numbers around them.
				 */
				message: sprintf(
					/* translators: 1: number of events indexed, 2: number skipped. */
					_n(
						'%1$d event indexed, %2$d skipped.',
						'%1$d events indexed, %2$d skipped.',
						result.rebuilt,
						'blockendar'
					),
					result.rebuilt,
					result.skipped
				),
			} );
			loadStats();
		} catch ( e ) {
			setNotice( {
				type: 'error',
				message: e?.message ?? __( 'Rebuild failed.', 'blockendar' ),
			} );
			// It may be carrying on in the background; the table says so.
			loadStats();
		} finally {
			setRebuilding( false );
			setHandled( null );
		}
	};

	return (
		<VStack spacing={ 5 }>
			<h2>{ __( 'Performance', 'blockendar' ) }</h2>

			{ notice && (
				<Notice
					status={ notice.type }
					isDismissible
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			{ inBackground && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'The event index is being rebuilt in the background. Events stay available while it runs.',
						'blockendar'
					) }
				</Notice>
			) }

			{ stats && (
				<table className="blockendar-stats-table">
					<tbody>
						<tr>
							<th>{ __( 'Index row count', 'blockendar' ) }</th>
							<td>{ stats.index_row_count.toLocaleString() }</td>
						</tr>
						<tr>
							<th>{ __( 'Last full rebuild', 'blockendar' ) }</th>
							<td>
								{ stats.last_rebuild ??
									__( 'Never', 'blockendar' ) }
							</td>
						</tr>
						<tr>
							<th>{ __( 'Database version', 'blockendar' ) }</th>
							<td>{ stats.db_version }</td>
						</tr>
						<tr>
							<th>{ __( 'Plugin version', 'blockendar' ) }</th>
							<td>{ stats.plugin_version }</td>
						</tr>
					</tbody>
				</table>
			) }

			<div>
				<Button
					variant="secondary"
					isBusy={ rebuilding }
					disabled={ rebuilding }
					onClick={ handleRebuild }
				>
					{ rebuilding
						? __( 'Rebuilding…', 'blockendar' )
						: __( 'Rebuild Event Index', 'blockendar' ) }
				</Button>
				{ rebuilding && null !== handled && (
					<p aria-live="polite">
						{ sprintf(
							/* translators: %d: number of events processed so far. */
							_n(
								'%d event so far.',
								'%d events so far.',
								handled,
								'blockendar'
							),
							handled
						) }
					</p>
				) }
				<p className="description">
					{ __(
						'Regenerates the event occurrence index from all published events. Events stay available while it runs. Use after bulk imports.',
						'blockendar'
					) }
				</p>
			</div>
		</VStack>
	);
}
