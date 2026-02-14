<?php
/**
 * PSR-4 Autoloader for YangSheep\ShoplinePayment
 *
 * 類別名稱 = 檔案名稱，零轉換。
 * YangSheep\ShoplinePayment\Gateways\YSCreditCard → src/Gateways/YSCreditCard.php
 *
 * @package YangSheep\ShoplinePayment
 */

spl_autoload_register( function ( $class ) {
    $prefix = 'YangSheep\\ShoplinePayment\\';
    $len    = strlen( $prefix );

    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }

    $file = dirname( __DIR__ ) . '/src/' . str_replace( '\\', '/', substr( $class, $len ) ) . '.php';

    if ( file_exists( $file ) ) {
        require $file;
    }
} );
