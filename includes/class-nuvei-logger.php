<?php

defined( 'ABSPATH' ) || exit;

/**
 * A custom class for logs.
 */
class Nuvei_Logger {

	public static function write( $data, $title = '') {
		$logs_path   = plugin_dir_path( NUVEI_PLUGIN_FILE ) . 'logs' . DIRECTORY_SEPARATOR;
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
		if (!is_dir($logs_path) || 'yes' != $save_logs) {
			return;
		}
		
		$d		= $data;
		$string	= '';

		if (is_array($data)) {
			// do not log accounts if on prod
			if ('no' == $test_mode) {
				if (isset($data['userAccountDetails']) && is_array($data['userAccountDetails'])) {
					$data['userAccountDetails'] = 'account details';
				}
				if (isset($data['userPaymentOption']) && is_array($data['userPaymentOption'])) {
					$data['userPaymentOption'] = 'user payment options details';
				}
				if (isset($data['paymentOption']) && is_array($data['paymentOption'])) {
					$data['paymentOption'] = 'payment options details';
				}
			}
			// do not log accounts if on prod
			
			if (!empty($data['paymentMethods']) && is_array($data['paymentMethods'])) {
				$data['paymentMethods'] = json_encode($data['paymentMethods']);
			}
			if (!empty($data['plans']) && is_array($data['plans'])) {
				$data['plans'] = json_encode($data['plans']);
			}
			
			$d = 'yes' == $test_mode ? print_r($data, true) : json_encode($data);
		} elseif (is_object($data)) {
			$d = 'yes' == $test_mode ? print_r($data, true) : json_encode($data);
		} elseif (is_bool($data)) {
			$d = $data ? 'true' : 'false';
		}
		
		if (!empty($plugin_data['Version'])) {
			$string .= '[v.' . $plugin_data['Version'] . '] | ';
		}

		if (!empty($title)) {
			if (is_string($title)) {
				$string .= $title;
			} else {
				$string .= "\r\n" . ( 'yes' == $test_mode ? json_encode($title, JSON_PRETTY_PRINT) : json_encode($title) );
			}
			
			$string .= "\r\n";
		}

		$string .= $d . "\r\n\r\n";
		
		file_put_contents(
			$logs_path . gmdate('Y-m-d', time()) . '.txt',
			gmdate('H:i:s', time()) . ': ' . $string,
			FILE_APPEND
		);
	}
}
