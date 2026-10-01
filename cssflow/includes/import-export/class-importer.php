<?php
/**
 * Validates CSSFlow JSON imports and builds a no-write preview.
 *
 * @package CSSFlow
 */

namespace CSSFlow\Import_Export;

use CSSFlow\CSS\Compiler;
use CSSFlow\CSS\Output_Manager;
use CSSFlow\CSS\Variables;
use CSSFlow\Database\Snippet_Repository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSSFlow JSON import validation and preview service.
 */
final class Importer {

	/**
	 * Supported JSON backup schema.
	 *
	 * @var int
	 */
	const SCHEMA_VERSION = 1;

	/**
	 * Maximum JSON backup size accepted by CSSFlow before WordPress/PHP limits.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_IMPORT_SIZE = 5242880;

	/**
	 * Snippet repository.
	 *
	 * @var Snippet_Repository
	 */
	private $repository;

	/**
	 * Breakpoint validation service.
	 *
	 * @var Compiler
	 */
	private $compiler;

	/**
	 * CSS Variables service.
	 *
	 * @var Variables
	 */
	private $variables;

	/**
	 * Generated-output manager.
	 *
	 * @var Output_Manager
	 */
	private $output_manager;

	/**
	 * Constructor.
	 *
	 * @param Snippet_Repository $repository Snippet repository.
	 * @param Compiler           $compiler       Breakpoint validator.
	 * @param Variables          $variables      CSS Variables service.
	 * @param Output_Manager     $output_manager Generated-output manager.
	 */
	public function __construct( Snippet_Repository $repository, Compiler $compiler, Variables $variables, Output_Manager $output_manager ) {
		$this->repository     = $repository;
		$this->compiler       = $compiler;
		$this->variables      = $variables;
		$this->output_manager = $output_manager;
	}

	/**
	 * Return the effective import size limit.
	 *
	 * @return int Size limit in bytes.
	 */
	public function get_max_import_size() {
		$wp_limit = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 0;

		if ( $wp_limit > 0 ) {
			return min( self::DEFAULT_MAX_IMPORT_SIZE, $wp_limit );
		}

		return self::DEFAULT_MAX_IMPORT_SIZE;
	}

	/**
	 * Validate a PHP upload array and build an in-memory preview.
	 *
	 * This method performs no database, option, or filesystem writes.
	 *
	 * @param array $file One item from $_FILES.
	 * @return array|WP_Error Preview data or validation error.
	 */
	public function preview_uploaded_file( array $file ) {
		$upload_error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_OK !== $upload_error ) {
			return $this->upload_error( $upload_error );
		}

		$name     = isset( $file['name'] ) && is_scalar( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
		$tmp_name = isset( $file['tmp_name'] ) && is_scalar( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$size     = isset( $file['size'] ) ? absint( $file['size'] ) : 0;
		$type     = isset( $file['type'] ) && is_scalar( $file['type'] ) ? strtolower( trim( (string) $file['type'] ) ) : '';

		if ( '' === $name || '' === $tmp_name || ! is_readable( $tmp_name ) ) {
			return new WP_Error( 'cssflow_import_unreadable_file', __( 'CSSFlow could not read the selected backup file.', 'cssflow' ) );
		}

		if ( 0 === $size ) {
			$detected_size = filesize( $tmp_name );
			$size          = false === $detected_size ? 0 : (int) $detected_size;
		}

		if ( $size < 1 ) {
			return new WP_Error( 'cssflow_import_empty_file', __( 'The selected backup file is empty.', 'cssflow' ) );
		}

		if ( $size > $this->get_max_import_size() ) {
			return new WP_Error(
				'cssflow_import_file_too_large',
				sprintf(
					/* translators: %s: Maximum file size. */
					__( 'The backup file is too large. The maximum allowed size is %s.', 'cssflow' ),
					size_format( $this->get_max_import_size() )
				)
			);
		}

		$filetype = wp_check_filetype( $name, array( 'json' => 'application/json' ) );

		if ( 'json' !== $filetype['ext'] ) {
			return new WP_Error( 'cssflow_import_invalid_file_type', __( 'Select a CSSFlow JSON backup file ending in .json.', 'cssflow' ) );
		}

		$allowed_types = array( '', 'application/json', 'text/json', 'text/plain', 'application/octet-stream' );

		if ( ! in_array( $type, $allowed_types, true ) ) {
			return new WP_Error( 'cssflow_import_invalid_mime_type', __( 'The selected file type is not supported. Choose a JSON backup exported by CSSFlow.', 'cssflow' ) );
		}

		$contents = file_get_contents( $tmp_name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading PHP's temporary upload only.

		if ( false === $contents || '' === $contents ) {
			return new WP_Error( 'cssflow_import_read_failed', __( 'CSSFlow could not read the selected backup file.', 'cssflow' ) );
		}

		$preview = $this->preview_json( $contents );

		if ( is_wp_error( $preview ) ) {
			return $preview;
		}

		$preview['file'] = array(
			'name' => $name,
			'size' => $size,
		);

		return $preview;
	}

	/**
	 * Parse and validate JSON backup contents without writing anything.
	 *
	 * @param string $json Raw JSON.
	 * @return array|WP_Error Preview data or validation error.
	 */
	public function preview_json( $json ) {
		if ( ! is_string( $json ) || '' === trim( $json ) ) {
			return new WP_Error( 'cssflow_import_empty_json', __( 'The selected backup file is empty.', 'cssflow' ) );
		}

		$document = json_decode( $json, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $document ) ) {
			return new WP_Error( 'cssflow_import_invalid_json', __( 'This file does not contain valid JSON.', 'cssflow' ) );
		}

		$header = $this->validate_document_header( $document );

		if ( is_wp_error( $header ) ) {
			return $header;
		}

		$breakpoints_result = $this->validate_breakpoints_section( $document['breakpoints'] );
		$variables_result   = $this->validate_variables_section( $document['variables'] );
		$settings_result    = $this->validate_settings_section( $document['settings'] );
		$breakpoints        = $breakpoints_result['valid'] ? $breakpoints_result['data'] : array();
		$snippets           = $this->validate_snippets( $document['snippets'], $breakpoints );
		$blocking_errors    = ! $breakpoints_result['valid'] || ! $variables_result['valid'] || ! $settings_result['valid'];

		foreach ( $snippets as $snippet ) {
			if ( ! $snippet['valid'] ) {
				$blocking_errors = true;
				break;
			}
		}

		$valid_count    = 0;
		$invalid_count  = 0;
		$conflict_count = 0;
		$warning_count  = 0;

		foreach ( $snippets as $snippet ) {
			$snippet['valid'] ? ++$valid_count : ++$invalid_count;
			if ( ! empty( $snippet['warning'] ) ) {
				++$warning_count;
			}
			if ( ! empty( $snippet['conflict']['exists'] ) ) {
				++$conflict_count;
			}
		}

		return array(
			'metadata'            => $header,
			'snippets'            => $snippets,
			'breakpoints'         => $breakpoints_result,
			'variables'           => $variables_result,
			'settings'            => $settings_result,
			'counts'              => array(
				'snippets'    => count( $snippets ),
				'valid'       => $valid_count,
				'invalid'     => $invalid_count,
				'conflicts'   => $conflict_count,
				'warnings'    => $warning_count,
				'breakpoints' => $breakpoints_result['valid'] ? count( $breakpoints_result['data'] ) : 0,
				'variables'   => $variables_result['valid'] ? count( $variables_result['data'] ) : 0,
			),
			'has_blocking_errors' => $blocking_errors,
			'has_warnings'        => $warning_count > 0,
		);
	}


	/**
	 * Build the compact, normalized payload posted back by the conflict form.
	 *
	 * The browser is never trusted as the authority. commit_payload() parses and
	 * validates this document again immediately before any write occurs.
	 *
	 * @param array $preview Previously validated preview.
	 * @return string|WP_Error Compact JSON payload or an error.
	 */
	public function build_commit_payload( array $preview ) {
		if ( ! empty( $preview['has_blocking_errors'] ) ) {
			return new WP_Error( 'cssflow_import_not_ready', __( 'Resolve the highlighted backup issues before importing.', 'cssflow' ) );
		}

		$snippets = array();

		$portable_fields = array( 'name', 'description', 'css_code', 'scope_type', 'scope_data', 'responsive_type', 'breakpoint_key', 'priority', 'status' );

		foreach ( $preview['snippets'] as $snippet ) {
			if ( empty( $snippet['valid'] ) || ! isset( $snippet['data'] ) || ! is_array( $snippet['data'] ) ) {
				return new WP_Error( 'cssflow_import_not_ready', __( 'The backup contains a snippet that is not ready to import.', 'cssflow' ) );
			}

			$portable = array();
			foreach ( $portable_fields as $field ) {
				$portable[ $field ] = $snippet['data'][ $field ];
			}
			$snippets[] = $portable;
		}

		$document = array(
			'generator'      => 'CSSFlow',
			'schema_version' => self::SCHEMA_VERSION,
			'plugin_version' => $preview['metadata']['plugin_version'],
			'exported_at'    => $preview['metadata']['exported_at'],
			'snippets'       => $snippets,
			'breakpoints'    => $preview['breakpoints']['data'],
			'variables'      => $preview['variables']['data'],
			'settings'       => $preview['settings']['data'],
		);
		$json     = wp_json_encode( $document );

		if ( false === $json ) {
			return new WP_Error( 'cssflow_import_payload_failed', __( 'CSSFlow could not prepare this backup for import.', 'cssflow' ) );
		}

		return $json;
	}

	/**
	 * Revalidate and commit one previewed backup safely.
	 *
	 * Database/options writes are wrapped in one transaction. Generated output
	 * is invalidated before commit; if any step fails, source data is rolled back.
	 * Cleared generated files are disposable and will rebuild from the restored
	 * source of truth on the next frontend request.
	 *
	 * @param string $payload  Compact JSON payload returned by build_commit_payload().
	 * @param array  $choices  Per-row conflict choices.
	 * @return array|WP_Error Import result counts or an error.
	 */
	public function commit_payload( $payload, array $choices ) {
		if ( ! is_string( $payload ) || '' === trim( $payload ) ) {
			return new WP_Error( 'cssflow_import_missing_payload', __( 'The import data is missing. Preview the backup again and retry.', 'cssflow' ) );
		}

		$preview = $this->preview_json( $payload );

		if ( is_wp_error( $preview ) ) {
			return $preview;
		}

		if ( ! empty( $preview['has_blocking_errors'] ) ) {
			return new WP_Error( 'cssflow_import_changed', __( 'The backup is no longer safe to import. Preview it again and review the highlighted issues.', 'cssflow' ) );
		}

		$breakpoints = $this->merge_breakpoints( $preview['breakpoints']['data'] );
		$variables   = $this->merge_variables( $preview['variables']['data'] );

		$validated_breakpoints = $this->compiler->validate_breakpoints( $breakpoints );
		if ( is_wp_error( $validated_breakpoints ) ) {
			return $validated_breakpoints;
		}

		$validated_variables = $this->variables->validate_all( $variables );
		if ( is_wp_error( $validated_variables ) ) {
			return $validated_variables;
		}

		if ( ! $this->repository->begin_transaction() ) {
			return new WP_Error( 'cssflow_import_transaction_failed', __( 'CSSFlow could not start a safe import transaction. No data was imported.', 'cssflow' ) );
		}

		$result = array(
			'created'  => 0,
			'replaced' => 0,
			'skipped'  => 0,
		);

		$failure = null;

		if ( ! $this->update_option_safely( 'cssflow_breakpoints', $validated_breakpoints ) ) {
			$failure = new WP_Error( 'cssflow_import_breakpoints_write_failed', __( 'CSSFlow could not save the imported breakpoints.', 'cssflow' ) );
		}

		if ( ! $failure && ! $this->update_option_safely( Variables::OPTION_NAME, $validated_variables ) ) {
			$failure = new WP_Error( 'cssflow_import_variables_write_failed', __( 'CSSFlow could not save the imported CSS variables.', 'cssflow' ) );
		}

		if ( ! $failure && ! $this->update_option_safely( 'cssflow_settings', $preview['settings']['data'] ) ) {
			$failure = new WP_Error( 'cssflow_import_settings_write_failed', __( 'CSSFlow could not save the imported settings.', 'cssflow' ) );
		}

		if ( ! $failure ) {
			foreach ( array_values( $preview['snippets'] ) as $index => $snippet ) {
				$data     = $snippet['data'];
				$conflict = $this->repository->find_by_name( $data['name'] );

				if ( is_wp_error( $conflict ) ) {
					$failure = $conflict;
					break;
				}

				$choice = isset( $choices[ $index ] ) && is_scalar( $choices[ $index ] ) ? sanitize_key( (string) $choices[ $index ] ) : 'skip';
				if ( ! in_array( $choice, array( 'import', 'copy', 'replace', 'skip' ), true ) ) {
					$choice = 'skip';
				}

				if ( 'skip' === $choice ) {
					++$result['skipped'];
					continue;
				}

				if ( is_array( $conflict ) ) {
					if ( 'replace' === $choice ) {
						$updated = $this->repository->update_imported( (int) $conflict['id'], $data, $validated_breakpoints );
						if ( is_wp_error( $updated ) || true !== $updated ) {
							$failure = is_wp_error( $updated ) ? $updated : new WP_Error( 'cssflow_import_replace_failed', __( 'CSSFlow could not replace one of the conflicting snippets.', 'cssflow' ) );
							break;
						}
						++$result['replaced'];
						continue;
					}

					$data['name'] = $this->repository->make_unique_import_name( $data['name'] );
					if ( is_wp_error( $data['name'] ) ) {
						$failure = $data['name'];
						break;
					}
				}

				$created = $this->repository->create_imported( $data, $validated_breakpoints );
				if ( is_wp_error( $created ) ) {
					$failure = $created;
					break;
				}
				++$result['created'];
			}
		}

		if ( ! $failure ) {
			$regenerated = $this->output_manager->regenerate();
			if ( is_wp_error( $regenerated ) ) {
				$failure = $regenerated;
			}
		}

		if ( $failure ) {
			$this->repository->rollback_transaction();
			$this->clear_import_option_caches();
			return new WP_Error(
				'cssflow_import_commit_failed',
				sprintf(
					/* translators: %s: Import failure error message. */
					__( 'Nothing was imported. %s', 'cssflow' ),
					$failure->get_error_message()
				)
			);
		}

		if ( ! $this->repository->commit_transaction() ) {
			$this->repository->rollback_transaction();
			$this->clear_import_option_caches();
			return new WP_Error( 'cssflow_import_commit_failed', __( 'Nothing was imported because CSSFlow could not finish the safe import transaction.', 'cssflow' ) );
		}

		return $result;
	}


	/**
	 * Read the current theme's WordPress Additional CSS for a safe preview.
	 *
	 * This method performs no writes. WordPress remains the source of the
	 * original Additional CSS until the user explicitly changes it outside
	 * CSSFlow.
	 *
	 * @return array|WP_Error Preview data or an error.
	 */
	public function preview_additional_css() {
		if ( ! function_exists( 'wp_get_custom_css' ) ) {
			return new WP_Error( 'cssflow_additional_css_unavailable', __( 'WordPress Additional CSS is not available on this site.', 'cssflow' ) );
		}

		$css   = wp_get_custom_css();
		$theme = wp_get_theme();

		if ( ! is_string( $css ) ) {
			$css = '';
		}

		return array(
			'has_css'    => '' !== trim( $css ),
			'css'        => $css,
			'size'       => strlen( $css ),
			'theme_name' => $theme->exists() ? (string) $theme->get( 'Name' ) : __( 'Current theme', 'cssflow' ),
			'stylesheet' => (string) get_stylesheet(),
		);
	}

	/**
	 * Import the current theme's Additional CSS as one inactive CSSFlow snippet.
	 *
	 * The original WordPress Additional CSS is deliberately left untouched to
	 * avoid a silent migration. The imported snippet is inactive so the same CSS
	 * is not output twice immediately after import.
	 *
	 * @return array|WP_Error Imported snippet information or an error.
	 */
	public function import_additional_css() {
		$preview = $this->preview_additional_css();

		if ( is_wp_error( $preview ) ) {
			return $preview;
		}

		if ( empty( $preview['has_css'] ) ) {
			return new WP_Error( 'cssflow_additional_css_empty', __( 'No WordPress Additional CSS was found for the current theme.', 'cssflow' ) );
		}

		$breakpoints = $this->compiler->validate_breakpoints( get_option( 'cssflow_breakpoints', array() ) );
		if ( is_wp_error( $breakpoints ) ) {
			return new WP_Error( 'cssflow_additional_css_breakpoints_invalid', __( 'CSSFlow could not import Additional CSS because the current breakpoint setup is invalid.', 'cssflow' ) );
		}

		$name     = __( 'Imported WordPress Additional CSS', 'cssflow' );
		$existing = $this->repository->find_by_name( $name );

		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		if ( is_array( $existing ) ) {
			$name = $this->repository->make_unique_import_name( $name );
			if ( is_wp_error( $name ) ) {
				return $name;
			}
		}

		$data = array(
			'name'            => $name,
			'description'     => __( 'Imported from WordPress Additional CSS. The original WordPress Additional CSS was left unchanged.', 'cssflow' ),
			'css_code'        => $preview['css'],
			'scope_type'      => 'global',
			'scope_data'      => array(),
			'responsive_type' => 'all',
			'breakpoint_key'  => null,
			'priority'        => 10,
			'status'          => 'inactive',
		);

		if ( ! $this->repository->begin_transaction() ) {
			return new WP_Error( 'cssflow_additional_css_transaction_failed', __( 'CSSFlow could not start a safe Additional CSS import. Nothing was imported.', 'cssflow' ) );
		}

		$created = $this->repository->create_imported( $data, $breakpoints );
		if ( is_wp_error( $created ) ) {
			$this->repository->rollback_transaction();
			return $created;
		}

		$regenerated = $this->output_manager->regenerate();
		if ( is_wp_error( $regenerated ) ) {
			$this->repository->rollback_transaction();
			return new WP_Error(
				'cssflow_additional_css_output_failed',
				sprintf(
					/* translators: %s: Output error message. */
					__( 'Nothing was imported. CSSFlow could not refresh generated output: %s', 'cssflow' ),
					$regenerated->get_error_message()
				)
			);
		}

		if ( ! $this->repository->commit_transaction() ) {
			$this->repository->rollback_transaction();
			return new WP_Error( 'cssflow_additional_css_commit_failed', __( 'Nothing was imported because CSSFlow could not finish the safe Additional CSS import.', 'cssflow' ) );
		}

		return array(
			'id'   => (int) $created,
			'name' => $name,
		);
	}

	/**
	 * Merge imported breakpoints into the current configuration.
	 *
	 * @param array $imported Imported breakpoints.
	 * @return array
	 */
	private function merge_breakpoints( array $imported ) {
		$current = get_option( 'cssflow_breakpoints', array() );
		$current = is_array( $current ) ? $current : array();
		return array_replace( $current, $imported );
	}

	/**
	 * Merge imported variables into the current configuration.
	 *
	 * @param array $imported Imported variables.
	 * @return array
	 */
	private function merge_variables( array $imported ) {
		$current = get_option( Variables::OPTION_NAME, array() );
		$current = is_array( $current ) ? $current : array();
		$indexes = array();

		foreach ( $current as $index => $variable ) {
			if ( is_array( $variable ) && isset( $variable['name'] ) && is_scalar( $variable['name'] ) ) {
				$indexes[ (string) $variable['name'] ] = $index;
			}
		}

		foreach ( $imported as $variable ) {
			$name = isset( $variable['name'] ) ? (string) $variable['name'] : '';
			if ( isset( $indexes[ $name ] ) ) {
				$current[ $indexes[ $name ] ] = $variable;
			} else {
				$indexes[ $name ] = count( $current );
				$current[]        = $variable;
			}
		}

		return array_values( $current );
	}

	/**
	 * Update an option while treating an unchanged value as success.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Option value.
	 * @return bool
	 */
	private function update_option_safely( $name, $value ) {
		$current = get_option( $name, null );
		if ( $current === $value ) {
			return true;
		}
		return update_option( $name, $value );
	}

	/**
	 * Clear option caches touched by an import transaction.
	 *
	 * @return void
	 */
	private function clear_import_option_caches() {
		foreach ( array( 'cssflow_breakpoints', Variables::OPTION_NAME, 'cssflow_settings', 'cssflow_generated_version' ) as $option_name ) {
			wp_cache_delete( $option_name, 'options' );
		}
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * Validate document identity and required top-level section types.
	 *
	 * @param array $document Parsed JSON document.
	 * @return array|WP_Error Normalized metadata or error.
	 */
	private function validate_document_header( array $document ) {
		$required = array( 'generator', 'schema_version', 'plugin_version', 'exported_at', 'snippets', 'breakpoints', 'variables', 'settings' );

		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $document ) ) {
				return new WP_Error( 'cssflow_import_missing_section', __( 'This backup is missing required CSSFlow data.', 'cssflow' ) );
			}
		}

		if ( ! is_string( $document['generator'] ) || 'CSSFlow' !== $document['generator'] ) {
			return new WP_Error( 'cssflow_import_wrong_generator', __( 'This file was not created by CSSFlow.', 'cssflow' ) );
		}

		$schema_version = $this->normalize_integer( $document['schema_version'] );

		if ( self::SCHEMA_VERSION !== $schema_version ) {
			return new WP_Error( 'cssflow_import_unsupported_schema', __( 'This CSSFlow backup uses a schema version that this plugin cannot import.', 'cssflow' ) );
		}

		if ( ! is_string( $document['plugin_version'] ) || '' === trim( $document['plugin_version'] ) ) {
			return new WP_Error( 'cssflow_import_invalid_plugin_version', __( 'The CSSFlow backup plugin version is invalid.', 'cssflow' ) );
		}

		if ( ! is_string( $document['exported_at'] ) || false === strtotime( $document['exported_at'] ) ) {
			return new WP_Error( 'cssflow_import_invalid_export_date', __( 'The CSSFlow backup export date is invalid.', 'cssflow' ) );
		}

		if ( ! is_array( $document['snippets'] ) || ! is_array( $document['breakpoints'] ) || ! is_array( $document['variables'] ) || ! is_array( $document['settings'] ) ) {
			return new WP_Error( 'cssflow_import_invalid_sections', __( 'One or more required CSSFlow backup sections are invalid.', 'cssflow' ) );
		}

		return array(
			'generator'      => 'CSSFlow',
			'schema_version' => self::SCHEMA_VERSION,
			'plugin_version' => sanitize_text_field( $document['plugin_version'] ),
			'exported_at'    => sanitize_text_field( $document['exported_at'] ),
		);
	}

	/**
	 * Validate the imported breakpoints section.
	 *
	 * @param mixed $raw_breakpoints Raw section.
	 * @return array
	 */
	private function validate_breakpoints_section( $raw_breakpoints ) {
		if ( ! is_array( $raw_breakpoints ) ) {
			return $this->invalid_section( __( 'The backup breakpoint data is invalid.', 'cssflow' ) );
		}

		$validated = $this->compiler->validate_breakpoints( $raw_breakpoints );

		return is_wp_error( $validated )
			? $this->invalid_section( $validated->get_error_message() )
			: array(
				'valid' => true,
				'data'  => $validated,
				'error' => '',
			);
	}

	/**
	 * Validate the imported Variables section.
	 *
	 * @param mixed $raw_variables Raw section.
	 * @return array
	 */
	private function validate_variables_section( $raw_variables ) {
		if ( ! is_array( $raw_variables ) ) {
			return $this->invalid_section( __( 'The backup CSS Variables data is invalid.', 'cssflow' ) );
		}

		$validated = $this->variables->validate_all( $raw_variables );

		return is_wp_error( $validated )
			? $this->invalid_section( $validated->get_error_message() )
			: array(
				'valid' => true,
				'data'  => $validated,
				'error' => '',
			);
	}

	/**
	 * Validate the imported settings section.
	 *
	 * @param mixed $raw_settings Raw section.
	 * @return array
	 */
	private function validate_settings_section( $raw_settings ) {
		if ( ! is_array( $raw_settings ) ) {
			return $this->invalid_section( __( 'The backup CSSFlow settings are invalid.', 'cssflow' ) );
		}

		if ( ! array_key_exists( 'output_method', $raw_settings ) || ! array_key_exists( 'delete_on_uninstall', $raw_settings ) ) {
			return $this->invalid_section( __( 'The backup is missing required CSSFlow settings.', 'cssflow' ) );
		}

		$output_method = is_scalar( $raw_settings['output_method'] ) ? sanitize_key( (string) $raw_settings['output_method'] ) : '';

		if ( ! in_array( $output_method, array( 'file', 'inline' ), true ) ) {
			return $this->invalid_section( __( 'The backup output method is invalid.', 'cssflow' ) );
		}

		if ( ! is_bool( $raw_settings['delete_on_uninstall'] ) ) {
			return $this->invalid_section( __( 'The backup uninstall setting is invalid.', 'cssflow' ) );
		}

		$current_settings    = get_option( 'cssflow_settings', array() );
		$delete_on_uninstall = is_array( $current_settings ) && ! empty( $current_settings['delete_on_uninstall'] );

		$enable_syntax_highlighting = true;

		if ( array_key_exists( 'enable_syntax_highlighting', $raw_settings ) ) {
			if ( ! is_bool( $raw_settings['enable_syntax_highlighting'] ) ) {
				return $this->invalid_section( __( 'The backup code editor setting is invalid.', 'cssflow' ) );
			}

			$enable_syntax_highlighting = $raw_settings['enable_syntax_highlighting'];
		}

		return array(
			'valid' => true,
			'data'  => array(
				'output_method'              => $output_method,
				'delete_on_uninstall'        => $delete_on_uninstall,
				'enable_syntax_highlighting' => $enable_syntax_highlighting,
			),
			'error' => '',
		);
	}

	/**
	 * Validate every snippet and detect destination name conflicts.
	 *
	 * @param array $raw_snippets Raw snippets.
	 * @param array $breakpoints  Validated imported breakpoints.
	 * @return array
	 */
	private function validate_snippets( array $raw_snippets, array $breakpoints ) {
		$results = array();

		foreach ( array_values( $raw_snippets ) as $index => $raw_snippet ) {
			$item = array(
				'number'   => $index + 1,
				'valid'    => false,
				'data'     => array(),
				'error'    => '',
				'warning'  => '',
				'conflict' => array( 'exists' => false ),
			);

			if ( ! is_array( $raw_snippet ) ) {
				$item['error'] = __( 'This snippet is not a valid CSSFlow snippet record.', 'cssflow' );
				$results[]     = $item;
				continue;
			}

			$required = array( 'name', 'description', 'css_code', 'scope_type', 'scope_data', 'responsive_type', 'breakpoint_key', 'priority', 'status' );
			$missing  = array_diff( $required, array_keys( $raw_snippet ) );

			if ( ! empty( $missing ) ) {
				$item['error']        = __( 'This snippet is missing required data.', 'cssflow' );
				$item['data']['name'] = isset( $raw_snippet['name'] ) && is_scalar( $raw_snippet['name'] ) ? sanitize_text_field( (string) $raw_snippet['name'] ) : '';
				$results[]            = $item;
				continue;
			}

			$normalized = $this->repository->validate_for_import( $raw_snippet, $breakpoints );

			if ( is_wp_error( $normalized ) ) {
				$item['error']        = $normalized->get_error_message();
				$item['data']['name'] = isset( $raw_snippet['name'] ) && is_scalar( $raw_snippet['name'] ) ? sanitize_text_field( (string) $raw_snippet['name'] ) : '';
				$results[]            = $item;
				continue;
			}

			$item['valid'] = true;
			$item['data']  = $normalized;

			if ( ! empty( $normalized['_target_unavailable'] ) ) {
				$item['warning'] = __( 'This target is not available on this site right now. CSSFlow will preserve it and import the snippet as inactive.', 'cssflow' );
			}

			if ( isset( $raw_snippet['source_id'] ) ) {
				$source_id = $this->normalize_integer( $raw_snippet['source_id'] );
				if ( $source_id > 0 ) {
					$item['source_id'] = $source_id;
				}
			}

			$conflict = $this->repository->find_by_name( $normalized['name'] );

			if ( is_wp_error( $conflict ) ) {
				$item['valid'] = false;
				$item['error'] = $conflict->get_error_message();
			} elseif ( is_array( $conflict ) ) {
				$item['conflict'] = array(
					'exists'       => true,
					'matched_id'   => (int) $conflict['id'],
					'matched_name' => (string) $conflict['name'],
				);
			}

			$results[] = $item;
		}

		return $results;
	}

	/**
	 * Build a normalized invalid-section result.
	 *
	 * @param string $message Error message.
	 * @return array
	 */
	private function invalid_section( $message ) {
		return array(
			'valid' => false,
			'data'  => array(),
			'error' => (string) $message,
		);
	}

	/**
	 * Normalize an imported whole-number value.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private function normalize_integer( $value ) {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && preg_match( '/^\d+$/D', $value ) ) {
			return (int) $value;
		}
		return 0;
	}

	/**
	 * Convert a PHP upload error code into a CSSFlow error.
	 *
	 * @param int $code PHP upload error code.
	 * @return WP_Error
	 */
	private function upload_error( $code ) {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				$message = __( 'The selected backup file is larger than the server allows.', 'cssflow' );
				break;
			case UPLOAD_ERR_NO_FILE:
				$message = __( 'Choose a CSSFlow JSON backup file to preview.', 'cssflow' );
				break;
			default:
				$message = __( 'The backup file could not be uploaded. Please try again.', 'cssflow' );
				break;
		}
		return new WP_Error( 'cssflow_import_upload_failed', $message );
	}
}
