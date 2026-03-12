import {
	RangeControl,
	SelectControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const STRATEGY_OPTIONS = [
	{
		label: __(
			'On save (synchronous for small sets, background for large)',
			'blockendar'
		),
		value: 'on_save',
	},
	{
		label: __(
			'Cron only (generate instances via daily WP-Cron job)',
			'blockendar'
		),
		value: 'cron',
	},
];

export function RecurringSection( { settings, update } ) {
	return (
		<VStack spacing={ 5 }>
			<h2>{ __( 'Recurring Events', 'blockendar' ) }</h2>

			<RangeControl
				label={ __( 'Lookahead horizon (days)', 'blockendar' ) }
				help={ __(
					'How far into the future to pre-generate recurrence instances. ' +
						'Higher values mean more index rows but fewer on-demand generations.',
					'blockendar'
				) }
				value={ settings.horizon_days ?? 365 }
				min={ 30 }
				max={ 3650 }
				step={ 30 }
				onChange={ ( val ) => update( { horizon_days: val } ) }
				__nextHasNoMarginBottom
			/>

			<RangeControl
				label={ __( 'Max instances per event', 'blockendar' ) }
				help={ __(
					'Hard cap on occurrences generated per recurring event.',
					'blockendar'
				) }
				value={ settings.max_instances ?? 3650 }
				min={ 10 }
				max={ 3650 }
				step={ 10 }
				onChange={ ( val ) => update( { max_instances: val } ) }
				__nextHasNoMarginBottom
			/>

			<SelectControl
				label={ __( 'Generation strategy', 'blockendar' ) }
				value={ settings.generation_strategy ?? 'on_save' }
				options={ STRATEGY_OPTIONS }
				onChange={ ( val ) => update( { generation_strategy: val } ) }
				__nextHasNoMarginBottom
			/>
		</VStack>
	);
}
