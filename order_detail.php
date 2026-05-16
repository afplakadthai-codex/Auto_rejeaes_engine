<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$sellerOrderDetailDebugLogger = static function (string $event, array $context = []): void {
    $paths = [
        dirname(__DIR__) . '/private_html/seller_order_detail_debug.log',
        dirname(__DIR__) . '/seller_order_detail_debug.log',
        __DIR__ . '/../seller_order_detail_debug.log',
    ];

    $line = '[' . gmdate('Y-m-d H:i:s') . " UTC] " . $event;
    if ($context !== []) {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json) && $json !== '') {
            $line .= ' ' . $json;
        }
    }
    $line .= "\n";

    foreach ($paths as $logPath) {
        $dir = dirname($logPath);
        if (!is_dir($dir)) {
            continue;
        }
        if (is_file($logPath) || is_writable($dir)) {
            @file_put_contents($logPath, $line, FILE_APPEND);
            return;
        }
    }
};

if (!function_exists('seller_order_detail_debug')) {
    function seller_order_detail_debug(string $event, array $context = []): void
    {
        $logger = $GLOBALS['sellerOrderDetailDebugLogger'] ?? null;
        if (is_callable($logger)) {
            $logger($event, $context);
        }
    }
}
$GLOBALS['sellerOrderDetailDebugLogger'] = $sellerOrderDetailDebugLogger;

$guardCandidates = [
    __DIR__ . '/_guard.php',
    dirname(__DIR__) . '/member/_guard.php',
];
foreach ($guardCandidates as $guardFile) {
    if (is_file($guardFile)) {
        require_once $guardFile;
    }
}

$requiredHelper = __DIR__ . '/includes/order_request_actions.php';
if (!is_file($requiredHelper)) {
    seller_order_detail_debug('order_detail_blocked_internal_error', [
        'checkpoint' => 'required_helper_missing',
        'helper' => $requiredHelper,
    ]);
    http_response_code(500);
    echo 'Order helper unavailable.';
    exit;
}
require_once $requiredHelper;

$optionalHelpers = [
    __DIR__ . '/includes/cancel_refund_summary.php',
    dirname(__DIR__) . '/includes/order_cancel.php',
    dirname(__DIR__) . '/includes/order_refund.php',
    dirname(__DIR__) . '/order_cancel.php',
    dirname(__DIR__) . '/order_refund.php',
];
foreach ($optionalHelpers as $optionalFile) {
    if (is_file($optionalFile)) {
        require_once $optionalFile;
    }
}
if (!function_exists('seller_order_request_current_user_id')) {
    seller_order_detail_debug('order_detail_forbidden_exit', [
        'checkpoint' => 'seller_auth_unavailable',
        'reason' => 'seller_order_request_current_user_id_missing',
    ]);
    http_response_code(403);
    echo 'Seller authentication unavailable.';
    exit;
}

$resolvePdo = static function (): ?PDO {
    $candidateKeys = ['pdo', 'db', 'conn', 'database'];
    foreach ($candidateKeys as $key) {
        if (isset($GLOBALS[$key]) && $GLOBALS[$key] instanceof PDO) {
            return $GLOBALS[$key];
        }
    }
    if (isset($GLOBALS['mysqli']) && $GLOBALS['mysqli'] instanceof PDO) {
        return $GLOBALS['mysqli'];
    }
    return null;
};

$pdo = $resolvePdo();
if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo 'Database connection unavailable.';
    exit;
}

$tableExists = static function (PDO $pdo, string $tableName): bool {
    static $cache = [];
    $key = strtolower($tableName);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name LIMIT 1');
        $stmt->execute([':table_name' => $tableName]);
        $cache[$key] = (bool)$stmt->fetchColumn();
        return $cache[$key];
    } catch (Throwable $e) {
        $cache[$key] = false;
        return false;
    }
};

$columnExists = static function (PDO $pdo, string $tableName, string $columnName) use ($tableExists): bool {
    static $cache = [];
    $cacheKey = strtolower($tableName . '.' . $columnName);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    if (!$tableExists($pdo, $tableName)) {
        $cache[$cacheKey] = false;
        return false;
    }
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name LIMIT 1');
        $stmt->execute([
            ':table_name' => $tableName,
            ':column_name' => $columnName,
        ]);
        $cache[$cacheKey] = (bool)$stmt->fetchColumn();
        return $cache[$cacheKey];
    } catch (Throwable $e) {
        $cache[$cacheKey] = false;
        return false;
    }
};


$resolveSellerUserId = static function (): int {
    $candidates = [
        $_SESSION['user']['id'] ?? null,
        $_SESSION['auth_user']['id'] ?? null,
        $_SESSION['member']['id'] ?? null,
        $_SESSION['seller']['id'] ?? null,
        $_SESSION['user_id'] ?? null,
        $_SESSION['member_id'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (is_numeric($candidate) && (int)$candidate > 0) {
            return (int)$candidate;
        }
    }

    return 0;
};

$orderId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;

$currentSellerId = 0;
if (function_exists('seller_order_request_current_seller_id')) {
    $currentSellerId = (int)seller_order_request_current_seller_id();
}

$helperSellerUserId = 0;
if (function_exists('seller_order_request_current_user_id')) {
    $helperSellerUserId = (int)seller_order_request_current_user_id();
}

$sellerUserId = $resolveSellerUserId();
if ($sellerUserId <= 0) {
    $sellerUserId = $helperSellerUserId;
}

seller_order_detail_debug('seller_order_request_current_user_id returned', [
    'sellerUserId' => $helperSellerUserId,
]);

$sessionIdentities = [
    'user.id' => $_SESSION['user']['id'] ?? null,
    'auth_user.id' => $_SESSION['auth_user']['id'] ?? null,
    'member.id' => $_SESSION['member']['id'] ?? null,
    'seller.id' => $_SESSION['seller']['id'] ?? null,
    'user_id' => $_SESSION['user_id'] ?? null,
    'member_id' => $_SESSION['member_id'] ?? null,
];
seller_order_detail_debug('session identities discovered', [
    'session_ids' => $sessionIdentities,
    'resolvedSellerUserId' => $sellerUserId,
    'resolvedCurrentSellerId' => $currentSellerId,
    'helperSellerUserId' => $helperSellerUserId,
    'orderId' => $orderId,
]);

if ($orderId <= 0 || $sellerUserId <= 0) {
    seller_order_detail_debug('order_detail_not_found_exit', [
        'checkpoint' => 'invalid_order_or_identity',
        'orderId' => $orderId,
        'sellerUserId' => $sellerUserId,
        'currentSellerId' => $currentSellerId,
    ]);
    http_response_code(404);
    echo 'Order not found.';
    exit;
}

if (!isset($_SESSION['seller_order_request_csrf_token']) || !is_string($_SESSION['seller_order_request_csrf_token']) || $_SESSION['seller_order_request_csrf_token'] === '') {
    try {
        $_SESSION['seller_order_request_csrf_token'] = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $_SESSION['seller_order_request_csrf_token'] = sha1((string)microtime(true) . '-' . (string)mt_rand());
    }
}
$csrfToken = (string)$_SESSION['seller_order_request_csrf_token'];

$orderContext = null;
$bundle = [
    'order' => null,
    'cancel' => null,
    'refund' => null,
    'primary_type' => '',
    'seller_can_approve_cancel' => false,
    'seller_can_reject_cancel' => false,
    'seller_can_approve_refund' => false,
    'seller_can_reject_refund' => false,
];

try {
    if (function_exists('seller_order_request_get_order_context')) {
        $orderContext = seller_order_request_get_order_context($orderId, $sellerUserId);
        seller_order_detail_debug('order_context_lookup', [
            'sellerUserId' => $sellerUserId,
            'result_empty' => !is_array($orderContext) || $orderContext === [],
        ]);
    }
    if (function_exists('seller_order_request_get_request_bundle')) {
        $loaded = seller_order_request_get_request_bundle($orderId, $sellerUserId);
        seller_order_detail_debug('request_bundle_lookup', [
            'sellerUserId' => $sellerUserId,
            'result_empty' => !is_array($loaded) || $loaded === [],
        ]);
        if (is_array($loaded)) {
            $bundle = array_merge($bundle, $loaded);
        }
    } else {
        if (function_exists('seller_order_request_get_cancel_by_order_id')) {
            $bundle['cancel'] = seller_order_request_get_cancel_by_order_id($orderId, $sellerUserId);
        }
        if (function_exists('seller_order_request_get_refund_by_order_id')) {
            $bundle['refund'] = seller_order_request_get_refund_by_order_id($orderId, $sellerUserId);
        }
     }
} catch (Throwable $e) {
    seller_order_detail_debug('order_detail_exception', [
        'message' => $e->getMessage(),
        'orderId' => $orderId,
        'sellerUserId' => $sellerUserId,
        'currentSellerId' => $currentSellerId,
    ]);
    // keep safe defaults
}

if (!$orderContext && is_array($bundle['order'] ?? null)) {
    $orderContext = $bundle['order'];
}

if (!$orderContext || !is_array($orderContext)) {
   seller_order_detail_debug('order_detail_forbidden_exit', [
        'checkpoint' => 'order_context_empty_after_helper_calls',
        'orderId' => $orderId,
        'sellerUserId' => $sellerUserId,
        'currentSellerId' => $currentSellerId,
    ]);
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$cancelRow = is_array($bundle['cancel'] ?? null) ? $bundle['cancel'] : null;
$refundRow = is_array($bundle['refund'] ?? null) ? $bundle['refund'] : null;

if (($bundle['primary_type'] ?? '') === '' && function_exists('seller_order_request_detect_primary_type')) {
    $bundle['primary_type'] = seller_order_request_detect_primary_type($cancelRow, $refundRow);
}
$primaryType = (string)($bundle['primary_type'] ?? '');

$canApproveCancel = !empty($bundle['seller_can_approve_cancel']);
$canRejectCancel = !empty($bundle['seller_can_reject_cancel']);
$canApproveRefund = !empty($bundle['seller_can_approve_refund']);
$canRejectRefund = !empty($bundle['seller_can_reject_refund']);

$flashSuccess = isset($_SESSION['seller_order_request_success']) ? trim((string)$_SESSION['seller_order_request_success']) : '';
$flashError = isset($_SESSION['seller_order_request_error']) ? trim((string)$_SESSION['seller_order_request_error']) : '';
unset($_SESSION['seller_order_request_success'], $_SESSION['seller_order_request_error']);

// Fulfillment flash
$fulfillmentFlashType = '';
$fulfillmentFlashMessage = '';
if (isset($_SESSION['seller_fulfillment_flash']) && is_array($_SESSION['seller_fulfillment_flash'])) {
    $flashTypeCandidate = strtolower(trim((string)($_SESSION['seller_fulfillment_flash']['type'] ?? '')));
    $flashMessageCandidate = trim((string)($_SESSION['seller_fulfillment_flash']['message'] ?? ''));
    if (in_array($flashTypeCandidate, ['success', 'error'], true) && $flashMessageCandidate !== '') {
        $fulfillmentFlashType = $flashTypeCandidate;
        $fulfillmentFlashMessage = $flashMessageCandidate;
    }
}
if ($fulfillmentFlashMessage === '') {
    $fulfillmentFlashSuccess = isset($_SESSION['seller_fulfillment_flash']['success']) ? trim((string)$_SESSION['seller_fulfillment_flash']['success']) : '';
    $fulfillmentFlashError   = isset($_SESSION['seller_fulfillment_flash']['error'])   ? trim((string)$_SESSION['seller_fulfillment_flash']['error'])   : '';
    if ($fulfillmentFlashSuccess !== '') {
        $fulfillmentFlashType = 'success';
        $fulfillmentFlashMessage = $fulfillmentFlashSuccess;
    } elseif ($fulfillmentFlashError !== '') {
        $fulfillmentFlashType = 'error';
        $fulfillmentFlashMessage = $fulfillmentFlashError;
    } elseif (isset($_SESSION['seller_fulfillment_flash']) && is_string($_SESSION['seller_fulfillment_flash'])) {
        $fulfillmentFlashType = 'success';
        $fulfillmentFlashMessage = trim((string)$_SESSION['seller_fulfillment_flash']);
    }
}
unset($_SESSION['seller_fulfillment_flash']);

// Fulfillment CSRF token
if (!isset($_SESSION['_csrf_seller_order']['fulfillment']) || !is_string($_SESSION['_csrf_seller_order']['fulfillment']) || $_SESSION['_csrf_seller_order']['fulfillment'] === '') {
    try {
        $_SESSION['_csrf_seller_order']['fulfillment'] = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $_SESSION['_csrf_seller_order']['fulfillment'] = sha1((string)microtime(true) . '-' . (string)mt_rand());
    }
}
$fulfillmentCsrf = (string)$_SESSION['_csrf_seller_order']['fulfillment'];

$h = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

$money = static function ($amount, ?string $currency = null): string {
    if (function_exists('seller_order_request_money')) {
        return seller_order_request_money($amount, $currency);
    }
    if (!is_numeric($amount)) {
        return '—';
    }
    $currency = strtoupper(trim((string)$currency));
    if ($currency === '') {
        $currency = 'USD';
    }
    return number_format((float)$amount, 2) . ' ' . $currency;
};

$statusBadge = static function (string $type, string $status): array {
    if (function_exists('seller_order_request_status_badge')) {
        return seller_order_request_status_badge($type, $status);
    }
    $label = $status !== '' ? ucfirst(str_replace('_', ' ', $status)) : 'Unknown';
    return ['label' => $label, 'class' => 'badge-default'];
};

$paymentBadge = static function (string $status): array {
    $key = strtolower(trim($status));
    $map = [
        'paid' => ['Paid', 'offer-thread-badge-completed'],
        'completed' => ['Completed', 'offer-thread-badge-completed'],
        'authorized' => ['Authorized', 'offer-thread-badge-ready'],
        'pending' => ['Pending', 'offer-thread-badge-open'],
        'unpaid' => ['Unpaid', 'offer-thread-badge-needs-reply'],
        'failed' => ['Failed', 'offer-thread-badge-needs-reply'],
        'refunded' => ['Refunded', 'offer-thread-badge-ready'],
        'partially_refunded' => ['Partially Refunded', 'offer-thread-badge-open'],
    ];
    if (isset($map[$key])) {
        return ['label' => $map[$key][0], 'class' => $map[$key][1]];
    }
    return ['label' => $key !== '' ? ucfirst(str_replace('_', ' ', $key)) : 'Unknown', 'class' => 'badge-default'];
};

$deriveShippingStatus = static function (array $ctx): string {
    $candidates = [
        'shipping_status',
        'delivery_status',
        'tracking_status',
        'fulfillment_status',
        'order_shipping_status',
    ];
    foreach ($candidates as $key) {
        if (isset($ctx[$key]) && trim((string)$ctx[$key]) !== '') {
            $rawStatus = strtolower(trim((string)$ctx[$key]));
            if ($rawStatus === 'pending') {
                $orderStatus = strtolower(trim((string)($ctx['order_status'] ?? '')));
                if (in_array($orderStatus, ['paid', 'confirmed'], true)) {
                    return 'to_ship';
                }
            }
            return $rawStatus;
        }
    }

    $orderStatus = strtolower(trim((string)($ctx['order_status'] ?? '')));
    $derivedMap = [
        'paid' => 'to_ship',
        'confirmed' => 'to_ship',
        'ready_to_ship' => 'to_ship',
        'packed' => 'processing',
        'processing' => 'processing',
        'shipped' => 'shipped',
        'in_transit' => 'shipped',
        'out_for_delivery' => 'shipped',
        'delivered' => 'delivered',
        'completed' => 'delivered',
        'cancelled' => 'cancelled',
        'refunded' => 'cancelled',
    ];
    if (isset($derivedMap[$orderStatus])) {
        return $derivedMap[$orderStatus];
    }
    return 'pending';
};

$shippingBadge = static function (string $status): array {
    $key = strtolower(trim($status));
$map = [
        'to_ship' => ['To Ship', 'offer-thread-badge-ready'],
        'pending' => ['Pending', 'offer-thread-badge-open'],
        'processing' => ['Processing', 'offer-thread-badge-ready'],
        'ready_to_ship' => ['Ready to Ship', 'offer-thread-badge-ready'],
        'shipped' => ['Shipped', 'offer-thread-badge-completed'],
        'completed' => ['Completed', 'offer-thread-badge-completed'],
        'in_transit' => ['In Transit', 'offer-thread-badge-completed'],
        'out_for_delivery' => ['Out for Delivery', 'offer-thread-badge-completed'],
        'delivered' => ['Delivered', 'offer-thread-badge-completed'],
        'cancelled' => ['Closed', 'offer-thread-badge-needs-reply'],
        'refunded' => ['Closed', 'offer-thread-badge-needs-reply'],
        'closed' => ['Closed', 'offer-thread-badge-needs-reply'],
        'unknown' => ['Unknown', 'badge-default'],
        'returned' => ['Returned', 'offer-thread-badge-needs-reply'],
    ];
    if (isset($map[$key])) {
        return ['label' => $map[$key][0], 'class' => $map[$key][1]];
    }
    return ['label' => $key !== '' ? ucfirst(str_replace('_', ' ', $key)) : 'Unknown', 'class' => 'badge-default'];
};

$pickDate = static function (?array $row): string {
    if (!$row) {
        return '';
    }
    foreach (['requested_at', 'created_at', 'updated_at'] as $k) {
        if (!empty($row[$k])) {
            return (string)$row[$k];
        }
    }
    return '';
};

$pickValue = static function (?array $row, array $keys): string {
    if (!$row) {
        return '';
    }
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return trim((string)$row[$key]);
        }
    }
    return '';
};

$lineTotalForItem = static function (array $row): ?float {
    foreach (['line_total', 'subtotal'] as $k) {
        if (isset($row[$k]) && is_numeric($row[$k])) {
            return (float)$row[$k];
        }
    }
    $qty = null;
    foreach (['qty', 'quantity'] as $k) {
        if (isset($row[$k]) && is_numeric($row[$k])) {
            $qty = (float)$row[$k];
            break;
        }
    }
    $price = null;
    foreach (['unit_price', 'price'] as $k) {
        if (isset($row[$k]) && is_numeric($row[$k])) {
            $price = (float)$row[$k];
            break;
        }
    }
    if ($qty !== null && $price !== null) {
        return $qty * $price;
    }
    return null;
};

if (!function_exists('bv_seller_order_detail_derive_shipping_status')) {
    function bv_seller_order_detail_derive_shipping_status(array $items): array
    {
        if ($items === []) {
            return ['key' => 'unknown', 'label' => 'Unknown', 'color' => '#95a1c7', 'bg' => 'rgba(149,161,199,0.18)'];
        }

        $counts = [];
        foreach ($items as $row) {
            $s = strtolower(trim((string)($row['fulfillment_status'] ?? '')));
            if ($s === '') {
                $s = 'pending';
            }
            $counts[$s] = ($counts[$s] ?? 0) + 1;
        }

        $total = count($items);

        if (($counts['completed'] ?? 0) === $total) {
            return ['key' => 'completed', 'label' => 'Delivered',           'color' => '#c9ffdf', 'bg' => 'rgba(46,204,113,0.18)'];
        }
        if (($counts['shipped'] ?? 0) === $total) {
            return ['key' => 'shipped',   'label' => 'Shipped',             'color' => '#c9ffdf', 'bg' => 'rgba(46,204,113,0.18)'];
        }
        if (($counts['processing'] ?? 0) === $total) {
            return ['key' => 'processing','label' => 'Preparing',           'color' => '#d8ddff', 'bg' => 'rgba(127,140,255,0.18)'];
        }
        if (($counts['pending'] ?? 0) === $total) {
            return ['key' => 'pending',   'label' => 'To Ship',             'color' => '#ffd89a', 'bg' => 'rgba(243,156,18,0.20)'];
        }
        if (($counts['cancelled'] ?? 0) === $total) {
            return ['key' => 'cancelled', 'label' => 'Cancelled',           'color' => '#ffd2cd', 'bg' => 'rgba(231,76,60,0.18)'];
        }
        // Mixed statuses
        return     ['key' => 'mixed',     'label' => 'Partially Fulfilled', 'color' => '#ffd89a', 'bg' => 'rgba(243,156,18,0.20)'];
    }
}

if (!function_exists('seller_order_detail_fulfillment_badge')) {
    function seller_order_detail_fulfillment_badge($status): array
    {
        $key = strtolower(trim((string)$status));
        if ($key === '') {
            $key = 'pending';
        }
        $map = [
            'pending' => ['label' => 'Pending', 'color' => '#ffd89a', 'bg' => 'rgba(243,156,18,0.20)'],
            'processing' => ['label' => 'Processing', 'color' => '#d8ddff', 'bg' => 'rgba(127,140,255,0.18)'],
            'shipped' => ['label' => 'Shipped', 'color' => '#c9ffdf', 'bg' => 'rgba(46,204,113,0.18)'],
            'completed' => ['label' => 'Completed', 'color' => '#c9ffdf', 'bg' => 'rgba(46,204,113,0.18)'],
            'cancelled' => ['label' => 'Cancelled', 'color' => '#ffd2cd', 'bg' => 'rgba(231,76,60,0.18)'],
        ];
        return $map[$key] ?? ['label' => ucfirst(str_replace('_', ' ', $key)), 'color' => '#dce4ff', 'bg' => 'rgba(149,161,199,0.18)'];
    }
}

if (!function_exists('seller_order_detail_fulfillment_value')) {
    function seller_order_detail_fulfillment_value(array $item, string $key, string $default = ''): string
    {
        if (!array_key_exists($key, $item)) {
            return $default;
        }
        $value = trim((string)$item[$key]);
        return $value !== '' ? $value : $default;
    }
}

if (!function_exists('seller_order_detail_can_show_fulfillment_actions')) {
    function seller_order_detail_can_show_fulfillment_actions(array $order): bool
    {
        $allowedStatuses = ['paid', 'confirmed', 'processing', 'packing', 'shipped', 'completed'];
        $blockedStatuses = ['pending', 'pending_payment', 'cancelled', 'refunded'];

        $orderStatus = strtolower(trim((string)($order['order_status'] ?? '')));
        $paymentStatus = strtolower(trim((string)($order['payment_status'] ?? '')));

        if (in_array($orderStatus, $blockedStatuses, true) || in_array($paymentStatus, $blockedStatuses, true)) {
            return false;
        }
        if (in_array($orderStatus, $allowedStatuses, true) || in_array($paymentStatus, $allowedStatuses, true)) {
            return true;
        }
        return false;
    }
}

$currency = (string)($orderContext['currency'] ?? 'USD');
$orderCode = trim((string)($orderContext['order_code'] ?? ''));
$paymentStatus = trim((string)($orderContext['payment_status'] ?? ''));
$buyerName = trim((string)($orderContext['buyer_name'] ?? ''));
$listingTitle = trim((string)($orderContext['listing_title'] ?? ''));
$orderStatus = strtolower(trim((string)($orderContext['order_status'] ?? '')));
$sellerOwnershipKeys = ['seller_user_id', 'seller_id', 'owner_user_id', 'user_id'];
$toPositiveIntOrNull = static function ($rawValue): ?int {
    if (is_int($rawValue)) {
        return $rawValue > 0 ? $rawValue : null;
    }
    if (is_string($rawValue)) {
        $trimmed = trim($rawValue);
        if ($trimmed === '' || !preg_match('/^\d+$/', $trimmed)) {
            return null;
        }
        $parsed = (int)$trimmed;
        return $parsed > 0 ? $parsed : null;
    }
    if (is_float($rawValue) && floor($rawValue) === $rawValue) {
        $parsed = (int)$rawValue;
        return $parsed > 0 ? $parsed : null;
    }
    return null;
};

$ordersExists = $tableExists($pdo, 'orders');
$orderItemsExists = $tableExists($pdo, 'order_items');
$listingsExists = $tableExists($pdo, 'listings');
if (!$ordersExists || !$orderItemsExists) {
    seller_order_detail_debug('order_detail_not_found_exit', [
        'checkpoint' => 'orders_or_order_items_table_missing',
        'ordersExists' => $ordersExists,
        'orderItemsExists' => $orderItemsExists,
    ]);
    http_response_code(404);
    echo 'Order not found.';
    exit;
}

try {
    $orderExistsStmt = $pdo->prepare('SELECT id FROM orders WHERE id = :order_id LIMIT 1');
 $orderExistsStmt->execute([':order_id' => $orderId]);
    $orderExists = (bool)$orderExistsStmt->fetchColumn();
    if (!$orderExists) {
        seller_order_detail_debug('order_detail_not_found_exit', [
            'checkpoint' => 'order_id_not_found',
            'orderId' => $orderId,
        ]);
        http_response_code(404);
        echo 'Order not found.';
        exit;
    }
} catch (Throwable $e) {
    seller_order_detail_debug('order_detail_not_found_exit', [
        'checkpoint' => 'order_exists_query_failed',
        'orderId' => $orderId,
        'error' => $e->getMessage(),
    ]);
    http_response_code(404);
    echo 'Order not found.';
    exit;
}

// ------------------------------------------------------------------
// Detect which ownership columns exist (used in SELECT and WHERE)
// ------------------------------------------------------------------
$hasOiSellerUserId = $columnExists($pdo, 'order_items', 'seller_user_id');
$hasOiSellerId     = $columnExists($pdo, 'order_items', 'seller_id');
$hasOiListingId    = $columnExists($pdo, 'order_items', 'listing_id');
$hasLsellerId      = $listingsExists && $columnExists($pdo, 'listings', 'seller_id');
$hasLTitle         = $listingsExists && $columnExists($pdo, 'listings', 'title');
$hasLName          = $listingsExists && $columnExists($pdo, 'listings', 'name');

seller_order_detail_debug('seller_items_ownership_columns', [
    'sellerUserId'       => $sellerUserId,
    'currentSellerId'    => $currentSellerId,
    'orderId'            => $orderId,
    'oi.seller_user_id'  => $hasOiSellerUserId,
    'oi.seller_id'       => $hasOiSellerId,
    'oi.listing_id'      => $hasOiListingId,
    'l.seller_id'        => $hasLsellerId,
]);

// ------------------------------------------------------------------
// Build SELECT list (same columns used by both primary and fallback)
// ------------------------------------------------------------------
$bvItemSelect = [
    'oi.id AS item_id',
    'oi.id',
    'oi.order_id',
    $hasOiListingId ? 'oi.listing_id AS listing_id' : 'NULL AS listing_id',
    $columnExists($pdo, 'order_items', 'title')
        ? 'oi.title AS item_title'
        : ($columnExists($pdo, 'order_items', 'name') ? 'oi.name AS item_title' : "'' AS item_title"),
    $columnExists($pdo, 'order_items', 'qty')
        ? 'oi.qty AS qty'
        : ($columnExists($pdo, 'order_items', 'quantity') ? 'oi.quantity AS qty' : 'NULL AS qty'),
    $columnExists($pdo, 'order_items', 'quantity')
        ? 'oi.quantity AS quantity'
        : ($columnExists($pdo, 'order_items', 'qty') ? 'oi.qty AS quantity' : 'NULL AS quantity'),
    $columnExists($pdo, 'order_items', 'unit_price')
        ? 'oi.unit_price AS unit_price'
        : ($columnExists($pdo, 'order_items', 'price') ? 'oi.price AS unit_price' : 'NULL AS unit_price'),
    $columnExists($pdo, 'order_items', 'price')
        ? 'oi.price AS price'
        : 'NULL AS price',
    $columnExists($pdo, 'order_items', 'line_total')
        ? 'oi.line_total AS line_total'
        : ($columnExists($pdo, 'order_items', 'subtotal') ? 'oi.subtotal AS line_total' : 'NULL AS line_total'),
    $columnExists($pdo, 'order_items', 'currency') ? 'oi.currency AS item_currency' : ("'" . addslashes($currency) . "' AS item_currency"),
    // ownership cols (needed for multi-seller detection)
    $hasOiSellerUserId ? 'oi.seller_user_id AS seller_user_id' : 'NULL AS seller_user_id',
    $hasOiSellerId     ? 'oi.seller_id AS seller_id'           : 'NULL AS seller_id',
    $columnExists($pdo, 'order_items', 'owner_user_id') ? 'oi.owner_user_id AS owner_user_id' : 'NULL AS owner_user_id',
    $columnExists($pdo, 'order_items', 'user_id')       ? 'oi.user_id AS user_id'             : 'NULL AS user_id',
    // fulfillment
    $columnExists($pdo, 'order_items', 'fulfillment_status') ? 'oi.fulfillment_status AS fulfillment_status' : "'pending' AS fulfillment_status",
    $columnExists($pdo, 'order_items', 'tracking_number')    ? 'oi.tracking_number AS tracking_number'       : "'' AS tracking_number",
    $columnExists($pdo, 'order_items', 'carrier')            ? 'oi.carrier AS carrier'                       : "'' AS carrier",
    $columnExists($pdo, 'order_items', 'processed_at')       ? 'oi.processed_at AS processed_at'             : 'NULL AS processed_at',
    $columnExists($pdo, 'order_items', 'shipped_at')         ? 'oi.shipped_at AS shipped_at'                 : 'NULL AS shipped_at',
    $columnExists($pdo, 'order_items', 'completed_at')       ? 'oi.completed_at AS completed_at'             : 'NULL AS completed_at',
    // snapshots
    $columnExists($pdo, 'order_items', 'strain_snapshot')      ? 'oi.strain_snapshot AS strain_snapshot'           : "'' AS strain_snapshot",
    $columnExists($pdo, 'order_items', 'species_snapshot')     ? 'oi.species_snapshot AS species_snapshot'         : "'' AS species_snapshot",
    $columnExists($pdo, 'order_items', 'cover_image_snapshot') ? 'oi.cover_image_snapshot AS cover_image_snapshot' : "'' AS cover_image_snapshot",
    // listing join
    $hasLsellerId ? 'l.seller_id AS listing_seller_id' : 'NULL AS listing_seller_id',
    $hasLTitle ? 'l.title AS listing_title_fallback' : ($hasLName ? 'l.name AS listing_title_fallback' : "'' AS listing_title_fallback"),
];
$bvSelectSql = 'SELECT ' . implode(', ', $bvItemSelect);

// Shared LEFT JOIN fragment for listings
$bvListingJoin = ($listingsExists && $hasOiListingId)
    ? ' LEFT JOIN listings l ON l.id = oi.listing_id'
    : '';

// ------------------------------------------------------------------
// Build WHERE ownership OR clauses (one set per candidate seller ID)
// Both $sellerUserId and $currentSellerId are tried so that
// users.id vs sellers.id mismatches are covered.
// ------------------------------------------------------------------
$bvCandidates = array_keys(array_filter([
    $sellerUserId    => $sellerUserId > 0,
    $currentSellerId => $currentSellerId > 0,
]));

$bvOwnerClauses = [];
$bvPrimaryParams = [':order_id' => $orderId];
$bvPIdx = 0;
foreach ($bvCandidates as $cid) {
    $bvPIdx++;
    $sfx = '_c' . $bvPIdx;
    if ($hasOiSellerUserId) {
        $bvOwnerClauses[] = 'oi.seller_user_id = :su' . $sfx;
        $bvPrimaryParams[':su' . $sfx] = $cid;
    }
    if ($hasOiSellerId) {
        $bvOwnerClauses[] = 'oi.seller_id = :si' . $sfx;
        $bvPrimaryParams[':si' . $sfx] = $cid;
    }
    if ($hasOiListingId && $hasLsellerId) {
        $bvOwnerClauses[] = 'l.seller_id = :ls' . $sfx;
        $bvPrimaryParams[':ls' . $sfx] = $cid;
    }
}
$bvOwnerWhere = $bvOwnerClauses !== []
    ? ' AND (' . implode(' OR ', $bvOwnerClauses) . ')'
    : ''; // no ownership columns → no filter (single-seller shop)

// ------------------------------------------------------------------
// Helper: normalise a raw DB row (title fallback + owner id scan)
// ------------------------------------------------------------------
$bvNormaliseRow = static function (array $row) use ($toPositiveIntOrNull): array {
    if ((!isset($row['item_title']) || trim((string)$row['item_title']) === '')
        && isset($row['listing_title_fallback'])
        && trim((string)$row['listing_title_fallback']) !== '') {
        $row['item_title'] = (string)$row['listing_title_fallback'];
    }
    return $row;
};

// ------------------------------------------------------------------
// PRIMARY query: seller-owned items only
// ------------------------------------------------------------------
$sellerVisibleItems = [];
try {
    $bvPrimarySql  = $bvSelectSql;
    $bvPrimarySql .= ' FROM orders o INNER JOIN order_items oi ON oi.order_id = o.id';
    $bvPrimarySql .= $bvListingJoin;
    $bvPrimarySql .= ' WHERE o.id = :order_id' . $bvOwnerWhere;
    $bvPrimarySql .= ' ORDER BY oi.id ASC';

    seller_order_detail_debug('seller_items_primary_query', [
        'sql'    => $bvPrimarySql,
        'params' => $bvPrimaryParams,
    ]);

    $bvPrimaryStmt = $pdo->prepare($bvPrimarySql);
    $bvPrimaryStmt->execute($bvPrimaryParams);
    $bvPrimaryRows = $bvPrimaryStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    seller_order_detail_debug('seller_items_primary_query_error', ['error' => $e->getMessage()]);
    $bvPrimaryRows = [];
}

seller_order_detail_debug('seller_items_primary_query_count', [
    'count'          => count($bvPrimaryRows),
    'sellerUserId'   => $sellerUserId,
    'currentSellerId'=> $currentSellerId,
    'orderId'        => $orderId,
    'ownerClauses'   => $bvOwnerClauses,
]);

foreach ($bvPrimaryRows as $bvRow) {
    $sellerVisibleItems[] = $bvNormaliseRow($bvRow);
}

// ------------------------------------------------------------------
// SAFE FALLBACK: if primary returned nothing AND all safety conditions
// are met, check whether the entire order has exactly 1 item.
// Only use that single item if it is the only item in the order.
// This handles schemas where no ownership column is populated at all
// but the orderContext already confirmed seller access.
// ------------------------------------------------------------------
$bvFallbackUsed = false;
if ($sellerVisibleItems === [] && is_array($orderContext) && $orderContext !== []) {
    // Count ALL items for this order (no ownership filter)
    $bvFallbackTotalCount = 0;
    $bvFallbackRows       = [];
    try {
        $bvCountSql  = $bvSelectSql;
        $bvCountSql .= ' FROM order_items oi';
        $bvCountSql .= $bvListingJoin;
        $bvCountSql .= ' WHERE oi.order_id = :order_id ORDER BY oi.id ASC';
        $bvCountStmt = $pdo->prepare($bvCountSql);
        $bvCountStmt->execute([':order_id' => $orderId]);
        $bvFallbackRows = $bvCountStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $bvFallbackTotalCount = count($bvFallbackRows);
    } catch (Throwable $e) {
        seller_order_detail_debug('seller_items_fallback_count_error', ['error' => $e->getMessage()]);
        $bvFallbackRows       = [];
        $bvFallbackTotalCount = 0;
    }

    seller_order_detail_debug('seller_items_fallback_total_count', [
        'count'          => $bvFallbackTotalCount,
        'sellerUserId'   => $sellerUserId,
        'currentSellerId'=> $currentSellerId,
        'orderId'        => $orderId,
    ]);

    if ($bvFallbackTotalCount === 1) {
        // Exactly one item in this order → safe to show to the seller
        // (orderContext already passed the existing permission gate above)
        $bvFallbackUsed = true;
        foreach ($bvFallbackRows as $bvRow) {
            $sellerVisibleItems[] = $bvNormaliseRow($bvRow);
        }
        seller_order_detail_debug('seller_items_fallback_used', [
            'fallback_used'  => true,
            'sellerUserId'   => $sellerUserId,
            'currentSellerId'=> $currentSellerId,
            'orderId'        => $orderId,
        ]);
    } else {
        seller_order_detail_debug('seller_items_fallback_used', [
            'fallback_used'       => false,
            'reason'              => $bvFallbackTotalCount === 0 ? 'no_items_in_order' : 'multi_item_order_unsafe',
            'fallback_item_count' => $bvFallbackTotalCount,
            'sellerUserId'        => $sellerUserId,
            'currentSellerId'     => $currentSellerId,
            'orderId'             => $orderId,
        ]);
    }
}

// ------------------------------------------------------------------
// Collect observed owner IDs for multi-seller detection
// ------------------------------------------------------------------
$observedOwnerIds     = [];
$rowsWithNumericOwner = 0;
$totalItemRows        = count($sellerVisibleItems);
foreach ($sellerVisibleItems as $bvRow) {
    $bvFoundOwner = false;
    foreach (['seller_user_id', 'seller_id', 'listing_seller_id', 'owner_user_id', 'user_id'] as $bvOwnerField) {
        $bvOwnerVal = $toPositiveIntOrNull($bvRow[$bvOwnerField] ?? null);
        if ($bvOwnerVal !== null) {
            $observedOwnerIds[$bvOwnerVal] = true;
            $bvFoundOwner = true;
        }
    }
    if ($bvFoundOwner) {
        $rowsWithNumericOwner++;
    }
}

seller_order_detail_debug('seller_item_filter_result', [
    'visible_count'   => count($sellerVisibleItems),
    'fallback_used'   => $bvFallbackUsed,
    'sellerUserId'    => $sellerUserId,
    'currentSellerId' => $currentSellerId,
    'orderId'         => $orderId,
]);

$distinctOwnerIds = array_keys($observedOwnerIds);
$isMultiSellerOrder = count($distinctOwnerIds) > 1;
// Single-seller when: one distinct owner ID observed, OR fallback was used
// (fallback only fires for single-item orders so it's always single-seller)
$isClearlySingleSellerOrder = !$isMultiSellerOrder
    && $totalItemRows > 0
    && ($rowsWithNumericOwner === $totalItemRows || $bvFallbackUsed);

$sellerItemsSubtotal = null;
if (!empty($sellerVisibleItems)) {
    $sellerSubtotal = 0.0;
    foreach ($sellerVisibleItems as $itemRow) {
        $qty = 0;
        if (isset($itemRow['qty']) && is_numeric($itemRow['qty'])) {
            $qty = (int)$itemRow['qty'];
        } elseif (isset($itemRow['quantity']) && is_numeric($itemRow['quantity'])) {
            $qty = (int)$itemRow['quantity'];
        }

        $price = 0.0;
        if (isset($itemRow['unit_price']) && is_numeric($itemRow['unit_price'])) {
            $price = (float)$itemRow['unit_price'];
        } elseif (isset($itemRow['price']) && is_numeric($itemRow['price'])) {
            $price = (float)$itemRow['price'];
        }

        $sellerSubtotal += ($qty * $price);
    }
    $sellerItemsSubtotal = $sellerSubtotal;
}

$fullOrderTotal = null;
foreach (['order_total', 'grand_total', 'total_amount', 'total', 'amount_total'] as $totalKey) {
    if (isset($orderContext[$totalKey]) && is_numeric($orderContext[$totalKey])) {
        $fullOrderTotal = (float)$orderContext[$totalKey];
        break;
    }
}

$sellerVisibleCount = count($sellerVisibleItems);
$sellerVisibleListingTitle = '';

if ($sellerVisibleCount === 1) {
    foreach (['item_title', 'title', 'name', 'item_name', 'listing_title', 'product_name'] as $titleKey) {
        if (isset($sellerVisibleItems[0][$titleKey]) && trim((string)$sellerVisibleItems[0][$titleKey]) !== '') {
            $sellerVisibleListingTitle = trim((string)$sellerVisibleItems[0][$titleKey]);
            break;
        }
    }
}
if ($sellerVisibleCount > 1) {
    $firstTitle = '';
    foreach ($sellerVisibleItems as $itemRow) {
        if (!is_array($itemRow)) {
            continue;
        }
        foreach (['item_title', 'title', 'name', 'item_name', 'listing_title', 'product_name'] as $titleKey) {
            if (isset($itemRow[$titleKey]) && trim((string)$itemRow[$titleKey]) !== '') {
                $firstTitle = trim((string)$itemRow[$titleKey]);
                break 2;
            }
        }
    }
    if ($firstTitle !== '') {
        $sellerVisibleListingTitle = $firstTitle . ' +' . (string)($sellerVisibleCount - 1) . ' more';
    } else {
        $sellerVisibleListingTitle = (string)$sellerVisibleCount . ' seller-visible items';
    }
}
if ($sellerVisibleListingTitle === '') {
    $sellerVisibleListingTitle = $listingTitle !== '' ? $listingTitle : 'Listing unavailable';
}

$shippingStatus = $deriveShippingStatus($orderContext);
if ($shippingStatus === 'pending') {
    $deriveFromOrder = [
        'confirmed' => 'to_ship',
        'paid' => 'to_ship',
        'processing' => 'to_ship',
        'shipped' => 'shipped',
        'completed' => 'completed',
        'cancelled' => 'closed',
        'refunded' => 'closed',
    ];
    $shippingStatus = $deriveFromOrder[$orderStatus] ?? 'unknown';
}
$paymentBadgeUi = $paymentBadge($paymentStatus);
$shippingBadgeUi = $shippingBadge($shippingStatus);
$orderBadgeUi = $statusBadge('order', $orderStatus);

// Derive shipping status from seller-owned items (source of truth for this seller's view)
$sellerShippingDerived = bv_seller_order_detail_derive_shipping_status($sellerVisibleItems);

// Override the shipping badge with item-level data when items are available
if ($sellerShippingDerived['key'] !== 'unknown') {
    $shippingBadgeUi = [
        'label' => $sellerShippingDerived['label'],
        'class' => 'badge-default',
        '_derived_color' => $sellerShippingDerived['color'],
        '_derived_bg'    => $sellerShippingDerived['bg'],
    ];
}

// Optional: upgrade the Order Status badge if it shows Unknown but we have item-level data
if (($orderBadgeUi['label'] ?? 'Unknown') === 'Unknown' && $sellerShippingDerived['key'] !== 'unknown') {
    $derivedOrderLabel = [
        'pending'    => 'Paid',
        'processing' => 'Processing',
        'shipped'    => 'Shipped',
        'completed'  => 'Completed',
        'cancelled'  => 'Cancelled',
        'mixed'      => 'In Progress',
    ][$sellerShippingDerived['key']] ?? null;
    if ($derivedOrderLabel !== null) {
        $orderBadgeUi['label'] = $derivedOrderLabel;
    }
}
$requestActionEndpoint = '/seller/order_request_action.php';
$requestActionEndpointExists = false;
$requestActionCandidates = [
    __DIR__ . '/order_request_action.php',
    __DIR__ . '/includes/order_request_action.php',
    dirname(__DIR__) . '/seller/order_request_action.php',
    dirname(__DIR__) . '/order_request_action.php',
];
foreach ($requestActionCandidates as $candidatePath) {
    if (is_file($candidatePath)) {
        $requestActionEndpointExists = true;
        break;
    }
}

$fulfillmentActionEndpoint = '/seller/fulfillment_action.php';
$fulfillmentActionEndpointExists = false;
$fulfillmentActionCandidates = [
    __DIR__ . '/fulfillment_action.php',
    __DIR__ . '/includes/fulfillment_action.php',
    dirname(__DIR__) . '/seller/fulfillment_action.php',
    dirname(__DIR__) . '/fulfillment_action.php',
    // legacy fallback names
    __DIR__ . '/order_action.php',
    __DIR__ . '/includes/order_action.php',
    dirname(__DIR__) . '/seller/order_action.php',
    dirname(__DIR__) . '/order_action.php',
];
foreach ($fulfillmentActionCandidates as $candidatePath) {
    if (is_file($candidatePath)) {
        $fulfillmentActionEndpointExists = true;
        break;
    }
}

$returnUrl = '/seller/apply.php';
if (function_exists('seller_order_request_best_return_url')) {
    try {
        $candidate = (string)seller_order_request_best_return_url($orderId);
        if ($candidate !== '') {
            $returnUrl = $candidate;
        }
    } catch (Throwable $e) {
        $returnUrl = '/seller/apply.php';
    }
}

if ($returnUrl === '' || preg_match('~^https?://~i', $returnUrl)) {
    $returnUrl = '/seller/apply.php';
}
if ($returnUrl[0] !== '/') {
    $returnUrl = '/' . ltrim($returnUrl, '/');
}

$hasAnyRequest = $cancelRow !== null || $refundRow !== null;
$currentRow = $primaryType === 'cancel' ? $cancelRow : ($primaryType === 'refund' ? $refundRow : ($refundRow ?: $cancelRow));
$currentType = $primaryType !== '' ? $primaryType : ($refundRow ? 'refund' : ($cancelRow ? 'cancel' : 'none'));
$currentStatus = strtolower(trim((string)($currentRow['status'] ?? '')));
$currentDate = $pickDate($currentRow);
$currentReason = $pickValue($currentRow, ['cancel_reason_text', 'reason_text', 'reason', 'note', 'admin_note']);
$currentAmount = $pickValue($currentRow, ['approved_refund_amount', 'requested_refund_amount', 'actual_refunded_amount', 'refundable_amount', 'amount']);
$currentRefundMode = $pickValue($currentRow, ['refund_mode']);
$currentRefundRef = $pickValue($currentRow, ['payment_reference_snapshot', 'payment_reference', 'refund_reference', 'reference']);

$cancelBadge = $statusBadge('cancel', strtolower(trim((string)($cancelRow['status'] ?? ''))));
$refundBadge = $statusBadge('refund', strtolower(trim((string)($refundRow['status'] ?? ''))));

$paymentStatusKey = strtolower(trim((string)$paymentStatus));
$shippingStatusKey = strtolower(trim((string)$shippingStatus));
$lineFulfillmentActions = [];
foreach ($sellerVisibleItems as $idx => $sellerItemRow) {
    if (!is_array($sellerItemRow)) {
        continue;
    }
    $lineStatus = strtolower(trim((string)($sellerItemRow['fulfillment_status'] ?? 'pending')));
    if ($lineStatus === '') {
        $lineStatus = 'pending';
    }
    $actions = [];
    if ($lineStatus === 'pending' && in_array($paymentStatusKey, ['paid', 'authorized'], true) && in_array($orderStatus, ['paid', 'confirmed'], true)) {
        $actions[] = ['label' => 'Mark Processing', 'value' => 'mark_processing'];
    }
    if (in_array($lineStatus, ['processing', 'to_ship', 'pending'], true) && ($orderStatus === 'processing' || $shippingStatusKey === 'to_ship' || $shippingStatusKey === 'processing')) {
        $actions[] = ['label' => 'Mark Shipped', 'value' => 'mark_shipped'];
    }
    if ($lineStatus === 'shipped' || ($orderStatus === 'shipped' && $lineStatus !== 'completed')) {
        $actions[] = ['label' => 'Mark Completed', 'value' => 'mark_completed'];
    }
    $lineFulfillmentActions[$idx] = $actions;
}
$allowFulfillmentSubmit = !$isMultiSellerOrder && $isClearlySingleSellerOrder;
$canShowFulfillmentActionsByOrder = seller_order_detail_can_show_fulfillment_actions([
    'order_status' => $orderStatus,
    'payment_status' => $paymentStatus,
]);
$isOrderPaidForFulfillment = $canShowFulfillmentActionsByOrder;
$rawRequestUri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
$fulfillmentReturnUrl = '/seller/order_detail.php?id=' . $orderId;
if ($rawRequestUri !== '' && strpos($rawRequestUri, "\r") === false && strpos($rawRequestUri, "\n") === false && !preg_match('~^[a-z][a-z0-9+.-]*://~i', $rawRequestUri)) {
    if ($rawRequestUri[0] === '/') {
        $fulfillmentReturnUrl = $rawRequestUri;
    } else {
        $fulfillmentReturnUrl = '/' . ltrim($rawRequestUri, '/');
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Seller Order Detail</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #0b1020;
            --panel: #121a31;
            --panel-soft: #16203b;
            --text: #e7ecff;
            --muted: #95a1c7;
            --line: #2a3558;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
            background: radial-gradient(1200px 700px at 20% -10%, #1a2550 0%, var(--bg) 60%);
            color: var(--text);
        }
        .wrap {
            max-width: 1100px;
            margin: 28px auto;
            padding: 0 16px 28px;
        }
        .top-nav {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .btn-link {
            display: inline-block;
            padding: 10px 14px;
            border-radius: 10px;
            text-decoration: none;
            border: 1px solid var(--line);
            color: var(--text);
            background: var(--panel);
            font-weight: 600;
        }
        .card {
            background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 16px;
        }
        h1, h2, h3 {
            margin: 0 0 10px;
            line-height: 1.25;
        }
        h1 { font-size: 26px; }
        h2 { font-size: 19px; }
        h3 { font-size: 16px; color: #d4dcff; }
        .meta-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        }
        .meta-item {
            background: var(--panel-soft);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px;
        }
        .meta-item .k {
            display: block;
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .meta-item .v {
            font-size: 15px;
            font-weight: 600;
            word-break: break-word;
        }
        .flash {
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 12px;
            font-weight: 600;
        }
        .flash.success {
            background: rgba(46, 204, 113, 0.15);
            border: 1px solid rgba(46, 204, 113, 0.5);
            color: #baffd4;
        }
        .flash.error {
            background: rgba(231, 76, 60, 0.15);
            border: 1px solid rgba(231, 76, 60, 0.5);
            color: #ffd0cc;
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            border: 1px solid transparent;
            vertical-align: middle;
        }
        .offer-thread-badge-open { background: rgba(243, 156, 18, 0.2); border-color: rgba(243, 156, 18, 0.5); color: #ffd89a; }
        .offer-thread-badge-ready { background: rgba(127, 140, 255, 0.18); border-color: rgba(127, 140, 255, 0.5); color: #d8ddff; }
        .offer-thread-badge-completed { background: rgba(46, 204, 113, 0.18); border-color: rgba(46, 204, 113, 0.45); color: #c9ffdf; }
        .offer-thread-badge-needs-reply { background: rgba(231, 76, 60, 0.18); border-color: rgba(231, 76, 60, 0.45); color: #ffd2cd; }
        .badge-default { background: rgba(149, 161, 199, 0.18); border-color: rgba(149, 161, 199, 0.45); color: #dce4ff; }
        .fulfillment-panel { display: grid; gap: 7px; min-width: 230px; }
        .fulfillment-status { font-size: 12px; color: var(--muted); }
        .fulfillment-actions { display: grid; gap: 8px; }
        .fulfillment-form { display: grid; gap: 6px; }
        .fulfillment-note { font-size: 12px; color: var(--muted); }
        .stack { display: grid; gap: 10px; }  
        .muted { color: var(--muted); }
        .empty {
            border: 1px dashed var(--line);
            border-radius: 12px;
            padding: 16px;
            color: var(--muted);
            background: rgba(255, 255, 255, 0.02);
        }
        .actions {
            display: grid;
            gap: 12px;
            grid-template-columns: 1fr;
        }
        textarea {
            width: 100%;
            min-height: 110px;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: #0e152c;
            color: var(--text);
            padding: 12px;
            font-size: 14px;
            resize: vertical;
        }
        .action-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        button {
            appearance: none;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 11px 14px;
            font-weight: 700;
            color: var(--text);
            cursor: pointer;
            background: var(--panel-soft);
        }
        .btn-approve { border-color: rgba(46, 204, 113, 0.5); background: rgba(46, 204, 113, 0.16); }
        .btn-reject { border-color: rgba(231, 76, 60, 0.5); background: rgba(231, 76, 60, 0.16); }
        .split {
            display: grid;
            gap: 16px;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        }
        table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid var(--line);
            border-radius: 10px;
            overflow: hidden;
        }
        th, td {
            text-align: left;
            padding: 10px;
            border-bottom: 1px solid var(--line);
            font-size: 14px;
        }
        th {
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-size: 12px;
            background: var(--panel-soft);
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="top-nav">
        <a class="btn-link" href="<?= $h($returnUrl) ?>">← Back</a>
        <a class="btn-link" href="/seller/apply.php">Seller Dashboard</a>
    </div>

    <?php if ($flashSuccess !== ''): ?>
        <div class="flash success"><?= $h($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
        <div class="flash error"><?= $h($flashError) ?></div>
    <?php endif; ?>

   <?php if ($fulfillmentFlashType !== '' && $fulfillmentFlashMessage !== ''): ?>
        <div class="flash <?= $h($fulfillmentFlashType) ?>"><?= $h($fulfillmentFlashMessage) ?></div>
    <?php endif; ?>

    <section class="card">
         <h1>Seller Order Detail</h1>
        <div class="meta-grid">
            <div class="meta-item"><span class="k">Order Status</span><span class="v"><span class="badge <?= $h((string)($orderBadgeUi['class'] ?? 'badge-default')) ?>"><?= $h((string)($orderBadgeUi['label'] ?? 'Unknown')) ?></span></span></div>
            <div class="meta-item"><span class="k">Payment Status</span><span class="v"><span class="badge <?= $h((string)($paymentBadgeUi['class'] ?? 'badge-default')) ?>"><?= $h((string)($paymentBadgeUi['label'] ?? 'Unknown')) ?></span></span></div>
            <div class="meta-item"><span class="k">Shipping Status</span><span class="v"><?php
                if (isset($shippingBadgeUi['_derived_color'])):
                    $bvStyle = 'display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;border:1px solid transparent;vertical-align:middle;'
                             . 'color:' . htmlspecialchars($shippingBadgeUi['_derived_color'], ENT_QUOTES, 'UTF-8') . ';'
                             . 'background:' . htmlspecialchars($shippingBadgeUi['_derived_bg'], ENT_QUOTES, 'UTF-8') . ';'; ?>
                    <span style="<?= $bvStyle ?>"><?= $h((string)($shippingBadgeUi['label'] ?? 'Unknown')) ?></span>
                <?php else: ?>
                    <span class="badge <?= $h((string)($shippingBadgeUi['class'] ?? 'badge-default')) ?>"><?= $h((string)($shippingBadgeUi['label'] ?? 'Unknown')) ?></span>
                <?php endif; ?></span></div>
            <div class="meta-item"><span class="k">Buyer</span><span class="v"><?= $h($buyerName !== '' ? $buyerName : 'Unknown Buyer') ?></span></div> 
            <div class="meta-item"><span class="k">Listing</span><span class="v"><?= $h($sellerVisibleListingTitle) ?></span></div>
            <div class="meta-item"><span class="k">Your Items Subtotal</span><span class="v"><?= $h($sellerItemsSubtotal !== null ? $money($sellerItemsSubtotal, $currency) : '—') ?></span></div>
            <div class="meta-item"><span class="k">Full Order Total Snapshot</span><span class="v"><?= $h($fullOrderTotal !== null ? $money($fullOrderTotal, $currency) : '—') ?></span></div>
        </div>
    </section>

    <section class="card stack">
        <h2>Your Items</h2>
        <?php if (empty($sellerVisibleItems)): ?>
            <div class="empty">No seller-owned line items are visible for this order.</div>
        <?php else: ?>
             <table>
                <thead>
                <tr>
                    <th>Item ID</th>
                    <th>Listing ID</th>
                    <th>Item</th>
                    <th>Snapshot</th>
                    <th>Qty</th>
                    <th>Unit Price</th>
                    <th>Line Total</th>
                    <th>Status</th>
                    <th>Tracking</th>
                    <th>Carrier</th>
                    <th>Dates</th>
                    <th>Line Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($sellerVisibleItems as $itemIndex => $itemRow): ?>
                    <?php
                    $itemTitle = '';
                    foreach (['item_title', 'title', 'name', 'item_name', 'listing_title', 'product_name'] as $titleKey) {
                        if (isset($itemRow[$titleKey]) && trim((string)$itemRow[$titleKey]) !== '') {
                            $itemTitle = trim((string)$itemRow[$titleKey]);
                            break;
                        }
                    }
                    $itemQty = null;
                    foreach (['quantity', 'qty'] as $qtyKey) {
                        if (isset($itemRow[$qtyKey]) && is_numeric($itemRow[$qtyKey])) {
                            $itemQty = (float)$itemRow[$qtyKey];
                            break;
                        }
                    }
                     $itemUnitPrice = null;
                    foreach (['unit_price', 'price'] as $priceKey) {
                        if (isset($itemRow[$priceKey]) && is_numeric($itemRow[$priceKey])) {
                            $itemUnitPrice = (float)$itemRow[$priceKey];
                            break;
                        }
                    }
                    $itemCurrency = isset($itemRow['item_currency']) && trim((string)$itemRow['item_currency']) !== '' ? (string)$itemRow['item_currency'] : $currency;
                    $itemLineTotal = $lineTotalForItem($itemRow);
                    $lineStatusRaw = strtolower(trim((string)($itemRow['fulfillment_status'] ?? 'pending')));
                    $lineStatusBadge = $shippingBadge($lineStatusRaw !== '' ? $lineStatusRaw : 'unknown');
                    $snapParts = [];
                    if (!empty($itemRow['strain_snapshot'])) {
                        $snapParts[] = 'Strain: ' . trim((string)$itemRow['strain_snapshot']);
                    }
                    if (!empty($itemRow['species_snapshot'])) {
                        $snapParts[] = 'Species: ' . trim((string)$itemRow['species_snapshot']);
                    }
                    if (!empty($itemRow['cover_image_snapshot'])) {
                        $snapParts[] = 'Image snap available';
                    }
                    $dateParts = [];
                    foreach (['processed_at' => 'Processed', 'shipped_at' => 'Shipped', 'completed_at' => 'Completed'] as $dKey => $dLabel) {
                        if (!empty($itemRow[$dKey])) {
                            $dateParts[] = $dLabel . ': ' . (string)$itemRow[$dKey];
                        }
                    }
                    $lineItemId = isset($itemRow['item_id']) && is_numeric($itemRow['item_id']) ? (int)$itemRow['item_id'] : 0;
                    $lineActions = $lineFulfillmentActions[$itemIndex] ?? [];
                    ?>
                    <tr>
                        <td><?= $h(isset($itemRow['item_id']) ? (string)$itemRow['item_id'] : '—') ?></td>
                        <td><?= $h(isset($itemRow['listing_id']) ? (string)$itemRow['listing_id'] : '—') ?></td>
                        <td><?= $h($itemTitle !== '' ? $itemTitle : 'Item') ?></td>
                        <td><?= $h($snapParts !== [] ? implode(' | ', $snapParts) : '—') ?></td>
                        <td><?= $h($itemQty !== null ? (string)$itemQty : '—') ?></td>
                        <td><?= $h($itemUnitPrice !== null ? $money($itemUnitPrice, $itemCurrency) : '—') ?></td>
                        <td><?= $h($itemLineTotal !== null ? $money($itemLineTotal, $itemCurrency) : '—') ?></td>
                        <td><span class="badge <?= $h((string)($lineStatusBadge['class'] ?? 'badge-default')) ?>"><?= $h((string)($lineStatusBadge['label'] ?? 'Unknown')) ?></span></td>
                        <td><?= $h(!empty($itemRow['tracking_number']) ? (string)$itemRow['tracking_number'] : '—') ?></td>
                        <td><?= $h(!empty($itemRow['carrier']) ? (string)$itemRow['carrier'] : '—') ?></td>
                        <td><?= $h($dateParts !== [] ? implode(' | ', $dateParts) : '—') ?></td>
                        <td>
                            <?php
                            // Derive fulfillment status for this item
                            $itemFulfillmentStatus = strtolower(trim((string)($itemRow['fulfillment_status'] ?? '')));
                            if ($itemFulfillmentStatus === '') {
                                $itemFulfillmentStatus = 'pending';
                            }
                             $fulfillmentBadge = seller_order_detail_fulfillment_badge($itemFulfillmentStatus);
                            ?>
                            <div class="fulfillment-panel">
                                <div class="fulfillment-status">
                                    Fulfillment:
                                    <span class="badge" style="color:<?= $h($fulfillmentBadge['color']) ?>;background:<?= $h($fulfillmentBadge['bg']) ?>;">
                                        <?= $h($fulfillmentBadge['label']) ?>
                                    </span>
                                </div>
                                <?php if (seller_order_detail_fulfillment_value($itemRow, 'carrier') !== ''): ?>
                                    <div class="fulfillment-note">Carrier: <?= $h(seller_order_detail_fulfillment_value($itemRow, 'carrier')) ?></div>
                                <?php endif; ?>
                                <?php if (seller_order_detail_fulfillment_value($itemRow, 'tracking_number') !== ''): ?>
                                    <div class="fulfillment-note">Tracking: <?= $h(seller_order_detail_fulfillment_value($itemRow, 'tracking_number')) ?></div>
                                <?php endif; ?>
                                <?php if (seller_order_detail_fulfillment_value($itemRow, 'processed_at') !== ''): ?>
                                    <div class="fulfillment-note">Processed: <?= $h(seller_order_detail_fulfillment_value($itemRow, 'processed_at')) ?></div>
                                <?php endif; ?>
                                <?php if (seller_order_detail_fulfillment_value($itemRow, 'shipped_at') !== ''): ?>
                                    <div class="fulfillment-note">Shipped: <?= $h(seller_order_detail_fulfillment_value($itemRow, 'shipped_at')) ?></div>
                                <?php endif; ?>
                                <?php if (seller_order_detail_fulfillment_value($itemRow, 'completed_at') !== ''): ?>
                                    <div class="fulfillment-note">Completed: <?= $h(seller_order_detail_fulfillment_value($itemRow, 'completed_at')) ?></div>
                                <?php endif; ?>
                                <div class="fulfillment-actions">
                                <?php if ($lineItemId <= 0): ?>
                                    <span class="fulfillment-note">Item ID unavailable</span>
                                <?php elseif ($isMultiSellerOrder): ?>
                                    <span class="fulfillment-note">Multi-seller order</span>
                                <?php elseif (!$fulfillmentActionEndpointExists): ?>
                                    <span class="fulfillment-note">Endpoint unavailable</span>
                                <?php elseif (!$allowFulfillmentSubmit): ?>
                                    <span class="fulfillment-note">Read-only</span>
                                <?php elseif (!$isOrderPaidForFulfillment): ?>
                                    <span class="fulfillment-note">Fulfillment actions are available after payment is confirmed.</span>
                                <?php elseif ($itemFulfillmentStatus === 'pending'): ?>
                                    <form class="fulfillment-form" method="post" action="<?= $h($fulfillmentActionEndpoint) ?>">
                                        <input type="hidden" name="csrf_token" value="<?= $h($fulfillmentCsrf) ?>">
                                        <input type="hidden" name="order_item_id" value="<?= $h((string)$lineItemId) ?>">
                                        <input type="hidden" name="action" value="process">
                                        <input type="hidden" name="return_url" value="<?= $h($fulfillmentReturnUrl) ?>">
                                        <button type="submit" class="btn-approve" style="font-size:13px;padding:8px 12px;">Start Processing</button>
                                    </form>
                                    <form class="fulfillment-form" method="post" action="<?= $h($fulfillmentActionEndpoint) ?>">
                                        <input type="hidden" name="csrf_token" value="<?= $h($fulfillmentCsrf) ?>">
                                        <input type="hidden" name="order_item_id" value="<?= $h((string)$lineItemId) ?>">
                                        <input type="hidden" name="action" value="ship">
                                        <input type="hidden" name="return_url" value="<?= $h($fulfillmentReturnUrl) ?>">
                                        <input type="text" name="carrier" placeholder="Carrier (e.g. UPS)" value="<?= $h((string)($itemRow['carrier'] ?? '')) ?>" style="padding:7px 9px;border-radius:8px;border:1px solid var(--line);background:#0e152c;color:var(--text);font-size:13px;">
                                        <input type="text" name="tracking_number" placeholder="Tracking number (required)" value="<?= $h((string)($itemRow['tracking_number'] ?? '')) ?>" required style="padding:7px 9px;border-radius:8px;border:1px solid var(--line);background:#0e152c;color:var(--text);font-size:13px;">
                                        <input type="text" name="fulfillment_note" placeholder="Optional note" value="<?= $h((string)($itemRow['fulfillment_note'] ?? '')) ?>" style="padding:7px 9px;border-radius:8px;border:1px solid var(--line);background:#0e152c;color:var(--text);font-size:13px;">
                                        <button type="submit" class="btn-approve" style="font-size:13px;padding:8px 12px;">Mark Shipped</button>
                                    </form>
                                <?php elseif ($itemFulfillmentStatus === 'processing'): ?>
                                    <form class="fulfillment-form" method="post" action="<?= $h($fulfillmentActionEndpoint) ?>">
                                        <input type="hidden" name="csrf_token" value="<?= $h($fulfillmentCsrf) ?>">
                                        <input type="hidden" name="order_item_id" value="<?= $h((string)$lineItemId) ?>">
                                        <input type="hidden" name="action" value="ship">
                                        <input type="hidden" name="return_url" value="<?= $h($fulfillmentReturnUrl) ?>">
                                        <input type="text" name="carrier" placeholder="Carrier (e.g. UPS)" value="<?= $h((string)($itemRow['carrier'] ?? '')) ?>" style="padding:7px 9px;border-radius:8px;border:1px solid var(--line);background:#0e152c;color:var(--text);font-size:13px;">
                                        <input type="text" name="tracking_number" placeholder="Tracking number (required)" value="<?= $h((string)($itemRow['tracking_number'] ?? '')) ?>" required style="padding:7px 9px;border-radius:8px;border:1px solid var(--line);background:#0e152c;color:var(--text);font-size:13px;">
                                        <input type="text" name="fulfillment_note" placeholder="Optional note" value="<?= $h((string)($itemRow['fulfillment_note'] ?? '')) ?>" style="padding:7px 9px;border-radius:8px;border:1px solid var(--line);background:#0e152c;color:var(--text);font-size:13px;">
                                        <button type="submit" class="btn-approve" style="font-size:13px;padding:8px 12px;">Mark Shipped</button>
                                    </form>
                                <?php elseif ($itemFulfillmentStatus === 'shipped'): ?>
                                    <form class="fulfillment-form" method="post" action="<?= $h($fulfillmentActionEndpoint) ?>">
                                        <input type="hidden" name="csrf_token" value="<?= $h($fulfillmentCsrf) ?>">
                                        <input type="hidden" name="order_item_id" value="<?= $h((string)$lineItemId) ?>">
                                        <input type="hidden" name="action" value="complete">
                                        <input type="hidden" name="return_url" value="<?= $h($fulfillmentReturnUrl) ?>">
                                        <button type="submit" class="btn-approve" style="font-size:13px;padding:8px 12px;">Mark Completed</button>
                                    </form>
                                <?php elseif ($itemFulfillmentStatus === 'completed'): ?>
                                    <span class="fulfillment-note" style="color:#c9ffdf;font-weight:600;">✓ Completed</span>
                                <?php elseif ($itemFulfillmentStatus === 'cancelled'): ?>
                                    <span class="fulfillment-note" style="color:#ffd2cd;font-weight:600;">✕ Cancelled</span>
                                <?php else: ?>
                                    <span class="fulfillment-note">No actions</span>
                                <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="card stack">
        <h2>Request Status</h2>
        <?php if (!$hasAnyRequest): ?>
            <div class="empty">No cancel or refund request for this order.</div>
        <?php else: ?>
            <?php $activeBadge = $statusBadge($currentType === 'none' ? 'refund' : $currentType, $currentStatus); ?>
            <div class="meta-grid">
                <div class="meta-item"><span class="k">Request Type</span><span class="v"><?= $h($currentType !== 'none' ? ucfirst($currentType) : '—') ?></span></div>
                <div class="meta-item"><span class="k">Current Status</span><span class="v"><span class="badge <?= $h((string)($activeBadge['class'] ?? 'badge-default')) ?>"><?= $h((string)($activeBadge['label'] ?? 'Unknown')) ?></span></span></div>
                <div class="meta-item"><span class="k">Requested Date</span><span class="v"><?= $h($currentDate !== '' ? $currentDate : '—') ?></span></div>
                <div class="meta-item"><span class="k">Reason</span><span class="v"><?= $h($currentReason !== '' ? $currentReason : '—') ?></span></div>
                <div class="meta-item"><span class="k">Amount</span><span class="v"><?= $h($currentAmount !== '' && is_numeric($currentAmount) ? $money($currentAmount, $currency) : '—') ?></span></div>
                <div class="meta-item"><span class="k">Refund Mode</span><span class="v"><?= $h($currentRefundMode !== '' ? $currentRefundMode : '—') ?></span></div>
                <div class="meta-item"><span class="k">Refund Reference</span><span class="v"><?= $h($currentRefundRef !== '' ? $currentRefundRef : '—') ?></span></div>
            </div>
        <?php endif; ?>
    </section>

    <section class="split">
          <div class="card stack">
            <h3>Cancel Request</h3>
            <div class="muted">Order-level request data (may include non-seller-line context).</div>
            <?php if ($cancelRow): ?>
                <div class="meta-grid">
                    <div class="meta-item"><span class="k">Status</span><span class="v"><span class="badge <?= $h((string)($cancelBadge['class'] ?? 'badge-default')) ?>"><?= $h((string)($cancelBadge['label'] ?? 'Unknown')) ?></span></span></div>
                    <div class="meta-item"><span class="k">Source</span><span class="v"><?= $h($pickValue($cancelRow, ['cancel_source']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Reason Code</span><span class="v"><?= $h($pickValue($cancelRow, ['cancel_reason_code']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Reason Text</span><span class="v"><?= $h($pickValue($cancelRow, ['cancel_reason_text', 'reason_text', 'reason']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Admin Note</span><span class="v"><?= $h($pickValue($cancelRow, ['admin_note']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Refundable Amount</span><span class="v"><?= $h(($pickValue($cancelRow, ['refundable_amount']) !== '' && is_numeric($pickValue($cancelRow, ['refundable_amount']))) ? $money($pickValue($cancelRow, ['refundable_amount']), $currency) : '—') ?></span></div>
                    <div class="meta-item"><span class="k">Refund Status</span><span class="v"><?= $h($pickValue($cancelRow, ['refund_status']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Requested At</span><span class="v"><?= $h($pickDate($cancelRow) ?: '—') ?></span></div>
                </div>
            <?php else: ?>
                <div class="empty">No cancel request found for this order.</div>
            <?php endif; ?>
        </div>

         <div class="card stack">
            <h3>Refund Request</h3>
            <div class="muted">Order-level request data (may include non-seller-line context).</div>
            <?php if ($refundRow): ?>
                <div class="meta-grid">
                    <div class="meta-item"><span class="k">Refund Code</span><span class="v"><?= $h($pickValue($refundRow, ['refund_code']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Status</span><span class="v"><span class="badge <?= $h((string)($refundBadge['class'] ?? 'badge-default')) ?>"><?= $h((string)($refundBadge['label'] ?? 'Unknown')) ?></span></span></div>
                    <div class="meta-item"><span class="k">Refund Mode</span><span class="v"><?= $h($pickValue($refundRow, ['refund_mode']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Requested Amount</span><span class="v"><?= $h(($pickValue($refundRow, ['requested_refund_amount']) !== '' && is_numeric($pickValue($refundRow, ['requested_refund_amount']))) ? $money($pickValue($refundRow, ['requested_refund_amount']), $currency) : '—') ?></span></div>
                    <div class="meta-item"><span class="k">Approved Amount</span><span class="v"><?= $h(($pickValue($refundRow, ['approved_refund_amount']) !== '' && is_numeric($pickValue($refundRow, ['approved_refund_amount']))) ? $money($pickValue($refundRow, ['approved_refund_amount']), $currency) : '—') ?></span></div>
                    <div class="meta-item"><span class="k">Actual Refunded</span><span class="v"><?= $h(($pickValue($refundRow, ['actual_refunded_amount']) !== '' && is_numeric($pickValue($refundRow, ['actual_refunded_amount']))) ? $money($pickValue($refundRow, ['actual_refunded_amount']), $currency) : '—') ?></span></div>
                    <div class="meta-item"><span class="k">Payment Provider</span><span class="v"><?= $h($pickValue($refundRow, ['payment_provider']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Reference</span><span class="v"><?= $h($pickValue($refundRow, ['payment_reference_snapshot', 'payment_reference', 'refund_reference']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Admin/Internal Note</span><span class="v"><?= $h($pickValue($refundRow, ['admin_note', 'internal_note', 'note']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Requested At</span><span class="v"><?= $h($pickDate($refundRow) ?: '—') ?></span></div>
                </div>
            <?php else: ?>
                <div class="empty">No refund request found for this order.</div>
            <?php endif; ?>
        </div>
    </section>

    <section class="card actions">
        <h2>Take Action</h2>
        <div class="stack">
            <h3>Cancel / Refund Request Actions</h3>
            <?php if (!$requestActionEndpointExists): ?>
                <div class="empty">Read-only mode: seller request action endpoint is not available in this environment.</div>
            <?php elseif (!$canApproveCancel && !$canRejectCancel && !$canApproveRefund && !$canRejectRefund): ?>
                <div class="empty">No actions are currently available for this request state.</div>
            <?php else: ?>
                <form method="post" action="<?= $h($requestActionEndpoint) ?>" class="stack">
                    <input type="hidden" name="order_id" value="<?= $h((string)$orderId) ?>">
                    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                    <label for="note" class="muted">Note (optional)</label>
                    <textarea id="note" name="note" placeholder="Add a note for approval/rejection logs"></textarea>
                    <div class="action-row">
                        <?php if ($canApproveCancel): ?>
                            <button type="submit" class="btn-approve" name="action" value="approve_cancel">Approve Cancel</button>
                        <?php endif; ?>
                        <?php if ($canRejectCancel): ?>
                            <button type="submit" class="btn-reject" name="action" value="reject_cancel">Reject Cancel</button>
                        <?php endif; ?>
                        <?php if ($canApproveRefund): ?>
                            <button type="submit" class="btn-approve" name="action" value="approve_refund">Approve Refund</button>
                        <?php endif; ?>
                        <?php if ($canRejectRefund): ?>
                            <button type="submit" class="btn-reject" name="action" value="reject_refund">Reject Refund</button>
                        <?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <div class="stack">
             <h3>Order Fulfillment Actions</h3>
            <?php if ($isMultiSellerOrder): ?>
                <div class="empty">Multi-seller fulfillment actions are not enabled yet for this environment.</div>
            <?php elseif (!$fulfillmentActionEndpointExists): ?>
                <div class="empty">Fulfillment actions are not enabled yet for this environment.</div>
            <?php elseif (!$allowFulfillmentSubmit): ?>
                <div class="empty">Read-only mode: fulfillment actions require a clearly single-seller order in this environment.</div>
            <?php else: ?>
                <div class="empty">Use line-level controls in the “Your Items” table for safe fulfillment updates.</div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php if (isset($_GET['debug']) && $_GET['debug'] === '1'): ?>
<?php
// -----------------------------------------------------------------------
// TEMPORARY DEBUG PANEL - visible only when ?debug=1
// All queries here are diagnostic only and do NOT affect normal rendering.
// -----------------------------------------------------------------------

// --- Diagnostic A: all order_items for this order, no seller filter ---
$_dbgRawItems = [];
$_dbgRawError = '';
$_dbgRawSql   = '';
try {
    $_dbgSelCols = ['oi.id', 'oi.order_id'];
    if ($hasOiListingId)    { $_dbgSelCols[] = 'oi.listing_id'; }
    if ($hasOiSellerId)     { $_dbgSelCols[] = 'oi.seller_id'; }
    if ($hasOiSellerUserId) { $_dbgSelCols[] = 'oi.seller_user_id'; }
    foreach (['title','name','price','unit_price','line_total','subtotal','quantity','qty'] as $_dbgCol) {
        if ($columnExists($pdo, 'order_items', $_dbgCol)) { $_dbgSelCols[] = 'oi.' . $_dbgCol; }
    }
    if ($hasLsellerId) { $_dbgSelCols[] = 'l.seller_id AS l_seller_id'; }
    if ($hasLTitle)    { $_dbgSelCols[] = 'l.title AS l_title'; }
    elseif ($hasLName) { $_dbgSelCols[] = 'l.name AS l_title'; }

    $_dbgRawSql  = 'SELECT ' . implode(', ', $_dbgSelCols) . ' FROM order_items oi';
    if ($listingsExists && $hasOiListingId) {
        $_dbgRawSql .= ' LEFT JOIN listings l ON l.id = oi.listing_id';
    }
    $_dbgRawSql .= ' WHERE oi.order_id = :order_id ORDER BY oi.id ASC';
    $_dbgStmtA = $pdo->prepare($_dbgRawSql);
    $_dbgStmtA->execute([':order_id' => $orderId]);
    $_dbgRawItems = $_dbgStmtA->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $_dbgExA) {
    $_dbgRawError = $_dbgExA->getMessage();
}

// --- Diagnostic B: the orders row itself ---
$_dbgOrderRow = [];
try {
    $_dbgStmtB = $pdo->prepare('SELECT * FROM orders WHERE id = :oid LIMIT 1');
    $_dbgStmtB->execute([':oid' => $orderId]);
    $_dbgOrderRow = $_dbgStmtB->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $_dbgExB) { $_dbgOrderRow = ['query_error' => $_dbgExB->getMessage()]; }

// --- Diagnostic C: all columns in order_items ---
$_dbgOiCols = [];
try {
    $_dbgStmtC = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t ORDER BY ORDINAL_POSITION');
    $_dbgStmtC->execute([':t' => 'order_items']);
    $_dbgOiCols = array_column($_dbgStmtC->fetchAll(PDO::FETCH_ASSOC) ?: [], 'COLUMN_NAME');
} catch (Throwable $_dbgExC) { $_dbgOiCols = ['error: ' . $_dbgExC->getMessage()]; }

// --- Diagnostic D: sellers table ---
$_dbgSellersRows = [];
$_dbgSellersTableExists = $tableExists($pdo, 'sellers');
if ($_dbgSellersTableExists) {
    try {
        $_dbgFoundSellers = false;
        if ($sellerUserId > 0) {
            foreach (['user_id','member_id','owner_id','id'] as $_dbgSk) {
                if ($columnExists($pdo, 'sellers', $_dbgSk)) {
                    $_dbgStmtD = $pdo->prepare('SELECT * FROM sellers WHERE ' . $_dbgSk . ' = :uid LIMIT 5');
                    $_dbgStmtD->execute([':uid' => $sellerUserId]);
                    $_dbgSellersRows = $_dbgStmtD->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    if ($_dbgSellersRows !== []) { $_dbgFoundSellers = true; break; }
                }
            }
        }
        if (!$_dbgFoundSellers) {
            $_dbgStmtD2 = $pdo->prepare('SELECT * FROM sellers LIMIT 10');
            $_dbgStmtD2->execute();
            $_dbgSellersRows = $_dbgStmtD2->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $_dbgExD) { $_dbgSellersRows = [['query_error' => $_dbgExD->getMessage()]]; }
}

// --- Diagnostic E: seller_applications ---
$_dbgSellerAppsRows = [];
$_dbgSaTableExists = $tableExists($pdo, 'seller_applications');
if ($_dbgSaTableExists && $sellerUserId > 0) {
    try {
        foreach (['user_id','member_id','owner_id'] as $_dbgSak) {
            if ($columnExists($pdo, 'seller_applications', $_dbgSak)) {
                $_dbgStmtE = $pdo->prepare('SELECT * FROM seller_applications WHERE ' . $_dbgSak . ' = :uid LIMIT 5');
                $_dbgStmtE->execute([':uid' => $sellerUserId]);
                $_dbgSellerAppsRows = $_dbgStmtE->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if ($_dbgSellerAppsRows !== []) break;
            }
        }
    } catch (Throwable $_dbgExE) { $_dbgSellerAppsRows = [['query_error' => $_dbgExE->getMessage()]]; }
}

// Render helpers
$_dbgTable = static function (array $rows, string $title) use ($h): void {
    echo '<h4 style="margin:12px 0 4px;color:#facc15;">' . $h($title) . ' (' . count($rows) . ' row' . (count($rows) !== 1 ? 's' : '') . ')</h4>';
    if ($rows === []) { echo '<p style="color:#94a3b8;font-size:12px;">No rows.</p>'; return; }
    $cols = array_keys($rows[0]);
    echo '<div style="overflow-x:auto"><table style="border-collapse:collapse;font-size:12px;width:auto;">';
    echo '<thead><tr>';
    foreach ($cols as $c) {
        echo '<th style="border:1px solid #334155;padding:4px 8px;background:#1e293b;color:#94a3b8;white-space:nowrap;">' . $h((string)$c) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($cols as $c) {
            $v = $r[$c] ?? '';
            echo '<td style="border:1px solid #334155;padding:4px 8px;color:#e2e8f0;white-space:nowrap;">' . $h((string)$v) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
};

$_dbgKv = static function (string $key, $val) use ($h): void {
    if (is_bool($val))       { $display = $val ? 'true' : 'false'; $color = $val ? '#4ade80' : '#f87171'; }
    elseif (is_null($val))   { $display = 'null'; $color = '#94a3b8'; }
    else                     { $display = (string)$val; $color = '#e2e8f0'; }
    echo '<tr>'
       . '<td style="padding:3px 10px;color:#94a3b8;white-space:nowrap;border-bottom:1px solid #1e293b;font-size:12px;">' . $h($key) . '</td>'
       . '<td style="padding:3px 10px;color:' . $color . ';border-bottom:1px solid #1e293b;font-size:12px;word-break:break-all;">' . $h($display) . '</td>'
       . '</tr>';
};
?>
<div style="margin:20px auto;max-width:1100px;padding:0 16px 48px;">
<div style="background:#0f172a;border:2px solid #f59e0b;border-radius:14px;padding:22px;font-family:ui-monospace,monospace;">
<h2 style="margin:0 0 18px;color:#f59e0b;font-size:17px;">&#128269; DEBUG PANEL &nbsp;&mdash;&nbsp; <?= $h($_SERVER['REQUEST_URI'] ?? '') ?></h2>

<h3 style="color:#fb923c;margin:14px 0 5px;font-size:14px;">Identity &amp; Session</h3>
<table style="border-collapse:collapse;width:100%;">
<?php
$_dbgKv('orderId (from ?id=)',                     $orderId);
$_dbgKv('sellerUserId (resolved from session)',     $sellerUserId);
$_dbgKv('currentSellerId (helper func)',            $currentSellerId);
$_dbgKv('helperSellerUserId (helper func)',         $helperSellerUserId);
$_dbgKv('SESSION user.id',         $_SESSION['user']['id'] ?? '(not set)');
$_dbgKv('SESSION auth_user.id',    $_SESSION['auth_user']['id'] ?? '(not set)');
$_dbgKv('SESSION member.id',       $_SESSION['member']['id'] ?? '(not set)');
$_dbgKv('SESSION seller.id',       $_SESSION['seller']['id'] ?? '(not set)');
$_dbgKv('SESSION user_id',         $_SESSION['user_id'] ?? '(not set)');
$_dbgKv('SESSION member_id',       $_SESSION['member_id'] ?? '(not set)');
$_dbgKv('bvCandidates used in SQL', implode(', ', $bvCandidates ?? []) ?: '(empty - no IDs > 0)');
$_dbgKv('bvOwnerWhere fragment',    $bvOwnerWhere ?: '(empty - no ownership columns found)');
?>
</table>

<h3 style="color:#fb923c;margin:14px 0 5px;font-size:14px;">Detected Schema Columns</h3>
<table style="border-collapse:collapse;width:100%;">
<?php
$_dbgKv('order_items.seller_id',      $hasOiSellerId);
$_dbgKv('order_items.seller_user_id', $hasOiSellerUserId);
$_dbgKv('order_items.listing_id',     $hasOiListingId);
$_dbgKv('listings table',             $listingsExists);
$_dbgKv('listings.seller_id',         $hasLsellerId);
$_dbgKv('listings.title',             $hasLTitle);
$_dbgKv('listings.name',              $hasLName);
$_dbgKv('sellers table',              $_dbgSellersTableExists);
$_dbgKv('seller_applications table',  $_dbgSaTableExists);
$_dbgKv('order_items columns (all)',  implode(', ', $_dbgOiCols));
?>
</table>

<h3 style="color:#fb923c;margin:14px 0 5px;font-size:14px;">Query Outcomes</h3>
<table style="border-collapse:collapse;width:100%;">
<?php
$_dbgKv('Primary query row count',         count($bvPrimaryRows ?? []));
$_dbgKv('Fallback total item count',       isset($bvFallbackTotalCount) ? $bvFallbackTotalCount : '(fallback block not entered)');
$_dbgKv('Fallback used',                   $bvFallbackUsed);
$_dbgKv('sellerVisibleItems count (final)',count($sellerVisibleItems));
$_dbgKv('isMultiSellerOrder',              $isMultiSellerOrder);
$_dbgKv('isClearlySingleSellerOrder',      $isClearlySingleSellerOrder);
?>
</table>

<h3 style="color:#fb923c;margin:14px 0 5px;font-size:14px;">Primary SQL</h3>
<pre style="background:#1e293b;padding:10px;border-radius:8px;color:#a3e635;font-size:11px;overflow:auto;white-space:pre-wrap;"><?= $h($bvPrimarySql ?? '(not set)') ?></pre>
<p style="color:#64748b;font-size:11px;margin:2px 0 0;">Params: <?= $h(json_encode($bvPrimaryParams ?? [], JSON_UNESCAPED_SLASHES)) ?></p>

<h3 style="color:#fb923c;margin:14px 0 5px;font-size:14px;">Fallback SQL</h3>
<pre style="background:#1e293b;padding:10px;border-radius:8px;color:#a3e635;font-size:11px;overflow:auto;white-space:pre-wrap;"><?= $h(isset($bvCountSql) ? $bvCountSql : '(fallback block not entered - primary returned rows OR orderContext empty)') ?></pre>

<h3 style="color:#fb923c;margin:14px 0 5px;font-size:14px;">orderContext (all fields)</h3>
<table style="border-collapse:collapse;width:100%;">
<?php
if (is_array($orderContext)) {
    foreach ($orderContext as $_dbgOck => $_dbgOcv) {
        $_dbgKv((string)$_dbgOck, is_scalar($_dbgOcv) ? (string)$_dbgOcv : json_encode($_dbgOcv, JSON_UNESCAPED_SLASHES));
    }
} else {
    echo '<tr><td colspan="2" style="color:#f87171;padding:4px 10px;font-size:12px;">orderContext is not an array</td></tr>';
}
?>
</table>

<?php $_dbgTable($_dbgRawItems, 'DIAGNOSTIC: All order_items for order_id=' . $orderId . ' (no seller filter)'); ?>
<?php if ($_dbgRawError !== ''): ?>
    <p style="color:#f87171;font-size:12px;">Diagnostic query error: <?= $h($_dbgRawError) ?></p>
<?php endif; ?>
<p style="color:#475569;font-size:11px;margin:2px 0 8px;">SQL: <?= $h($_dbgRawSql) ?></p>

<?php $_dbgTable(array_values(array_filter([$_dbgOrderRow])), 'orders row for id=' . $orderId); ?>

<?php if ($_dbgSellersTableExists): ?>
    <?php $_dbgTable($_dbgSellersRows, 'sellers rows (searched by user_id/member_id/owner_id = sellerUserId=' . $sellerUserId . ', or first 10)'); ?>
<?php else: ?>
    <p style="color:#94a3b8;font-size:12px;">sellers table does not exist in this schema.</p>
<?php endif; ?>

<?php if ($_dbgSaTableExists): ?>
    <?php $_dbgTable($_dbgSellerAppsRows, 'seller_applications rows matching sellerUserId=' . $sellerUserId); ?>
<?php else: ?>
    <p style="color:#94a3b8;font-size:12px;">seller_applications table does not exist in this schema.</p>
<?php endif; ?>

<h3 style="color:#fb923c;margin:14px 0 5px;font-size:14px;">Auto-Diagnosis</h3>
<ul style="color:#e2e8f0;font-size:13px;line-height:2;margin:0;padding-left:20px;">
<?php
$_dbgPrimaryCount  = count($bvPrimaryRows ?? []);
$_dbgFallbackCount = $bvFallbackTotalCount ?? -1;
$_dbgDiagCount     = count($_dbgRawItems);

if ($_dbgPrimaryCount > 0) {
    echo '<li style="color:#4ade80;">✓ Primary query returned ' . $_dbgPrimaryCount . ' row(s). Items should be visible. If still empty, a rendering bug may exist.</li>';
} else {
    if (!$hasOiSellerId && !$hasOiSellerUserId && !$hasLsellerId) {
        echo '<li style="color:#facc15;">⚠ No ownership columns found. bvOwnerWhere is empty, so primary fetches ALL items. Count=' . $_dbgPrimaryCount . '. Check if order_items.order_id matches orderId=' . (int)$orderId . '.</li>';
    } else {
        echo '<li style="color:#f87171;">✗ Primary returned 0. Ownership columns exist but session IDs [' . htmlspecialchars(implode(', ', $bvCandidates ?? []), ENT_QUOTES) . '] do not match DB values.</li>';
    }
}

if ($_dbgDiagCount === 0) {
    echo '<li style="color:#f87171;">✗ Diagnostic query found 0 items for order_id=' . (int)$orderId . '. Possible: (1) ?id= is not orders.id (may be item ID or order code), (2) order_items.order_id stored differently, (3) orders uses different PK.</li>';
} else {
    echo '<li style="color:#4ade80;">✓ Diagnostic found ' . $_dbgDiagCount . ' order_item row(s) for order_id=' . (int)$orderId . '.</li>';
    if ($_dbgRawItems !== []) {
        $fr = $_dbgRawItems[0];
        foreach (['seller_id','seller_user_id','l_seller_id'] as $_dk) {
            if (array_key_exists($_dk, $fr)) {
                $dbVal = $fr[$_dk];
                $match = in_array((int)$dbVal, array_map('intval', $bvCandidates ?? []), true);
                $icon  = $match ? '✓' : '✗';
                $col   = $match ? '#4ade80' : '#f87171';
                echo '<li style="color:' . $col . ';"><strong>' . htmlspecialchars($_dk, ENT_QUOTES) . ' = ' . htmlspecialchars((string)$dbVal, ENT_QUOTES) . '</strong> vs session candidates [' . htmlspecialchars(implode(', ', $bvCandidates ?? []), ENT_QUOTES) . '] &rarr; ' . ($match ? 'MATCH' : 'NO MATCH - THIS IS THE BUG') . '</li>';
            }
        }
    }
}

if ($_dbgFallbackCount === 0) {
    echo '<li style="color:#f87171;">✗ Fallback also found 0 items. Same root cause.</li>';
} elseif ($_dbgFallbackCount > 1) {
    echo '<li style="color:#facc15;">⚠ Fallback found ' . $_dbgFallbackCount . ' items - multi-item order, safely declined. Fix ownership matching.</li>';
} elseif ($bvFallbackUsed) {
    echo '<li style="color:#4ade80;">✓ Single-item fallback fired successfully.</li>';
} elseif ($_dbgFallbackCount === -1) {
    echo '<li style="color:#94a3b8;">ℹ Fallback block not entered (primary returned rows or orderContext empty).</li>';
}
?>
</ul>

<p style="color:#475569;font-size:11px;margin-top:20px;border-top:1px solid #1e293b;padding-top:10px;">
    ⚠ TEMPORARY DEBUG PANEL - visible only when ?debug=1 - remove before production.
</p>
</div>
</div>
<?php endif; // end debug=1 panel ?>
</body>
</html>
