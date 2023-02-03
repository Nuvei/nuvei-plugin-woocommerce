<?php

defined( 'ABSPATH' ) || exit;

/**
 * A class to help with some strings.
 */
class Nuvei_String {

	/**
	 * Generate base of the Notify URL.
	 * 
	 * @param array $plugin_settings
	 * @param int $order_id
	 * @param bool $use_default Use default DMN URL or use the one from the settings
	 * 
	 * @return string
	 */
	public static function get_notify_url( $plugin_settings, $use_default = false) {
		if (!$use_default && !empty($plugin_settings['notify_url'])) {
			return $plugin_settings['notify_url'];
		}
		
		$url_part  = get_site_url();
		$save_logs = isset($plugin_settings['save_logs']) ? $plugin_settings['save_logs'] : 'no';  
		$test_mode = isset($plugin_settings['test']) ? $plugin_settings['test'] : 'yes';
		$url       = $url_part . ( strpos($url_part, '?') !== false ? '&' : '?' )
            . 'wc-api=nuvei_listener'
			. '&save_logs=' . $save_logs 
            . '&test_mode=' . $test_mode;
		
		// some servers needs / before ?
		if (strpos($url, '?') !== false && strpos($url, '/?') === false) {
			$url = str_replace('?', '/?', $url);
		}
		
		return $url;
	}
	
	/**
	 * Convert string to a URL frendly slug.
	 * 
	 * @param string $text
	 * @return string
	 */
	public static function get_slug( $text = '') {
		return str_replace(' ', '-', strtolower($text));
	}
	
	/**
	 * Convert 5 letter locale to 2 letter locale.
	 * 
	 * @param string $locale
	 * @return string
	 */
	public static function format_location( $locale) {
		switch ($locale) {
			case 'de_DE':
				return 'de';
				
			case 'zh_CN':
				return 'zh';
				
			case 'en_GB':
			default:
				return 'en';
		}
	}
}
