<?php

/**
 * Lease lock manager for rebuild operations.
 *
 * @package Commerce_Intelligence_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages token-owned locks stored in wp_options.
 */
class CIE_Lock_Manager {

	/**
	 * Lock stale threshold in seconds (10 minutes).
	 *
	 * @var int
	 */
	const STALE_THRESHOLD = 600;

	/**
	 * Acquires a lock and returns its token.
	 *
	 * @param string $key Lock key.
	 * @param int    $ttl Contract argument for future extension.
	 * @return string|false
	 */
	public function acquire( string $key, int $ttl ) {
		$option_name = $this->get_option_name( $key );
		$existing    = $this->read_payload( $option_name );
		$token       = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( '', true );
		$now         = time();
		$payload     = array(
			'token'       => $token,
			'acquired_at' => $now,
			'heartbeat_at' => $now,
		);

		// Kept as part of public method contract; staleness is heartbeat-driven.
		unset( $ttl );

		if ( is_array( $existing ) && ! $this->is_payload_stale( $existing ) ) {
			return false;
		}

		$encoded = wp_json_encode( $payload );
		if ( false === $encoded ) {
			return false;
		}

		if ( false === get_option( $option_name, false ) ) {
			$saved = add_option( $option_name, $encoded, '', 'no' );

			// Guard against races where another process acquired before add_option.
			if ( ! $saved ) {
				$current = $this->read_payload( $option_name );
				if ( is_array( $current ) && ! $this->is_payload_stale( $current ) ) {
					return false;
				}

				$saved = update_option( $option_name, $encoded );
			}
		} else {
			$saved = update_option( $option_name, $encoded );
		}

		if ( ! $saved ) {
			$current = $this->read_payload( $option_name );
			if ( is_array( $current ) && isset( $current['token'] ) && $current['token'] === $token ) {
				$this->enforce_no_autoload( $option_name );
				return $token;
			}

			return false;
		}

		$this->enforce_no_autoload( $option_name );
		return $token;
	}

	/**
	 * Releases a lock if token matches.
	 *
	 * @param string $key   Lock key.
	 * @param string $token Lock token.
	 * @return bool
	 */
	public function release( string $key, string $token ): bool {
		$option_name = $this->get_option_name( $key );
		$payload     = $this->read_payload( $option_name );

		if ( ! is_array( $payload ) || ! isset( $payload['token'] ) || $payload['token'] !== $token ) {
			return false;
		}

		return (bool) delete_option( $option_name );
	}

	/**
	 * Sends heartbeat for a held lock.
	 *
	 * @param string $key   Lock key.
	 * @param string $token Lock token.
	 * @return bool
	 */
	public function heartbeat( string $key, string $token ): bool {
		$option_name = $this->get_option_name( $key );
		$payload     = $this->read_payload( $option_name );

		if ( ! is_array( $payload ) || ! isset( $payload['token'] ) || $payload['token'] !== $token ) {
			return false;
		}

		$payload['heartbeat_at'] = time();

		$encoded = wp_json_encode( $payload );
		if ( false === $encoded ) {
			return false;
		}

		$saved = update_option( $option_name, $encoded );
		$this->enforce_no_autoload( $option_name );

		if ( ! $saved ) {
			$current = $this->read_payload( $option_name );
			return is_array( $current ) && isset( $current['heartbeat_at'] ) && (int) $current['heartbeat_at'] === (int) $payload['heartbeat_at'];
		}

		return true;
	}

	/**
	 * Checks whether lock is stale.
	 *
	 * @param string $key Lock key.
	 * @return bool
	 */
	public function is_stale( string $key ): bool {
		$payload = $this->read_payload( $this->get_option_name( $key ) );
		return $this->is_payload_stale( $payload );
	}

	/**
	 * Checks stale status for a payload.
	 *
	 * @param mixed $payload Payload array or invalid value.
	 * @return bool
	 */
	private function is_payload_stale( $payload ): bool {
		if ( ! is_array( $payload ) || ! isset( $payload['heartbeat_at'] ) ) {
			return true;
		}

		$heartbeat_at = (int) $payload['heartbeat_at'];
		if ( $heartbeat_at <= 0 ) {
			return true;
		}

		return ( time() - $heartbeat_at ) > self::STALE_THRESHOLD;
	}

	/**
	 * Builds lock option name.
	 *
	 * @param string $key Lock key.
	 * @return string
	 */
	private function get_option_name( string $key ): string {
		$normalized = sanitize_key( $key );
		if ( '' === $normalized ) {
			$normalized = 'default';
		}

		return 'cie_lock_' . $normalized;
	}

	/**
	 * Reads and decodes lock payload.
	 *
	 * @param string $option_name Option name.
	 * @return array|null
	 */
	private function read_payload( string $option_name ): ?array {
		$raw = get_option( $option_name, false );
		if ( false === $raw ) {
			return null;
		}

		if ( is_array( $raw ) ) {
			return $raw;
		}

		if ( ! is_string( $raw ) ) {
			return null;
		}

		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Forces autoload to no for lock rows.
	 *
	 * @param string $option_name Option name.
	 * @return void
	 */
	private function enforce_no_autoload( string $option_name ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->options,
			array( 'autoload' => 'no' ),
			array( 'option_name' => $option_name ),
			array( '%s' ),
			array( '%s' )
		);
	}
}
