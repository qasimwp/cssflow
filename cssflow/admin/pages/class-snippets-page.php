<?php
/**
 * Renders the CSSFlow All CSS administration page.
 *
 * @package CSSFlow
 */

namespace CSSFlow\Admin\Pages;

use CSSFlow\Admin\Snippets_List_Table;
use CSSFlow\Database\Snippet_Repository;
use CSSFlow\Support\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All CSS page controller.
 */
final class Snippets_Page {

	/**
	 * Snippet repository.
	 *
	 * @var Snippet_Repository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Snippet_Repository $repository Snippet repository.
	 */
	public function __construct( Snippet_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Render the All CSS admin screen.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( Capabilities::get_edit_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage CSSFlow snippets.', 'cssflow' ) );
		}

		$table = new Snippets_List_Table( $this->repository );
		$table->prepare_items();
		?>
		<div class="wrap cssflow-snippets-page">
			<hr class="wp-header-end" />
			<div class="cssflow-snippets-header">
				<div class="cssflow-snippets-title-row">
					<h1><?php esc_html_e( 'All CSS', 'cssflow' ); ?></h1>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=cssflow-add-css' ) ); ?>" class="button cssflow-primary-button cssflow-add-snippet-button">
						<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
						<?php esc_html_e( 'Add CSS', 'cssflow' ); ?>
					</a>
				</div>
				<p><?php esc_html_e( 'Manage where your CSS runs, its responsive target, and whether it is active.', 'cssflow' ); ?></p>
			</div>

			<?php $this->render_notice(); ?>

			<div class="cssflow-snippets-toolbar">
				<div class="cssflow-snippets-views">
					<?php $table->views(); ?>
				</div>

				<form method="get" class="cssflow-snippets-search">
					<input type="hidden" name="page" value="cssflow" />
					<?php $table->search_box( __( 'Search CSS', 'cssflow' ), 'cssflow-snippets' ); ?>
				</form>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cssflow-snippets-table-form">
				<input type="hidden" name="action" value="cssflow_bulk_snippets" />
				<?php wp_nonce_field( 'cssflow_bulk_snippets', 'cssflow_bulk_nonce' ); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a sanitized admin notice from redirect query arguments.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_notice() {
		$user_id = get_current_user_id();
		$flash   = $user_id > 0 ? get_transient( 'cssflow_list_notice_' . $user_id ) : false;

		if ( $user_id > 0 ) {
			delete_transient( 'cssflow_list_notice_' . $user_id );
		}

		$notice = is_array( $flash ) && isset( $flash['notice'] )
			? sanitize_key( (string) $flash['notice'] )
			: '';
		$count  = is_array( $flash ) && isset( $flash['count'] )
			? absint( $flash['count'] )
			: 0;
		$error  = is_array( $flash ) && isset( $flash['error'] )
			? sanitize_key( (string) $flash['error'] )
			: '';

		$action_failed_message = __( 'CSSFlow could not complete the requested action.', 'cssflow' );

		if ( 'cssflow_invalid_target_post_type' === $error ) {
			$action_failed_message = __( 'This snippet targets a content type that is currently unavailable. Activate the plugin or theme that provides it, or edit the snippet and choose another target before activating it.', 'cssflow' );
		} elseif ( 'cssflow_invalid_target_ids' === $error ) {
			$action_failed_message = __( 'One or more content items targeted by this snippet are no longer available. Edit the snippet before activating it.', 'cssflow' );
		} elseif ( 'cssflow_woocommerce_unavailable' === $error ) {
			$action_failed_message = __( 'This snippet uses WooCommerce targeting, but WooCommerce is not active. Activate WooCommerce or edit the snippet before activating it.', 'cssflow' );
		} elseif ( 'cssflow_invalid_breakpoint' === $error ) {
			$action_failed_message = __( 'This snippet uses a custom breakpoint that is no longer available. Edit the snippet and choose a valid responsive target before activating it.', 'cssflow' );
		}

		$messages = array(
			'enabled'         => array( 'success', __( 'CSS snippet activated.', 'cssflow' ) ),
			'disabled'        => array( 'success', __( 'CSS snippet deactivated.', 'cssflow' ) ),
			'deleted'         => array( 'success', __( 'CSS snippet deleted.', 'cssflow' ) ),
			'not_found'       => array( 'error', __( 'The requested CSS snippet could not be found.', 'cssflow' ) ),
			'invalid_action'  => array( 'error', __( 'The requested CSSFlow action was invalid.', 'cssflow' ) ),
			'action_failed'   => array( 'error', $action_failed_message ),
			'invalid_bulk'    => array( 'error', __( 'Choose at least one snippet and a valid bulk action.', 'cssflow' ) ),
			'bulk_no_changes' => array( 'warning', __( 'No snippets were changed.', 'cssflow' ) ),
			'cache_warning'   => array( 'warning', __( 'The snippet change was saved, but CSSFlow could not clear old generated cache files. Frontend output will still be rebuilt from current source data.', 'cssflow' ) ),
		);

		$bulk_messages = array(
			/* translators: %d: number of activated snippets. */
			'bulk_activated'   => _n( '%d CSS snippet activated.', '%d CSS snippets activated.', $count, 'cssflow' ),
			/* translators: %d: number of deactivated snippets. */
			'bulk_deactivated' => _n( '%d CSS snippet deactivated.', '%d CSS snippets deactivated.', $count, 'cssflow' ),
			/* translators: %d: number of permanently deleted snippets. */
			'bulk_deleted'     => _n( '%d CSS snippet deleted permanently.', '%d CSS snippets deleted permanently.', $count, 'cssflow' ),
		);

		if ( 'bulk_activated_partial' === $notice ) {
			$this->print_notice(
				'warning',
				sprintf(
					/* translators: %d: number of snippets successfully activated. */
					_n( '%d CSS snippet activated. Some selected snippets could not be activated because their saved targets are unavailable.', '%d CSS snippets activated. Some selected snippets could not be activated because their saved targets are unavailable.', $count, 'cssflow' ),
					$count
				)
			);
			return;
		}

		if ( isset( $bulk_messages[ $notice ] ) ) {
			$this->print_notice( 'success', sprintf( $bulk_messages[ $notice ], $count ) );
			return;
		}

		if ( isset( $messages[ $notice ] ) ) {
			$this->print_notice( $messages[ $notice ][0], $messages[ $notice ][1] );
		}
	}

	/**
	 * Print a WordPress admin notice.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type    Notice type.
	 * @param string $message Notice message.
	 * @return void
	 */
	private function print_notice( $type, $message ) {
		$allowed = array( 'success', 'warning', 'error', 'info' );
		$type    = in_array( $type, $allowed, true ) ? $type : 'info';
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible inline">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}
}
