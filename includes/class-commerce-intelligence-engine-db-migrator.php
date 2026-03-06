<?php

/**
 * Database migrator.
 *
 * @package Commerce_Intelligence_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles versioned database migrations.
 */
class Commerce_Intelligence_Engine_DB_Migrator {

	/**
	 * DB version option key.
	 *
	 * @var string
	 */
	const OPTION_DB_VERSION = 'cie_db_version';

	/**
	 * Runs migrations when stored version is behind the code version.
	 *
	 * @return void
	 */
	public static function maybe_migrate(): void {
		$current_version = get_option( self::OPTION_DB_VERSION, false );
		if ( false === $current_version ) {
			return;
		}

		$current_version = (string) $current_version;
		$target_version  = defined( 'CIE_DB_VERSION' ) ? (string) CIE_DB_VERSION : '1.0.0';

		if ( '' === $current_version || version_compare( $current_version, $target_version, '>=' ) ) {
			return;
		}

		try {
			$working_version = $current_version;

			while ( version_compare( $working_version, $target_version, '<' ) ) {
				$method = self::resolve_migration_method( $working_version, $target_version );

				if ( ! method_exists( __CLASS__, $method ) ) {
					throw new RuntimeException(
						sprintf(
							'Missing DB migration method "%1$s" for version %2$s.',
							$method,
							$working_version
						)
					);
				}

				self::{$method}();
				$working_version = self::next_version( $working_version );
			}

			update_option( self::OPTION_DB_VERSION, $target_version );
		} catch ( Throwable $exception ) {
			error_log(
				sprintf(
					'[CIE] Database migration failed from %1$s to %2$s: %3$s',
					$current_version,
					$target_version,
					$exception->getMessage()
				)
			);
		}
	}

	/**
	 * Returns a prefixed CIE table name.
	 *
	 * @param string $suffix Table suffix.
	 * @return string
	 */
	public static function get_table_name( string $suffix ): string {
		global $wpdb;

		return $wpdb->prefix . 'ci_' . $suffix;
	}

	/**
	 * Baseline migration stub for the next schema step.
	 *
	 * Documents the migration convention from 1.0.0 to 1.1.0.
	 *
	 * @return void
	 */
	private static function migrate_100_to_110(): void {
		// No-op baseline stub for future schema migrations.
	}

	/**
	 * Resolves the migration method for the current step.
	 *
	 * @param string $from_version Current DB version.
	 * @param string $to_version   Target DB version.
	 * @return string
	 */
	private static function resolve_migration_method( string $from_version, string $to_version ): string {
		if ( '1.0.0' === $from_version && version_compare( $to_version, '1.1.0', '>=' ) ) {
			return 'migrate_100_to_110';
		}

		throw new RuntimeException(
			sprintf(
				'No migration path registered from version %1$s to %2$s.',
				$from_version,
				$to_version
			)
		);
	}

	/**
	 * Returns the next semantic version step.
	 *
	 * @param string $version Current semantic version.
	 * @return string
	 */
	private static function next_version( string $version ): string {
		if ( '1.0.0' === $version ) {
			return '1.1.0';
		}

		throw new RuntimeException( sprintf( 'Unable to resolve next version from %s.', $version ) );
	}
}

if ( ! class_exists( 'CIE_DB_Migrator', false ) ) {
	class_alias( 'Commerce_Intelligence_Engine_DB_Migrator', 'CIE_DB_Migrator' );
}
