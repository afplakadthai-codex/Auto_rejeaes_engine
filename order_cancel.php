<?php
declare(strict_types=1);

/**
 * Bettavaro - Order Cancel Helper
 *
 * Purpose:
 * - Read orders + order_items safely
 * - Create cancel request into:
 *   - order_cancellations
 *   - order_cancellation_items
 * - Support approve / reject / complete flow
 * - Reverse stock safely (only once)
 * - Keep refund flow separate from cancel flow
 */

if (!function_exists('bvoc_boot')) {
    function bvoc_boot(): void
    {
        static $booted = false;
        if ($booted) {
            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $candidates = [
            dirname(__DIR__) . '/config/db.php',
            dirname(__DIR__) . '/includes/db.php',
            dirname(__DIR__) . '/db.php',
        ];

        foreach ($candidates as $file) {
            if (is_file($file)) {
                require_once $file;
                break;
            }
        }

        $booted = true;
    }
}

if (!function_exists('bvoc_db')) {
    function bvoc_db(): PDO
    {
        bvoc_boot();

        $candidates = [
            $GLOBALS['pdo'] ?? null,
            $GLOBALS['PDO'] ?? null,
            $GLOBALS['db'] ?? null,
            $GLOBALS['conn'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate instanceof PDO) {
                return $candidate;
            }
        }

        throw new RuntimeException('PDO connection not found.');
    }
}

if (!function_exists('bvoc_h')) {
    function bvoc_h($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('bvoc_now')) {
    function bvoc_now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('bvoc_num')) {
    function bvoc_num($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        return (float) $value;
    }
}

if (!function_exists('bvoc_int')) {
    function bvoc_int($value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        return (int) $value;
    }
}

if (!function_exists('bvoc_str')) {
    function bvoc_str($value): string
    {
        return trim((string) $value);
    }
}

if (!function_exists('bvoc_bool')) {
    function bvoc_bool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $value = strtolower(trim((string) $value));
        return in_array($value, ['1', 'true', 'yes', 'y', 'on'], true);
    }
}

if (!function_exists('bvoc_log')) {
    function bvoc_log(string $event, array $data = []): void
    {
        $candidates = [
            dirname(__DIR__) . '/private_html/order_cancel.log',
            dirname(__DIR__) . '/order_cancel.log',
            __DIR__ . '/../order_cancel.log',
        ];

        $line = '[' . date('Y-m-d H:i:s') . '] ' . $event . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

        foreach ($candidates as $file) {
            $dir = dirname($file);
            if (is_dir($dir) || @mkdir($dir, 0775, true)) {
                @file_put_contents($file, $line, FILE_APPEND);
                break;
            }
        }

        @error_log(trim($line));
    }
}

if (!function_exists('bvoc_table_exists')) {
    function bvoc_table_exists(PDO $pdo, string $table): bool
    {
        static $cache = [];

        $table = str_replace('`', '', trim($table));
        if ($table === '') {
            return false;
        }

        if (isset($cache[$table])) {
            return $cache[$table];
        }

        try {
            $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
            $cache[$table] = (bool) $stmt->fetchColumn();
            return $cache[$table];
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('bvoc_columns')) {
    function bvoc_columns(PDO $pdo, string $table): array
    {
        static $cache = [];

        $table = str_replace('`', '', trim($table));
        if ($table === '') {
            return [];
        }

        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $cols = [];
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($row['Field'])) {
                    $cols[(string) $row['Field']] = true;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }

        $cache[$table] = $cols;
        return $cols;
    }
}

if (!function_exists('bvoc_has_col')) {
    function bvoc_has_col(PDO $pdo, string $table, string $column): bool
    {
        $cols = bvoc_columns($pdo, $table);
        return isset($cols[$column]);
    }
}

if (!function_exists('bvoc_require_tables')) {
    function bvoc_require_tables(?PDO $pdo = null): bool
    {
        $pdo = $pdo ?: bvoc_db();

        $required = [
            'orders',
            'order_cancellations',
            'order_cancellation_items',
        ];

        foreach ($required as $table) {
            if (!bvoc_table_exists($pdo, $table)) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('bvoc_begin')) {
    function bvoc_begin(PDO $pdo): void
    {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }
    }
}

if (!function_exists('bvoc_commit')) {
    function bvoc_commit(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    }
}

if (!function_exists('bvoc_rollback')) {
    function bvoc_rollback(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

if (!function_exists('bvoc_current_user_id')) {
    function bvoc_current_user_id(): int
    {
        $candidates = [
            $_SESSION['user']['id'] ?? null,
            $_SESSION['auth_user']['id'] ?? null,
            $_SESSION['member']['id'] ?? null,
            $_SESSION['seller']['id'] ?? null,
            $_SESSION['admin']['id'] ?? null,
            $_SESSION['user_id'] ?? null,
            $_SESSION['member_id'] ?? null,
            $_SESSION['admin_id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                return (int) $candidate;
            }
        }

        return 0;
    }
}

if (!function_exists('bvoc_current_role')) {
    function bvoc_current_role(): string
    {
        $candidates = [
            $_SESSION['user']['role'] ?? null,
            $_SESSION['auth_user']['role'] ?? null,
            $_SESSION['member']['role'] ?? null,
            $_SESSION['seller']['role'] ?? null,
            $_SESSION['admin']['role'] ?? null,
            $_SESSION['role'] ?? null,
            $_SESSION['user_role'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $role = strtolower(trim((string) $candidate));
            if ($role !== '') {
                if (in_array($role, ['super_admin', 'superadmin', 'owner'], true)) {
                    return 'admin';
                }
                return $role;
            }
        }

        return 'guest';
    }
}

if (!function_exists('bvoc_is_admin_role')) {
    function bvoc_is_admin_role(?string $role = null): bool
    {
        $role = strtolower(trim((string) ($role ?? bvoc_current_role())));
        return in_array($role, ['admin', 'owner', 'superadmin', 'super_admin'], true);
    }
}

if (!function_exists('bvoc_is_seller_role')) {
    function bvoc_is_seller_role(?string $role = null): bool
    {
        $role = strtolower(trim((string) ($role ?? bvoc_current_role())));
        return $role === 'seller' || bvoc_is_admin_role($role);
    }
}

if (!function_exists('bvoc_normalize_cancel_source')) {
    function bvoc_normalize_cancel_source(?string $source = null, ?string $actorRole = null): string
    {
        $source = strtolower(trim((string) $source));
        $actorRole = strtolower(trim((string) ($actorRole ?? bvoc_current_role())));

        $allowed = ['buyer', 'seller', 'admin', 'system'];
        if (in_array($source, $allowed, true)) {
            return $source;
        }

        if (bvoc_is_admin_role($actorRole)) {
            return 'admin';
        }

        if ($actorRole === 'seller') {
            return 'seller';
        }

        if ($actorRole === 'system') {
            return 'system';
        }

        return 'buyer';
    }
}

if (!function_exists('bvoc_get_order_by_id')) {
    function bvoc_get_order_by_id(int $orderId, bool $forUpdate = false, ?PDO $pdo = null): ?array
    {
        $pdo = $pdo ?: bvoc_db();

        if ($orderId <= 0) {
            return null;
        }

        $sql = "SELECT * FROM orders WHERE id = ? LIMIT 1";
        if ($forUpdate) {
            $sql .= " FOR UPDATE";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}

if (!function_exists('bvoc_get_order_items')) {
    function bvoc_get_order_items(int $orderId, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?: bvoc_db();

        if ($orderId <= 0) {
            return [];
        }

        if (!bvoc_table_exists($pdo, 'order_items')) {
            return [];
        }

        $stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC");
        $stmt->execute([$orderId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('bvoc_get_cancellation_by_id')) {
    function bvoc_get_cancellation_by_id(int $cancellationId, ?PDO $pdo = null): ?array
    {
        $pdo = $pdo ?: bvoc_db();

        if ($cancellationId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare("SELECT * FROM order_cancellations WHERE id = ? LIMIT 1");
        $stmt->execute([$cancellationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}

if (!function_exists('bvoc_get_cancellation_items')) {
    function bvoc_get_cancellation_items(int $cancellationId, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?: bvoc_db();

        if ($cancellationId <= 0) {
            return [];
        }

        $stmt = $pdo->prepare("SELECT * FROM order_cancellation_items WHERE cancellation_id = ? ORDER BY id ASC");
        $stmt->execute([$cancellationId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('bvoc_get_latest_cancellation_by_order_id')) {
    function bvoc_get_latest_cancellation_by_order_id(int $orderId, ?PDO $pdo = null): ?array
    {
        $pdo = $pdo ?: bvoc_db();

        if ($orderId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare("SELECT * FROM order_cancellations WHERE order_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}

if (!function_exists('bvoc_has_open_cancellation_request')) {
    function bvoc_has_open_cancellation_request(int $orderId, ?PDO $pdo = null): bool
    {
        $pdo = $pdo ?: bvoc_db();

        if ($orderId <= 0) {
            return false;
        }

        $stmt = $pdo->prepare("
            SELECT id
            FROM order_cancellations
            WHERE order_id = ?
              AND status IN ('requested','approved')
            LIMIT 1
        ");
        $stmt->execute([$orderId]);

        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists('bvoc_order_payment_state')) {
    function bvoc_order_payment_state(array $order): string
    {
        $candidates = [
            $order['payment_status'] ?? null,
            $order['payment_state'] ?? null,
            $order['payment_method_status'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = strtolower(trim((string) $candidate));
            if ($value !== '') {
                return $value;
            }
        }

        $status = strtolower(trim((string) ($order['status'] ?? '')));
        if (in_array($status, ['paid', 'paid-awaiting-verify', 'processing', 'confirmed', 'packing', 'shipped', 'completed'], true)) {
            return 'paid';
        }

        if (in_array($status, ['pending_payment'], true)) {
            return 'pending_payment';
        }

        return 'unpaid';
    }
}

if (!function_exists('bvoc_order_currency')) {
    function bvoc_order_currency(array $order): string
    {
        $currency = strtoupper(trim((string) ($order['currency'] ?? 'USD')));
        return $currency !== '' ? $currency : 'USD';
    }
}

if (!function_exists('bvoc_order_source')) {
    function bvoc_order_source(array $order): string
    {
        return strtolower(trim((string) ($order['order_source'] ?? 'shop')));
    }
}

if (!function_exists('bvoc_order_status')) {
    function bvoc_order_status(array $order): string
    {
        return strtolower(trim((string) ($order['status'] ?? '')));
    }
}

if (!function_exists('bvoc_order_code')) {
    function bvoc_order_code(array $order): string
    {
        $candidates = [
            $order['order_code'] ?? null,
            $order['code'] ?? null,
            $order['reference'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}

if (!function_exists('bvoc_order_user_id')) {
    function bvoc_order_user_id(array $order): int
    {
        return bvoc_int($order['user_id'] ?? 0);
    }
}

if (!function_exists('bvoc_is_allowed_order_status_for_request')) {
    function bvoc_is_allowed_order_status_for_request(string $orderStatus, string $cancelSource): bool
    {
        $orderStatus = strtolower(trim($orderStatus));
        $cancelSource = strtolower(trim($cancelSource));

        if ($cancelSource === 'admin' || $cancelSource === 'system') {
            return !in_array($orderStatus, ['cancelled', 'refunded'], true);
        }

        if ($cancelSource === 'seller') {
            return !in_array($orderStatus, ['cancelled', 'refunded', 'completed'], true);
        }

        return in_array($orderStatus, ['pending', 'pending_payment', 'paid-awaiting-verify', 'paid'], true);
    }
}

if (!function_exists('bvoc_is_allowed')) {
    function bvoc_is_allowed(array $order, ?int $actorUserId = null, ?string $actorRole = null, ?string $cancelSource = null): bool
    {
        $actorUserId = $actorUserId ?? bvoc_current_user_id();
        $actorRole = strtolower(trim((string) ($actorRole ?? bvoc_current_role())));
        $cancelSource = bvoc_normalize_cancel_source($cancelSource, $actorRole);
        $orderStatus = bvoc_order_status($order);

        if (!bvoc_is_allowed_order_status_for_request($orderStatus, $cancelSource)) {
            return false;
        }

        if ($cancelSource === 'admin' || $cancelSource === 'system') {
            return true;
        }

        if ($cancelSource === 'seller') {
            if (bvoc_is_admin_role($actorRole)) {
                return true;
            }

            $sellerId = bvoc_int($order['seller_user_id'] ?? 0);
            if ($sellerId > 0 && $actorUserId > 0 && $sellerId === $actorUserId) {
                return true;
            }

            return false;
        }

        $buyerId = bvoc_order_user_id($order);
        if ($buyerId > 0 && $actorUserId > 0 && $buyerId === $actorUserId) {
            return true;
        }

        return false;
    }
}

if (!function_exists('bvoc_calculate_refundable_amount')) {
    function bvoc_calculate_refundable_amount(array $order): float
    {
        $candidates = [
            $order['total'] ?? null,
            $order['grand_total'] ?? null,
            $order['amount_total'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== null && $candidate !== '' && is_numeric($candidate)) {
                $amount = round((float) $candidate, 2);
                return $amount > 0 ? $amount : 0.0;
            }
        }

        $subtotal = 0.0;
        foreach (['subtotal', 'subtotal_after_discount', 'subtotal_before_discount'] as $field) {
            if (isset($order[$field]) && $order[$field] !== '' && $order[$field] !== null && is_numeric($order[$field])) {
                $subtotal = (float) $order[$field];
                break;
            }
        }

        $discount = 0.0;
        foreach (['discount_amount', 'seller_discount_total'] as $field) {
            if (isset($order[$field]) && $order[$field] !== '' && $order[$field] !== null && is_numeric($order[$field])) {
                $discount += (float) $order[$field];
            }
        }

        $shipping = 0.0;
        if (isset($order['shipping_amount']) && $order['shipping_amount'] !== '' && $order['shipping_amount'] !== null && is_numeric($order['shipping_amount'])) {
            $shipping = (float) $order['shipping_amount'];
        }

        $total = round(($subtotal - $discount) + $shipping, 2);
        return $total > 0 ? $total : 0.0;
    }
}

if (!function_exists('bvoc_restock_required')) {
    function bvoc_restock_required(array $order): bool
    {
        $status = bvoc_order_status($order);
        $paymentState = bvoc_order_payment_state($order);

        if (in_array($status, ['processing', 'confirmed', 'packing', 'shipped', 'completed', 'paid', 'paid-awaiting-verify'], true)) {
            return true;
        }

        return in_array($paymentState, ['paid', 'partially_paid', 'authorized'], true);
    }
}

if (!function_exists('bvoc_refund_status_for_new_request')) {
    function bvoc_refund_status_for_new_request(array $order): string
    {
        $paymentState = bvoc_order_payment_state($order);

        if (in_array($paymentState, ['paid', 'partially_paid', 'authorized'], true)) {
            return 'pending';
        }

        return 'not_required';
    }
}

if (!function_exists('bvoc_build_order_snapshot')) {
    function bvoc_build_order_snapshot(array $order, array $items = []): array
    {
        return [
            'order_id' => bvoc_int($order['id'] ?? 0),
            'order_code_snapshot' => bvoc_order_code($order),
            'user_id' => bvoc_order_user_id($order),
            'seller_user_id' => bvoc_int($order['seller_user_id'] ?? 0),
            'order_status_snapshot' => bvoc_order_status($order),
            'payment_state_snapshot' => bvoc_order_payment_state($order),
            'order_source_snapshot' => bvoc_order_source($order),
            'currency' => bvoc_order_currency($order),
            'subtotal_before_discount_snapshot' => round(bvoc_num($order['subtotal_before_discount'] ?? 0), 2),
            'discount_amount_snapshot' => round(bvoc_num($order['discount_amount'] ?? 0), 2),
            'seller_discount_total_snapshot' => round(bvoc_num($order['seller_discount_total'] ?? 0), 2),
            'subtotal_snapshot' => round(bvoc_num($order['subtotal'] ?? 0), 2),
            'shipping_amount_snapshot' => round(bvoc_num($order['shipping_amount'] ?? 0), 2),
            'total_snapshot' => round(bvoc_calculate_refundable_amount($order), 2),
            'restock_required' => bvoc_restock_required($order) ? 1 : 0,
            'refundable_amount' => round(bvoc_calculate_refundable_amount($order), 2),
            'items_count' => count($items),
        ];
    }
}

if (!function_exists('bvoc_pick_item_listing_id')) {
    function bvoc_pick_item_listing_id(array $item): int
    {
        foreach (['listing_id', 'product_id', 'item_id'] as $field) {
            if (isset($item[$field]) && is_numeric($item[$field]) && (int) $item[$field] > 0) {
                return (int) $item[$field];
            }
        }
        return 0;
    }
}

if (!function_exists('bvoc_pick_item_qty')) {
    function bvoc_pick_item_qty(array $item): int
    {
        foreach (['quantity', 'qty'] as $field) {
            if (isset($item[$field]) && is_numeric($item[$field]) && (int) $item[$field] > 0) {
                return (int) $item[$field];
            }
        }
        return 1;
    }
}

if (!function_exists('bvoc_pick_item_unit_price')) {
    function bvoc_pick_item_unit_price(array $item): float
    {
        foreach (['unit_price', 'price', 'item_price'] as $field) {
            if (isset($item[$field]) && $item[$field] !== '' && $item[$field] !== null && is_numeric($item[$field])) {
                return round((float) $item[$field], 2);
            }
        }

        $lineTotal = bvoc_pick_item_line_total($item);
        $qty = max(1, bvoc_pick_item_qty($item));

        if ($lineTotal > 0) {
            return round($lineTotal / $qty, 2);
        }

        return 0.0;
    }
}

if (!function_exists('bvoc_pick_item_line_total')) {
    function bvoc_pick_item_line_total(array $item): float
    {
        foreach (['line_total', 'total', 'amount_total'] as $field) {
            if (isset($item[$field]) && $item[$field] !== '' && $item[$field] !== null && is_numeric($item[$field])) {
                return round((float) $item[$field], 2);
            }
        }

        $unit = bvoc_pick_item_unit_price($item);
        $qty = max(1, bvoc_pick_item_qty($item));

        return round($unit * $qty, 2);
    }
}

if (!function_exists('bvoc_insert_cancellation_header')) {
    function bvoc_insert_cancellation_header(PDO $pdo, array $payload): int
    {
        $columns = bvoc_columns($pdo, 'order_cancellations');

        $data = [
            'order_id' => bvoc_int($payload['order_id'] ?? 0),
            'order_code_snapshot' => bvoc_str($payload['order_code_snapshot'] ?? ''),
            'requested_by_user_id' => bvoc_int($payload['requested_by_user_id'] ?? 0) ?: null,
            'requested_by_role' => bvoc_str($payload['requested_by_role'] ?? ''),
            'user_id' => bvoc_int($payload['user_id'] ?? 0) ?: null,
            'actor_role' => bvoc_str($payload['actor_role'] ?? ''),
            'cancel_source' => bvoc_str($payload['cancel_source'] ?? 'buyer'),
            'cancel_reason_code' => bvoc_str($payload['cancel_reason_code'] ?? ''),
            'cancel_reason_text' => bvoc_str($payload['cancel_reason_text'] ?? ''),
            'admin_note' => bvoc_str($payload['admin_note'] ?? ''),
            'status' => bvoc_str($payload['status'] ?? 'requested'),
            'payment_state_snapshot' => bvoc_str($payload['payment_state_snapshot'] ?? ''),
            'order_status_snapshot' => bvoc_str($payload['order_status_snapshot'] ?? ''),
            'order_source_snapshot' => bvoc_str($payload['order_source_snapshot'] ?? ''),
            'currency' => bvoc_str($payload['currency'] ?? 'USD'),
            'subtotal_before_discount_snapshot' => round(bvoc_num($payload['subtotal_before_discount_snapshot'] ?? 0), 2),
            'discount_amount_snapshot' => round(bvoc_num($payload['discount_amount_snapshot'] ?? 0), 2),
            'seller_discount_total_snapshot' => round(bvoc_num($payload['seller_discount_total_snapshot'] ?? 0), 2),
            'subtotal_snapshot' => round(bvoc_num($payload['subtotal_snapshot'] ?? 0), 2),
            'shipping_amount_snapshot' => round(bvoc_num($payload['shipping_amount_snapshot'] ?? 0), 2),
            'total_snapshot' => round(bvoc_num($payload['total_snapshot'] ?? 0), 2),
            'refundable_amount' => round(bvoc_num($payload['refundable_amount'] ?? 0), 2),
            'restock_required' => bvoc_bool($payload['restock_required'] ?? false) ? 1 : 0,
            'refund_status' => bvoc_str($payload['refund_status'] ?? 'pending'),
            'refund_reference' => bvoc_str($payload['refund_reference'] ?? ''),
            'requested_at' => bvoc_str($payload['requested_at'] ?? bvoc_now()),
            'approved_at' => $payload['approved_at'] ?? null,
            'completed_at' => $payload['completed_at'] ?? null,
            'created_at' => bvoc_str($payload['created_at'] ?? bvoc_now()),
            'updated_at' => bvoc_str($payload['updated_at'] ?? bvoc_now()),
        ];

        $insert = [];
        foreach ($data as $column => $value) {
            if (isset($columns[$column])) {
                $insert[$column] = $value;
            }
        }

        if (empty($insert['order_id'])) {
            throw new InvalidArgumentException('order_id is required.');
        }

        if (empty($insert['cancel_source'])) {
            $insert['cancel_source'] = 'buyer';
        }

        if (empty($insert['status'])) {
            $insert['status'] = 'requested';
        }

        if (empty($insert['currency'])) {
            $insert['currency'] = 'USD';
        }

        $keys = array_keys($insert);
        $sql = "INSERT INTO order_cancellations (`" . implode('`,`', $keys) . "`) VALUES (" . implode(',', array_fill(0, count($keys), '?')) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($insert));

        return (int) $pdo->lastInsertId();
    }
}

if (!function_exists('bvoc_insert_cancellation_item')) {
    function bvoc_insert_cancellation_item(PDO $pdo, array $payload): int
    {
        $columns = bvoc_columns($pdo, 'order_cancellation_items');

        $data = [
            'cancellation_id' => bvoc_int($payload['cancellation_id'] ?? 0),
            'order_item_id' => bvoc_int($payload['order_item_id'] ?? 0) ?: null,
            'listing_id' => bvoc_int($payload['listing_id'] ?? 0) ?: null,
            'qty' => max(1, bvoc_int($payload['qty'] ?? 1)),
            'unit_price_snapshot' => round(bvoc_num($payload['unit_price_snapshot'] ?? 0), 2),
            'line_total_snapshot' => round(bvoc_num($payload['line_total_snapshot'] ?? 0), 2),
            'restock_qty' => max(0, bvoc_int($payload['restock_qty'] ?? 0)),
            'stock_reversed' => bvoc_bool($payload['stock_reversed'] ?? false) ? 1 : 0,
            'stock_reversed_at' => $payload['stock_reversed_at'] ?? null,
            'created_at' => bvoc_str($payload['created_at'] ?? bvoc_now()),
            'updated_at' => bvoc_str($payload['updated_at'] ?? bvoc_now()),
        ];

        $insert = [];
        foreach ($data as $column => $value) {
            if (isset($columns[$column])) {
                $insert[$column] = $value;
            }
        }

        if (empty($insert['cancellation_id'])) {
            throw new InvalidArgumentException('cancellation_id is required.');
        }

        $keys = array_keys($insert);
        $sql = "INSERT INTO order_cancellation_items (`" . implode('`,`', $keys) . "`) VALUES (" . implode(',', array_fill(0, count($keys), '?')) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($insert));

        return (int) $pdo->lastInsertId();
    }
}

if (!function_exists('bvoc_build_items_payload')) {
    function bvoc_build_items_payload(array $orderItems, bool $restockRequired): array
    {
        $rows = [];

        foreach ($orderItems as $item) {
            $qty = bvoc_pick_item_qty($item);
            $listingId = bvoc_pick_item_listing_id($item);
            $unitPrice = bvoc_pick_item_unit_price($item);
            $lineTotal = bvoc_pick_item_line_total($item);

            $rows[] = [
                'order_item_id' => bvoc_int($item['id'] ?? 0),
                'listing_id' => $listingId > 0 ? $listingId : null,
                'qty' => $qty,
                'unit_price_snapshot' => $unitPrice,
                'line_total_snapshot' => $lineTotal,
                'restock_qty' => $restockRequired ? $qty : 0,
                'stock_reversed' => 0,
                'stock_reversed_at' => null,
            ];
        }

        return $rows;
    }
}

if (!function_exists('bvoc_create_request')) {
    function bvoc_create_request(array $context, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?: bvoc_db();

        if (!bvoc_require_tables($pdo)) {
            throw new RuntimeException('Required cancellation tables are missing.');
        }

        $orderId = bvoc_int($context['order_id'] ?? 0);
        $actorUserId = bvoc_int($context['actor_user_id'] ?? bvoc_current_user_id());
        $actorRole = strtolower(trim((string) ($context['actor_role'] ?? bvoc_current_role())));
        $cancelSource = bvoc_normalize_cancel_source($context['cancel_source'] ?? null, $actorRole);
        $reasonCode = bvoc_str($context['cancel_reason_code'] ?? '');
        $reasonText = bvoc_str($context['cancel_reason_text'] ?? '');
        $adminNote = bvoc_str($context['admin_note'] ?? '');

        if ($orderId <= 0) {
            throw new InvalidArgumentException('Invalid order_id.');
        }

        bvoc_begin($pdo);

        try {
            $order = bvoc_get_order_by_id($orderId, true, $pdo);
            if (!$order) {
                throw new RuntimeException('Order not found.');
            }

            if (!bvoc_is_allowed($order, $actorUserId, $actorRole, $cancelSource)) {
                throw new RuntimeException('Cancel request is not allowed for this order or actor.');
            }

            if (bvoc_has_open_cancellation_request($orderId, $pdo)) {
                throw new RuntimeException('An open cancel request already exists for this order.');
            }

            $orderItems = bvoc_get_order_items($orderId, $pdo);
            $snapshot = bvoc_build_order_snapshot($order, $orderItems);

            $headerId = bvoc_insert_cancellation_header($pdo, [
                'order_id' => $orderId,
                'order_code_snapshot' => $snapshot['order_code_snapshot'],
                'requested_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
                'requested_by_role' => $actorRole !== '' ? $actorRole : 'buyer',
                'user_id' => $actorUserId > 0 ? $actorUserId : null,
                'actor_role' => $actorRole !== '' ? $actorRole : 'buyer',
                'cancel_source' => $cancelSource,
                'cancel_reason_code' => $reasonCode,
                'cancel_reason_text' => $reasonText,
                'admin_note' => $adminNote,
                'status' => 'requested',
                'payment_state_snapshot' => $snapshot['payment_state_snapshot'],
                'order_status_snapshot' => $snapshot['order_status_snapshot'],
                'order_source_snapshot' => $snapshot['order_source_snapshot'],
                'currency' => $snapshot['currency'],
                'subtotal_before_discount_snapshot' => $snapshot['subtotal_before_discount_snapshot'],
                'discount_amount_snapshot' => $snapshot['discount_amount_snapshot'],
                'seller_discount_total_snapshot' => $snapshot['seller_discount_total_snapshot'],
                'subtotal_snapshot' => $snapshot['subtotal_snapshot'],
                'shipping_amount_snapshot' => $snapshot['shipping_amount_snapshot'],
                'total_snapshot' => $snapshot['total_snapshot'],
                'refundable_amount' => $snapshot['refundable_amount'],
                'restock_required' => $snapshot['restock_required'],
                'refund_status' => bvoc_refund_status_for_new_request($order),
                'refund_reference' => '',
                'requested_at' => bvoc_now(),
                'created_at' => bvoc_now(),
                'updated_at' => bvoc_now(),
            ]);

            $itemRows = bvoc_build_items_payload($orderItems, !empty($snapshot['restock_required']));
            foreach ($itemRows as $itemRow) {
                $itemRow['cancellation_id'] = $headerId;
                $itemRow['created_at'] = bvoc_now();
                $itemRow['updated_at'] = bvoc_now();
                bvoc_insert_cancellation_item($pdo, $itemRow);
            }

            bvoc_commit($pdo);

            $cancellation = bvoc_get_cancellation_by_id($headerId, $pdo);
            $cancellationItems = bvoc_get_cancellation_items($headerId, $pdo);

            bvoc_log('cancel_request_created', [
                'cancellation_id' => $headerId,
                'order_id' => $orderId,
                'actor_user_id' => $actorUserId,
                'actor_role' => $actorRole,
                'cancel_source' => $cancelSource,
                'items_count' => count($cancellationItems),
            ]);

            return [
                'ok' => true,
                'cancellation_id' => $headerId,
                'cancellation' => $cancellation,
                'items' => $cancellationItems,
                'order' => $order,
            ];
        } catch (Throwable $e) {
            bvoc_rollback($pdo);
            bvoc_log('cancel_request_failed', [
                'order_id' => $orderId,
                'actor_user_id' => $actorUserId,
                'actor_role' => $actorRole,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}

if (!function_exists('bvoc_update_cancellation_status')) {
    function bvoc_update_cancellation_status(int $cancellationId, string $status, array $extra = [], ?PDO $pdo = null): bool
    {
        $pdo = $pdo ?: bvoc_db();

        $status = strtolower(trim($status));
        if (!in_array($status, ['requested', 'approved', 'rejected', 'completed'], true)) {
            throw new InvalidArgumentException('Invalid cancellation status.');
        }

        $row = bvoc_get_cancellation_by_id($cancellationId, $pdo);
        if (!$row) {
            throw new RuntimeException('Cancellation not found.');
        }

        $columns = bvoc_columns($pdo, 'order_cancellations');
        $set = [];
        $params = [];

        if (isset($columns['status'])) {
            $set[] = "`status` = ?";
            $params[] = $status;
        }

        if ($status === 'approved' && isset($columns['approved_at'])) {
            $set[] = "`approved_at` = ?";
            $params[] = bvoc_now();
        }

        if ($status === 'completed' && isset($columns['completed_at'])) {
            $set[] = "`completed_at` = ?";
            $params[] = bvoc_now();
        }

        foreach ($extra as $column => $value) {
            if (isset($columns[$column])) {
                $set[] = "`{$column}` = ?";
                $params[] = $value;
            }
        }

        if (isset($columns['updated_at'])) {
            $set[] = "`updated_at` = ?";
            $params[] = bvoc_now();
        }

        if (!$set) {
            return false;
        }

        $params[] = $cancellationId;

        $sql = "UPDATE order_cancellations SET " . implode(', ', $set) . " WHERE id = ? LIMIT 1";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    }
}

if (!function_exists('bvoc_mark_refund_ready')) {
    function bvoc_mark_refund_ready(int $cancellationId, ?PDO $pdo = null): bool
    {
        $pdo = $pdo ?: bvoc_db();
        return bvoc_update_cancellation_status($cancellationId, 'approved', [
            'refund_status' => 'ready',
        ], $pdo);
    }
}

if (!function_exists('bvoc_approve')) {
    function bvoc_approve(int $cancellationId, ?int $actorUserId = null, ?string $actorRole = null, string $adminNote = '', ?PDO $pdo = null): array
    {
        $pdo = $pdo ?: bvoc_db();
        $actorUserId = $actorUserId ?? bvoc_current_user_id();
        $actorRole = strtolower(trim((string) ($actorRole ?? bvoc_current_role())));

        if (!bvoc_is_admin_role($actorRole) && $actorRole !== 'seller') {
            throw new RuntimeException('Only seller/admin can approve cancellation.');
        }

        bvoc_begin($pdo);

        try {
            $cancellation = bvoc_get_cancellation_by_id($cancellationId, $pdo);
            if (!$cancellation) {
                throw new RuntimeException('Cancellation not found.');
            }

            $status = strtolower(trim((string) ($cancellation['status'] ?? '')));
            if ($status !== 'requested') {
                throw new RuntimeException('Only requested cancellation can be approved.');
            }

            bvoc_update_cancellation_status($cancellationId, 'approved', [
                'admin_note' => $adminNote !== '' ? $adminNote : ($cancellation['admin_note'] ?? ''),
                'refund_status' => bvoc_bool($cancellation['restock_required'] ?? 0) || bvoc_num($cancellation['refundable_amount'] ?? 0) > 0 ? 'ready' : 'not_required',
            ], $pdo);

            $updated = bvoc_get_cancellation_by_id($cancellationId, $pdo);

            bvoc_commit($pdo);

            bvoc_log('cancel_request_approved', [
                'cancellation_id' => $cancellationId,
                'actor_user_id' => $actorUserId,
                'actor_role' => $actorRole,
            ]);

            return [
                'ok' => true,
                'cancellation' => $updated,
            ];
        } catch (Throwable $e) {
            bvoc_rollback($pdo);
            bvoc_log('cancel_request_approve_failed', [
                'cancellation_id' => $cancellationId,
                'actor_user_id' => $actorUserId,
                'actor_role' => $actorRole,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}

if (!function_exists('bvoc_reject')) {
    function bvoc_reject(int $cancellationId, ?int $actorUserId = null, ?string $actorRole = null, string $adminNote = '', ?PDO $pdo = null): array
    {
        $pdo = $pdo ?: bvoc_db();
        $actorUserId = $actorUserId ?? bvoc_current_user_id();
        $actorRole = strtolower(trim((string) ($actorRole ?? bvoc_current_role())));

        if (!bvoc_is_admin_role($actorRole) && $actorRole !== 'seller') {
            throw new RuntimeException('Only seller/admin can reject cancellation.');
        }

        bvoc_begin($pdo);

        try {
            $cancellation = bvoc_get_cancellation_by_id($cancellationId, $pdo);
            if (!$cancellation) {
                throw new RuntimeException('Cancellation not found.');
            }

            $status = strtolower(trim((string) ($cancellation['status'] ?? '')));
            if (!in_array($status, ['requested', 'approved'], true)) {
                throw new RuntimeException('Cancellation cannot be rejected in current state.');
            }

            bvoc_update_cancellation_status($cancellationId, 'rejected', [
                'admin_note' => $adminNote !== '' ? $adminNote : ($cancellation['admin_note'] ?? ''),
                'refund_status' => 'not_required',
            ], $pdo);

            $updated = bvoc_get_cancellation_by_id($cancellationId, $pdo);

            bvoc_commit($pdo);

            bvoc_log('cancel_request_rejected', [
                'cancellation_id' => $cancellationId,
                'actor_user_id' => $actorUserId,
                'actor_role' => $actorRole,
            ]);

            return [
                'ok' => true,
                'cancellation' => $updated,
            ];
        } catch (Throwable $e) {
            bvoc_rollback($pdo);
            bvoc_log('cancel_request_reject_failed', [
                'cancellation_id' => $cancellationId,
                'actor_user_id' => $actorUserId,
                'actor_role' => $actorRole,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}

if (!function_exists('bvoc_listing_has_stock_columns')) {
    function bvoc_listing_has_stock_columns(PDO $pdo): bool
    {
        return bvoc_table_exists($pdo, 'listings')
            && bvoc_has_col($pdo, 'listings', 'stock_sold')
            && bvoc_has_col($pdo, 'listings', 'stock_available');
    }
}

if (!function_exists('bvoc_reverse_stock_for_item')) {
    function bvoc_reverse_stock_for_item(array $cancellationItem, ?PDO $pdo = null): bool
    {
        $pdo = $pdo ?: bvoc_db();

        if (!bvoc_listing_has_stock_columns($pdo)) {
            return false;
        }

        $listingId = bvoc_int($cancellationItem['listing_id'] ?? 0);
        $restockQty = max(0, bvoc_int($cancellationItem['restock_qty'] ?? 0));
        $itemId = bvoc_int($cancellationItem['id'] ?? 0);

        if ($listingId <= 0 || $restockQty <= 0 || $itemId <= 0) {
            return false;
        }

        if (bvoc_bool($cancellationItem['stock_reversed'] ?? 0)) {
            return true;
        }

        $stmt = $pdo->prepare("SELECT * FROM listings WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$listingId]);
        $listing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$listing) {
            return false;
        }

        $stockSold = max(0, bvoc_int($listing['stock_sold'] ?? 0));
        $stockAvailable = max(0, bvoc_int($listing['stock_available'] ?? 0));
        $stockTotal = max(0, bvoc_int($listing['stock_total'] ?? 0));

        $stockSold = max(0, $stockSold - $restockQty);
        $stockAvailable = $stockAvailable + $restockQty;

        if ($stockTotal > 0 && $stockAvailable > $stockTotal) {
            $stockAvailable = $stockTotal;
        }

        $saleStatus = $stockAvailable > 0 ? 'available' : strtolower(trim((string) ($listing['sale_status'] ?? 'available')));
        $status = strtolower(trim((string) ($listing['status'] ?? 'active')));
        if ($stockAvailable > 0 && in_array($status, ['sold', 'hidden'], true)) {
            $status = 'active';
        }

        $fields = [];
        $params = [];

        if (bvoc_has_col($pdo, 'listings', 'stock_sold')) {
            $fields[] = "`stock_sold` = ?";
            $params[] = $stockSold;
        }
        if (bvoc_has_col($pdo, 'listings', 'stock_available')) {
            $fields[] = "`stock_available` = ?";
            $params[] = $stockAvailable;
        }
        if (bvoc_has_col($pdo, 'listings', 'sale_status')) {
            $fields[] = "`sale_status` = ?";
            $params[] = $saleStatus;
        }
        if (bvoc_has_col($pdo, 'listings', 'status')) {
            $fields[] = "`status` = ?";
            $params[] = $status;
        }
        if (bvoc_has_col($pdo, 'listings', 'updated_at')) {
            $fields[] = "`updated_at` = ?";
            $params[] = bvoc_now();
        }

        if (!$fields) {
            return false;
        }

        $params[] = $listingId;

        $sql = "UPDATE listings SET " . implode(', ', $fields) . " WHERE id = ? LIMIT 1";
        $updateStmt = $pdo->prepare($sql);
        $updateStmt->execute($params);

        $itemFields = [];
        $itemParams = [];

        if (bvoc_has_col($pdo, 'order_cancellation_items', 'stock_reversed')) {
            $itemFields[] = "`stock_reversed` = 1";
        }
        if (bvoc_has_col($pdo, 'order_cancellation_items', 'stock_reversed_at')) {
            $itemFields[] = "`stock_reversed_at` = ?";
            $itemParams[] = bvoc_now();
        }
        if (bvoc_has_col($pdo, 'order_cancellation_items', 'updated_at')) {
            $itemFields[] = "`updated_at` = ?";
            $itemParams[] = bvoc_now();
        }

        if ($itemFields) {
            $itemParams[] = $itemId;
            $itemSql = "UPDATE order_cancellation_items SET " . implode(', ', $itemFields) . " WHERE id = ? LIMIT 1";
            $itemStmt = $pdo->prepare($itemSql);
            $itemStmt->execute($itemParams);
        }

        return true;
    }
}

if (!function_exists('bvoc_reverse_stock')) {
    function bvoc_reverse_stock(int $cancellationId, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?: bvoc_db();

        bvoc_begin($pdo);

        try {
            $items = bvoc_get_cancellation_items($cancellationId, $pdo);
            if (!$items) {
                bvoc_commit($pdo);
                return [
                    'ok' => true,
                    'reversed_count' => 0,
                ];
            }

            $reversedCount = 0;
            foreach ($items as $item) {
                if (bvoc_reverse_stock_for_item($item, $pdo)) {
                    $reversedCount++;
                }
            }

            bvoc_commit($pdo);

            bvoc_log('cancel_reverse_stock_done', [
                'cancellation_id' => $cancellationId,
                'reversed_count' => $reversedCount,
            ]);

            return [
                'ok' => true,
                'reversed_count' => $reversedCount,
            ];
        } catch (Throwable $e) {
            bvoc_rollback($pdo);
            bvoc_log('cancel_reverse_stock_failed', [
                'cancellation_id' => $cancellationId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}

if (!function_exists('bvoc_set_order_status_cancelled')) {
    function bvoc_set_order_status_cancelled(int $orderId, ?PDO $pdo = null): bool
    {
        $pdo = $pdo ?: bvoc_db();

        if ($orderId <= 0) {
            return false;
        }

        $columns = bvoc_columns($pdo, 'orders');
        $set = [];
        $params = [];

        if (isset($columns['status'])) {
            $set[] = "`status` = ?";
            $params[] = 'cancelled';
        }

        if (isset($columns['updated_at'])) {
            $set[] = "`updated_at` = ?";
            $params[] = bvoc_now();
        }

        if (!$set) {
            return false;
        }

        $params[] = $orderId;

        $sql = "UPDATE orders SET " . implode(', ', $set) . " WHERE id = ? LIMIT 1";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    }
}

if (!function_exists('bvoc_complete')) {
    function bvoc_complete(int $cancellationId, ?int $actorUserId = null, ?string $actorRole = null, string $refundStatus = 'ready', ?PDO $pdo = null): array
    {
        $pdo = $pdo ?: bvoc_db();
        $actorUserId = $actorUserId ?? bvoc_current_user_id();
        $actorRole = strtolower(trim((string) ($actorRole ?? bvoc_current_role())));

        if (!bvoc_is_admin_role($actorRole) && $actorRole !== 'seller') {
            throw new RuntimeException('Only seller/admin can complete cancellation.');
        }

        bvoc_begin($pdo);

        try {
            $cancellation = bvoc_get_cancellation_by_id($cancellationId, $pdo);
            if (!$cancellation) {
                throw new RuntimeException('Cancellation not found.');
            }

            $status = strtolower(trim((string) ($cancellation['status'] ?? '')));
            if (!in_array($status, ['approved', 'requested'], true)) {
                throw new RuntimeException('Cancellation cannot be completed in current state.');
            }

            $orderId = bvoc_int($cancellation['order_id'] ?? 0);
            if ($orderId <= 0) {
                throw new RuntimeException('Invalid order_id in cancellation.');
            }

            if (bvoc_bool($cancellation['restock_required'] ?? 0)) {
                $items = bvoc_get_cancellation_items($cancellationId, $pdo);
                foreach ($items as $item) {
                    bvoc_reverse_stock_for_item($item, $pdo);
                }
            }

            bvoc_set_order_status_cancelled($orderId, $pdo);

            bvoc_update_cancellation_status($cancellationId, 'completed', [
                'refund_status' => $refundStatus,
            ], $pdo);

            $updated = bvoc_get_cancellation_by_id($cancellationId, $pdo);
            $updatedItems = bvoc_get_cancellation_items($cancellationId, $pdo);

            bvoc_commit($pdo);

            bvoc_log('cancel_request_completed', [
                'cancellation_id' => $cancellationId,
                'order_id' => $orderId,
                'actor_user_id' => $actorUserId,
                'actor_role' => $actorRole,
                'items_count' => count($updatedItems),
            ]);

            return [
                'ok' => true,
                'cancellation' => $updated,
                'items' => $updatedItems,
            ];
        } catch (Throwable $e) {
            bvoc_rollback($pdo);
            bvoc_log('cancel_request_complete_failed', [
                'cancellation_id' => $cancellationId,
                'actor_user_id' => $actorUserId,
                'actor_role' => $actorRole,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}

if (!function_exists('bv_order_cancel_is_allowed')) {
    function bv_order_cancel_is_allowed(array $order, ?int $actorUserId = null, ?string $actorRole = null, ?string $cancelSource = null): bool
    {
        return bvoc_is_allowed($order, $actorUserId, $actorRole, $cancelSource);
    }
}

if (!function_exists('bv_order_cancel_build_snapshot')) {
    function bv_order_cancel_build_snapshot(array $order, array $items = []): array
    {
        return bvoc_build_order_snapshot($order, $items);
    }
}

if (!function_exists('bv_order_cancel_get_by_id')) {
    function bv_order_cancel_get_by_id(int $id, ?PDO $pdo = null): ?array
    {
        return bvoc_get_cancellation_by_id($id, $pdo);
    }
}

if (!function_exists('bv_order_cancel_get_by_order_id')) {
    function bv_order_cancel_get_by_order_id(int $orderId, ?PDO $pdo = null): ?array
    {
        return bvoc_get_latest_cancellation_by_order_id($orderId, $pdo);
    }
}

if (!function_exists('bv_order_cancel_calculate_refundable_amount')) {
    function bv_order_cancel_calculate_refundable_amount(array $order): float
    {
        return bvoc_calculate_refundable_amount($order);
    }
}

if (!function_exists('bv_order_cancel_mark_refund_ready')) {
    function bv_order_cancel_mark_refund_ready(int $cancellationId, ?PDO $pdo = null): bool
    {
        return bvoc_mark_refund_ready($cancellationId, $pdo);
    }
}

if (!function_exists('bv_order_cancel_reverse_stock')) {
    function bv_order_cancel_reverse_stock(int $cancellationId, ?PDO $pdo = null): array
    {
        return bvoc_reverse_stock($cancellationId, $pdo);
    }
}

if (!function_exists('bv_order_cancel_create_request')) {
    function bv_order_cancel_create_request(array $context, ?PDO $pdo = null): array
    {
        return bvoc_create_request($context, $pdo);
    }
}

if (!function_exists('bv_order_cancel_approve')) {
    function bv_order_cancel_approve(int $cancellationId, ?int $actorUserId = null, ?string $actorRole = null, string $adminNote = '', ?PDO $pdo = null): array
    {
        return bvoc_approve($cancellationId, $actorUserId, $actorRole, $adminNote, $pdo);
    }
}

if (!function_exists('bv_order_cancel_reject')) {
    function bv_order_cancel_reject(int $cancellationId, ?int $actorUserId = null, ?string $actorRole = null, string $adminNote = '', ?PDO $pdo = null): array
    {
        return bvoc_reject($cancellationId, $actorUserId, $actorRole, $adminNote, $pdo);
    }
}

if (!function_exists('bv_order_cancel_complete')) {
    function bv_order_cancel_complete(int $cancellationId, ?int $actorUserId = null, ?string $actorRole = null, string $refundStatus = 'ready', ?PDO $pdo = null): array
    {
        return bvoc_complete($cancellationId, $actorUserId, $actorRole, $refundStatus, $pdo);
    }
}
?>