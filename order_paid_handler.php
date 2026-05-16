<?php

declare(strict_types=1);



/**

 * Bettavaro - Order Paid Handler

 *

 * Goals:

 * - Keep old paid-order flow working

 * - Add safe auction-paid flow without breaking fixed-price flow

 * - Queue notifications AFTER COMMIT

 * - Be idempotent

 * - Be discount-aware without recalculating paid totals after payment

 * - Capture refund fee snapshot at paid stage when refund fee engine exists

 * - Force log path to /logs/order_paid_handler.log

 * - Add hard marker at function entry

 */



if (is_file(__DIR__ . '/mailer.php')) {

    require_once __DIR__ . '/mailer.php';

}



if (is_file(__DIR__ . '/auction_engine.php')) {

    require_once __DIR__ . '/auction_engine.php';

}



if (is_file(__DIR__ . '/refund_fee_engine.php')) {

    require_once __DIR__ . '/refund_fee_engine.php';

}



if (is_file(__DIR__ . '/notification_engine.php')) {

    require_once __DIR__ . '/notification_engine.php';

}



// --- Seller Balance System (Phase 1) ---
if (is_file(__DIR__ . '/seller_balance.php')) {

    require_once __DIR__ . '/seller_balance.php';

}



function bvoph_h($v): string

{

    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

}



function bvoph_now(): string

{

    return date('Y-m-d H:i:s');

}



function bvoph_log(string $event, array $data = []): void

{

    $file = dirname(__DIR__) . '/logs/order_paid_handler.log';

    $dir  = dirname($file);



    $line = '[' . date('Y-m-d H:i:s') . '] ' . $event . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;



    if (!is_dir($dir)) {

        @mkdir($dir, 0775, true);

    }



    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

    @error_log(trim($line));

}



function bvoph_table_exists(PDO $pdo, string $table): bool

{

    static $cache = [];

    if (isset($cache[$table])) {

        return $cache[$table];

    }



    try {

        $table = str_replace('`', '', $table);

        $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));

        return $cache[$table] = (bool)$stmt->fetchColumn();

    } catch (Throwable $e) {

        return false;

    }

}



function bvoph_columns(PDO $pdo, string $table): array

{

    static $cache = [];

    if (isset($cache[$table])) {

        return $cache[$table];

    }



    $cols = [];

    try {

        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

            if (!empty($row['Field'])) {

                $cols[$row['Field']] = true;

            }

        }

    } catch (Throwable $e) {

        // ignore

    }



    return $cache[$table] = $cols;

}



function bvoph_has_col(PDO $pdo, string $table, string $column): bool

{

    $cols = bvoph_columns($pdo, $table);

    return isset($cols[$column]);

}



function bvoph_num($value): float

{

    if ($value === null || $value === '') {

        return 0.0;

    }

    return (float)$value;

}



function bvoph_money(float $amount, string $currency = 'USD'): string

{

    $currency = strtoupper(trim($currency));

    if ($currency === '') {

        $currency = 'USD';

    }

    return $currency . ' ' . number_format($amount, 2);

}



function bvoph_refund_fee_engine_available(): bool

{

    return function_exists('bv_refund_fee_policy_detect_from_order')

        && function_exists('bv_refund_fee_policy_get')

        && function_exists('bv_refund_fee_build_order_paid_snapshot')

        && function_exists('bv_refund_fee_snapshot_save_to_order');

}



function bvoph_refund_fee_invoke_save(int $orderId, array $snapshot, PDO $pdo): bool

{

    if (!function_exists('bv_refund_fee_snapshot_save_to_order')) {

        return false;

    }



    try {

        bv_refund_fee_snapshot_save_to_order($orderId, $snapshot, $pdo);

        return true;

    } catch (ArgumentCountError | TypeError $e) {

        try {

            bv_refund_fee_snapshot_save_to_order($orderId, $snapshot);

            return true;

        } catch (Throwable $inner) {

            bvoph_log('refund_fee_snapshot_save_failed', [

                'order_id' => $orderId,

                'error' => $inner->getMessage(),

            ]);

            return false;

        }

    } catch (Throwable $e) {

        bvoph_log('refund_fee_snapshot_save_failed', [

            'order_id' => $orderId,

            'error' => $e->getMessage(),

        ]);

        return false;

    }

}



function bvoph_capture_refund_fee_snapshot(PDO $pdo, array $order): array

{

    $empty = [

        'captured' => false,

        'snapshot' => [],

        'policy_code' => '',

        'error' => null,

    ];



    if (!bvoph_refund_fee_engine_available()) {

        return $empty;

    }



    $orderId = (int)($order['id'] ?? 0);

    if ($orderId <= 0) {

        return $empty;

    }



    try {

        bvoph_log('refund_fee_snapshot_capture_started', [

            'order_id' => $orderId,

        ]);



        $policyCode = (string)bv_refund_fee_policy_detect_from_order($order);

        if ($policyCode === '') {

            $policyCode = 'MARKETPLACE_STD';

        }



        $channel = trim((string)($order['order_source'] ?? $order['source'] ?? 'shop'));

        if ($channel === '') {

            $channel = 'shop';

        }



        $paymentProvider = trim((string)($order['payment_provider'] ?? ''));

        if ($paymentProvider === '') {

            $paymentMethod = strtolower(trim((string)($order['payment_method'] ?? '')));

            if ($paymentMethod === 'stripe_checkout' || strpos($paymentMethod, 'stripe') !== false) {

                $paymentProvider = 'stripe';

            }

        }



        $policyRows = bv_refund_fee_policy_get($policyCode, $channel, $paymentProvider, 'both');

        $snapshot = bv_refund_fee_build_order_paid_snapshot($order, is_array($policyRows) ? $policyRows : []);



        if (!is_array($snapshot) || $snapshot === []) {

            bvoph_log('refund_fee_snapshot_capture_failed', [

                'order_id' => $orderId,

                'policy_code' => $policyCode,

                'error' => 'empty_snapshot',

            ]);



            return [

                'captured' => false,

                'snapshot' => [],

                'policy_code' => $policyCode,

                'error' => 'empty_snapshot',

            ];

        }



        if (!array_key_exists('fee_policy_code', $snapshot) || trim((string)$snapshot['fee_policy_code']) === '') {

            $snapshot['fee_policy_code'] = $policyCode;

        }



        if (!array_key_exists('fee_policy_snapshot', $snapshot) || trim((string)$snapshot['fee_policy_snapshot']) === '') {

            $snapshot['fee_policy_snapshot'] = json_encode([

                'policy_code' => $policyCode,

                'channel' => $channel,

                'payment_provider' => $paymentProvider,

                'captured_by' => 'order_paid_handler',

                'captured_at' => bvoph_now(),

            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        }



        $grossPaid = isset($snapshot['gross_paid_amount']) ? (float)$snapshot['gross_paid_amount'] : bvoph_num($order['total'] ?? 0);

        $platformNonRefundable = isset($snapshot['platform_fee_non_refundable']) ? (float)$snapshot['platform_fee_non_refundable'] : 0.0;

        $gatewayNonRefundable = isset($snapshot['payment_gateway_fee_non_refundable']) ? (float)$snapshot['payment_gateway_fee_non_refundable'] : 0.0;



        if (!isset($snapshot['seller_net_amount_snapshot']) || !is_numeric($snapshot['seller_net_amount_snapshot'])) {

            $snapshot['seller_net_amount_snapshot'] = round(max(0, $grossPaid - $platformNonRefundable - $gatewayNonRefundable), 2);

        }



        $saved = bvoph_refund_fee_invoke_save($orderId, $snapshot, $pdo);



        if ($saved) {

            bvoph_log('refund_fee_snapshot_captured', [

                'order_id' => $orderId,

                'policy_code' => $policyCode,

            ]);

        } else {

            bvoph_log('refund_fee_snapshot_capture_failed', [

                'order_id' => $orderId,

                'policy_code' => $policyCode,

                'error' => 'save_failed',

            ]);

        }



        return [

            'captured' => $saved,

            'snapshot' => $snapshot,

            'policy_code' => $policyCode,

            'error' => $saved ? null : 'save_failed',

        ];

    } catch (Throwable $e) {

        bvoph_log('refund_fee_snapshot_capture_failed', [

            'order_id' => $orderId,

            'error' => $e->getMessage(),

        ]);



        return [

            'captured' => false,

            'snapshot' => [],

            'policy_code' => '',

            'error' => $e->getMessage(),

        ];

    }

}



function bvoph_final_paid_order_status(PDO $pdo): string

{

    static $status = null;



    if ($status !== null) {

        return $status;

    }



    $status = 'confirmed';

    return $status;

}



function bvoph_fetch_order(PDO $pdo, int $orderId): ?array

{

    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");

    $stmt->execute([$orderId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;

}



function bvoph_fetch_order_items(PDO $pdo, int $orderId): array

{

    if (!bvoph_table_exists($pdo, 'order_items')) {

        return [];

    }



    $stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC");

    $stmt->execute([$orderId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

}



function bvoph_fetch_listing_for_update(PDO $pdo, int $listingId): ?array

{

    $stmt = $pdo->prepare("SELECT * FROM listings WHERE id = ? LIMIT 1 FOR UPDATE");

    $stmt->execute([$listingId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;

}



function bvoph_update_order(PDO $pdo, int $orderId, array $patch): void

{

    if (!$patch) {

        return;

    }



    $cols = bvoph_columns($pdo, 'orders');

    $sets = [];

    $params = [];



    foreach ($patch as $k => $v) {

        if (isset($cols[$k])) {

            $sets[] = "`{$k}` = ?";

            $params[] = $v;

        }

    }



    if (!$sets) {

        return;

    }



    if (isset($cols['updated_at']) && !array_key_exists('updated_at', $patch)) {

        $sets[] = "`updated_at` = ?";

        $params[] = bvoph_now();

    }



    $params[] = $orderId;

    $stmt = $pdo->prepare("UPDATE orders SET " . implode(', ', $sets) . " WHERE id = ?");

    $stmt->execute($params);

}



function bvoph_extract_discount_snapshot(array $order): array

{

    $currency = strtoupper(trim((string)($order['currency'] ?? 'USD')));

    if ($currency === '') {

        $currency = 'USD';

    }



    $subtotalBeforeDiscount = bvoph_num($order['subtotal_before_discount'] ?? 0);

    $discountAmount         = bvoph_num($order['discount_amount'] ?? 0);

    $sellerDiscountTotal    = bvoph_num($order['seller_discount_total'] ?? 0);

    $subtotal               = bvoph_num($order['subtotal'] ?? 0);

    $shippingAmount         = bvoph_num($order['shipping_amount'] ?? 0);

    $total                  = bvoph_num($order['total'] ?? 0);



    if ($subtotalBeforeDiscount <= 0 && $subtotal > 0 && $discountAmount > 0) {

        $subtotalBeforeDiscount = $subtotal + $discountAmount;

    }



    if ($discountAmount <= 0 && $subtotalBeforeDiscount > 0 && $subtotal > 0 && $subtotalBeforeDiscount >= $subtotal) {

        $discountAmount = $subtotalBeforeDiscount - $subtotal;

    }



    if ($sellerDiscountTotal <= 0 && $discountAmount > 0) {

        $sellerDiscountTotal = $discountAmount;

    }



    $subtotalAfterDiscount = $subtotal;

    if ($subtotalAfterDiscount <= 0 && $subtotalBeforeDiscount > 0) {

        $subtotalAfterDiscount = max(0, $subtotalBeforeDiscount - $discountAmount);

    }



    $computedTotal = $subtotalAfterDiscount + $shippingAmount;

    if ($total <= 0 && $computedTotal > 0) {

        $total = $computedTotal;

    }



    $commissionBase = $subtotalAfterDiscount;

    if ($commissionBase <= 0 && $subtotalBeforeDiscount > 0) {

        $commissionBase = max(0, $subtotalBeforeDiscount - $sellerDiscountTotal);

    }

    if ($commissionBase <= 0 && $total > 0) {

        $commissionBase = max(0, $total - $shippingAmount);

    }



    return [

        'currency'                 => $currency,

        'subtotal_before_discount' => round($subtotalBeforeDiscount, 2),

        'discount_amount'          => round($discountAmount, 2),

        'seller_discount_total'    => round($sellerDiscountTotal, 2),

        'subtotal_after_discount'  => round($subtotalAfterDiscount, 2),

        'shipping_amount'          => round($shippingAmount, 2),

        'total'                    => round($total, 2),

        'commission_base'          => round(max(0, $commissionBase), 2),

        'has_discount'             => ($discountAmount > 0.00001 || $sellerDiscountTotal > 0.00001),

    ];

}



function bvoph_discount_summary_text(array $discount): string

{

    $currency = (string)($discount['currency'] ?? 'USD');

    $parts = [

        'Before discount: ' . bvoph_money((float)($discount['subtotal_before_discount'] ?? 0), $currency),

        'Discount: ' . bvoph_money((float)($discount['discount_amount'] ?? 0), $currency),

        'Seller discount total: ' . bvoph_money((float)($discount['seller_discount_total'] ?? 0), $currency),

        'Subtotal after discount: ' . bvoph_money((float)($discount['subtotal_after_discount'] ?? 0), $currency),

        'Shipping: ' . bvoph_money((float)($discount['shipping_amount'] ?? 0), $currency),

        'Total paid: ' . bvoph_money((float)($discount['total'] ?? 0), $currency),

        'Commission base: ' . bvoph_money((float)($discount['commission_base'] ?? 0), $currency),

    ];



    return implode(' | ', $parts);

}



function bvoph_build_stock_log_payload(

    int $orderId,

    int $itemId,

    int $listingId,

    array $listing,

    array $res,

    int $qty,

    string $source,

    string $finalOrderStatus

): array {

    return [

        'event_key'              => 'order_paid:' . $orderId . ':item:' . $itemId . ':listing:' . $listingId,

        'source'                 => $source,

        'order_id'               => $orderId,

        'listing_id'             => $listingId,

        'order_status'           => $finalOrderStatus,

        'payment_status'         => 'paid',

        'qty_paid'               => $qty,

        'qty_reserved'           => 0,

        'stock_total'            => (int)($res['after_stock_total'] ?? 0),

        'stock_sold_before'      => (int)($res['before_stock_sold'] ?? 0),

        'stock_sold_after'       => (int)($res['after_stock_sold'] ?? 0),

        'stock_available_before' => (int)($res['before_stock_available'] ?? 0),

        'stock_available_after'  => (int)($res['after_stock_available'] ?? 0),

        'sale_status_before'     => (string)($res['before_sale_status'] ?? ''),

        'sale_status_after'      => (string)($res['after_sale_status'] ?? ''),

        'status_before'          => (string)($listing['status'] ?? ''),

        'status_after'           => (string)($res['after_status'] ?? ''),

        'details_json'           => json_encode([

            'event'         => 'cut_stock_after_paid',

            'order_item_id' => $itemId,

            'qty'           => $qty,

            'message'       => 'Stock synced after paid handler',

            'handler'       => 'bv_handle_paid_order',

        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),

    ];

}



function bvoph_insert_stock_log(PDO $pdo, array $data): void

{

    if (!bvoph_table_exists($pdo, 'order_stock_sync_logs')) {

        return;

    }



    $cols = bvoph_columns($pdo, 'order_stock_sync_logs');



    if (isset($cols['event_key']) && empty($data['event_key'])) {

        $data['event_key'] = 'stocklog:' . md5(json_encode([

            $data['order_id'] ?? null,

            $data['order_item_id'] ?? null,

            $data['listing_id'] ?? null,

            $data['action'] ?? null,

            $data['qty'] ?? null,

            date('Y-m-d H:i:s'),

        ]));

    }



    $insertCols = [];

    $insertVals = [];

    $params = [];



    foreach ($data as $k => $v) {

        if (isset($cols[$k])) {

            $insertCols[] = "`{$k}`";

            $insertVals[] = "?";

            $params[] = $v;

        }

    }



    if (isset($cols['created_at'])) {

        $insertCols[] = "`created_at`";

        $insertVals[] = "?";

        $params[] = bvoph_now();

    }



    if (!$insertCols) {

        return;

    }



    $stmt = $pdo->prepare(

        "INSERT INTO order_stock_sync_logs (" . implode(', ', $insertCols) . ")

         VALUES (" . implode(', ', $insertVals) . ")"

    );

    $stmt->execute($params);

}



function bvoph_insert_audit_log(PDO $pdo, array $data): void

{

    if (!bvoph_table_exists($pdo, 'audit_logs')) {

        return;

    }



    $cols = bvoph_columns($pdo, 'audit_logs');

    $payload = [

        'actor_type'   => $data['actor_type'] ?? 'system',

        'actor_id'     => $data['actor_id'] ?? null,

        'actor_name'   => $data['actor_name'] ?? null,

        'actor_email'  => $data['actor_email'] ?? null,

        'event_type'   => $data['event_type'] ?? null,

        'entity_type'  => $data['entity_type'] ?? 'order',

        'entity_id'    => $data['entity_id'] ?? null,

        'entity_title' => $data['entity_title'] ?? null,

        'action'       => $data['action'] ?? null,

        'summary'      => $data['summary'] ?? null,

        'before_data'  => $data['before_data'] ?? null,

        'after_data'   => $data['after_data'] ?? null,

        'meta_data'    => $data['meta_data'] ?? null,

        'ip_address'   => $data['ip_address'] ?? null,

        'user_agent'   => $data['user_agent'] ?? null,

        'created_at'   => $data['created_at'] ?? bvoph_now(),

    ];



    $insertCols = [];

    $insertVals = [];

    $params = [];



    foreach ($payload as $k => $v) {

        if (isset($cols[$k])) {

            $insertCols[] = "`{$k}`";

            $insertVals[] = "?";

            $params[] = $v;

        }

    }



    if (!$insertCols) {

        return;

    }



    $stmt = $pdo->prepare(

        "INSERT INTO audit_logs (" . implode(', ', $insertCols) . ")

         VALUES (" . implode(', ', $insertVals) . ")"

    );

    $stmt->execute($params);

}



function bvoph_sync_listing_stock_after_paid(PDO $pdo, array $listing, int $qty): array

{

    $listingId = (int)$listing['id'];

    $beforeStockTotal     = (int)($listing['stock_total'] ?? 0);

    $beforeStockSold      = (int)($listing['stock_sold'] ?? 0);

    $beforeStockAvailable = (int)($listing['stock_available'] ?? max(0, $beforeStockTotal - $beforeStockSold));

    $beforeSaleStatus     = strtolower(trim((string)($listing['sale_status'] ?? 'available')));



    $afterStockSold      = max(0, $beforeStockSold + $qty);

    $afterStockAvailable = max(0, $beforeStockAvailable - $qty);



    if ($beforeStockTotal > 0) {

        $afterStockAvailable = max(0, $beforeStockTotal - $afterStockSold);

    }



    $afterSaleStatus = $afterStockAvailable > 0 ? 'available' : 'sold';

    $afterStatus     = $afterStockAvailable > 0 ? ((string)($listing['status'] ?? 'active') ?: 'active') : 'sold';



    $patch = [

        'stock_sold'      => $afterStockSold,

        'stock_available' => $afterStockAvailable,

        'sale_status'     => $afterSaleStatus,

        'sold_at'         => $afterSaleStatus === 'sold' ? bvoph_now() : null,

    ];



    if (bvoph_has_col($pdo, 'listings', 'status')) {

        $patch['status'] = $afterStatus;

    }



    $sets = [];

    $params = [];

    foreach ($patch as $k => $v) {

        if (bvoph_has_col($pdo, 'listings', $k)) {

            $sets[] = "`{$k}` = ?";

            $params[] = $v;

        }

    }



    if ($sets) {

        $params[] = $listingId;

        $stmt = $pdo->prepare("UPDATE listings SET " . implode(', ', $sets) . " WHERE id = ?");

        $stmt->execute($params);

    }



    return [

        'listing_id'              => $listingId,

        'before_stock_total'      => $beforeStockTotal,

        'before_stock_sold'       => $beforeStockSold,

        'before_stock_available'  => $beforeStockAvailable,

        'before_sale_status'      => $beforeSaleStatus,

        'after_stock_total'       => $beforeStockTotal,

        'after_stock_sold'        => $afterStockSold,

        'after_stock_available'   => $afterStockAvailable,

        'after_sale_status'       => $afterSaleStatus,

        'after_status'            => $afterStatus,

    ];

}



function bvoph_resolve_order_source(array $order): string

{

    $candidates = [

        $order['order_source'] ?? null,

        $order['source'] ?? null,

        $order['type'] ?? null,

    ];



    foreach ($candidates as $candidate) {

        $source = strtolower(trim((string)$candidate));

        if ($source !== '') {

            return $source;

        }

    }



    return 'shop';

}



function bvoph_detect_auction_paid_context(PDO $pdo, array $order, array $items): array

{

    $source = bvoph_resolve_order_source($order);



    $orderHasAuctionSource = in_array($source, ['auction', 'auction_win', 'auction_award'], true);

    $auctionItemCount = 0;

    $auctionAwardIds = [];



    foreach ($items as $item) {

        $itemSource = strtolower(trim((string)($item['source_type'] ?? $item['order_source'] ?? '')));

        if (in_array($itemSource, ['auction', 'auction_win', 'auction_award'], true)) {

            $auctionItemCount++;

        }



        $awardId = (int)($item['auction_award_id'] ?? 0);

        if ($awardId > 0) {

            $auctionAwardIds[$awardId] = true;

        }

    }



    if (!$orderHasAuctionSource && !$auctionItemCount && !$auctionAwardIds) {

        return [

            'is_auction_paid' => false,

            'source'          => $source,

            'auction_awards'  => [],

        ];

    }



    $awards = [];

    if (bvoph_table_exists($pdo, 'auction_awards')) {

        if ($auctionAwardIds) {

            $placeholders = implode(',', array_fill(0, count($auctionAwardIds), '?'));

            $stmt = $pdo->prepare("SELECT * FROM auction_awards WHERE id IN ($placeholders)");

            $stmt->execute(array_keys($auctionAwardIds));

            $awards = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        } elseif ((int)($order['auction_award_id'] ?? 0) > 0) {

            $stmt = $pdo->prepare("SELECT * FROM auction_awards WHERE id = ? LIMIT 1");

            $stmt->execute([(int)$order['auction_award_id']]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {

                $awards = [$row];

            }

        }

    }



    return [

        'is_auction_paid' => true,

        'source'          => $source,

        'auction_awards'  => $awards,

    ];

}



function bvoph_mark_auction_awards_paid(PDO $pdo, array $awards, array $order): int

{

    if (!$awards || !bvoph_table_exists($pdo, 'auction_awards')) {

        return 0;

    }



    $cols = bvoph_columns($pdo, 'auction_awards');

    $count = 0;



    foreach ($awards as $award) {

        $awardId = (int)($award['id'] ?? 0);

        if ($awardId <= 0) {

            continue;

        }



        $sets = [];

        $params = [];



        if (isset($cols['award_status'])) {

            $sets[] = "`award_status` = ?";

            $params[] = 'paid';

        }

        if (isset($cols['paid_at'])) {

            $sets[] = "`paid_at` = ?";

            $params[] = bvoph_now();

        }

        if (isset($cols['updated_at'])) {

            $sets[] = "`updated_at` = ?";

            $params[] = bvoph_now();

        }

        if (isset($cols['order_id']) && !empty($order['id'])) {

            $sets[] = "`order_id` = ?";

            $params[] = (int)$order['id'];

        }

        if (isset($cols['order_code']) && !empty($order['order_code'])) {

            $sets[] = "`order_code` = ?";

            $params[] = (string)$order['order_code'];

        }



        if (!$sets) {

            continue;

        }



        $params[] = $awardId;

        $stmt = $pdo->prepare("UPDATE auction_awards SET " . implode(', ', $sets) . " WHERE id = ?");

        $stmt->execute($params);

        $count += (int)$stmt->rowCount();

    }



    return $count;

}

function bvoph_queue_mail_jobs(PDO $pdo, array $jobs): int
{
    if (!$jobs) {
        return 0;
    }
    if (!function_exists('bv_queue_mail')) {
        bvoph_log('mail_queue_function_missing', [
            'function' => 'bv_queue_mail',
            'jobs' => count($jobs),
        ]);
        return 0;
    }

    $count = 0;
   foreach ($jobs as $job) {
        try {
            $to      = (string)($job['to_email'] ?? '');
            $subject = (string)($job['subject'] ?? '');
            $html    = (string)($job['html_body'] ?? '');
            $text    = (string)($job['text_body'] ?? '');
            $metaRaw = (string)($job['meta_json'] ?? '');

            if ($to === '' || $subject === '' || ($html === '' && $text === '')) {
                continue;
            }

            $meta = [];
            if ($metaRaw !== '') {
                $decoded = json_decode($metaRaw, true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }

            $payload = [
                'to'      => [$to],
                'subject' => $subject,
                'html'    => $html,
                'text'    => $text,
                'meta'    => $meta,
            ];

            $result = bv_queue_mail($payload);
            $ok = false;
            if (is_array($result)) {
                $status = strtolower((string)($result['status'] ?? ''));
                $ok = ($result['queued'] ?? false) === true
                    || ($result['ok'] ?? false) === true
                    || ($result['success'] ?? false) === true
                    || in_array($status, ['queued', 'success'], true);
            }

            if ($ok) {
                $count++;
            } else {
                bvoph_log('mail_queue_add_failed', [
                    'job' => $job['template'] ?? ($job['subject'] ?? 'unknown'),
                    'to'  => $to,  
                ]);
            }
        } catch (Throwable $e) {
            bvoph_log('mail_queue_add_failed', [
                'error' => $e->getMessage(),
                'job'   => $job['template'] ?? ($job['subject'] ?? 'unknown'),
            ]);
        }
    }

    return $count;
}
function bvoph_resolve_admin_email(array $order): string
{
    $candidates = [
        $order['admin_email'] ?? null,
        $order['site_admin_email'] ?? null,
        defined('ADMIN_EMAIL') ? ADMIN_EMAIL : null,
        defined('SITE_ADMIN_EMAIL') ? SITE_ADMIN_EMAIL : null,
        getenv('ADMIN_EMAIL') ?: null,
    ];
    foreach ($candidates as $candidate) {
        $email = trim((string)$candidate);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }
    }

    return '';
}

function bvoph_build_mail_jobs(array $order, array $items, array $discount, string $source): array
{
    $jobs = [];
    $sentTo = [];
    $buyerEmail = trim((string)($order['buyer_email'] ?? $order['email'] ?? ''));
    $buyerName  = trim((string)($order['buyer_name'] ?? $order['customer_name'] ?? 'Customer'));
    $sellerName  = trim((string)($order['seller_name'] ?? $order['merchant_name'] ?? 'Seller'));
    $sellerEmails = [];
    $adminEmail = bvoph_resolve_admin_email($order);
    $adminName  = 'Admin';
    $orderCode  = trim((string)($order['order_code'] ?? ('ORDER-' . (int)($order['id'] ?? 0))));

    $addSellerEmail = function (?string $email, ?string $name = null) use (&$sellerEmails, &$sellerName): void {
        $email = trim((string)$email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $key = strtolower($email);
        if (!isset($sellerEmails[$key])) {
            $sellerEmails[$key] = $email;
        }
        $name = trim((string)$name);
        if (($sellerName === '' || $sellerName === 'Seller') && $name !== '') {
            $sellerName = $name;
        }
    };

    $addSellerEmail((string)($order['seller_email'] ?? ''), (string)($order['seller_name'] ?? ''));
    $addSellerEmail((string)($order['merchant_email'] ?? ''), (string)($order['merchant_name'] ?? ''));

    foreach ($items as $item) {
        $addSellerEmail((string)($item['seller_email'] ?? ''), (string)($item['seller_name'] ?? ''));
        $addSellerEmail((string)($item['merchant_email'] ?? ''), (string)($item['merchant_name'] ?? ''));
    }

    if (empty($sellerEmails)) {
        $listingIds = [];
        foreach ($items as $item) {
            $listingId = (int)($item['listing_id'] ?? 0);
            if ($listingId > 0) {
                $listingIds[$listingId] = true;
            }
        }

        if (!empty($listingIds)) {
            $pdo = null;
            foreach (['pdo', 'db', 'conn', 'database'] as $globalKey) {
                if (isset($GLOBALS[$globalKey]) && $GLOBALS[$globalKey] instanceof PDO) {
                    $pdo = $GLOBALS[$globalKey];
                    break;
                }
            }

            if ($pdo instanceof PDO) {
               try {
                    if (bvoph_table_exists($pdo, 'listings') && bvoph_table_exists($pdo, 'users')
                        && bvoph_has_col($pdo, 'listings', 'seller_id')
                        && bvoph_has_col($pdo, 'users', 'email')) {
                        $listingStmt = $pdo->prepare("SELECT `seller_id` FROM `listings` WHERE `id` = ? LIMIT 1");
                        $userStmt = $pdo->prepare("SELECT `email` FROM `users` WHERE `id` = ? LIMIT 1");

                        foreach (array_keys($listingIds) as $listingId) {
                            try {
                                $listingStmt->execute([(int)$listingId]);
                                $sellerId = (int)$listingStmt->fetchColumn();
                                if ($sellerId <= 0) {
                                    continue;
                                }

                                $userStmt->execute([$sellerId]);
                                $sellerEmail = (string)$userStmt->fetchColumn();
                                if ($sellerEmail !== '') {
                                    $addSellerEmail($sellerEmail);
                                }
                            } catch (Throwable $e) {
                                bvoph_log('seller_email_lookup_failed', [
                                    'listing_id' => (int)$listingId,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }
                    }
                } catch (Throwable $e) {
                    bvoph_log('seller_email_lookup_failed', [
                        'order_id' => (int)($order['id'] ?? 0),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
	
    $makeMeta = function (string $recipientType) use ($order, $orderCode, $source, $items): string {
        return json_encode([
            'order_id'       => (int)($order['id'] ?? 0),
            'order_code'     => $orderCode,
            'source'         => $source,
            'item_count'     => count($items),
            'recipient_type' => $recipientType,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    };

    $addJob = function (string $email, string $name, string $subject, string $html, string $text, string $template, string $metaJson) use (&$jobs, &$sentTo): void {
        $email = trim($email);
         if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $key = strtolower($email);
        if (isset($sentTo[$key])) {
            return;
        }
        $sentTo[$key] = true;
        $jobs[] = [
            'to_email'  => $email,
            'to_name'   => $name,
            'subject'   => $subject,
            'html_body' => $html,
            'text_body' => $text,
            'template'  => $template,
            'meta_json' => $metaJson,
        ];
    };

    $discountLine = bvoph_discount_summary_text($discount);

    $addJob(
        $buyerEmail,
        $buyerName,
        'Payment received for order ' . $orderCode,
        '<p>Hi ' . bvoph_h($buyerName) . ',</p>'
            . '<p>We received your payment for order <strong>' . bvoph_h($orderCode) . '</strong>.</p>'
            . '<p>' . bvoph_h($discountLine) . '</p>'
            . '<p>Thank you.</p>',
        "Hi {$buyerName},

We received your payment for order {$orderCode}.
" . $discountLine . "

Thank you.",
       'order_paid_buyer',
        $makeMeta('buyer')
    );
foreach (array_values($sellerEmails) as $sellerEmail) {
        $addJob(
            $sellerEmail,
            $sellerName,
            'Order paid: ' . $orderCode,
            '<p>Hi ' . bvoph_h($sellerName) . ',</p>'
                . '<p>The buyer payment is confirmed for order <strong>' . bvoph_h($orderCode) . '</strong>.</p>'
                . '<p>' . bvoph_h($discountLine) . '</p>',
            "Hi {$sellerName},

The buyer payment is confirmed for order {$orderCode}.
" . $discountLine,
            'order_paid_seller',
            $makeMeta('seller')
        );
    }
	
    $addJob(
        $adminEmail,
        $adminName,
        'Order paid notification: ' . $orderCode,
        '<p>Order <strong>' . bvoph_h($orderCode) . '</strong> has been marked as paid.</p>'
            . '<p>' . bvoph_h($discountLine) . '</p>'
            . '<p>Source: ' . bvoph_h($source) . '</p>',
        "Order {$orderCode} has been marked as paid.
" . $discountLine . "
Source: {$source}",
        'order_paid_admin',
        $makeMeta('admin')
    );

    return $jobs;
}
function bvoph_should_skip(PDO $pdo, array $order): bool

{

    $status = strtolower(trim((string)($order['status'] ?? '')));

    $doneAt = trim((string)($order['paid_handler_done_at'] ?? ''));



    if ($doneAt !== '') {

        return true;

    }



    if (in_array($status, ['cancelled', 'refunded'], true)) {

        return true;

    }



    return false;

}



function bvoph_lock_and_get_order(PDO $pdo, int $orderId): ?array

{

    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1 FOR UPDATE");

    $stmt->execute([$orderId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;

}



function bvoph_order_patch_for_paid(array $order, string $finalStatus, array $discount): array

{

    $patch = [

        'status' => $finalStatus,

    ];



    $paidAt = trim((string)($order['paid_at'] ?? ''));

    if ($paidAt === '') {

        $patch['paid_at'] = bvoph_now();

    }



    if (array_key_exists('payment_status', $order)) {

        $patch['payment_status'] = 'paid';

    }



    if (array_key_exists('subtotal_before_discount', $order) && !isset($order['subtotal_before_discount'])) {

        $patch['subtotal_before_discount'] = (float)$discount['subtotal_before_discount'];

    }

    if (array_key_exists('discount_amount', $order)) {

        $patch['discount_amount'] = (float)$discount['discount_amount'];

    }

    if (array_key_exists('seller_discount_total', $order)) {

        $patch['seller_discount_total'] = (float)$discount['seller_discount_total'];

    }



    $patch['paid_handler_done_at'] = bvoph_now();



    return $patch;

}



function bvoph_sync_order_items_discount_snapshot(PDO $pdo, int $orderId, array $discount, array $items): void

{

    if (!bvoph_table_exists($pdo, 'order_items') || !$items) {

        return;

    }



    $cols = bvoph_columns($pdo, 'order_items');

    $setParts = [];

    $baseParams = [];



    if (isset($cols['currency'])) {

        $setParts[] = "`currency` = ?";

        $baseParams[] = (string)$discount['currency'];

    }

    if (isset($cols['discount_amount'])) {

        $setParts[] = "`discount_amount` = ?";

        $baseParams[] = 0.0;

    }

    if (isset($cols['seller_discount_amount'])) {

        $setParts[] = "`seller_discount_amount` = ?";

        $baseParams[] = 0.0;

    }



    if (!$setParts) {

        return;

    }



    $lineTotalKey = null;

    foreach (['line_total', 'total_price', 'subtotal'] as $candidate) {

        if (isset($cols[$candidate])) {

            $lineTotalKey = $candidate;

            break;

        }

    }



    $hasQty = isset($cols['quantity']);

    $hasUnitPrice = isset($cols['unit_price']);



    $commissionBase = max(0.0, (float)$discount['commission_base']);

    $discountTotal  = max(0.0, (float)$discount['discount_amount']);

    $sellerDiscount = max(0.0, (float)$discount['seller_discount_total']);



    $weights = [];

    $totalWeight = 0.0;



    foreach ($items as $item) {

        $itemId = (int)($item['id'] ?? 0);

        if ($itemId <= 0) {

            continue;

        }



        $weight = 0.0;

        if ($lineTotalKey !== null && isset($item[$lineTotalKey])) {

            $weight = (float)$item[$lineTotalKey];

        } elseif ($hasQty && $hasUnitPrice) {

            $weight = (float)$item['quantity'] * (float)$item['unit_price'];

        } else {

            $weight = 1.0;

        }



        if ($weight < 0) {

            $weight = 0.0;

        }



        $weights[$itemId] = $weight;

        $totalWeight += $weight;

    }



    if ($totalWeight <= 0) {

        $count = max(1, count($weights));

        foreach ($weights as $itemId => $weight) {

            $weights[$itemId] = 1.0 / $count;

        }

    } else {

        foreach ($weights as $itemId => $weight) {

            $weights[$itemId] = $weight / $totalWeight;

        }

    }



    foreach ($weights as $itemId => $ratio) {

        $params = $baseParams;

        $setPartsPerItem = $setParts;



        if (isset($cols['discount_amount'])) {

            $params[array_search(0.0, $baseParams, true)] = round($discountTotal * $ratio, 2);

        }

        if (isset($cols['seller_discount_amount'])) {

            $offset = 0;

            foreach ($setPartsPerItem as $idx => $part) {

                if (strpos($part, '`seller_discount_amount`') !== false) {

                    $offset = $idx;

                    break;

                }

            }

            $params[$offset] = round($sellerDiscount * $ratio, 2);

        }

        if (isset($cols['commission_base'])) {

            $setPartsPerItem[] = "`commission_base` = ?";

            $params[] = round($commissionBase * $ratio, 2);

        }



        $params[] = $itemId;

        $params[] = $orderId;



        $sql = "UPDATE order_items

                SET " . implode(', ', $setPartsPerItem) . "

                WHERE id = ? AND order_id = ?";



        $stmt = $pdo->prepare($sql);

        $stmt->execute($params);

    }

}



function bvoph_finalize_offer_paid(PDO $pdo, array $freshOrder): void
{
    $orderId      = (int)   ($freshOrder['id']             ?? 0);
    $orderSource  = strtolower(trim((string)($freshOrder['order_source'] ?? '')));
    $offerId      = (int)   ($freshOrder['offer_id']        ?? 0);
    $offerTokenId = (int)   ($freshOrder['offer_token_id']  ?? 0);

    // Only process offer orders.
    if ($orderSource !== 'offer') {
        bvoph_log('offer_paid_finalize_skipped', [
            'order_id'     => $orderId,
            'reason'       => 'not_offer_order',
            'order_source' => $orderSource,
        ]);
        return;
    }

    // Nothing to finalize if both IDs are absent.
    if ($offerId <= 0 && $offerTokenId <= 0) {
        bvoph_log('offer_paid_finalize_skipped', [
            'order_id' => $orderId,
            'reason'   => 'no_offer_id_or_token_id',
        ]);
        return;
    }

    bvoph_log('offer_paid_finalize_started', [
        'order_id'       => $orderId,
        'offer_id'       => $offerId,
        'offer_token_id' => $offerTokenId,
    ]);

    try {

        $offerUpdated = 0;
        $tokenUpdated = 0;

        // Mark the accepted offer as completed.
        if ($offerId > 0 && bvoph_table_exists($pdo, 'listing_offers')) {
            $stmt = $pdo->prepare(
                "UPDATE listing_offers
                    SET completed_order_id = :order_id,
                        status             = 'completed',
                        updated_at         = UTC_TIMESTAMP()
                  WHERE id                 = :offer_id
                    AND completed_order_id IS NULL
                    AND status             IN ('seller_accepted', 'buyer_checkout_ready')"
            );
            $stmt->execute([':order_id' => $orderId, ':offer_id' => $offerId]);
            $offerUpdated = (int) $stmt->rowCount();
        }

        // Consume the single-use checkout token.
        // offer_id guard in WHERE prevents cross-offer token reuse.
        if ($offerTokenId > 0 && $offerId > 0
            && bvoph_table_exists($pdo, 'listing_offer_checkout_tokens')
        ) {
            $stmt = $pdo->prepare(
                "UPDATE listing_offer_checkout_tokens
                    SET status     = 'used',
                        used_at    = UTC_TIMESTAMP(),
                        order_id   = :order_id,
                        updated_at = UTC_TIMESTAMP()
                  WHERE id         = :token_id
                    AND offer_id   = :offer_id
                    AND used_at    IS NULL
                    AND status     IN ('active', 'expired')"
            );
            $stmt->execute([
                ':order_id' => $orderId,
                ':token_id' => $offerTokenId,
                ':offer_id' => $offerId,
            ]);
            $tokenUpdated = (int) $stmt->rowCount();
        }

        bvoph_log('offer_paid_finalize_completed', [
            'order_id'       => $orderId,
            'offer_id'       => $offerId,
            'offer_token_id' => $offerTokenId,
            'offer_rows'     => $offerUpdated,
            'token_rows'     => $tokenUpdated,
        ]);

    } catch (Throwable $e) {
        // Never rethrow — offer finalization failure must never rollback the paid order.
        bvoph_log('offer_paid_finalize_failed', [
            'order_id'       => $orderId,
            'offer_id'       => $offerId,
            'offer_token_id' => $offerTokenId,
            'error'          => $e->getMessage(),
        ]);
    }
}



function bv_handle_paid_order(PDO $pdo, int $orderId, $source = 'webhook'): array

{

    if (is_array($source)) {

        $source = (string)($source['source'] ?? $source['trigger'] ?? 'webhook');

    }

    $source = trim((string)$source);

    if ($source === '') {

        $source = 'webhook';

    }



    bvoph_log('PAID_HANDLER_ENTER', [

        'order_id' => $orderId,

        'source' => $source,

        'time' => bvoph_now(),

    ]);



    $result = [

        'ok' => false,

        'order_id' => $orderId,

        'source' => $source,

        'skipped' => false,

        'message' => '',

        'order_code' => '',

        'items_processed' => 0,

        'auction_items' => 0,

        'mail_jobs_queued' => 0,

        'discount' => [],

        'fee_snapshot' => [

            'captured' => false,

            'snapshot' => [],

            'policy_code' => '',

            'error' => null,

        ],

    ];



    if ($orderId <= 0) {

        $result['message'] = 'Invalid order id';

        bvoph_log('paid_handler_invalid_order_id', [

            'order_id' => $orderId,

            'source' => $source,

        ]);

        return $result;

    }



    $mailJobs = [];



    try {

        $pdo->beginTransaction();



        $order = bvoph_lock_and_get_order($pdo, $orderId);

        if (!$order) {

            throw new RuntimeException('Order not found');

        }



        $result['order_code'] = (string)($order['order_code'] ?? '');



        if (bvoph_should_skip($pdo, $order)) {

            $pdo->rollBack();

            $result['ok'] = true;

            $result['skipped'] = true;

            $result['message'] = 'Already handled or terminal state';



            bvoph_log('paid_handler_skipped', [

                'order_id' => $orderId,

                'order_code' => $result['order_code'],

                'source' => $source,

                'status' => (string)($order['status'] ?? ''),

                'paid_handler_done_at' => (string)($order['paid_handler_done_at'] ?? ''),

            ]);



            return $result;

        }



        $items = bvoph_fetch_order_items($pdo, $orderId);

        $discount = bvoph_extract_discount_snapshot($order);

        $finalStatus = bvoph_final_paid_order_status($pdo);



        $auctionContext = bvoph_detect_auction_paid_context($pdo, $order, $items);

        $auctionItems = 0;



        foreach ($items as $item) {

            $itemId = (int)($item['id'] ?? 0);

            $listingId = (int)($item['listing_id'] ?? 0);

            $qty = max(1, (int)($item['quantity'] ?? 1));



            $itemSource = strtolower(trim((string)($item['source_type'] ?? $item['order_source'] ?? '')));

            if (in_array($itemSource, ['auction', 'auction_win', 'auction_award'], true)) {

                $auctionItems++;

            }



            if ($listingId <= 0) {

                continue;

            }



            $listing = bvoph_fetch_listing_for_update($pdo, $listingId);

            if (!$listing) {

                continue;

            }



            $stockRes = bvoph_sync_listing_stock_after_paid($pdo, $listing, $qty);

            bvoph_insert_stock_log(

                $pdo,

                bvoph_build_stock_log_payload($orderId, $itemId, $listingId, $listing, $stockRes, $qty, $source, $finalStatus)

            );

        }



        $result['items_processed'] = count($items);

        $result['auction_items'] = $auctionItems;



        bvoph_sync_order_items_discount_snapshot($pdo, $orderId, $discount, $items);



        $orderPatch = bvoph_order_patch_for_paid($order, $finalStatus, $discount);

        bvoph_update_order($pdo, $orderId, $orderPatch);



        $freshOrder = bvoph_fetch_order($pdo, $orderId) ?: array_merge($order, $orderPatch);



        $result['fee_snapshot'] = bvoph_capture_refund_fee_snapshot($pdo, $freshOrder);



        if (!empty($auctionContext['is_auction_paid'])) {

            bvoph_mark_auction_awards_paid($pdo, (array)$auctionContext['auction_awards'], $freshOrder);

        }



        $mailJobs = bvoph_build_mail_jobs($freshOrder, $items, $discount, $source);



        if (function_exists('bv_reservation_release_for_order')) {

            try {

                bv_reservation_release_for_order($pdo, $orderId, 'paid');

            } catch (Throwable $e) {

                bvoph_log('reservation_release_failed', [

                    'order_id' => $orderId,

                    'error' => $e->getMessage(),

                ]);

            }

        }



        bvoph_insert_audit_log($pdo, [

            'actor_type'   => 'system',

            'actor_id'     => null,

            'actor_name'   => 'order_paid_handler',

            'event_type'   => 'order_paid',

            'entity_type'  => 'order',

            'entity_id'    => $orderId,

            'entity_title' => (string)($freshOrder['order_code'] ?? ''),

            'action'       => 'paid_handler_completed',

            'summary'      => 'Order marked paid and stock synced',

            'before_data'  => json_encode([

                'status'         => $order['status'] ?? null,

                'payment_status' => $order['payment_status'] ?? null,

            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),

            'after_data'   => json_encode([

                'status'         => $finalStatus,

                'payment_status' => 'paid',

                'discount'       => $discount,

                'fee_snapshot'   => $result['fee_snapshot'],

            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),

            'meta_data'    => json_encode([

                'source'          => $source,

                'items_processed' => count($items),

                'auction_items'   => $auctionItems,

            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),

        ]);

        // ── Offer finalization — inside transaction, before commit ────────────────
        // Marks listing_offers as completed and consumes the checkout token.
        // bvoph_finalize_offer_paid() is fully try/catch wrapped — any failure is
        // logged and swallowed so the paid order is always committed successfully.
        bvoph_finalize_offer_paid($pdo, $freshOrder);



        $pdo->commit();



        $queued = bvoph_queue_mail_jobs($pdo, $mailJobs);

        $result['mail_jobs_queued'] = $queued;

        if (function_exists('bv_notify')) {

            try {

                bv_notify('order.payment.received', ['order_id' => (int)$orderId]);

            } catch (Throwable $e) {

                bvoph_log('order_payment_notification_failed', [

                    'order_id' => $orderId,

                    'event' => 'order.payment.received',

                    'error' => $e->getMessage(),

                ]);

            }

        } else {

            bvoph_log('order_payment_notification_missing', [

                'order_id' => $orderId,

                'event' => 'order.payment.received',

                'error' => 'bv_notify_not_available',

            ]);

        }

        // --- Seller Balance Hook: credit seller pending_balance per order item ---
        // Runs AFTER $pdo->commit() — failure is non-fatal and NEVER rolls back the order.
        // Idempotency key inside bv_seller_balance_process_order_paid() prevents
        // duplicate ledger entries on Stripe webhook retries.
        if (function_exists('bv_seller_balance_process_order_paid')) {
            try {
                $__sbItems = bv_seller_balance_process_order_paid($orderId);
                bvoph_log('seller_balance_order_paid_recorded', [
                    'order_id'       => $orderId,
                    'items_recorded' => $__sbItems,
                ]);
            } catch (Throwable $__sbEx) {
                bvoph_log('seller_balance_order_paid_failed', [
                    'order_id' => $orderId,
                    'error'    => $__sbEx->getMessage(),
                ]);
            } finally {
                unset($__sbItems, $__sbEx);
            }
        }
        // --- End Seller Balance Hook ---

        $result['ok'] = true;

        $result['discount'] = $discount;

        $result['message'] = 'Paid handler completed';



        bvoph_log('paid_handler_completed', [

            'order_id' => $orderId,

            'order_code' => $result['order_code'],

            'source' => $source,

            'stock_items' => $result['items_processed'],

            'status' => $finalStatus,

            'auction_items' => $auctionItems,

            'discount' => $discount,

            'fee_snapshot' => $result['fee_snapshot'],

            'mail_jobs_queued' => $queued,

        ]);



        return $result;

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {

            $pdo->rollBack();

        }



        $result['message'] = $e->getMessage();



        bvoph_log('paid_handler_failed', [

            'order_id' => $orderId,

            'source' => $source,

            'error' => $e->getMessage(),

        ]);



        return $result;

    }

}



if (!function_exists('bv_run_order_paid_handler')) {

    function bv_run_order_paid_handler(PDO $pdo, int $orderId, array $context = []): array

    {

        return bv_handle_paid_order($pdo, $orderId, $context);

    }


}