<?php

/**
 * Woocommerce CiviCRM Manger class.
 *
 * @since 2.0
 */

class Woocommerce_CiviCRM_Manager {

	/**
	 * Initialises this object.
	 *
	 * @since 2.0
	 */
	public function __construct(){

		$this->register_hooks();

	}

	/**
	 * Register hooks.
	 *
	 * @since 2.0
	 */
	public function register_hooks(){

		add_action('woocommerce_checkout_order_processed', array( $this, 'action_order' ), 10 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'update_order_status' ), 99, 3 );

	}

	/**
	 * Action called when order is created in Woocommenrce.
	 *
	 * @since 2.0
	 * @param int $order_id The order id
	 */
	 public function action_order( $order_id ){

		$order = new WC_Order( $order_id );

		$cid = Woocommerce_CiviCRM_Helper::$instance->civicrm_get_cid( $order );
  	if ( $cid === FALSE ) {
			return;
  	}

  	$cid = $this->add_update_contact( $cid, $order );

  	if ( $cid === FALSE ) {
			return;
  	}

		// Add the contribution record.
		$this->add_contribution( $cid, $order );

		return $order_id;

	}

	/**
	 * Update Order status.
	 *
	 * @since 2.0
	 * @param int $order_id The order id
	 * @param string $old_status The old status
	 * @param string $new_status The new status
	 */
	public function update_order_status( $order_id, $old_status, $new_status ){

		$order = new WC_Order( $order_id );

		$params = array(
			'invoice_id' => $order_id . '_woocommerce',
			'return' => 'id'
		);

		try {

			/**
			 * Filter Contribution params before calling the Civi's API.
			 *
			 * @since 2.0
			 * @param array $params The params to be passsed to the API
			 */
			$contribution = civicrm_api3( 'Contribution', 'getsingle', apply_filters( 'woocommerce_civicrm_contribution_update_params', $params ) );
		} catch ( Exception $e ) {
			CRM_Core_Error::debug_log_message( 'Not able to find contribution' );
			return;
		}

		// Update contribution
		try {
			$params = array(
				'contribution_status_id' => $this->map_contribution_status( $order->get_status() ),
				'id' => $contribution['id'],
			);
			$result = civicrm_api3( 'Contribution', 'create', $params );
		} catch ( Exception $e ){
			CRM_Core_Error::debug_log_message( __( 'Not able to update contribution', 'woocommerce-civicrm' ) );
			return;
		}

	}

	/**
	 * Create or update contact.
	 *
	 * @since 2.0
	 * @param int $cid The contact_id
	 * @param object $order The order object
	 * @return int $cid The contact_id
	 */
	public function add_update_contact( $cid, $order ){

		$action = 'create';

		$contact = array();
		if( $cid != 0 ){
			try {
				$params = array(
					'contact_id' => $cid,
					'return' => array( 'id', 'source', 'first_name', 'last_name' ),
				);
				$contact = civicrm_api3( 'contact', 'getsingle', $params );
			} catch ( CiviCRM_Exception $e ){
				CRM_Core_Error::debug_log_message( __( 'Not able to find contact', 'woocommerce-civicrm' ) );
				return FALSE;
			}
		}

		// Create contact
		// Prepare array to update contact via civi API.
		$cid = '';
		$email = $order->get_billing_email();
		$fname = $order->get_billing_first_name();
		$lname = $order->get_billing_last_name();

		// Try to get contact Id using dedupe
		$contact['first_name'] = $fname;
		$contact['last_name'] = $lname;
		$contact['email'] = $email;
		$dedupeParams = CRM_Dedupe_Finder::formatParams( $contact, 'Individual' );
		$dedupeParams['check_permission'] = FALSE;
		$ids = CRM_Dedupe_Finder::dupesByParams( $dedupeParams, 'Individual', 'Unsupervised' );

		if( $ids ){
			$cid = $ids['0'];
			$action = 'update';
		}

		$contact['display_name'] = "{$fname} {$lname}";
		if( ! $cid ){
			$contact['contact_type'] = 'Individual';
		}

		if( isset( $contact['contact_subtype'] ) ){
			unset( $contact['contact_subtype'] );
		}
		if( empty( $contact['source'] ) ){
			$contact['source'] = __( 'Woocommerce purchase', 'woocommerce-civicrm' );
		}

		// Create contact or update existing contact.
		try {
			$result = civicrm_api3( 'Contact', 'create', $contact );
			$cid = $result['id'];
			$name = trim( $contact['display_name'] );
			$name = ! empty( $name ) ? $contact['display_name'] : $cid;
			$contact_url = "<a href='" . get_admin_url() . "admin.php?page=CiviCRM&q=civicrm/contact/view&reset=1&cid=" . $cid . "'>" . __( 'View', 'woocommerce-civicrm' ) . "</a>";

			// Add order note
			if( $action == 'update' ){
				$note = __( 'CiviCRM Contact Updated - ', 'woocommerce-civicrm' ) . $contact_url;
			} else {
				$note = __( 'Created new CiviCRM Contact - ', 'woocommerce-civicrm' ) . $contact_url;
			}
			$order->add_order_note( $note );
		} catch ( Exception $e ){
			CRM_Core_Error::debug_log_message( __( 'Not able to create/update contact', 'woocommerce-civicrm' ) );
			return FALSE;
		}

		try {
			$existing_addresses = civicrm_api3( 'Address', 'get', array( 'contact_id' => $cid ) );
			$existing_addresses = $existing_addresses['values'];
			$existing_phones = civicrm_api3( 'Phone', 'get', array( 'contact_id' => $cid ) );
			$existing_phones = $existing_phones['values'];
			$existing_emails = civicrm_api3( 'Email', 'get', array( 'contact_id' => $cid ) );
			$existing_email = $existing_emails['values'];
			$address_types = Woocommerce_CiviCRM_Helper::$instance->mapped_location_types;

			foreach( $address_types as $address_type => $location_type_id ){

				// Process Phone
				$phone_exists = FALSE;
				// 'shipping_phone' does not exist as a Woocommerce field
				if( $address_type != 'shipping' && ! empty( $order->{'get_' . $address_type . '_phone'}() ) ){
					$phone = array(
						'phone_type_id' => 1,
						'location_type_id' => $location_type_id,
						'phone' => $order->{'get_' . $address_type . '_phone'}(),
						'contact_id' => $cid,
					);
					foreach( $existing_phones as $existing_phone ){
						if( $existing_phone['location_type_id'] == $location_type_id ){
							$phone['id'] = $existing_phone['id'];
						}
						if( $existing_phone['phone'] == $phone['phone'] ){
							$phone_exists = TRUE;
						}
					}
					if( ! $phone_exists ){
					civicrm_api3( 'Phone', 'create', $phone );

						$note = __( "Created new CiviCRM Phone of type {$address_type}: {$phone['phone']}", 'woocommerce-civicrm' );
						$order->add_order_note( $note );
					}
				}

				// Process Email
				$email_exists = FALSE;
				// 'shipping_email' does not exist as a Woocommerce field
				if( $address_type != 'shipping' && ! empty( $order->{'get_' . $address_type . '_email'}() ) ){
					$email = array(
						'location_type_id' => $location_type_id,
						'email' => $order->{'get_' . $address_type . '_email'}(),
						'contact_id' => $cid,
					);
					foreach( $existing_emails as $existing_email ){
						if( $existing_email['location_type_id'] == $location_type_id ){
							$email['id'] = $existing_email['id'];
						}
						if( $existing_email['email'] == $email['email'] ){
							$email_exists = TRUE;
						}
					}
					if( ! $email_exists ){
					civicrm_api3( 'Email', 'create', $email );
						$note = __( "Created new CiviCRM Email of type {$address_type}: {$email['email']}", 'woocommerce-civicrm' );
						$order->add_order_note( $note );
					}
				}

				// Process Address
				$address_exists = FALSE;
				if( ! empty( $order->{'get_' . $address_type . '_address_1'}() ) && ! empty( $order->{'get_' . $address_type . '_postcode'}() ) ){

					$country_id = Woocommerce_CiviCRM_Helper::$instance->get_civi_country_id( $order->{'get_' . $address_type . '_country'}() );
					$address = array(
						'location_type_id'       => $location_type_id,
						'city'                   => $order->{'get_' . $address_type . '_city'}(),
						'postal_code'            => $order->{'get_' . $address_type . '_postcode'}(),
						'name'                   => $order->{'get_' . $address_type . '_company'}(),
						'street_address'         => $order->{'get_' . $address_type . '_address_1'}(),
						'supplemental_address_1' => $order->{'get_' . $address_type . '_address_2'}(),
						'country'                => $country_id,
						'state_province_id'      => Woocommerce_CiviCRM_Helper::$instance->get_civi_state_province_id( $order->{'get_' . $address_type . '_state'}(), $country_id ),
						'contact_id'             => $cid,
					);

					foreach( $existing_addresses as $existing ){
						if( $existing['location_type_id'] == $location_type_id ){
							$address['id'] = $existing['id'];
						}
						// @TODO Don't create if exact match of another - should
						// we make 'exact match' configurable.
						elseif (
							$existing['street_address'] == $address['street_address']
							&& CRM_Utils_Array::value( 'supplemental_address_1', $existing ) == CRM_Utils_Array::value( 'supplemental_address_1', $address )
							&& $existing['city'] == $address['city']
							&& $existing['postal_code'] == $address['postal_code']
						){
							$address_exists = TRUE;
						}
					}
					if( ! $address_exists ){
						civicrm_api3( 'Address', 'create', $address );

						$note = __( "Created new CiviCRM Address of type {$address_type}: {$address['street_address']}", 'woocommerce-civicrm' );
						$order->add_order_note( $note );
					}
				}
			}
		} catch ( CiviCRM_Exception $e ){
			CRM_Core_Error::debug_log_message( __( 'Not able to add/update address or phone', 'woocommerce-civicrm' ) );
		}

		return $cid;

	}

	/**
	 * Fuction to add a contribution record.
	 *
	 * @since 2.0
	 * @param int $cid The contact_id
	 * @param object $order The order object
	 */
	public function add_contribution( $cid, &$order ) {

		$txn_id = __( 'Woocommerce Order - ', 'woocommerce-civicrm' ) . $order->get_id();
		$invoice_id = $order->get_id() . '_woocommerce';

		$this->create_custom_contribution_fields();

		$sales_tax_field_id = 'custom_' . get_option( 'woocommerce_civicrm_sales_tax_field_id' );
		$shipping_cost_field_id = 'custom_' . get_option( 'woocommerce_civicrm_shipping_cost_field_id' );

		$sales_tax = $order->get_total_tax();
		$sales_tax = number_format( $sales_tax, 2 );

		$shipping_cost = $order->get_total_shipping();
		$shipping_cost = number_format( $shipping_cost, 2 );

		// @FIXME Landmine. CiviCRM doesn't seem to accept financial values
		// with precision greater than 2 digits after the decimal.
		$rounded_total = round( $order->get_total() * 100 ) / 100;

		// Couldn't figure where Woocommerce stores the subtotal (ie no TAX price)
		// So for now...
		$rounded_subtotal = $rounded_total - $sales_tax;

		$contribution_type_id = get_option( 'woocommerce_civicrm_financial_type_id' );
		$contribution_type_vat_id = get_option( 'woocommerce_civicrm_financial_type_vat_id' ); // Get the VAT Financial type

		// If the order has VAT (Tax) use VAT Fnancial type
		if( $sales_tax != 0 ){
			$params = array(
				'contact_id' => $cid,
				'total_amount' => $rounded_subtotal,
				// Need to be set in admin page
				'contribution_type_id' => $contribution_type_vat_id,
				'payment_instrument_id' => $this->map_payment_instrument( $order->get_payment_method() ),
				'non_deductible_amount' => 00.00,
				'fee_amount' => 00.00,
				'total_amount' => $rounded_subtotal,
				'trxn_id' => $txn_id,
				'invoice_id' => $invoice_id,
				'source' => $this->create_detail_string( $order ),
				'receive_date' => 'now',
				'contribution_status_id' => $this->map_contribution_status( $order->get_status() ),
				'note' => $this->create_detail_string( $order ),
				"$sales_tax_field_id" => $sales_tax,
				"$shipping_cost_field_id" => $shipping_cost,
			);
		} else {
			$params = array(
				'contact_id' => $cid,
				'total_amount' => $rounded_total,
				// Need to be set in admin page
				'contribution_type_id' => $contribution_type_id,
				'payment_instrument_id' => $this->map_payment_instrument( $order->get_payment_method() ),
				'non_deductible_amount' => 00.00,
				'fee_amount' => 00.00,
				'total_amount' => $rounded_total,
				'trxn_id' => $txn_id,
				'invoice_id' => $invoice_id,
				'source' => $this->create_detail_string( $order ),
				'receive_date' => 'now',
				'contribution_status_id' => $this->map_contribution_status( $order->get_status() ),
				'note' => $this->create_detail_string( $order ),
				"$sales_tax_field_id" => $sales_tax,
				"$shipping_cost_field_id" => $shipping_cost,
			);
		}

		try {
			/**
		 * Filter Contribution params before calling the Civi's API.
		 *
		 * @since 2.0
		 * @param array $params The params to be passsed to the API
		 */
			$contribution = civicrm_api3( 'Contribution', 'create', apply_filters( 'woocommerce_civicrm_contribution_create_params', $params ) );
		} catch ( Exception $e ) {
			// Log the error, but continue.
			return FALSE;
		}

		return TRUE;
	}

	/**
	 * Maps Woocommerce payment method to CiviCRM payment instrument.
	 *
	 * @since 2.0
	 * @param string $payment_method Woocommerce payment method
	 * @return int $id CiviCRM payment processor ID
	 */
	public function map_payment_instrument( $payment_method ) {
		$map = array(
			"paypal" 	=> 1,
			"cod"  		=> 3,
			"cheque"  => 4,
			"bacs" 		=> 5,
		);

		if( array_key_exists( $payment_method, $map ) ){
			$id = $map[$payment_method];
		} else {
			// Another Woocommerce payment method - good chance this is credit.
			$id = 1;
		}

		return $id;

	}

	/**
	 * Create string to insert for purchase activity details.
	 *
	 * @since 2.0
	 * @param object $order The order object
	 * @return string $str
	 */
	public function create_detail_string( $order ) {
		$items = $order->get_items();

		$str = '';
		$n = 1;
		foreach( $items as $item ){
			if ( $n > 1 ) {
				$str .= ', ';
			}
			$str .= $item['name'].' x '.$item['quantity'];
			$n++;
		}

		return $str;

	}

	/**
	 * Maps WooCommerce order status to CiviCRM contribution status.
	 *
	 * @since 2.0
	 * @param string $order_status WooCommerce order status
	 * @return int $id CiviCRM Contribution status
	 */
	public function map_contribution_status( $order_status ) {

		$map = array(
			'wc-completed'  => 1,
			'wc-pending'    => 2,
			'wc-cancelled'  => 3,
			'wc-failed'     => 4,
			'wc-processing' => 5,
			'wc-on-hold'    => 5,
			'wc-refunded'   => 7,
		);

		if ( array_key_exists( $order_status, $map ) ) {
			$id = $map[$order_status];
		} else {
			// Oh no.
			$id = 1;
		}

		return $id;

	}

	/**
	 * Function to create sales tax and shipping cost custom fields for contribution.
	 *
	 * @since 2.0
	 */
	public function create_custom_contribution_fields(){
		$group_id = get_option( 'woocommerce_civicrm_contribution_group_id', FALSE );
		if( $group_id != FALSE ){
			return;
		}

		// First we need to check if the VAT and Shipping custom fields have
		// already been created.
		$params = array(
			'title'            => 'Woocommerce Purchases',
			'name'             => 'Woocommerce_purchases',
			'extends'          => array( 'Contribution' ),
			'weight'           => 1,
			'collapse_display' => 0,
			'is_active'        => 1,
		);

		try {
			$custom_group = civicrm_api3( 'CustomGroup', 'create', $params );
		} catch ( CiviCRM_API3_Exception $e ){
			CRM_Core_Error::debug_log_message( __( 'Not able to create custom group', 'woocommerce-civicrm' ) );
		}
		add_option( 'woocommerce_civicrm_contribution_group_id', $custom_group['id'] );

		$params = array(
			'custom_group_id' => $custom_group['id'],
			'label'           => 'Sales tax',
			'html_type'       => 'Text',
			'data_type'       => 'String',
			'weight'          => 1,
			'is_required'     => 0,
			'is_searchable'   => 0,
			'is_active'       => 1,
		);
		$tax_field = civicrm_api3( 'Custom_field', 'create', $params );
		add_option( 'woocommerce_civicrm_sales_tax_field_id', $tax_field['id'] );

		$params = array(
			'custom_group_id' => $custom_group['id'],
			'label'           => 'Shipping Cost',
			'html_type'       => 'Text',
			'data_type'       => 'String',
			'weight'          => 2,
			'is_required'     => 0,
			'is_searchable'   => 0,
			'is_active'       => 1,
		);
		$shipping_field = civicrm_api3( 'Custom_field', 'create', $params );
		add_option( 'woocommerce_civicrm_shipping_cost_field_id', $shipping_field['id'] );
	}
}
