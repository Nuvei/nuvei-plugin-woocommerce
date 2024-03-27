<?php

defined( 'ABSPATH' ) || exit;

/**
 * A custom class for logs.
 */
class Nuvei_Logger
{
    private static $trace_id;

    /**
     * Save plugin logs.
     * 
     * @param mixed $data The data to save in the log.
     * @param string $message Record message.
     * @param string $log_level The Log level.
     * @param string $span_id Process unique ID.
     */
	public static function write( $data, $message = '', $log_level = 'INFO', $span_id = '')
    {
        $nuvei_gw           = WC()->payment_gateways->payment_gateways()[NUVEI_GATEWAY_NAME];
        $save_logs          = $nuvei_gw->get_option('save_logs');
		$save_single_log    = $nuvei_gw->get_option('save_single_log');
        
		if (!is_dir(NUVEI_LOGS_DIR)) {
			return;
		}
		if ('no' == $save_logs && 'no' == $save_single_log) {
			return;
		}
        
        $plugin_data    = get_plugin_data(plugin_dir_path(NUVEI_PLUGIN_FILE) . 'index.php');
		$test_mode      = $nuvei_gw->get_option('test');
		$maskUserData   = $nuvei_gw->get_option('mask_user_data');
		
        $beauty_log = ('yes' == $test_mode) ? true : false;
        $tab        = '    '; // 4 spaces
        
        # prepare log parts
        $utimestamp     = microtime(true);
        $timestamp      = floor($utimestamp);
        $milliseconds   = round(($utimestamp - $timestamp) * 1000000);
        $record_time    = date('Y-m-d') . 'T' . date('H:i:s') . '.' . $milliseconds . date('P');
        
        if(!self::$trace_id) {
            self::$trace_id = bin2hex(random_bytes(16));
        }
        
        if(!empty($span_id)) {
            $span_id .= $tab;
        }
        
        $machine_name       = '';
        $service_name       = NUVEI_SOURCE_APPLICATION . ' ' . $plugin_data['Version'] . '|';
        $source_file_name   = '';
        $member_name        = '';
        $source_line_number = '';
        $backtrace          = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
        
        if(!empty($backtrace)) {
            if(!empty($backtrace[0]['file'])) {
                $file_path_arr  = explode(DIRECTORY_SEPARATOR, $backtrace[0]['file']);
                
                if(!empty($file_path_arr)) {
                    $source_file_name = end($file_path_arr) . '|';
                }
            }
            
            if(!empty($backtrace[0]['line'])) {
                $source_line_number = $backtrace[0]['line'] . $tab;
            }
        }
        
        if(!empty($message)) {
            $message .= $tab;
        }
        
        if(is_array($data)) {
            if (1 == $maskUserData) {
                $data = self::mask_data($data);
            }

            // paymentMethods can be very big array
            if(!empty($data['paymentMethods'])) {
                $exception = json_encode($data);
            }
            else {
                $exception = $beauty_log ? json_encode($data, JSON_PRETTY_PRINT) : json_encode($data);
            }
        }
        elseif(is_object($data)) {
            $data_tmp   = print_r((array) $data, true);
            $exception  = $beauty_log ? json_encode($data_tmp, JSON_PRETTY_PRINT) : json_encode($data_tmp);
        }
        elseif(is_bool($data)) {
            $exception = $data ? 'true' : 'false';
        }
        elseif(is_string($data)) {
            $exception = false === strpos($data, 'http') ? $data : urldecode($data);
        }
        else {
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
            . $exception            // the exception, in our case - data to print
        ;
        
        $string             .= "\r\n\r\n";
        $file_name          = date('Y-m-d', time()) . '-' . md5($nuvei_gw->get_option('secret') . date('Ymd'));
        $single_file_name   = NUVEI_GATEWAY_NAME . '-' . md5($nuvei_gw->get_option('secret'));
        
        if ('yes' == $save_logs) {
            $res = file_put_contents(
                NUVEI_LOGS_DIR . $file_name . '.' . NUVEI_LOG_EXT,
                $string,
                FILE_APPEND
            );
        }
        
        if ('yes' == $save_single_log) {
            $res = file_put_contents(
                NUVEI_LOGS_DIR . $single_file_name . '.' . NUVEI_LOG_EXT,
                $string,
                FILE_APPEND
            );
        }
	}
    
    /**
     * Mask data for the log.
     * 
     * @param array $data
     */
    private static function mask_data($data)
    {
        // mask the IP address
        if (!empty($data[LOG_REQUEST_PARAMS]['deviceDetails'])) {
            $data[LOG_REQUEST_PARAMS]['deviceDetails']['ipAddress']
                = rtrim(long2ip(ip2long($data[LOG_REQUEST_PARAMS]['deviceDetails']['ipAddress']) & (~255)),"0")."x";
        }
        
        // mask the shipping details
        if (!empty($data[LOG_REQUEST_PARAMS]['shippingAddress'])) {
            $data[LOG_REQUEST_PARAMS]['shippingAddress']
                = self::mask_string($data[LOG_REQUEST_PARAMS]['shippingAddress']);
        }
        
        // mask the billing details
        if (!empty($data[LOG_REQUEST_PARAMS]['billingAddress'])) {
            $data[LOG_REQUEST_PARAMS]['billingAddress']
                = self::mask_string($data[LOG_REQUEST_PARAMS]['billingAddress']);
        }
        
        if (!empty($data['billingAddress'])) {
            $data['billingAddress'] = self::mask_string($data['billingAddress']);
        }
        
        // mask the user details
        if (!empty($data[LOG_REQUEST_PARAMS]['userDetails'])) {
            $data[LOG_REQUEST_PARAMS]['userDetails']
                = self::mask_string($data[LOG_REQUEST_PARAMS]['userDetails']);
        }
        
        // other, like responses
        $data = self::mask_string($data, false);

        return $data;
    }
    
    /**
     * A help function to mask user data.
     * 
     * @param type $array           Original array.
     * @param bool $is_user_data    If this is no user data, do not mask all fields.
     * 
     * @return array $array         Array with masked strings.
     */
    private static function mask_string($array, $is_user_data = true)
    {
        foreach ($array as $key => $value) {
            // mask the email
            if (in_array($key, ['email', 'userTokenId']) && !empty($value)) {
                $part = substr($value, 4);
                $array[$key] = '****' . $part;
                continue;
            }
            
            // mask the names
            if (strpos(strtolower($key), 'name')) {
                $array[$key] = substr($value, 0, 1) . '****';
                continue;
            }
            
            // mask userAccountDetails, userPaymentOption and paymentOption
            if (in_array($key, ['userAccountDetails', 'userPaymentOption', 'paymentOption'])
                && is_array($value)
            ) {
                $array[$key] = '****';
                continue;
            }
            
            // mask the others
            if ($is_user_data && !is_numeric($key) && !in_array($key, ['city', 'country'])) {
                $array[$key] = '****';
            } 
        }
        
        return $array;
    }
    
}
