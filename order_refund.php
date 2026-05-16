<?php
declare(strict_types=1);

/*
 * Bettavaro Order Refund subsystem helpers.
 */

if (!function_exists('bv_order_refund_boot')) {
    function bv_order_refund_boot(): void
    {
        if (!isset($GLOBALS['bv_order_refund_booted'])) {
            $GLOBALS['bv_order_refund_booted'] = true;
            $GLOBALS['bv_order_refund_tx_level'] = 0;
            $GLOBALS['bv_order_refund_table_cache'] = [];
            $GLOBALS['bv_order_refund_column_cache'] = [];

            $dbBootstrapCandidates = [
                dirname(__DIR__) . '/config/db.php',
                dirname(__DIR__) . '/includes/db.php',
                dirname(__DIR__) . '/db.php',
            ];
            foreach ($dbBootstrapCandidates as $candidate) {
                if (is_file($candidate)) {
                    require_once $candidate;
                    break;
                }
            }
        }
    }
}


if (!function_exists('bv_order_refund_db')) {
    function bv_order_refund_db()
    {
        bv_order_refund_boot();
        $keys = ['pdo', 'PDO', 'db', 'conn', 'mysqli'];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $GLOBALS)) {
                continue;
            }
            $db = $GLOBALS[$key];
            if (bv_order_refund_is_pdo($db) || bv_order_refund_is_mysqli($db)) {
                return $db;
            }
        }

        throw new RuntimeException('Database connection is missing. Expected PDO or mysqli in $GLOBALS.');
    }
}

if (!function_exists('bv_order_refund_is_pdo')) {
    function bv_order_refund_is_pdo($db): bool
    {
        return $db instanceof PDO;
    }
}

if (!function_exists('bv_order_refund_is_mysqli')) {
    function bv_order_refund_is_mysqli($db): bool
    {
        return $db instanceof mysqli;
    }
}

if (!function_exists('bv_order_refund_mysqli_prepare_named')) {
    function bv_order_refund_mysqli_prepare_named(string $sql, array $params): array
    {
        if ($params === []) {
            return [$sql, []];
        }

        $ordered = [];
        $rebuilt = preg_replace_callback('/:[a-zA-Z_][a-zA-Z0-9_]*/', static function (array $m) use ($params, &$ordered): string {
            $name = substr($m[0], 1);
            if (!array_key_exists($name, $params)) {
                throw new RuntimeException('Missing SQL parameter: ' . $name);
            }
            $ordered[] = $params[$name];
            return '?';
        }, $sql);

        if ($rebuilt === null) {
            throw new RuntimeException('Failed to parse SQL parameters for mysqli.');
        }

        return [$rebuilt, $ordered];
    }
}

if (!function_exists('bv_order_refund_query_all')) {
    function bv_order_refund_query_all(string $sql, array $params = []): array
    {
        $db = bv_order_refund_db();

        if (bv_order_refund_is_pdo($db)) {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('PDO prepare failed.');
            }
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        }

        [$msql, $ordered] = bv_order_refund_mysqli_prepare_named($sql, $params);
        $stmt = $db->prepare($msql);
        if (!$stmt) {
            throw new RuntimeException('mysqli prepare failed: ' . $db->error);
        }
        if ($ordered !== []) {
            $types = str_repeat('s', count($ordered));
            $stmt->bind_param($types, ...$ordered);
        }
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('mysqli execute failed: ' . $error);
        }
        $result = $stmt->get_result();
        if (!$result) {
            $stmt->close();
            return [];
        }
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('bv_order_refund_query_one')) {
    function bv_order_refund_query_one(string $sql, array $params = []): ?array
    {
        $rows = bv_order_refund_query_all($sql, $params);
        return $rows[0] ?? null;
    }
}

if (!function_exists('bv_order_refund_execute')) {
    function bv_order_refund_execute(string $sql, array $params = []): array
    {
        $db = bv_order_refund_db();

        if (bv_order_refund_is_pdo($db)) {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('PDO prepare failed.');
            }
            $ok = $stmt->execute($params);
            if (!$ok) {
                throw new RuntimeException('PDO execute failed.');
            }
            return [
                'affected_rows' => $stmt->rowCount(),
                'insert_id' => (int)$db->lastInsertId(),
            ];
        }

        [$msql, $ordered] = bv_order_refund_mysqli_prepare_named($sql, $params);
        $stmt = $db->prepare($msql);
        if (!$stmt) {
            throw new RuntimeException('mysqli prepare failed: ' . $db->error);
        }
        if ($ordered !== []) {
            $types = str_repeat('s', count($ordered));
            $stmt->bind_param($types, ...$ordered);
        }
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('mysqli execute failed: ' . $error);
        }
        $meta = [
            'affected_rows' => $stmt->affected_rows,
            'insert_id' => (int)$db->insert_id,
        ];
        $stmt->close();
        return $meta;
    }
}

if (!function_exists('bv_order_refund_begin_transaction')) {
    function bv_order_refund_begin_transaction(): void
    {
        bv_order_refund_boot();
        $db = bv_order_refund_db();
        if ((int)$GLOBALS['bv_order_refund_tx_level'] === 0) {
            if (bv_order_refund_is_pdo($db)) {
                $db->beginTransaction();
            } else {
                $db->begin_transaction();
            }
        }
        $GLOBALS['bv_order_refund_tx_level'] = (int)$GLOBALS['bv_order_refund_tx_level'] + 1;
    }
}

if (!function_exists('bv_order_refund_commit')) {
    function bv_order_refund_commit(): void
    {
        bv_order_refund_boot();
        $level = (int)$GLOBALS['bv_order_refund_tx_level'];
        if ($level <= 0) {
            return;
        }

        $level--;
        $GLOBALS['bv_order_refund_tx_level'] = $level;
        if ($level === 0) {
            $db = bv_order_refund_db();
            if (bv_order_refund_is_pdo($db)) {
                $db->commit();
            } else {
                $db->commit();
            }
        }
    }
}

if (!function_exists('bv_order_refund_rollback')) {
    function bv_order_refund_rollback(): void
    {
        bv_order_refund_boot();
        $level = (int)$GLOBALS['bv_order_refund_tx_level'];
        if ($level <= 0) {
            return;
        }
        $GLOBALS['bv_order_refund_tx_level'] = 0;
        $db = bv_order_refund_db();
        if (bv_order_refund_is_pdo($db)) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } else {
            $db->rollback();
        }
    }
}

if (!function_exists('bv_order_refund_require_session')) {
    function bv_order_refund_require_session(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
    }
}

if (!function_exists('bv_order_refund_current_user_id')) {
    function bv_order_refund_current_user_id(): int
    {
        bv_order_refund_require_session();

        $nestedKeys = ['user', 'admin', 'member', 'auth_user'];
        foreach ($nestedKeys as $nk) {
            if (isset($_SESSION[$nk]) && is_array($_SESSION[$nk]) && isset($_SESSION[$nk]['id']) && is_numeric($_SESSION[$nk]['id'])) {
                return (int)$_SESSION[$nk]['id'];
            }
        }

        $keys = ['user_id', 'id', 'admin_id', 'member_id'];
        foreach ($keys as $k) {
            if (isset($_SESSION[$k]) && is_numeric($_SESSION[$k])) {
                return (int)$_SESSION[$k];
            }
        }
        return 0;
    }
}

if (!function_exists('bv_order_refund_current_user_role')) {
    function bv_order_refund_current_user_role(): string
    {
        bv_order_refund_require_session();

        $nestedRoles = [
            ['user', 'role'],
            ['admin', 'role'],
            ['auth_user', 'role'],
        ];
        foreach ($nestedRoles as $pair) {
            [$root, $key] = $pair;
            if (isset($_SESSION[$root]) && is_array($_SESSION[$root]) && isset($_SESSION[$root][$key]) && is_string($_SESSION[$root][$key]) && $_SESSION[$root][$key] !== '') {
                return strtolower(trim($_SESSION[$root][$key]));
            }
        }

        $keys = ['user_role', 'role', 'account_role', 'admin_role'];
        foreach ($keys as $k) {
            if (isset($_SESSION[$k]) && is_string($_SESSION[$k]) && $_SESSION[$k] !== '') {
                return strtolower(trim($_SESSION[$k]));
            }
        }
        return 'guest';
    }
}

if (!function_exists('bv_order_refund_is_admin_role')) {
    function bv_order_refund_is_admin_role(?string $role = null): bool
    {
        $role = strtolower(trim((string)($role ?? bv_order_refund_current_user_role())));
        return in_array($role, ['admin', 'superadmin', 'super_admin', 'owner', 'staff', 'support', 'manager'], true);
    }
}

if (!function_exists('bv_order_refund_now')) {
    function bv_order_refund_now(): string
    {
        return date('Y-m-d H:i:s');
    }
}


if (!function_exists('bv_order_refund_table_exists')) {
    function bv_order_refund_table_exists(string $tableName): bool
    {
        $tableName = trim($tableName);
        if ($tableName === '') {
            throw new InvalidArgumentException('Table name cannot be empty.');
        }

        bv_order_refund_boot();
        if (array_key_exists($tableName, $GLOBALS['bv_order_refund_table_cache'])) {
            return (bool)$GLOBALS['bv_order_refund_table_cache'][$tableName];
        }

        $row = bv_order_refund_query_one(
            'SELECT 1
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
             LIMIT 1',
            ['table_name' => $tableName]
        );

        $exists = is_array($row) && $row !== [];
        $GLOBALS['bv_order_refund_table_cache'][$tableName] = $exists;
        return $exists;
    }
}


if (!function_exists('bv_order_refund_require_tables')) {
    function bv_order_refund_require_tables(): bool
    {
        $required = [
            'order_cancellations',
            'order_cancellation_items',
            'order_refunds',
            'order_refund_items',
            'order_refund_transactions',
            'order_financial_ledger',
        ];

        $missing = [];
        foreach ($required as $table) {
            if (!bv_order_refund_table_exists($table)) {
                $missing[] = $table;
            }
        }
        if ($missing !== []) {
            throw new RuntimeException('Missing required tables: ' . implode(', ', $missing));
        }

        return true;
    }
}

if (!function_exists('bv_order_refund_allowed_statuses')) {
    function bv_order_refund_allowed_statuses(): array
    {
        return [
            'draft',
            'pending_approval',
            'partially_approved',
            'approved',
            'processing',
            'partially_refunded',
            'refunded',
            'rejected',
            'failed',
            'cancelled',
        ];
    }
}

if (!function_exists('bv_order_refund_can_transition')) {
    function bv_order_refund_can_transition(string $from, string $to): bool
    {
        $from = strtolower(trim($from));
        $to = strtolower(trim($to));

        if ($from === $to) {
            return true;
        }

        $map = [
            'draft'              => ['pending_approval', 'cancelled'],
            'pending_approval'   => ['approved', 'partially_approved', 'rejected', 'cancelled'],
            'partially_approved' => ['approved', 'pending_approval', 'rejected', 'cancelled'],
            'approved'           => ['processing', 'cancelled', 'failed'],
            'processing'         => ['partially_refunded', 'refunded', 'failed'],
            'partially_refunded' => ['processing', 'refunded', 'failed'],
            'refunded'           => [],
            'rejected'           => [],
            'failed'             => ['processing', 'cancelled'],
            'cancelled'          => [],
        ];

        return isset($map[$from]) && in_array($to, $map[$from], true);
    }
}

if (!function_exists('bv_order_refund_validate_amount')) {
    function bv_order_refund_validate_amount($amount): float
    {
        if (!is_numeric($amount)) {
            throw new InvalidArgumentException('Refund amount must be numeric.');
        }
        $value = round((float)$amount, 2);
        if ($value < 0) {
            throw new InvalidArgumentException('Refund amount cannot be negative.');
        }
        return $value;
    }
}

if (!function_exists('bv_order_refund_column_exists')) {
    function bv_order_refund_column_exists(string $tableName, string $columnName): bool
    {
        $tableName = trim($tableName);
        $columnName = trim($columnName);
        if ($tableName === '' || $columnName === '') {
            return false;
        }

        $key = $tableName . '.' . $columnName;
        if (isset($GLOBALS['bv_order_refund_column_cache'][$key])) {
            return (bool)$GLOBALS['bv_order_refund_column_cache'][$key];
        }

        $row = bv_order_refund_query_one(
            'SELECT 1
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1',
            [
                'table_name' => $tableName,
                'column_name' => $columnName,
            ]
        );

        $exists = is_array($row) && $row !== [];
        $GLOBALS['bv_order_refund_column_cache'][$key] = $exists;
        return $exists;
    }
}

if (!function_exists('bv_order_refund_round_money')) {
    function bv_order_refund_round_money($amount): float
    {
        if (!is_numeric($amount)) {
            $amount = 0;
        }
        return round((float)$amount, 2);
    }
}

if (!function_exists('bv_order_refund_debug_log')) {
    function bv_order_refund_debug_log(string $event, array $context = []): void
    {
        try {
            $payload = [
                'ts' => bv_order_refund_now(),
                'event' => $event,
                'context' => $context,
            ];
            $line = '[order_refund] ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

            if (isset($GLOBALS['bv_order_refund_logger']) && is_callable($GLOBALS['bv_order_refund_logger'])) {
                try {
                    $GLOBALS['bv_order_refund_logger']($event, $context);
                } catch (Throwable $ignored) {
                }
            }

            @error_log(trim($line));

            $candidates = [
                __DIR__ . '/order_refund.log',
                dirname(__DIR__) . '/logs/order_refund.log',
                sys_get_temp_dir() . '/bettavaro_order_refund.log',
            ];
            foreach ($candidates as $path) {
                if (!is_string($path) || $path === '') {
                    continue;
                }
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                if (@file_put_contents($path, $line, FILE_APPEND | LOCK_EX) !== false) {
                    break;
                }
            }
        } catch (Throwable $ignored) {
        }
    }
}

if (!function_exists('bv_order_refund_notify_event')) {
    function bv_order_refund_notify_event(string $eventKey, int $refundId): array
    {
        try {
            if (!function_exists('bv_notify')) {
                $candidates = [
                    __DIR__ . '/notification_engine.php',
                    dirname(__DIR__) . '/notification_engine.php',
                ];
                foreach ($candidates as $candidate) {
                    if (is_file($candidate)) {
                        require_once $candidate;
                        break;
                    }
                }
            }

            if (!function_exists('bv_notify')) {
                return [
                    'ok' => false,
                    'event' => $eventKey,
                    'refund_id' => (int)$refundId,
                    'error' => 'notification_engine_unavailable',
                ];
            }

            $result = bv_notify($eventKey, ['refund_id' => (int)$refundId]);
            if (!is_array($result)) {
                return [
                    'ok' => false,
                    'event' => $eventKey,
                    'refund_id' => (int)$refundId,
                    'error' => 'notification_engine_invalid_response',
                ];
            }

            return $result;
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'event' => $eventKey,
                'refund_id' => (int)$refundId,
                'error' => $e->getMessage(),
            ];
        }
    }
}

if (!function_exists('bv_order_refund_after_request_created')) {
    function bv_order_refund_after_request_created(int $refundId): void
    {
        try {
            bv_order_refund_notify_event('refund.request.created', (int)$refundId);
        } catch (Throwable $e) {
            bv_order_refund_debug_log('refund_notification_hook_failed', [
                'refund_id' => (int)$refundId,
                'event' => 'refund.request.created',
                'error' => $e->getMessage(),
            ]);
        }
    }
}

if (!function_exists('bv_order_refund_after_completed')) {
    function bv_order_refund_after_completed(int $refundId): void
    {
        try {
            bv_order_refund_notify_event('refund.completed', (int)$refundId);
        } catch (Throwable $e) {
            bv_order_refund_debug_log('refund_notification_hook_failed', [
                'refund_id' => (int)$refundId,
                'event' => 'refund.completed',
                'error' => $e->getMessage(),
            ]);
        }
    }
}

if (!function_exists('bv_order_refund_manual_resend_completed_email')) {
    function bv_order_refund_manual_resend_completed_email(int $refundId, array $actor = []): array
    {
        return [
            'ok' => false,
            'message' => 'Manual resend for completed refund email is disabled. Completed email is sent only once after successful refund processing.',
            'refund_id' => (int)$refundId,
            'status' => '',
        ];
    }
}

if (!function_exists('bv_order_refund_pick_row_value')) {
    function bv_order_refund_pick_row_value(array $row, array $keys, $default = null)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return $row[$key];
            }
        }
        return $default;
    }
}

if (!function_exists('bv_order_refund_build_fee_snapshot_from_row')) {
    function bv_order_refund_build_fee_snapshot_from_row(array $row): array
    {
        return [
            'gross_paid_amount' => bv_order_refund_round_money(bv_order_refund_pick_row_value($row, ['gross_paid_amount', 'gross_amount_paid'], 0)),
            'refundable_gross_amount' => bv_order_refund_round_money(bv_order_refund_pick_row_value($row, ['refundable_gross_amount'], 0)),
            'platform_fee_total' => bv_order_refund_round_money(bv_order_refund_pick_row_value($row, ['platform_fee_total'], 0)),
            'platform_fee_refundable' => bv_order_refund_round_money(bv_order_refund_pick_row_value($row, ['platform_fee_refundable'], 0)),
            'platform_fee_non_refundable' => bv_order_refund_round_money(bv_order_refund_pick_row_value($row, ['platform_fee_non_refundable'], 0)),
            'payment_gateway_fee_total' => bv_order_refund_round_money(bv_order_refund_pick_row_value($row, ['payment_gateway_fee_total', 'gateway_fee_total'], 0)),
            'payment_gateway_fee_refundable' => bv_order_refund_round_money(bv_order_refund_pick_row_value($row, ['payment_gateway_fee_refundable', 'gateway_fee_refundable'], 0)),
            'payment_gateway_fee_non_refundable' => bv_order_refund_round_money(bv_order_refund_pick_row_value($row, ['payment_gateway_fee_non_refundable', 'gateway_fee_non_refundable'], 0)),
            'manual_deduction_amount' => bv_order_refund_round_money(bv_order_refund_pick_row_value($row, ['manual_deduction_amount'], 0)),
            'manual_deduction_reason' => (string)bv_order_refund_pick_row_value($row, ['manual_deduction_reason'], ''),
            'fee_policy_code_snapshot' => (string)bv_order_refund_pick_row_value($row, ['fee_policy_code_snapshot', 'fee_policy_code'], ''),
            'fee_policy_snapshot' => (string)bv_order_refund_pick_row_value($row, ['fee_policy_snapshot'], ''),
            'seller_net_amount_snapshot' => bv_order_refund_round_money(bv_order_refund_pick_row_value($row, ['seller_net_amount_snapshot', 'seller_net_amount'], 0)),
        ];
    }
}

if (!function_exists('bv_order_refund_fee_snapshot_is_empty')) {
    function bv_order_refund_fee_snapshot_is_empty(array $snapshot): bool
    {
        foreach ($snapshot as $v) {
            if (is_numeric($v) && (float)$v > 0) {
                return false;
            }
            if (is_string($v) && trim($v) !== '') {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('bv_order_refund_effective_amount')) {
    function bv_order_refund_effective_amount(array $refund): float
    {
        $actual = bv_order_refund_round_money($refund['actual_refund_amount'] ?? 0);
        if ($actual > 0) {
            return $actual;
        }
        return bv_order_refund_round_money($refund['approved_refund_amount'] ?? 0);
    }
}
if (!function_exists('bv_order_refund_read_actual_amount')) {
    function bv_order_refund_read_actual_amount(array $row): float
    {
        if (array_key_exists('actual_refund_amount', $row)) {
            return bv_order_refund_round_money($row['actual_refund_amount']);
        }
        if (array_key_exists('actual_refunded_amount', $row)) {
            return bv_order_refund_round_money($row['actual_refunded_amount']);
        }
        return 0.0;
    }
}

if (!function_exists('bv_order_refund_call_fee_rebuild_for_refund')) {
    function bv_order_refund_call_fee_rebuild_for_refund(int $refundId): array
    {
        if ($refundId <= 0 || !function_exists('bv_refund_fee_rebuild_for_refund')) {
            return [];
        }
        $result = bv_refund_fee_rebuild_for_refund($refundId);
        return is_array($result) ? $result : [];
    }
}

if (!function_exists('bv_order_refund_resolve_executable_amount')) {
    function bv_order_refund_resolve_executable_amount(int $refundId, array $refundRow = []): float
    {
        $row = $refundRow !== [] ? $refundRow : (bv_order_refund_get_by_id($refundId) ?? []);
        if ($row === []) {
            return 0.0;
        }

        $actual = bv_order_refund_round_money($row['actual_refund_amount'] ?? 0);
        if ($actual > 0) {
            return $actual;
        }

        $rebuilt = bv_order_refund_call_fee_rebuild_for_refund($refundId);
        if ($rebuilt !== []) {
            $row = bv_order_refund_get_by_id($refundId) ?? $row;
            $actual = bv_order_refund_round_money($row['actual_refund_amount'] ?? 0);
            if ($actual > 0) {
                return $actual;
            }
        }

        return bv_order_refund_round_money($row['approved_refund_amount'] ?? 0);
    }
}

if (!function_exists('bv_order_refund_allocate_weighted_amounts')) {
    function bv_order_refund_allocate_weighted_amounts(float $totalAmount, array $weights): array
    {
        $count = count($weights);
        if ($count <= 0) {
            return [];
        }

        $totalCents = (int)round(max(0.0, bv_order_refund_round_money($totalAmount)) * 100);
        if ($totalCents <= 0) {
            return array_fill(0, $count, 0.0);
        }

        $normalized = [];
        $sumWeights = 0.0;
        foreach ($weights as $w) {
            $weight = (float)$w;
            if ($weight < 0) {
                $weight = 0.0;
            }
            $normalized[] = $weight;
            $sumWeights += $weight;
        }

        if ($sumWeights <= 0) {
            $normalized = array_fill(0, $count, 1.0);
            $sumWeights = (float)$count;
        }

        $allocated = [];
        $usedCents = 0;
        for ($i = 0; $i < $count; $i++) {
            if ($i === $count - 1) {
                $lineCents = $totalCents - $usedCents;
            } else {
                $lineCents = (int)round(($totalCents * $normalized[$i]) / $sumWeights);
            }
            if ($lineCents < 0) {
                $lineCents = 0;
            }
            if ($lineCents > ($totalCents - $usedCents)) {
                $lineCents = $totalCents - $usedCents;
            }
            $usedCents += $lineCents;
            $allocated[] = bv_order_refund_round_money($lineCents / 100);
        }

        return $allocated;
    }
}

if (!function_exists('bv_order_refund_sync_item_actual_refunded_amounts')) {
    function bv_order_refund_sync_item_actual_refunded_amounts(int $refundId, float $finalizedAmount, ?string $updatedAt = null): array
    {
        if ($refundId <= 0) {
            return [];
        }

        $actualColumns = [];
        if (bv_order_refund_column_exists('order_refund_items', 'actual_refunded_amount')) {
            $actualColumns[] = 'actual_refunded_amount';
        }
        if (bv_order_refund_column_exists('order_refund_items', 'actual_refund_amount')) {
            $actualColumns[] = 'actual_refund_amount';
        }
        if (bv_order_refund_column_exists('order_refund_items', 'actual_refund_after_fee')) {
            $actualColumns[] = 'actual_refund_after_fee';
        }
        if ($actualColumns === []) {
            return [];
        }

        $items = bv_order_refund_query_all(
            'SELECT * FROM order_refund_items WHERE refund_id = :refund_id ORDER BY id ASC FOR UPDATE',
            ['refund_id' => $refundId]
        );
        if ($items === []) {
            return [];
        }

        $finalizedAmount = bv_order_refund_round_money(max(0.0, $finalizedAmount));
        $lineAmounts = [];
        $count = count($items);
        if ($count === 1) {
            $lineAmounts[] = $finalizedAmount;
        } else {
            $weights = [];
            foreach ($items as $item) {
                $weight = bv_order_refund_round_money($item['approved_refund_amount'] ?? 0);
                if ($weight <= 0) {
                    $weight = bv_order_refund_round_money($item['requested_refund_amount'] ?? 0);
                }
                if ($weight <= 0) {
                    $weight = bv_order_refund_round_money(
                        bv_order_refund_pick_row_value($item, ['line_total_snapshot', 'total_snapshot', 'line_total', 'subtotal_snapshot', 'price_snapshot'], 0)
                    );
                }
                if ($weight < 0) {
                    $weight = 0.0;
                }
                $weights[] = $weight;
            }
            $lineAmounts = bv_order_refund_allocate_weighted_amounts($finalizedAmount, $weights);
        }

        $updatedAt = $updatedAt ?? bv_order_refund_now();
        $distributed = [];
        foreach ($items as $idx => $item) {
            $lineActual = bv_order_refund_round_money($lineAmounts[$idx] ?? 0);
            $itemSet = ['updated_at = :updated_at'];
            $itemParams = [
                'updated_at' => $updatedAt,
                'id' => (int)$item['id'],
            ];
            foreach ($actualColumns as $col) {
                $itemSet[] = $col . ' = :' . $col;
                $itemParams[$col] = $lineActual;
            }

            bv_order_refund_execute(
                'UPDATE order_refund_items SET ' . implode(', ', $itemSet) . ' WHERE id = :id',
                $itemParams
            );
            $distributed[] = [
                'id' => (int)$item['id'],
                'amount' => $lineActual,
            ];
        }

        return $distributed;
    }
}

if (!function_exists('bv_order_refund_require_fee_engine')) {
    function bv_order_refund_require_fee_engine(): bool
    {
        if (isset($GLOBALS['bv_order_refund_fee_engine_loaded'])) {
            return (bool)$GLOBALS['bv_order_refund_fee_engine_loaded'];
        }

        $paths = [
            __DIR__ . '/refund_fee_engine.php',
            dirname(__DIR__) . '/includes/refund_fee_engine.php',
            dirname(__DIR__) . '/public_html/includes/refund_fee_engine.php',
            dirname(__DIR__, 2) . '/public_html/includes/refund_fee_engine.php',
        ];

        $loaded = false;
        foreach ($paths as $path) {
            if (is_file($path)) {
                require_once $path;
                $loaded = true;
                break;
            }
        }

        $GLOBALS['bv_order_refund_fee_engine_loaded'] = $loaded;
        return $loaded;
    }
}

if (!function_exists('bv_order_refund_generate_code')) {
    function bv_order_refund_generate_code(): string
    {
        return 'RFN-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }
}

if (!function_exists('bv_order_refund_get_by_id')) {
    function bv_order_refund_get_by_id(int $refundId): ?array
    {
        if ($refundId <= 0) {
            throw new InvalidArgumentException('Invalid refund ID.');
        }
        return bv_order_refund_query_one('SELECT * FROM order_refunds WHERE id = :id LIMIT 1', ['id' => $refundId]);
    }
}

if (!function_exists('bv_order_refund_get_by_code')) {
    function bv_order_refund_get_by_code(string $refundCode): ?array
    {
        $refundCode = trim($refundCode);
        if ($refundCode === '') {
            throw new InvalidArgumentException('Refund code is required.');
        }
        return bv_order_refund_query_one('SELECT * FROM order_refunds WHERE refund_code = :code LIMIT 1', ['code' => $refundCode]);
    }
}

if (!function_exists('bv_order_refund_get_by_cancellation_id')) {
    function bv_order_refund_get_by_cancellation_id(int $cancellationId): ?array
    {
        if ($cancellationId <= 0) {
            throw new InvalidArgumentException('Invalid cancellation ID.');
        }
        return bv_order_refund_query_one('SELECT * FROM order_refunds WHERE order_cancellation_id = :cid ORDER BY id DESC LIMIT 1', ['cid' => $cancellationId]);
    }
}

if (!function_exists('bv_order_refund_get_items')) {
    function bv_order_refund_get_items(int $refundId): array
    {
        if ($refundId <= 0) {
            throw new InvalidArgumentException('Invalid refund ID.');
        }
        return bv_order_refund_query_all('SELECT * FROM order_refund_items WHERE refund_id = :rid ORDER BY id ASC', ['rid' => $refundId]);
    }
}

if (!function_exists('bv_order_refund_get_transactions')) {
    function bv_order_refund_get_transactions(int $refundId): array
    {
        if ($refundId <= 0) {
            throw new InvalidArgumentException('Invalid refund ID.');
        }
        return bv_order_refund_query_all('SELECT * FROM order_refund_transactions WHERE refund_id = :rid ORDER BY id DESC', ['rid' => $refundId]);
    }
}

if (!function_exists('bv_order_refund_build_filter_where')) {
    function bv_order_refund_build_filter_where(array $filters): array
    {
        $where = [];
        $params = [];

        if (isset($filters['status']) && $filters['status'] !== '') {
            $where[] = 'r.status = :status';
            $params['status'] = (string)$filters['status'];
        }
        if (isset($filters['refund_mode']) && $filters['refund_mode'] !== '') {
            $where[] = 'r.refund_mode = :refund_mode';
            $params['refund_mode'] = (string)$filters['refund_mode'];
        }
        if (isset($filters['refund_source']) && $filters['refund_source'] !== '') {
            $where[] = 'r.refund_source = :refund_source';
            $params['refund_source'] = (string)$filters['refund_source'];
        }
        if (isset($filters['order_id']) && is_numeric($filters['order_id'])) {
            $where[] = 'r.order_id = :order_id';
            $params['order_id'] = (int)$filters['order_id'];
        }
        if (isset($filters['cancellation_id']) && is_numeric($filters['cancellation_id'])) {
            $where[] = 'r.order_cancellation_id = :cancellation_id';
            $params['cancellation_id'] = (int)$filters['cancellation_id'];
        }
        if (isset($filters['date_from']) && $filters['date_from'] !== '') {
            $where[] = 'r.created_at >= :date_from';
            $params['date_from'] = (string)$filters['date_from'];
        }
        if (isset($filters['date_to']) && $filters['date_to'] !== '') {
            $where[] = 'r.created_at <= :date_to';
            $params['date_to'] = (string)$filters['date_to'];
        }
        if (isset($filters['keyword']) && trim((string)$filters['keyword']) !== '') {
            $where[] = '(r.refund_code LIKE :kw OR r.refund_reason_text LIKE :kw OR r.admin_note LIKE :kw OR r.internal_note LIKE :kw)';
            $params['kw'] = '%' . trim((string)$filters['keyword']) . '%';
        }

        return [
            'sql' => $where === [] ? '1=1' : implode(' AND ', $where),
            'params' => $params,
        ];
    }
}

if (!function_exists('bv_order_refund_list')) {
    function bv_order_refund_list(array $filters = []): array
    {
        $f = bv_order_refund_build_filter_where($filters);
        $sql = 'SELECT r.* FROM order_refunds r WHERE ' . $f['sql'] . ' ORDER BY r.id DESC';
        return bv_order_refund_query_all($sql, $f['params']);
    }
}

if (!function_exists('bv_order_refund_summary')) {
    function bv_order_refund_summary(array $filters = []): array
    {
        $f = bv_order_refund_build_filter_where($filters);
        $sql = 'SELECT
                    COUNT(*) AS total_count,
                    SUM(CASE WHEN r.status = "pending_approval" THEN 1 ELSE 0 END) AS pending_approval_count,
                    SUM(CASE WHEN r.status = "approved" THEN 1 ELSE 0 END) AS approved_count,
                    SUM(CASE WHEN r.status = "processing" THEN 1 ELSE 0 END) AS processing_count,
                    SUM(CASE WHEN r.status = "partially_refunded" THEN 1 ELSE 0 END) AS partially_refunded_count,
                    SUM(CASE WHEN r.status = "refunded" THEN 1 ELSE 0 END) AS refunded_count,
                    SUM(CASE WHEN r.status = "failed" THEN 1 ELSE 0 END) AS failed_count,
                    SUM(CASE WHEN r.status = "rejected" THEN 1 ELSE 0 END) AS rejected_count,
                    SUM(CASE WHEN r.status = "cancelled" THEN 1 ELSE 0 END) AS cancelled_count,
                    COALESCE(SUM(r.requested_refund_amount), 0) AS requested_total,
                    COALESCE(SUM(r.approved_refund_amount), 0) AS approved_total,
                    COALESCE(SUM(r.actual_refunded_amount), 0) AS refunded_total
                FROM order_refunds r
                WHERE ' . $f['sql'];
        return bv_order_refund_query_one($sql, $f['params']) ?? [];
    }
}

if (!function_exists('bv_order_refund_terminal_statuses')) {
    function bv_order_refund_terminal_statuses(): array
    {
        return ['cancelled', 'canceled', 'refunded', 'completed', 'failed'];
    }
}

if (!function_exists('bv_order_refund_active_request_statuses')) {
    function bv_order_refund_active_request_statuses(): array
    {
        return ['draft', 'pending_approval', 'approved', 'processing', 'partially_refunded'];
    }
}

if (!function_exists('bv_order_refund_normalize_order_status')) {
    function bv_order_refund_normalize_order_status($status): string
    {
        return strtolower(trim((string)$status));
    }
}

if (!function_exists('bv_order_refund_normalize_payment_status')) {
    function bv_order_refund_normalize_payment_status($status): string
    {
        return strtolower(trim((string)$status));
    }
}

if (!function_exists('bv_order_refund_is_terminal_status')) {
    function bv_order_refund_is_terminal_status(string $status): bool
    {
        return in_array(bv_order_refund_normalize_order_status($status), bv_order_refund_terminal_statuses(), true);
    }
}

if (!function_exists('bv_order_refund_is_paid_payment_status')) {
    function bv_order_refund_is_paid_payment_status(string $paymentStatus): bool
    {
        $paymentStatus = bv_order_refund_normalize_payment_status($paymentStatus);
        return in_array($paymentStatus, ['paid', 'succeeded', 'success', 'completed', 'captured'], true);
    }
}

if (!function_exists('bv_order_refund_is_unpaid_payment_status')) {
    function bv_order_refund_is_unpaid_payment_status(string $paymentStatus): bool
    {
        $paymentStatus = bv_order_refund_normalize_payment_status($paymentStatus);
        return in_array($paymentStatus, ['unpaid', 'pending', 'failed', 'void', 'refunded'], true);
    }
}

if (!function_exists('bv_order_refund_get_order_by_id')) {
    function bv_order_refund_get_order_by_id(int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }

        return bv_order_refund_query_one('SELECT * FROM orders WHERE id = :id LIMIT 1', ['id' => $orderId]);
    }
}

if (!function_exists('bv_order_refund_get_items_by_order_id')) {
    function bv_order_refund_get_items_by_order_id(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        if (!bv_order_refund_table_exists('order_items')) {
            return [];
        }

        return bv_order_refund_query_all(
            'SELECT * FROM order_items WHERE order_id = :order_id ORDER BY id ASC',
            ['order_id' => $orderId]
        );
    }
}

if (!function_exists('bv_order_refund_find_latest_by_order_id')) {
    function bv_order_refund_find_latest_by_order_id(int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }

        return bv_order_refund_query_one('SELECT * FROM order_refunds WHERE order_id = :order_id ORDER BY id DESC LIMIT 1', ['order_id' => $orderId]);
    }
}

if (!function_exists('bv_order_refund_find_open_by_order_id')) {
    function bv_order_refund_find_open_by_order_id(int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }

        $statuses = bv_order_refund_active_request_statuses();
        $placeholders = [];
        $params = ['order_id' => $orderId];
        foreach ($statuses as $i => $status) {
            $key = 's' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $status;
        }

        $sql = 'SELECT * FROM order_refunds WHERE order_id = :order_id AND status IN (' . implode(',', $placeholders) . ') ORDER BY id DESC LIMIT 1';
        return bv_order_refund_query_one($sql, $params);
    }
}

if (!function_exists('bv_order_refund_has_successful_refund_for_order')) {
    function bv_order_refund_has_successful_refund_for_order(int $orderId): bool
    {
        if ($orderId <= 0) {
            return false;
        }

        $row = bv_order_refund_query_one(
            'SELECT id FROM order_refunds
             WHERE order_id = :order_id
               AND (status = :status_refunded OR status = :status_partial OR actual_refunded_amount > 0)
             ORDER BY id DESC
             LIMIT 1',
            [
                'order_id' => $orderId,
                'status_refunded' => 'refunded',
                'status_partial' => 'partially_refunded',
            ]
        );

        return $row !== null;
    }
}

if (!function_exists('bv_order_refund_find_cancellation_by_order_id')) {
    function bv_order_refund_find_cancellation_by_order_id(int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }

        return bv_order_refund_query_one(
            'SELECT * FROM order_cancellations
             WHERE order_id = :order_id
             ORDER BY id DESC
             LIMIT 1',
            ['order_id' => $orderId]
        );
    }
}

if (!function_exists('bv_order_refund_ensure_cancellation_bridge')) {
    function bv_order_refund_ensure_cancellation_bridge(array $order, array $data = []): ?array
    {
        $orderId = (int)($order['id'] ?? 0);
        if ($orderId <= 0 || !bv_order_refund_table_exists('order_cancellations')) {
            return null;
        }

        $existing = bv_order_refund_find_cancellation_by_order_id($orderId);
        if ($existing) {
            return $existing;
        }

        $now = bv_order_refund_now();
        $actorUserId = isset($data['requested_by_user_id']) && is_numeric($data['requested_by_user_id'])
            ? (int)$data['requested_by_user_id']
            : bv_order_refund_current_user_id();
        $actorRole = (string)($data['requested_by_role'] ?? $data['actor_role'] ?? 'system');
        if ($actorRole === '') {
            $actorRole = 'system';
        }

        $total = bv_order_refund_round_money($order['total'] ?? $order['grand_total'] ?? 0);
        $subtotal = bv_order_refund_round_money($order['subtotal'] ?? 0);
        $discount = bv_order_refund_round_money($order['discount_total'] ?? $order['discount_amount'] ?? 0);
        $shipping = bv_order_refund_round_money($order['shipping_total'] ?? $order['shipping_amount'] ?? 0);

        $result = bv_order_refund_execute(
            'INSERT INTO order_cancellations
             (order_id, cancel_source, cancel_reason_code, cancel_reason_text,
              status, currency, subtotal_before_discount_snapshot, discount_amount_snapshot,
              shipping_amount_snapshot, total_snapshot, refundable_amount, approved_refund_amount,
              order_status_snapshot, payment_state_snapshot, order_source_snapshot,
              requested_by_user_id, requested_by_role, created_at, updated_at)
             VALUES
             (:order_id, :cancel_source, :cancel_reason_code, :cancel_reason_text,
              :status, :currency, :subtotal_before_discount_snapshot, :discount_amount_snapshot,
              :shipping_amount_snapshot, :total_snapshot, :refundable_amount, :approved_refund_amount,
              :order_status_snapshot, :payment_state_snapshot, :order_source_snapshot,
              :requested_by_user_id, :requested_by_role, :created_at, :updated_at)',
            [
                'order_id' => $orderId,
                'cancel_source' => 'system',
                'cancel_reason_code' => (string)($data['refund_reason_code'] ?? 'refund_request_bridge'),
                'cancel_reason_text' => (string)($data['refund_reason_text'] ?? 'Auto-generated cancellation bridge for refund request'),
                'status' => (string)($data['cancellation_status'] ?? 'approved'),
                'currency' => (string)($order['currency'] ?? 'USD'),
                'subtotal_before_discount_snapshot' => $subtotal,
                'discount_amount_snapshot' => $discount,
                'shipping_amount_snapshot' => $shipping,
                'total_snapshot' => $total,
                'refundable_amount' => $total,
                'approved_refund_amount' => $total,
                'order_status_snapshot' => (string)($order['status'] ?? ''),
                'payment_state_snapshot' => (string)($order['payment_status'] ?? ($order['payment_state'] ?? '')),
                'order_source_snapshot' => (string)($order['order_source'] ?? ''),
                'requested_by_user_id' => $actorUserId,
                'requested_by_role' => $actorRole,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $cancellationId = (int)($result['last_insert_id'] ?? 0);
        if ($cancellationId <= 0) {
            return null;
        }

        $orderItems = bv_order_refund_get_items_by_order_id($orderId);
        foreach ($orderItems as $item) {
            bv_order_refund_execute(
                'INSERT INTO order_cancellation_items
                 (cancellation_id, order_item_id, listing_id, refund_qty, qty,
                  unit_price_snapshot, line_total_snapshot, item_refundable_amount, refund_line_amount,
                  created_at, updated_at)
                 VALUES
                 (:cancellation_id, :order_item_id, :listing_id, :refund_qty, :qty,
                  :unit_price_snapshot, :line_total_snapshot, :item_refundable_amount, :refund_line_amount,
                  :created_at, :updated_at)',
                [
                    'cancellation_id' => $cancellationId,
                    'order_item_id' => (int)($item['id'] ?? 0),
                    'listing_id' => (int)($item['listing_id'] ?? 0),
                    'refund_qty' => (int)($item['qty'] ?? $item['quantity'] ?? 0),
                    'qty' => (int)($item['qty'] ?? $item['quantity'] ?? 0),
                    'unit_price_snapshot' => bv_order_refund_round_money($item['unit_price'] ?? $item['unit_price_snapshot'] ?? 0),
                    'line_total_snapshot' => bv_order_refund_round_money($item['line_total'] ?? $item['line_total_snapshot'] ?? 0),
                    'item_refundable_amount' => bv_order_refund_round_money($item['line_total'] ?? $item['line_total_snapshot'] ?? 0),
                    'refund_line_amount' => bv_order_refund_round_money($item['line_total'] ?? $item['line_total_snapshot'] ?? 0),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        return bv_order_refund_query_one(
            'SELECT * FROM order_cancellations WHERE id = :id LIMIT 1',
            ['id' => $cancellationId]
        );
    }
}

if (!function_exists('bv_order_refund_get_fee_lines')) {
    function bv_order_refund_get_fee_lines(int $refundId): array
    {
        if ($refundId <= 0) {
            return [];
        }

        if (!bv_order_refund_require_fee_engine()) {
            return [];
        }

        $candidates = [
            'bv_refund_fee_engine_get_fee_lines',
            'bv_order_refund_get_fee_lines_from_engine',
            'bv_refund_fee_get_lines',
        ];

        foreach ($candidates as $fn) {
            if (function_exists($fn)) {
                $rows = $fn($refundId);
                return is_array($rows) ? $rows : [];
            }
        }

        return [];
    }
}

if (!function_exists('bv_order_refund_recalculate_fee_summary')) {
    function bv_order_refund_recalculate_fee_summary(int $refundId): array
    {
        if ($refundId <= 0) {
            throw new InvalidArgumentException('Invalid refund ID.');
        }

        $refund = bv_order_refund_get_by_id($refundId);
        if (!$refund) {
            throw new RuntimeException('Refund not found.');
        }

        $headerSnapshot = bv_order_refund_build_fee_snapshot_from_row($refund);
        $fallbackUsed = false;
        if (bv_order_refund_fee_snapshot_is_empty($headerSnapshot)) {
            $orderId = (int)($refund['order_id'] ?? 0);
            if ($orderId > 0) {
                $orderRow = bv_order_refund_get_order_by_id($orderId) ?? [];
                if ($orderRow !== []) {
                    $headerSnapshot = bv_order_refund_build_fee_snapshot_from_row($orderRow);
                    $fallbackUsed = true;
                }
            }
        }

        bv_order_refund_debug_log('recalculate_fee_summary_start', [
            'refund_id' => $refundId,
            'fallback_used' => $fallbackUsed,
            'gross_paid_amount' => $headerSnapshot['gross_paid_amount'] ?? 0,
            'platform_fee_total' => $headerSnapshot['platform_fee_total'] ?? 0,
            'payment_gateway_fee_total' => $headerSnapshot['payment_gateway_fee_total'] ?? 0,
        ]);

        $summary = [];
        $feeEngineLoaded = bv_order_refund_require_fee_engine();
        if ($feeEngineLoaded) {
            $rebuildCandidates = [
                'bv_refund_fee_engine_rebuild',
                'bv_order_refund_fee_engine_rebuild',
                'bv_refund_fee_rebuild',
                'bv_order_refund_rebuild_fee_lines',
            ];
            foreach ($rebuildCandidates as $fn) {
                if (function_exists($fn)) {
                    $result = $fn($refundId);
                    if (is_array($result)) {
                        $summary = $result;
                    }
                    break;
                }
            }
        }

        $headerRequested = bv_order_refund_round_money($refund['requested_refund_amount'] ?? 0);
        $headerApproved = bv_order_refund_round_money($refund['approved_refund_amount'] ?? 0);
        $basisAmount = $headerApproved > 0 ? $headerApproved : $headerRequested;

        $platformFeeLoss = bv_order_refund_round_money(
            $summary['platform_fee_loss']
            ?? $headerSnapshot['platform_fee_non_refundable']
            ?? $headerSnapshot['platform_fee_total']
            ?? 0
        );
        $gatewayFeeLoss = bv_order_refund_round_money(
            $summary['gateway_fee_loss']
            ?? $headerSnapshot['payment_gateway_fee_non_refundable']
            ?? $headerSnapshot['payment_gateway_fee_total']
            ?? 0
        );
        $manualDeduction = bv_order_refund_round_money($summary['manual_deduction_amount'] ?? ($headerSnapshot['manual_deduction_amount'] ?? 0));
        $feeLoss = bv_order_refund_round_money($summary['fee_loss_amount'] ?? ($platformFeeLoss + $gatewayFeeLoss));

        $actual = bv_order_refund_round_money($summary['actual_refund_amount'] ?? ($basisAmount - $feeLoss - $manualDeduction));
        if ($actual < 0) {
            $actual = 0.0;
        }

        $requested = bv_order_refund_round_money($summary['requested_refund_amount'] ?? $headerRequested);
        $approved = bv_order_refund_round_money($summary['approved_refund_amount'] ?? $headerApproved);
        if ($approved <= 0 && $headerApproved > 0) {
            $approved = $headerApproved;
        }

        $headerSet = [
            'requested_refund_amount = :requested_refund_amount',
            'approved_refund_amount = :approved_refund_amount',
            'updated_at = :updated_at',
        ];
        $headerParams = [
            'requested_refund_amount' => $requested,
            'approved_refund_amount' => $approved,
            'updated_at' => bv_order_refund_now(),
            'id' => $refundId,
        ];
        if (bv_order_refund_column_exists('order_refunds', 'actual_refund_amount')) {
            $headerSet[] = 'actual_refund_amount = :actual_refund_amount';
            $headerParams['actual_refund_amount'] = $actual;
        }
        if (bv_order_refund_column_exists('order_refunds', 'fee_loss_amount')) {
            $headerSet[] = 'fee_loss_amount = :fee_loss_amount';
            $headerParams['fee_loss_amount'] = $feeLoss;
        }
        if (bv_order_refund_column_exists('order_refunds', 'platform_fee_loss')) {
            $headerSet[] = 'platform_fee_loss = :platform_fee_loss';
            $headerParams['platform_fee_loss'] = $platformFeeLoss;
        }
        if (bv_order_refund_column_exists('order_refunds', 'gateway_fee_loss')) {
            $headerSet[] = 'gateway_fee_loss = :gateway_fee_loss';
            $headerParams['gateway_fee_loss'] = $gatewayFeeLoss;
        }
        if (bv_order_refund_column_exists('order_refunds', 'manual_deduction_amount')) {
            $headerSet[] = 'manual_deduction_amount = :manual_deduction_amount';
            $headerParams['manual_deduction_amount'] = $manualDeduction;
        }
        bv_order_refund_execute(
            'UPDATE order_refunds SET ' . implode(', ', $headerSet) . ' WHERE id = :id',
            $headerParams
        );

        $items = bv_order_refund_get_items($refundId);
        if ($items !== []) {
            $basis = $approved > 0 ? $approved : $requested;
            $sumApprovedItems = 0.0;
            foreach ($items as $it) {
                $lineBasis = bv_order_refund_round_money($it['approved_refund_amount'] ?? 0);
                if ($lineBasis <= 0) {
                    $lineBasis = bv_order_refund_round_money($it['requested_refund_amount'] ?? 0);
                }
                $sumApprovedItems += $lineBasis;
            }
            $sumApprovedItems = bv_order_refund_round_money($sumApprovedItems);

            $remaining = $actual;
            $count = count($items);
            foreach ($items as $idx => $item) {
                $itemBasis = bv_order_refund_round_money($item['approved_refund_amount'] ?? 0);
                if ($itemBasis <= 0) {
                    $itemBasis = bv_order_refund_round_money($item['requested_refund_amount'] ?? 0);
                }

                if ($idx === $count - 1) {
                    $lineActual = $remaining;
                } elseif ($basis > 0 && $itemBasis > 0) {
                    $ratio = $sumApprovedItems > 0 ? ($itemBasis / $sumApprovedItems) : ($itemBasis / $basis);
                    $lineActual = bv_order_refund_round_money($actual * $ratio);
                } else {
                    $lineActual = 0.0;
                }

                if ($lineActual > $remaining) {
                    $lineActual = $remaining;
                }
                if ($lineActual < 0) {
                    $lineActual = 0.0;
                }
                $remaining = bv_order_refund_round_money($remaining - $lineActual);
                if ($remaining < 0) {
                    $remaining = 0.0;
                }

                $itemSet = ['updated_at = :updated_at'];
                $itemParams = [
                    'updated_at' => bv_order_refund_now(),
                    'id' => (int)$item['id'],
                ];
                if (bv_order_refund_column_exists('order_refund_items', 'actual_refund_amount')) {
                    $itemSet[] = 'actual_refund_amount = :actual_refund_amount';
                    $itemParams['actual_refund_amount'] = $lineActual;
                }
                if (bv_order_refund_column_exists('order_refund_items', 'actual_refund_after_fee')) {
                    $itemSet[] = 'actual_refund_after_fee = :actual_refund_after_fee';
                    $itemParams['actual_refund_after_fee'] = $lineActual;
                }

                bv_order_refund_execute(
                    'UPDATE order_refund_items SET ' . implode(', ', $itemSet) . ' WHERE id = :id',
                    $itemParams
                );
            }
        }

        bv_order_refund_debug_log('recalculate_fee_summary_end', [
            'refund_id' => $refundId,
            'fallback_used' => $fallbackUsed,
            'fee_engine_loaded' => $feeEngineLoaded,
            'fee_loss_amount' => $feeLoss,
            'manual_deduction_amount' => $manualDeduction,
            'actual_refund_amount' => $actual,
        ]);

        return [
            'refund_id' => $refundId,
            'refund_mode' => (string)($summary['refund_mode'] ?? ($refund['refund_mode'] ?? 'partial')),
            'currency' => (string)($summary['currency'] ?? ($refund['currency'] ?? 'USD')),
            'requested_refund_amount' => $requested,
            'approved_refund_amount' => $approved,
            'fee_loss_amount' => $feeLoss,
            'platform_fee_loss' => $platformFeeLoss,
            'gateway_fee_loss' => $gatewayFeeLoss,
            'manual_deduction_amount' => $manualDeduction,
            'actual_refund_amount' => $actual,
            '_degraded' => false,
        ];
    }
}

if (!function_exists('bv_order_refund_fee_summary_safe')) {
    function bv_order_refund_fee_summary_safe(int $refundId): array
    {
        if ($refundId <= 0) {
            return [];
        }

        $refund = bv_order_refund_get_by_id($refundId);
        if (!$refund) {
            return [];
        }

        try {
            return bv_order_refund_recalculate_fee_summary($refundId);
        } catch (Throwable $e) {
            $requested = bv_order_refund_round_money($refund['requested_refund_amount'] ?? 0);
            $approved = bv_order_refund_round_money($refund['approved_refund_amount'] ?? 0);
            $actual = bv_order_refund_read_actual_amount($refund);
            if ($actual <= 0) {
                $actual = $approved > 0 ? $approved : $requested;
            }
            if ($actual < 0) {
                $actual = 0.0;
            }

            $fallback = [
                'refund_id' => $refundId,
                'refund_mode' => (string)($refund['refund_mode'] ?? 'partial'),
                'currency' => (string)($refund['currency'] ?? 'USD'),
                'requested_refund_amount' => $requested,
                'approved_refund_amount' => $approved,
                'fee_loss_amount' => bv_order_refund_round_money($refund['fee_loss_amount'] ?? 0),
                'platform_fee_loss' => bv_order_refund_round_money($refund['platform_fee_loss'] ?? 0),
                'gateway_fee_loss' => bv_order_refund_round_money($refund['gateway_fee_loss'] ?? 0),
                'manual_deduction_amount' => bv_order_refund_round_money($refund['manual_deduction_amount'] ?? 0),
                'actual_refund_amount' => $actual,
                '_degraded' => true,
                '_error' => $e->getMessage(),
            ];

            bv_order_refund_debug_log('fee_summary_safe_catch', [
                'refund_id' => $refundId,
                'error' => $e->getMessage(),
                'fallback' => $fallback,
            ]);

            return $fallback;
        }
    }
}



if (!function_exists('bv_refund_notification_find_listing_seller_email')) {
    function bv_refund_notification_find_listing_seller_email(int $listingId): string
    {
        if ($listingId <= 0) {
            return '';
        }

        try {
            $listing = bv_order_refund_query_one(
                'SELECT seller_id FROM listings WHERE id = :id LIMIT 1',
                ['id' => $listingId]
            );
            $sellerId = (int)($listing['seller_id'] ?? 0);
            if ($sellerId <= 0) {
                return '';
            }

            $user = bv_order_refund_query_one(
                'SELECT email FROM users WHERE id = :id LIMIT 1',
                ['id' => $sellerId]
            );
            $email = trim((string)($user['email'] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        } catch (Throwable $e) {
            if (function_exists('bv_order_refund_debug_log')) {
                bv_order_refund_debug_log('refund_notification_find_seller_email_failed', [
                    'listing_id' => $listingId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return '';
    }
}

if (!function_exists('bv_order_refund_app_url_base')) {
    function bv_order_refund_app_url_base(): string
    {
        $appUrl = defined('APP_URL') ? trim((string)APP_URL) : '';
        if ($appUrl === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
            if ($host !== '') {
                $appUrl = $scheme . '://' . $host;
            }
        }
        return rtrim($appUrl, '/');
    }
}

if (!function_exists('bv_order_refund_resolve_existing_page_path')) {
    function bv_order_refund_resolve_existing_page_path(array $candidates, string $fallback): string
    {
        $roots = [];
        $docRoot = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($docRoot !== '') {
            $roots[] = rtrim($docRoot, '/');
        }
        $roots[] = rtrim(dirname(__DIR__), '/');
        $roots[] = rtrim(__DIR__, '/');
        $roots[] = rtrim(getcwd() ?: '', '/');
        $roots = array_values(array_unique(array_filter($roots, static function (string $root): bool {
            return $root !== '';
        })));

        foreach ($candidates as $candidate) {
            $candidate = '/' . ltrim((string)$candidate, '/');
            foreach ($roots as $root) {
                if (is_file($root . $candidate)) {
                    return $candidate;
                }
            }
        }

        return '/' . ltrim($fallback, '/');
    }
}

if (!function_exists('bv_order_refund_build_link')) {
    function bv_order_refund_build_link(string $path, int $refundId, bool $useId = false): string
    {
        $path = '/' . ltrim($path, '/');
        if ($useId && $refundId > 0) {
            $separator = strpos($path, '?') === false ? '?' : '&';
            $path .= $separator . 'id=' . $refundId;
        }

        $appUrl = bv_order_refund_app_url_base();
        return $appUrl !== '' ? ($appUrl . $path) : $path;
    }
}

if (!function_exists('bv_order_refund_buyer_detail_url')) {
    function bv_order_refund_buyer_detail_url(int $refundId, int $orderId = 0, int $cancellationId = 0): string
    {
        $detailPath = bv_order_refund_resolve_existing_page_path([
            '/member/order_detail.php',
            '/member/orders.php',
        ], '/member/order_detail.php');

        if (basename($detailPath) === 'order_detail.php' && $orderId > 0) {
            $query = [
                'id' => $orderId,
                'refund' => 'requested',
            ];
            if ($refundId > 0) {
                $query['refund_id'] = $refundId;
            }
            if ($cancellationId > 0) {
                $query['cancellation_id'] = $cancellationId;
            }

            $queryString = http_build_query($query);
            $detailPath .= $queryString !== '' ? ('?' . $queryString) : '';
            return bv_order_refund_build_link($detailPath, 0, false);
        }

        return bv_order_refund_build_link($detailPath, 0, false);
    }
}

if (!function_exists('bv_order_refund_buyer_dashboard_url')) {
    function bv_order_refund_buyer_dashboard_url(int $refundId = 0, int $orderId = 0, int $cancellationId = 0): string
    {
        return bv_order_refund_buyer_detail_url($refundId, $orderId, $cancellationId);
    }
}

if (!function_exists('bv_order_refund_seller_detail_url')) {
    function bv_order_refund_seller_detail_url(int $refundId): string
    {
        $detailPath = bv_order_refund_resolve_existing_page_path([
            '/seller/refund_view.php',
            '/seller/refunds.php',
        ], '/seller/refunds.php');
        return bv_order_refund_build_link($detailPath, $refundId, basename($detailPath) === 'refund_view.php');
    }
}

if (!function_exists('bv_order_refund_seller_dashboard_url')) {
    function bv_order_refund_seller_dashboard_url(): string
    {
        $dashboardPath = bv_order_refund_resolve_existing_page_path([
            '/seller/refunds.php',
        ], '/seller/refunds.php');
        return bv_order_refund_build_link($dashboardPath, 0, false);
    }
}

if (!function_exists('bv_order_refund_admin_detail_url')) {
    function bv_order_refund_admin_detail_url(int $refundId): string
    {
        $detailPath = bv_order_refund_resolve_existing_page_path([
            '/admin/refund_view.php',
            '/admin/refunds.php',
        ], '/admin/refunds.php');
        return bv_order_refund_build_link($detailPath, $refundId, basename($detailPath) === 'refund_view.php');
    }
}

if (!function_exists('bv_order_refund_admin_dashboard_url')) {
    function bv_order_refund_admin_dashboard_url(): string
    {
        $dashboardPath = bv_order_refund_resolve_existing_page_path([
            '/admin/refunds.php',
            '/admin/refund_view.php',
        ], '/admin/refunds.php');
        return bv_order_refund_build_link($dashboardPath, 0, false);
    }
}

if (!function_exists('bv_order_refund_role_url_is_safe')) {
    function bv_order_refund_role_url_is_safe(string $url, string $role): bool
    {
        $role = strtolower(trim($role));
        $path = strtolower((string)parse_url($url, PHP_URL_PATH));
        if ($path === '') {
            return false;
        }

        if ($role === 'buyer') {
            if (strpos($path, '/admin/') !== false || strpos($path, '/seller/') !== false) {
                return false;
            }
            return strpos($path, '/member/') === 0;
        }

        if ($role === 'seller') {
            if (strpos($path, '/admin/') !== false) {
                return false;
            }
            return strpos($path, '/seller/') === 0;
        }

        if ($role === 'admin') {
            return strpos($path, '/admin/') === 0;
        }

        return false;
    }
}
if (!function_exists('bv_order_refund_enforce_role_safe_url')) {
    function bv_order_refund_enforce_role_safe_url(string $url, string $role, int $refundId, int $orderId = 0, bool $dashboard = false, int $cancellationId = 0): string
    {
        if ($role === 'buyer') {
            return $dashboard
                ? bv_order_refund_buyer_dashboard_url($refundId, $orderId, $cancellationId)
                : bv_order_refund_buyer_detail_url($refundId, $orderId, $cancellationId);
        }
        if (bv_order_refund_role_url_is_safe($url, $role)) {
            return $url;
        }
        if ($role === 'seller') {
            return $dashboard ? bv_order_refund_seller_dashboard_url() : bv_order_refund_seller_detail_url($refundId);
        }
        return $dashboard ? bv_order_refund_admin_dashboard_url() : bv_order_refund_admin_detail_url($refundId);
    }
}

if (!function_exists('bv_order_refund_resolve_refund_links')) {
    function bv_order_refund_resolve_refund_links(int $refundId, int $orderId = 0, int $cancellationId = 0): array
    {
        $buyerDetailUrl = bv_order_refund_buyer_detail_url($refundId, $orderId, $cancellationId);
        $buyerDashboardUrl = bv_order_refund_buyer_dashboard_url($refundId, $orderId, $cancellationId);
        $sellerDetailUrl = bv_order_refund_seller_detail_url($refundId);
        $sellerDashboardUrl = bv_order_refund_seller_dashboard_url();
        $adminDetailUrl = bv_order_refund_admin_detail_url($refundId);
        $adminDashboardUrl = bv_order_refund_admin_dashboard_url();

        return [
            'detail_url' => $buyerDetailUrl,
            'dashboard_url' => $buyerDashboardUrl,
            'seller_detail_url' => $sellerDetailUrl,
            'seller_dashboard_url' => $sellerDashboardUrl,
            'admin_detail_url' => $adminDetailUrl,
            'admin_dashboard_url' => $adminDashboardUrl,
            'app_url' => bv_order_refund_app_url_base(),
        ];
    }
}


if (!function_exists('bv_order_refund_send_completed_notifications_once')) {
    function bv_order_refund_send_completed_notifications_once(int $refundId): void
    {
        // Process-level guard: prevents double-fire within the same PHP request.
        static $sent = [];
        if ($refundId <= 0 || isset($sent[$refundId])) {
            return;
        }
        $sent[$refundId] = true;

        // DB-backed guard: prevents cross-process duplicate (e.g. webhook replay
        // in a new PHP process). We use a DB-level atomic claim via UPDATE with
        // a NULL-to-timestamp transition. If the column does not exist we fall
        // through gracefully and still fire the notification (existing behaviour).
        $dbGuardPassed = true;
        try {
            if (bv_order_refund_table_exists('order_refunds') &&
                bv_order_refund_column_exists('order_refunds', 'completed_email_sent_at')
            ) {
                // Only proceed if completed_email_sent_at is currently NULL.
                $affected = bv_order_refund_execute(
                    'UPDATE order_refunds
                     SET completed_email_sent_at = :now
                     WHERE id = :id
                       AND completed_email_sent_at IS NULL
                     LIMIT 1',
                    ['now' => bv_order_refund_now(), 'id' => $refundId]
                );
                // affected_rows = 0 means another process already claimed it.
                if ((int)($affected['affected_rows'] ?? 0) === 0) {
                    $dbGuardPassed = false;
                    bv_order_refund_debug_log('completed_email_already_claimed', ['refund_id' => $refundId]);
                }
            }
        } catch (Throwable $guardError) {
            // If the guard query fails (e.g. column does not exist yet), allow the
            // notification to proceed — the static guard above still protects
            // within the same process.
            bv_order_refund_debug_log('completed_email_db_guard_failed', [
                'refund_id' => $refundId,
                'error'     => $guardError->getMessage(),
            ]);
        }

        if (!$dbGuardPassed) {
            return;
        }

        bv_order_refund_after_completed($refundId);
        try {
            bv_refund_queue_notifications($refundId, 'completed');
        } catch (Throwable $notifyError) {
            if (function_exists('bv_order_refund_debug_log')) {
                bv_order_refund_debug_log('refund_notification_queue_failed', [
                    'refund_id' => $refundId,
                    'event'     => 'completed',
                    'error'     => $notifyError->getMessage(),
                ]);
            }
        }
    }
}


if (!function_exists('bv_refund_notification_context')) {
    function bv_refund_notification_context(array $refund, array $order, array $items, array $options = []): array
    {
        $refundId = (int)($refund['id'] ?? 0);
        $orderId = (int)($order['id'] ?? ($refund['order_id'] ?? 0));

        $orderCode = trim((string)($order['order_code'] ?? $order['code'] ?? $refund['order_code_snapshot'] ?? ('ORDER-' . $orderId)));
        if ($orderCode === '') {
            $orderCode = 'ORDER-' . $orderId;
        }

        $buyerEmail = '';
        foreach (['buyer_email', 'ship_email', 'email'] as $key) {
            $v = trim((string)($order[$key] ?? ''));
            if ($v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL)) {
                $buyerEmail = $v;
                break;
            }
        }

        $buyerName = '';
        foreach (['buyer_name', 'ship_name', 'customer_name'] as $key) {
            $v = trim((string)($order[$key] ?? ''));
            if ($v !== '') {
                $buyerName = $v;
                break;
            }
        }
        if ($buyerName === '') {
            $buyerName = 'Customer';
        }

        $sellerEmails = [];
        $addSellerEmail = static function (?string $email) use (&$sellerEmails): void {
            $email = trim((string)$email);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return;
            }
            $sellerEmails[strtolower($email)] = $email;
        };

        foreach ($items as $item) {
            $addSellerEmail((string)($item['seller_email'] ?? ''));
            $addSellerEmail((string)($item['merchant_email'] ?? ''));

            if (trim((string)($item['seller_email'] ?? '')) === '' && trim((string)($item['merchant_email'] ?? '')) === '') {
                $listingId = (int)($item['listing_id'] ?? 0);
                if ($listingId > 0) {
                    $fallbackSellerEmail = bv_refund_notification_find_listing_seller_email($listingId);
                    if ($fallbackSellerEmail !== '') {
                        $addSellerEmail($fallbackSellerEmail);
                    }
                }
            }
        }

       $requestedRefundAmount = bv_order_refund_round_money($refund['requested_refund_amount'] ?? 0);
        $approvedRefundAmount = bv_order_refund_round_money(
            $refund['approved_refund_amount']
            ?? $refund['requested_refund_amount']
            ?? 0
        );
        $actualRefundedAmount = bv_order_refund_round_money(
            $refund['actual_refunded_amount']
            ?? $refund['actual_refund_amount']
            ?? $refund['approved_refund_amount']
            ?? $refund['requested_refund_amount']
            ?? 0
        );
        $nonRefundableFeeAmount = bv_order_refund_round_money(
            $refund['fee_loss_amount']
            ?? (
                bv_order_refund_round_money($refund['platform_fee_loss'] ?? 0)
                + bv_order_refund_round_money($refund['gateway_fee_loss'] ?? 0)
            )
        );
        $finalRefundStatus = strtolower(trim((string)($refund['status'] ?? '')));
        $refundCode = trim((string)($refund['refund_code'] ?? ('RFN-' . $refundId)));
        if ($refundCode === '') {
            $refundCode = 'RFN-' . $refundId;
        }

        $currency = trim((string)($refund['currency'] ?? $order['currency'] ?? 'USD'));
        if ($currency === '') {
            $currency = 'USD';
        }
        $siteName = defined('APP_NAME') ? trim((string)APP_NAME) : '';
        if ($siteName === '') {
            $siteName = 'Bettavaro';
        }
        $cancellationId = (int)($refund['order_cancellation_id'] ?? 0);
      $links = bv_order_refund_resolve_refund_links($refundId, $orderId, $cancellationId);
        $appUrl = (string)($links['app_url'] ?? '');
        $dashboardUrl = bv_order_refund_enforce_role_safe_url(
            (string)($links['dashboard_url'] ?? '/member/refunds.php'),
            'buyer',
            $refundId,
            $orderId,
            true,
            $cancellationId
        );
        $detailUrl = bv_order_refund_enforce_role_safe_url(
            (string)($links['detail_url'] ?? '/member/refunds.php'),
            'buyer',
            $refundId,
            $orderId,
            false,
            $cancellationId
        );
        $sellerDashboardUrl = bv_order_refund_enforce_role_safe_url(
            (string)($links['seller_dashboard_url'] ?? '/seller/refunds.php'),
            'seller',
            $refundId,
            $orderId,
            true
        );
        $sellerDetailUrl = bv_order_refund_enforce_role_safe_url(
            (string)($links['seller_detail_url'] ?? '/seller/refunds.php'),
            'seller',
            $refundId,
            $orderId,
            false
        );
        $adminDashboardUrl = bv_order_refund_enforce_role_safe_url(
            (string)($links['admin_dashboard_url'] ?? '/admin/refunds.php'),
            'admin',
            $refundId,
            $orderId,
            true
        );
        $adminDetailUrl = bv_order_refund_enforce_role_safe_url(
            (string)($links['admin_detail_url'] ?? '/admin/refunds.php'),
            'admin',
            $refundId,
            $orderId,
            false
        );


        $adminEmail = '';
        foreach ([
            $options['admin_email'] ?? null,
            (defined('ADMIN_EMAIL') ? ADMIN_EMAIL : null),
            (defined('SITE_ADMIN_EMAIL') ? SITE_ADMIN_EMAIL : null),
        ] as $candidate) {
            $candidateEmail = trim((string)$candidate);
            if ($candidateEmail !== '' && filter_var($candidateEmail, FILTER_VALIDATE_EMAIL)) {
                $adminEmail = $candidateEmail;
                break;
            }
        }

        return [
            'refund_id' => $refundId,
           'order_id' => $orderId,
            'cancellation_id' => $cancellationId,
            'order_code' => $orderCode,
            'refund_code' => $refundCode,
            'refund_reason_text' => trim((string)($refund['refund_reason_text'] ?? '')),
            'requested_refund_amount' => $requestedRefundAmount,
            'approved_refund_amount' => $approvedRefundAmount,
            'non_refundable_fee_amount' => $nonRefundableFeeAmount,
            'actual_refunded_amount' => $actualRefundedAmount,
            'final_refund_status' => $finalRefundStatus,
            'currency' => $currency,
            'buyer_email' => $buyerEmail,
            'buyer_name' => $buyerName,
            'seller_emails' => array_values($sellerEmails),
            'admin_email' => $adminEmail,
            'site_name' => $siteName,
            'app_url' => $appUrl,
            'dashboard_url' => $dashboardUrl,
            'detail_url' => $detailUrl,
            'seller_dashboard_url' => $sellerDashboardUrl,
            'seller_detail_url' => $sellerDetailUrl,
			'admin_dashboard_url' => $adminDashboardUrl,
            'admin_detail_url' => $adminDetailUrl,
        ];
    }
}

if (!function_exists('bv_refund_notification_build_payloads')) {
    function bv_refund_notification_build_payloads(array $ctx, string $event, array $options = []): array
    {
        $event = strtolower(trim($event));
         $refundId = (int)($ctx['refund_id'] ?? 0);
        $orderId = (int)($ctx['order_id'] ?? 0);
        $orderCode = (string)($ctx['order_code'] ?? ('ORDER-' . $orderId));
        $cancellationId = (int)($ctx['cancellation_id'] ?? 0);
        $refundCode = (string)($ctx['refund_code'] ?? ('RFN-' . $refundId));
        $siteName = (string)($ctx['site_name'] ?? 'Bettavaro');
        $currency = (string)($ctx['currency'] ?? 'USD');
        $requestedAmount = (float)($ctx['requested_refund_amount'] ?? 0);
        $approvedAmount = (float)($ctx['approved_refund_amount'] ?? $requestedAmount);
        $nonRefundableFeeAmount = (float)($ctx['non_refundable_fee_amount'] ?? 0);
        $actualRefundedAmount = (float)($ctx['actual_refunded_amount'] ?? 0);
        $finalRefundStatus = trim((string)($ctx['final_refund_status'] ?? $event));
        $amount = $event === 'completed'
            ? $actualRefundedAmount
            : $requestedAmount;

        $amountLine = number_format($amount, 2) . ' ' . $currency;
        $requestedAmountLine = number_format($requestedAmount, 2) . ' ' . $currency;
        $approvedAmountLine = number_format($approvedAmount, 2) . ' ' . $currency;
        $nonRefundableFeeLine = number_format($nonRefundableFeeAmount, 2) . ' ' . $currency;
        $actualRefundedAmountLine = number_format($actualRefundedAmount, 2) . ' ' . $currency;
        $reason = trim((string)($ctx['refund_reason_text'] ?? ''));
        $dashboardUrl = (string)($ctx['dashboard_url'] ?? '/member/refunds.php');
        $detailUrl = (string)($ctx['detail_url'] ?? '/member/refund_view.php?id=' . $refundId);
        $sellerDashboardUrl = (string)($ctx['seller_dashboard_url'] ?? $dashboardUrl);
        $sellerDetailUrl = (string)($ctx['seller_detail_url'] ?? $detailUrl);
        $adminDashboardUrl = (string)($ctx['admin_dashboard_url'] ?? $dashboardUrl);
        $adminDetailUrl = (string)($ctx['admin_detail_url'] ?? $detailUrl);

        $dashboardUrl = bv_order_refund_enforce_role_safe_url($dashboardUrl, 'buyer', $refundId, $orderId, true, $cancellationId);
        $detailUrl = bv_order_refund_enforce_role_safe_url($detailUrl, 'buyer', $refundId, $orderId, false, $cancellationId); 
        $sellerDashboardUrl = bv_order_refund_enforce_role_safe_url($sellerDashboardUrl, 'seller', $refundId, $orderId, true);
        $sellerDetailUrl = bv_order_refund_enforce_role_safe_url($sellerDetailUrl, 'seller', $refundId, $orderId, false);
        $adminDashboardUrl = bv_order_refund_enforce_role_safe_url($adminDashboardUrl, 'admin', $refundId, $orderId, true);
        $adminDetailUrl = bv_order_refund_enforce_role_safe_url($adminDetailUrl, 'admin', $refundId, $orderId, false);
		
        if ($event === 'request') {
            $buyerSubject = 'Your Refund Request Has Been Submitted – Order #' . $orderCode;
            $sellerSubject = 'New Refund Request – Order #' . $orderCode;
       } else {
            $buyerSubject = 'Final Refund Summary – Order #' . $orderCode;
            $sellerSubject = 'Final Refund Summary – Order #' . $orderCode;
        }

        if ($event === 'completed') {
            $baseText = [
                $siteName,
                'Final refund result for your order:',
                'Refund code: ' . $refundCode,
                'Order code: #' . $orderCode,
                'Requested refund amount: ' . $requestedAmountLine,
                'Approved refund amount: ' . $approvedAmountLine,
                'Fee deducted / non-refundable fee: ' . $nonRefundableFeeLine,
                'Actual refunded amount: ' . $actualRefundedAmountLine,
                'Final refund status: ' . $finalRefundStatus,
                'Refund detail link: ' . $detailUrl,
            ];
        } else {
            $baseText = [
                $siteName,
                'Order: #' . $orderCode,
                'Amount: ' . $amountLine,
            ];
            if ($reason !== '') {
                $baseText[] = 'Reason: ' . $reason;
            }
            $baseText[] = 'Refund details: ' . $detailUrl;
            $baseText[] = 'Dashboard: ' . $dashboardUrl;
        }


         $buyerEmail = trim((string)($ctx['buyer_email'] ?? ''));
        $payloads = [];
        if ($buyerEmail !== '' && filter_var($buyerEmail, FILTER_VALIDATE_EMAIL)) {
             $buyerDetailUrl = bv_order_refund_enforce_role_safe_url($detailUrl, 'buyer', $refundId, $orderId, false, $cancellationId);
            $buyerDashboardUrl = bv_order_refund_enforce_role_safe_url($dashboardUrl, 'buyer', $refundId, $orderId, true, $cancellationId);
           if ($event === 'completed') {
               $buyerCompletedUrl = bv_order_refund_enforce_role_safe_url(
                    bv_order_refund_build_link('/member/index.php', 0, false),
                    'buyer',
                    $refundId,
                    $orderId,
                    true,
                    $cancellationId
                );  
                $buyerDetailUrl = $buyerCompletedUrl;
                $buyerDashboardUrl = $buyerCompletedUrl;
            }			
          $buyerText = "Hello " . (string)($ctx['buyer_name'] ?? 'Customer') . ",

" . implode("
", $baseText);
            $buyerText = str_replace([$detailUrl, $dashboardUrl], [$buyerDetailUrl, $buyerDashboardUrl], $buyerText);
            if ($event === 'completed') {
                $buyerHtml = '<p>Hello ' . htmlspecialchars((string)($ctx['buyer_name'] ?? 'Customer'), ENT_QUOTES, 'UTF-8') . ',</p>'
                    . '<p>' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</p>'
                    . '<p><strong>Final refund result for your order:</strong><br>'
                    . 'Refund code: <strong>' . htmlspecialchars($refundCode, ENT_QUOTES, 'UTF-8') . '</strong><br>'
                    . 'Order code: <strong>#' . htmlspecialchars($orderCode, ENT_QUOTES, 'UTF-8') . '</strong><br>'
                    . 'Requested refund amount: <strong>' . htmlspecialchars($requestedAmountLine, ENT_QUOTES, 'UTF-8') . '</strong><br>'
                    . 'Approved refund amount: <strong>' . htmlspecialchars($approvedAmountLine, ENT_QUOTES, 'UTF-8') . '</strong><br>'
                    . 'Fee deducted / non-refundable fee: <strong>' . htmlspecialchars($nonRefundableFeeLine, ENT_QUOTES, 'UTF-8') . '</strong><br>'
                    . 'Actual refunded amount: <strong>' . htmlspecialchars($actualRefundedAmountLine, ENT_QUOTES, 'UTF-8') . '</strong><br>'
                    . 'Final refund status: <strong>' . htmlspecialchars($finalRefundStatus, ENT_QUOTES, 'UTF-8') . '</strong>'
                    . '</p>'
                    . '<p><a href="' . htmlspecialchars($buyerDetailUrl, ENT_QUOTES, 'UTF-8') . '">View final refund details</a></p>';
            } else {
                $buyerHtml = '<p>Hello ' . htmlspecialchars((string)($ctx['buyer_name'] ?? 'Customer'), ENT_QUOTES, 'UTF-8') . ',</p>'
                    . '<p>' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</p>'
                    . '<p>Order: <strong>#' . htmlspecialchars($orderCode, ENT_QUOTES, 'UTF-8') . '</strong><br>'
                    . 'Amount: <strong>' . htmlspecialchars($amountLine, ENT_QUOTES, 'UTF-8') . '</strong>'
                    . ($reason !== '' ? '<br>Reason: ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') : '')
                    . '</p>'
					. '<p><a href="' . htmlspecialchars($buyerDetailUrl, ENT_QUOTES, 'UTF-8') . '">View refund details</a><br>'
                    . '<a href="' . htmlspecialchars($buyerDashboardUrl, ENT_QUOTES, 'UTF-8') . '">Open dashboard</a></p>';
            }


             $payloads[] = [
                'recipient_type' => 'buyer',
                'email' => $buyerEmail,
                'job' => [
                    'queue_key' => 'refund_' . $event . '_buyer_' . $refundId . '_' . md5(strtolower($buyerEmail)),
                    'profile' => 'default',
                    'to' => [$buyerEmail],
                    'subject' => $buyerSubject,
                    'html' => $buyerHtml,
                    'text' => $buyerText,
                     'meta' => [
                        'type' => 'refund_notification',
                        'event' => $event,
                        'event_type' => 'refund_' . $event,
                        'target' => 'buyer',
                        'recipient_type' => 'buyer',
                        'refund_id' => $refundId,
                        'order_id' => $orderId,
                        'order_code' => $orderCode,
                    ],
                ],
            ];
        }

        $sellerEmails = isset($ctx['seller_emails']) && is_array($ctx['seller_emails']) ? $ctx['seller_emails'] : [];
        foreach ($sellerEmails as $sellerEmailRaw) {
            $sellerEmail = trim((string)$sellerEmailRaw);
            if ($sellerEmail === '' || !filter_var($sellerEmail, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

             if ($event === 'completed') {
                $sellerSafeDetailUrl = bv_order_refund_enforce_role_safe_url($sellerDetailUrl, 'seller', $refundId, $orderId, false);
                $sellerBaseText = [
                    $siteName,
                    'Final refund result for your order:',
                    'Refund code: ' . $refundCode,
                    'Order code: #' . $orderCode,
                    'Requested refund amount: ' . $requestedAmountLine,
                    'Approved refund amount: ' . $approvedAmountLine,
                    'Fee deducted / non-refundable fee: ' . $nonRefundableFeeLine,
                    'Actual refunded amount: ' . $actualRefundedAmountLine,
                    'Final refund status: ' . $finalRefundStatus,
                    'Refund detail link: ' . $sellerSafeDetailUrl,
                ];
            } else {
                $sellerSafeDetailUrl = bv_order_refund_enforce_role_safe_url($sellerDetailUrl, 'seller', $refundId, $orderId, false);
                $sellerSafeDashboardUrl = bv_order_refund_enforce_role_safe_url($sellerDashboardUrl, 'seller', $refundId, $orderId, true);
                $sellerBaseText = [
                    $siteName,
                    'Order: #' . $orderCode,
                    'Amount: ' . $amountLine,
                ];
                if ($reason !== '') {
                    $sellerBaseText[] = 'Reason: ' . $reason;
                }
                $sellerBaseText[] = 'Refund details: ' . $sellerSafeDetailUrl;
                $sellerBaseText[] = 'Dashboard: ' . $sellerSafeDashboardUrl;
            }

            $sellerText = "Hello Seller,

" . implode("
", $sellerBaseText);
            if ($event === 'completed') {
                $sellerHtml = '<p>Hello Seller,</p>'
                    . '<p>' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</p>'
                    . '<p><strong>Final refund result for your order:</strong><br>'
                    . 'Refund code: <strong>' . htmlspecialchars($refundCode, ENT_QUOTES, 'UTF-8') . '</strong><br>'
                    . 'Order code: <strong>#' . htmlspecialchars($orderCode, ENT_QUOTES, 'UTF-8') . '</strong><br>'
                    . 'Requested refund amount: <strong>' . htmlspecialchars($requestedAmountLine, ENT_QUOTES, 'UTF-8') . '</strong><br>'
                    . 'Approved refund amount: <strong>' . htmlspecialchars($approvedAmountLine, ENT_QUOTES, 'UTF-8') . '</strong><br>'
                    . 'Fee deducted / non-refundable fee: <strong>' . htmlspecialchars($nonRefundableFeeLine, ENT_QUOTES, 'UTF-8') . '</strong><br>'
                    . 'Actual refunded amount: <strong>' . htmlspecialchars($actualRefundedAmountLine, ENT_QUOTES, 'UTF-8') . '</strong><br>'
                    . 'Final refund status: <strong>' . htmlspecialchars($finalRefundStatus, ENT_QUOTES, 'UTF-8') . '</strong>'
                    . '</p>'
                     . '<p><a href="' . htmlspecialchars($sellerSafeDetailUrl, ENT_QUOTES, 'UTF-8') . '">View final refund details</a></p>';
            } else {
                $sellerHtml = '<p>Hello Seller,</p>'
                    . '<p>' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</p>'
                    . '<p>Order: <strong>#' . htmlspecialchars($orderCode, ENT_QUOTES, 'UTF-8') . '</strong><br>'
                    . 'Amount: <strong>' . htmlspecialchars($amountLine, ENT_QUOTES, 'UTF-8') . '</strong>'
                    . ($reason !== '' ? '<br>Reason: ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') : '')
                    . '</p>'
                    . '<p><a href="' . htmlspecialchars($sellerSafeDetailUrl, ENT_QUOTES, 'UTF-8') . '">View refund details</a><br>'
                    . '<a href="' . htmlspecialchars($sellerSafeDashboardUrl, ENT_QUOTES, 'UTF-8') . '">Open dashboard</a></p>';
            }
			
            $payloads[] = [
                'recipient_type' => 'seller',
                'email' => $sellerEmail,
                'job' => [
                    'queue_key' => 'refund_' . $event . '_seller_' . $refundId . '_' . md5(strtolower($sellerEmail)),
                    'profile' => 'support',
                    'to' => [$sellerEmail],
                    'subject' => $sellerSubject,
                    'html' => $sellerHtml,
                    'text' => $sellerText,
                    'meta' => [
                        'type' => 'refund_notification',
                        'event' => $event,
                        'event_type' => 'refund_' . $event,
                        'target' => 'seller',
                        'recipient_type' => 'seller',
                        'refund_id' => $refundId,
                        'order_id' => $orderId,
                        'order_code' => $orderCode,
                    ],
                ],
            ];
        }

        if (!empty($options['send_admin'])) {
             $adminEmail = trim((string)($ctx['admin_email'] ?? ''));
            if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                $adminSafeDetailUrl = bv_order_refund_enforce_role_safe_url($adminDetailUrl, 'admin', $refundId, $orderId, false);
                $adminSafeDashboardUrl = bv_order_refund_enforce_role_safe_url($adminDashboardUrl, 'admin', $refundId, $orderId, true);
                $adminSubject = ($event === 'request' ? 'Refund Request Submitted' : 'Refund Completed') . ' – Order #' . $orderCode;
                $adminText = "Hello Admin,

" . implode("
", $baseText);
                $adminText = str_replace([$detailUrl, $dashboardUrl], [$adminSafeDetailUrl, $adminSafeDashboardUrl], $adminText);
                $adminHtml = '<p>Hello Admin,</p>'
                    . '<p>' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</p>'
                    . '<p>Order: <strong>#' . htmlspecialchars($orderCode, ENT_QUOTES, 'UTF-8') . '</strong><br>'
                    . 'Amount: <strong>' . htmlspecialchars($amountLine, ENT_QUOTES, 'UTF-8') . '</strong>'
                    . ($event === 'request' && $reason !== '' ? '<br>Reason: ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') : '')
                    . '</p>'
                    . '<p><a href="' . htmlspecialchars($adminSafeDetailUrl, ENT_QUOTES, 'UTF-8') . '">View refund details</a><br>'
                    . '<a href="' . htmlspecialchars($adminSafeDashboardUrl, ENT_QUOTES, 'UTF-8') . '">Open dashboard</a></p>';    

                $payloads[] = [
                    'recipient_type' => 'admin',
                    'email' => $adminEmail,
                    'job' => [
                        'queue_key' => 'refund_' . $event . '_admin_' . $refundId . '_' . md5(strtolower($adminEmail)),
                        'profile' => 'support',       
                        'to' => [$adminEmail],
                        'subject' => $adminSubject,
                        'html' => $adminHtml,
                        'text' => $adminText,
                        'meta' => [
                            'type' => 'refund_notification',
                            'event' => $event,
                            'event_type' => 'refund_' . $event,
                            'target' => 'admin',
                            'recipient_type' => 'admin',
                            'refund_id' => $refundId,
                            'order_id' => $orderId,
                            'order_code' => $orderCode,
                        ],
                    ],
                ];
            }
        }

        return $payloads;
    }
}

if (!function_exists('bv_refund_queue_notifications')) {
    function bv_refund_queue_notifications(int $refundId, string $event, array $options = []): array
    {
        $event = strtolower(trim($event));
        if ($refundId <= 0) {
            throw new InvalidArgumentException('Invalid refund id.');
        }
        if (!in_array($event, ['request', 'completed'], true)) {
            throw new InvalidArgumentException('Invalid refund notification event.');
        }

        if (!function_exists('bv_queue_mail')) {
            $mailerFile = __DIR__ . '/mailer.php';
            if (is_file($mailerFile)) {
                require_once $mailerFile;
            }
        }

        if (!function_exists('bv_queue_mail')) {
            return [
                'ok' => false,
                'event' => $event,
                'refund_id' => $refundId,
                'reason' => 'mail_queue_not_available',
                'queued' => 0,
                'results' => [],
                'context' => [],
            ];
        }

        $refund = function_exists('bv_order_refund_get_by_id')
            ? (bv_order_refund_get_by_id($refundId) ?? [])
            : (bv_order_refund_query_one('SELECT * FROM order_refunds WHERE id = :id LIMIT 1', ['id' => $refundId]) ?? []);

        if ($refund === []) {
            throw new RuntimeException('Refund not found.');
        }

        $orderId = (int)($refund['order_id'] ?? 0);
        $order = function_exists('bv_order_refund_get_order_by_id')
            ? (bv_order_refund_get_order_by_id($orderId) ?? [])
            : (bv_order_refund_query_one('SELECT * FROM orders WHERE id = :id LIMIT 1', ['id' => $orderId]) ?? []);

        if ($order === []) {
            throw new RuntimeException('Order not found for refund notification.');
        }

        $items = function_exists('bv_order_refund_get_items')
            ? bv_order_refund_get_items($refundId)
            : bv_order_refund_query_all('SELECT * FROM order_refund_items WHERE refund_id = :rid ORDER BY id ASC', ['rid' => $refundId]);

        $ctx = bv_refund_notification_context($refund, $order, $items, $options);
        $payloads = bv_refund_notification_build_payloads($ctx, $event, $options);

        $results = [];
        $queued = 0;

        foreach ($payloads as $payload) {
            $recipientType = (string)($payload['recipient_type'] ?? 'unknown');
            $email = (string)($payload['email'] ?? '');
            $job = isset($payload['job']) && is_array($payload['job']) ? $payload['job'] : [];

            try {
                $queueResult = bv_queue_mail($job);
                $ok = false;
                if (is_array($queueResult)) {
                    $status = strtolower((string)($queueResult['status'] ?? ''));
                    $ok = !empty($queueResult['ok']) || !empty($queueResult['queued']) || !empty($queueResult['success']) || in_array($status, ['queued', 'success'], true);
                } else {
                    $ok = (bool)$queueResult;
                }

                if ($ok) {
                    $queued++;
                    if (function_exists('bv_order_refund_debug_log')) {
                        bv_order_refund_debug_log('refund_notification_queued', [
                            'refund_id' => $refundId,
                            'event' => $event,
                            'recipient_type' => $recipientType,
                            'email' => $email,
                            'queue_key' => (string)($job['queue_key'] ?? ''),
                        ]);
                    }
                } else {
                    if (function_exists('bv_order_refund_debug_log')) {
                        bv_order_refund_debug_log('refund_notification_queue_failed', [
                            'refund_id' => $refundId,
                            'event' => $event,
                            'recipient_type' => $recipientType,
                            'email' => $email,
                            'result' => $queueResult,
                        ]);
                    }
                }

                $results[] = [
                    'recipient_type' => $recipientType,
                    'email' => $email,
                    'ok' => $ok,
                    'result' => $queueResult,
                ];
            } catch (Throwable $e) {
                if (function_exists('bv_order_refund_debug_log')) {
                    bv_order_refund_debug_log('refund_notification_queue_failed', [
                        'refund_id' => $refundId,
                        'event' => $event,
                        'recipient_type' => $recipientType,
                        'email' => $email,
                        'error' => $e->getMessage(),
                    ]);
                }

                $results[] = [
                    'recipient_type' => $recipientType,
                    'email' => $email,
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'ok' => true,
            'event' => $event,
            'refund_id' => $refundId,
            'queued' => $queued,
            'results' => $results,
            'context' => $ctx,
        ];
    }
}

if (!function_exists('bv_order_refund_map_source')) {
    function bv_order_refund_map_source(string $source): string
    {
        $source = strtolower(trim($source));
        if ($source === 'buyer' || $source === 'buyer_request') {
            return 'buyer_request';
        }
        if ($source === 'seller') {
            return 'seller';
        }
        if ($source === 'admin') {
            return 'admin';
        }
        if ($source === 'system') {
            return 'system';
        }
        return 'buyer_request';
    }
}

if (!function_exists('bv_order_refund_is_allowed')) {
    function bv_order_refund_is_allowed(array $order, int $actorUserId = 0, string $actorRole = 'buyer_request'): bool
    {
        try {
            if ($order === []) {
                return false;
            }

            $orderId = (int)($order['id'] ?? 0);
            if ($orderId <= 0) {
                return false;
            }

            $orderStatus = bv_order_refund_normalize_order_status($order['status'] ?? '');
            $paymentStatus = bv_order_refund_normalize_payment_status($order['payment_status'] ?? $order['payment_state'] ?? '');
            $paymentState = bv_order_refund_normalize_payment_status($order['payment_state'] ?? '');

            if (bv_order_refund_is_terminal_status($orderStatus)) {
                return false;
            }

            if (bv_order_refund_is_unpaid_payment_status($paymentStatus) || bv_order_refund_is_unpaid_payment_status($paymentState)) {
                return false;
            }

            $isPaid = bv_order_refund_is_paid_payment_status($paymentStatus) || bv_order_refund_is_paid_payment_status($paymentState);
            if (!$isPaid) {
                return false;
            }

            if (!in_array($orderStatus, ['paid', 'confirmed', 'processing'], true)) {
                return false;
            }

            $role = strtolower(trim($actorRole));
            if ($role === '') {
                $role = 'buyer_request';
            }

            $isPrivilegedRole = in_array($role, ['admin', 'superadmin', 'super_admin', 'seller', 'system', 'staff', 'support', 'manager'], true);

            if (!$isPrivilegedRole && $actorUserId > 0) {
                $buyerId = 0;
                $buyerKeys = ['buyer_user_id', 'user_id', 'member_id'];
                foreach ($buyerKeys as $key) {
                    if (isset($order[$key]) && is_numeric($order[$key])) {
                        $buyerId = (int)$order[$key];
                        break;
                    }
                }

                if ($buyerId > 0 && $buyerId !== $actorUserId) {
                    return false;
                }
            }

            $open = bv_order_refund_find_open_by_order_id($orderId);
            if ($open) {
                return false;
            }

            if (bv_order_refund_has_successful_refund_for_order($orderId)) {
                return false;
            }

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('bv_order_refund_create_request')) {
    function bv_order_refund_create_request(array $data): array
    {
        bv_order_refund_require_tables();

        $orderId = isset($data['order_id']) ? (int)$data['order_id'] : 0;
        if ($orderId <= 0) {
            throw new RuntimeException('order_id is required.');
        }

        $order = bv_order_refund_get_order_by_id($orderId);
        if (!$order) {
            throw new RuntimeException('Order not found.');
        }

        $actorUserId = 0;
        if (isset($data['actor_user_id']) && is_numeric($data['actor_user_id'])) {
            $actorUserId = (int)$data['actor_user_id'];
        } elseif (isset($data['requested_by_user_id']) && is_numeric($data['requested_by_user_id'])) {
            $actorUserId = (int)$data['requested_by_user_id'];
        } else {
            $actorUserId = bv_order_refund_current_user_id();
        }

        $actorRole = '';
        if (isset($data['actor_role']) && is_string($data['actor_role']) && trim($data['actor_role']) !== '') {
            $actorRole = strtolower(trim((string)$data['actor_role']));
        } elseif (isset($data['requested_by_role']) && is_string($data['requested_by_role']) && trim($data['requested_by_role']) !== '') {
            $actorRole = strtolower(trim((string)$data['requested_by_role']));
        } elseif (isset($data['refund_source']) && is_string($data['refund_source']) && trim($data['refund_source']) !== '') {
            $actorRole = strtolower(trim((string)$data['refund_source']));
        } else {
            $actorRole = bv_order_refund_current_user_role();
        }

        $isPrivilegedActorRole = in_array($actorRole, ['admin', 'superadmin', 'super_admin', 'seller', 'system', 'staff', 'support', 'manager'], true);
        if (!$isPrivilegedActorRole && $actorUserId <= 0) {
            throw new RuntimeException('A logged-in actor is required for refund request.');
        }

        if (!bv_order_refund_is_allowed($order, $actorUserId, $actorRole)) {
            throw new RuntimeException('Refund request is not allowed for this order.');
        }

        $existingOpen = bv_order_refund_find_open_by_order_id($orderId);
        if ($existingOpen) {
            throw new RuntimeException('An active refund request already exists for this order.');
        }

        $cancellationId = 0;
        if (isset($data['order_cancellation_id']) && is_numeric($data['order_cancellation_id'])) {
            $cancellationId = (int)$data['order_cancellation_id'];
        }
        if ($cancellationId <= 0 && isset($data['cancellation_id']) && is_numeric($data['cancellation_id'])) {
            $cancellationId = (int)$data['cancellation_id'];
        }

        $cancellation = null;
        if ($cancellationId > 0) {
            $cancellation = bv_order_refund_query_one('SELECT * FROM order_cancellations WHERE id = :id LIMIT 1', ['id' => $cancellationId]);
            if ($cancellation && (int)($cancellation['order_id'] ?? 0) !== $orderId) {
                $cancellation = null;
                $cancellationId = 0;
            }
        }
       if ($cancellationId <= 0) {
            $cancellation = bv_order_refund_find_cancellation_by_order_id($orderId);
            $cancellationId = (int)($cancellation['id'] ?? 0);
        }

        if ($cancellationId <= 0 || !$cancellation) {
            $cancellation = bv_order_refund_ensure_cancellation_bridge($order, $data);
            $cancellationId = (int)($cancellation['id'] ?? 0);
        }

        if ($cancellationId <= 0 || !$cancellation) {
            throw new RuntimeException('Refund request requires cancellation bridge.');
        }


       $selectedCancellationItemIds = [];
        $selectedCandidates = $data['selected_cancellation_item_ids'] ?? ($data['cancellation_item_ids'] ?? []);
        if (is_array($selectedCandidates)) {
            foreach ($selectedCandidates as $selectedItemId) {
                if (is_numeric($selectedItemId) && (int) $selectedItemId > 0) {
                    $selectedCancellationItemIds[(int) $selectedItemId] = true;
                }
            }
        }
        $selectedCancellationItemIds = array_keys($selectedCancellationItemIds);

        $requestedAmount = 0.0;
        if (!empty($selectedCancellationItemIds)) {
            $inParts = [];
            $params = ['cancellation_id' => $cancellationId];
            foreach (array_values($selectedCancellationItemIds) as $idx => $selectedId) {
                $key = 'sel_id_' . $idx;
                $inParts[] = ':' . $key;
                $params[$key] = (int) $selectedId;
            }
            $selectedRows = bv_order_refund_query_all(
                'SELECT id, item_refundable_amount, refund_line_amount
                 FROM order_cancellation_items
                 WHERE cancellation_id = :cancellation_id AND id IN (' . implode(', ', $inParts) . ')
                 ORDER BY id ASC',
                $params
            );
            if (count($selectedRows) !== count($selectedCancellationItemIds)) {
                throw new RuntimeException('Invalid selected cancellation items.');
            }
            foreach ($selectedRows as $selectedRow) {
                $requestedAmount += bv_order_refund_validate_amount($selectedRow['item_refundable_amount'] ?? ($selectedRow['refund_line_amount'] ?? 0));
            }
            $requestedAmount = round($requestedAmount, 2);
            if ($requestedAmount <= 0) {
                throw new RuntimeException('Selected cancellation items are not refundable.');
            }
        } else {
            if (isset($data['requested_refund_amount']) && is_numeric($data['requested_refund_amount'])) {
                $requestedAmount = bv_order_refund_validate_amount($data['requested_refund_amount']);
            }

            if ($requestedAmount <= 0) {
                $requestedAmount = bv_order_refund_validate_amount(
                    $cancellation['approved_refund_amount']
                    ?? $cancellation['refundable_amount']
                    ?? $cancellation['total_snapshot']
                    ?? $order['total']
                    ?? 0
                );
            }
        }

        $safeMax = bv_order_refund_validate_amount($cancellation['refundable_amount'] ?? ($cancellation['total_snapshot'] ?? ($order['total'] ?? $requestedAmount)));
        if ($safeMax > 0 && $requestedAmount > $safeMax) {
            $requestedAmount = $safeMax;
        }
        $requestedAmount = round(max(0.0, $requestedAmount), 2);

        $requestSource = bv_order_refund_map_source((string)($data['refund_source'] ?? $actorRole));
        $requestedByRole = bv_order_refund_map_source((string)($data['requested_by_role'] ?? $actorRole));

        $payload = [
            'order_id' => $orderId,
            'cancellation_id' => $cancellationId,
            'requested_refund_amount' => $requestedAmount,
            'refund_reason_code' => (string)($data['refund_reason_code'] ?? ($cancellation['cancel_reason_code'] ?? '')),
            'refund_reason_text' => (string)($data['refund_reason_text'] ?? ($cancellation['cancel_reason_text'] ?? '')),
            'refund_source' => $requestSource,
            'requested_by_user_id' => isset($data['requested_by_user_id']) && is_numeric($data['requested_by_user_id'])
                ? (int)$data['requested_by_user_id']
                : $actorUserId,
            'requested_by_role' => $requestedByRole,
            'actor_user_id' => $actorUserId,
            'actor_role' => $requestedByRole,
            'admin_note' => (string)($data['admin_note'] ?? ''),
            'internal_note' => (string)($data['internal_note'] ?? ''),
            'selected_cancellation_item_ids' => $selectedCancellationItemIds,
        ];

        return bv_order_refund_create_from_cancellation($payload);
    }
}

if (!function_exists('bv_order_refund_create_from_cancellation')) {
    function bv_order_refund_create_from_cancellation(array $data): array
    {
        bv_order_refund_require_tables();

        $cancellationId = isset($data['cancellation_id']) ? (int)$data['cancellation_id'] : 0;
        if ($cancellationId <= 0) {
            throw new InvalidArgumentException('cancellation_id is required.');
        }

        $allowDuplicate = !empty($data['allow_duplicate']);
        $actorUserId = isset($data['actor_user_id']) ? (int)$data['actor_user_id'] : bv_order_refund_current_user_id();
        $actorRole = isset($data['actor_role']) && is_string($data['actor_role']) ? strtolower(trim($data['actor_role'])) : bv_order_refund_current_user_role();
        $now = bv_order_refund_now();

        bv_order_refund_begin_transaction();
        try {
            $cancellation = bv_order_refund_query_one(
                'SELECT * FROM order_cancellations WHERE id = :id LIMIT 1 FOR UPDATE',
                ['id' => $cancellationId]
            );
            if (!$cancellation) {
                throw new RuntimeException('Cancellation not found for refund creation.');
            }

            $existing = bv_order_refund_get_by_cancellation_id($cancellationId);
            if ($existing && !$allowDuplicate) {
                $existingStatus = strtolower(trim((string)($existing['status'] ?? '')));
                if (in_array($existingStatus, ['rejected', 'cancelled', 'failed'], true)) {
                    // Allow retry after terminal non-successful refund states.
                } elseif (in_array($existingStatus, ['draft', 'pending_approval', 'approved', 'processing'], true)) {
                    throw new RuntimeException('An active refund request already exists for this cancellation.');
                } elseif (in_array($existingStatus, ['refunded', 'partially_refunded'], true)) {
                    throw new RuntimeException('A refund already exists for this cancellation.');
                } else {
                    throw new RuntimeException('A refund already exists for this cancellation.');
                }
            }

            $cancelItems = bv_order_refund_query_all(
                'SELECT * FROM order_cancellation_items WHERE cancellation_id = :cid ORDER BY id ASC FOR UPDATE',
                ['cid' => $cancellationId]
            );
            $selectedCancellationItemIds = [];
            $selectedCandidates = $data['selected_cancellation_item_ids'] ?? ($data['cancellation_item_ids'] ?? []);
            if (is_array($selectedCandidates)) {
                foreach ($selectedCandidates as $selectedItemId) {
                    if (is_numeric($selectedItemId) && (int) $selectedItemId > 0) {
                        $selectedCancellationItemIds[(int) $selectedItemId] = true;
                    }
                }
            }
            $selectedCancellationItemIds = array_keys($selectedCancellationItemIds);
            if (!empty($selectedCancellationItemIds)) {
                $cancelItems = array_values(array_filter($cancelItems, static function (array $cancelItem) use ($selectedCancellationItemIds): bool {
                    return in_array((int)($cancelItem['id'] ?? 0), $selectedCancellationItemIds, true);
                }));
                if (count($cancelItems) !== count($selectedCancellationItemIds)) {
                    throw new RuntimeException('Invalid selected cancellation items.');
                }
            }
            if (empty($cancelItems)) {
                throw new RuntimeException('No cancellation items available for refund request.');
            }

            $refundCode = bv_order_refund_generate_code();
            $itemsTotalMax = 0.0;
            foreach ($cancelItems as $ci) {
                $itemsTotalMax += bv_order_refund_validate_amount($ci['item_refundable_amount'] ?? ($ci['refund_line_amount'] ?? 0));
            }
            $requested = $itemsTotalMax;
            if (empty($selectedCancellationItemIds) && isset($data['requested_refund_amount']) && is_numeric($data['requested_refund_amount'])) {
                $requested = bv_order_refund_validate_amount($data['requested_refund_amount']);
            }
            $safeMax = !empty($selectedCancellationItemIds)
                ? $itemsTotalMax
                : bv_order_refund_validate_amount($cancellation['refundable_amount'] ?? $itemsTotalMax);
            if ($requested > $safeMax) {
                throw new RuntimeException('Requested refund amount exceeds cancellation refundable amount.');
            }
            $scopeTotal = max($safeMax, $itemsTotalMax);
            if ($scopeTotal <= 0 || $requested <= 0) {
                throw new RuntimeException('No refundable amount available for selected cancellation items.');
            }
            $refundMode = ($scopeTotal > 0 && $requested >= $scopeTotal) ? 'full' : 'partial';


            $orderId = (int)($cancellation['order_id'] ?? 0);
            $order = $orderId > 0 ? (bv_order_refund_get_order_by_id($orderId) ?? []) : [];
            $orderFeeSnapshot = $order !== [] ? bv_order_refund_build_fee_snapshot_from_row($order) : [];

            $insertColumns = [
                'order_id', 'order_cancellation_id', 'refund_code',
                'refund_source', 'refund_reason_code', 'refund_reason_text',
                'status', 'refund_mode', 'currency',
                'subtotal_snapshot', 'discount_snapshot', 'shipping_snapshot', 'tax_snapshot', 'order_total_snapshot',
                'already_refunded_amount_snapshot', 'requested_refund_amount', 'approved_refund_amount', 'actual_refunded_amount',
                'payment_provider', 'payment_reference_snapshot', 'payment_status_snapshot',
                'order_status_snapshot', 'order_source_snapshot', 'restock_state_snapshot',
                'requested_by_user_id', 'requested_by_role', 'requested_at',
                'admin_note', 'internal_note', 'created_at', 'updated_at',
            ];

            $insertParams = [
                'order_id' => $orderId,
                'order_cancellation_id' => $cancellationId,
                'refund_code' => $refundCode,
                'refund_source' => (static function (string $source): string {
                    $source = strtolower(trim($source));
                    if ($source === 'buyer' || $source === 'buyer_request') {
                        return 'buyer_request';
                    }
                    if ($source === 'seller') {
                        return 'seller';
                    }
                    if ($source === 'admin') {
                        return 'admin';
                    }
                    if ($source === 'system') {
                        return 'system';
                    }
                    return 'system';
                })((string)($data['refund_source'] ?? ($cancellation['cancel_source'] ?? 'system'))),
                'refund_reason_code' => (string)($data['refund_reason_code'] ?? ($cancellation['cancel_reason_code'] ?? '')),
                'refund_reason_text' => (string)($data['refund_reason_text'] ?? ($cancellation['cancel_reason_text'] ?? '')),
                'status' => 'pending_approval',
                'refund_mode' => $refundMode,
                'currency' => (string)($cancellation['currency'] ?? ($order['currency'] ?? 'USD')),
                'subtotal_snapshot' => bv_order_refund_validate_amount($cancellation['subtotal_before_discount_snapshot'] ?? ($order['subtotal'] ?? 0)),
                'discount_snapshot' => bv_order_refund_validate_amount($cancellation['discount_amount_snapshot'] ?? ($order['discount_total'] ?? ($order['discount_amount'] ?? 0))),
                'shipping_snapshot' => bv_order_refund_validate_amount($cancellation['shipping_amount_snapshot'] ?? ($order['shipping_total'] ?? ($order['shipping_amount'] ?? 0))),
                'tax_snapshot' => bv_order_refund_validate_amount($data['tax_snapshot'] ?? ($order['tax_total'] ?? ($order['tax_amount'] ?? 0))),
                'order_total_snapshot' => bv_order_refund_validate_amount($cancellation['total_snapshot'] ?? ($order['total'] ?? ($order['grand_total'] ?? 0))),
                'already_refunded_amount_snapshot' => bv_order_refund_validate_amount($data['already_refunded_amount_snapshot'] ?? 0),
                'requested_refund_amount' => $requested,
                'approved_refund_amount' => 0,
                'actual_refunded_amount' => 0,
                'payment_provider' => (string)($data['payment_provider'] ?? ($order['payment_provider'] ?? '')),
                'payment_reference_snapshot' => (string)($data['payment_reference_snapshot'] ?? ($cancellation['refund_reference'] ?? ($order['payment_reference'] ?? ''))),
                'payment_status_snapshot' => (string)($cancellation['payment_state_snapshot'] ?? ($order['payment_status'] ?? ($order['payment_state'] ?? ''))),
                'order_status_snapshot' => (string)($cancellation['order_status_snapshot'] ?? ($order['status'] ?? '')),
                'order_source_snapshot' => (string)($cancellation['order_source_snapshot'] ?? ($order['order_source'] ?? '')),
                'restock_state_snapshot' => (string)($data['restock_state_snapshot'] ?? ''),
                'requested_by_user_id' => $actorUserId > 0 ? $actorUserId : (int)($cancellation['requested_by_user_id'] ?? 0),
                'requested_by_role' => $actorRole !== '' ? $actorRole : (string)($cancellation['requested_by_role'] ?? 'system'),
                'requested_at' => (string)($data['requested_at'] ?? $now),
                'admin_note' => (string)($data['admin_note'] ?? ($cancellation['admin_note'] ?? '')),
                'internal_note' => (string)($data['internal_note'] ?? ''),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $copiedFeeSnapshot = [];
            foreach ($orderFeeSnapshot as $field => $value) {
                if (bv_order_refund_column_exists('order_refunds', $field)) {
                    $insertColumns[] = $field;
                    $insertParams[$field] = $value;
                    $copiedFeeSnapshot[$field] = $value;
                }
            }

            $placeholders = [];
            foreach ($insertColumns as $column) {
                $placeholders[] = ':' . $column;
            }

            $insertSql = 'INSERT INTO order_refunds (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $placeholders) . ')';
            $insert = bv_order_refund_execute($insertSql, $insertParams);

            $refundId = (int)($insert['insert_id'] ?? 0);
            if ($refundId <= 0) {
                throw new RuntimeException('Failed to create refund header.');
            }

            $createdItems = [];
            $remainingToDistribute = $requested;
            foreach ($cancelItems as $ci) {
                $max = bv_order_refund_validate_amount($ci['item_refundable_amount'] ?? ($ci['refund_line_amount'] ?? 0));
                $reqLine = 0.0;
                if ($remainingToDistribute > 0 && $max > 0) {
                    $reqLine = $remainingToDistribute >= $max ? $max : $remainingToDistribute;
                    $remainingToDistribute = round($remainingToDistribute - $reqLine, 2);
                    if ($remainingToDistribute < 0) {
                        $remainingToDistribute = 0.0;
                    }
                }

                $itemInsert = bv_order_refund_execute(
                    'INSERT INTO order_refund_items (
                        refund_id, order_cancellation_item_id, order_item_id, listing_id,
                        qty_snapshot, unit_price_snapshot, line_total_snapshot,
                        max_refundable_amount, requested_refund_amount, approved_refund_amount, actual_refunded_amount,
                        refund_type, note, created_at, updated_at
                    ) VALUES (
                        :refund_id, :order_cancellation_item_id, :order_item_id, :listing_id,
                        :qty_snapshot, :unit_price_snapshot, :line_total_snapshot,
                        :max_refundable_amount, :requested_refund_amount, :approved_refund_amount, :actual_refunded_amount,
                        :refund_type, :note, :created_at, :updated_at
                    )',
                    [
                        'refund_id' => $refundId,
                        'order_cancellation_item_id' => (int)$ci['id'],
                        'order_item_id' => (int)($ci['order_item_id'] ?? 0),
                        'listing_id' => (int)($ci['listing_id'] ?? 0),
                        'qty_snapshot' => (int)($ci['refund_qty'] ?? $ci['qty'] ?? 0),
                        'unit_price_snapshot' => bv_order_refund_validate_amount($ci['unit_price_snapshot'] ?? 0),
                        'line_total_snapshot' => bv_order_refund_validate_amount($ci['line_total_snapshot'] ?? 0),
                        'max_refundable_amount' => $max,
                        'requested_refund_amount' => $reqLine,
                        'approved_refund_amount' => 0,
                        'actual_refunded_amount' => 0,
                        'refund_type' => 'item',
                        'note' => '',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
                $createdItems[] = bv_order_refund_query_one('SELECT * FROM order_refund_items WHERE id = :id LIMIT 1', ['id' => (int)($itemInsert['insert_id'] ?? 0)]);
            }

            $requestedSum = 0.0;
            foreach ($createdItems as $createdItem) {
                if (!is_array($createdItem)) {
                    continue;
                }
                $requestedSum += bv_order_refund_validate_amount($createdItem['requested_refund_amount'] ?? 0);
            }
            $requestedSum = round($requestedSum, 2);
            if (abs($requestedSum - $requested) > 0.01) {
                throw new RuntimeException('Refund item requested amount sum does not match refund header requested amount.');
            }

            bv_order_refund_execute(
                'UPDATE order_cancellations
                 SET refund_id = :rid, refund_status = :refund_status, updated_at = :updated_at
                 WHERE id = :cid',
                [
                    'rid' => $refundId,
                    'refund_status' => 'pending',
                    'updated_at' => $now,
                    'cid' => $cancellationId,
                ]
            );

            bv_order_refund_insert_ledger([
                'order_id' => (int)$cancellation['order_id'],
                'refund_id' => $refundId,
                'event_type' => 'refund_created',
                'amount' => $requested,
                'currency' => (string)($cancellation['currency'] ?? 'USD'),
                'actor_user_id' => $actorUserId,
                'actor_role' => $actorRole,
                'note' => 'Refund created from cancellation snapshot',
                'created_at' => $now,
          ]);

            bv_order_refund_commit();
            bv_order_refund_after_request_created((int)$refundId);

            try {
                bv_refund_queue_notifications((int)$refundId, 'request');
            } catch (Throwable $notifyError) {
                if (function_exists('bv_order_refund_debug_log')) {
                    bv_order_refund_debug_log('refund_notification_queue_failed', [
                        'refund_id' => (int)$refundId,
                        'event' => 'request',
                        'error' => $notifyError->getMessage(),
                    ]);
                }
            }

            bv_order_refund_debug_log('create_from_cancellation_inserted', [
                'refund_id' => $refundId,
                'order_id' => $orderId,
                'requested_refund_amount' => $requested,
                'copied_fee_snapshot' => $copiedFeeSnapshot,
            ]);

            $feeSummary = bv_order_refund_fee_summary_safe($refundId);
            $refundRow = bv_order_refund_get_by_id($refundId);
            return [
                'refund_id' => $refundId,
                'id' => $refundId,
                'request_id' => $refundId,
                'refund' => $refundRow,
                'items' => bv_order_refund_get_items($refundId),
                'fee_summary' => $feeSummary,
            ];
        } catch (Throwable $e) {
            bv_order_refund_rollback();
            throw $e;
        }
    }
}

if (!function_exists('bv_order_refund_approve')) {
    function bv_order_refund_approve(int $refundId, float $approvedAmount, ?int $actorUserId = null, ?string $actorRole = null, string $note = ''): array
    {
        $approvedAmount = bv_order_refund_validate_amount($approvedAmount);
        $actorUserId = $actorUserId ?? bv_order_refund_current_user_id();
        $actorRole = strtolower(trim((string)($actorRole ?? bv_order_refund_current_user_role())));
        $now = bv_order_refund_now();

        bv_order_refund_begin_transaction();
        try {
            $refund = bv_order_refund_query_one('SELECT * FROM order_refunds WHERE id = :id LIMIT 1 FOR UPDATE', ['id' => $refundId]);
            if (!$refund) {
                throw new RuntimeException('Refund not found.');
            }

            $currentStatus = strtolower(trim((string)($refund['status'] ?? '')));

            // Idempotency: if already approved with the same amount, return safely
            // without duplicate updates, fee rebuilds, or ledger entries.
            if ($currentStatus === 'approved') {
                $existingApproved = bv_order_refund_round_money($refund['approved_refund_amount'] ?? 0);
                if (abs($existingApproved - $approvedAmount) < 0.01) {
                    bv_order_refund_commit();
                    return bv_order_refund_get_by_id($refundId) ?? [];
                }
                // Different amount — fall through to throw the transition error.
            }

            if (!bv_order_refund_can_transition($currentStatus, 'approved')) {
                throw new RuntimeException('Invalid refund status transition to approved (current: ' . $currentStatus . ').');
            }
            $max = bv_order_refund_validate_amount($refund['requested_refund_amount'] ?? 0);
            if ($approvedAmount > $max) {
                throw new RuntimeException('Approved amount cannot exceed requested amount.');
            }
            $mode = ($max > 0 && $approvedAmount >= $max) ? 'full' : 'partial';

            bv_order_refund_execute(
                'UPDATE order_refunds
                 SET status = :status,
                     refund_mode = :refund_mode,
                     approved_refund_amount = :approved_refund_amount,
                     approved_by_user_id = :approved_by_user_id,
                     approved_at = :approved_at,
                     admin_note = :admin_note,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    'status' => 'approved',
                    'refund_mode' => $mode,
                    'approved_refund_amount' => $approvedAmount,
                    'approved_by_user_id' => $actorUserId,
                    'approved_at' => $now,
                    'admin_note' => $note,
                    'updated_at' => $now,
                    'id' => $refundId,
                ]
            );

            $items = bv_order_refund_query_all(
                'SELECT * FROM order_refund_items WHERE refund_id = :refund_id ORDER BY id ASC FOR UPDATE',
                ['refund_id' => $refundId]
            );
            $remaining = $approvedAmount;
            foreach ($items as $item) {
                $requestedLine = bv_order_refund_validate_amount($item['requested_refund_amount'] ?? 0);
                $lineApproved = 0.0;
                if ($remaining > 0 && $requestedLine > 0) {
                    $lineApproved = $remaining >= $requestedLine ? $requestedLine : $remaining;
                }
                $lineApproved = round($lineApproved, 2);
                $remaining = round($remaining - $lineApproved, 2);
                if ($remaining < 0) {
                    $remaining = 0.0;
                }

                bv_order_refund_execute(
                    'UPDATE order_refund_items SET approved_refund_amount = :approved_refund_amount, updated_at = :updated_at WHERE id = :id',
                    [
                        'approved_refund_amount' => $lineApproved,
                        'updated_at' => $now,
                        'id' => (int)$item['id'],
                    ]
                );
            }

            $zeroRow = bv_order_refund_query_one(
                'SELECT COUNT(*) AS c FROM order_refund_items WHERE refund_id = :refund_id AND approved_refund_amount <= 0',
                ['refund_id' => $refundId]
            );
            $zeroRowCount = (int)($zeroRow['c'] ?? 0);

            $fallbackSet = ['approved_refund_amount = requested_refund_amount'];
            if (bv_order_refund_column_exists('order_refund_items', 'qty_approved')) {
                $fallbackSet[] = 'qty_approved = qty_snapshot';
            }
            bv_order_refund_execute(
                'UPDATE order_refund_items SET ' . implode(', ', $fallbackSet) . ' WHERE refund_id = :refund_id AND approved_refund_amount <= 0',
                ['refund_id' => $refundId]
            );

            $sumRow = bv_order_refund_query_one(
                'SELECT COALESCE(SUM(approved_refund_amount), 0) AS sum_approved FROM order_refund_items WHERE refund_id = :refund_id',
                ['refund_id' => $refundId]
            );

            $sumApproved = bv_order_refund_validate_amount($sumRow['sum_approved'] ?? 0);
            if (abs($sumApproved - $approvedAmount) > 0.01) {
                throw new RuntimeException('Approved refund allocation mismatch between header and items.');
            }

            bv_order_refund_debug_log('approve_allocation', [
                'refund_id' => $refundId,
                'approvedAmount' => $approvedAmount,
                'sumApproved' => $sumApproved,
                'zero_row_fallback_count' => $zeroRowCount,
            ]);

            bv_order_refund_fee_summary_safe($refundId);
            $rebuildResult = bv_order_refund_call_fee_rebuild_for_refund($refundId);
            if ($rebuildResult !== []) {
                $refund = bv_order_refund_query_one('SELECT * FROM order_refunds WHERE id = :id LIMIT 1 FOR UPDATE', ['id' => $refundId]) ?? $refund;
            }
            bv_order_refund_insert_ledger([
                'order_id' => (int)$refund['order_id'],
                'refund_id' => $refundId,
                'event_type' => 'refund_approved',
                'amount' => $approvedAmount,
                'currency' => (string)($refund['currency'] ?? 'USD'),
                'actor_user_id' => (int)$actorUserId,
                'actor_role' => $actorRole,
                'note' => $note,
                'created_at' => $now,
            ]);

             bv_order_refund_sync_cancellation_bridge($refundId);
            bv_order_refund_commit();

            return bv_order_refund_get_by_id($refundId) ?? [];
        } catch (Throwable $e) {
            bv_order_refund_rollback();
            throw $e;
        }
    }
}


if (!function_exists('bv_order_refund_mark_processing')) {
    function bv_order_refund_mark_processing(int $refundId, ?int $actorUserId = null, string $note = '', ?string $actorRole = null): array
    {
        $actorUserId = $actorUserId ?? bv_order_refund_current_user_id();
        $actorRole = strtolower(trim((string)($actorRole ?? bv_order_refund_current_user_role())));
        if ($actorRole === '') {
            $actorRole = 'system';
        }
        $now = bv_order_refund_now();

        bv_order_refund_begin_transaction();
        try {
            // Lock the refund row before any validation — prevents concurrent
            // double-processing attempts from both passing the transition check.
            $refund = bv_order_refund_query_one('SELECT * FROM order_refunds WHERE id = :id LIMIT 1 FOR UPDATE', ['id' => $refundId]);
            if (!$refund) {
                throw new RuntimeException('Refund not found.');
            }

            $currentStatus = strtolower(trim((string)($refund['status'] ?? '')));

            // Idempotency: if already processing, return safely without duplicate
            // DB writes. The caller may retry legitimately.
            if ($currentStatus === 'processing') {
                bv_order_refund_commit();
                return bv_order_refund_get_by_id($refundId) ?? [];
            }

            if (!bv_order_refund_can_transition($currentStatus, 'processing')) {
                throw new RuntimeException('Invalid refund status transition to processing (current: ' . $currentStatus . ').');
            }

            // Re-read under lock to get latest amounts after any concurrent approve.
            $refund = bv_order_refund_query_one('SELECT * FROM order_refunds WHERE id = :id LIMIT 1 FOR UPDATE', ['id' => $refundId]) ?? $refund;
            $authorityAmount = bv_order_refund_resolve_executable_amount($refundId, $refund);
            if ($authorityAmount <= 0) {
                throw new RuntimeException('Executable refund amount must be greater than zero before processing.');
            }

            $updateSet = [
                'status = :status',
                'processed_by_user_id = :processed_by_user_id',
                'processing_started_at = :processing_started_at',
                'internal_note = :internal_note',
                'updated_at = :updated_at',
            ];
            $updateParams = [
                'status' => 'processing',
                'processed_by_user_id' => $actorUserId,
                'processing_started_at' => $now,
                'internal_note' => $note,
                'updated_at' => $now,
                'id' => $refundId,
            ];
            if (bv_order_refund_column_exists('order_refunds', 'actual_refund_amount')) {
                $updateSet[] = 'actual_refund_amount = :actual_refund_amount';
                $updateParams['actual_refund_amount'] = $authorityAmount;
            }

            bv_order_refund_execute(
                'UPDATE order_refunds SET ' . implode(', ', $updateSet) . ' WHERE id = :id',
                $updateParams
            );

            // Dedup guard for processing ledger: only insert if a refund_processing
            // ledger row does not already exist for this refund. Uses the entry_type
            // resolved by insert_ledger (refund_adjustment for refund_processing events).
            // We check by refund_id + event_type to avoid duplicates on retry.
            $processingLedgerExists = false;
            try {
                if (bv_order_refund_table_exists('order_financial_ledger') &&
                    bv_order_refund_column_exists('order_financial_ledger', 'entry_type')
                ) {
                    $existingProcessingLedger = bv_order_refund_query_one(
                        'SELECT id FROM order_financial_ledger
                         WHERE refund_id = :refund_id
                           AND entry_type = :entry_type
                         ORDER BY id DESC LIMIT 1',
                        ['refund_id' => $refundId, 'entry_type' => 'refund_adjustment']
                    );
                    // A refund_adjustment row for processing already exists — skip insert.
                    // We look specifically for event_type if the column exists.
                    if (bv_order_refund_column_exists('order_financial_ledger', 'memo')) {
                        // Use a more targeted check via the memo/note field when available.
                        $existingProcessingLedger = bv_order_refund_query_one(
                            'SELECT id FROM order_financial_ledger
                             WHERE refund_id = :refund_id
                               AND entry_type = :entry_type
                               AND (memo LIKE :memo OR memo = :exact_memo)
                             ORDER BY id DESC LIMIT 1',
                            [
                                'refund_id'  => $refundId,
                                'entry_type' => 'refund_adjustment',
                                'memo'       => '%processing%',
                                'exact_memo' => $note !== '' ? $note : 'processing',
                            ]
                        );
                    }
                    $processingLedgerExists = ($existingProcessingLedger !== null);
                }
            } catch (Throwable $ledgerCheckError) {
                // Dedup check failed — proceed with insert; insert_ledger will handle
                // provider_reference-based dedup for refund_out rows.
                $processingLedgerExists = false;
            }

            if (!$processingLedgerExists) {
                bv_order_refund_insert_ledger([
                    'order_id'      => (int)$refund['order_id'],
                    'refund_id'     => $refundId,
                    'event_type'    => 'refund_processing',
                    'amount'        => $authorityAmount,
                    'currency'      => (string)($refund['currency'] ?? 'USD'),
                    'actor_user_id' => (int)$actorUserId,
                    'actor_role'    => $actorRole,
                    'note'          => $note,
                    'created_at'    => $now,
                ]);
            }

            bv_order_refund_sync_cancellation_bridge($refundId);
            bv_order_refund_commit();
            return bv_order_refund_get_by_id($refundId) ?? [];
        } catch (Throwable $e) {
            bv_order_refund_rollback();
            throw $e;
        }
    }
}


if (!function_exists('bv_order_refund_mark_refunded')) {
    function bv_order_refund_mark_refunded(int $refundId, float $actualAmount, array $transactionData = [], ?int $actorUserId = null, string $note = '', ?string $actorRole = null): array
    {
         $actualAmount = bv_order_refund_validate_amount($actualAmount);
        $actorUserId = $actorUserId ?? bv_order_refund_current_user_id();
        $actorRole = strtolower(trim((string)($actorRole ?? bv_order_refund_current_user_role())));
        if ($actorRole === '') {
            $actorRole = 'system';
        }
        $now = bv_order_refund_now();

        bv_order_refund_begin_transaction();
        try {
           $refund = bv_order_refund_query_one('SELECT * FROM order_refunds WHERE id = :id LIMIT 1 FOR UPDATE', ['id' => $refundId]);
            if (!$refund) {
                throw new RuntimeException('Refund not found.');
            }

             $refund = bv_order_refund_query_one('SELECT * FROM order_refunds WHERE id = :id LIMIT 1 FOR UPDATE', ['id' => $refundId]) ?? $refund;

            // Idempotency guard: if the header is already in a terminal refunded state,
            // return safely without duplicate processing or duplicate email.
            $currentHeaderStatus = strtolower(trim((string)($refund['status'] ?? '')));
            if (in_array($currentHeaderStatus, ['refunded', 'partially_refunded'], true)) {
                bv_order_refund_commit();
                // Only trigger completed notification if header is fully refunded AND
                // we have not already flagged it as email-sent in this process.
                // bv_order_refund_send_completed_notifications_once() has its own
                // static guard; the DB-backed guard inside it prevents cross-process replay.
                 if (in_array($currentHeaderStatus, ['refunded', 'partially_refunded'], true)) {
                    bv_order_refund_send_completed_notifications_once((int)$refundId);
                }
                return bv_order_refund_get_by_id($refundId) ?? [];
            }

            // Provider reference dedup: check whether this exact provider refund
            // reference has already been recorded as a succeeded transaction for this
            // refund. If so, the incoming call is a replay — return safely without
            // re-adding to the cumulative total, inserting duplicate transactions or
            // ledger rows, or re-sending the completed email.
            $incomingProviderRefundId = trim((string)($transactionData['provider_refund_id'] ?? ($transactionData['provider_reference'] ?? '')));
            if ($incomingProviderRefundId !== '' && bv_order_refund_table_exists('order_refund_transactions')) {
                $alreadyFinalized = bv_order_refund_query_one(
                    'SELECT id FROM order_refund_transactions
                     WHERE refund_id = :refund_id
                       AND transaction_status = :status
                       AND provider_refund_id = :provider_refund_id
                     ORDER BY id DESC LIMIT 1',
                    [
                        'refund_id'          => $refundId,
                        'status'             => 'succeeded',
                        'provider_refund_id' => $incomingProviderRefundId,
                    ]
                );
                if ($alreadyFinalized) {
                    bv_order_refund_debug_log('mark_refunded_provider_ref_replay_skipped', [
                        'refund_id'          => $refundId,
                        'provider_refund_id' => $incomingProviderRefundId,
                    ]);
                    bv_order_refund_commit();
                    // Re-check status after commit read — if the earlier finalization
                    // completed, send the notification via the exact-once helper.
                    $freshForNotify = bv_order_refund_get_by_id($refundId);
                    $freshStatus = strtolower(trim((string)($freshForNotify['status'] ?? '')));
                     if (in_array($freshStatus, ['refunded', 'partially_refunded'], true)) {
                        bv_order_refund_send_completed_notifications_once((int)$refundId);
                    }
                    return $freshForNotify ?? [];
                }
            }

            $approved = bv_order_refund_validate_amount($refund['approved_refund_amount'] ?? 0); 
            if ($approved <= 0) {
                throw new RuntimeException('Refund must be approved with positive amount before marking refunded.');
            }

            $explicitActual = bv_order_refund_round_money($actualAmount);
            $finalizedActual = $explicitActual;
            if ($finalizedActual <= 0) {
                $finalizedActual = bv_order_refund_round_money($refund['actual_refund_amount'] ?? 0);
            }
            if ($finalizedActual <= 0) {
                $rebuilt = bv_order_refund_call_fee_rebuild_for_refund($refundId);
                if ($rebuilt !== []) {
                    $refund = bv_order_refund_query_one('SELECT * FROM order_refunds WHERE id = :id LIMIT 1 FOR UPDATE', ['id' => $refundId]) ?? $refund;
                    $finalizedActual = bv_order_refund_round_money($refund['actual_refund_amount'] ?? 0);
                }
            }
            if ($finalizedActual <= 0) {
                $finalizedActual = $approved;
            }
            if ($finalizedActual <= 0) {
                throw new RuntimeException('Finalized refund amount must be greater than zero.');
            }

            $currentActual = bv_order_refund_read_actual_amount($refund);
            $cumulativeActual = round($currentActual + $finalizedActual, 2);
            if ($cumulativeActual > $approved) {
                throw new RuntimeException('Cumulative refunded amount exceeds approved amount.');
            }

            if (!bv_order_refund_can_transition((string)$refund['status'], 'refunded')
                && !bv_order_refund_can_transition((string)$refund['status'], 'partially_refunded')) {
                throw new RuntimeException('Invalid refund status transition for refund completion.');
            }

            $toStatus = ($cumulativeActual < $approved) ? 'partially_refunded' : 'refunded';

            $refundSet = [
                'status = :status',
                'processed_by_user_id = :processed_by_user_id',
                'refunded_at = :refunded_at',
                'internal_note = :internal_note',
                'updated_at = :updated_at',
            ];
            $refundParams = [
                'status' => $toStatus,
                'processed_by_user_id' => $actorUserId,
                'refunded_at' => $now,
                'internal_note' => $note,
                'updated_at' => $now,
                'id' => $refundId,
            ];
            if (bv_order_refund_column_exists('order_refunds', 'actual_refunded_amount')) {
                $refundSet[] = 'actual_refunded_amount = :actual_refunded_amount';
                $refundParams['actual_refunded_amount'] = $cumulativeActual;
            }
             if (bv_order_refund_column_exists('order_refunds', 'actual_refund_amount')) {
                $refundSet[] = 'actual_refund_amount = :actual_refund_amount';
                $refundParams['actual_refund_amount'] = $cumulativeActual;
            }
            bv_order_refund_execute(
                'UPDATE order_refunds SET ' . implode(', ', $refundSet) . ' WHERE id = :id',
                $refundParams
            );

            $distributedItems = bv_order_refund_sync_item_actual_refunded_amounts($refundId, $cumulativeActual, $now);
            $sumActual = 0.0;
            foreach ($distributedItems as $line) {
                $sumActual = bv_order_refund_round_money($sumActual + bv_order_refund_round_money($line['amount'] ?? 0));
            }
            if (abs($sumActual - $cumulativeActual) > 0.01) {
                throw new RuntimeException('Actual refunded allocation mismatch between header and items.');
            }

            if ($transactionData !== []) {
                $transactionData['refund_id'] = $refundId;
                $transactionData['amount'] = $transactionData['amount'] ?? $finalizedActual;
                $transactionData['currency'] = $transactionData['currency'] ?? (string)($refund['currency'] ?? 'USD');
                $transactionData['status'] = $transactionData['status'] ?? 'succeeded';
                bv_order_refund_insert_transaction($transactionData);
            }

            $eventType = $toStatus === 'partially_refunded' ? 'refund_partially_refunded' : 'refund_refunded';
            bv_order_refund_insert_ledger([
                'order_id' => (int)$refund['order_id'],
                'refund_id' => $refundId,
                'event_type' => $eventType,
                'amount' => $finalizedActual,
                'currency' => (string)($refund['currency'] ?? 'USD'),
                'actor_user_id' => (int)$actorUserId,
                'actor_role' => $actorRole,
                'note' => $note,
                'created_at' => $now,
                // Pass provider_reference so the insert_ledger dedup guard can match
                // on exact provider reference for refund_out rows.
                'provider_reference' => $incomingProviderRefundId,
            ]);

            bv_order_refund_sync_cancellation_bridge($refundId);
            bv_order_refund_commit();

            // Send completed notification exactly once.
            // For fully refunded: always notify via the exact-once helper.
            // For partially_refunded: also notify — the customer has received a real
            // money event and the exact-once helper prevents cross-process duplicates.
            bv_order_refund_send_completed_notifications_once((int)$refundId);

            return bv_order_refund_get_by_id($refundId) ?? [];
        } catch (Throwable $e) {
            bv_order_refund_rollback();
            throw $e;
        }
    }
}


if (!function_exists('bv_order_refund_mark_failed')) {
    function bv_order_refund_mark_failed(int $refundId, string $reason = '', array $transactionData = [], ?int $actorUserId = null, ?string $actorRole = null): array
    {
        $actorUserId = $actorUserId ?? bv_order_refund_current_user_id();
        $actorRole = strtolower(trim((string)($actorRole ?? bv_order_refund_current_user_role())));
        if ($actorRole === '') {
            $actorRole = 'system';
        }
        $now = bv_order_refund_now();

        bv_order_refund_begin_transaction();
        try {
            $refund = bv_order_refund_query_one('SELECT * FROM order_refunds WHERE id = :id LIMIT 1 FOR UPDATE', ['id' => $refundId]);
            if (!$refund) {
                throw new RuntimeException('Refund not found.');
            }
            if (!bv_order_refund_can_transition((string)$refund['status'], 'failed')) {
                throw new RuntimeException('Invalid refund status transition to failed.');
            }

            bv_order_refund_execute(
                'UPDATE order_refunds
                 SET status = :status,
                     failed_by_user_id = :failed_by_user_id,
                     failed_at = :failed_at,
                     internal_note = :internal_note,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    'status' => 'failed',
                    'failed_by_user_id' => $actorUserId,
                    'failed_at' => $now,
                    'internal_note' => $reason,
                    'updated_at' => $now,
                    'id' => $refundId,
                ]
            );

            if ($transactionData !== []) {
                $transactionData['refund_id'] = $refundId;
                $transactionData['status'] = $transactionData['status'] ?? 'failed';
                $transactionData['error_message'] = $transactionData['error_message'] ?? $reason;
                $transactionData['currency'] = $transactionData['currency'] ?? (string)($refund['currency'] ?? 'USD');
                bv_order_refund_insert_transaction($transactionData);
            }

            // Determine the correct amount for the failed ledger entry.
            // Priority:
            //   1. actual_refund_amount if > 0 (the amount that was about to be sent)
            //   2. executable amount resolved from the refund row
            //   3. approved_refund_amount as final fallback
            $failedLedgerAmount = bv_order_refund_round_money($refund['actual_refund_amount'] ?? 0);
            if ($failedLedgerAmount <= 0) {
                $failedLedgerAmount = bv_order_refund_round_money($refund['actual_refunded_amount'] ?? 0);
            }
            if ($failedLedgerAmount <= 0) {
                try {
                    $failedLedgerAmount = bv_order_refund_resolve_executable_amount($refundId, $refund);
                } catch (Throwable $amountError) {
                    $failedLedgerAmount = 0.0;
                }
            }
            if ($failedLedgerAmount <= 0) {
                $failedLedgerAmount = bv_order_refund_validate_amount($refund['approved_refund_amount'] ?? 0);
            }

            // Dedup guard for failed ledger: prevent duplicate refund_failed rows on
            // repeated fail/retry paths (e.g. webhook sending failed event multiple times).
            $failedLedgerExists = false;
            try {
                if (bv_order_refund_table_exists('order_financial_ledger')) {
                    $existingFailedLedger = bv_order_refund_query_one(
                        'SELECT id FROM order_financial_ledger
                         WHERE refund_id = :refund_id
                           AND entry_type = :entry_type
                         ORDER BY id DESC LIMIT 1',
                        ['refund_id' => $refundId, 'entry_type' => 'refund_adjustment']
                    );
                    // Narrow check: if memo/note column exists, match on failure-related memo.
                    if ($existingFailedLedger !== null && bv_order_refund_column_exists('order_financial_ledger', 'memo')) {
                        $existingFailedLedger = bv_order_refund_query_one(
                            'SELECT id FROM order_financial_ledger
                             WHERE refund_id = :refund_id
                               AND entry_type = :entry_type
                               AND (memo LIKE :memo1 OR memo LIKE :memo2)
                             ORDER BY id DESC LIMIT 1',
                            [
                                'refund_id'  => $refundId,
                                'entry_type' => 'refund_adjustment',
                                'memo1'      => '%fail%',
                                'memo2'      => '%error%',
                            ]
                        );
                    }
                    $failedLedgerExists = ($existingFailedLedger !== null);
                }
            } catch (Throwable $ledgerCheckError) {
                $failedLedgerExists = false;
            }

            if (!$failedLedgerExists) {
                bv_order_refund_insert_ledger([
                    'order_id'      => (int)$refund['order_id'],
                    'refund_id'     => $refundId,
                    'event_type'    => 'refund_failed',
                    'amount'        => $failedLedgerAmount,
                    'currency'      => (string)($refund['currency'] ?? 'USD'),
                    'actor_user_id' => (int)$actorUserId,
                    'actor_role'    => $actorRole,
                    'note'          => $reason,
                    'created_at'    => $now,
                ]);
            }

            // Compensating ledger entry: if a refund_processing reserve was written
            // earlier (entry_type = refund_adjustment with processing-related memo),
            // write a reversal/compensation entry to keep accounting balanced.
            // This records that the processing reserve is now void so the ledger
            // does not show a pending outflow that never materialized.
            try {
                if (bv_order_refund_table_exists('order_financial_ledger') &&
                    bv_order_refund_column_exists('order_financial_ledger', 'memo')
                ) {
                    $processingReserve = bv_order_refund_query_one(
                        'SELECT id, amount FROM order_financial_ledger
                         WHERE refund_id = :refund_id
                           AND entry_type = :entry_type
                           AND (memo LIKE :memo OR memo = \'\')
                         ORDER BY id DESC LIMIT 1',
                        [
                            'refund_id'  => $refundId,
                            'entry_type' => 'refund_adjustment',
                            'memo'       => '%processing%',
                        ]
                    );
                    if ($processingReserve) {
                        $reserveAmount = bv_order_refund_round_money($processingReserve['amount'] ?? 0);
                        if ($reserveAmount > 0) {
                            bv_order_refund_insert_ledger([
                                'order_id'      => (int)$refund['order_id'],
                                'refund_id'     => $refundId,
                                'event_type'    => 'refund_processing_reversal',
                                'entry_type'    => 'refund_adjustment',
                                'direction'     => 'in',
                                'amount'        => $reserveAmount,
                                'currency'      => (string)($refund['currency'] ?? 'USD'),
                                'actor_user_id' => (int)$actorUserId,
                                'actor_role'    => $actorRole,
                                'note'          => 'Processing reserve reversed due to refund failure: ' . $reason,
                                'created_at'    => $now,
                            ]);
                        }
                    }
                }
            } catch (Throwable $compensatingError) {
                // Compensating entry is best-effort — do not let it abort the failure
                // transition that already succeeded.
                bv_order_refund_debug_log('refund_failed_compensating_ledger_error', [
                    'refund_id' => $refundId,
                    'error'     => $compensatingError->getMessage(),
                ]);
            }

            bv_order_refund_sync_cancellation_bridge($refundId);
            bv_order_refund_commit();
            return bv_order_refund_get_by_id($refundId) ?? [];
        } catch (Throwable $e) {
            bv_order_refund_rollback();
            throw $e;
        }
    }
}


if (!function_exists('bv_order_refund_reject')) {
    function bv_order_refund_reject(int $refundId, string $reason = '', ?int $actorUserId = null, ?string $actorRole = null): array
    {
        $actorUserId = $actorUserId ?? bv_order_refund_current_user_id();
        $actorRole = strtolower(trim((string)($actorRole ?? bv_order_refund_current_user_role())));
        $now = bv_order_refund_now();

        bv_order_refund_begin_transaction();
        try {
            $refund = bv_order_refund_query_one('SELECT * FROM order_refunds WHERE id = :id LIMIT 1 FOR UPDATE', ['id' => $refundId]);
            if (!$refund) {
                throw new RuntimeException('Refund not found.');
            }
            if (!bv_order_refund_can_transition((string)$refund['status'], 'rejected')) {
                throw new RuntimeException('Invalid refund status transition to rejected.');
            }

            bv_order_refund_execute(
                'UPDATE order_refunds
                 SET status = :status,
                     rejected_by_user_id = :rejected_by_user_id,
                     rejected_at = :rejected_at,
                     admin_note = :admin_note,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    'status' => 'rejected',
                    'rejected_by_user_id' => $actorUserId,
                    'rejected_at' => $now,
                    'admin_note' => $reason,
                    'updated_at' => $now,
                    'id' => $refundId,
                ]
            );

            bv_order_refund_insert_ledger([
                'order_id' => (int)$refund['order_id'],
                'refund_id' => $refundId,
                'event_type' => 'refund_rejected',
                'amount' => bv_order_refund_validate_amount($refund['requested_refund_amount'] ?? 0),
                'currency' => (string)($refund['currency'] ?? 'USD'),
                'actor_user_id' => (int)$actorUserId,
                'actor_role' => $actorRole,
                'note' => $reason,
                'created_at' => $now,
            ]);

            bv_order_refund_sync_cancellation_bridge($refundId);
            bv_order_refund_commit();
            return bv_order_refund_get_by_id($refundId) ?? [];
        } catch (Throwable $e) {
            bv_order_refund_rollback();
            throw $e;
        }
    }
}

if (!function_exists('bv_order_refund_cancel')) {
    function bv_order_refund_cancel(int $refundId, string $reason = '', ?int $actorUserId = null, ?string $actorRole = null): array
    {
        $actorUserId = $actorUserId ?? bv_order_refund_current_user_id();
        $actorRole = strtolower(trim((string)($actorRole ?? bv_order_refund_current_user_role())));
        $now = bv_order_refund_now();

        bv_order_refund_begin_transaction();
        try {
            $refund = bv_order_refund_query_one('SELECT * FROM order_refunds WHERE id = :id LIMIT 1 FOR UPDATE', ['id' => $refundId]);
            if (!$refund) {
                throw new RuntimeException('Refund not found.');
            }
            if (!bv_order_refund_can_transition((string)$refund['status'], 'cancelled')) {
                throw new RuntimeException('Invalid refund status transition to cancelled.');
            }

            bv_order_refund_execute(
                'UPDATE order_refunds
                 SET status = :status,
                     admin_note = :admin_note,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    'status' => 'cancelled',
                    'admin_note' => $reason,
                    'updated_at' => $now,
                    'id' => $refundId,
                ]
            );

            bv_order_refund_insert_ledger([
                'order_id' => (int)$refund['order_id'],
                'refund_id' => $refundId,
                'event_type' => 'refund_cancelled',
                'amount' => bv_order_refund_validate_amount($refund['requested_refund_amount'] ?? 0),
                'currency' => (string)($refund['currency'] ?? 'USD'),
                'actor_user_id' => (int)$actorUserId,
                'actor_role' => $actorRole,
                'note' => $reason,
                'created_at' => $now,
            ]);

            bv_order_refund_sync_cancellation_bridge($refundId);
            bv_order_refund_commit();
            return bv_order_refund_get_by_id($refundId) ?? [];
        } catch (Throwable $e) {
            bv_order_refund_rollback();
            throw $e;
        }
    }
}

if (!function_exists('bv_order_refund_insert_transaction')) {
    function bv_order_refund_insert_transaction(array $data): int
    {
        if (!bv_order_refund_table_exists('order_refund_transactions')) {
            return 0;
        }

        $refundId = isset($data['refund_id']) ? (int)$data['refund_id'] : 0;
        if ($refundId <= 0) {
            throw new InvalidArgumentException('refund_id is required for transaction insert.');
        }

        $status = strtolower(trim((string)($data['transaction_status'] ?? ($data['status'] ?? 'pending'))));
        if (!in_array($status, ['pending', 'succeeded', 'failed', 'cancelled'], true)) {
            throw new InvalidArgumentException('Invalid transaction_status. Allowed: pending, succeeded, failed, cancelled.');
        }

        // Dedup guard: if a succeeded transaction row already exists for this
        // refund + provider_refund_id combination, return the existing ID rather
        // than inserting a duplicate. This protects against webhook replay and
        // double-execution paths.
        $providerRefundIdForDedup = trim((string)($data['provider_refund_id'] ?? ($data['provider_reference'] ?? '')));
        if ($status === 'succeeded' && $providerRefundIdForDedup !== '') {
            $existing = bv_order_refund_query_one(
                'SELECT id FROM order_refund_transactions
                 WHERE refund_id = :refund_id
                   AND transaction_status = :status
                   AND provider_refund_id = :provider_refund_id
                 ORDER BY id DESC LIMIT 1',
                [
                    'refund_id'         => $refundId,
                    'status'            => 'succeeded',
                    'provider_refund_id'=> $providerRefundIdForDedup,
                ]
            );
            if ($existing) {
                return (int)($existing['id'] ?? 0);
            }
        }

        $transactionType = trim((string)($data['transaction_type'] ?? ''));
        if ($transactionType === '') {
            if ($status === 'succeeded') {
                $transactionType = 'provider_refund';
            } elseif ($status === 'failed') {
                $transactionType = 'failure';
            } else {
                $transactionType = 'request';
            }
        }

        $requestPayload = $data['raw_request_payload'] ?? ($data['raw_payload'] ?? ($data['payload_json'] ?? null));
        $responsePayload = $data['raw_response_payload'] ?? ($data['payload_json'] ?? ($data['raw_payload'] ?? null));
        if (is_array($requestPayload) || is_object($requestPayload)) {
            $requestPayload = json_encode($requestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (is_array($responsePayload) || is_object($responsePayload)) {
            $responsePayload = json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $result = bv_order_refund_execute(
            'INSERT INTO order_refund_transactions
             (refund_id, transaction_type, transaction_status, provider, provider_refund_id, provider_payment_intent_id,
              currency, amount, raw_request_payload, raw_response_payload, failure_code, failure_message, created_by_user_id, created_at)
             VALUES
             (:refund_id, :transaction_type, :transaction_status, :provider, :provider_refund_id, :provider_payment_intent_id,
              :currency, :amount, :raw_request_payload, :raw_response_payload, :failure_code, :failure_message, :created_by_user_id, :created_at)',
            [
                'refund_id' => $refundId,
                'transaction_type' => $transactionType,
                'transaction_status' => $status,
                'provider' => (string)($data['provider'] ?? ''),
                'provider_refund_id' => (string)($data['provider_refund_id'] ?? ($data['provider_reference'] ?? '')),
                'provider_payment_intent_id' => (string)($data['provider_payment_intent_id'] ?? ''),
                'currency' => (string)($data['currency'] ?? 'USD'),
                'amount' => bv_order_refund_validate_amount($data['amount'] ?? 0),
                'raw_request_payload' => (string)($requestPayload ?? ''),
                'raw_response_payload' => (string)($responsePayload ?? ''),
                'failure_code' => (string)($data['failure_code'] ?? ''),
                'failure_message' => (string)($data['failure_message'] ?? ($data['error_message'] ?? '')),
                'created_by_user_id' => (int)($data['created_by_user_id'] ?? bv_order_refund_current_user_id()),
                'created_at' => (string)($data['created_at'] ?? bv_order_refund_now()),
            ]
        );

        return (int)($result['insert_id'] ?? $result['last_insert_id'] ?? 0);
    }
}


if (!function_exists('bv_order_refund_insert_ledger')) {
    function bv_order_refund_insert_ledger(array $data): int
    {
        if (!bv_order_refund_table_exists('order_financial_ledger')) {
            return 0;
        }

        $eventType = trim((string)($data['event_type'] ?? ''));
        $entryType = trim((string)($data['entry_type'] ?? ''));
        $direction = trim((string)($data['direction'] ?? ''));

        if ($entryType === '' || $direction === '') {
            $eventMap = [
                'refund_created' => ['entry_type' => 'refund_adjustment', 'direction' => 'out'],
                'refund_approved' => ['entry_type' => 'refund_adjustment', 'direction' => 'out'],
                'refund_processing' => ['entry_type' => 'refund_adjustment', 'direction' => 'out'],
                'refund_partially_refunded' => ['entry_type' => 'refund_out', 'direction' => 'out'],
                'refund_refunded' => ['entry_type' => 'refund_out', 'direction' => 'out'],
                'refund_failed' => ['entry_type' => 'refund_adjustment', 'direction' => 'out'],
                'refund_rejected' => ['entry_type' => 'refund_adjustment', 'direction' => 'out'],
                'refund_cancelled' => ['entry_type' => 'refund_adjustment', 'direction' => 'out'],
            ];
            if ($eventType !== '' && isset($eventMap[$eventType])) {
                $entryType = $entryType !== '' ? $entryType : $eventMap[$eventType]['entry_type'];
                $direction = $direction !== '' ? $direction : $eventMap[$eventType]['direction'];
            }
        }

        if ($entryType === '') {
            $entryType = 'refund_adjustment';
        }
        if ($direction === '') {
            $direction = 'out';
        }

        $refundId = (int)($data['refund_id'] ?? 0);
        $referenceType = (string)($data['reference_type'] ?? 'refund');
        $referenceId = isset($data['reference_id']) ? (string)$data['reference_id'] : ($refundId > 0 ? (string)$refundId : '');

        // Dedup guard: for real-money outflow events (refund_out entries where a
        // provider_reference is present), check that we have not already written
        // a ledger row for this exact refund_id + entry_type + provider_reference.
        // This prevents duplicate ledger rows on webhook replay or double-execute.
        $providerRefForDedup = trim((string)($data['provider_reference'] ?? ($data['provider_refund_id'] ?? '')));
        if ($entryType === 'refund_out' && $providerRefForDedup !== '' && $refundId > 0) {
            try {
                $ledgerExisting = bv_order_refund_query_one(
                    'SELECT id FROM order_financial_ledger
                     WHERE refund_id = :refund_id
                       AND entry_type = :entry_type
                       AND provider_reference = :provider_reference
                     ORDER BY id DESC LIMIT 1',
                    [
                        'refund_id'          => $refundId,
                        'entry_type'         => $entryType,
                        'provider_reference' => $providerRefForDedup,
                    ]
                );
                if ($ledgerExisting) {
                    return (int)($ledgerExisting['id'] ?? 0);
                }
            } catch (Throwable $dedupError) {
                // If dedup query fails (e.g. column absent), continue with insert.
            }
        }

        $result = bv_order_refund_execute(
            'INSERT INTO order_financial_ledger
             (order_id, refund_id, entry_type, direction, currency, amount,
              reference_type, reference_id, provider, provider_reference,
              memo, entry_status, created_by_user_id, created_at)
             VALUES
             (:order_id, :refund_id, :entry_type, :direction, :currency, :amount,
              :reference_type, :reference_id, :provider, :provider_reference,
              :memo, :entry_status, :created_by_user_id, :created_at)',
            [
                'order_id' => (int)($data['order_id'] ?? 0),
                'refund_id' => $refundId,
                'entry_type' => $entryType,
                'direction' => $direction,
                'currency' => (string)($data['currency'] ?? 'USD'),
                'amount' => bv_order_refund_validate_amount($data['amount'] ?? 0),
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'provider' => (string)($data['provider'] ?? ''),
                'provider_reference' => (string)($data['provider_reference'] ?? ''),
                'memo' => (string)($data['memo'] ?? ($data['note'] ?? '')),
                'entry_status' => (string)($data['entry_status'] ?? 'posted'),
                'created_by_user_id' => (int)($data['created_by_user_id'] ?? ($data['actor_user_id'] ?? 0)),
                'created_at' => (string)($data['created_at'] ?? bv_order_refund_now()),
            ]
        );

        return (int)($result['insert_id'] ?? $result['last_insert_id'] ?? 0);
    }
}


if (!function_exists('bv_order_refund_sync_cancellation_bridge')) {
    function bv_order_refund_sync_cancellation_bridge(int $refundId): void
    {
        $refund = bv_order_refund_get_by_id($refundId);
        if (!$refund) {
            throw new RuntimeException('Refund not found for cancellation bridge sync.');
        }

        $cancellationId = (int)($refund['order_cancellation_id'] ?? 0);
        if ($cancellationId <= 0) {
            return;
        }

        $cancellation = bv_order_refund_query_one('SELECT * FROM order_cancellations WHERE id = :id LIMIT 1 FOR UPDATE', ['id' => $cancellationId]);
        if (!$cancellation) {
            return;
        }

        $tx = bv_order_refund_query_one(
            'SELECT provider_refund_id, provider_payment_intent_id
             FROM order_refund_transactions
             WHERE refund_id = :rid
               AND (provider_refund_id <> "" OR provider_payment_intent_id <> "")
             ORDER BY id DESC
             LIMIT 1',
            ['rid' => $refundId]
        );

        $reference = (string)($cancellation['refund_reference'] ?? '');
        if (!empty($tx['provider_refund_id'])) {
            $reference = (string)$tx['provider_refund_id'];
        } elseif (!empty($tx['provider_payment_intent_id'])) {
            $reference = (string)$tx['provider_payment_intent_id'];
        }

        $fromStatus = strtolower((string)($refund['status'] ?? ''));
        $bridgeStatus = (string)($cancellation['refund_status'] ?? '');
        if ($fromStatus === 'pending_approval') {
            $bridgeStatus = $bridgeStatus !== '' ? $bridgeStatus : 'pending';
        } elseif ($fromStatus === 'approved') {
            $bridgeStatus = 'ready';
        } elseif ($fromStatus === 'processing') {
            $bridgeStatus = 'processing';
        } elseif ($fromStatus === 'partially_refunded') {
            $bridgeStatus = 'partially_refunded';
        } elseif ($fromStatus === 'refunded') {
            $bridgeStatus = 'refunded';
        } elseif ($fromStatus === 'failed') {
            $bridgeStatus = 'failed';
        } elseif (in_array($fromStatus, ['rejected', 'cancelled'], true)) {
            if (!in_array($bridgeStatus, ['refunded', 'partially_refunded', 'processing', 'failed'], true)) {
                $bridgeStatus = $bridgeStatus !== '' ? $bridgeStatus : 'pending';
            }
        }

        bv_order_refund_execute(
            'UPDATE order_cancellations
             SET refund_id = :refund_id,
                 approved_refund_amount = :approved_refund_amount,
                 refund_reference = :refund_reference,
                 refund_status = :refund_status,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'refund_id' => $refundId,
                'approved_refund_amount' => bv_order_refund_validate_amount($refund['approved_refund_amount'] ?? 0),
                'refund_reference' => $reference,
                'refund_status' => $bridgeStatus,
                'updated_at' => bv_order_refund_now(),
                'id' => $cancellationId,
            ]
        );
    }
}

// ---------------------------------------------------------------------------
// Audit log helper – writes one row to order_refund_seller_decision_logs.
// Called AFTER the decision UPDATE is committed inside the same transaction.
// If the audit table does not exist (pre-migration), the write is silently
// skipped so the decision itself is never blocked.
// ---------------------------------------------------------------------------
if (!function_exists('bv_order_refund_write_seller_decision_audit_log')) {
    function bv_order_refund_write_seller_decision_audit_log(
        int $refundId,
        int $sellerId,
        string $decision,
        string $oldStatus,
        string $newStatus,
        float $requestedAmount,
        float $approvedAmount,
        int $actorUserId,
        string $actorRole,
        string $note
    ): void {
        if (!bv_order_refund_table_exists('order_refund_seller_decision_logs')) {
            return;
        }

        $ipAddress = null;
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ipAddress = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ipAddress = (string)$_SERVER['REMOTE_ADDR'];
        }
        if ($ipAddress !== null) {
            $ipAddress = substr($ipAddress, 0, 45);
        }

        $userAgent = isset($_SERVER['HTTP_USER_AGENT'])
            ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 512)
            : null;

        try {
            bv_order_refund_execute(
                'INSERT INTO order_refund_seller_decision_logs
                 (refund_id, seller_id, decision, old_status, new_status,
                  requested_amount, approved_amount,
                  actor_user_id, actor_role, note,
                  ip_address, user_agent, created_at)
                 VALUES
                 (:refund_id, :seller_id, :decision, :old_status, :new_status,
                  :requested_amount, :approved_amount,
                  :actor_user_id, :actor_role, :note,
                  :ip_address, :user_agent, :created_at)',
                [
                    'refund_id'        => $refundId,
                    'seller_id'        => $sellerId,
                    'decision'         => $decision,
                    'old_status'       => $oldStatus,
                    'new_status'       => $newStatus,
                    'requested_amount' => $requestedAmount,
                    'approved_amount'  => $approvedAmount,
                    'actor_user_id'    => $actorUserId > 0 ? $actorUserId : null,
                    'actor_role'       => $actorRole !== '' ? $actorRole : null,
                    'note'             => $note !== '' ? $note : null,
                    'ip_address'       => $ipAddress,
                    'user_agent'       => $userAgent,
                    'created_at'       => bv_order_refund_now(),
                ]
            );
        } catch (Throwable $auditError) {
            // Audit log failure must never abort the actual decision.
            bv_order_refund_debug_log('seller_decision_audit_log_failed', [
                'refund_id' => $refundId,
                'seller_id' => $sellerId,
                'error'     => $auditError->getMessage(),
            ]);
        }
    }
}

// ---------------------------------------------------------------------------
// Convenience helper used by refund_action.php seller ownership check.
// Returns the refund items belonging to a specific seller for a given refund.
// ---------------------------------------------------------------------------
if (!function_exists('bv_order_refund_get_items_for_seller')) {
    function bv_order_refund_get_items_for_seller(int $refundId, int $sellerId): array
    {
        if ($refundId <= 0 || $sellerId <= 0) {
            return [];
        }

        return bv_order_refund_query_all(
            'SELECT ri.*
             FROM order_refund_items ri
             INNER JOIN order_items oi ON oi.id = ri.order_item_id
             INNER JOIN listings l ON l.id = oi.listing_id
             WHERE ri.refund_id = :refund_id
               AND l.seller_id = :seller_id
             ORDER BY ri.id ASC',
            [
                'refund_id' => $refundId,
                'seller_id' => $sellerId,
            ]
        );
    }
}

// ---------------------------------------------------------------------------
// Core seller decision function – hardened for production:
//   • transaction + FOR UPDATE locking on all touched rows
//   • double-approve / double-reject guard with precise error codes
//   • partially_approved header state when some sellers approved, others pending
//   • per-seller item approved_amount allocation
//   • audit log written inside the same transaction after the UPDATE
//   • full rollback on any failure
// ---------------------------------------------------------------------------
if (!function_exists('bv_order_refund_seller_decide')) {
    function bv_order_refund_seller_decide(
        int $refundId,
        int $sellerId,
        string $decision,
        $approvedAmount = 0,
        ?string $note = null,
        ?int $actorUserId = null
    ): array {
        bv_order_refund_require_tables();

        if (!bv_order_refund_table_exists('order_refund_seller_decisions')) {
            throw new RuntimeException('order_refund_seller_decisions table is required.');
        }

        if ($refundId <= 0) {
            throw new InvalidArgumentException('Invalid refund ID.');
        }

        if ($sellerId <= 0) {
            throw new InvalidArgumentException('Invalid seller ID.');
        }

        $decision = strtolower(trim($decision));
        if ($decision === 'approve') {
            $decision = 'approved';
        } elseif ($decision === 'reject') {
            $decision = 'rejected';
        }

        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('Seller decision must be approve/reject or approved/rejected.');
        }

        $actorUserId  = is_numeric($actorUserId) ? (int)$actorUserId : bv_order_refund_current_user_id();
        $actorRole    = bv_order_refund_current_user_role();
        $note         = trim((string)$note);
        $now          = bv_order_refund_now();

        try {
            bv_order_refund_begin_transaction();

            // Best-effort sync to ensure decision rows exist for all sellers.
            if (function_exists('bv_order_refund_sync_seller_decisions')) {
                try {
                    bv_order_refund_sync_seller_decisions($refundId);
                } catch (Throwable $ignored) {
                    // non-fatal
                }
            }

            // Lock refund header row.
            $refund = bv_order_refund_query_one(
                'SELECT * FROM order_refunds WHERE id = :id LIMIT 1 FOR UPDATE',
                ['id' => $refundId]
            );
            if (!$refund) {
                throw new RuntimeException('Refund not found.');
            }

            $headerStatus = strtolower(trim((string)($refund['status'] ?? '')));
            if (in_array($headerStatus, ['processing', 'partially_refunded', 'refunded', 'failed', 'cancelled'], true)) {
                throw new RuntimeException(
                    'Refund can no longer be changed by seller decision (header status: ' . $headerStatus . ').'
                );
            }

            // Lock this seller's decision row.
            $sellerDecision = bv_order_refund_query_one(
                'SELECT * FROM order_refund_seller_decisions
                 WHERE refund_id = :refund_id AND seller_id = :seller_id
                 LIMIT 1
                 FOR UPDATE',
                [
                    'refund_id' => $refundId,
                    'seller_id' => $sellerId,
                ]
            );
            if (!$sellerDecision) {
                throw new RuntimeException('Seller decision slice not found. Ensure bv_order_refund_sync_seller_decisions() has run.');
            }

            $currentDecisionStatus = strtolower(trim((string)($sellerDecision['status'] ?? 'pending_approval')));

            // ---------------------------------------------------------------
            // DOUBLE-ACTION GUARD – precise error messages surfaced to caller
            // ---------------------------------------------------------------
            if ($currentDecisionStatus === 'approved') {
                if ($decision === 'approved') {
                    throw new RuntimeException('already_approved: This refund slice has already been approved by your account.');
                }
                // Seller trying to reject after already approving – not allowed without admin flow.
                throw new RuntimeException('already_approved: Cannot reject a slice that was already approved. Contact an administrator.');
            }
            if ($currentDecisionStatus === 'rejected') {
                if ($decision === 'rejected') {
                    throw new RuntimeException('already_rejected: This refund slice has already been rejected by your account.');
                }
                // Seller trying to approve after already rejecting – not allowed without admin flow.
                throw new RuntimeException('already_rejected: Cannot approve a slice that was already rejected. Contact an administrator.');
            }
            if ($currentDecisionStatus !== 'pending_approval') {
                throw new RuntimeException(
                    'stale_action: Unexpected seller decision status "' . $currentDecisionStatus . '". Action cannot be replayed.'
                );
            }

            // Lock seller-owned refund items.
            $sellerItems = bv_order_refund_query_all(
                'SELECT
                    ri.*,
                    oi.id AS order_item_id,
                    l.seller_id AS listing_seller_id
                 FROM order_refund_items ri
                 INNER JOIN order_items oi ON oi.id = ri.order_item_id
                 INNER JOIN listings l ON l.id = oi.listing_id
                 WHERE ri.refund_id = :refund_id
                   AND l.seller_id = :seller_id
                 ORDER BY ri.id ASC
                 FOR UPDATE',
                [
                    'refund_id' => $refundId,
                    'seller_id' => $sellerId,
                ]
            );

            if ($sellerItems === []) {
                throw new RuntimeException('invalid_seller_ownership: No seller-owned refund items found for this seller slice.');
            }

            // Resolve requested slice amount.
            $requestedSliceAmount = bv_order_refund_round_money($sellerDecision['requested_amount'] ?? 0);
            if ($requestedSliceAmount <= 0) {
                foreach ($sellerItems as $sellerItem) {
                    $requestedSliceAmount += bv_order_refund_round_money($sellerItem['requested_refund_amount'] ?? 0);
                }
                $requestedSliceAmount = bv_order_refund_round_money($requestedSliceAmount);
            }

            // Validate and finalise approved amount.
            if ($decision === 'approved') {
                $approvedAmount = bv_order_refund_round_money($approvedAmount);
                if ($approvedAmount <= 0) {
                    $approvedAmount = $requestedSliceAmount;
                }
                if ($approvedAmount <= 0) {
                    throw new RuntimeException('Approved amount must be greater than zero.');
                }
                if ($requestedSliceAmount > 0 && $approvedAmount > $requestedSliceAmount) {
                    throw new RuntimeException('Approved amount cannot exceed seller requested amount.');
                }
            } else {
                $approvedAmount = 0.00;
            }

            // Write the seller decision row.
            bv_order_refund_execute(
                'UPDATE order_refund_seller_decisions
                 SET status = :status,
                     approved_amount = :approved_amount,
                     note = :note,
                     decided_by_user_id = :decided_by_user_id,
                     decided_at = :decided_at,
                     updated_at = :updated_at
                 WHERE refund_id = :refund_id
                   AND seller_id = :seller_id',
                [
                    'status'            => $decision,
                    'approved_amount'   => $approvedAmount,
                    'note'              => $note !== '' ? $note : null,
                    'decided_by_user_id'=> $actorUserId > 0 ? $actorUserId : null,
                    'decided_at'        => $now,
                    'updated_at'        => $now,
                    'refund_id'         => $refundId,
                    'seller_id'         => $sellerId,
                ]
            );

            // Allocate approved amount across seller's line items.
            $weights = [];
            foreach ($sellerItems as $sellerItem) {
                $weight = bv_order_refund_round_money($sellerItem['requested_refund_amount'] ?? 0);
                if ($weight <= 0) {
                    $weight = bv_order_refund_round_money(
                        bv_order_refund_pick_row_value(
                            $sellerItem,
                            ['line_total_snapshot', 'total_snapshot', 'line_total', 'subtotal_snapshot', 'price_snapshot'],
                            0
                        )
                    );
                }
                if ($weight < 0) {
                    $weight = 0.0;
                }
                $weights[] = $weight;
            }

            $allocatedApproved = $decision === 'approved'
                ? bv_order_refund_allocate_weighted_amounts($approvedAmount, $weights)
                : array_fill(0, count($sellerItems), 0.0);

            foreach ($sellerItems as $idx => $sellerItem) {
                $lineApproved = bv_order_refund_round_money($allocatedApproved[$idx] ?? 0);
                bv_order_refund_execute(
                    'UPDATE order_refund_items
                     SET approved_refund_amount = :approved_refund_amount,
                         updated_at = :updated_at
                     WHERE id = :id',
                    [
                        'approved_refund_amount' => $lineApproved,
                        'updated_at'             => $now,
                        'id'                     => (int)$sellerItem['id'],
                    ]
                );
            }

            // Re-read ALL seller decision rows (with lock) to derive header state.
            $allDecisions = bv_order_refund_query_all(
                'SELECT *
                 FROM order_refund_seller_decisions
                 WHERE refund_id = :refund_id
                 ORDER BY seller_id ASC
                 FOR UPDATE',
                ['refund_id' => $refundId]
            );

            if ($allDecisions === []) {
                throw new RuntimeException('Unable to re-read seller decision rows after update.');
            }

            $hasPending  = false;
            $hasApproved = false;
            $hasRejected = false;
            $allApproved = true;
            $totalApprovedAcrossSellers = 0.00;

            foreach ($allDecisions as $decisionRow) {
                $rowStatus        = strtolower(trim((string)($decisionRow['status'] ?? 'pending_approval')));
                $rowApprovedAmount = bv_order_refund_round_money($decisionRow['approved_amount'] ?? 0);

                if ($rowStatus === 'pending_approval' || $rowStatus === '') {
                    $hasPending  = true;
                    $allApproved = false;
                } elseif ($rowStatus === 'rejected') {
                    $hasRejected = true;
                    $allApproved = false;
                } elseif ($rowStatus === 'approved') {
                    $hasApproved = true;
                    $totalApprovedAcrossSellers += $rowApprovedAmount;
                } else {
                    $allApproved = false;
                }
            }

            $totalApprovedAcrossSellers = bv_order_refund_round_money($totalApprovedAcrossSellers);

            // ---------------------------------------------------------------
            // Deterministic header status derivation:
            //   all approved                  → approved
            //   no pending + any rejected     → rejected
            //   some approved + still pending → partially_approved
            //   default (all/some pending)    → pending_approval
            // ---------------------------------------------------------------
            $newHeaderStatus            = 'pending_approval';
            $newCancellationStatus      = 'requested';
            $newCancellationRefundStatus = 'pending';
            $headerApprovedAmount       = 0.00;

            if ($allApproved && !$hasRejected && !$hasPending) {
                $newHeaderStatus            = 'approved';
                $newCancellationStatus      = 'approved';
                $newCancellationRefundStatus = 'ready';
                $headerApprovedAmount       = $totalApprovedAcrossSellers;
            } elseif (!$hasPending && $hasRejected && !$allApproved) {
                $newHeaderStatus            = 'rejected';
                $newCancellationStatus      = 'rejected';
                $newCancellationRefundStatus = 'failed';
                $headerApprovedAmount       = 0.00;
            } elseif ($hasApproved && $hasPending) {
                // Some sellers approved, others still pending → partially_approved.
                // This state signals to admin: not yet ready for payment processing.
                $newHeaderStatus            = 'partially_approved';
                $newCancellationStatus      = 'requested';
                $newCancellationRefundStatus = 'pending';
                $headerApprovedAmount       = $totalApprovedAcrossSellers;
            } else {
                $newHeaderStatus            = 'pending_approval';
                $newCancellationStatus      = 'requested';
                $newCancellationRefundStatus = 'pending';
                $headerApprovedAmount       = 0.00;
            }

            // Update refund header.
            $refundSet = [
                'status = :status',
                'approved_refund_amount = :approved_refund_amount',
                'updated_at = :updated_at',
            ];
            $refundParams = [
                'status'                => $newHeaderStatus,
                'approved_refund_amount'=> $headerApprovedAmount,
                'updated_at'            => $now,
                'id'                    => $refundId,
            ];

            if (bv_order_refund_column_exists('order_refunds', 'approved_at')) {
                $refundSet[]                 = 'approved_at = :approved_at';
                $refundParams['approved_at'] = ($newHeaderStatus === 'approved') ? $now : null;
            }
            if (bv_order_refund_column_exists('order_refunds', 'approved_by_user_id')) {
                $refundSet[]                        = 'approved_by_user_id = :approved_by_user_id';
                $refundParams['approved_by_user_id'] = ($newHeaderStatus === 'approved' && $actorUserId > 0)
                    ? $actorUserId
                    : null;
            }
            if (bv_order_refund_column_exists('order_refunds', 'partially_approved_at')) {
                $refundSet[]                           = 'partially_approved_at = :partially_approved_at';
                $refundParams['partially_approved_at'] = ($newHeaderStatus === 'partially_approved') ? $now : null;
            }
            if (bv_order_refund_column_exists('order_refunds', 'rejected_at')) {
                $refundSet[]                = 'rejected_at = :rejected_at';
                $refundParams['rejected_at'] = ($newHeaderStatus === 'rejected') ? $now : null;
            }
            if (bv_order_refund_column_exists('order_refunds', 'rejected_by_user_id')) {
                $refundSet[]                       = 'rejected_by_user_id = :rejected_by_user_id';
                $refundParams['rejected_by_user_id'] = ($newHeaderStatus === 'rejected' && $actorUserId > 0)
                    ? $actorUserId
                    : null;
            }

            bv_order_refund_execute(
                'UPDATE order_refunds SET ' . implode(', ', $refundSet) . ' WHERE id = :id',
                $refundParams
            );

            // Update linked cancellation record if present.
            $cancellationId = (int)($refund['order_cancellation_id'] ?? 0);
            if ($cancellationId > 0 && bv_order_refund_table_exists('order_cancellations')) {
                $cancelSet = [
                    'status = :status',
                    'refund_status = :refund_status',
                    'approved_refund_amount = :approved_refund_amount',
                    'updated_at = :updated_at',
                ];
                $cancelParams = [
                    'status'                => $newCancellationStatus,
                    'refund_status'         => $newCancellationRefundStatus,
                    'approved_refund_amount'=> $headerApprovedAmount,
                    'updated_at'            => $now,
                    'id'                    => $cancellationId,
                ];

                if (bv_order_refund_column_exists('order_cancellations', 'approved_at')) {
                    $cancelSet[]                 = 'approved_at = :approved_at';
                    $cancelParams['approved_at'] = ($newHeaderStatus === 'approved') ? $now : null;
                }
                if (bv_order_refund_column_exists('order_cancellations', 'approved_by')) {
                    $cancelSet[]                = 'approved_by = :approved_by';
                    $cancelParams['approved_by'] = ($newHeaderStatus === 'approved' && $actorUserId > 0) ? $actorUserId : null;
                }
                if (bv_order_refund_column_exists('order_cancellations', 'rejected_at')) {
                    $cancelSet[]                = 'rejected_at = :rejected_at';
                    $cancelParams['rejected_at'] = ($newHeaderStatus === 'rejected') ? $now : null;
                }

                bv_order_refund_execute(
                    'UPDATE order_cancellations SET ' . implode(', ', $cancelSet) . ' WHERE id = :id',
                    $cancelParams
                );
            }

            bv_order_refund_call_fee_rebuild_for_refund($refundId);
			
            // Write audit log inside the transaction. If the audit table does not
            // exist (pre-migration) the helper silently no-ops.
            bv_order_refund_write_seller_decision_audit_log(
                $refundId,
                $sellerId,
                $decision,
                $currentDecisionStatus,   // old_status (was pending_approval)
                $decision,                // new_status
                $requestedSliceAmount,
                $approvedAmount,
                $actorUserId,
                $actorRole,
                $note
            );

            bv_order_refund_debug_log('seller_refund_decision_applied', [
                'refund_id'                    => $refundId,
                'seller_id'                    => $sellerId,
                'decision'                     => $decision,
                'seller_requested_amount'      => $requestedSliceAmount,
                'seller_approved_amount'       => $approvedAmount,
                'header_status'                => $newHeaderStatus,
                'header_approved_refund_amount'=> $headerApprovedAmount,
                'has_pending'                  => $hasPending,
                'has_approved'                 => $hasApproved,
                'has_rejected'                 => $hasRejected,
                'all_approved'                 => $allApproved,
            ]);

            bv_order_refund_commit();

            return [
                'ok'                           => true,
                'refund_id'                    => $refundId,
                'seller_id'                    => $sellerId,
                'decision'                     => $decision,
                'seller_decision_status'       => $decision,
                'header_status'                => $newHeaderStatus,
                'header_approved_refund_amount'=> $headerApprovedAmount,
                'seller_requested_amount'      => $requestedSliceAmount,
                'seller_approved_amount'       => $approvedAmount,
                'all_approved'                 => $allApproved,
                'has_pending'                  => $hasPending,
                'has_approved'                 => $hasApproved,
                'has_rejected'                 => $hasRejected,
                'refund'                       => bv_order_refund_get_by_id($refundId),
                'seller_decision'              => bv_order_refund_get_seller_decision($refundId, $sellerId),
            ];
        } catch (Throwable $e) {
            bv_order_refund_rollback();
            throw $e;
        }
    }
}

if (!function_exists('bv_order_refund_get_seller_decision')) {
    function bv_order_refund_get_seller_decision(int $refundId, int $sellerId): ?array
    {
        bv_order_refund_require_tables();

        if (!bv_order_refund_table_exists('order_refund_seller_decisions')) {
            return null;
        }

        if ($refundId <= 0 || $sellerId <= 0) {
            return null;
        }

        return bv_order_refund_query_one(
            'SELECT *
             FROM order_refund_seller_decisions
             WHERE refund_id = :refund_id
               AND seller_id = :seller_id
             LIMIT 1',
            [
                'refund_id' => $refundId,
                'seller_id' => $sellerId,
            ]
        );
    }
}

if (!function_exists('bv_order_refund_sync_seller_decisions')) {
    function bv_order_refund_sync_seller_decisions(int $refundId): void
    {
        bv_order_refund_require_tables();

        if ($refundId <= 0) {
            return;
        }

        if (!bv_order_refund_table_exists('order_refund_seller_decisions')) {
            return;
        }

        $rows = bv_order_refund_query_all(
            'SELECT
                ri.refund_id,
                l.seller_id,
                ROUND(SUM(COALESCE(ri.requested_refund_amount, 0)), 2) AS requested_amount
             FROM order_refund_items ri
             INNER JOIN order_items oi ON oi.id = ri.order_item_id
             INNER JOIN listings l ON l.id = oi.listing_id
             WHERE ri.refund_id = :refund_id
             GROUP BY ri.refund_id, l.seller_id',
            ['refund_id' => $refundId]
        );

        foreach ($rows as $row) {
            $sellerId = (int)($row['seller_id'] ?? 0);
            if ($sellerId <= 0) {
                continue;
            }

            $existing = bv_order_refund_query_one(
                'SELECT id
                 FROM order_refund_seller_decisions
                 WHERE refund_id = :refund_id
                   AND seller_id = :seller_id
                 LIMIT 1',
                [
                    'refund_id' => $refundId,
                    'seller_id' => $sellerId,
                ]
            );

            if ($existing) {
                bv_order_refund_execute(
                    'UPDATE order_refund_seller_decisions
                     SET requested_amount = :requested_amount,
                         updated_at = :updated_at
                     WHERE refund_id = :refund_id
                       AND seller_id = :seller_id',
                    [
                        'requested_amount' => (float)($row['requested_amount'] ?? 0),
                        'updated_at' => bv_order_refund_now(),
                        'refund_id' => $refundId,
                        'seller_id' => $sellerId,
                    ]
                );
                continue;
            }

            bv_order_refund_execute(
                'INSERT INTO order_refund_seller_decisions
                 (refund_id, seller_id, status, requested_amount, approved_amount, created_at, updated_at)
                 VALUES
                 (:refund_id, :seller_id, :status, :requested_amount, :approved_amount, :created_at, :updated_at)',
                [
                    'refund_id' => $refundId,
                    'seller_id' => $sellerId,
                    'status' => 'pending_approval',
                    'requested_amount' => (float)($row['requested_amount'] ?? 0),
                    'approved_amount' => 0.00,
                    'created_at' => bv_order_refund_now(),
                    'updated_at' => bv_order_refund_now(),
                ]
            );
        }
    }
}


bv_order_refund_boot();
