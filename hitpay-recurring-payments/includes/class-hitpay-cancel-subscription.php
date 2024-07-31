<?php

/**
 * Cancel Subscription Request class.
 */
class HitPay_Cancel_Subscription_Request {

    /**
     * var @HitPay_Gateway_API
     */
    public $gateway_api;

    /**
     * Payment ID for the successful payment request.
     *
     * @var string
     */
    public $payment_id;

    /**
     * Last response from the API.
     *
     * @var array|WP_Error
     */
    public $last_response;

    /**
     * @param HitPay_Gateway_API $gateway_api
     */
    public function __construct( HitPay_Gateway_API $gateway_api ) {

        $this->gateway_api = $gateway_api;
    }

    public function create() {

        $endpoint = $this->gateway_api->get_endpoint_prefix() . 'recurring-billing/' . $this->payment_id;

        $this->last_response = wp_remote_request( $endpoint, array_merge( $this->gateway_api->get_options(), array('method' => 'DELETE') ) );

        return $this->fetch_response_data();
    }

    /**
     * @param string $payment_id
     */
    public function set_payment_id( $payment_id )
    {
        $this->payment_id = $payment_id;

        return $this;
    }

    /**
     * Fetch the response data.
     *
     * @return false|mixed
     */
    private function fetch_response_data() {

        if ( HitPay_Gateway_API::RESPONSE_CODE_OK
            != wp_remote_retrieve_response_code( $this->last_response ) ) {
            return false;
        }

        if ( $json_body = wp_remote_retrieve_body( $this->last_response ) ) {
            if ( $body = json_decode( $json_body ) ) {
                return $body;
            }
        }

        return false;
    }
}
