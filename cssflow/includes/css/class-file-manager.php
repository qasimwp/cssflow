<?php
/**
 * Manages disposable generated CSS files for CSSFlow.
 *
 * @package CSSFlow
 */

namespace CSSFlow\CSS;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes and removes generated CSSFlow cache files below the uploads directory.
 */
final class File_Manager {

	/**
	 * Generated output directory name.
	 *
	 * @var string
	 */
	private $directory_name = 'cssflow';

	/**
	 * Write one deterministic contextual CSS bundle.
	 *
	 * The generated filename contains the SHA-256 content hash. Identical CSS
	 * therefore reuses the same file and version, while changed CSS receives a
	 * different cache-busting version automatically.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bundle_key Logical bundle key.
	 * @param string $css        Compiled CSS.
	 * @return array<string,string>|WP_Error Generated file metadata or an error.
	 */
	public function write_bundle( $bundle_key, $css ) {
		if ( ! is_string( $bundle_key ) || '' === $bundle_key || sanitize_key( $bundle_key ) !== $bundle_key ) {
			return new WP_Error(
				'cssflow_invalid_bundle_key',
				__( 'The CSSFlow generated bundle key is invalid.', 'cssflow' )
			);
		}

		if ( ! is_string( $css ) ) {
			return new WP_Error(
				'cssflow_invalid_generated_css',
				__( 'The CSSFlow generated CSS value is invalid.', 'cssflow' )
			);
		}

		$location = $this->get_output_location( true );

		if ( is_wp_error( $location ) ) {
			return $location;
		}

		$filesystem = $this->get_filesystem();
		$file_mode  = $this->get_file_permissions();
		$version    = hash( 'sha256', $css );
		$filename   = 'cssflow-' . $bundle_key . '-' . $version . '.css';
		$file_path  = trailingslashit( $location['path'] ) . $filename;
		$file_url   = trailingslashit( $location['url'] ) . rawurlencode( $filename );

		if ( $filesystem->exists( $file_path ) ) {
			$existing_css = $filesystem->get_contents( $file_path );

			if ( is_string( $existing_css ) && hash_equals( $version, hash( 'sha256', $existing_css ) ) ) {
				if ( ! $filesystem->chmod( $file_path, $file_mode ) ) {
					return new WP_Error(
						'cssflow_generated_permissions_failed',
						__( 'CSSFlow could not set safe permissions on the generated CSS file.', 'cssflow' )
					);
				}

				return array(
					'url'      => $file_url,
					'version'  => $version,
					'filename' => $filename,
				);
			}
		}

		$temp_name = $filename . '.tmp-' . strtolower( wp_generate_password( 12, false, false ) );
		$temp_path = trailingslashit( $location['path'] ) . $temp_name;

		if ( ! $filesystem->put_contents( $temp_path, $css, $file_mode ) ) {
			return new WP_Error(
				'cssflow_generated_write_failed',
				__( 'CSSFlow could not write the generated CSS file.', 'cssflow' )
			);
		}

		if ( $filesystem->exists( $file_path ) && ! $filesystem->delete( $file_path, false, 'f' ) ) {
			$filesystem->delete( $temp_path, false, 'f' );

			return new WP_Error(
				'cssflow_generated_replace_failed',
				__( 'CSSFlow could not replace the generated CSS file.', 'cssflow' )
			);
		}

		if ( ! $filesystem->move( $temp_path, $file_path, true ) ) {
			$filesystem->delete( $temp_path, false, 'f' );

			return new WP_Error(
				'cssflow_generated_move_failed',
				__( 'CSSFlow could not finalize the generated CSS file.', 'cssflow' )
			);
		}

		if ( ! $filesystem->chmod( $file_path, $file_mode ) ) {
			$filesystem->delete( $file_path, false, 'f' );

			return new WP_Error(
				'cssflow_generated_permissions_failed',
				__( 'CSSFlow could not set safe permissions on the generated CSS file.', 'cssflow' )
			);
		}

		return array(
			'url'      => $file_url,
			'version'  => $version,
			'filename' => $filename,
		);
	}

	/**
	 * Remove CSSFlow-generated bundle and temporary files.
	 *
	 * Unknown files are left untouched. The database/options remain the source
	 * of truth and are never modified by this operation.
	 *
	 * @since 1.0.0
	 *
	 * @return true|WP_Error True on success or an error.
	 */
	public function clear_generated_files() {
		$location = $this->get_output_location( false );

		if ( is_wp_error( $location ) ) {
			return $location;
		}

		$filesystem = $this->get_filesystem();

		if ( ! $filesystem->is_dir( $location['path'] ) ) {
			return true;
		}

		$files = $filesystem->dirlist( $location['path'], false, false );

		if ( false === $files ) {
			return new WP_Error(
				'cssflow_generated_list_failed',
				__( 'CSSFlow could not inspect the generated CSS directory.', 'cssflow' )
			);
		}

		foreach ( $files as $name => $details ) {
			if ( ! isset( $details['type'] ) || 'f' !== $details['type'] ) {
				continue;
			}

			$is_bundle = 1 === preg_match( '/^cssflow-[a-z0-9_-]+-[a-f0-9]{64}\.css$/D', $name );
			$is_temp   = 1 === preg_match( '/^cssflow-[a-z0-9_-]+-[a-f0-9]{64}\.css\.tmp-[a-z0-9]+$/D', $name );

			if ( ! $is_bundle && ! $is_temp ) {
				continue;
			}

			$file_path = trailingslashit( $location['path'] ) . $name;

			if ( ! $filesystem->delete( $file_path, false, 'f' ) ) {
				return new WP_Error(
					'cssflow_generated_delete_failed',
					__( 'CSSFlow could not remove a generated CSS file.', 'cssflow' )
				);
			}
		}

		return true;
	}

	/**
	 * Inspect generated-output storage without creating or modifying files.
	 *
	 * Diagnostic data intentionally excludes filesystem paths and URLs. If the
	 * CSSFlow directory does not exist yet, writability reflects whether the
	 * WordPress uploads base directory can create it when file output is needed.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,bool> Safe generated-output storage health.
	 */
	public function get_output_health() {
		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return array(
				'available'        => false,
				'directory_exists' => false,
				'writable'         => false,
			);
		}

		$filesystem = $this->get_filesystem();
		$base_path  = untrailingslashit( $uploads['basedir'] );
		$path       = trailingslashit( $base_path ) . $this->directory_name;
		$exists     = $filesystem->is_dir( $path );
		$writable   = $exists
			? $filesystem->is_writable( $path )
			: $filesystem->is_dir( $base_path ) && $filesystem->is_writable( $base_path );

		return array(
			'available'        => true,
			'directory_exists' => (bool) $exists,
			'writable'         => (bool) $writable,
		);
	}

	/**
	 * Resolve the CSSFlow generated-output directory through wp_upload_dir().
	 *
	 * @since 1.0.0
	 *
	 * @param bool $create Whether the directory should be created when missing.
	 * @return array<string,string>|WP_Error Output path/URL or an error.
	 */
	private function get_output_location( $create ) {
		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return new WP_Error(
				'cssflow_uploads_unavailable',
				__( 'The WordPress uploads directory is unavailable to CSSFlow.', 'cssflow' )
			);
		}

		$path = trailingslashit( $uploads['basedir'] ) . $this->directory_name;
		$url  = trailingslashit( $uploads['baseurl'] ) . $this->directory_name;

		if ( $create && ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			return new WP_Error(
				'cssflow_output_directory_create_failed',
				__( 'CSSFlow could not create its generated CSS directory.', 'cssflow' )
			);
		}

		if ( $create ) {
			$filesystem = $this->get_filesystem();

			if ( ! $filesystem->is_dir( $path ) || ! $filesystem->is_writable( $path ) ) {
				return new WP_Error(
					'cssflow_output_directory_unwritable',
					__( 'The CSSFlow generated CSS directory is not writable.', 'cssflow' )
				);
			}
		}

		return array(
			'path' => $path,
			'url'  => $url,
		);
	}

	/**
	 * Determine safe file permissions for generated CSS files.
	 *
	 * FS_CHMOD_FILE is normally initialized by WordPress's filesystem bootstrap,
	 * but CSSFlow intentionally uses WP_Filesystem_Direct for local uploads
	 * without invoking the credentials workflow. Therefore the constant may not
	 * exist on every request.
	 *
	 * Generated stylesheets are public frontend assets. Retain safe file-mode bits
	 * from WordPress or the host, but guarantee the owner-write and public-read
	 * permissions required for normal stylesheet delivery.
	 *
	 * @since 1.0.0
	 *
	 * @return int File permission mode.
	 */
	private function get_file_permissions() {
		if ( defined( 'FS_CHMOD_FILE' ) ) {
			$permissions = (int) FS_CHMOD_FILE;
		} else {
			$permissions = is_dir( ABSPATH ) ? fileperms( ABSPATH ) : false;
		}

		if ( false === $permissions ) {
			return 0644;
		}

		/*
		 * Generated stylesheets are public frontend assets. Preserve any broader
		 * read/write bits supplied by WordPress or the host, while guaranteeing
		 * owner write access and public read access required for normal HTTP use.
		 */
		return (int) ( ( $permissions & 0666 ) | 0644 );
	}

	/**
	 * Get WordPress's direct filesystem implementation.
	 *
	 * CSSFlow writes only below the already-local WordPress uploads directory, so
	 * direct filesystem access is the appropriate filesystem implementation here.
	 *
	 * @since 1.0.0
	 *
	 * @return \WP_Filesystem_Direct Filesystem instance.
	 */
	private function get_filesystem() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

		return new \WP_Filesystem_Direct( null );
	}
}
