<?php
/**
 * Simple PSR-4 Autoloader for YangSheep\ShoplinePayment
 *
 * This is a fallback autoloader when Composer is not available.
 * When Composer is available, run: composer dump-autoload
 *
 * @package YangSheep\ShoplinePayment
 */

spl_autoload_register( function ( $class ) {
    // Only handle our namespace
    $prefix = 'YangSheep\\ShoplinePayment\\';

    // Does the class use the namespace prefix?
    $len = strlen( $prefix );
    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }

    // Get the relative class name
    $relative_class = substr( $class, $len );

    // Replace namespace separator with directory separator
    $file = dirname( __DIR__ ) . '/includes/' . str_replace( '\\', '/', $relative_class ) . '.php';

    // If the file exists, require it
    if ( file_exists( $file ) ) {
        require $file;
    }
} );
