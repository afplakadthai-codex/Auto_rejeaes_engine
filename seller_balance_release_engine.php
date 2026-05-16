<?php
declare(strict_types=1);

/**
 * Bettavaro seller balance auto-release engine.
 *
 * This file is intentionally side-effect-light: it defines reusable functions,
 * bootstraps an existing PDO connection when needed, logs to PHP/file logs, and
 * never calls payout providers or external money movement APIs.
 */

if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli' && !headers_sent()) {
    // The engine itself does not require a session, but some legacy includes may.
    @session_start();
}

$bvSellerReleaseRoot = dirname(__DIR__, 2);
$bvSellerReleaseIncludes = [
    $bvSellerReleaseRoot . '/config/db.php',
    $bvSellerReleaseRoot . '/includes/db.php',
    dirname(__DIR__) . '/config/db.php',
    dirname(__DIR__) . '/includes/db.php',
    __DIR__ . '/db.php',
];
foreach ($bvSellerReleaseIncludes as $bvSellerReleaseFile) {
    if (is_file($bvSellerReleaseFile)) {
        require_once $bvSellerReleaseFile;
    }
}
unset($bvSellerReleaseFile, $bvSellerReleaseIncludes);

$bvSellerBalanceIncludes = [
    __DIR__ . '/seller_balance.php',
    dirname(__DIR__) . '/includes/seller_balance.php',
    $bvSellerReleaseRoot . '/includes/seller_balance.php',
];
foreach ($bvSellerBalanceIncludes as $bvSellerReleaseFile) {
    if (is_file($bvSellerReleaseFile)) {
        require_once $bvSellerReleaseFile;
        break;
    }
}
unset($bvSellerReleaseFile, $bvSellerBalanceIncludes, $bvSellerReleaseRoot);

if (!function_exists('bv_seller_release_now')) {
    function bv_seller_release_now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('bv_seller_release_config')) {
    function bv_seller_release_config(): array
    {
        return [
            'release_delay_days' => 3,
            'batch_limit' => 50,
            'dry_run' => true,
            'pending_statuses' => ['pending', 'locked', 'on_hold', 'hold', 'releasable'],
            'released_status_preference' => ['available', 'released'],
            'active_refund_statuses' => ['pending_approval', 'approved', 'processing', 'partially_refunded', 'failed'],
            'active_cancel_statuses' => ['requested', 'approved', 'processing'],
            'completed_fulfillment_statuses' => ['completed'],
        ];
    }
}

if (!function_exists('bv_seller_release_log')) {
    function bv_seller_release_log(string $event, array $data = []): void
    {
        if (function_exists('bv_seller_balance_log')) {
            bv_seller_balance_log('seller_balance_release_' . $event, $data);
            return;
        }

        $line = '[' . bv_seller_release_now() . '] seller_balance_release_' . $event . ' '
            . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

        $paths = [
            dirname(__DIR__, 2) . '/private_html/logs/seller_balance_release.log',
            dirname(__DIR__) . '/logs/seller_balance_release.log',
            sys_get_temp_dir() . '/seller_balance_release.log',
        ];
        foreach ($paths as $path) {
            $dir = dirname($path);
            if (is_dir($dir) && is_writable($dir)) {
                @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
                break;
            }
        }
    }
}

if (!function_exists('_bv_seller_release_balance_audit')) {
    function _bv_seller_release_balance_audit(array $payload): void
    {
        $helpers = [
            'bv_seller_balance_log',
            'bv_seller_balance_audit_log',
            'bv_seller_balance_add_audit',
            'bv_seller_balance_entry_log',
        ];

        foreach ($helpers as $helper) {
            if (!function_exists($helper)) {
                continue;
            }

            try {
                if ($helper === 'bv_seller_balance_log') {
                    $helper((string)$payload['event'], $payload);
                } else {
                    $ref = new ReflectionFunction($helper);
                    if ($ref->getNumberOfParameters() >= 2) {
                        $helper((string)$payload['event'], $payload);
                    } else {
                        $helper($payload);
                    }
                }
                return;
            } catch (Throwable $e) {
                try {
                    bv_seller_release_log('audit_helper_failed', [
                        'helper' => $helper,
                        'entry_id' => (int)($payload['entry_id'] ?? 0),
                        'error' => $e->getMessage(),
                    ]);
                } catch (Throwable $ignored) {
                    error_log('seller_balance_release_audit_helper_failed ' . $helper . ': ' . $e->getMessage());
                }
            }
        }

        try {
            bv_seller_release_log((string)$payload['event'], $payload);
        } catch (Throwable $e) {
            error_log('seller_balance_release_audit_fallback_failed: ' . $e->getMessage());
        }
    }
}


if (!function_exists('bv_seller_release_db')) {
    function bv_seller_release_db(): PDO
    {
        if (function_exists('bv_seller_balance_db')) {
            return bv_seller_balance_db();
        }
        if (function_exists('bv_seller_balance_pdo')) {
            return bv_seller_balance_pdo();
        }
        if (function_exists('bv_member_pdo')) {
            return bv_member_pdo();
        }

        foreach (['pdo', 'db', 'dbh'] as $name) {
            if (isset($GLOBALS[$name]) && $GLOBALS[$name] instanceof PDO) {
                return $GLOBALS[$name];
            }
        }

        throw new RuntimeException('Unable to obtain PDO connection for seller balance release engine.');
    }
}

if (!function_exists('_bv_seller_release_ident')) {
    function _bv_seller_release_ident(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException('Unsafe SQL identifier: ' . $identifier);
        }
        return '`' . $identifier . '`';
    }
}

if (!function_exists('_bv_seller_release_table_exists')) {
    function _bv_seller_release_table_exists(PDO $pdo, string $table): bool
    {
        static $cache = [];
        $key = spl_object_id($pdo) . ':' . $table;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            $stmt->execute([$table]);
            return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
        } catch (Throwable $e) {
            bv_seller_release_log('table_check_error', ['table' => $table, 'error' => $e->getMessage()]);
            return $cache[$key] = false;
        }
    }
}

if (!function_exists('_bv_seller_release_columns')) {
    function _bv_seller_release_columns(PDO $pdo, string $table): array
    {
        static $cache = [];
        $key = spl_object_id($pdo) . ':' . $table;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        if (!_bv_seller_release_table_exists($pdo, $table)) {
            return $cache[$key] = [];
        }
        try {
            $rows = $pdo->query('SHOW COLUMNS FROM ' . _bv_seller_release_ident($table))->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $cols = [];
            foreach ($rows as $row) {
                $cols[(string)$row['Field']] = $row;
            }
            return $cache[$key] = $cols;
        } catch (Throwable $e) {
            bv_seller_release_log('columns_error', ['table' => $table, 'error' => $e->getMessage()]);
            return $cache[$key] = [];
        }
    }
}

if (!function_exists('_bv_seller_release_has_col')) {
    function _bv_seller_release_has_col(PDO $pdo, string $table, string $column): bool
    {
        $cols = _bv_seller_release_columns($pdo, $table);
        return isset($cols[$column]);
    }
}

if (!function_exists('_bv_seller_release_first_col')) {
    function _bv_seller_release_first_col(PDO $pdo, string $table, array $candidates): ?string
    {
        foreach ($candidates as $column) {
            if (_bv_seller_release_has_col($pdo, $table, $column)) {
                return $column;
            }
        }
        return null;
    }
}

if (!function_exists('_bv_seller_release_enum_values')) {
    function _bv_seller_release_enum_values(PDO $pdo, string $table, string $column): array
    {
        $cols = _bv_seller_release_columns($pdo, $table);
        $type = (string)($cols[$column]['Type'] ?? '');
        if (!str_starts_with(strtolower($type), 'enum(')) {
            return [];
        }
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $type, $matches);
        return array_map(static fn(string $v): string => stripcslashes($v), $matches[1] ?? []);
    }
}

if (!function_exists('_bv_seller_release_date_due')) {
    function _bv_seller_release_date_due(?string $value, int $delayDays = 0): bool
    {
        if ($value === null || trim($value) === '' || $value === '0000-00-00 00:00:00') {
            return false;
        }
        $ts = strtotime($value);
        return $ts !== false && ($ts + ($delayDays * 86400)) <= time();
    }
}

if (!function_exists('_bv_seller_release_entry_id')) {
    function _bv_seller_release_entry_id(array $entry): int
    {
        return (int)($entry['id'] ?? $entry['entry_id'] ?? 0);
    }
}

if (!function_exists('_bv_seller_release_scalar_query')) {
    function _bv_seller_release_scalar_query(PDO $pdo, string $sql, array $params): bool
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('bv_seller_release_is_refund_blocked')) {
    function bv_seller_release_is_refund_blocked(PDO $pdo, array $entry): bool
    {
        $cfg = bv_seller_release_config();
        $statuses = $cfg['active_refund_statuses'];
        if (!_bv_seller_release_table_exists($pdo, 'order_refunds') || !_bv_seller_release_has_col($pdo, 'order_refunds', 'status')) {
            return false;
        }

        $orderItemId = (int)($entry['order_item_id'] ?? 0);
        $orderId = (int)($entry['order_id'] ?? 0);
        $sellerId = (int)($entry['seller_id'] ?? 0);
        $ph = implode(',', array_fill(0, count($statuses), '?'));

        if ($orderItemId > 0 && _bv_seller_release_table_exists($pdo, 'order_refund_items') && _bv_seller_release_has_col($pdo, 'order_refund_items', 'order_item_id') && _bv_seller_release_has_col($pdo, 'order_refund_items', 'refund_id')) {
            return _bv_seller_release_scalar_query(
                $pdo,
                "SELECT COUNT(*) FROM order_refund_items ri JOIN order_refunds r ON r.id = ri.refund_id WHERE ri.order_item_id = ? AND r.status IN ($ph)",
                array_merge([$orderItemId], $statuses)
            );
        }

        if ($orderId <= 0) {
            return false;
        }

        if (_bv_seller_release_table_exists($pdo, 'order_refund_items') && _bv_seller_release_has_col($pdo, 'order_refund_items', 'order_item_id') && _bv_seller_release_has_col($pdo, 'order_refund_items', 'refund_id') && _bv_seller_release_table_exists($pdo, 'order_items') && $sellerId > 0) {
            $sellerCol = _bv_seller_release_first_col($pdo, 'order_items', ['seller_id', 'seller_user_id', 'vendor_id']);
            if ($sellerCol !== null) {
                return _bv_seller_release_scalar_query(
                    $pdo,
                    "SELECT COUNT(*) FROM order_refund_items ri JOIN order_refunds r ON r.id = ri.refund_id JOIN order_items oi ON oi.id = ri.order_item_id WHERE r.order_id = ? AND oi." . _bv_seller_release_ident($sellerCol) . " = ? AND r.status IN ($ph)",
                    array_merge([$orderId, $sellerId], $statuses)
                );
            }
        }

        return _bv_seller_release_scalar_query($pdo, "SELECT COUNT(*) FROM order_refunds WHERE order_id = ? AND status IN ($ph)", array_merge([$orderId], $statuses));
    }
}

if (!function_exists('bv_seller_release_is_cancel_blocked')) {
    function bv_seller_release_is_cancel_blocked(PDO $pdo, array $entry): bool
    {
        $cfg = bv_seller_release_config();
        $statuses = $cfg['active_cancel_statuses'];
        if (!_bv_seller_release_table_exists($pdo, 'order_cancellations') || !_bv_seller_release_has_col($pdo, 'order_cancellations', 'status')) {
            return false;
        }

        $orderItemId = (int)($entry['order_item_id'] ?? 0);
        $orderId = (int)($entry['order_id'] ?? 0);
        $sellerId = (int)($entry['seller_id'] ?? 0);
        $ph = implode(',', array_fill(0, count($statuses), '?'));

        if ($orderItemId > 0 && _bv_seller_release_table_exists($pdo, 'order_cancellation_items') && _bv_seller_release_has_col($pdo, 'order_cancellation_items', 'order_item_id') && _bv_seller_release_has_col($pdo, 'order_cancellation_items', 'cancellation_id')) {
            return _bv_seller_release_scalar_query(
                $pdo,
                "SELECT COUNT(*) FROM order_cancellation_items ci JOIN order_cancellations c ON c.id = ci.cancellation_id WHERE ci.order_item_id = ? AND c.status IN ($ph)",
                array_merge([$orderItemId], $statuses)
            );
        }

        if ($orderId <= 0) {
            return false;
        }

        if (_bv_seller_release_table_exists($pdo, 'order_cancellation_items') && _bv_seller_release_table_exists($pdo, 'order_items') && $sellerId > 0) {
            $sellerCol = _bv_seller_release_first_col($pdo, 'order_cancellation_items', ['seller_user_id', 'seller_id']);
            if ($sellerCol !== null) {
                return _bv_seller_release_scalar_query(
                    $pdo,
                    "SELECT COUNT(*) FROM order_cancellation_items ci JOIN order_cancellations c ON c.id = ci.cancellation_id WHERE ci.order_id = ? AND ci." . _bv_seller_release_ident($sellerCol) . " = ? AND c.status IN ($ph)",
                    array_merge([$orderId, $sellerId], $statuses)
                );
            }
        }

        return _bv_seller_release_scalar_query($pdo, "SELECT COUNT(*) FROM order_cancellations WHERE order_id = ? AND status IN ($ph)", array_merge([$orderId], $statuses));
    }
}

if (!function_exists('_bv_seller_release_item_ready')) {
    function _bv_seller_release_item_ready(PDO $pdo, array $item, array $entry, int $delayDays): bool
    {
        $status = strtolower(trim((string)($item['fulfillment_status'] ?? '')));
        $completedAt = (string)($item['completed_at'] ?? '');
        $hasFulfillmentStatusColumn = array_key_exists('fulfillment_status', $item);		

        if ($hasFulfillmentStatusColumn) {
            if ($status !== 'completed') {
                return false;
            }
        }

        if (array_key_exists('completed_at', $item)) {
            return _bv_seller_release_date_due($completedAt, $delayDays);
        }

        if (array_key_exists('fulfillment_status', $item) && $status === 'completed') {
            foreach (['release_at', 'available_at', 'created_at', 'updated_at'] as $col) {
                if (isset($entry[$col]) && _bv_seller_release_date_due((string)$entry[$col], $col === 'release_at' || $col === 'available_at' ? 0 : $delayDays)) {
                    return true;
                }
            }
        }

        if (!array_key_exists('fulfillment_status', $item)) {
            foreach (['release_at', 'available_at'] as $col) {
                if (isset($entry[$col]) && _bv_seller_release_date_due((string)$entry[$col], 0)) {
                    return true;
                }
            }
        }

        return false;
    }
}

if (!function_exists('bv_seller_release_is_fulfillment_ready')) {
    function bv_seller_release_is_fulfillment_ready(PDO $pdo, array $entry): bool
    {
        $cfg = bv_seller_release_config();
        $delayDays = max(0, (int)$cfg['release_delay_days']);
        if (!_bv_seller_release_table_exists($pdo, 'order_items')) {
            return false;
        }

        $oiCols = _bv_seller_release_columns($pdo, 'order_items');
        $select = ['id'];
        foreach (['order_id', 'seller_id', 'seller_user_id', 'vendor_id', 'fulfillment_status', 'status', 'completed_at', 'delivered_at', 'updated_at', 'created_at'] as $col) {
            if (isset($oiCols[$col])) {
                $alias = $col === 'status' && !isset($oiCols['fulfillment_status']) ? 'fulfillment_status' : $col;
                $select[] = _bv_seller_release_ident($col) . ($alias !== $col ? ' AS ' . _bv_seller_release_ident($alias) : '');
            }
        }
        if (!isset($oiCols['completed_at']) && isset($oiCols['delivered_at'])) {
            $select[] = '`delivered_at` AS `completed_at`';
        }

        $orderItemId = (int)($entry['order_item_id'] ?? 0);
        $orderId = (int)($entry['order_id'] ?? 0);
        $sellerId = (int)($entry['seller_id'] ?? 0);
        $sellerCol = _bv_seller_release_first_col($pdo, 'order_items', ['seller_id', 'seller_user_id', 'vendor_id']);

        if ($orderItemId > 0) {
            $stmt = $pdo->prepare('SELECT ' . implode(', ', array_unique($select)) . ' FROM order_items WHERE id = ? LIMIT 1');
            $stmt->execute([$orderItemId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$item) {
                return false;
            }
            if ($sellerCol !== null && $sellerId > 0 && (int)($item[$sellerCol] ?? 0) !== $sellerId) {
                return false;
            }
            return _bv_seller_release_item_ready($pdo, $item, $entry, $delayDays);
        }

        if ($orderId <= 0 || $sellerCol === null || $sellerId <= 0) {
            return false;
        }

        $stmt = $pdo->prepare('SELECT ' . implode(', ', array_unique($select)) . ' FROM order_items WHERE order_id = ? AND ' . _bv_seller_release_ident($sellerCol) . ' = ?');
        $stmt->execute([$orderId, $sellerId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($items === []) {
            return false;
        }
        foreach ($items as $item) {
            if (!_bv_seller_release_item_ready($pdo, $item, $entry, $delayDays)) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('_bv_seller_release_entry_eligible')) {
    function _bv_seller_release_entry_eligible(PDO $pdo, array $entry, array $options = []): array
    {
        $statusCol = _bv_seller_release_first_col($pdo, 'seller_balance_entries', ['status', 'balance_status', 'entry_status']);
        $cfg = array_replace(bv_seller_release_config(), $options);
        if ($statusCol === null) {
            return ['eligible' => false, 'reason' => 'missing_status_column'];
        }
        $status = strtolower(trim((string)($entry[$statusCol] ?? '')));
        if (!in_array($status, $cfg['pending_statuses'], true)) {
            return ['eligible' => false, 'reason' => 'status_not_releasable', 'status' => $status];
        }
        if (bv_seller_release_is_refund_blocked($pdo, $entry)) {
            return ['eligible' => false, 'reason' => 'refund_blocked'];
        }
        if (bv_seller_release_is_cancel_blocked($pdo, $entry)) {
            return ['eligible' => false, 'reason' => 'cancel_blocked'];
        }
        if (!bv_seller_release_is_fulfillment_ready($pdo, $entry)) {
            return ['eligible' => false, 'reason' => 'fulfillment_not_ready'];
        }
        return ['eligible' => true, 'reason' => 'eligible'];
    }
}

if (!function_exists('bv_seller_release_find_candidates')) {
    function bv_seller_release_find_candidates(PDO $pdo, array $options = []): array
    {
        $cfg = array_replace(bv_seller_release_config(), $options);
        if (!_bv_seller_release_table_exists($pdo, 'seller_balance_entries')) {
            throw new RuntimeException('Required table seller_balance_entries is missing.');
        }
        $cols = _bv_seller_release_columns($pdo, 'seller_balance_entries');
        $statusCol = _bv_seller_release_first_col($pdo, 'seller_balance_entries', ['status', 'balance_status', 'entry_status']);
        if ($statusCol === null || !isset($cols['id'])) {
            throw new RuntimeException('seller_balance_entries must have id and a status/balance_status/entry_status column.');
        }

        $limit = max(1, min(500, (int)($options['limit'] ?? $cfg['batch_limit'])));
        $statuses = array_values(array_intersect($cfg['pending_statuses'], _bv_seller_release_enum_values($pdo, 'seller_balance_entries', $statusCol) ?: $cfg['pending_statuses']));
        if ($statuses === []) {
            return [];
        }

        $where = [_bv_seller_release_ident($statusCol) . ' IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')'];
        $params = $statuses;
        if (isset($cols['amount'])) {
            $where[] = '`amount` > 0';
        }
        if (isset($cols['release_at'])) {
            $where[] = '(`release_at` IS NULL OR `release_at` <= NOW())';
        } elseif (isset($cols['available_at'])) {
            $where[] = '(`available_at` IS NULL OR `available_at` <= NOW())';
        }
        if (!empty($options['seller_id'])) {
            $where[] = '`seller_id` = ?';
            $params[] = (int)$options['seller_id'];
        }

        $orderParts = [];
        foreach (['release_at', 'available_at', 'created_at', 'id'] as $col) {
            if (isset($cols[$col])) {
                $orderParts[] = _bv_seller_release_ident($col) . ' ASC';
            }
        }

        $sql = 'SELECT * FROM seller_balance_entries WHERE ' . implode(' AND ', $where)
            . ' ORDER BY ' . implode(', ', $orderParts ?: ['id ASC']) . ' LIMIT ' . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('bv_seller_release_entry')) {
    function bv_seller_release_entry(PDO $pdo, int $entryId, array $options = []): array
    {
        $cfg = array_replace(bv_seller_release_config(), $options);
        $dryRun = (bool)($options['dry_run'] ?? $cfg['dry_run']);
        $base = ['entry_id' => $entryId, 'ok' => false, 'released' => false, 'blocked' => false, 'dry_run' => $dryRun];

        if ($entryId <= 0) {
            return $base + ['error' => 'invalid_entry_id'];
        }
        if (!_bv_seller_release_table_exists($pdo, 'seller_balance_entries')) {
            return $base + ['error' => 'missing_seller_balance_entries'];
        }
        $cols = _bv_seller_release_columns($pdo, 'seller_balance_entries');
        $statusCol = _bv_seller_release_first_col($pdo, 'seller_balance_entries', ['status', 'balance_status', 'entry_status']);
        if ($statusCol === null) {
            return $base + ['error' => 'missing_status_column'];
        }

        $load = static function (bool $forUpdate) use ($pdo, $entryId): array {
            $sql = 'SELECT * FROM seller_balance_entries WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$entryId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        };

        try {
            if ($dryRun) {
                $entry = $load(false);
                if (!$entry) {
                    return $base + ['error' => 'entry_not_found'];
                }
                $eligibility = _bv_seller_release_entry_eligible($pdo, $entry, $options);
                if (empty($eligibility['eligible'])) {
                    return $base + ['blocked' => true, 'reason' => $eligibility['reason'] ?? 'blocked'];
                }
                bv_seller_release_log('dry_run_release', ['entry_id' => $entryId, 'seller_id' => (int)($entry['seller_id'] ?? 0), 'amount' => (string)($entry['amount'] ?? '')]);
                return $base + ['ok' => true, 'released' => true, 'reason' => 'dry_run_eligible'];
            }

            $pdo->beginTransaction();
            $entry = $load(true);
            if (!$entry) {
                $pdo->rollBack();
                return $base + ['error' => 'entry_not_found'];
            }
            $eligibility = _bv_seller_release_entry_eligible($pdo, $entry, $options);
            if (empty($eligibility['eligible'])) {
                $pdo->rollBack();
                return $base + ['blocked' => true, 'reason' => $eligibility['reason'] ?? 'blocked'];
            }

            $enum = _bv_seller_release_enum_values($pdo, 'seller_balance_entries', $statusCol);
            $releaseStatus = 'available';
            foreach ($cfg['released_status_preference'] as $candidate) {
                if ($enum === [] || in_array($candidate, $enum, true)) {
                    $releaseStatus = $candidate;
                    break;
                }
            }

            $sets = [_bv_seller_release_ident($statusCol) . ' = ?'];
            $params = [$releaseStatus];
            if (isset($cols['released_at'])) {
                $sets[] = '`released_at` = COALESCE(`released_at`, NOW())';
            }
            if (isset($cols['available_at'])) {
                $sets[] = '`available_at` = COALESCE(`available_at`, NOW())';
            }
            if (isset($cols['updated_at'])) {
                $sets[] = '`updated_at` = NOW()';
            }
           $oldStatus = (string)($entry[$statusCol] ?? '');			
            $params[] = $entryId;
            $params[] = $oldStatus;			

           $stmt = $pdo->prepare('UPDATE seller_balance_entries SET ' . implode(', ', $sets) . ' WHERE id = ? AND ' . _bv_seller_release_ident($statusCol) . ' = ? LIMIT 1');  
            $stmt->execute($params);
           if ($stmt->rowCount() <= 0) {
                $pdo->rollBack();
                return $base + ['blocked' => true, 'reason' => 'stale_status_or_already_released'];
            }			
            $pdo->commit();
			
           $auditPayload = [
                'event' => 'auto_release_completed',
                'entry_id' => $entryId,
                'seller_id' => (int)($entry['seller_id'] ?? 0),
                'amount' => (string)($entry['amount'] ?? ''),
                'old_status' => $oldStatus,
                'new_status' => $releaseStatus,
                'source' => 'seller_balance_auto_release',
                'dry_run' => false,
            ];
            bv_seller_release_log('released', ['entry_id' => $entryId, 'seller_id' => (int)($entry['seller_id'] ?? 0), 'amount' => (string)($entry['amount'] ?? ''), 'status' => $releaseStatus]);
            _bv_seller_release_balance_audit($auditPayload);			
            return $base + ['ok' => true, 'released' => true, 'status' => $releaseStatus];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            bv_seller_release_log('release_error', ['entry_id' => $entryId, 'error' => $e->getMessage()]);
            return $base + ['error' => $e->getMessage()];
        }
    }
}

if (!function_exists('bv_seller_release_run')) {
    function bv_seller_release_run(array $options = []): array
    {
        $cfg = array_replace(bv_seller_release_config(), $options);
        $dryRun = (bool)($options['dry_run'] ?? $cfg['dry_run']);
        $result = [
            'ok' => true,
            'dry_run' => $dryRun,
            'checked' => 0,
            'released' => 0,
            'blocked' => 0,
            'errors' => [],
            'items' => [],
        ];

        try {
            $pdo = bv_seller_release_db();
            $candidates = bv_seller_release_find_candidates($pdo, $options);
            foreach ($candidates as $entry) {
                $result['checked']++;
                $entryId = _bv_seller_release_entry_id($entry);
                $item = bv_seller_release_entry($pdo, $entryId, ['dry_run' => $dryRun] + $options);
                $result['items'][] = $item;
               $reason = trim((string)($item['reason'] ?? ''));
                $isBlocked = !empty($item['blocked'])
                    || (
                        empty($item['released'])
                        && empty($item['ok'])
                        && !isset($item['errors'])
                        && $reason !== ''
                    );				
                if (!empty($item['released'])) {
                    $result['released']++;
                } elseif ($isBlocked) { 
                    $result['blocked']++;
                } elseif (!empty($item['error'])) {
                    $result['errors'][] = ['entry_id' => $entryId, 'error' => $item['error']];
                }
            }
            if ($result['errors'] !== []) {
                $result['ok'] = false;
            }
            bv_seller_release_log('run_completed', ['dry_run' => $dryRun, 'checked' => $result['checked'], 'released' => $result['released'], 'blocked' => $result['blocked'], 'errors' => count($result['errors'])]);
        } catch (Throwable $e) {
            $result['ok'] = false;
            $result['errors'][] = ['error' => $e->getMessage()];
            bv_seller_release_log('run_error', ['dry_run' => $dryRun, 'error' => $e->getMessage()]);
        }

        return $result;
    }
}
