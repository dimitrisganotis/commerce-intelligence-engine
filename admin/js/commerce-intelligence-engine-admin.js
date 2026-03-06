(function( $ ) {
	'use strict';

	var settings = window.CIEAdminSettings || {};
	var ajaxUrl = settings.ajaxUrl || window.ajaxurl || '';
	var statusInterval = parseInt( settings.statusInterval, 10 ) || 3000;
	var pollHandle = null;

	function getMessage( key, fallback ) {
		if ( settings.messages && settings.messages[ key ] ) {
			return settings.messages[ key ];
		}

		return fallback;
	}

	function setProgressMessage( message, typeClass ) {
		var $progress = $( '#cie-rebuild-progress' );
		if ( ! $progress.length ) {
			return;
		}

		$progress.removeClass( 'notice-info notice-success notice-error notice-warning' );
		$progress.addClass( typeClass || 'notice-info' );
		$progress.find( 'p' ).text( message );
	}

	function setButtonLoading( $button, isLoading ) {
		if ( ! $button.length ) {
			return;
		}

		$button.prop( 'disabled', !!isLoading );
		if ( isLoading ) {
			$button.addClass( 'updating-message' );
		} else {
			$button.removeClass( 'updating-message' );
		}
	}

	function renderStatusPayload( payload ) {
		var status = payload.status || 'idle';
		var orders = parseInt( payload.orders_processed || 0, 10 );
		var associations = parseInt( payload.associations_written || 0, 10 );
		var duration = parseInt( payload.duration_seconds || 0, 10 );
		var startedAt = payload.started_at || 'n/a';
		var errorMessage = payload.error_message || '';
		var typeClass = 'notice-info';
		var message = '';

		if ( status === 'completed' ) {
			typeClass = 'notice-success';
		} else if ( status === 'failed' ) {
			typeClass = 'notice-error';
		} else if ( status === 'running' || status === 'queued' ) {
			typeClass = 'notice-warning';
		}

		message = 'Status: ' + status +
			' | Orders: ' + orders +
			' | Associations: ' + associations +
			' | Duration: ' + duration + 's' +
			' | Started: ' + startedAt;

		if ( status === 'failed' && errorMessage ) {
			message += ' | Error: ' + errorMessage;
		}

		setProgressMessage( message, typeClass );

		if ( status === 'completed' || status === 'failed' || status === 'idle' ) {
			if ( pollHandle ) {
				window.clearInterval( pollHandle );
				pollHandle = null;
			}
		} else if ( ( status === 'running' || status === 'queued' ) && ! pollHandle ) {
			pollHandle = window.setInterval( fetchStatus, statusInterval );
		}
	}

	function fetchStatus() {
		var nonce = $( '#cie-status-nonce' ).val();
		if ( ! nonce || ! ajaxUrl ) {
			return;
		}

		$.post( ajaxUrl, {
			action: 'cie_get_rebuild_status',
			nonce: nonce
		} ).done( function( response ) {
			if ( ! response || ! response.success || ! response.data ) {
				setProgressMessage( getMessage( 'requestFailed', 'Request failed. Please try again.' ), 'notice-error' );
				return;
			}

			renderStatusPayload( response.data );
		} ).fail( function() {
			setProgressMessage( getMessage( 'requestFailed', 'Request failed. Please try again.' ), 'notice-error' );
		} );
	}

	function startPolling() {
		if ( pollHandle ) {
			return;
		}

		fetchStatus();
		pollHandle = window.setInterval( fetchStatus, statusInterval );
	}

	function setFieldValue( selector, value ) {
		var $field = $( selector );
		if ( ! $field.length || typeof value === 'undefined' || value === null ) {
			return;
		}

		$field.val( String( value ) );
	}

	function applyAlgorithmPreset( presetKey ) {
		var presets = settings.algorithmPresets || {};
		var preset = presets[ presetKey ];

		if ( ! preset ) {
			return;
		}

		setFieldValue( '#cie-min-co-occurrence', preset.min_co_occurrence );
		setFieldValue( '#cie-min-support', preset.min_support );
		setFieldValue( '#cie-min-confidence', preset.min_confidence );
		setFieldValue( '#cie-min-lift', preset.min_lift );
		setFieldValue( '#cie-decay-rate', preset.decay_rate );
		setFieldValue( '#cie-query-headroom-mult', preset.query_headroom_mult );

		if ( preset.weights ) {
			setFieldValue( '#cie-weight-confidence', preset.weights.confidence );
			setFieldValue( '#cie-weight-lift', preset.weights.lift );
			setFieldValue( '#cie-weight-margin', preset.weights.margin );
			setFieldValue( '#cie-weight-stock', preset.weights.stock );
			setFieldValue( '#cie-weight-recency', preset.weights.recency );
		}

		setProgressMessage(
			getMessage( 'presetApplied', 'Algorithm preset applied. Save settings to persist.' ),
			'notice-info'
		);
	}

	$( function() {
		var $rebuildButton = $( '#cie-rebuild-now' );
		var $rebuildMode = $( '#cie-rebuild-mode' );
		var $cacheButton = $( '#cie-clear-cache' );
		var $presetButton = $( '#cie-apply-algorithm-preset' );

		if ( $rebuildButton.length ) {
			$rebuildButton.on( 'click', function() {
				var nonce = $( '#cie-rebuild-nonce' ).val() || $rebuildButton.data( 'nonce' );
				var mode = '';
				if ( $rebuildMode.length ) {
					mode = String( $rebuildMode.val() || '' );
				} else {
					mode = String( settings.rebuildModeDefault || '' );
				}

				if ( mode === 'auto' || ( mode !== 'incremental' && mode !== 'full' ) ) {
					mode = '';
				}

				if ( ! nonce || ! ajaxUrl ) {
					setProgressMessage( getMessage( 'requestFailed', 'Request failed. Please try again.' ), 'notice-error' );
					return;
				}

				setButtonLoading( $rebuildButton, true );
				if ( $rebuildMode.length ) {
					$rebuildMode.prop( 'disabled', true );
				}
				var payload = {
					action: 'cie_trigger_rebuild',
					nonce: nonce
				};

				if ( mode ) {
					payload.mode = mode;
				}

				$.post( ajaxUrl, payload ).done( function( response ) {
					if ( response && response.success ) {
						setProgressMessage( getMessage( 'rebuildQueued', 'Rebuild queued. Checking progress...' ), 'notice-info' );
						startPolling();
					} else {
						setProgressMessage( getMessage( 'requestFailed', 'Request failed. Please try again.' ), 'notice-error' );
					}
				} ).fail( function() {
					setProgressMessage( getMessage( 'requestFailed', 'Request failed. Please try again.' ), 'notice-error' );
				} ).always( function() {
					setButtonLoading( $rebuildButton, false );
					if ( $rebuildMode.length ) {
						$rebuildMode.prop( 'disabled', false );
					}
				} );
			} );
		}

		if ( $cacheButton.length ) {
			$cacheButton.on( 'click', function() {
				var nonce = $( '#cie-cache-nonce' ).val() || $cacheButton.data( 'nonce' );
				if ( ! nonce || ! ajaxUrl ) {
					setProgressMessage( getMessage( 'requestFailed', 'Request failed. Please try again.' ), 'notice-error' );
					return;
				}

				setButtonLoading( $cacheButton, true );

				$.post( ajaxUrl, {
					action: 'cie_clear_cache',
					nonce: nonce
				} ).done( function( response ) {
					if ( response && response.success && response.data ) {
						setProgressMessage(
							getMessage( 'cacheCleared', 'Cache cleared.' ) + ' (' + parseInt( response.data.count || 0, 10 ) + ')',
							'notice-success'
						);
					} else {
						setProgressMessage( getMessage( 'requestFailed', 'Request failed. Please try again.' ), 'notice-error' );
					}
				} ).fail( function() {
					setProgressMessage( getMessage( 'requestFailed', 'Request failed. Please try again.' ), 'notice-error' );
				} ).always( function() {
					setButtonLoading( $cacheButton, false );
				} );
			} );
		}

		if ( $presetButton.length ) {
			$presetButton.on( 'click', function() {
				var presetKey = $( '#cie-algorithm-preset' ).val();
				applyAlgorithmPreset( presetKey );
			} );
		}

		// Always fetch current status when Operations tab is open.
		if ( $( '#cie-status-nonce' ).length ) {
			fetchStatus();
		}
	} );

})( jQuery );
