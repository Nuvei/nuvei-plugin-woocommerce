<?php

defined( 'ABSPATH' ) || exit;

/**
 * A helper class to provide filtered $_REQUEST or array parameters by its key.
 */
class Nuvei_Pfw_Http {


	/**
	 * Get request parameter by key.
	 *
	 * @param string $key         Request key.
	 * @param string $type        Optional. Possible values: string, float, int, array, mail/email, other.
	 * @param mixed  $default     Optional. Return value if fail.
	 * @param array  $parent      Optional array with parameters to search in.
	 *
	 * @return mixed
	 */
	public static function get_param( $key, $type = 'string', $default = '', $parent = array() ) {
        // TODO - when check for the $key try for the exact $key and for lcfirst($key)
        
		switch ( $type ) {
			case 'mail':
			case 'email':
				if ( ! empty( $parent[ $key ] ) ) {
					return sanitize_email( $parent[ $key ] );
				}

				if ( ! empty( $_REQUEST[ $key ] ) ) {
					return sanitize_email( wp_unslash( $_REQUEST[ $key ] ) );
				}

				return $default;

			case 'float':
				if ( isset( $parent[ $key ] ) && is_numeric( $parent[ $key ] ) ) {
					return (float) $parent[ $key ];
				}
				if ( isset( $_REQUEST[ $key ] ) && is_numeric( $_REQUEST[ $key ] ) ) {
					return (float) $_REQUEST[ $key ];
				}

				if ( ! is_numeric( $default ) ) {
					$default = 0;
				}

				return $default;

			case 'int':
				if ( isset( $parent[ $key ] ) && is_numeric( $parent[ $key ] ) ) {
					return (int) $parent[ $key ];
				}
				if ( isset( $_REQUEST[ $key ] ) && is_numeric( $_REQUEST[ $key ] ) ) {
					return (int) $_REQUEST[ $key ];
				}

				if ( ! is_numeric( $default ) ) {
					$default = 0;
				}

				return $default;

			case 'string':
			default:
				if ( isset( $parent[ $key ] ) ) {
					return sanitize_text_field( $parent[ $key ] );
				}
				if ( isset( $_REQUEST[ $key ] ) ) {
					return sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) );
				}

				return $default;
		}
	}

	/**
	 * We need this stupid function because as response request variable
	 * we get 'Status' or 'status'...
	 *
	 * @param  array $params
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
