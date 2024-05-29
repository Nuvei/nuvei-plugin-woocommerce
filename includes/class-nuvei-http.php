<?php

defined( 'ABSPATH' ) || exit;

/**
 * A helper class to provide filtered $_REQUEST or array parameters by its key.
 */
class Nuvei_Http {

	/**
	 * Get request parameter by key
	 *
	 * @param string    $key - request key
	 * @param string    $type - possible vaues: string, float, int, array, mail/email, other
	 * @param mixed     $default - return value if fail
	 * @param array     $parent - optional list with parameters
	 *
	 * @return mixed
	 */
	public static function get_param( $key, $type = 'string', $default = '', $parent = array() ) {
		if ( ! empty( $parent ) && is_array( $parent ) ) {
			$arr = $parent;
		} else {
            //phpcs:ignore
			$arr = $_REQUEST;
		}

		switch ( $type ) {
			case 'mail':
			case 'email':
				return ! empty( $arr[ $key ] ) ? filter_var( $arr[ $key ], FILTER_VALIDATE_EMAIL ) : $default;

			case 'float':
				if ( empty( $default ) ) {
					$default = 0;
				}

				return ( ! empty( $arr[ $key ] ) && is_numeric( $arr[ $key ] ) ) ? (float) $arr[ $key ] : $default;

			case 'int':
				if ( empty( $default ) ) {
					$default = 0;
				}

				return ( ! empty( $arr[ $key ] ) && is_numeric( $arr[ $key ] ) ) ? (int) $arr[ $key ] : $default;

			case 'array':
				if ( empty( $default ) || ! is_array( $default ) ) {
					$default = array();
				}

				return ! empty( $arr[ $key ] ) ? filter_var( $arr[ $key ], FILTER_REQUIRE_ARRAY ) : $default;

			case 'string':
				return ! empty( $arr[ $key ] ) ? filter_var( $arr[ $key ] ) : $default;

			case 'json':
				return ! empty( $arr[ $key ] ) ? filter_var( stripslashes( $arr[ $key ] ), FILTER_DEFAULT ) : $default;

			default:
				return ! empty( $arr[ $key ] ) ? filter_var( $arr[ $key ], FILTER_DEFAULT ) : $default;
		}
	}

	/**
	 * We need this stupid function because as response request variable
	 * we get 'Status' or 'status'...
	 *
	 * @param array $params
	 * @return string
	 */
	public static function get_request_status( $params = array() ) {
		$status_upper = self::get_param( 'Status' );
		$status_lower = self::get_param( 'status' );

		if ( empty( $params ) ) {
			if ( '' != $status_upper ) {
				return $status_upper;
			}

			if ( '' != $status_lower ) {
				return $status_lower;
			}
		} else {
			if ( isset( $params['Status'] ) ) {
				return $params['Status'];
			}

			if ( isset( $params['status'] ) ) {
				return $params['status'];
			}
		}

		return '';
	}
}
