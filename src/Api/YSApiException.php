<?php
/**
 * API 錯誤例外
 *
 * 攜帶 Shopline API 回傳的錯誤碼（字串），讓上層可精確映射 WP_Error code。
 *
 * @package YangSheep\ShoplinePayment\Api
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\Api;

defined( 'ABSPATH' ) || exit;

class YSApiException extends \Exception {

    /** @var string API 回傳的錯誤碼（如 '1999'、'api_error'） */
    private string $api_code;

    /**
     * @param string $api_code API 錯誤碼
     * @param string $message  錯誤訊息
     * @param int    $http_code HTTP 狀態碼
     */
    public function __construct( string $api_code, string $message, int $http_code = 400 ) {
        $this->api_code = $api_code;
        parent::__construct( $message, $http_code );
    }

    /**
     * 取得 API 錯誤碼
     */
    public function get_api_code(): string {
        return $this->api_code;
    }
}
