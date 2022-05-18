<?php

defined( 'ABSPATH' ) || exit;

/**
 * Just a helper class to use some functions form Request class for the Cashier
 * and/or Nuvei_Gateway Class.
 */
class Nuvei_Helper extends Nuvei_Request {

	public function process() {
		
	}
	
	protected function get_checksum_params() {
		
	}

	public function get_addresses() {
		return $this->get_order_addresses();
	}
	
	public function get_products() {
		return $this->get_products_data();
	}
	
}
