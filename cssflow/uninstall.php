<?php
/**
 * CSSFlow uninstall cleanup.
 *
 * @package CSSFlow
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove CSSFlow-owned data for the current site when the site's uninstall
 * preference explicitly allows destructive cleanup.
 *
 * @return void
 */
function cssflow_uninstall_current_site() {
	global $wpdb;

	$settings = get_option( 'cssflow_settings', array() );

	if (
		! is_array( $settings )
		|| empty( $settings['delete_on_uninstall'] )
	) {
		return;
	}

	/*
	 * Generated CSS is disposable. Reuse the production file manager so
	 * uninstall follows the same ownership rules and never removes unrelated
	 * files from the uploads directory.
	 */
	if ( ! class_exists( 'CSSFlow\\CSS\\File_Manager', false ) ) {
		require_once __DIR__ . '/includes/css/class-file-manager.php';
	}

	$file_manager = new CSSFlow\CSS\File_Manager();
	$file_manager->clear_generated_files();

	$table_name = $wpdb->prefix . 'cssflow_snippets';

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall must remove CSSFlow's own custom table when the user has opted in to data deletion.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema deletion is an intentional direct database operation; caching is not applicable.
	$wpdb->query(
		$wpdb->prepare(
			'DROP TABLE IF EXISTS %i',
			$table_name
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange

	$options = array(
		'cssflow_settings',
		'cssflow_breakpoints',
		'cssflow_variables',
		'cssflow_version',
		'cssflow_db_version',
		'cssflow_generated_version',
	);

	foreach ( $options as $option_name ) {
		delete_option( $option_name );
	}

	$administrator = get_role( 'administrator' );

	if ( null !== $administrator && $administrator->has_cap( 'cssflow_edit_css' ) ) {
		$administrator->remove_cap( 'cssflow_edit_css' );
	}
}

/*
 * Plugin deletion is global on Multisite, while CSSFlow's data and uninstall
 * preference are site-specific. Inspect every site independently and only
 * remove data from sites that explicitly opted in.
 */
if ( is_multisite() ) {
	$cssflow_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $cssflow_site_ids as $cssflow_site_id ) {
		switch_to_blog( (int) $cssflow_site_id );
		cssflow_uninstall_current_site();
		restore_current_blog();
	}
} else {
	cssflow_uninstall_current_site();
}
