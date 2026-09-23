<?php
/**
 * Tools > Blockendar Demo.
 *
 * @package BlockendarDemo
 */

declare( strict_types=1 );

namespace Blockendar\Demo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screen for seeding and resetting the demo dataset.
 */
class AdminPage {

	/**
	 * Menu slug.
	 */
	private const SLUG = 'blockendar-demo';

	/**
	 * Separate nonce actions, so a seed form cannot be replayed as a reset.
	 */
	private const SEED_ACTION  = 'blockendar_demo_seed';
	private const RESET_ACTION = 'blockendar_demo_reset';

	/**
	 * Transient carrying the result across the post/redirect/get.
	 */
	private const RESULT_KEY = 'blockendar_demo_result';

	/**
	 * Attach hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
		add_action( 'admin_post_' . self::SEED_ACTION, [ $this, 'handle_seed' ] );
		add_action( 'admin_post_' . self::RESET_ACTION, [ $this, 'handle_reset' ] );
	}

	/**
	 * Register the Tools submenu.
	 */
	public function add_page(): void {
		add_management_page(
			'Blockendar Demo',
			'Blockendar Demo',
			'manage_options',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	/**
	 * Seed handler.
	 */
	public function handle_seed(): void {
		$this->authorize( self::SEED_ACTION );

		$result = ( new Seeder() )->seed();

		if ( $result['skipped'] ) {
			$refreshed = (int) $result['refreshed'];

			$message = $refreshed
				? sprintf(
					'Demo content is already installed. Rebuilt %d tour page(s) with the current layout; the events were left alone. Reset first if you want a fresh dataset.',
					$refreshed
				)
				: 'Demo content is already installed, and no demo tour pages were found to rebuild. Reset it first to re-seed.';
		} else {
			$message = sprintf(
				'Created %d events and %d pages.',
				(int) $result['created'],
				(int) $result['pages']
			);

			if ( ! empty( $result['errors'] ) ) {
				$message .= ' ' . count( $result['errors'] ) . ' fixture(s) reported a problem: '
					. implode( ' | ', array_map( 'sanitize_text_field', $result['errors'] ) );
			}
		}

		$this->redirect_with( $message );
	}

	/**
	 * Reset handler.
	 */
	public function handle_reset(): void {
		$this->authorize( self::RESET_ACTION );

		$result = ( new Seeder() )->reset();

		$this->redirect_with(
			sprintf(
				'Removed %d events, %d pages and %d terms.',
				(int) $result['events'],
				(int) $result['pages'],
				(int) $result['terms']
			)
		);
	}

	/**
	 * Capability + nonce check for one specific action.
	 */
	private function authorize( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to manage demo content.', '', [ 'response' => 403 ] );
		}

		check_admin_referer( $action );

		if ( ! Dependency::is_satisfied() ) {
			wp_die( esc_html( Dependency::failure_reason() ), '', [ 'response' => 409 ] );
		}
	}

	/**
	 * Store the outcome and bounce back to the page (post/redirect/get).
	 */
	private function redirect_with( string $message ): void {
		set_transient( self::RESULT_KEY, $message, MINUTE_IN_SECONDS );

		wp_safe_redirect( admin_url( 'tools.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$seeded = ( new Seeder() )->is_seeded();
		$result = get_transient( self::RESULT_KEY );

		if ( is_string( $result ) && '' !== $result ) {
			delete_transient( self::RESULT_KEY );
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( $result )
			);
		}

		?>
		<div class="wrap">
			<h1>Blockendar Demo</h1>
			<p>
				Seeds this site with demo events, venues and a six-page guided tour.
				Every date is generated relative to the moment you seed, so the demo never goes stale.
			</p>
			<p>
				<strong>Status:</strong>
				<?php echo $seeded ? 'Demo content is installed.' : 'No demo content installed.'; ?>
			</p>

			<?php if ( $seeded ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::RESET_ACTION ); ?>">
					<?php wp_nonce_field( self::RESET_ACTION ); ?>
					<p>
						<button type="submit" class="button button-secondary">Reset demo content</button>
					</p>
					<p class="description">
						Permanently deletes the events, pages, terms and images this demo created,
						and restores your previous front page setting. Content you created yourself is not touched.
					</p>
				</form>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::SEED_ACTION ); ?>">
					<?php wp_nonce_field( self::SEED_ACTION ); ?>
					<p>
						<button type="submit" class="button button-primary">Seed demo content</button>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
