<?php
/**
 * API 部分成功例外
 *
 * 當 HTTP 400 但回應中包含 tradeOrderId 或 nextAction 時拋出，
 * 表示 3DS 驗證等需要後續處理的流程。
 *
 * @package YangSheep\ShoplinePayment\Api
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\Api;

defined( 'ABSPATH' ) || exit;

/**
 * YSApiPartialSuccessException
 *
 * 攜帶完整 response body，讓上層可取得 tradeOrderId / nextAction 繼續處理。
 */
class YSApiPartialSuccessException extends \Exception {

    /** @var array<string, mixed> */
    private array $response_body;

    /**
     * @param array<string, mixed> $response_body API 回應 body
     * @param string               $message       錯誤訊息
     * @param int                  $code          HTTP 狀態碼
     */
    public function __construct( array $response_body, string $message = '', int $code = 400 ) {
        $this->response_body = $response_body;
        parent::__construct( $message, $code );
    }

    /**
     * 取得完整 API 回應 body
     *
     * @return array<string, mixed>
     */
    public function get_response_body(): array {
        return $this->response_body;
    }
}
