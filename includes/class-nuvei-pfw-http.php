<?php

defined( 'ABSPATH' ) || exit;

/**
 * A helper class to provide filtered $_REQUEST or array parameters by its key.
 */
class Nuvei_Pfw_Http {


	/**
	 * Get request parameter by key (or first match from array of keys).
	 *
	 * @param string|array $key    Request key, or array of keys to try in order.
	 * @param string       $type   Optional. Possible values: string, float, int, array, mail/email, other.
	 * @param mixed        $default Optional. Return value if fail.
	 * @param array        $parent Optional array with parameters to search in.
	 *
	 * @return mixed
	 */
	public static function get_param( $key, $type = 'string', $default = '', $parent = array() ) {
		// Normalize $key to array for uniform handling
		$keys = is_array( $key ) ? $key : array( $key );
		
		// Helper: find first match from multiple keys
		$find_value = function( $key_list, $search_array ) {
			foreach ( $key_list as $search_key ) {
				if ( isset( $search_array[ $search_key ] ) ) {
					return $search_array[ $search_key ];
				}
			}
			return null;
		};
        
		switch ( $type ) {
			case 'mail':
			case 'email':
				$value = $find_value( $keys, $parent );
				if ( ! empty( $value ) ) {
					return sanitize_email( $value );
				}

				$value = $find_value( $keys, $_REQUEST );
				if ( ! empty( $value ) ) {
					return sanitize_email( wp_unslash( $value ) );
				}

				return $default;

			case 'float':
				$value = $find_value( $keys, $parent );
				if ( isset( $value ) && is_numeric( $value ) ) {
					return (float) $value;
				}

				$value = $find_value( $keys, $_REQUEST );
				if ( isset( $value ) && is_numeric( $value ) ) {
					return (float) $value;
				}

				if ( ! is_numeric( $default ) ) {
					$default = 0;
				}

				return $default;

			case 'int':
				$value = $find_value( $keys, $parent );
				if ( isset( $value ) && is_numeric( $value ) ) {
					return (int) $value;
				}

				$value = $find_value( $keys, $_REQUEST );
				if ( isset( $value ) && is_numeric( $value ) ) {
					return (int) $value;
				}

				if ( ! is_numeric( $default ) ) {
					$default = 0;
				}

				return $default;

			case 'string':
			default:
				$value = $find_value( $keys, $parent );
				if ( isset( $value ) ) {
					return sanitize_text_field( $value );
				}

				$value = $find_value( $keys, $_REQUEST );
				if ( isset( $value ) ) {
					return sanitize_text_field( wp_unslash( $value ) );
				}

				return $default;
		}
	}

	/**
	 * Get request status (case-insensitive).
	 * Now that get_param() supports case-insensitive matching, this is simplified.
	 *
	 * @param  array $params Optional array to search in.
	 * @return string
	 */
	public static function get_request_status( $params = array() ) {
		if ( empty( $params ) ) {
			return self::get_param( 'status' );
		}

		return self::get_param( 'status', 'string', '', $params );
	}
}
