<?php

defined( 'ABSPATH' ) || exit;

/**
 * Just a helper class to use some functions form Request class for the Cashier
 * and/or Nuvei_Gateway Class.
 */
class Nuvei_Helper extends Nuvei_Request
{
	public function process()
    {
		
	}
	
	protected function get_checksum_params()
    {
		
	}

	public function get_addresses()
    {
		return $this->get_order_addresses();
	}
	
	public function get_products()
    {
		return $this->get_products_data();
	}
    
    /**
     * Just a helper function to extract last of Nuvei transactions.
     * It is possible to set array of desired types. First found will
     * be returned.
     * 
     * @param array $transactions List with all transactions
     * @param array $types Search for specific type/s.
     * 
     * @return array
     */
    public function get_last_transaction(array $transactions, array $types = [])
    {
        if (empty($transactions) || !is_array($transactions)) {
            Nuvei_Logger::write($transactions, 'Problem with trnsactions array.');
            return [];
        }
        
        if (empty($types)) {
            return end($transactions);
        }
        
        foreach (array_reverse($transactions) as $trId => $data) {
            if (in_array($data['transactionType'], $types)) {
                return $data;
            }
        }
        
        return [];
    }
    
    /**
     * Temp help function until stop using old Order meta fields.
     * 
     * @param int|null $order_id WC Order ID
     * @param array $types Search for specific type/s.
     * 
     * @return int
     */
    public function get_tr_id($order_id = null, $types = [])
    {
        $order = $this->get_order($order_id);
        
        // first check for new meta data
        $ord_tr_id = $order->get_meta(NUVEI_TR_ID);
        
        if (!empty($ord_tr_id)) {
            return $ord_tr_id;
        }
        
        $nuvei_data = $order->get_meta(NUVEI_TRANSACTIONS);
        
        if (!empty($nuvei_data) && is_array($nuvei_data)) {
            // just get from last transaction
            if (empty($types)) {
                $last_tr = end($nuvei_data);
            }
            // get last transaction by type
            else {
                $last_tr = $this->get_last_transaction($nuvei_data, $types);
            }
            
            if (!empty($last_tr['transactionId'])) {
                return $last_tr['transactionId'];
            }
        }
        
        // check for old meta data
        return $order->get_meta('_transactionId'); // NUVEI_TRANS_ID
    }
	
    /**
     * Temp help function until stop using old Order meta fields.
     * 
     * @param int|null $order_id WC Order ID
     * @return int
     */
    public function get_tr_upo_id($order_id = null)
    {
        $order = $this->get_order($order_id);
        
        // first check for new meta data
        $nuvei_data = $order->get_meta(NUVEI_TRANSACTIONS);
        
        if (!empty($nuvei_data) && is_array($nuvei_data)) {
            $last_tr = end($nuvei_data);
            
            if (!empty($last_tr['userPaymentOptionId'])) {
                return $last_tr['userPaymentOptionId'];
            }
        }
        
        // check for old meta data
        return $order->get_meta('_transactionUpo'); // NUVEI_UPO
    }
    
    /**
     * Temp help function until stop using old Order meta fields.
     * 
     * @param int|null $order_id WC Order ID
     * @return int
     */
    public function get_tr_status($order_id = null)
    {
        $order = $this->get_order($order_id);
        
        // first check for new meta data
        $nuvei_data = $order->get_meta(NUVEI_TRANSACTIONS);
        
        if (!empty($nuvei_data) && is_array($nuvei_data)) {
            $last_tr = end($nuvei_data);
            
            if (!empty($last_tr['status'])) {
                return $last_tr['status'];
            }
        }
        
        // check for old meta data
        return $order->get_meta('_transactionStatus'); // NUVEI_TRANS_STATUS
    }
    
    /**
     * Temp help function until stop using old Order meta fields.
     * 
     * @param int|null $order_id WC Order ID
     * @return int
     */
    public function get_payment_method($order_id = null)
    {
        $order = $this->get_order($order_id);
        
        // first check for new meta data
        $nuvei_data = $order->get_meta(NUVEI_TRANSACTIONS);
        
        if (!empty($nuvei_data) && is_array($nuvei_data)) {
            $last_tr = $this->get_last_transaction($nuvei_data, ['Sale', 'Settle']);
            
            if (!empty($last_tr['paymentMethod'])) {
                return $last_tr['paymentMethod'];
            }
        }
        
        // check for old meta data
        return $order->get_meta('_paymentMethod'); // NUVEI_PAYMENT_METHOD
    }
    
    /**
     * Temp help function until stop using old Order meta fields.
     * 
     * @param int|null $order_id WC Order ID
     * @return int
     */
    public function get_tr_type($order_id = null)
    {
        $order = $this->get_order($order_id);
        
        // first check for new meta data
        $nuvei_data = $order->get_meta(NUVEI_TRANSACTIONS);
        
        if (!empty($nuvei_data) && is_array($nuvei_data)) {
            $last_tr = end($nuvei_data);
            
            if (!empty($last_tr['transactionType'])) {
                return $last_tr['transactionType'];
            }
        }
        
        // check for old meta data
        return $order->get_meta('_transactionType'); // NUVEI_RESP_TRANS_TYPE
    }
    
    /**
     * Temp help function until stop using old Order meta fields.
     * 
     * @param int|null $order_id WC Order ID
     * @return int
     */
    public function get_refunds($order_id = null)
    {
        $order = $this->get_order($order_id);
        
        // first check for new meta data
        $nuvei_data = $order->get_meta(NUVEI_TRANSACTIONS);
        
        if (!empty($nuvei_data) && is_array($nuvei_data)) {
            $last_tr = end($nuvei_data);
            
            if (!empty($last_tr['transactionType'])) {
                return $last_tr['transactionType'];
            }
        }
        
        // check for old meta data
        return $order->get_meta('_transactionType'); // NUVEI_RESP_TRANS_TYPE
    }
    
    /**
     * Temp help function until stop using old Order meta fields.
     * 
     * @param int|null $order_id WC Order ID
     * @return int
     */
    public function are_there_subscr($order_id = null)
    {
        $order = $this->get_order($order_id);
        
        // first check for new meta data
        $subscr_data = $order->get_meta(NUVEI_ORDER_SUBSCR);
        
        // check for old meta data
        return $order->get_meta('_transactionType'); // NUVEI_RESP_TRANS_TYPE
    }
    
    /**
     * A help function for the above methods.
     */
    private function get_order($order_id)
    {
        if (empty($this->sc_order)) {
            $order = wc_get_order($order_id);
        }
        elseif ($order_id == $this->sc_order->get_id()) {
            $order = $this->sc_order;
        }
        else {
            $order = wc_get_order($order_id);
        }
        
        return $order;
    }
	
}
