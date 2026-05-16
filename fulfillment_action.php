<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$root = dirname(__DIR__);
$sellerRoot = __DIR__;

foreach ([
    $sellerRoot . '/_guard.php',
    $sellerRoot . '/guard.php',
    $root . '/includes/auth.php',
    $root . '/includes/auth_bootstrap.php',
    $root . '/config/db.php',
    $root . '/includes/db.php',
    $root . '/db.php',
] as $file) {
    if (is_file($file)) {
        require_once $file;
    }
}

function bvfa_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function bvfa_db(): PDO {
    foreach ([$GLOBALS['pdo'] ?? null, $GLOBALS['PDO'] ?? null, $GLOBALS['db'] ?? null, $GLOBALS['conn'] ?? null] as $db) {
        if ($db instanceof PDO) {
            return $db;
        }
    }
    throw new RuntimeException('PDO connection not found.');
}

function bvfa_current_seller_id(): int {
    foreach ([
        $_SESSION['user']['id'] ?? null,
        $_SESSION['auth_user']['id'] ?? null,
        $_SESSION['seller']['id'] ?? null,
        $_SESSION['member']['id'] ?? null,
        $_SESSION['seller_id'] ?? null,
        $_SESSION['user_id'] ?? null,
        $_SESSION['member_id'] ?? null,
    ] as $id) {
        if (is_numeric($id) && (int)$id > 0) {
            return (int)$id;
        }
    }
    return 0;
}

function bvfa_current_role(): string {
    $role = '';
    foreach ([
        $_SESSION['user']['role'] ?? null,
        $_SESSION['auth_user']['role'] ?? null,
        $_SESSION['member']['role'] ?? null,
        $_SESSION['seller']['role'] ?? null,
        $_SESSION['role'] ?? null,
    ] as $r) {
        if (is_string($r) && trim($r) !== '') {
            $role = strtolower(trim($r));
            break;
        }
    }

    if (in_array($role, ['super_admin', 'superadmin', 'owner', 'administrator'], true)) {
        return 'admin';
    }
    if ($role === 'vendor') {
        return 'seller';
    }
    return $role;
}

function bvfa_is_allowed_role(string $role): bool {
    return in_array($role, ['seller', 'admin'], true);
}

function bvfa_redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function bvfa_safe_return_url(): string {
    $url = trim((string)($_POST['return_url'] ?? '/seller/orders.php'));

    if ($url === '' || preg_match('~^[a-z][a-z0-9+\-.]*://~i', $url) || str_starts_with($url, '//')) {
        return '/seller/orders.php';
    }

    if (strpos($url, "\r") !== false || strpos($url, "\n") !== false) {
        return '/seller/orders.php';
    }

    if (!str_starts_with($url, '/')) {
        return '/seller/orders.php';
    }

    return $url;
}

function bvfa_redirect_target(int $orderId = 0): string {
    $safe = bvfa_safe_return_url();
    if ($safe !== '/seller/orders.php') {
        return $safe;
    }
    if ($orderId > 0) {
        return '/seller/order_detail.php?id=' . $orderId;
    }
    return '/seller/orders.php';
} 

function bvfa_flash(string $type, string $message): void {
    $_SESSION['seller_fulfillment_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function bvfa_columns(PDO $pdo, string $table): array {
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

function bvfa_has_col(PDO $pdo, string $table, string $col): bool {
    $cols = bvfa_columns($pdo, $table);
    return isset($cols[$col]);
}

function bvfa_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function bvfa_verify_csrf(): bool {
    $posted = (string)($_POST['csrf_token'] ?? '');

    if ($posted === '') {
        return false;
    }

    foreach ([
        $_SESSION['csrf_token'] ?? null,
        $_SESSION['_csrf_seller_order']['fulfillment'] ?? null,
        $_SESSION['_csrf_seller_fulfillment'] ?? null,
    ] as $token) {
        if (is_string($token) && $token !== '' && hash_equals($token, $posted)) {
            return true;
        }
    }

    return false;
}

function bvfa_fetch_item_for_update(PDO $pdo, int $orderItemId): ?array {
    $orderItemCols = bvfa_columns($pdo, 'order_items');
    $joinListing = isset($orderItemCols['listing_id']) && bvfa_table_exists($pdo, 'listings');

    // Buyer email: check which column exists in orders
    $orderCols = bvfa_columns($pdo, 'orders');
    $buyerEmailExpr = 'NULL';
    foreach (['buyer_email', 'email', 'customer_email', 'ship_email'] as $_bec) {
        if (isset($orderCols[$_bec])) {
            $buyerEmailExpr = "o.`{$_bec}`";
            break;
        }
    }
    // Buyer name: best-effort
    $buyerNameExpr = 'NULL';
    foreach (['buyer_name', 'customer_name', 'ship_name', 'name'] as $_bnc) {
        if (isset($orderCols[$_bnc])) {
            $buyerNameExpr = "o.`{$_bnc}`";
            break;
        }
    }

    $sql = "
        SELECT
            oi.*,
            o.status AS order_status,
            o.payment_status AS payment_status,
            o.order_code AS order_code,
            {$buyerEmailExpr} AS _buyer_email,
            {$buyerNameExpr} AS _buyer_name
            " . ($joinListing ? ", l.seller_id AS listing_seller_id, l.title AS _listing_title" : "") . "
       FROM order_items oi
        LEFT JOIN orders o ON o.id = oi.order_id
        " . ($joinListing ? "LEFT JOIN listings l ON l.id = oi.listing_id" : "") . "
        WHERE oi.id = :order_item_id
        LIMIT 1
        FOR UPDATE
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':order_item_id' => $orderItemId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function bvfa_log_item(PDO $pdo, int $orderItemId, string $action, string $oldStatus, string $newStatus, int $actorId): void {
    if (!bvfa_table_exists($pdo, 'order_item_logs')) {
        return;
    }

    try {
        $cols = bvfa_columns($pdo, 'order_item_logs');
        $insertCols = [];
        $values = [];
        $params = [];
        $push = static function(string $col, $val) use (&$cols, &$insertCols, &$values, &$params): void {
            if (isset($cols[$col])) {
                $insertCols[] = "`{$col}`";
                $values[] = '?';
                $params[] = $val;
            }
        };

        $actorType = strtolower(trim((string)bvfa_current_role())) === 'admin' ? 'admin' : 'seller';
        $push('order_item_id', $orderItemId);
        $push('order_id', (int)($_POST['_resolved_order_id'] ?? 0));
        $push('listing_id', (int)($_POST['_resolved_listing_id'] ?? 0));
        $push('seller_id', (int)($_POST['_resolved_seller_id'] ?? $actorId));
        $push('action', $action);
        $push('old_status', $oldStatus);
        $push('new_status', $newStatus);
        $push('actor_type', $actorType);
        $push('actor_id', $actorId);
        $push('note', trim((string)($_POST['fulfillment_note'] ?? '')));
        $push('ip_address', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        $push('user_agent', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));

        if (isset($cols['created_at'])) {
            $insertCols[] = '`created_at`';
            $values[] = 'NOW()';
        }

        if ($insertCols === []) {
            return;
        }

        $sql = 'INSERT INTO order_item_logs (' . implode(',', $insertCols) . ') VALUES (' . implode(',', $values) . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } catch (Throwable $e) {
        // best effort log; never break fulfillment updates
    }
}

function bvfa_order_action_blocked(string $orderStatus, string $paymentStatus): bool {
    $blockedOrder = ['pending', 'pending_payment', 'cancelled', 'canceled', 'refunded', 'failed'];
    $allowedOrder = ['paid', 'confirmed', 'processing', 'packing', 'shipped', 'completed'];
    $allowedPayment = ['paid', 'succeeded', 'complete', 'completed'];

    if ($orderStatus !== '' && in_array($orderStatus, $blockedOrder, true)) {
        return true;
    }
    if ($paymentStatus !== '' && in_array($paymentStatus, ['pending', 'pending_payment', 'cancelled', 'refunded', 'failed'], true)) {
        return true;
    }

    if ($orderStatus !== '' && !in_array($orderStatus, $allowedOrder, true)) {
        if ($paymentStatus !== '' && !in_array($paymentStatus, $allowedPayment, true)) {
            return true;
        }
    }
    return false;
}

function bvfa_update_order_rollup(PDO $pdo, int $orderId): void {
    if (!bvfa_has_col($pdo, 'orders', 'status')) {
        return;
    }

    $stmt = $pdo->prepare("
        SELECT fulfillment_status, COUNT(*) AS c
        FROM order_items
        WHERE order_id = ?
        GROUP BY fulfillment_status
    ");
    $stmt->execute([$orderId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        return;
    }

    $counts = [];
    $total = 0;

    foreach ($rows as $row) {
        $status = strtolower((string)($row['fulfillment_status'] ?? 'pending'));
        $count = (int)($row['c'] ?? 0);
        $counts[$status] = $count;
        $total += $count;
    }

    if ($total <= 0) {
        return;
    }

   $pendingCount = ($counts['pending'] ?? 0) + ($counts['processing'] ?? 0);
    $shippedOrCompleted = ($counts['shipped'] ?? 0) + ($counts['completed'] ?? 0);

    if (($counts['completed'] ?? 0) === $total) {
        $stmt = $pdo->prepare("UPDATE orders SET status = 'completed' WHERE id = ? AND status IN ('paid','confirmed','processing','packing','shipped')");
        $stmt->execute([$orderId]);
        return;
    }

    if ($shippedOrCompleted > 0 && $pendingCount === 0) {
        $stmt = $pdo->prepare("UPDATE orders SET status = 'shipped' WHERE id = ? AND status IN ('paid','confirmed','processing','packing')");
        $stmt->execute([$orderId]);
        return;
    }
}

// ---------------------------------------------------------------------------
// Shipped email helpers — buyer notification on fulfillment.shipped
// ---------------------------------------------------------------------------

function bvfa_mail_log(string $event, array $context = []): void
{
    $paths = [
        dirname(dirname(__DIR__)) . '/private_html/seller_fulfillment_mail.log',
        dirname(__DIR__) . '/seller_fulfillment_mail.log',
        __DIR__ . '/seller_fulfillment_mail.log',
    ];
    $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $event;
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
}

function bvfa_load_mailer(): void
{
    $root = dirname(__DIR__);
    foreach ([
        $root . '/includes/mailer.php',
        $root . '/includes/mail.php',
        $root . '/includes/mailer/mailer.php',
        $root . '/lib/mailer.php',
    ] as $f) {
        if (is_file($f)) {
            require_once $f;
            return;
        }
    }
}

function bvfa_find_buyer_email(array $item): string
{
    $email = trim((string)($item['_buyer_email'] ?? ''));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $email;
    }
    return '';
}

function bvfa_build_member_order_url(int $orderId): string
{
    if ($orderId <= 0) {
        return '/member/orders.php';
    }
    return '/member/order_detail.php?id=' . $orderId;
}

function bvfa_shipped_email_already_queued(PDO $pdo, int $orderItemId): bool
{
    if (!bvfa_table_exists($pdo, 'order_item_logs')) {
        return false;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM order_item_logs
            WHERE order_item_id = ? AND action = 'shipped_email_queued'
            LIMIT 1
        ");
        $stmt->execute([$orderItemId]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function bvfa_mark_shipped_email_queued(PDO $pdo, int $orderItemId, int $sellerId): void
{
    if (!bvfa_table_exists($pdo, 'order_item_logs')) {
        return;
    }
    try {
        $stmt = $pdo->prepare("
            INSERT INTO order_item_logs
                (order_item_id, action, old_status, new_status, actor_type, actor_id, created_at)
            VALUES
                (?, 'shipped_email_queued', 'shipped', 'shipped', 'seller', ?, NOW())
        ");
        $stmt->execute([$orderItemId, $sellerId]);
    } catch (Throwable $e) {
        bvfa_mail_log('shipped_email_mark_failed', [
            'error'         => $e->getMessage(),
            'order_item_id' => $orderItemId,
        ]);
    }
}

function bvfa_queue_shipped_email(array $ctx): array
{
    $buyerName  = trim((string)($ctx['buyer_name'] ?? ''));
    $orderCode  = trim((string)($ctx['order_code'] ?? ('Order #' . $ctx['order_id'])));
    $itemTitle  = trim((string)($ctx['item_title'] ?? ('Order item #' . $ctx['order_item_id'])));
    $carrier    = trim((string)($ctx['carrier'] ?? ''));
    $tracking   = trim((string)($ctx['tracking_number'] ?? ''));
    $shippedAt  = trim((string)($ctx['shipped_at'] ?? gmdate('Y-m-d H:i:s') . ' UTC'));
    $orderUrl   = (string)($ctx['order_url'] ?? bvfa_build_member_order_url((int)$ctx['order_id']));
    $to         = (string)($ctx['buyer_email'] ?? '');
    $greeting   = $buyerName !== '' ? $buyerName : 'Customer';

    $subject = 'Your Bettavaro order item has been shipped';

    $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    $html = "<html><body style='font-family:Arial,sans-serif;color:#222;'>"
        . "<p>Dear " . $esc($greeting) . ",</p>"
        . "<p>Great news! Your order item has been shipped.</p>"
        . "<table style='border-collapse:collapse;margin:12px 0;'>"
        . "<tr><td style='padding:4px 12px 4px 0;color:#555;'>Order</td><td><strong>" . $esc($orderCode) . "</strong></td></tr>"
        . "<tr><td style='padding:4px 12px 4px 0;color:#555;'>Item</td><td>" . $esc($itemTitle) . "</td></tr>"
        . "<tr><td style='padding:4px 12px 4px 0;color:#555;'>Tracking</td><td><strong>" . $esc($tracking) . "</strong></td></tr>"
        . "<tr><td style='padding:4px 12px 4px 0;color:#555;'>Carrier</td><td>" . $esc($carrier ?: '—') . "</td></tr>"
        . "<tr><td style='padding:4px 12px 4px 0;color:#555;'>Shipped</td><td>" . $esc($shippedAt) . "</td></tr>"
        . "</table>"
        . "<p><a href='" . $esc($orderUrl) . "' style='background:#1d4ed8;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block;'>View Your Order</a></p>"
        . "<p style='color:#888;font-size:13px;'>Thank you for shopping with Bettavaro.</p>"
        . "</body></html>";

    $text = "Dear {$greeting},\n\nYour order item has been shipped.\n\n"
        . "Order: {$orderCode}\n"
        . "Item: {$itemTitle}\n"
        . "Tracking: {$tracking}\n"
        . ($carrier !== '' ? "Carrier: {$carrier}\n" : '')
        . "Shipped: {$shippedAt}\n\n"
        . "View your order: {$orderUrl}\n\n"
        . "Thank you for shopping with Bettavaro.";

    $payload = [
        'queue_key' => 'fulfillment_shipped_' . $ctx['order_item_id'],
        'profile'   => 'default',
        'to'        => $to,
        'subject'   => $subject,
        'html'      => $html,
        'text'      => $text,
        'meta'      => [
            'event'           => 'fulfillment.shipped',
            'order_id'        => (int)$ctx['order_id'],
            'order_item_id'   => (int)$ctx['order_item_id'],
            'tracking_number' => $tracking,
        ],
    ];

    bvfa_load_mailer();

    if (function_exists('bv_queue_mail')) {
        bv_queue_mail($payload);
        return ['sent' => true, 'method' => 'bv_queue_mail'];
    }

    // Fallback: PHP mail()
    if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: noreply@bettavaro.com\r\n";
        @mail($to, $subject, $html, $headers);
        return ['sent' => true, 'method' => 'mail'];
    }

    return ['sent' => false, 'method' => 'none'];
}

// ---------------------------------------------------------------------------

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request method.');
    }

    if (!bvfa_verify_csrf()) {
        throw new RuntimeException('Invalid CSRF token.');
    }

    $pdo = bvfa_db();
    $sellerId = bvfa_current_seller_id();
    $role = bvfa_current_role();

    if ($sellerId <= 0) {
        throw new RuntimeException('Seller session not found.');
    }
    if (!bvfa_is_allowed_role($role)) {
        http_response_code(403);
        throw new RuntimeException('You are not allowed to perform this action.');
    }

    $orderItemId = (int)($_POST['order_item_id'] ?? 0);
    $action = strtolower(trim((string)($_POST['action'] ?? '')));

    if ($orderItemId <= 0) {
        throw new RuntimeException('Invalid order item.');
    }

    if (!in_array($action, ['process', 'ship', 'complete'], true)) {
        throw new RuntimeException('Invalid fulfillment action.');
    }

    if (!bvfa_has_col($pdo, 'order_items', 'fulfillment_status')) {
        throw new RuntimeException('Missing order_items.fulfillment_status column.');
    }

    $pdo->beginTransaction();

 $item = bvfa_fetch_item_for_update($pdo, $orderItemId);

    if (!$item) {
        throw new RuntimeException('Order item not found.');
    }

    $orderId = (int)($item['order_id'] ?? 0);
    $_POST['_resolved_order_id'] = (string)$orderId;
    $_POST['_resolved_listing_id'] = (string)((int)($item['listing_id'] ?? 0));
    $_POST['_resolved_seller_id'] = (string)((int)($item['listing_seller_id'] ?? ($item['seller_id'] ?? $sellerId)));

    if ($role === 'seller') {
        $itemSellerId = (int)($item['listing_seller_id'] ?? ($item['seller_id'] ?? ($item['seller_user_id'] ?? 0)));
        if ($itemSellerId <= 0 || $itemSellerId !== $sellerId) {
            http_response_code(403);
            throw new RuntimeException('Order item not found or not owned by this seller.');
        }
    }

    $oldStatus = strtolower(trim((string)($item['fulfillment_status'] ?? 'pending')));
    if ($oldStatus === '') {
        $oldStatus = 'pending';
    }

    $orderStatus = strtolower(trim((string)($item['order_status'] ?? '')));
    $paymentStatus = strtolower(trim((string)($item['payment_status'] ?? '')));

    if (bvfa_order_action_blocked($orderStatus, $paymentStatus)) {
        throw new RuntimeException('This order cannot be fulfilled in its current payment/status state.');
    } 

    $newStatus = $oldStatus;
    $updates = [];
    $params = [];

    if ($action === 'process') {
        if (!in_array($oldStatus, ['pending'], true)) {
            throw new RuntimeException('This item cannot be moved to processing.');
        }

       $newStatus = 'processing';
        $updates[] = "fulfillment_status = ?";
        $params[] = $newStatus;

        if (bvfa_has_col($pdo, 'order_items', 'processed_at')) {
            $updates[] = "processed_at = NOW()";
        }
    }

    if ($action === 'ship') {
         if (!in_array($oldStatus, ['pending', 'processing'], true)) {
            throw new RuntimeException('This item cannot be moved to shipped.');
        }

        $trackingNumber = trim((string)($_POST['tracking_number'] ?? ''));
        $carrier = trim((string)($_POST['carrier'] ?? ''));
        $fulfillmentNote = trim((string)($_POST['fulfillment_note'] ?? ''));

        if ($trackingNumber === '') {
            throw new RuntimeException('Tracking number is required to mark this item as shipped.');
        }

        $newStatus = 'shipped';
        $updates[] = "fulfillment_status = ?";
        $params[] = $newStatus;

        if (bvfa_has_col($pdo, 'order_items', 'tracking_number')) {
            $updates[] = "tracking_number = ?";
            $params[] = $trackingNumber;
        }

         if (bvfa_has_col($pdo, 'order_items', 'carrier')) {
            $updates[] = "carrier = ?";
            $params[] = $carrier;
        }
        if (bvfa_has_col($pdo, 'order_items', 'fulfillment_note')) {
            $updates[] = "fulfillment_note = ?";
            $params[] = $fulfillmentNote;
        }

        if (bvfa_has_col($pdo, 'order_items', 'shipped_at')) {
            $updates[] = "shipped_at = NOW()";
        }
    }

    if ($action === 'complete') {
        if (!in_array($oldStatus, ['shipped'], true)) {
            throw new RuntimeException('Item must be shipped before it can be completed.');
        }

        $newStatus = 'completed';
        $updates[] = "fulfillment_status = ?";
        $params[] = $newStatus;

        if (bvfa_has_col($pdo, 'order_items', 'completed_at')) {
            $updates[] = "completed_at = NOW()";
        }
    }

    if (!$updates) {
        throw new RuntimeException('Nothing to update.');
    }

    $params[] = $orderItemId;

    $sql = "UPDATE order_items SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

     bvfa_log_item($pdo, $orderItemId, $action, $oldStatus, $newStatus, $sellerId);

    if ($orderId > 0) {
        bvfa_update_order_rollup($pdo, $orderId);
    }

    $pdo->commit();

    // ------------------------------------------------------------------
    // Shipped email — queued/sent after DB commit so the transaction is
    // fully committed before any mail attempt. Failures do NOT roll back.
    // ------------------------------------------------------------------
    if ($action === 'ship') {
        try {
            $buyerEmail = bvfa_find_buyer_email($item);

            if ($buyerEmail === '') {
                bvfa_mail_log('missing_buyer_email', [
                    'order_item_id' => $orderItemId,
                    'order_id'      => $orderId,
                ]);
            } elseif (bvfa_shipped_email_already_queued($pdo, $orderItemId)) {
                bvfa_mail_log('shipped_email_already_queued', [
                    'order_item_id' => $orderItemId,
                    'order_id'      => $orderId,
                ]);
            } else {
                // Resolve item title — prefer oi columns, then listing, then snapshots
                $itemTitle = '';
                foreach (['title', 'name', 'item_title', 'product_name'] as $_tc) {
                    if (isset($item[$_tc]) && trim((string)$item[$_tc]) !== '') {
                        $itemTitle = trim((string)$item[$_tc]);
                        break;
                    }
                }
                if ($itemTitle === '' && isset($item['_listing_title']) && trim((string)$item['_listing_title']) !== '') {
                    $itemTitle = trim((string)$item['_listing_title']);
                }
                if ($itemTitle === '' && isset($item['strain_snapshot']) && trim((string)$item['strain_snapshot']) !== '') {
                    $itemTitle = trim((string)$item['strain_snapshot']);
                }
                if ($itemTitle === '') {
                    $itemTitle = 'Order item #' . $orderItemId;
                }

                $shippedAtValue = isset($item['shipped_at']) && trim((string)$item['shipped_at']) !== ''
                    ? trim((string)$item['shipped_at'])
                    : gmdate('Y-m-d H:i:s') . ' UTC';

                $emailCtx = [
                    'order_id'        => $orderId,
                    'order_item_id'   => $orderItemId,
                    'order_code'      => trim((string)($item['order_code'] ?? '')),
                    'buyer_name'      => trim((string)($item['_buyer_name'] ?? '')),
                    'buyer_email'     => $buyerEmail,
                    'item_title'      => $itemTitle,
                    'carrier'         => $carrier,
                    'tracking_number' => $trackingNumber,
                    'shipped_at'      => $shippedAtValue,
                    'order_url'       => bvfa_build_member_order_url($orderId),
                ];

                bvfa_mail_log('shipped_email_queue_attempt', [
                    'order_item_id' => $orderItemId,
                    'order_id'      => $orderId,
                    'to'            => $buyerEmail,
                ]);

                $mailResult = bvfa_queue_shipped_email($emailCtx);

                if ($mailResult['sent']) {
                    bvfa_mark_shipped_email_queued($pdo, $orderItemId, $sellerId);
                    bvfa_mail_log('shipped_email_queued', [
                        'order_item_id' => $orderItemId,
                        'order_id'      => $orderId,
                        'method'        => $mailResult['method'],
                        'to'            => $buyerEmail,
                    ]);
                } else {
                    bvfa_mail_log('shipped_email_failed', [
                        'order_item_id' => $orderItemId,
                        'order_id'      => $orderId,
                        'reason'        => 'mail_queue_not_available',
                    ]);
                    // Record in logs so duplicate check still works on retry
                    bvfa_mark_shipped_email_queued($pdo, $orderItemId, $sellerId);
                }
            }
        } catch (Throwable $mailEx) {
            // Email failure must never affect shipment outcome
            bvfa_mail_log('shipped_email_failed', [
                'order_item_id' => $orderItemId,
                'order_id'      => $orderId,
                'error'         => $mailEx->getMessage(),
            ]);
        }
    }
    // ------------------------------------------------------------------

    $successMessage = 'Fulfillment updated successfully.';
    if ($action === 'process') {
        $successMessage = 'Item marked as processing.';
    } elseif ($action === 'ship') {
        $successMessage = 'Item marked as shipped.';
    } elseif ($action === 'complete') {
        $successMessage = 'Item marked as completed.';
    }
    bvfa_flash('success', $successMessage);
    bvfa_redirect(bvfa_redirect_target($orderId));

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    bvfa_flash('error', $e->getMessage());
    bvfa_redirect(bvfa_redirect_target((int)($_POST['_resolved_order_id'] ?? 0)));
}