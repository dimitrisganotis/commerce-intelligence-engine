<?php

/**
 * Cache wrapper for CIE.
 *
 * @package Commerce_Intelligence_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles CIE cache operations.
 */
class CIE_Cache {

	/**
	 * Default cache TTL in seconds.
	 *
	 * @var int
	 */
	const DEFAULT_TTL = 21600;

	/**
	 * Cache group.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'cie';

	/**
	 * Gets a cached value.
	 *
	 * @param string $key Cache key.
	 * @return mixed
	 */
	public function get( string $key ) {
		$cache_value = wp_cache_get( $key, self::CACHE_GROUP );
		if ( false !== $cache_value ) {
			return $cache_value;
		}

		$transient_value = get_transient( $key );
		if ( false !== $transient_value ) {
			return $transient_value;
		}

		return false;
	}

	/**
	 * Sets a cached value.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Cache value.
	 * @param int    $ttl   Time-to-live in seconds.
	 * @return bool
	 */
	public function set( string $key, $value, int $ttl = self::DEFAULT_TTL ): bool {
		$ttl = max( 1, absint( $ttl ) );

		$cache_saved     = wp_cache_set( $key, $value, self::CACHE_GROUP, $ttl );
		$transient_saved = set_transient( $key, $value, $ttl );

		return (bool) ( $cache_saved && $transient_saved );
	}

	/**
	 * Deletes a cache key from both cache layers.
	 *
	 * @param string $key Cache key.
	 * @return bool
	 */
	public function delete( string $key ): bool {
		$cache_deleted     = wp_cache_delete( $key, self::CACHE_GROUP );
		$transient_deleted = delete_transient( $key );

		return (bool) ( $cache_deleted || $transient_deleted );
	}

	/**
	 * Flushes a transient namespace.
	 *
	 * @param string $namespace Namespace prefix.
	 * @return int
	 */
	public function flush_namespace( string $namespace ): int {
		global $wpdb;

		$namespace = sanitize_key( $namespace );
		if ( '' === $namespace ) {
			return 0;
		}

		$escaped_namespace = $wpdb->esc_like( $namespace );
		$transient_like    = '_transient_' . $escaped_namespace . '%';
		$timeout_like      = '_transient_timeout_' . $escaped_namespace . '%';

		$delete_transient_query = $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$transient_like
		);
		$deleted_transients      = $wpdb->query( $delete_transient_query );
		$deleted_transients      = false === $deleted_transients ? 0 : (int) $deleted_transients;

		$delete_timeout_query = $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$timeout_like
		);
		$deleted_timeouts     = $wpdb->query( $delete_timeout_query );
		$deleted_timeouts     = false === $deleted_timeouts ? 0 : (int) $deleted_timeouts;

		return $deleted_transients + $deleted_timeouts;
	}

	/**
	 * Builds a transient-safe cache key.
	 *
	 * @param string ...$parts Key parts.
	 * @return string
	 */
	public static function make_key( string ...$parts ): string {
		$key = implode( '_', $parts );

		if ( strlen( $key ) > 172 ) {
			$key = substr( $key, 0, 172 );
		}

		return $key;
	}
}
