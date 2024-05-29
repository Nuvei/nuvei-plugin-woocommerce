<?php

defined( 'ABSPATH' ) || exit;

/**
 * A custom class for logs.
 */
class Nuvei_Logger {

	private static $fields_to_mask = array(
		'ips'       => array( 'ipAddress' ),
		'names'     => array( 'firstName', 'lastName', 'first_name', 'last_name', 'shippingFirstName', 'shippingLastName', 'billing_first_name', 'billing_last_name', 'shipping_first_name', 'shipping_last_name', 'shipping_address_1', 'shipping_address_2' ),
		'emails'    => array(
			'userTokenId',
			'email',
			'shippingMail', // from the DMN
			'userid', // from the DMN
			'user_token_id', // from the DMN
			'billing_email',
		),
		'address'   => array( 'address', 'phone', 'zip', 'billing_address_1', 'billing_address_2', 'billing_postcode', 'billing_phone', 'shipping_postcode' ),
		'others'    => array( 'userAccountDetails', 'userPaymentOption', 'paymentOption' ),
	);

	private static $trace_id;

	/**
	 * Save plugin logs.
	 *
	 * @param mixed $data The data to save in the log.
	 * @param string $message Record message.
	 * @param string $log_level The Log level.
	 * @param string $span_id Process unique ID.
	 */
	public static function write( $data, $message = '', $log_level = 'INFO', $span_id = '' ) {
		$nuvei_gw           = WC()->payment_gateways->payment_gateways()[ NUVEI_GATEWAY_NAME ];
		$save_logs          = $nuvei_gw->get_option( 'save_logs' );
		$save_single_log    = $nuvei_gw->get_option( 'save_single_log' );

		if ( ! is_dir( NUVEI_LOGS_DIR ) ) {
			return;
		}
		if ( 'no' == $save_logs && 'no' == $save_single_log ) {
			return;
		}

		$plugin_data    = get_plugin_data( plugin_dir_path( NUVEI_PLUGIN_FILE ) . 'index.php' );
		$test_mode      = $nuvei_gw->get_option( 'test' );
		$mask_user_data = $nuvei_gw->get_option( 'mask_user_data' );

		$beauty_log = ( 'yes' == $test_mode ) ? true : false;
		$tab        = '    '; // 4 spaces

		# prepare log parts
		$utimestamp     = microtime( true );
		$timestamp      = floor( $utimestamp );
		$milliseconds   = round( ( $utimestamp - $timestamp ) * 1000000 );
		$record_time    = gmdate( 'Y-m-d' ) . 'T' . gmdate( 'H:i:s' ) . '.' . $milliseconds . gmdate( 'P' );

		if ( ! self::$trace_id ) {
			self::$trace_id = bin2hex( random_bytes( 16 ) );
		}

		if ( ! empty( $span_id ) ) {
			$span_id .= $tab;
		}

		$machine_name       = '';
		$service_name       = NUVEI_SOURCE_APPLICATION . ' ' . $plugin_data['Version'] . '|';
		$source_file_name   = '';
		$member_name        = '';
		$source_line_number = '';
		$backtrace          = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 1 );

		if ( ! empty( $backtrace ) ) {
			if ( ! empty( $backtrace[0]['file'] ) ) {
				$file_path_arr  = explode( DIRECTORY_SEPARATOR, $backtrace[0]['file'] );

				if ( ! empty( $file_path_arr ) ) {
					$source_file_name = end( $file_path_arr ) . '|';
				}
			}

			if ( ! empty( $backtrace[0]['line'] ) ) {
				$source_line_number = $backtrace[0]['line'] . $tab;
			}
		}

		if ( ! empty( $message ) ) {
			$message .= $tab;
		}

		if ( is_array( $data ) ) {
			if ( 1 == $mask_user_data ) {
				// clean possible objects inside array
				$data = wp_json_file_decode( wp_json_encode( $data ), ['associative' => true] );

				array_walk_recursive( $data, 'self::mask_data', self::$fields_to_mask );
			}

			// paymentMethods can be very big array
			if ( ! empty( $data['paymentMethods'] ) ) {
				$exception = wp_json_encode( $data );
			} else {
                // phpcs:ignore
				$exception = $beauty_log ? json_encode( $data, JSON_PRETTY_PRINT ) : wp_json_encode( $data );
			}
		} elseif ( is_object( $data ) ) {
			if ( 1 == $mask_user_data && ! empty( $data ) ) {
				// clean possible objects inside array
				$data = wp_json_file_decode( wp_json_encode( $data ), ['associative' => true] );

				array_walk_recursive( $data, 'self::mask_data', self::$fields_to_mask );
			}

			$data_tmp   = print_r( (array) $data, true );
            // phpcs:ignore
			$exception  = $beauty_log ? json_encode( $data_tmp, JSON_PRETTY_PRINT ) : wp_json_encode( $data_tmp );
		} elseif ( is_bool( $data ) ) {
			$exception = $data ? 'true' : 'false';
		} elseif ( is_string( $data ) ) {
			$exception = false === strpos( $data, 'http' ) ? $data : urldecode( $data );
		} else {
			$exception = $data;
		}
		# prepare log parts END

		// Content of the log string:
		$string = $record_time      // timestamp
			. $tab                  // tab
			. $log_level            // level
			. $tab                  // tab
			. self::$trace_id       // TraceId
			. $tab                  // tab
			. $span_id              // SpanId, if not empty it will include $tab
		//            . $parent_id            // ParentId, if not empty it will include $tab
			. $machine_name         // MachineName if not empty it will include a "|"
			. $service_name         // ServiceName if not empty it will include a "|"
			// TreadId
			. $source_file_name     // SourceFileName if not empty it will include a "|"
			. $member_name          // MemberName if not empty it will include a "|"
			. $source_line_number   // SourceLineName if not empty it will include $tab
			// RequestPath
			// RequestId
			. $message
			. $exception;            // the exception, in our case - data to print

		$string             .= "\r\n\r\n";
		$file_name          = gmdate( 'Y-m-d', time() ) . '-' . md5( $nuvei_gw->get_option( 'secret' ) . gmdate( 'Ymd' ) );
		$single_file_name   = NUVEI_GATEWAY_NAME . '-' . md5( $nuvei_gw->get_option( 'secret' ) );

		if ( 'yes' == $save_logs ) {
            // phpcs:ignore
			$res = file_put_contents(
				NUVEI_LOGS_DIR . $file_name . '.' . NUVEI_LOG_EXT,
				$string,
				FILE_APPEND
			);
		}

		if ( 'yes' == $save_single_log ) {
            // phpcs:ignore
			$res = file_put_contents(
				NUVEI_LOGS_DIR . $single_file_name . '.' . NUVEI_LOG_EXT,
				$string,
				FILE_APPEND
			);
		}
	}

	/**
	 * A callback function for arraw_walk_recursive.
	 *
	 * @param mixed $value
	 * @param mixed $key
	 * @param array $fields
	 */
	private static function mask_data( &$value, $key, $fields ) {
		if ( ! empty( $value ) ) {
			if ( in_array( $key, $fields['ips'] ) ) {
				$value = rtrim( long2ip( ip2long( $value ) & ( ~255 ) ), '0' ) . 'x';
			} elseif ( in_array( $key, $fields['names'] ) ) {
				$value = mb_substr( $value, 0, 1 ) . '****';
			} elseif ( in_array( $key, $fields['emails'] ) ) {
				$value = '****' . mb_substr( $value, 4 );
			} elseif ( in_array( $key, $fields['address'] )
				|| in_array( $key, $fields['others'] )
			) {
				$value = '****';
			}
		}
	}
}
