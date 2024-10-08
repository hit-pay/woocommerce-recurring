<?php
/**
 * HitPay Payment Gateway Core class
 *
 * This is the main class of the HitPay Payment Gateway plugin for WooCommerce.
 * The class extends the WooCommerce's WC_Payment_Gateway class
 * and provides necessary functionality for payments and subscriptions.
 */
class HitPay_Payment_Gateway_Core extends WC_Payment_Gateway {

    public function __construct() {

        /**
         * ID of the class extending the settings API. Used in option names.
         */
        $this->id = 'hit_pay';

        /**
         * Supported features such as 'default_credit_card_form', 'refunds'.
         *
         * @var array
         */
        $this->supports = [
            'products',
            'refunds',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'multiple_subscriptions',
        ];

        /**
         * Icon for the gateway.
         */
        $this->icon = plugin_dir_url( __DIR__ ) . 'assets/images/logo.png';

        /**
         * True if the gateway shows fields on the checkout.
         */
        $this->has_fields = false;

        /**
         * Gateway title.
         */
        $this->method_title = __( 'HitPay Payment Gateway', 'hitpay-payment-gateway' );

        /**
         * Gateway description.
         */
        $this->method_description = __( 'Accept PayNow QR, Cards, Apple Pay, Google Pay, WeChatPay, AliPay, GrabPay, and other popular payment methods.', 'hitpay-payment-gateway' ) . "\n"
            . __( 'Visit <a href="https://www.hitpayapp.com">hitpayapp.com</a> for more details.', 'hitpay-payment-gateway' );

        /**
         * Initialise settings form fields.
         *
         * Add an array of fields to be displayed on the gateway's settings screen.
         */
        $this->init_form_fields();

        /**
         * Init settings for gateways.
         */
        $this->init_settings();

        /**
         * Applying all the options from the Settings page to the instance.
         */
        $this->enabled              = $this->get_option( 'enabled' );
        $this->title                = $this->get_option( 'title' );
        $this->description          = $this->get_option( 'description' );
        $this->live_mode            = $this->get_option( 'live_mode' );
        $this->api_key              = $this->get_option( 'api_key' );
        $this->api_salt             = $this->get_option( 'api_salt' );
        $this->payment_logos        = $this->get_option( 'payment_logos' );
        $this->status_after_payment = $this->get_option( 'status_after_payment' );
        $this->payment_link_expires = $this->get_option( 'payment_link_expires' );
        $this->payment_link_ttl     = $this->get_option( 'payment_link_ttl' );
    }

    /**
     * Process Payment.
     *
     * Process the payment. This should return the success
     * and redirect in an array. e.g:
     *
     *        return array(
     *            'result'   => 'success',
     *            'redirect' => $this->get_return_url( $order )
     *        );
     *
     * @param int $order_id Order ID.
     * @return array
     * @throws Exception
     */
    public function process_payment( $order_id ) {

        $order = wc_get_order( $order_id );

        if ( $this->subscriptions_activated() ) {
            if ( wcs_order_contains_subscription( $order ) ) {
                return $this->process_subscription_payment( $order );
            }
        }

        return $this->process_regular_payment( $order );
    }

    /**
     * Process a regular payment.
     *
     * This should return the success and redirect in an array. e.g:
     *
     *        return array(
     *            'result'   => 'success',
     *            'redirect' => $this->get_return_url( $order )
     *        );
     *
     * @param WC_Order $order Order.
     * @return array
     */
    public function process_regular_payment( WC_Order $order ) {
        $this->log($order->get_id().' => process_regular_payment triggered');
        $customer_full_name = $order->get_billing_first_name() . ' '
            . $order->get_billing_last_name();

        $webhook_url = add_query_arg( 'wc-api', 'hitpay-regular-payments', site_url( '/' ) );

        $payment_request = new HitPay_Payment_Request( $this->get_gateway_api() );
        
        $this->log('hitpay_set_amount: '.$order->get_total());
        $this->log('hitpay_set_reference: '.$order->get_order_number());
        $this->log('hitpay_set_webhook: '.$webhook_url);

        $payment_request
            ->set_amount( $order->get_total() )
            ->set_currency( $order->get_currency() )
            ->set_name( $customer_full_name )
            ->set_email( $order->get_billing_email() )
            ->set_purpose( get_bloginfo() )
            ->set_reference_number( $order->get_order_number() )
            ->set_redirect_url( $this->get_return_url( $order ) )
            ->set_webhook( $webhook_url );

		/**
		 * Payment link TTL (time-to-live):
		 */
		if ( "yes" == $this->get_option( 'payment_link_expires' )
			&& $payment_link_ttl = $this->get_option( 'payment_link_ttl' ) ) {

			$expire_date = new DateTime( 'now', new DateTimeZone( HitPay_Gateway_API::TIMEZONE ) );

			$expire_date->modify( "+" . intval( $payment_link_ttl ) . " minutes" );

			$payment_request->set_expiry_date( $expire_date->format( 'Y-m-d H:i:s' ) );
		}

		$response = $payment_request->create();
                
                $this->log($response);
                
        if ( ! $response || $response->status != 'pending' ) {
            return [ 'result' => 'error' ];
        }
        
        $this->log('hitpay_payment_id: '.$response->id);
        $this->log('hitpay_payment_status: '.$response->status);

        WC()->cart->empty_cart();

        return [
            'result'    => 'success',
            'redirect'  => $response->url,
        ];
    }

    /**
     * Process a payment for subscriptions (recurring).
     *
     * This should return the success and redirect in an array. e.g:
     *
     *        return array(
     *            'result'   => 'success',
     *            'redirect' => $this->get_return_url( $order )
     *        );
     *
     * @param WC_Order $order
     * @return array
     * @throws Exception
     */
    public function process_subscription_payment( WC_Order $order ) {
        $this->log($order->get_id().' => process_subscription_payment triggered');
        
        $customer_full_name = $order->get_billing_first_name() . ' '
            . $order->get_billing_last_name();

        $recurring_billing_request = new HitPay_Recurring_Billing_Request( $this->get_gateway_api() );

        $subscription_starts_from = new DateTime(
            'now',
            new DateTimeZone( HitPay_Gateway_API::TIMEZONE )
        );

        $webhook_url = add_query_arg( 'wc-api', 'hitpay-recurring-payments', site_url( '/' ) );

	$subscription = current( wcs_get_subscriptions_for_order( $order ) );
        
        $this->log('hitpay_recurring_set_amount: '.$order->get_total());
        $this->log('hitpay_recurring_set_reference: '.$order->get_order_number());
        $this->log('hitpay_recurring_set_start_date: '.$subscription_starts_from->format( 'Y-m-d' ));
        $this->log('hitpay_recurring_set_webhook: '.$webhook_url);
        $this->log('hitpay_recurring subscription id: '.$subscription->get_id());

        $response = $recurring_billing_request
            ->set_name( 'Subscription #' . $subscription->get_order_number() )
            ->set_amount( $order->get_total() )
            ->set_webhook( $webhook_url )
            ->set_currency( $order->get_currency() )
            ->set_reference( $order->get_order_number() )
            ->set_customer_email( $order->get_billing_email() )
            ->set_customer_name( $customer_full_name )
            ->set_start_date( $subscription_starts_from->format( 'Y-m-d' ) )
            ->set_redirect_url( $this->get_return_url( $order ) )
            ->set_save_card( true )
            ->create();

        if ( ! $response || $response->status != 'scheduled' ) {
            return [ 'result' => 'error' ];
        }
        
        $this->log('hitpay_recurring_billing_id: '.$response->id);
        $this->log('hitpay_recurring_status: '.$response->status);

        /**
         * Save the recurring billing ID.
         * It's used for future automatic payments.
         */
        add_post_meta(
                $subscription->get_id(),
                '_hitpay_recurring_billing_id',
                $response->id,
                true
        );

        WC()->cart->empty_cart();

        return [
            'result'    => 'success',
            'redirect'  => $response->url,
        ];
    }

    /**
     * Handle webhook request for regular payments from HitPay API.
     *
     * POST-parameters:
     * - payment_id
     * - payment_request_id
     * - phone
     * - amount
     * - currency
     * - status
     * - reference_number
     * - hmac
     * @return void
     */
    public function handle_webhook_regular_payment() {
        $this->log('handle_webhook_regular_payment triggered');
        $data = $_POST;
        $this->log($data);
        
        $parameters = [
            'reference_number',
            'payment_id',
            'payment_request_id',
            'status',
            'hmac',
        ];

        /**
         * Check if necessary parameters are specified:
         */
        if ( array_diff_key( array_flip( $parameters ), $data ) ) {
            return;
        }

        $hmac =  $data[ 'hmac' ];

        /**
         * Remove this key since it shouldn't be a part of analyzing data.
         */
        unset( $data[ 'hmac' ] );

        if ( HitPay_Security::get_signature( $this->get_option( 'api_salt' ), $data ) != $hmac ) {
            $this->log('Security check hmac failed');
            return;
        }

        $order_id = $data[ 'reference_number' ];

        $order = wc_get_order( $order_id );

        if ( ! $order || ! $order->needs_payment() ) {
            $this->log('Skip if the order is already paid');
            return;
        }

        if ( 'completed' == $data[ 'status' ] ) {

            /**
             * We need this meta for refunds processing.
             */
            $order->add_meta_data( '_hitpay_payment_id', $data[ 'payment_id' ], true );

			$this->payment_complete( $order );
        }

        if ( 'failed' == $data[ 'status' ] ) {
            $order->update_status( 'failed' );
        }
    }

    /**
     * Handle webhook request for recurring payments from HitPay API.
     *
     * POST-parameters:
     * - payment_id
     * - recurring_billing_id
     * - amount
     * - currency
     * - status
     * - reference
     * - hmac
     *
     * @return void
     */
    public function handle_webhook_recurring_payment() {
        $this->log('handle_webhook_recurring_payment triggered');
        $data = $_POST;
        
        $this->log($data);

        $parameters = [
            'payment_id',
            'recurring_billing_id',
            'amount',
            'currency',
            'reference',
            'status',
            'hmac',
        ];

        /**
         * Check if necessary parameters are specified:
         */
        if ( array_diff_key( array_flip( $parameters ), $data ) ) {
            return;
        }

        $hmac =  $data[ 'hmac' ];

        /**
         * Remove this key since it shouldn't be a part of analyzing data.
         */
        unset( $data[ 'hmac' ] );

        if ( HitPay_Security::get_signature( $this->get_option( 'api_salt' ), $data ) != $hmac ) {
            $this->log('Security check hmac failed');
            return;
        }

        $order_id = $data[ 'reference' ];

        $order = wc_get_order( $order_id );

        if ( ! $order || ! $order->needs_payment() ) {
            $this->log('Skip if the order is already paid');
            return;
        }

        if ( 'succeeded' == $data[ 'status' ] ) {

            /**
             * We need this meta for refunds processing.
             */
            $order->add_meta_data( '_hitpay_payment_id', $data[ 'payment_id' ], true );

			$subscription = current( wcs_get_subscriptions_for_order( $order ) );

			add_post_meta(
				$subscription->get_id(),
				'_hitpay_recurring_billing_id',
				$data[ 'recurring_billing_id' ],
				true
			);

			$this->payment_complete( $order );
        }

        if ( 'failed' == $data[ 'status' ] ) {
            $order->update_status( 'failed' );
        }
    }

    /**
     * Process the first recurring payment
     * (after adding card information).
     *
     * @param integer $order_id Order ID.
     */
    public function handle_first_recurring_payment( $order_id ) {
        $this->log($order_id.' => handle_first_recurring_payment triggered');

        if ($order_id > 0) {
            $HitPay_webhook_triggered = (int)get_post_meta( $order_id, 'HitPay_FRP_Triggered', true );
            if ($HitPay_webhook_triggered == 1) {
                $this->log($order_id.' => handle_first_recurring_payment already triggered');
                return false;
            }
        }
        
        update_post_meta( $order_id, 'HitPay_FRP_Triggered', 1 );

        $params = [ 'key', 'type', 'reference', 'status' ];

        // Skip processing if parameters are absent:
        if ( array_diff_key( array_flip( $params ), $_GET ) ) {
                return false;
        }

        $this->log($_GET);

        // We process only recurring payments:
        if ( $_GET[ 'type' ] != 'recurring' ||  $_GET[ 'status' ] != 'active' ) {
                return false;
        }

        $order = wc_get_order( $order_id );

        // Skip if the order is already paid:
        if ( ! $order || ! $order->needs_payment() ) {
            $this->log('Skip if the order is already paid');
            return false;
        }

        // Security check:
        if ( $_GET[ 'key' ] != $order->get_order_key() ) {
            $this->log('Security check failed');
            return false;
        }

        $subscription = current( wcs_get_subscriptions_for_order( $order ) );
        $this->log('handle_first_recurring_payment subscription id: '.$subscription->get_id());
        $recurring_billing_id = get_post_meta( $subscription->get_id(), '_hitpay_recurring_billing_id', true );

        $this->log('hitpay_recurring_billing_id: '.$recurring_billing_id);

        if ( ! $recurring_billing_id ) {
                return false;
        }

        $recurring_billing_request = new HitPay_Recurring_Billing_Request( $this->get_gateway_api() );

        $response = $recurring_billing_request
                ->set_recurring_billing_id( $recurring_billing_id )
                ->set_amount( $order->get_total() )
                ->set_currency( $order->get_currency() )
                ->charge();

        $this->log($response);

        if ( $response && $response->status == 'succeeded' ) {
            $this->payment_complete( $order );
        }
    }

    /**
     * Process a scheduled subscription payment.
     *
     * @param float    $amount  The amount to charge.
     * @param WC_Order $order   A WC_Order object created to record the renewal payment.
     */
    public function process_scheduled_subscription_payment( $amount, $order ) {
        $this->log($order->get_id().' => process_scheduled_subscription_payment triggered');
        $recurring_billing_id = $order->get_meta( '_hitpay_recurring_billing_id' );
        
        $this->log('hitpay_recurring_billing_id: '.$recurring_billing_id);

        if ( ! $recurring_billing_id ) {
            $order->update_status( 'failed' );
            return;
        }

        $recurring_billing_request = new HitPay_Recurring_Billing_Request( $this->get_gateway_api() );

        $response = $recurring_billing_request
            ->set_recurring_billing_id( $recurring_billing_id )
            ->set_amount( $amount )
            ->set_currency( $order->get_currency() )
            ->charge();

        $this->log($response);
        
        if ( $response && $response->status == 'succeeded' ) {
            $this->payment_complete( $order );
        }
    }

    /**
     * Process refund.
     *
     * If the gateway declares 'refunds' support, this will allow it to refund.
     * a passed in amount.
     *
     * @param  int        $order_id Order ID.
     * @param  float|null $amount   Refund amount.
     * @param  string     $reason   Refund reason.
     * @return boolean              True or false based on success, or a WP_Error object.
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $this->log($order_id.' => process_refund triggered');
        $order = wc_get_order( $order_id );

        if ( 0 == $amount || ! $order ) {
            return false;
        }

        $payment_id = $order->get_meta( '_hitpay_payment_id' );

        if ( ! $payment_id ) {
            return false;
        }
        
        $this->log('hitpay_payment_id: '.$payment_id);

        $refund_request = new HitPay_Refund_Request( $this->get_gateway_api() );

        $response = $refund_request
            ->set_amount( $amount )
            ->set_payment_id( $payment_id )
            ->create();

        if ( ! $response ) {
            return false;
        }

        $message = "Refund was successful. Refund reference ID: $response->id. "
            . "Amount: $response->amount_refunded " . strtoupper( $response->currency ) . '.';
        $this->log($message);
        $order->add_order_note( $message );

        return true;
    }

    /**
     * Initialise settings form fields.
     *
     * Add an array of fields to be displayed on the gateway's settings screen.
     */
    public function init_form_fields() {

        $this->form_fields =[
            'enabled' => [
                'title'   => __( 'Enable / Disable', 'hitpay-payment-gateway' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable HitPay Payment Gateway', 'hitpay-payment-gateway' ),
                'default' => 'yes'
            ],
            'title' => [
                'title'         => __( 'Title', 'hitpay-payment-gateway' ),
                'type'          => 'text',
                'description'   => __( 'This controls the title which the user sees during checkout.', 'hitpay-payment-gateway' ),
                'default'       => $this->method_title,
                'desc_tip'      => true,
            ],
            'description' => [
                'title'         => __( 'Description', 'hitpay-payment-gateway' ),
                'type'          => 'textarea',
                'description'   => __( 'Instructions that the customer will see on your checkout.', 'hitpay-payment-gateway' ),
                'desc_tip'      => true,
            ],
            'live_mode' => [
                'title'         => __( 'Live Mode', 'hitpay-payment-gateway' ),
                'type'          => 'checkbox',
                'label'         => __( 'Enable Live Mode', 'hitpay-payment-gateway' ),
                'default'       => 'no',
                'description'   => __( 'Enable checkbox to enable payments in live mode.', 'hitpay-payment-gateway' ),
                'desc_tip'      => true,
            ],
            'api_key' => [
                'title'         => __( 'API Key', 'hitpay-payment-gateway' ),
                'type'          => 'text',
                'description'   => __( 'Copy and paste values from the HitPay Dashboard under Payment Gateway > API Keys.', 'hitpay-payment-gateway' ),
                'desc_tip'      => true,
            ],
            'api_salt' => [
                'title'         => __( 'Salt', 'hitpay-payment-gateway' ),
                'type'          => 'text',
                'description'   => __( 'Copy and paste values from the HitPay Dashboard under Payment Gateway > API Keys.', 'hitpay-payment-gateway' ),
                'desc_tip'      => true,
            ],
            'payment_logos' => [
                'title'         => __( 'Payment Logos', 'hitpay-payment-gateway' ),
                'type'          => 'multiselect',
                'class'         => 'wc-enhanced-select',
                'description'   => __( 'Activate payment methods in the HitPay dashboard under Settings > Payment Gateway > Integrations.', 'hitpay-payment-gateway' ),
                'css'           => 'height: 10rem',
                'options'       => [
                    'paynow-qr'         => 'PayNow QR',
                    'visa'              => 'Visa',
                    'mastercard'        => 'Mastercard',
                    'american-express'  => 'American Express',
                    'grabpay'           => 'GrabPay',
                    'wechatpay'         => 'WeChatPay',
                    'alipay'            => 'AliPay',
                    'shopeepay'         => 'ShopeePay',
                    'hoolahpay'         => 'HoolahPay',
                ],
                'desc_tip'      => true,
            ],
            'status_after_payment' => [
                'title'         => __( 'Status After Payment', 'hitpay-payment-gateway' ),
                'type'          => 'select',
                'class'         => 'wc-enhanced-select',
                'description'   => __( 'Set your desired order status upon successful payment.', 'hitpay-payment-gateway' ),
                'options'       => $this->get_order_statuses(),
                'default'       => 'wc-processing',
                'desc_tip'      => true,
            ],
            'payment_link_expires' => [
                'title'         => __( 'Expire the payment link?', 'hitpay-payment-gateway' ),
                'type'          => 'checkbox',
                'label'         => __( 'Yes', 'hitpay-payment-gateway' ),
                'default'       => 'no',
            ],
            'payment_link_ttl' => [
                'title'         => __( 'Expire after [x] min', 'hitpay-payment-gateway' ),
                'type'          => 'text',
                'description'   => __( 'Minimum value is 5. Maximum is 1000.', 'hitpay-payment-gateway' ),
            ],
        ];
    }

    /**
     * Load JS for the Settings page.
     *
     * We have special behaviour with the form on the Settings page:
     * we hide one field and show it when a user clicks a checkbox.
     * Here we are loading JS for this functionality.
     *
     * @return void
     */
    public function admin_options()
    {
        /**
         * Output the gateway settings screen.
         */
        parent::admin_options();

        /**
         * Loading "settings-page.js".
         */
        wp_enqueue_script(
            'hitpay-settings-page',
            plugin_dir_url( __DIR__ ) . 'admin/js/settings-page.js',
            [ 'jquery' ]
        );

        wp_localize_script( 'hitpay-settings-page', 'app', [
            'paymentLinkExpires'  => ( bool ) $this->get_option( 'payment_link_expires' ),
        ] );
    }

    /**
     * Validate admin options.
     *
     * @param $settings
     * @return array
     */
    public function validate_options( $settings ) {

        $errors = [];

        if ( ! $settings[ 'api_key' ] || ! $settings[ 'api_salt' ] ) {
            $errors[] = __( 'Please enter HitPay API Key and Salt.', 'hitpay-payment-gateway' );
        }

        if ( 'yes' == $settings[ 'payment_link_expires' ] ) {

            if ( ! $settings[ 'payment_link_ttl' ] ) {
                $errors[] = __( 'Please enter "Expire after [x] min" value.', 'hitpay-payment-gateway' );
            }

            if ( $settings[ 'payment_link_ttl' ] &&  $settings[ 'payment_link_ttl' ] < 5 ) {
                $errors[] = __( 'Value for "Expire after [x] min" should not be less 5.', 'hitpay-payment-gateway' );
            }

            if ( $settings[ 'payment_link_ttl' ] &&  $settings[ 'payment_link_ttl' ] > 1000 ) {
                $errors[] = __( 'Value for "Expire after [x] min" should not be more 1000.', 'hitpay-payment-gateway' );
            }
        }

        foreach ( $errors as $error ) {
            WC_Admin_Settings::add_error( $error );
        }

        return $settings;
    }

	/**
	 * Method that is called after a successful payment.
	 *
	 * We use it to be able to set a custom status
	 * after completing a payment.
	 *
	 * @param $order WC_Order
	 * @return void
	 */
	public function payment_complete( $order ) {

		$order->payment_complete();

		if ( $custom_status = $this->get_option( 'status_after_payment' ) ) {

			$order->update_status( $custom_status, 'Status was changed according to the gateway settings.' );
		}
	}

    /**
     * Show custom payment logos.
     *
     * @param $icon_html    string HTML with `<img>` tags.
     * @param $gateway_id   string Gateway ID.
     * @return string
     */
    public function show_custom_payment_logos( $icon_html, $gateway_id ) {

        if ( $this->id != $gateway_id || ! $payment_logos = $this->get_option( 'payment_logos' ) ) {
            return $icon_html;
        }

        $icon_html = '';

        foreach ( $payment_logos as $slug ) {

            $full_logo_path = plugin_dir_url( __DIR__ ) . "assets/images/payment-logos/$slug.svg";

            $description = $this->form_fields[ 'payment_logos' ][ 'options' ][ $slug ];

            $icon_html .= "<img src='$full_logo_path' alt='$description' title='$description' />";
        }

        return $icon_html;
    }

    /**
     * Check if the Subscriptions extension is activated.
     *
     * @return bool
     */
    private function subscriptions_activated() {

        if ( function_exists( 'wcs_order_contains_subscription' ) ) {
            return true;
        }

        return false;
    }

    /**
     * Get gateway API instance.
     *
     * @return HitPay_Gateway_API
     */
    private function get_gateway_api() {

        $live_mode = 'yes' == $this->get_option( 'live_mode' );

        return new HitPay_Gateway_API(
            $this->get_option( 'api_key' ),
            $this->get_option( 'api_salt' ),
            $live_mode
        );
    }

    /**
     * Getting the list of available order statuses.
     *
     * There's an option in the settings allowing us to choose which order status
     * will be set after a successful payment. Here we are forming the list of
     * available statuses.
     *
     * @return array
     */
    private function get_order_statuses() {

		$order_statuses = [ 'By default' ];

        $skipped_order_statuses = [
            'wc-cancelled',
            'wc-refunded',
            'wc-failed',
            'wc-on-hold',
            'wc-pending'
        ];

        foreach ( wc_get_order_statuses() as $slug => $text ) {
            if ( ! in_array( $slug, $skipped_order_statuses) ) {
                $order_statuses[ $slug ] = "⇨ $text";
            }
        }

        return $order_statuses;
    }
    
    /**
     * Process a cancelled subscription payment.
     *
     * @param WC_Subscription  $subscription  object representing the subscription that just had its status changed.
     */
    public function process_cancelled_subscription_payment( $subscription) {
        $this->log('process_cancelled_subscription_payment triggered');
        $recurring_billing_id = get_post_meta($subscription->get_id(), '_hitpay_recurring_billing_id', true );
        
        if ( ! $recurring_billing_id ) {
            return false;
        }
        
        $this->log('hitpay_recurring_billing_id: '.$recurring_billing_id);
        
        $request = new HitPay_Cancel_Subscription_Request( $this->get_gateway_api() );

        $response = $request
            ->set_payment_id( $recurring_billing_id )
            ->create();

        if ( ! $response ) {
             $this->log('Empty Response');
            return false;
        }
        
        $order = $subscription->order;

        if ($order && $order->get_id() > 0) {

            $message = "Subscription cancelled successfully. Canceled Subscription Reference ID: {$response->id}. "
                . "Status: {$response->status}.";
            $this->log($message);
            $order->add_order_note( $message );
        }

        return true;
    }
    
    public function option_exists($option_name) 
    {
        $value = get_option($option_name);
        return $value;
    }
    
    public function log($content)
    {
        $debug = 'yes';
        if ($debug == 'yes') {
            if (!$this->option_exists("woocommerce_hitpayrecurring_logfile_prefix")) {
                $logfile_prefix = md5(uniqid(wp_rand(), true));
                update_option('woocommerce_hitpayrecurring_logfile_prefix', $logfile_prefix);
            } else {
                $logfile_prefix = get_option('woocommerce_hitpayrecurring_logfile_prefix');
                if (empty($logfile_prefix)) {
                    $logfile_prefix = md5(uniqid(wp_rand(), true));
                    update_option('woocommerce_hitpayrecurring_logfile_prefix', $logfile_prefix);
                }
            }
			
            $filename = 'hitpayrecurring_debug_'.$logfile_prefix.'.log';

            $file = ABSPATH .'wp-content/uploads/wc-logs/'.$filename;
			
            try {
                // @codingStandardsIgnoreStart
                /*
                We tried to use WP_Filesystem methods.
                But WP_Filesystem put_contents method just writing the code not appending to the file.
                So we have only the last written content in the file.
                Because in the below method fopen initiated with 'wb' mode instead of 'a' or 'a+', otherwise this core method must be modified to able to pass the file open mode from the caller.
                public function put_contents( $file, $contents, $mode = false ) {
                $fp = @fopen( $file, 'wb' );
                */
                $fp = fopen($file, 'a+');
                if ($fp) {
                    fwrite($fp, "\n".gmdate("Y-m-d H:i:s").": ".print_r($content, true));
                    fclose($fp);
                }
		// @codingStandardsIgnoreEnd
				
            } catch (\Exception $e) {}
        }
    }
}
