<?php

/**
 * HitPay_Payment_Gateway_Blocks Class
 * @author: <a href="https://www.hitpayapp.com>HitPay Payment Solutions Pte Ltd</a>   
 * @package: HitPay Payment Gateway
 * @since: 1.1.4
*/

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Automattic\WooCommerce\Blocks\Payments\PaymentResult;
use Automattic\WooCommerce\Blocks\Payments\PaymentContext;

final class HitPay_Payment_Gateway_Blocks extends AbstractPaymentMethodType {
    /**
     * The gateway instance.
     *
     * @var WC_Gateway_Dummy
     */
    private $gateway;

    /**
     * Payment method name/id/slug.
     *
     * @var string
     */
    protected $name = 'hit_pay';
    
        /**
     * Initializes the payment method type.
     */
    public function initialize() {
        $this->settings = get_option( 'woocommerce_hit_pay_settings', [] );
        
        $payment_gateways_class   = WC()->payment_gateways();
        $payment_gateways         = $payment_gateways_class->payment_gateways();

        $this->gateway  = $payment_gateways['hit_pay'];
    }

    /**
     * Returns if this payment method should be active. If false, the scripts will not be enqueued.
     *
     * @return boolean
     */
    public function is_active() {
        return $this->gateway->is_available();
    }
    
        /**
     * Returns an array of scripts/handles to be registered for this payment method.
     *
     * @return array
     */
    public function get_payment_method_script_handles() {
        $asset_path   = HIT_PAY_PLUGIN_PATH . 'build/frontend/blocks.asset.php';
        $version      = HIT_PAY_VERSION;
        $dependencies = [];
        if ( file_exists( $asset_path ) ) {
            $asset        = require $asset_path;
            $version      = is_array( $asset ) && isset( $asset['version'] )
                    ? $asset['version']
                    : $version;
            $dependencies = is_array( $asset ) && isset( $asset['dependencies'] )
                    ? $asset['dependencies']
                    : $dependencies;
        }
        wp_register_script(
                'wc-hit-pay-blocks-integration',
                HIT_PAY_PLUGIN_URL . 'build/frontend/blocks.js',
                $dependencies,
                $version,
                true
        );

        return [ 'wc-hit-pay-blocks-integration' ];
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data() {
        return [
            'title'       => $this->get_setting( 'title' ),
            'description' => $this->get_setting( 'description' ),
            'supports'    => array_filter( $this->gateway->supports, [ $this->gateway, 'supports' ] ),
            'logo_url'    => plugin_dir_url( __DIR__ ) . 'assets/images/logo.png',
        ];
    }
}
