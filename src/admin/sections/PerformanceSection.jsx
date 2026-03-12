import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Notice,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const { statsUrl, rebuildUrl } = window.blockendarSettings ?? {};

export function PerformanceSection() {
	const [ stats, setStats ] = useState( null );
	const [ rebuilding, setRebuilding ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const loadStats = () => {
		apiFetch( { url: statsUrl } )
			.then( setStats )
			.catch( () => {} );
	};

	useEffect( loadStats, [] );

	const handleRebuild = async () => {
		setRebuilding( true );
		setNotice( null );

		try {
			const result = await apiFetch( {
				url: rebuildUrl,
				method: 'POST',
			} );
			setNotice( {
				type: 'success',
				message:
					result.rebuilt +
					' ' +
					__( 'events indexed,', 'blockendar' ) +
					' ' +
					result.skipped +
					' ' +
					__( 'skipped.', 'blockendar' ),
			} );
			loadStats();
		} catch ( e ) {
			setNotice( {
				type: 'error',
				message: e?.message ?? __( 'Rebuild failed.', 'blockendar' ),
			} );
		} finally {
			setRebuilding( false );
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
				<p className="description">
					{ __(
						'Clears and regenerates the event occurrence index from all published events. Use after bulk imports or plugin upgrades.',
						'blockendar'
					) }
				</p>
			</div>
		</VStack>
	);
}
