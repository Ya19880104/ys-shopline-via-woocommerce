<?php
// Composer autoloader shim  更 hub-client  autoloader
$hubClientDir = __DIR__ . '/yangsheep/ys-plugin-hub-client/';

// 更 hub-client ウΤ autoloader
if ( file_exists( $hubClientDir . 'ys-plugin-hub-client.php' ) ) {
    require_once $hubClientDir . 'ys-plugin-hub-client.php';
}

// 爹本セō PSR-4 autoloader
spl_autoload_register( function ( $class ) {
    // 矪パ本 autoloader 矪瞶
} );
