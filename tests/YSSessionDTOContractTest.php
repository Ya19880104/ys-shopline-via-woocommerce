<?php
/**
 * 契約測試：YSSessionDTO::from_response 的交易 ID 推導。
 *
 * 鎖定 P1 狀態同步契約（CODEX 覆核五輪定案）：
 *   - 一律經共用 selector 從 paymentDetails 產生交易 ID。
 *   - root `tradeOrderId` **不得**優先繞過 selector 的安全判斷。
 *   - root 與 selector 不一致時 selector 勝；無 paymentDetails / malformed → null。
 *   - 缺 sessionId 拋例外。
 *
 * @package YangSheep\ShoplinePayment\Tests
 */

declare(strict_types=1);

use YangSheep\ShoplinePayment\DTOs\YSSessionDTO;

/**
 * 執行 YSSessionDTO 契約案。
 *
 * @return void
 */
function ys_run_sessiondto_contract(): void {
	$tid = static fn( array $resp ) => YSSessionDTO::from_response( $resp )->trade_order_id;
	$D   = static fn( string $id, string $st ): array => array( 'tradeOrderId' => $id, 'status' => $st );

	echo "== YSSessionDTO: 無 root（一律經 selector） ==\n";
	YS_Assert::eq( 'no-root single paid → paid', 'A', $tid( array( 'sessionId' => 's', 'paymentDetails' => array( $D( 'A', 'SUCCEEDED' ) ) ) ) );
	YS_Assert::eq( 'no-root [failed,succeeded] → succeeded', 'N', $tid( array( 'sessionId' => 's', 'paymentDetails' => array( $D( 'O', 'FAILED' ), $D( 'N', 'SUCCEEDED' ) ) ) ) );
	YS_Assert::eq( 'no-root two-paid → null', null, $tid( array( 'sessionId' => 's', 'paymentDetails' => array( $D( 'A', 'SUCCEEDED' ), $D( 'B', 'CAPTURED' ) ) ) ) );
	YS_Assert::eq( 'no-root single in-flight → tracked', 'P', $tid( array( 'sessionId' => 's', 'paymentDetails' => array( $D( 'P', 'PROCESSING' ) ) ) ) );

	echo "== YSSessionDTO: root tradeOrderId 不得繞過 selector ==\n";
	YS_Assert::eq( 'root + multi-active → null（root 被忽略）', null, $tid( array( 'sessionId' => 's', 'tradeOrderId' => 'ROOT', 'paymentDetails' => array( $D( 'A', 'SUCCEEDED' ), $D( 'B', 'PROCESSING' ) ) ) ) );
	YS_Assert::eq( 'root 與 selector 不一致 → selector 勝', 'ONLY', $tid( array( 'sessionId' => 's', 'tradeOrderId' => 'ROOT', 'paymentDetails' => array( $D( 'ONLY', 'SUCCEEDED' ) ) ) ) );
	YS_Assert::eq( 'root + malformed details → null', null, $tid( array( 'sessionId' => 's', 'tradeOrderId' => 'ROOT', 'paymentDetails' => array( $D( 'A', 'SUCCEEDED' ), array( 'status' => 'PROCESSING' ) ) ) ) );
	YS_Assert::eq( 'root + 無 paymentDetails → null', null, $tid( array( 'sessionId' => 's', 'tradeOrderId' => 'ROOT_ONLY' ) ) );
	YS_Assert::eq( '完全無 details → null', null, $tid( array( 'sessionId' => 's' ) ) );

	echo "== YSSessionDTO: 其他契約 ==\n";
	YS_Assert::throws( '缺 sessionId → 拋例外', static function (): void {
		YSSessionDTO::from_response( array( 'status' => 'SUCCEEDED' ) );
	} );

	$dto_ok = YSSessionDTO::from_response( array( 'sessionId' => 's', 'status' => 'SUCCEEDED' ) );
	YS_Assert::is_true( 'is_succeeded() on SUCCEEDED', $dto_ok->is_succeeded() );
	YS_Assert::eq( 'status accessor 反映 root status', 'SUCCEEDED', $dto_ok->status );

	$dto_exp = YSSessionDTO::from_response( array( 'sessionId' => 's', 'status' => 'EXPIRED' ) );
	YS_Assert::is_true( 'is_expired() on EXPIRED', $dto_exp->is_expired() );

	$dto_arr = YSSessionDTO::from_response( array( 'sessionId' => 's', 'tradeOrderId' => 'ROOT', 'paymentDetails' => array( $D( 'SEL', 'SUCCEEDED' ) ) ) );
	YS_Assert::eq( 'to_array tradeOrderId = selector 推導值（非 root）', 'SEL', $dto_arr->to_array()['tradeOrderId'] );
}
