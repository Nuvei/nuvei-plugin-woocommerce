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
		$plugin_data = get_plugin_data(plugin_dir_path(NUVEI_PLUGIN_FILE) . 'index.php');
		$save_logs   = 'yes';
		$test_mode   = 'yes';
			
		if (!empty($_GET['save_logs'])) {
			$save_logs = filter_var($_GET['save_logs'], FILTER_SANITIZE_STRING);
		}
		if (!empty($_GET['test_mode'])) {
			$test_mode = filter_var($_GET['test_mode'], FILTER_SANITIZE_STRING);
		}
        
		// path is different fore each plugin
		if (!is_dir(NUVEI_LOGS_DIR) || 'yes' != $save_logs) {
			return;
		}
		
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
        
        $string     .= "\r\n\r\n";
        $file_name  = date('Y-m-d', time());
        
		$res = file_put_contents(
			NUVEI_LOGS_DIR . $file_name . '.' . NUVEI_LOG_EXT,
			$string,
			FILE_APPEND
		);
	}
}
