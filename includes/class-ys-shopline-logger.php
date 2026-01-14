<?php
/**
 * Logger class for YS Shopline Payment.
 *
 * @package YS_Shopline_Payment
 */

defined( 'ABSPATH' ) || exit;

/**
 * YS_Shopline_Logger Class.
 *
 * Provides logging functionality using WooCommerce's logger.
 */
class YS_Shopline_Logger {

    /**
     * WC Logger instance.
     *
     * @var WC_Logger|null
     */
    private static $logger = null;

    /**
     * Logger source context.
     *
     * @var string
     */
    const LOGGER_CONTEXT = 'ys-shopline-payment';

    /**
     * Check if debug logging is enabled.
     *
     * @return bool
     */
    private static function is_debug_enabled() {
        return 'yes' === get_option( 'ys_shopline_debug_log', 'no' );
    }

    /**
     * Get logger instance.
     *
     * @return WC_Logger|null
     */
    private static function get_logger() {
        if ( is_null( self::$logger ) && function_exists( 'wc_get_logger' ) ) {
            self::$logger = wc_get_logger();
        }

        return self::$logger;
    }

    /**
     * Log a message.
     *
     * @param string       $message Message to log.
     * @param string       $level   Log level (debug, info, notice, warning, error, critical, alert, emergency).
     * @param array|string $context Additional context data.
     */
    public static function log( $message, $level = 'info', $context = array() ) {
        $logger = self::get_logger();

        if ( ! $logger ) {
            return;
        }

        // Format context if it's an array
        if ( ! empty( $context ) ) {
            if ( is_array( $context ) ) {
                $message .= ' | Context: ' . wp_json_encode( $context );
            } else {
                $message .= ' | ' . $context;
            }
        }

        $logger->log( $level, $message, array( 'source' => self::LOGGER_CONTEXT ) );
    }

    /**
     * Log debug message.
     *
     * @param string       $message Message to log.
     * @param array|string $context Additional context data.
     */
    public static function debug( $message, $context = array() ) {
        // Only log debug messages if debug mode is enabled
        if ( self::is_debug_enabled() ) {
            self::log( $message, 'debug', $context );
        }
    }

    /**
     * Log info message.
     *
     * @param string       $message Message to log.
     * @param array|string $context Additional context data.
     */
    public static function info( $message, $context = array() ) {
        self::log( $message, 'info', $context );
    }

    /**
     * Log notice message.
     *
     * @param string       $message Message to log.
     * @param array|string $context Additional context data.
     */
    public static function notice( $message, $context = array() ) {
        self::log( $message, 'notice', $context );
    }

    /**
     * Log warning message.
     *
     * @param string       $message Message to log.
     * @param array|string $context Additional context data.
     */
    public static function warning( $message, $context = array() ) {
        self::log( $message, 'warning', $context );
    }

    /**
     * Log error message.
     *
     * @param string       $message Message to log.
     * @param array|string $context Additional context data.
     */
    public static function error( $message, $context = array() ) {
        self::log( $message, 'error', $context );
    }

    /**
     * Log critical message.
     *
     * @param string       $message Message to log.
     * @param array|string $context Additional context data.
     */
    public static function critical( $message, $context = array() ) {
        self::log( $message, 'critical', $context );
    }

    /**
     * Clear log file.
     *
     * @return bool
     */
    public static function clear() {
        if ( ! function_exists( 'wc_get_log_file_path' ) ) {
            return false;
        }

        $log_file = wc_get_log_file_path( self::LOGGER_CONTEXT );

        if ( file_exists( $log_file ) ) {
            return unlink( $log_file );
        }

        return true;
    }

    /**
     * Get log file path.
     *
     * @return string|false
     */
    public static function get_log_file_path() {
        if ( ! function_exists( 'wc_get_log_file_path' ) ) {
            return false;
        }

        return wc_get_log_file_path( self::LOGGER_CONTEXT );
    }
}
