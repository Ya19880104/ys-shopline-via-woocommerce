<?php
/**
 * Customer DTO
 *
 * @package YangSheep\ShoplinePayment\DTOs
 */

declare(strict_types=1);

namespace YangSheep\ShoplinePayment\DTOs;

defined( 'ABSPATH' ) || exit;

/**
 * 會員資料傳輸物件
 */
final class YSCustomerDTO {

    /**
     * Constructor
     *
     * @param string      $customer_id           Shopline 會員 ID
     * @param string      $reference_customer_id 商戶端會員 ID (WordPress User ID)
     * @param string|null $email                 電子郵件
     * @param string|null $name                  姓名
     * @param string|null $phone                 電話
     * @param array       $raw_data              原始回應資料
     */
    public function __construct(
        public readonly string $customer_id,
        public readonly string $reference_customer_id,
        public readonly ?string $email = null,
        public readonly ?string $name = null,
        public readonly ?string $phone = null,
        public readonly array $raw_data = []
    ) {}

    /**
     * 從 API 回應建立
     *
     * @param array<string, mixed> $response API 回應資料
     * @return self
     * @throws \Exception 如果回應資料不完整
     */
    public static function from_response( array $response ): self {
        if ( empty( $response['customerId'] ) && empty( $response['paymentCustomerId'] ) ) {
            throw new \Exception( '回應缺少 customerId' );
        }

        return new self(
            customer_id: $response['customerId'] ?? $response['paymentCustomerId'] ?? '',
            reference_customer_id: $response['referenceCustomerId'] ?? '',
            email: $response['email'] ?? null,
            name: $response['name'] ?? null,
            phone: $response['phone'] ?? null,
            raw_data: $response
        );
    }

    /**
     * 從 WordPress 用戶建立請求資料
     *
     * @param int $user_id WordPress 用戶 ID
     * @return array<string, mixed>
     */
    public static function create_request_from_user( int $user_id ): array {
        $user = get_userdata( $user_id );

        if ( ! $user ) {
            return [];
        }

        return [
            'referenceCustomerId' => (string) $user_id,
            'email'               => $user->user_email,
            'name'                => $user->display_name ?: $user->user_login,
            'phone'               => get_user_meta( $user_id, 'billing_phone', true ) ?: '',
        ];
    }

    /**
     * 轉換為陣列
     *
     * @return array<string, mixed>
     */
    public function to_array(): array {
        return [
            'customerId'          => $this->customer_id,
            'referenceCustomerId' => $this->reference_customer_id,
            'email'               => $this->email,
            'name'                => $this->name,
            'phone'               => $this->phone,
        ];
    }
}
