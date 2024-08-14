<?php

defined( 'ABSPATH' ) || exit;

/**
 * A helper class to provide filtered $_REQUEST or array parameters by its key.
 */
class Nuvei_Pfw_Http {

	/**
	 * Get request parameter by key.
	 *
	 * @param string    $key            Request key.
	 * @param string    $type           Optional. Possible vaues: string, float, int, array, mail/email, other.
	 * @param mixed     $default        Optional. Return value if fail.
	 * @param array     $parent         Optional array with parameters to search in.
	 * @param bool      $check_nonce    Optional. Check for nonce or no. We use this method mostly for outside requests, so the default will be false.
	 *
	 * @return mixed
	 */
	public static function get_param( $key, $type = 'string', $default = '', $parent = array(), $check_nonce = false ) {
		if ($check_nonce 
            && ! check_ajax_referer( 'nuvei-security-nonce', 'nuveiSecurity', false )
        ) {
            Nuvei_Pfw_Logger::write(
                ['$key' => sanitize_text_field($key)],
                'Faild to check nonce.'
            );
            
            if (in_array($type, array('int', 'float'))) {
                return 0;
            }
            
            return '';
        }
        
        switch ( $type ) {
			case 'mail':
			case 'email':
                if ( ! empty( $parent[ $key ] ) ) {
                    return sanitize_email( $parent[ $key ] );
                }
                
                if ( ! empty( $_REQUEST[ $key ] ) ) {
                    return sanitize_email( $_REQUEST[ $key ] );
                }
                
                return $default;

			case 'float':
                if ( isset( $parent[ $key ] ) && is_numeric($parent[ $key ]) ) {
                    return (float) $parent[ $key ];
                } 
                if ( isset( $_REQUEST[ $key ] ) && is_numeric($_REQUEST[ $key ]) ) {
                    return (float) $_REQUEST[ $key ];
                }
                
				if ( !is_numeric( $default ) ) {
					$default = 0;
				}
                
                return $default;

			case 'int':
                if ( isset( $parent[ $key ] ) && is_numeric($parent[ $key ]) ) {
                    return (int) $parent[ $key ];
                } 
                if ( isset( $_REQUEST[ $key ] ) && is_numeric($_REQUEST[ $key ]) ) {
                    return (int) $_REQUEST[ $key ];
                }
                
				if ( !is_numeric( $default ) ) {
					$default = 0;
				}

				return $default;

            case 'string':
			default:
                if ( isset( $parent[ $key ] ) ) {
                    return sanitize_text_field($parent[ $key ]);
                } 
                if ( isset( $_REQUEST[ $key ] ) ) {
                    return sanitize_text_field($_REQUEST[ $key ]);
                }
                
				return $default;
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
