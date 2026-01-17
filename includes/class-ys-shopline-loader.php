<?php
/**
 * Autoloader for YS Shopline Payment plugin.
 *
 * @package YS_Shopline_Payment
 */

defined( 'ABSPATH' ) || exit;

/**
 * YS_Shopline_Loader Class.
 */
class YS_Shopline_Loader {

    /**
     * Class map for autoloading.
     *
     * @var array
     */
    private static $class_map = array(
        // Core classes
        'YS_Shopline_Logger'          => 'includes/class-ys-shopline-logger.php',
        'YS_Shopline_API'             => 'includes/class-ys-shopline-api.php',
        'YS_Shopline_Webhook_Handler' => 'includes/class-ys-shopline-webhook-handler.php',
        'YS_Shopline_Customer'        => 'includes/class-ys-shopline-customer.php',
        'YS_Shopline_Order_Display'   => 'includes/class-ys-shopline-order-display.php',

        // Admin classes
        'YS_Shopline_Settings'        => 'includes/admin/class-ys-shopline-settings.php',

        // Gateway classes (moved to src/Gateways/)
        'YS_Shopline_Gateway_Base'       => 'src/Gateways/class-ys-shopline-gateway-base.php',
        'YS_Shopline_Credit_Card'        => 'src/Gateways/class-ys-shopline-credit-card.php',
        'YS_Shopline_Credit_Subscription'=> 'src/Gateways/class-ys-shopline-credit-subscription.php',
        'YS_Shopline_Virtual_Account'    => 'src/Gateways/class-ys-shopline-virtual-account.php',
        'YS_Shopline_JKOPay'             => 'src/Gateways/class-ys-shopline-jkopay.php',
        'YS_Shopline_ApplePay'           => 'src/Gateways/class-ys-shopline-applepay.php',
        'YS_Shopline_LinePay'            => 'src/Gateways/class-ys-shopline-linepay.php',
        'YS_Shopline_Chailease_BNPL'     => 'src/Gateways/class-ys-shopline-chailease-bnpl.php',
        'YS_Shopline_Subscription'       => 'src/Gateways/class-ys-shopline-subscription.php',
    );

    /**
     * Register autoloader.
     */
    public static function register() {
        spl_autoload_register( array( __CLASS__, 'autoload' ) );
    }

    /**
     * Autoload classes.
     *
     * @param string $class_name Class name to load.
     */
    public static function autoload( $class_name ) {
        if ( isset( self::$class_map[ $class_name ] ) ) {
            $file = YS_SHOPLINE_PLUGIN_DIR . self::$class_map[ $class_name ];
            if ( file_exists( $file ) ) {
                require_once $file;
            }
        }
    }
}

// Register autoloader
YS_Shopline_Loader::register();
