<?php
declare(strict_types=1);

/**
 * Bettavaro — Seller Balance / Wallet / Payout System
 * Core library.  Include once per request.
 *
 * seller_id throughout = users.id of the seller user
 *
 * Ledger types:
 *   earning            – net earning added to pending
 *   platform_fee       – pending debit of platform commission
 *   pending_release    – pending → available (2 entries: debit pending + credit available)
 *   refund_hold        – amount held back pending refund decision
 *   refund_deduction   – final deduction after refund processed
 *   adjustment_credit  – manual credit by admin
 *   adjustment_debit   – manual debit by admin
 *   payout_request     – available → held (2 entries)
 *   payout_paid        – held → paid_out (2 entries)
 *   payout_cancelled   – held → available (2 entries)
 *   tax_withholding    – future Phase 2
 */

// ---------------------------------------------------------------------------
// BOOTSTRAP — PDO connection
// ---------------------------------------------------------------------------

$sellerFeePromoFile = __DIR__ . '/seller_fee_promotion.php';
if (is_file($sellerFeePromoFile)) {
    require_once $sellerFeePromoFile;
}
unset($sellerFeePromoFile);

if (!function_exists('_bv_sb_request_context_meta')) {
    function _bv_sb_request_context_meta(): array
    {
        return [
            'ip_address' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ];
    }
}

if (!function_exists('bv_seller_balance_pdo')) {
    function bv_seller_balance_pdo(): PDO
    {
        // Reuse existing platform PDO if available
        if (function_exists('bv_member_pdo')) {
            return bv_member_pdo();
        }

        // Try global $pdo (common pattern in projects without DI)
        global $pdo;
        if ($pdo instanceof PDO) {
            return $pdo;
        }

        // Attempt to bootstrap from project config files
        $root = dirname(__DIR__);
        $candidates = [
            $root . '/includes/db.php',
            $root . '/config/database.php',
            $root . '/config.php',
            $root . '/includes/config.php',
            $root . '/bootstrap.php',
        ];
        foreach ($candidates as $f) {
            if (is_file($f)) {
                require_once $f;
                break;
            }
        }

        // Re-check after potential bootstrap
        if (function_exists('bv_member_pdo')) {
            return bv_member_pdo();
        }
        global $pdo;
        if ($pdo instanceof PDO) {
            return $pdo;
        }

        throw new RuntimeException(
            '[seller_balance] Cannot obtain PDO connection. ' .
            'Ensure bv_member_pdo() or global $pdo is available before including seller_balance.php.'
        );
    }
}

// ---------------------------------------------------------------------------
// SETTINGS
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_get_setting')) {
    function bv_seller_balance_get_setting(string $key, mixed $default = null): string
    {
        static $cache = [];
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        try {
            $stmt = bv_seller_balance_pdo()->prepare(
                'SELECT setting_value FROM seller_balance_settings WHERE setting_key = ? LIMIT 1'
            );
            $stmt->execute([$key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $cache[$key] = $row ? (string)$row['setting_value'] : (string)($default ?? '');
        } catch (Throwable) {
            $cache[$key] = (string)($default ?? '');
        }
        return $cache[$key];
    }
}

if (!function_exists('bv_seller_balance_commission_rate')) {
    function bv_seller_balance_commission_rate(): float
    {
        return (float)bv_seller_balance_get_setting('platform_commission_rate', '0.10');
    }
}

if (!function_exists('bv_seller_balance_default_currency')) {
    function bv_seller_balance_default_currency(): string
    {
        return bv_seller_balance_get_setting('default_currency', 'USD');
    }
}

// ---------------------------------------------------------------------------
// TABLES CHECK
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_tables_exist')) {
    function bv_seller_balance_tables_exist(): bool
    {
        try {
            $pdo = bv_seller_balance_pdo();
            $stmt = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                 AND table_name IN (
                     'seller_balances','seller_ledger',
                     'seller_payout_requests','seller_payout_transactions'
                 )"
            );
            return (int)$stmt->fetchColumn() >= 4;
        } catch (Throwable) {
            return false;
        }
    }
}

// ---------------------------------------------------------------------------
// CURRENT USER HELPERS
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_current_user_id')) {
    function bv_seller_balance_current_user_id(): int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $candidates = [
            $_SESSION['user']['id'] ?? null,
            $_SESSION['admin']['id'] ?? null,
            $_SESSION['auth_user']['id'] ?? null,
            $_SESSION['user_id'] ?? null,
            $_SESSION['admin_id'] ?? null,
        ];

        foreach ($candidates as $id) {
            if (is_numeric($id) && (int)$id > 0) {
                return (int)$id;
            }
        }

        return 0;
    }
}

if (!function_exists('bv_seller_balance_current_user_role')) {
    function bv_seller_balance_current_user_role(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $roleCandidates = [
            $_SESSION['admin_role'] ?? null,
            $_SESSION['admin']['role'] ?? null,
            $_SESSION['admin_user']['role'] ?? null,
            $_SESSION['current_admin']['role'] ?? null,
            $_SESSION['user']['role'] ?? null,
            $_SESSION['auth_user']['role'] ?? null,
            $_SESSION['user_role'] ?? null,
            $_SESSION['role'] ?? null,
        ];

        foreach ($roleCandidates as $role) {
            $role = strtolower(trim((string)$role));
            if ($role !== '') {
                return $role;
            }
        }

        return '';
    }
}

if (!function_exists('bv_seller_balance_is_seller')) {
    function bv_seller_balance_is_seller(): bool
    {
        return bv_seller_balance_current_user_role() === 'seller';
    }
}

if (!function_exists('bv_seller_balance_is_admin')) {
    function bv_seller_balance_is_admin(): bool
    {
        $role = strtolower(trim((string) bv_seller_balance_current_user_role()));

        return in_array($role, ['admin', 'super_admin', 'superadmin', 'owner'], true);
    }
}

// ---------------------------------------------------------------------------
// SELLER BALANCE — GET / ENSURE
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_get')) {
    /**
     * Get balance row for a seller. Returns null if not found.
     */
    function bv_seller_balance_get(int $sellerId): ?array
    {
        if ($sellerId <= 0) {
            return null;
        }
        try {
            $stmt = bv_seller_balance_pdo()->prepare(
                'SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1'
            );
            $stmt->execute([$sellerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}

if (!function_exists('bv_seller_balance_ensure')) {
    /**
     * Ensure a seller_balances row exists. Returns the current row.
     * Uses INSERT IGNORE + re-fetch to be race-condition-safe.
     */
    function bv_seller_balance_ensure(int $sellerId, string $currency = ''): array
    {
        if ($currency === '') {
            $currency = bv_seller_balance_default_currency();
        }
        $pdo = bv_seller_balance_pdo();
        $pdo->prepare(
            'INSERT IGNORE INTO seller_balances (seller_id, currency) VALUES (?, ?)'
        )->execute([$sellerId, $currency]);

        $stmt = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1');
        $stmt->execute([$sellerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}

// ---------------------------------------------------------------------------
// LEDGER — INTERNAL INSERT (must be called inside a DB transaction)
// ---------------------------------------------------------------------------

if (!function_exists('_bv_sb_insert_ledger')) {
    /**
     * Internal: insert one ledger row within an already-open transaction.
     * Returns the new ledger id.
     *
     * @param array{
     *   seller_id: int,
     *   type: string,
     *   balance_type: string,
     *   direction: string,
     *   amount: float,
     *   currency: string,
     *   balance_before: float,
     *   balance_after: float,
     *   reference_type?: string|null,
     *   reference_id?: int|null,
     *   idempotency_key?: string|null,
     *   note?: string|null,
     *   meta_json?: array|null,
     *   created_by_type?: string|null,
     *   created_by_id?: int|null,
     * } $e
     */
    function _bv_sb_insert_ledger(PDO $pdo, array $e): int
    {
        $meta = isset($e['meta_json']) ? json_encode($e['meta_json'], JSON_UNESCAPED_UNICODE) : null;
        $stmt = $pdo->prepare(
            'INSERT INTO seller_ledger
             (seller_id, type, balance_type, direction, amount, currency,
              balance_before, balance_after, reference_type, reference_id,
              idempotency_key, note, meta_json, created_by_type, created_by_id)
             VALUES
             (:seller_id, :type, :balance_type, :direction, :amount, :currency,
              :bb, :ba, :ref_type, :ref_id,
              :idem, :note, :meta, :cbt, :cbi)'
        );
        $stmt->execute([
            ':seller_id'   => $e['seller_id'],
            ':type'        => $e['type'],
            ':balance_type'=> $e['balance_type'],
            ':direction'   => $e['direction'],
            ':amount'      => round((float)($e['amount'] ?? 0), 4),
            ':currency'    => $e['currency'] ?? 'USD',
            ':bb'          => round((float)($e['balance_before'] ?? 0), 4),
            ':ba'          => round((float)($e['balance_after']  ?? 0), 4),
            ':ref_type'    => $e['reference_type'] ?? null,
            ':ref_id'      => isset($e['reference_id']) ? (int)$e['reference_id'] : null,
            ':idem'        => $e['idempotency_key'] ?? null,
            ':note'        => $e['note'] ?? null,
            ':meta'        => $meta,
            ':cbt'         => $e['created_by_type'] ?? 'system',
            ':cbi'         => isset($e['created_by_id']) ? (int)$e['created_by_id'] : null,
        ]);
        return (int)$pdo->lastInsertId();
    }
}


if (!function_exists('_bv_sb_guard_no_negative')) {
    /**
     * Anti-negative balance guard.
     * Throws RuntimeException if $currentBalance - $debitAmount < 0.
     * Call this inside an active transaction BEFORE updating the balance.
     *
     * @throws RuntimeException
     */
    function _bv_sb_guard_no_negative(
        float  $currentBalance,
        float  $debitAmount,
        string $context = ''
    ): void {
        if (round($currentBalance - $debitAmount, 4) < -0.00005) {
            throw new RuntimeException(sprintf(
                '[seller_balance] Anti-negative guard: cannot debit %.4f from balance %.4f%s.',
                $debitAmount,
                $currentBalance,
                $context !== '' ? ' (' . $context . ')' : ''
            ));
        }
    }
}

if (!function_exists('_bv_sb_table_exists')) {
    function _bv_sb_table_exists(PDO $pdo, string $table): bool
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
            );
            $stmt->execute([$table]);
            $cache[$table] = (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            $cache[$table] = false;
        }
        return $cache[$table];
    }
}

if (!function_exists('_bv_sb_column_exists')) {
    function _bv_sb_column_exists(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
            );
            $stmt->execute([$table, $column]);
            $cache[$key] = (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            $cache[$key] = false;
        }
        return $cache[$key];
    }
}

if (!function_exists('_bv_sb_ledger_exists')) {
    function _bv_sb_ledger_exists(PDO $pdo, string $idempotencyKey): bool
    {
        $stmt = $pdo->prepare('SELECT id FROM seller_ledger WHERE idempotency_key = ? LIMIT 1');
        $stmt->execute([$idempotencyKey]);
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('_bv_sb_ledger_reference_duplicate_exists')) {
    function _bv_sb_ledger_reference_duplicate_exists(PDO $pdo, array $entry): bool
    {
        $referenceType = (string)($entry['reference_type'] ?? '');
        $referenceId   = isset($entry['reference_id']) ? (int)$entry['reference_id'] : 0;
        $sellerId      = isset($entry['seller_id']) ? (int)$entry['seller_id'] : 0;
        $type          = (string)($entry['type'] ?? '');
        if ($referenceType === '' || $referenceId <= 0 || $sellerId <= 0 || $type === '') {
            return false;
        }

        $sql = 'SELECT id FROM seller_ledger
                WHERE reference_type = :reference_type
                  AND reference_id = :reference_id
                  AND seller_id = :seller_id
                  AND type = :type';
        $params = [
            ':reference_type' => $referenceType,
            ':reference_id'   => $referenceId,
            ':seller_id'      => $sellerId,
            ':type'           => $type,
        ];

        if (isset($entry['balance_type'])) {
            $sql .= ' AND balance_type = :balance_type';
            $params[':balance_type'] = (string)$entry['balance_type'];
        }
        if (isset($entry['direction'])) {
            $sql .= ' AND direction = :direction';
            $params[':direction'] = (string)$entry['direction'];
        }

        $stmt = $pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('_bv_sb_log_payout_ledger_duplicate_skip')) {
    function _bv_sb_log_payout_ledger_duplicate_skip(array $entry, string $guard): void
    {
        bv_seller_balance_log('payout_ledger_duplicate_skip', [
            'guard'           => $guard,
            'seller_id'       => isset($entry['seller_id']) ? (int)$entry['seller_id'] : null,
            'entry_type'      => (string)($entry['type'] ?? ''),
            'balance_type'    => (string)($entry['balance_type'] ?? ''),
            'direction'       => (string)($entry['direction'] ?? ''),
            'reference_type'  => (string)($entry['reference_type'] ?? ''),
            'reference_id'    => isset($entry['reference_id']) ? (int)$entry['reference_id'] : null,
            'idempotency_key' => (string)($entry['idempotency_key'] ?? ''),
        ]);
    }
}


if (!function_exists('_bv_sb_insert_ledger_once')) {
    function _bv_sb_insert_ledger_once(PDO $pdo, array $entry): int
    {
        $key = (string)($entry['idempotency_key'] ?? '');
        if ($key !== '' && _bv_sb_ledger_exists($pdo, $key)) {
            _bv_sb_log_payout_ledger_duplicate_skip($entry, 'idempotency_key');
            return 0;
        }
        if (_bv_sb_ledger_reference_duplicate_exists($pdo, $entry)) {
            _bv_sb_log_payout_ledger_duplicate_skip($entry, 'reference');			
            return 0;
        }
        return _bv_sb_insert_ledger($pdo, $entry);
    }
}

if (!function_exists('_bv_sb_log_payout_status_locked')) {
    function _bv_sb_log_payout_status_locked(int $payoutId, int $adminId, string $action, string $status): void
    {
        bv_seller_balance_log('payout_status_locked', [
            'payout_id' => $payoutId,
            'admin_id'  => $adminId,
            'action'    => $action,
            'status'    => $status,
        ]);
        bv_seller_balance_log('payout_duplicate_action_blocked', [
            'payout_id'       => $payoutId,
            'admin_id'        => $adminId,
            'action'          => $action,
            'current_status'  => $status,
            'idempotency_key' => $payoutId . ':' . $action . ':' . $adminId,
        ]);
    }
}

if (!function_exists('_bv_sb_payout_processed_noop')) {
    function _bv_sb_payout_processed_noop(int $payoutId, string $status): array
    {
        return [
            'ok'                => true,
            'noop'              => true,
            'already_processed' => true,
            'payout_request_id' => $payoutId,
            'status'            => $status,
        ];
    }
}


if (!function_exists('bv_seller_balance_create_entries_from_paid_order')) {
    function bv_seller_balance_create_entries_from_paid_order(int $orderId): array
    {
        $result = ['created_or_touched' => 0, 'skipped' => 0, 'entry_ids' => []];
        if ($orderId <= 0) {
            return $result;
        }

        $pdo = bv_seller_balance_pdo();
        if (!_bv_sb_table_exists($pdo, 'seller_balance_entries')) {
            return $result;
        }

        $orderStmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return $result;
        }

        $hasOiSellerId = _bv_sb_column_exists($pdo, 'order_items', 'seller_id');
        $hasOiListingId = _bv_sb_column_exists($pdo, 'order_items', 'listing_id');
        $hasOiLineTotal = _bv_sb_column_exists($pdo, 'order_items', 'line_total');
        $hasOiUnitPrice = _bv_sb_column_exists($pdo, 'order_items', 'unit_price');
        $hasOiPrice = _bv_sb_column_exists($pdo, 'order_items', 'price');
        $hasOiQty = _bv_sb_column_exists($pdo, 'order_items', 'quantity');
        $hasOiCurrency = _bv_sb_column_exists($pdo, 'order_items', 'currency');
        $hasOiStatus = _bv_sb_column_exists($pdo, 'order_items', 'status');
        $hasOiFulfillmentStatus = _bv_sb_column_exists($pdo, 'order_items', 'fulfillment_status');
        $hasOrderCurrency = _bv_sb_column_exists($pdo, 'orders', 'currency');
        $hasListingsSellerId = _bv_sb_column_exists($pdo, 'listings', 'seller_id');

        $itemSql = 'SELECT oi.*';
        if ($hasListingsSellerId && $hasOiListingId) {
            $itemSql .= ', l.seller_id AS listing_seller_id';
        } else {
            $itemSql .= ', NULL AS listing_seller_id';
        }
        $itemSql .= ' FROM order_items oi';
        if ($hasListingsSellerId && $hasOiListingId) {
            $itemSql .= ' LEFT JOIN listings l ON l.id = oi.listing_id';
        }
        $itemSql .= ' WHERE oi.order_id = ?';

        $itemStmt = $pdo->prepare($itemSql);
        $itemStmt->execute([$orderId]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        $insertSql = "INSERT INTO seller_balance_entries
            (seller_id, order_id, order_item_id, listing_id, amount, currency, status, risk_score, risk_flags_json, hold_reason, release_at, source, created_at, updated_at)
            VALUES
            (:seller_id, :order_id, :order_item_id, :listing_id, :amount, :currency, 'pending', 0, :risk_flags_json, 'order_paid_hold', DATE_ADD(NOW(), INTERVAL 1 DAY), 'order_paid', NOW(), NOW())
            ON DUPLICATE KEY UPDATE
            listing_id = IF(status IN ('paid_out','processing'), listing_id, VALUES(listing_id)),
            amount = IF(status IN ('paid_out','processing'), amount, VALUES(amount)),
            currency = IF(status IN ('paid_out','processing'), currency, VALUES(currency)),
            release_at = IF(status IN ('paid_out','processing'), release_at, VALUES(release_at)),
            updated_at = NOW()";
        $insertStmt = $pdo->prepare($insertSql);

        foreach ($items as $item) {
            $itemStatus = strtolower(trim((string)($item['status'] ?? '')));
            $fulfillmentStatus = strtolower(trim((string)($item['fulfillment_status'] ?? '')));
            if (($hasOiStatus && in_array($itemStatus, ['cancelled', 'canceled', 'refunded'], true)) ||
                ($hasOiFulfillmentStatus && in_array($fulfillmentStatus, ['cancelled', 'canceled', 'refunded'], true))) {
                $result['skipped']++;
                continue;
            }

            $sellerId = 0;
            if ($hasOiSellerId) {
                $sellerId = (int)($item['seller_id'] ?? 0);
            }
            if ($sellerId <= 0) {
                $sellerId = (int)($item['listing_seller_id'] ?? 0);
            }
            if ($sellerId <= 0) {
                $result['skipped']++;
                continue;
            }

            $qty = $hasOiQty ? (float)($item['quantity'] ?? 0) : 0.0;
            $lineTotal = $hasOiLineTotal ? (float)($item['line_total'] ?? 0) : 0.0;
            $unitPrice = $hasOiUnitPrice ? (float)($item['unit_price'] ?? 0) : 0.0;
            $price = $hasOiPrice ? (float)($item['price'] ?? 0) : 0.0;
            $amount = 0.0;
            if ($lineTotal > 0) {
                $amount = $lineTotal;
            } elseif ($qty > 0 && $unitPrice > 0) {
                $amount = $qty * $unitPrice;
            } elseif ($qty > 0 && $price > 0) {
                $amount = $qty * $price;
            }
            $amount = round($amount, 4);
            if ($amount <= 0) {
                $result['skipped']++;
                continue;
            }

            $currency = '';
            if ($hasOiCurrency) {
                $currency = trim((string)($item['currency'] ?? ''));
            }
            if ($currency === '' && $hasOrderCurrency) {
                $currency = trim((string)($order['currency'] ?? ''));
            }
            if ($currency === '') {
                $currency = 'USD';
            }

            $itemId = (int)($item['id'] ?? 0);
            $listingId = $hasOiListingId ? (int)($item['listing_id'] ?? 0) : 0;

            $insertStmt->execute([
                ':seller_id' => $sellerId,
                ':order_id' => $orderId,
                ':order_item_id' => $itemId,
                ':listing_id' => $listingId > 0 ? $listingId : null,
                ':amount' => $amount,
                ':currency' => $currency,
                ':risk_flags_json' => json_encode((object)[], JSON_UNESCAPED_UNICODE),
            ]);

            $lookup = $pdo->prepare('SELECT id FROM seller_balance_entries WHERE seller_id = ? AND order_id = ? AND order_item_id = ? LIMIT 1');
            $lookup->execute([$sellerId, $orderId, $itemId]);
            $entryId = (int)$lookup->fetchColumn();
            if ($entryId > 0) {
                $result['entry_ids'][] = $entryId;
            }
            $result['created_or_touched']++;
        }

        return $result;
    }
}

// ---------------------------------------------------------------------------
// ORDER PAID — MAIN HOOK (called by order_paid_handler.php)
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_process_order_paid')) {
    /**
     * Process seller-owned order_items when an order is paid.
     * Earning is gross credit to pending; platform_fee is a pending debit.
     */
    function bv_seller_balance_process_order_paid(int $orderId): array
    {
        if ($orderId <= 0 || !bv_seller_balance_tables_exist()) {
            return [];
        }

        $pdo = bv_seller_balance_pdo();
        $commissionRate = bv_seller_balance_commission_rate();
        $processed = [];

        $stmt = $pdo->prepare(
            'SELECT oi.id AS item_id,
                    oi.seller_id,
                    oi.line_total,
                    oi.currency,
                    oi.item_title,
                    o.order_code
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE oi.order_id = ?
               AND oi.seller_id > 0'
        );
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            $itemId = (int)$item['item_id'];
            $sellerId = (int)$item['seller_id'];
            $currency = (string)($item['currency'] ?? 'USD');
            $gross = round((float)($item['line_total'] ?? 0), 4);

            if ($gross <= 0 || $sellerId <= 0) {
                continue;
            }

            $earningKey = 'order_paid_earning:' . $orderId . ':' . $itemId;
            $feeKey = 'order_paid_platform_fee:' . $orderId . ':' . $itemId;
            $legacyKey = 'order_paid:' . $orderId . ':' . $itemId;

            $defaultPlatformFeePercent = (float)$commissionRate;
            if ($defaultPlatformFeePercent <= 1) {
                $defaultPlatformFeePercent *= 100;
            }
            $defaultPlatformFeePercent = round($defaultPlatformFeePercent, 4);
            $platformFee = round($gross * ((float)$commissionRate), 4);   
            $platformFeePercent = $defaultPlatformFeePercent;
            $feeMode = 'default';
			
            if (function_exists('bv_seller_fee_promo_apply_fee_amount')) {
                try {
                     $feeDecision = bv_seller_fee_promo_apply_fee_amount(
                        (float)$gross,
                        (float)$defaultPlatformFeePercent,
                        (int)$sellerId
                    );

                    if (is_array($feeDecision)) {
                        $platformFee = round((float)($feeDecision['fee_amount'] ?? $platformFee), 4);
                        $platformFeePercent = (float)($feeDecision['percent_used'] ?? $defaultPlatformFeePercent);
                        $feeMode = (string)($feeDecision['mode'] ?? 'default');
                    }
                } catch (Throwable) {
                   // Keep the existing default platform fee.
                }
  
            }
            $platformFeeWaived = $platformFee <= 0;
            $netEarning = round($gross - $platformFee, 4);

            try {
                $pdo->beginTransaction();

                $legacyExists = _bv_sb_ledger_exists($pdo, $legacyKey);
                $earningExists = _bv_sb_ledger_exists($pdo, $earningKey);
                $feeExists = _bv_sb_ledger_exists($pdo, $feeKey);

                if ($legacyExists) {
                    $pdo->rollBack();
                    $processed[] = $itemId;
                    continue;
                }

                 if ($earningExists && ($feeExists || $platformFee <= 0)) { 
                    $pdo->rollBack();
                    $processed[] = $itemId;
                    continue;
                }

                $pdo->prepare(
                    'INSERT IGNORE INTO seller_balances (seller_id, currency) VALUES (?, ?)'
                )->execute([$sellerId, $currency]);

                $balRow = $pdo->prepare(
                    'SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE'
                );
                $balRow->execute([$sellerId]);
                $balance = $balRow->fetch(PDO::FETCH_ASSOC);

                $pendingBefore = round((float)($balance['pending_balance'] ?? 0), 4);
                 $pendingCursor = $pendingBefore;
                $pendingDelta = 0.0;
                $grossDelta = 0.0;
                $feeDelta = 0.0;

                 if (!$earningExists) {
                    $pendingAfterEarning = round($pendingCursor + $gross, 4);
                    _bv_sb_insert_ledger_once($pdo, [
                        'seller_id'       => $sellerId,
                        'type'            => 'earning',
                        'balance_type'    => 'pending',
                        'direction'       => 'credit',
                        'amount'          => $gross,
                        'currency'        => $currency,
                        'balance_before'  => $pendingCursor,
                        'balance_after'   => $pendingAfterEarning,
                        'reference_type'  => 'order_item',
                        'reference_id'    => $itemId,
                        'idempotency_key' => $earningKey,
                        'note'            => 'Order #' . $orderId . ' item #' . $itemId . ': ' . ($item['item_title'] ?? ''),
                        'meta_json'       => [
                            'order_id'        => $orderId,
                            'order_code'      => $item['order_code'] ?? '',
                            'order_item_id'   => $itemId,
                            'gross'           => $gross,
                            'commission_rate' => $commissionRate,
                           'platform_fee_percent' => $platformFeePercent,
                            'platform_fee_mode' => $feeMode,							
                            'platform_fee'    => $platformFee,
	                        'platform_fee_waived' => $platformFeeWaived,						
                            'net_earning'     => $netEarning,
                        ],
                        'created_by_type' => 'system',
                    ]);
                    $pendingCursor = $pendingAfterEarning;
                    $pendingDelta = round($pendingDelta + $gross, 4);
                    $grossDelta = $gross;
                }
                 if (!$feeExists && $platformFee <= 0 && function_exists('bv_seller_balance_log')) {
                    bv_seller_balance_log('platform_fee_waived', [
                        'order_id' => $orderId,
                        'order_item_id' => $itemId,
                        'seller_id' => $sellerId,
                        'gross' => $gross,
                        'platform_fee_percent' => $platformFeePercent,
                        'platform_fee_mode' => $feeMode,
                       'platform_fee' => $platformFee,						
                    ]);
                }

               if (!$feeExists && $platformFee > 0) {
                    $pendingAfterFee = round($pendingCursor - $platformFee, 4);
                    _bv_sb_insert_ledger_once($pdo, [
                        'seller_id'       => $sellerId,
                        'type'            => 'platform_fee',
                        'balance_type'    => 'pending',
                        'direction'       => 'debit',
                        'amount'          => $platformFee,
                        'currency'        => $currency,
                        'balance_before'  => $pendingCursor,
                        'balance_after'   => $pendingAfterFee,
                        'reference_type'  => 'order_item',
                        'reference_id'    => $itemId,
                        'idempotency_key' => $feeKey,
                        'note'            => 'Platform fee ' . round($platformFeePercent, 2) . '% on order #' . $orderId,
                        'meta_json'       => [
                            'order_id'        => $orderId,
                            'order_item_id'   => $itemId,
                            'gross'           => $gross,
                            'commission_rate' => $commissionRate,
                            'platform_fee_percent' => $platformFeePercent,
                            'platform_fee_mode' => $feeMode,							
                            'platform_fee'    => $platformFee,
                        ],
                        'created_by_type' => 'system',
                    ]);
                    $pendingCursor = $pendingAfterFee;
                    $pendingDelta = round($pendingDelta - $platformFee, 4);
                    $feeDelta = $platformFee;
                }
				
                if ($pendingDelta !== 0.0 || $grossDelta !== 0.0 || $feeDelta !== 0.0) {
                    $pdo->prepare(
                        'UPDATE seller_balances
                         SET pending_balance = pending_balance + :pending_delta,
                             total_earned_gross = total_earned_gross + :gross_delta,
                             total_platform_fee = total_platform_fee + :fee_delta,
                             currency = :currency
                         WHERE seller_id = :sid'
                    )->execute([
                        ':pending_delta' => $pendingDelta,
                        ':gross_delta' => $grossDelta,
                        ':fee_delta' => $feeDelta,
                        ':currency' => $currency,
                        ':sid' => $sellerId,
                    ]);
                }
                $pdo->commit();
                $processed[] = $itemId;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[seller_balance] process_order_paid failed for order #' . $orderId . ' item #' . $itemId . ': ' . $e->getMessage());
            }
        }

         try {
            $entryResult = bv_seller_balance_create_entries_from_paid_order($orderId);
            if (function_exists('bv_seller_balance_log')) {
                bv_seller_balance_log('order_paid_entries_sync', [
                    'order_id' => $orderId,
                    'result' => $entryResult,
                ]);
            }
        } catch (Throwable $e) {
            error_log('[seller_balance] create_entries_from_paid_order failed for order #' . $orderId . ': ' . $e->getMessage());
        }

        return $processed;
    }
}
// ---------------------------------------------------------------------------
// PENDING → AVAILABLE RELEASE
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Internal: find pending sale ledger rows eligible for release.
// Supports both legacy 'earning' and newer 'sale_pending' ledger types.
// Purely ledger-based — no fulfillment or order-status gating (per design).
// Checks both new-format and legacy idempotency keys so already-released rows
// are excluded regardless of which release path wrote them.
// ---------------------------------------------------------------------------
if (!function_exists('bv_seller_balance_find_releasable_ledger_rows')) {
    function bv_seller_balance_find_releasable_ledger_rows(?int $sellerId = null): array
    {
        $pdo  = bv_seller_balance_pdo();
        $days = max(0, (int)bv_seller_balance_get_setting('payout_clearance_days', '3'));

        $params    = [$days];
        $sellerSql = '';
        if ($sellerId !== null && $sellerId > 0) {
            $sellerSql  = ' AND e.seller_id = ?';
            $params[]   = $sellerId;
        }

        // Refund block: if the tables exist, exclude items with active refunds.
        $activeRefundSql = '';
        if (_bv_sb_table_exists($pdo, 'order_refunds') && _bv_sb_table_exists($pdo, 'order_refund_items')) {
            $activeRefundSql = "
               AND NOT EXISTS (
                   SELECT 1
                   FROM order_refund_items ri
                   JOIN order_refunds r ON r.id = ri.refund_id
                   WHERE ri.order_item_id = e.reference_id
                     AND r.status IN ('draft','pending_approval','partially_approved',
                                      'approved','processing','partially_refunded')
               )";
        }

        // NOT EXISTS: covers both new key format and legacy key format so rows
        // released by either path are correctly excluded.
        $sql = "SELECT e.seller_id,
                       e.reference_id                                            AS order_item_id,
                       e.currency,
                       e.created_at,
                       COALESCE(oi.order_id, 0)                                 AS order_id,
                       COALESCE(o.order_code, '')                               AS order_code,
                       COALESCE(e.amount, 0)                                    AS gross_amount,
                       COALESCE(f.pending_fee_amount, 0)                        AS fee_amount,
                       COALESCE(e.amount, 0) - COALESCE(f.pending_fee_amount, 0) AS net_amount
                FROM seller_ledger e
                LEFT JOIN order_items oi ON oi.id = e.reference_id
                LEFT JOIN orders      o  ON o.id  = oi.order_id
                LEFT JOIN (
                    SELECT seller_id,
                           reference_id,
                           SUM(amount) AS pending_fee_amount
                    FROM seller_ledger
                    WHERE type          = 'platform_fee'
                      AND balance_type  = 'pending'
                      AND direction     = 'debit'
                      AND reference_type = 'order_item'
                    GROUP BY seller_id, reference_id
                ) f ON f.seller_id = e.seller_id AND f.reference_id = e.reference_id
                WHERE e.type          IN ('earning', 'sale_pending')
                  AND e.balance_type   = 'pending'
                  AND e.direction      = 'credit'
                  AND e.reference_type = 'order_item'
                  AND e.amount         > 0
                  AND e.created_at    <= DATE_SUB(NOW(), INTERVAL ? DAY)
                  -- Exclude rows already released via NEW key format
                  AND NOT EXISTS (
                      SELECT 1 FROM seller_ledger k1
                      WHERE k1.idempotency_key IN (
                          CONCAT('release_pending_debit:', e.reference_id),
                          CONCAT('release_available_credit:', e.reference_id)
                      )
                  )
                  -- Exclude rows already released via LEGACY key format
                  AND NOT EXISTS (
                      SELECT 1 FROM seller_ledger k2
                      WHERE k2.idempotency_key IN (
                          CONCAT('pending_release_debit:', e.seller_id, ':', e.reference_id),
                          CONCAT('pending_release_credit:', e.seller_id, ':', e.reference_id)
                      )
                  )
                  -- Exclude rows with an active refund hold
                  AND NOT EXISTS (
                      SELECT 1 FROM seller_ledger rh
                      WHERE rh.seller_id      = e.seller_id
                        AND rh.type           = 'refund_hold'
                        AND rh.reference_type = 'order_item'
                        AND rh.reference_id   = e.reference_id
                  )
                  {$sellerSql}
                  {$activeRefundSql}
                HAVING net_amount > 0
                ORDER BY e.created_at ASC, e.id ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

// ---------------------------------------------------------------------------
// Internal: canonical single-row pending → available release worker.
// Uses new idempotency key format while also recognising legacy format.
// Returns rich detail array consumed by public wrappers.
// ---------------------------------------------------------------------------
if (!function_exists('_bv_sb_release_one_row')) {
    /**
     * Core pending → available release worker.
     * Idempotency: checks both old and new key formats before inserting.
     * New ledger rows are written with the OLD (seller-scoped) key format for
     * backward compatibility with existing production ledger rows.
     * Returns a full detail array; the 'message' field summarises the outcome.
     */
    function _bv_sb_release_one_row(PDO $pdo, array $row): array
    {
        $sellerId     = (int)($row['seller_id']     ?? 0);
        $orderItemId  = (int)($row['order_item_id'] ?? $row['reference_id'] ?? 0);
        $sourceAmount = round((float)($row['net_amount'] ?? $row['amount'] ?? 0), 4);
        $currency     = (string)($row['currency']   ?? 'USD');
        $orderId      = (int)($row['order_id']      ?? 0);
        $orderCode    = (string)($row['order_code'] ?? '');

        $base = [
            'ok'                     => false,
            'noop'                   => false,
            'seller_id'              => $sellerId,
            'order_item_id'          => $orderItemId,
            'source_amount'          => $sourceAmount,
            'actual_released_amount' => 0.0,
            'shortfall'              => 0.0,
            'debit_ledger_id'        => null,
            'credit_ledger_id'       => null,
            'balance'                => null,
            'message'                => '',
        ];

        if ($sellerId <= 0 || $orderItemId <= 0 || $sourceAmount <= 0) {
            $base['message'] = 'Invalid input: seller_id, order_item_id, or source_amount is missing.';
            $base['noop']    = true;
            return $base;
        }

        // Idempotency key pairs.
        // OLD format (seller-scoped) — used for new inserts and for checking legacy rows.
        $debitKeyOld  = 'pending_release_debit:'  . $sellerId . ':' . $orderItemId;
        $creditKeyOld = 'pending_release_credit:' . $sellerId . ':' . $orderItemId;
        // NEW format (item-scoped only) — checked for rows written by earlier patch version.
        $debitKeyNew  = 'release_pending_debit:'    . $orderItemId;
        $creditKeyNew = 'release_available_credit:' . $orderItemId;

        bv_seller_balance_log('pending_release_started', [
            'seller_id'     => $sellerId,
            'order_item_id' => $orderItemId,
            'source_amount' => $sourceAmount,
        ]);

        $ownTransaction = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $ownTransaction = true;
            }

            // ── Idempotency: check both old and new formats ──────────────────
            $debitExists  = _bv_sb_ledger_exists($pdo, $debitKeyOld)
                         || _bv_sb_ledger_exists($pdo, $debitKeyNew);
            $creditExists = _bv_sb_ledger_exists($pdo, $creditKeyOld)
                         || _bv_sb_ledger_exists($pdo, $creditKeyNew);

            if ($debitExists && $creditExists) {
                if ($ownTransaction) { $pdo->rollBack(); }
                bv_seller_balance_log('pending_release_noop', [
                    'seller_id'     => $sellerId,
                    'order_item_id' => $orderItemId,
                    'reason'        => 'already_released',
                ]);
                return array_merge($base, [
                    'ok'      => true,
                    'noop'    => true,
                    'message' => 'Already released (idempotency).',
                ]);
            }

            if ($debitExists xor $creditExists) {
                // One side present, other missing — ledger inconsistency.
                if ($ownTransaction) { $pdo->rollBack(); }
                $msg = '[seller_balance] Ledger inconsistency for order_item #' . $orderItemId
                     . ': exactly one of debit/credit release entries exists. Manual review required.';
                bv_seller_balance_log('pending_release_inconsistent_idempotency', [
                    'seller_id'     => $sellerId,
                    'order_item_id' => $orderItemId,
                    'debit_exists'  => $debitExists,
                    'credit_exists' => $creditExists,
                ]);
                return array_merge($base, ['ok' => false, 'message' => $msg]);
            }

            // ── Lock seller_balances row (SELECT ... FOR UPDATE) ─────────────
            $balStmt = $pdo->prepare(
                'SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE'
            );
            $balStmt->execute([$sellerId]);
            $balance = $balStmt->fetch(PDO::FETCH_ASSOC);
            if (!$balance) {
                if ($ownTransaction) { $pdo->rollBack(); }
                $msg = 'No seller_balances row found for seller #' . $sellerId . '.';
                bv_seller_balance_log('pending_release_failed', [
                    'seller_id'     => $sellerId,
                    'order_item_id' => $orderItemId,
                    'reason'        => 'no_balance_row',
                ]);
                return array_merge($base, ['message' => $msg]);
            }

            $pendingNow   = round((float)($balance['pending_balance']   ?? 0), 4);
            $availableNow = round((float)($balance['available_balance'] ?? 0), 4);

            // ── Anti-negative: pending_balance is zero → noop ────────────────
            if ($pendingNow <= 0) {
                if ($ownTransaction) { $pdo->rollBack(); }
                bv_seller_balance_log('pending_release_noop', [
                    'seller_id'     => $sellerId,
                    'order_item_id' => $orderItemId,
                    'reason'        => 'pending_balance_zero',
                    'pending_now'   => $pendingNow,
                ]);
                return array_merge($base, [
                    'ok'      => true,
                    'noop'    => true,
                    'message' => 'Pending balance is zero; nothing to release.',
                ]);
            }

            // ── Shortfall: cap release at available pending ───────────────────
            $shortfall  = 0.0;
            $releaseAmt = $sourceAmount;
            if ($sourceAmount > $pendingNow + 0.00005) {
                $shortfall  = round($sourceAmount - $pendingNow, 4);
                $releaseAmt = $pendingNow;
                bv_seller_balance_log('pending_release_shortfall', [
                    'seller_id'     => $sellerId,
                    'order_item_id' => $orderItemId,
                    'source_amount' => $sourceAmount,
                    'pending_now'   => $pendingNow,
                    'shortfall'     => $shortfall,
                ]);
            }
            $releaseAmt = round($releaseAmt, 4);

            // Hard anti-negative guard (throws on programmer error).
            _bv_sb_guard_no_negative(
                $pendingNow, $releaseAmt,
                'release_one_row seller #' . $sellerId . ' item #' . $orderItemId
            );

            $meta = [
                'order_id'      => $orderId,
                'order_code'    => $orderCode,
                'source_amount' => $sourceAmount,
                'shortfall'     => $shortfall,
            ];

            // ── Insert debit ledger row — OLD key format ─────────────────────
            $debitLedgerId = _bv_sb_insert_ledger($pdo, [
                'seller_id'       => $sellerId,
                'type'            => 'pending_release',
                'balance_type'    => 'pending',
                'direction'       => 'debit',
                'amount'          => $releaseAmt,
                'currency'        => $currency,
                'balance_before'  => $pendingNow,
                'balance_after'   => round($pendingNow - $releaseAmt, 4),
                'reference_type'  => 'order_item',
                'reference_id'    => $orderItemId,
                'idempotency_key' => $debitKeyOld,
                'note'            => 'Pending released for order item #' . $orderItemId,
                'meta_json'       => $meta,
                'created_by_type' => 'system',
            ]);

            // ── Insert credit ledger row — OLD key format ────────────────────
            $creditLedgerId = _bv_sb_insert_ledger($pdo, [
                'seller_id'       => $sellerId,
                'type'            => 'pending_release',
                'balance_type'    => 'available',
                'direction'       => 'credit',
                'amount'          => $releaseAmt,
                'currency'        => $currency,
                'balance_before'  => $availableNow,
                'balance_after'   => round($availableNow + $releaseAmt, 4),
                'reference_type'  => 'order_item',
                'reference_id'    => $orderItemId,
                'idempotency_key' => $creditKeyOld,
                'note'            => 'Pending released for order item #' . $orderItemId,
                'meta_json'       => $meta,
                'created_by_type' => 'system',
            ]);

            // ── Update seller_balances snapshot ──────────────────────────────
            $pdo->prepare(
                'UPDATE seller_balances
                 SET pending_balance   = pending_balance   - ?,
                     available_balance = available_balance + ?
                 WHERE seller_id = ?'
            )->execute([$releaseAmt, $releaseAmt, $sellerId]);

            if ($ownTransaction) { $pdo->commit(); }

            // Re-read updated snapshot.
            $snapStmt = $pdo->prepare(
                'SELECT pending_balance, available_balance FROM seller_balances WHERE seller_id = ? LIMIT 1'
            );
            $snapStmt->execute([$sellerId]);
            $newSnap = $snapStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            bv_seller_balance_log('pending_release_completed', [
                'seller_id'       => $sellerId,
                'order_item_id'   => $orderItemId,
                'released_amount' => $releaseAmt,
                'shortfall'       => $shortfall,
            ]);

            $msg = $shortfall > 0
                ? 'Released ' . $releaseAmt . ' (shortfall ' . $shortfall . ').'
                : 'Released ' . $releaseAmt . '.';

            return [
                'ok'                     => true,
                'noop'                   => false,
                'seller_id'              => $sellerId,
                'order_item_id'          => $orderItemId,
                'source_amount'          => $sourceAmount,
                'actual_released_amount' => $releaseAmt,
                'shortfall'              => $shortfall,
                'debit_ledger_id'        => $debitLedgerId,
                'credit_ledger_id'       => $creditLedgerId,
                'balance'                => $newSnap,
                'message'                => $msg,
            ];

        } catch (Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) { $pdo->rollBack(); }
            bv_seller_balance_log('pending_release_failed', [
                'seller_id'     => $sellerId,
                'order_item_id' => $orderItemId,
                'error'         => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}

// ---------------------------------------------------------------------------
// Public: release pending balance for one specific order_item_id.
//
// Signature: (seller_id, order_item_id, currency='')
// Legacy single-arg callers: bv_seller_balance_release_pending_by_order_item($itemId)
//   are detected and handled automatically.
//
// Design: this wrapper is intentionally simple.
//   - It finds the raw earning/sale_pending ledger row directly from seller_ledger.
//   - No joins to orders/order_items. No fulfillment/status checks.
//   - No fee-subtraction here; the release helper reads actual pending_balance
//     with FOR UPDATE and caps the release there, so anti-negative is guaranteed.
//   - Fee deductions were already applied when the order was paid (platform_fee
//     debit to pending), so the live pending_balance already reflects net.
//
// Returns bool (backward-compatible):
//   true  — released successfully (actual_released_amount > 0)
//   false — row not found, already released (noop), or failure
// ---------------------------------------------------------------------------
if (!function_exists('bv_seller_balance_release_pending_by_order_item')) {
    function bv_seller_balance_release_pending_by_order_item(
        int    $sellerId    = 0,
        int    $orderItemId = 0,
        string $currency    = ''
    ): bool {
        // Legacy single-argument support:
        //   bv_seller_balance_release_pending_by_order_item($itemId)
        // PHP puts $itemId into $sellerId; $orderItemId stays 0.
        if ($sellerId > 0 && $orderItemId === 0) {
            $orderItemId = $sellerId;
            $sellerId    = 0;
        }

        if ($orderItemId <= 0) {
            bv_seller_balance_log('release_pending_wrapper_started', [
                'seller_id'     => $sellerId,
                'order_item_id' => $orderItemId,
                'currency'      => $currency,
                'error'         => 'invalid_order_item_id',
            ]);
            return false;
        }

        bv_seller_balance_log('release_pending_wrapper_started', [
            'seller_id'     => $sellerId,
            'order_item_id' => $orderItemId,
            'currency'      => $currency,
        ]);

        $pdo = bv_seller_balance_pdo();

        // ── Direct seller_ledger lookup ──────────────────────────────────────
        // Pure ledger query: no order/order_item joins, no eligibility gates.
        // seller_id and currency are optional narrow filters.
        $where  = [
            "type           IN ('earning', 'sale_pending')",
            "balance_type   = 'pending'",
            "direction      = 'credit'",
            "reference_type = 'order_item'",
            'reference_id   = ?',
        ];
        $params = [$orderItemId];

        if ($sellerId > 0) {
            $where[]  = 'seller_id = ?';
            $params[] = $sellerId;
        }
        if ($currency !== '') {
            $where[]  = 'currency = ?';
            $params[] = $currency;
        }

        $sql = 'SELECT seller_id,
                       reference_id AS order_item_id,
                       amount,
                       amount       AS net_amount,
                       amount       AS gross_amount,
                       0.0          AS fee_amount,
                       currency,
                       0            AS order_id,
                       \'\'         AS order_code
                FROM seller_ledger
                WHERE ' . implode("\n                  AND ", $where) . '
                ORDER BY id ASC
                LIMIT 1';

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            bv_seller_balance_log('release_pending_wrapper_no_row', [
                'seller_id'     => $sellerId,
                'order_item_id' => $orderItemId,
                'currency'      => $currency,
                'reason'        => 'query_error',
                'error'         => $e->getMessage(),
            ]);
            error_log('[seller_balance] release_pending_by_order_item query: ' . $e->getMessage());
            return false;
        }

        if (!$row || round((float)($row['amount'] ?? 0), 4) <= 0) {
            bv_seller_balance_log('release_pending_wrapper_no_row', [
                'seller_id'     => $sellerId,
                'order_item_id' => $orderItemId,
                'currency'      => $currency,
                'row_found'     => !empty($row),
                'amount'        => round((float)($row['amount'] ?? 0), 4),
                'debug'         => 'No pending earning/sale_pending credit row found in seller_ledger '
                                 . 'for reference_id=' . $orderItemId
                                 . ($sellerId > 0 ? ' seller_id=' . $sellerId : '')
                                 . ($currency !== '' ? ' currency=' . $currency : '') . '.',
            ]);
            return false;
        }

        bv_seller_balance_log('release_pending_wrapper_found_row', [
            'seller_id'     => (int)$row['seller_id'],
            'order_item_id' => $orderItemId,
            'currency'      => (string)$row['currency'],
            'amount'        => round((float)$row['amount'], 4),
        ]);

        // ── Delegate to public row-level release function ────────────────────
        // release_pending_for_row() → _bv_sb_release_one_row():
        //   - Checks both old+new idempotency key formats (noop if already done)
        //   - Locks seller_balances FOR UPDATE
        //   - Caps release at actual pending_balance (anti-negative)
        //   - Inserts paired ledger rows atomically
        //   - Returns detail array
        try {
            $result = bv_seller_balance_release_pending_for_row($row);
        } catch (Throwable $e) {
            bv_seller_balance_log('release_pending_wrapper_result', [
                'seller_id'     => (int)$row['seller_id'],
                'order_item_id' => $orderItemId,
                'ok'            => false,
                'error'         => $e->getMessage(),
            ]);
            error_log('[seller_balance] release_pending_by_order_item #' . $orderItemId . ': ' . $e->getMessage());
            return false;
        }

        $ok       = (bool)($result['ok']                     ?? false);
        $released = round((float)($result['actual_released_amount'] ?? 0), 4);
        $noop     = (bool)($result['noop']                   ?? false);

        bv_seller_balance_log('release_pending_wrapper_result', [
            'seller_id'              => $result['seller_id']       ?? (int)$row['seller_id'],
            'order_item_id'          => $orderItemId,
            'currency'               => (string)$row['currency'],
            'ok'                     => $ok,
            'noop'                   => $noop,
            'actual_released_amount' => $released,
            'shortfall'              => round((float)($result['shortfall'] ?? 0), 4),
            'debit_ledger_id'        => $result['debit_ledger_id']  ?? null,
            'credit_ledger_id'       => $result['credit_ledger_id'] ?? null,
            'message'                => $result['message']          ?? '',
        ]);

        return $ok && $released > 0;
    }
}

// ---------------------------------------------------------------------------
// Public: release all eligible pending rows for one seller.
// Returns a summary including actual released amounts and shortfalls.
// ---------------------------------------------------------------------------
if (!function_exists('bv_seller_balance_release_pending_for_seller')) {
    function bv_seller_balance_release_pending_for_seller(int $sellerId, string $currency = 'USD'): array
    {
        if ($sellerId <= 0) {
            return [
                'ok'              => false,
                'released_count'  => 0,
                'released_amount' => 0.0,
                'skipped_count'   => 0,
                'shortfall_count' => 0,
                'shortfall_amount'=> 0.0,
                'errors'          => ['Invalid seller_id'],
            ];
        }

        $pdo    = bv_seller_balance_pdo();
        $result = [
            'ok'               => true,
            'released_count'   => 0,
            'released_amount'  => 0.0,
            'skipped_count'    => 0,
            'shortfall_count'  => 0,
            'shortfall_amount' => 0.0,
            'errors'           => [],
        ];

        foreach (bv_seller_balance_find_releasable_ledger_rows($sellerId) as $row) {
            if ($currency !== '' && strcasecmp((string)($row['currency'] ?? ''), $currency) !== 0) {
                $result['skipped_count']++;
                continue;
            }
            $sourceAmount = round((float)($row['net_amount'] ?? 0), 4);
            if ($sourceAmount <= 0) {
                $result['skipped_count']++;
                continue;
            }
            try {
                // Call the public row function so the full call chain is consistent.
                $r = bv_seller_balance_release_pending_for_row($row);
                $released = (float)($r['actual_released_amount'] ?? 0);
                if ($r['noop'] ?? false) {
                    $result['skipped_count']++;
                } elseif (($r['ok'] ?? false) && $released > 0) {
                    $result['released_count']++;
                    $result['released_amount'] = round(
                        (float)$result['released_amount'] + $released, 4
                    );
                    $shortfall = (float)($r['shortfall'] ?? 0);
                    if ($shortfall > 0.00005) {
                        $result['shortfall_count']++;
                        $result['shortfall_amount'] = round(
                            (float)$result['shortfall_amount'] + $shortfall, 4
                        );
                    }
                } else {
                    $result['skipped_count']++;
                    if (!empty($r['message']) && !($r['ok'] ?? true)) {
                        $result['errors'][] = $r['message'];
                    }
                }
            } catch (Throwable $e) {
                $result['skipped_count']++;
                $result['errors'][] = $e->getMessage();
            }
        }
        return $result;
    }
}

// ---------------------------------------------------------------------------
// Public: release one pending ledger row to available.
//
// Returns an array result — a non-empty array is always truthy, so existing
// callers using `if (bv_seller_balance_release_pending_for_row($row))` still
// work correctly (an ok=false result is also a non-empty array and truthy, so
// callers that need to distinguish should check $result['ok'] and
// $result['actual_released_amount'] > 0).
//
// Return keys:
//   ok                    bool    — operation succeeded (incl. noop)
//   noop                  bool    — nothing changed (already released / no pending)
//   seller_id             int
//   order_item_id         int
//   source_amount         float   — net amount from ledger row
//   actual_released_amount float  — amount actually moved pending→available
//   shortfall             float   — source_amount − actual_released_amount
//   debit_ledger_id       int|null
//   credit_ledger_id      int|null
//   balance               array|null — updated seller_balances snapshot
//   message               string
// ---------------------------------------------------------------------------
if (!function_exists('bv_seller_balance_release_pending_for_row')) {
    function bv_seller_balance_release_pending_for_row(array $row): array
    {
        $sellerId    = (int)($row['seller_id']     ?? 0);
        $orderItemId = (int)($row['order_item_id'] ?? $row['reference_id'] ?? 0);
        $sourceAmt   = round((float)($row['net_amount'] ?? $row['amount'] ?? 0), 4);

        $invalid = [
            'ok'                     => false,
            'noop'                   => true,
            'seller_id'              => $sellerId,
            'order_item_id'          => $orderItemId,
            'source_amount'          => $sourceAmt,
            'actual_released_amount' => 0.0,
            'shortfall'              => 0.0,
            'debit_ledger_id'        => null,
            'credit_ledger_id'       => null,
            'balance'                => null,
            'message'                => 'Invalid input.',
        ];

        if ($sellerId <= 0 || $orderItemId <= 0 || $sourceAmt <= 0) {
            return $invalid;
        }

        $pdo = bv_seller_balance_pdo();
        try {
            return _bv_sb_release_one_row($pdo, $row);
        } catch (Throwable $e) {
            error_log('[seller_balance] release_pending_for_row: ' . $e->getMessage());
            return [
                'ok'                     => false,
                'noop'                   => false,
                'seller_id'              => $sellerId,
                'order_item_id'          => $orderItemId,
                'source_amount'          => $sourceAmt,
                'actual_released_amount' => 0.0,
                'shortfall'              => 0.0,
                'debit_ledger_id'        => null,
                'credit_ledger_id'       => null,
                'balance'                => null,
                'message'                => $e->getMessage(),
            ];
        }
    }
}

if (!function_exists('bv_seller_balance_release_pending')) {
    function bv_seller_balance_release_pending(
        int $sellerId,
        float $amount,
        string $referenceNote = '',
        string $idempotencyKey = '',
        int $adminId = 0
    ): bool {
        if ($sellerId <= 0 || $amount <= 0) {
            return false;
        }
        $pdo = bv_seller_balance_pdo();
        $amount = round($amount, 4);
        $debitKey = ($idempotencyKey ?: 'admin_pending_release:' . $sellerId . ':' . sha1($referenceNote . ':' . $amount)) . ':debit';
        $creditKey = ($idempotencyKey ?: 'admin_pending_release:' . $sellerId . ':' . sha1($referenceNote . ':' . $amount)) . ':credit';

        try {
            $pdo->beginTransaction();
            if (_bv_sb_ledger_exists($pdo, $debitKey) || _bv_sb_ledger_exists($pdo, $creditKey)) {
                $pdo->rollBack();
                return true;
            }
            $balRow = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE');
            $balRow->execute([$sellerId]);
            $balance = $balRow->fetch(PDO::FETCH_ASSOC);
            if (!$balance) {
                $pdo->rollBack();
                return false;
            }
            $pendingNow = round((float)$balance['pending_balance'], 4);
            $availableNow = round((float)$balance['available_balance'], 4);
            $releaseAmt = round(min($amount, $pendingNow), 4);
            if ($releaseAmt <= 0) {
                $pdo->rollBack();
                return false;
            }
            $currency = (string)($balance['currency'] ?? 'USD');
            // Anti-negative guard.
            _bv_sb_guard_no_negative($pendingNow, $releaseAmt, 'admin_pending_release seller #' . $sellerId);
            _bv_sb_insert_ledger_once($pdo, [
                'seller_id' => $sellerId,
                'type' => 'pending_release',
                'balance_type' => 'pending',
                'direction' => 'debit',
                'amount' => $releaseAmt,
                'currency' => $currency,
                'balance_before' => $pendingNow,
                'balance_after' => round($pendingNow - $releaseAmt, 4),
                'idempotency_key' => $debitKey,
                'note' => $referenceNote ?: 'Pending balance released to available',
                'created_by_type' => $adminId > 0 ? 'admin' : 'system',
                'created_by_id' => $adminId ?: null,
            ]);
            _bv_sb_insert_ledger_once($pdo, [
                'seller_id' => $sellerId,
                'type' => 'pending_release',
                'balance_type' => 'available',
                'direction' => 'credit',
                'amount' => $releaseAmt,
                'currency' => $currency,
                'balance_before' => $availableNow,
                'balance_after' => round($availableNow + $releaseAmt, 4),
                'idempotency_key' => $creditKey,
                'note' => $referenceNote ?: 'Pending balance released to available',
                'created_by_type' => $adminId > 0 ? 'admin' : 'system',
                'created_by_id' => $adminId ?: null,
            ]);
            $pdo->prepare(
                'UPDATE seller_balances
                SET pending_balance = pending_balance - :debit_amt,
                     available_balance = available_balance + :credit_amt 
                 WHERE seller_id = :sid'
            )->execute([
                ':debit_amt' => $releaseAmt,
                ':credit_amt' => $releaseAmt,
                ':sid' => $sellerId,
            ]);  
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[seller_balance] release_pending failed for seller #' . $sellerId . ': ' . $e->getMessage());
            return false;
        }
    }
}
// ---------------------------------------------------------------------------
// PAYOUT REQUEST — SELLER SUBMITS
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_request_payout')) {
    /**
     * Seller requests a payout from available_balance.
     * Moves amount: available_balance → held_balance atomically.
     *
     * Returns a structured array on success; throws RuntimeException on failure.
     * The array is truthy, so existing callers using if (result) still work.
     * Callers needing the payout_request_id should use $result['payout_request_id'].
     *
     * Ledger types written:
     *   payout_hold_debit   (available, debit)  idempotency: payout_hold_debit:{id}
     *   payout_hold_credit  (held, credit)       idempotency: payout_hold_credit:{id}
     */
    function bv_seller_balance_request_payout(int $sellerId, float $amount, array $details = []): array
    {
        if ($sellerId <= 0 || $amount <= 0) {
            throw new RuntimeException('Invalid seller_id or amount for payout request.');
        }

        $minAmount = (float)bv_seller_balance_get_setting('payout_min_amount', '10.00');
        if ($amount < $minAmount) {
            throw new RuntimeException('Payout amount must be at least ' . number_format($minAmount, 2));
        }
        if (bv_seller_balance_get_setting('payout_enabled', '1') !== '1') {
            throw new RuntimeException('Payout requests are temporarily disabled.');
        }

        $method = (string)($details['payout_method'] ?? '');
        $allowedMethods = ['promptpay', 'bank_transfer', 'wise', 'other'];
        if (!in_array($method, $allowedMethods, true)) {
            throw new RuntimeException('Invalid payout method.');
        }

        $bankName          = trim((string)($details['bank_name']           ?? ''));
        $bankAccountNumber = trim((string)($details['bank_account_number'] ?? ''));
        $bankAccountName   = trim((string)($details['bank_account_name']   ?? ''));
        $promptpay         = preg_replace('/\D+/', '', (string)($details['promptpay_number'] ?? ''));
        $sellerNote        = trim((string)($details['seller_note']          ?? ''));

        if ($method === 'promptpay' && !in_array(strlen($promptpay), [10, 13], true)) {
            throw new RuntimeException('PromptPay number must contain 10 or 13 digits.');
        }
        if ($method === 'bank_transfer') {
            if ($bankName === '' || $bankAccountNumber === '' || $bankAccountName === '') {
                throw new RuntimeException('Bank name, account number, and account name are required for bank transfer.');
            }
        }
        if ($method === 'wise') {
            if ($sellerNote === '' && trim((string)($details['wise_email'] ?? '')) === '') {
                throw new RuntimeException('Wise payout requires a note or email for destination details.');
            }
        }

        bv_seller_balance_log('payout_request_started', [
            'seller_id' => $sellerId,
            'amount'    => $amount,
            'method'    => $method,
        ]);

        $pdo = bv_seller_balance_pdo();
        try {
            $pdo->beginTransaction();

            // Lock seller_balances row.
            $balRow = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE');
            $balRow->execute([$sellerId]);
            $balance = $balRow->fetch(PDO::FETCH_ASSOC);
            if (!$balance) {
                $pdo->rollBack();
                throw new RuntimeException('No balance record found for seller #' . $sellerId . '.');
            }

            $currency        = (string)($balance['currency'] ?? 'USD');
            $requestCurrency = strtoupper(trim((string)($details['currency'] ?? $currency)));
            if ($requestCurrency !== '' && strtoupper($currency) !== $requestCurrency) {
                $pdo->rollBack();
                throw new RuntimeException('Currency mismatch for payout request.');
            }

            $availableNow = round((float)($balance['available_balance'] ?? 0), 4);
            $heldNow      = round((float)($balance['held_balance']      ?? 0), 4);
            $requestAmt   = round($amount, 4);

            // Sufficient available balance check.
            if ($requestAmt > $availableNow) {
                $pdo->rollBack();
                bv_seller_balance_log('payout_request_insufficient_balance', [
                    'seller_id'     => $sellerId,
                    'requested'     => $requestAmt,
                    'available_now' => $availableNow,
                ]);
                throw new RuntimeException(
                    'Requested amount exceeds available balance. Available: ' . number_format($availableNow, 2)
                );
            }

            // Anti-negative guard.
            _bv_sb_guard_no_negative($availableNow, $requestAmt, 'payout_request seller #' . $sellerId);

            // One open payout at a time.
            $openStmt = $pdo->prepare(
                "SELECT id FROM seller_payout_requests
                 WHERE seller_id = ? AND status IN ('requested','approved')
                 LIMIT 1 FOR UPDATE"
            );
            $openStmt->execute([$sellerId]);
            if ($openStmt->fetchColumn()) {
                $pdo->rollBack();
                throw new RuntimeException('You already have an open payout request. Please wait for it to be processed.');
            }

            // Insert payout request row.
            $pdo->prepare(
                'INSERT INTO seller_payout_requests
                 (seller_id, amount, currency, status, bank_name, bank_account_number,
                  bank_account_name, promptpay_number, payout_method, seller_note, requested_at)
                 VALUES
                 (:sid, :amt, :cur, :status, :bank, :acct, :acct_name, :pp, :method, :note, NOW())'
            )->execute([
                ':sid'       => $sellerId,
                ':amt'       => $requestAmt,
                ':cur'       => $currency,
                ':status'    => 'requested',
                ':bank'      => $method === 'bank_transfer' ? $bankName : null,
                ':acct'      => $method === 'bank_transfer' ? $bankAccountNumber : null,
                ':acct_name' => $method === 'bank_transfer' ? $bankAccountName : null,
                ':pp'        => $method === 'promptpay' ? $promptpay : null,
                ':method'    => $method,
                ':note'      => $sellerNote,
            ]);
            $payoutRequestId = (int)$pdo->lastInsertId();

            $metaBase = _bv_sb_request_context_meta() + [
                'action'             => 'payout_request',
                'payout_request_id'  => $payoutRequestId,
                'seller_id'          => $sellerId,
                'old_status'         => null,
                'new_status'         => 'requested',
                'payment_method'     => $method,
                'payment_reference'  => null,
            ];

            // Ledger: debit available (payout_hold_debit).
            $debitKey = 'payout_hold_debit:' . $payoutRequestId;
            $debitLedgerId = _bv_sb_insert_ledger_once($pdo, [
                'seller_id'       => $sellerId,
                'type'            => 'payout_hold_debit',
                'balance_type'    => 'available',
                'direction'       => 'debit',
                'amount'          => $requestAmt,
                'currency'        => $currency,
                'balance_before'  => $availableNow,
                'balance_after'   => round($availableNow - $requestAmt, 4),
                'reference_type'  => 'payout_request',
                'reference_id'    => $payoutRequestId,
                'idempotency_key' => $debitKey,
                'note'            => 'Payout request #' . $payoutRequestId . ' — hold debit',
                'meta_json'       => $metaBase,
                'created_by_type' => $details['created_by_type'] ?? 'seller',
                'created_by_id'   => $details['created_by_id']   ?? $sellerId,
            ]);

            // Ledger: credit held (payout_hold_credit).
            $creditKey = 'payout_hold_credit:' . $payoutRequestId;
            $creditLedgerId = _bv_sb_insert_ledger_once($pdo, [
                'seller_id'       => $sellerId,
                'type'            => 'payout_hold_credit',
                'balance_type'    => 'held',
                'direction'       => 'credit',
                'amount'          => $requestAmt,
                'currency'        => $currency,
                'balance_before'  => $heldNow,
                'balance_after'   => round($heldNow + $requestAmt, 4),
                'reference_type'  => 'payout_request',
                'reference_id'    => $payoutRequestId,
                'idempotency_key' => $creditKey,
                'note'            => 'Payout request #' . $payoutRequestId . ' — hold credit',
                'meta_json'       => $metaBase,
                'created_by_type' => $details['created_by_type'] ?? 'seller',
                'created_by_id'   => $details['created_by_id']   ?? $sellerId,
            ]);

            // Update snapshot.
            $pdo->prepare(
                'UPDATE seller_balances
                 SET available_balance = available_balance - :amt,
                     held_balance      = held_balance      + :amt
                 WHERE seller_id = :sid'
            )->execute([':amt' => $requestAmt, ':sid' => $sellerId]);

            $pdo->commit();

            // Re-read updated snapshot.
            $snapStmt = $pdo->prepare(
                'SELECT available_balance, held_balance FROM seller_balances WHERE seller_id = ? LIMIT 1'
            );
            $snapStmt->execute([$sellerId]);
            $newSnap = $snapStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            bv_seller_balance_log('payout_request_created', [
                'seller_id'         => $sellerId,
                'payout_request_id' => $payoutRequestId,
                'amount'            => $requestAmt,
                'currency'          => $currency,
                'method'            => $method,
                'debit_ledger_id'   => $debitLedgerId,
                'credit_ledger_id'  => $creditLedgerId,
            ]);

            return [
                'ok'                => true,
                'payout_request_id' => $payoutRequestId,
                'amount'            => $requestAmt,
                'currency'          => $currency,
                'debit_ledger_id'   => $debitLedgerId,
                'credit_ledger_id'  => $creditLedgerId,
                'balance'           => $newSnap,
            ];

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            bv_seller_balance_log('payout_request_failed', [
                'seller_id' => $sellerId,
                'amount'    => $amount,
                'error'     => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
// ---------------------------------------------------------------------------
// PAYOUT — ADMIN: APPROVE
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_approve_payout')) {
    function bv_seller_balance_approve_payout(int $payoutId, int $adminId, string $note = ''): array|false
    {
        if ($payoutId <= 0) {
            return false;
        }
        bv_seller_balance_log('payout_approve_started', [
            'payout_id' => $payoutId,
            'admin_id'  => $adminId,
        ]);
        $pdo = bv_seller_balance_pdo();
        try {
            $pdo->beginTransaction();
			
            $reqStmt = $pdo->prepare('SELECT * FROM seller_payout_requests WHERE id = ? LIMIT 1 FOR UPDATE');
            $reqStmt->execute([$payoutId]);
            $req = $reqStmt->fetch(PDO::FETCH_ASSOC);
            if (!$req) {
                $pdo->rollBack();
                return false;
            }
            $currentStatus = strtolower((string)$req['status']);
            if (in_array($currentStatus, ['approved', 'paid', 'cancelled', 'canceled', 'rejected', 'failed'], true)) {
                _bv_sb_log_payout_status_locked($payoutId, $adminId, 'approve', $currentStatus);
                $pdo->commit();
                return _bv_sb_payout_processed_noop($payoutId, $currentStatus);
            }
            if (!in_array($currentStatus, ['requested', 'pending'], true)) { 
                $pdo->rollBack();
                return false;
            }		

            $sellerId = (int)$req['seller_id'];
            $amount   = round((float)$req['amount'], 4);
            $currency = (string)$req['currency'];
            if ($sellerId <= 0 || $amount <= 0 || $currency === '') {
                $pdo->rollBack();
                return false;
            }
			
            $balRow = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE');
            $balRow->execute([$sellerId]);
            $balSnap = $balRow->fetch(PDO::FETCH_ASSOC);
            if (!$balSnap) {
                $pdo->rollBack();
                return false;
            }
			

            $availableNow = round((float)($balSnap['available_balance'] ?? 0), 4);
            $heldNow      = round((float)($balSnap['held_balance']      ?? 0), 4);
            if ($amount > $availableNow) {
                $pdo->rollBack();
                bv_seller_balance_log('payout_approve_insufficient_balance', [
                    'payout_id'     => $payoutId,
                    'seller_id'     => $sellerId,
                    'amount'        => $amount,
                    'available_now' => $availableNow, 
                ]);
              return false;
            }
            _bv_sb_guard_no_negative($availableNow, $amount, 'payout_approve seller #' . $sellerId);

            $availableKey = 'payout_request:' . $payoutId . ':available_debit';
            $heldKey      = 'payout_request:' . $payoutId . ':held_credit';
            $availableExists = _bv_sb_ledger_exists($pdo, $availableKey);
            $heldExists      = _bv_sb_ledger_exists($pdo, $heldKey);
            if ($availableExists || $heldExists) {
                $pdo->rollBack();
               bv_seller_balance_log('payout_ledger_inconsistent', [
                    'payout_id'        => $payoutId,
                    'available_exists' => $availableExists,
                    'held_exists'      => $heldExists,
                    'context'          => 'approve',
                ]);				
                return false;
            }

           $metaBase = _bv_sb_request_context_meta() + [
                'action'            => 'payout_approve',
                'payout_request_id' => $payoutId,
                'admin_id'          => $adminId,
                'old_status'        => $currentStatus,
                'new_status'        => 'approved',
                'payment_method'    => (string)($req['payout_method'] ?? ''),
                'payment_reference' => null,
            ];

            $debitLedgerId = _bv_sb_insert_ledger_once($pdo, [
                'seller_id'       => $sellerId,
                'type'            => 'payout_request',
                'balance_type'    => 'available',
                'direction'       => 'debit',
                'amount'          => $amount,
                'currency'        => $currency,
                'balance_before'  => $availableNow,
                'balance_after'   => round($availableNow - $amount, 4),
                'reference_type'  => 'payout_request',
                'reference_id'    => $payoutId,
                'idempotency_key' => $availableKey,
                'note'            => 'Payout request #' . $payoutId . ' approved — available debit',
                'meta_json'       => $metaBase,
                'created_by_type' => 'admin',
                'created_by_id'   => $adminId,
            ]);
            $creditLedgerId = _bv_sb_insert_ledger_once($pdo, [
                'seller_id'       => $sellerId,
                'type'            => 'payout_request',
                'balance_type'    => 'held',
                'direction'       => 'credit',
                'amount'          => $amount,
                'currency'        => $currency,
                'balance_before'  => $heldNow,
                'balance_after'   => round($heldNow + $amount, 4),
                'reference_type'  => 'payout_request',
                'reference_id'    => $payoutId,
                'idempotency_key' => $heldKey,
                'note'            => 'Payout request #' . $payoutId . ' approved — held credit',
                'meta_json'       => $metaBase,
                'created_by_type' => 'admin',
                'created_by_id'   => $adminId,
            ]);

            $pdo->prepare(
                'UPDATE seller_balances
                 SET available_balance = available_balance - :available_amount,
                     held_balance      = held_balance      + :held_amount
                 WHERE seller_id = :seller_id'
            )->execute([
                ':available_amount' => $amount,
                ':held_amount'      => $amount,
                ':seller_id'        => $sellerId,
            ]);

            $setParts  = ["status = 'approved'"];
            $updParams = [':id' => $payoutId];
            if (_bv_sb_column_exists($pdo, 'seller_payout_requests', 'approved_at')) {
              $setParts[] = 'approved_at = NOW()';
            }
            if (_bv_sb_column_exists($pdo, 'seller_payout_requests', 'approved_by')) {
                 $setParts[] = 'approved_by = :approved_by_admin_id';
                $updParams[':approved_by_admin_id'] = $adminId;
            }
            if (_bv_sb_column_exists($pdo, 'seller_payout_requests', 'admin_id')) {
                $setParts[] = 'admin_id = :request_admin_id';
                $updParams[':request_admin_id'] = $adminId;
            }
            if (_bv_sb_column_exists($pdo, 'seller_payout_requests', 'admin_note')) {
                $setParts[] = "admin_note = CONCAT(COALESCE(admin_note,''), :admin_note)";
                $updParams[':admin_note'] = $note !== '' ? "\n[Approved] " . $note : '';
            }

            $pdo->prepare(
                'UPDATE seller_payout_requests SET ' . implode(', ', $setParts) . " WHERE id = :id AND status IN ('requested','pending')" 
            )->execute($updParams);
			
            $pdo->commit();
            $snapStmt = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1');
            $snapStmt->execute([$sellerId]);
            $balSnap = $snapStmt->fetch(PDO::FETCH_ASSOC) ?: $balSnap;
           bv_seller_balance_log('payout_action_completed', [
                'action'     => 'approve',
                'payout_id'  => $payoutId,
                'admin_id'   => $adminId,
                'seller_id'  => $sellerId,
                'amount'     => $amount,				
                'old_status' => $currentStatus,
                'new_status' => 'approved',
            ]);
            bv_seller_balance_log('payout_approve_completed', [
                'payout_id'  => $payoutId,
                'admin_id'   => $adminId,
                'seller_id'  => $sellerId,
                'amount'     => $amount,
                'old_status' => $currentStatus,
                'new_status' => 'approved',
            ]);
            return ['ok' => true, 'noop' => false, 'payout_request_id' => $payoutId,
                    'status' => 'approved', 'debit_ledger_id' => $debitLedgerId,
                    'credit_ledger_id' => $creditLedgerId, 'balance' => $balSnap];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            bv_seller_balance_log('payout_failed', ['context' => 'approve_payout',
                'payout_id' => $payoutId, 'error' => $e->getMessage()]);
            error_log('[seller_balance] approve_payout #' . $payoutId . ': ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('bv_seller_balance_reject_payout')) {
    /**
     * Reject a payout request and return held funds → available.
     * Ledger types: payout_reject_held_debit / payout_reject_available_credit
     * Checks both new and legacy idempotency key formats.
     */
    function bv_seller_balance_reject_payout(int $payoutId, int $adminId, string $note = ''): bool
    {
        if ($payoutId <= 0) {
            return false;
        }
        bv_seller_balance_log('payout_reject_started', [
            'payout_id' => $payoutId,
            'admin_id'  => $adminId,
        ]);
        $pdo = bv_seller_balance_pdo();
        try {
            $pdo->beginTransaction();
            $reqStmt = $pdo->prepare('SELECT * FROM seller_payout_requests WHERE id = ? LIMIT 1 FOR UPDATE');
            $reqStmt->execute([$payoutId]);
            $req = $reqStmt->fetch(PDO::FETCH_ASSOC);
            if (!$req) {
                $pdo->rollBack();
                return false;
            }
            $currentStatus = strtolower((string)$req['status']);
            if ($currentStatus === 'rejected') {
                $pdo->commit();
                return true; // idempotent
            }
            if (!in_array($currentStatus, ['requested', 'pending', 'approved'], true)) {
                $pdo->rollBack();
                return false;
            }

            $sellerId     = (int)$req['seller_id'];
            $amount       = round((float)$req['amount'], 4);
            $currency     = (string)$req['currency'];

            if (in_array($currentStatus, ['requested', 'pending'], true)) {
                // Requested/pending payouts have not moved money from available -> held yet.
                // Rejecting them must only change the request status; never reverse held balance.
                $set = ["status = 'rejected'", "admin_note = CONCAT(COALESCE(admin_note,''), :note)"];
                $params = [
                    ':note' => "
[Rejected] " . ($note ?: 'No reason given'),
                    ':id'   => $payoutId,
                ];
                if (_bv_sb_column_exists($pdo, 'seller_payout_requests', 'rejected_at')) {
                    $set[] = 'rejected_at = COALESCE(rejected_at, NOW())';
                }
                if (_bv_sb_column_exists($pdo, 'seller_payout_requests', 'rejected_by')) {
                    $set[] = 'rejected_by = :admin_id';
                    $params[':admin_id'] = $adminId;
                } elseif (_bv_sb_column_exists($pdo, 'seller_payout_requests', 'admin_id')) {
                    $set[] = 'admin_id = :admin_id';
                    $params[':admin_id'] = $adminId;
                }

                $sql = 'UPDATE seller_payout_requests SET ' . implode(', ', $set) . ' WHERE id = :id';
                $pdo->prepare($sql)->execute($params);
                $pdo->commit();

                bv_seller_balance_log('payout_reject_pending_status_only', [
                    'payout_id'  => $payoutId,
                    'admin_id'   => $adminId,
                    'seller_id'  => $sellerId,
                    'amount'     => $amount,
                    'old_status' => $currentStatus,
                    'new_status' => 'rejected',
                ]);
                bv_seller_balance_log('payout_reject_completed', [
                    'payout_id'  => $payoutId,
                    'admin_id'   => $adminId,
                    'seller_id'  => $sellerId,
                    'amount'     => $amount,
                    'status_only'=> true,
                ]);
                return true;
            }

            // New canonical idempotency keys.
            $heldKeyNew      = 'payout_reject_held_debit:'       . $payoutId;
            $availableKeyNew = 'payout_reject_available_credit:' . $payoutId;
            // Legacy keys (from earlier patch revision).
            $heldKeyOld      = 'payout_reject_held:'      . $payoutId;
            $availableKeyOld = 'payout_reject_available:' . $payoutId;

            $balRow = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE');
            $balRow->execute([$sellerId]);
            $balance = $balRow->fetch(PDO::FETCH_ASSOC);
            if (!$balance) {
                $pdo->rollBack();
                return false;
            }

            $heldNow      = round((float)($balance['held_balance']      ?? 0), 4);
            $availableNow = round((float)($balance['available_balance'] ?? 0), 4);

            if ($amount > $heldNow) {
                $pdo->rollBack();
                throw new RuntimeException('Held balance is lower than payout amount; cannot reject safely.');
            }

            $metaBase = _bv_sb_request_context_meta() + [
                'action'            => 'payout_reject',
                'payout_request_id' => $payoutId,
                'admin_id'          => $adminId,
                'old_status'        => (string)$req['status'],
                'new_status'        => 'rejected',
                'payment_method'    => (string)($req['payout_method'] ?? ''),
                'payment_reference' => null,
            ];

            if ($amount > 0) {
                $heldExists      = _bv_sb_ledger_exists($pdo, $heldKeyNew)
                                || _bv_sb_ledger_exists($pdo, $heldKeyOld);
                $availableExists = _bv_sb_ledger_exists($pdo, $availableKeyNew)
                                || _bv_sb_ledger_exists($pdo, $availableKeyOld);

                if ($heldExists && $availableExists) {
                    // Both reversal rows already written — noop on ledger/balance.
                } elseif ($heldExists xor $availableExists) {
                    $pdo->rollBack();
                    bv_seller_balance_log('payout_ledger_inconsistent', [
                        'payout_id'       => $payoutId,
                        'held_exists'     => $heldExists,
                        'available_exists'=> $availableExists,
                        'context'         => 'reject',
                    ]);
                    bv_seller_balance_log('payout_inconsistent_ledger', [
                        'payout_id' => $payoutId, 'context' => 'reject']);
                    throw new RuntimeException(
                        '[seller_balance] Payout ledger inconsistency on reject #' . $payoutId
                        . ': exactly one of held/available reversal entries exists. Manual review required.'
                    );
                } else {
                    // Neither exists — insert both.
                    _bv_sb_guard_no_negative($heldNow, $amount, 'payout_reject held seller #' . $sellerId);

                    _bv_sb_insert_ledger_once($pdo, [
                        'seller_id'       => $sellerId,
                        'type'            => 'payout_reject',
                        'balance_type'    => 'held',
                        'direction'       => 'debit',
                        'amount'          => $amount,
                        'currency'        => $currency,
                        'balance_before'  => $heldNow,
                        'balance_after'   => round($heldNow - $amount, 4),
                        'reference_type'  => 'payout_request',
                        'reference_id'    => $payoutId,
                        'idempotency_key' => $heldKeyNew,
                        'note'            => 'Payout #' . $payoutId . ' rejected — returned to available',
                        'meta_json'       => $metaBase,
                        'created_by_type' => 'admin',
                        'created_by_id'   => $adminId,
                    ]);
                    _bv_sb_insert_ledger_once($pdo, [
                        'seller_id'       => $sellerId,
                        'type'            => 'payout_reject',
                        'balance_type'    => 'available',
                        'direction'       => 'credit',
                        'amount'          => $amount,
                        'currency'        => $currency,
                        'balance_before'  => $availableNow,
                        'balance_after'   => round($availableNow + $amount, 4),
                        'reference_type'  => 'payout_request',
                        'reference_id'    => $payoutId,
                        'idempotency_key' => $availableKeyNew,
                        'note'            => 'Payout #' . $payoutId . ' rejected — returned to available',
                        'meta_json'       => $metaBase,
                        'created_by_type' => 'admin',
                        'created_by_id'   => $adminId,
                    ]);
                    $pdo->prepare(
                        'UPDATE seller_balances
                         SET held_balance      = held_balance      - :amt,
                             available_balance = available_balance + :amt
                         WHERE seller_id = :sid'
                    )->execute([':amt' => $amount, ':sid' => $sellerId]);
                }
            }

            $set = ["status = 'rejected'", "admin_note = CONCAT(COALESCE(admin_note,''), :note)"];
            $params = [
                ':note' => "
[Rejected] " . ($note ?: 'No reason given'),
                ':id'   => $payoutId,
            ];			
            if (_bv_sb_column_exists($pdo, 'seller_payout_requests', 'rejected_at')) {
                $set[] = 'rejected_at = COALESCE(rejected_at, NOW())';
            }
            if (_bv_sb_column_exists($pdo, 'seller_payout_requests', 'rejected_by')) {
                $set[] = 'rejected_by = :admin_id';
               $params[':admin_id'] = $adminId;				
            } elseif (_bv_sb_column_exists($pdo, 'seller_payout_requests', 'admin_id')) {
                $set[] = 'admin_id = :admin_id';
               $params[':admin_id'] = $adminId;				
            }

            $sql = 'UPDATE seller_payout_requests SET ' . implode(', ', $set) . ' WHERE id = :id';
           $pdo->prepare($sql)->execute($params); 

            $pdo->commit();
            bv_seller_balance_log('payout_reject_completed', [
                'payout_id'  => $payoutId,
                'admin_id'   => $adminId,
                'seller_id'  => $sellerId,
                'amount'     => $amount,
            ]);
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            bv_seller_balance_log('payout_failed', ['context' => 'reject_payout',
                'payout_id' => $payoutId, 'error' => $e->getMessage()]);
            error_log('[seller_balance] reject_payout #' . $payoutId . ': ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('bv_seller_balance_mark_payout_paid')) {
    function bv_seller_balance_mark_payout_paid(
        int $payoutId,
        int $adminId,
        string $paymentReference = '',
        string $paymentMethod = 'bank_transfer',
        string $note = ''
    ): array|false {
        if ($payoutId <= 0) {
            return false;
        }
		
		
        $pdo = bv_seller_balance_pdo();
        try {
            $pdo->beginTransaction();

            $reqStmt = $pdo->prepare(
                'SELECT id, seller_id, amount, currency, status
                 FROM seller_payout_requests
                 WHERE id = :request_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $reqStmt->execute([':request_id' => $payoutId]);
            $req = $reqStmt->fetch(PDO::FETCH_ASSOC);
           if (!$req) {
                $pdo->rollBack();
                return false;
            }
            $currentStatus = strtolower((string)$req['status']);
            if (in_array($currentStatus, ['paid', 'cancelled', 'canceled', 'rejected', 'failed'], true)) {
                _bv_sb_log_payout_status_locked($payoutId, $adminId, 'mark_paid', $currentStatus);
                $pdo->commit();
                return _bv_sb_payout_processed_noop($payoutId, $currentStatus);
            }
            if ($currentStatus !== 'approved') { 
                $pdo->rollBack();
                return false;
            }

             $sellerId = (int)$req['seller_id'];
            $amount   = round((float)$req['amount'], 4);
            $currency = (string)$req['currency'];
            if ($sellerId <= 0 || $amount <= 0) {
                throw new RuntimeException('Invalid payout request amount or seller.');
            }

           $balanceStmt = $pdo->prepare(
                'SELECT *
                 FROM seller_balances
                 WHERE seller_id = :seller_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $balanceStmt->execute([':seller_id' => $sellerId]);
            $balance = $balanceStmt->fetch(PDO::FETCH_ASSOC);
            if (!$balance) {
                throw new RuntimeException('Seller balance row not found.');
            }

            $heldNow    = round((float)($balance['held_balance'] ?? 0), 4);
            $paidOutNow = round((float)($balance['paid_out_balance'] ?? 0), 4);
         if (round($heldNow - $amount, 4) < -0.00005) {
                throw new RuntimeException('Held balance is lower than payout amount; cannot mark paid safely.');
            }
			
            $paymentReference = trim($paymentReference);
            $paymentMethod = trim($paymentMethod);
            $note = trim($note);
			

            $metaBase = _bv_sb_request_context_meta() + [
                'action'            => 'payout_paid',
                'payout_request_id' => $payoutId,
                'admin_id'          => $adminId,
               'old_status'        => $currentStatus,
                'new_status'        => 'paid',
                'payment_method'    => $paymentMethod,
                'payment_reference' => $paymentReference,
            ];

            bv_seller_balance_log('payout_paid_started', [
                'payout_id' => $payoutId,
                'admin_id'  => $adminId,
               'seller_id' => $sellerId,				
                'amount'    => $amount,
            ]);

           $heldKey = 'payout_paid:' . $payoutId . ':held_debit';
            $paidOutKey = 'payout_paid:' . $payoutId . ':paid_out_credit'; 

            $debitLedgerId = _bv_sb_insert_ledger_once($pdo, [
                'seller_id'       => $sellerId,
                'type'            => 'payout_paid',
                'balance_type'    => 'held',
                'direction'       => 'debit',
                'amount'          => $amount,
                'currency'        => $currency,
                'balance_before'  => $heldNow,
                'balance_after'   => round($heldNow - $amount, 4),
                'reference_type'  => 'payout_request',
                'reference_id'    => $payoutId,
                'idempotency_key' => $heldKey,
                'note'            => 'Payout #' . $payoutId . ' paid - held debit',
                'meta_json'       => $metaBase,
                'created_by_type' => 'admin',
                'created_by_id'   => $adminId,
            ]);
            $creditLedgerId = _bv_sb_insert_ledger_once($pdo, [
                'seller_id'       => $sellerId,
                'type'            => 'payout_paid',
                'balance_type'    => 'paid_out',
                'direction'       => 'credit',
                'amount'          => $amount,
                'currency'        => $currency,
                'balance_before'  => $paidOutNow,
                'balance_after'   => round($paidOutNow + $amount, 4),
                'reference_type'  => 'payout_request',
                'reference_id'    => $payoutId,
                'idempotency_key' => $paidOutKey,
                'note'            => 'Payout #' . $payoutId . ' paid - paid_out credit',
                'meta_json'       => $metaBase,
                'created_by_type' => 'admin',
                'created_by_id'   => $adminId,
            ]);

           $pdo->prepare(
                'UPDATE seller_balances
                 SET held_balance = held_balance - :held_amount,
                     paid_out_balance = paid_out_balance + :paid_amount
                 WHERE seller_id = :balance_seller_id'
            )->execute([
                ':held_amount'      => $amount,
                ':paid_amount'      => $amount,
                ':balance_seller_id'=> $sellerId,
            ]);

            $setParts = ["status = 'paid'"];
            $updParams = [':request_update_id' => $payoutId];

            if (_bv_sb_column_exists($pdo, 'seller_payout_requests', 'paid_at')) {
                $setParts[] = 'paid_at = NOW()';
            }
            if (_bv_sb_column_exists($pdo, 'seller_payout_requests', 'payment_reference')) {
               $setParts[] = 'payment_reference = :payment_reference';
                $updParams[':payment_reference'] = $paymentReference;
            }

            if ($paymentMethod !== '' && _bv_sb_column_exists($pdo, 'seller_payout_requests', 'payout_method')) {
                $setParts[] = 'payout_method = :paid_payout_method';
                $updParams[':paid_payout_method'] = $paymentMethod;
            }

           if (_bv_sb_column_exists($pdo, 'seller_payout_requests', 'admin_note') && $note !== '') {
                $setParts[] = "admin_note = CONCAT(COALESCE(admin_note,''), :paid_admin_note)";
                $updParams[':paid_admin_note'] = "\n[Paid] " . $note;
            }

            if ($adminId > 0 && _bv_sb_column_exists($pdo, 'seller_payout_requests', 'admin_id')) {
                $setParts[] = 'admin_id = :paid_admin_id';
                $updParams[':paid_admin_id'] = $adminId;
            }
            if ($adminId > 0 && _bv_sb_column_exists($pdo, 'seller_payout_requests', 'paid_by')) {
                $setParts[] = 'paid_by = :paid_by_admin_id';
                $updParams[':paid_by_admin_id'] = $adminId; 
            }


            $pdo->prepare(
                'UPDATE seller_payout_requests
                 SET ' . implode(', ', $setParts) . "
                 WHERE id = :request_update_id AND status = 'approved'"
            )->execute($updParams);

 
            $pdo->commit();

            $snapStmt = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = :snap_seller_id LIMIT 1');
            $snapStmt->execute([':snap_seller_id' => $sellerId]);
            $newBalSnap = $snapStmt->fetch(PDO::FETCH_ASSOC) ?: null;
 
            bv_seller_balance_log('payout_action_completed', [
                'action'            => 'mark_paid',
                'payout_id'         => $payoutId,
                'admin_id'          => $adminId,
                'seller_id'         => $sellerId,
                'amount'            => $amount,
                'payment_method'    => $paymentMethod,
                'payment_reference' => $paymentReference,  
            ]);
            bv_seller_balance_log('payout_paid_completed', [
                'payout_id'         => $payoutId,
                'admin_id'          => $adminId,
                'seller_id'         => $sellerId,
                'amount'            => $amount,
                'payment_method'    => $paymentMethod,
                'payment_reference' => $paymentReference,
            ]);
			

            return [
                'ok'                => true,
                'noop'              => false,
                'payout_request_id' => $payoutId,
                'status'            => 'paid',
                'debit_ledger_id'   => $debitLedgerId,
                'credit_ledger_id'  => $creditLedgerId,
                'balance'           => $newBalSnap,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            bv_seller_balance_log('payout_failed', [
                'context'   => 'mark_payout_paid',
                'payout_id' => $payoutId,
                'error'     => $e->getMessage(),
            ]);
            error_log('[seller_balance] mark_payout_paid failed for request #' . $payoutId . ': ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('bv_seller_balance_cancel_payout')) {
    /**
     * Cancel a payout request and return held funds → available.
     * Has its own ledger types (payout_cancel_*) separate from reject.
     * Idempotency keys: payout_cancel_held_debit:{id} / payout_cancel_available_credit:{id}
     */
   function bv_seller_balance_cancel_payout(int $payoutId, int $adminId, string $note = ''): array|bool
    {
        if ($payoutId <= 0) {
            return false;
        }
        bv_seller_balance_log('payout_cancel_started', [
            'payout_id' => $payoutId,
            'admin_id'  => $adminId,
        ]);
        $pdo = bv_seller_balance_pdo();
        try {
            $pdo->beginTransaction();
            $reqStmt = $pdo->prepare('SELECT * FROM seller_payout_requests WHERE id = ? LIMIT 1 FOR UPDATE');
            $reqStmt->execute([$payoutId]);
            $req = $reqStmt->fetch(PDO::FETCH_ASSOC);
            if (!$req) {
                $pdo->rollBack();
                return false;
            }
            $currentStatus = strtolower((string)$req['status']);
            if (in_array($currentStatus, ['paid', 'cancelled', 'canceled', 'rejected', 'failed'], true)) {
                _bv_sb_log_payout_status_locked($payoutId, $adminId, 'cancel', $currentStatus);  
                $pdo->commit();
               return _bv_sb_payout_processed_noop($payoutId, $currentStatus);
            }
           if (!in_array($currentStatus, ['requested', 'pending', 'approved'], true)) {
                $pdo->rollBack();
                return false;
            }

            $sellerId = (int)$req['seller_id'];
            $amount   = round((float)$req['amount'], 4);
            $currency = (string)$req['currency'];

            if (in_array($currentStatus, ['requested', 'pending'], true)) {
                // Requested/pending payouts have not moved money from available -> held yet.
                // Cancelling them must only change the request status; never reverse held balance.
                $set = ["status = 'cancelled'", "admin_note = CONCAT(COALESCE(admin_note,''), :note)"];
                $params = [
                    ':note' => "
[Cancelled] " . ($note ?: 'No reason given'),
                    ':id'   => $payoutId,
                ];
                if (_bv_sb_column_exists($pdo, 'seller_payout_requests', 'cancelled_at')) {
                    $set[] = 'cancelled_at = COALESCE(cancelled_at, NOW())';
                }
                if (_bv_sb_column_exists($pdo, 'seller_payout_requests', 'cancelled_by')) {
                    $set[] = 'cancelled_by = :admin_id';
                    $params[':admin_id'] = $adminId;
                } elseif (_bv_sb_column_exists($pdo, 'seller_payout_requests', 'admin_id')) {
                    $set[] = 'admin_id = :admin_id';
                    $params[':admin_id'] = $adminId;
                }

                $sql = 'UPDATE seller_payout_requests SET ' . implode(', ', $set) . ' WHERE id = :id';
                $pdo->prepare($sql)->execute($params);
                $pdo->commit();

                bv_seller_balance_log('payout_cancel_pending_status_only', [
                    'payout_id'  => $payoutId,
                    'admin_id'   => $adminId,
                    'seller_id'  => $sellerId,
                    'amount'     => $amount,
                    'old_status' => $currentStatus,
                    'new_status' => 'cancelled',
                ]);
                bv_seller_balance_log('payout_action_completed', [
                    'action'      => 'cancel',
                    'payout_id'   => $payoutId,
                    'admin_id'    => $adminId,
                    'seller_id'   => $sellerId,
                    'amount'      => $amount,
                    'status_only' => true,
                ]);
                bv_seller_balance_log('payout_cancel_completed', [
                    'payout_id'   => $payoutId,
                    'admin_id'    => $adminId,
                    'seller_id'   => $sellerId,
                    'amount'      => $amount,
                    'status_only' => true,
                ]);
                return true;
            }

            $heldKeyNew      = 'payout_cancel_held_debit:'       . $payoutId;
            $availableKeyNew = 'payout_cancel_available_credit:' . $payoutId;

            $balRow = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE');
            $balRow->execute([$sellerId]);
            $balance = $balRow->fetch(PDO::FETCH_ASSOC);
            if (!$balance) {
                $pdo->rollBack();
                return false;
            }

            $heldNow      = round((float)($balance['held_balance']      ?? 0), 4);
            $availableNow = round((float)($balance['available_balance'] ?? 0), 4);

            if ($amount > $heldNow) {
                $pdo->rollBack();
                throw new RuntimeException('Held balance is lower than payout amount; cannot cancel safely.');
            }

            $metaBase = _bv_sb_request_context_meta() + [
                'action'            => 'payout_cancel',
                'payout_request_id' => $payoutId,
                'admin_id'          => $adminId,
                 'old_status'        => $currentStatus,
                'new_status'        => 'cancelled',
                'payment_method'    => (string)($req['payout_method'] ?? ''),
                'payment_reference' => null,
            ];

            if ($amount > 0) {
                $heldExists      = _bv_sb_ledger_exists($pdo, $heldKeyNew);
                $availableExists = _bv_sb_ledger_exists($pdo, $availableKeyNew);

                if ($heldExists && $availableExists) {
                    // Already reversed — noop on ledger.
                } elseif ($heldExists xor $availableExists) {
                    $pdo->rollBack();
                    bv_seller_balance_log('payout_ledger_inconsistent', [
                        'payout_id'       => $payoutId,
                        'held_exists'     => $heldExists,
                        'available_exists'=> $availableExists,
                        'context'         => 'cancel',
                    ]);
                    bv_seller_balance_log('payout_inconsistent_ledger', [
                        'payout_id' => $payoutId, 'context' => 'cancel']);
                    throw new RuntimeException(
                        '[seller_balance] Payout ledger inconsistency on cancel #' . $payoutId
                        . ': exactly one reversal entry exists. Manual review required.'
                    );
                } else {
                    _bv_sb_guard_no_negative($heldNow, $amount, 'payout_cancel held seller #' . $sellerId);

                    _bv_sb_insert_ledger_once($pdo, [
                        'seller_id'       => $sellerId,
                        'type'            => 'payout_cancel',
                        'balance_type'    => 'held',
                        'direction'       => 'debit',
                        'amount'          => $amount,
                        'currency'        => $currency,
                        'balance_before'  => $heldNow,
                        'balance_after'   => round($heldNow - $amount, 4),
                        'reference_type'  => 'payout_request',
                        'reference_id'    => $payoutId,
                        'idempotency_key' => $heldKeyNew,
                        'note'            => 'Payout #' . $payoutId . ' cancelled — returned to available',
                        'meta_json'       => $metaBase,
                        'created_by_type' => 'admin',
                        'created_by_id'   => $adminId,
                    ]);
                    _bv_sb_insert_ledger_once($pdo, [
                        'seller_id'       => $sellerId,
                        'type'            => 'payout_cancel',
                        'balance_type'    => 'available',
                        'direction'       => 'credit',
                        'amount'          => $amount,
                        'currency'        => $currency,
                        'balance_before'  => $availableNow,
                        'balance_after'   => round($availableNow + $amount, 4),
                        'reference_type'  => 'payout_request',
                        'reference_id'    => $payoutId,
                        'idempotency_key' => $availableKeyNew,
                        'note'            => 'Payout #' . $payoutId . ' cancelled — returned to available',
                        'meta_json'       => $metaBase,
                        'created_by_type' => 'admin',
                        'created_by_id'   => $adminId,
                    ]);
                    $pdo->prepare(
                        'UPDATE seller_balances
                         SET held_balance      = held_balance      - :amt,
                             available_balance = available_balance + :amt
                         WHERE seller_id = :sid'
                    )->execute([':amt' => $amount, ':sid' => $sellerId]);
                }
            }

            $set = ["status = 'cancelled'", "admin_note = CONCAT(COALESCE(admin_note,''), :note)"];
           $params = [
                ':note' => "
[Cancelled] " . ($note ?: 'No reason given'),
                ':id'   => $payoutId,
            ];			
            if (_bv_sb_column_exists($pdo, 'seller_payout_requests', 'cancelled_at')) {
                $set[] = 'cancelled_at = COALESCE(cancelled_at, NOW())';
            }
            if (_bv_sb_column_exists($pdo, 'seller_payout_requests', 'cancelled_by')) {
                $set[] = 'cancelled_by = :admin_id';
               $params[':admin_id'] = $adminId;				
            } elseif (_bv_sb_column_exists($pdo, 'seller_payout_requests', 'admin_id')) {
                $set[] = 'admin_id = :admin_id';
                $params[':admin_id'] = $adminId;				
            }

            $sql = 'UPDATE seller_payout_requests SET ' . implode(', ', $set) . ' WHERE id = :id';
           $pdo->prepare($sql)->execute($params); 

            $pdo->commit();
          bv_seller_balance_log('payout_action_completed', [
                'action'    => 'cancel',
                'payout_id' => $payoutId,
                'admin_id'  => $adminId,
                'seller_id' => $sellerId,
                'amount'    => $amount,
            ]);			
            bv_seller_balance_log('payout_cancel_completed', [
                'payout_id' => $payoutId,
                'admin_id'  => $adminId,
                'seller_id' => $sellerId,
                'amount'    => $amount,
            ]);
            return true;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            bv_seller_balance_log('payout_failed', ['context' => 'cancel_payout',
                'payout_id' => $payoutId, 'error' => $e->getMessage()]);
            error_log('[seller_balance] cancel_payout #' . $payoutId . ': ' . $e->getMessage());
            return false;
        }
    }
}
// ---------------------------------------------------------------------------
// REFUND STUBS — Phase 1 (prepared, not enforced)
// ---------------------------------------------------------------------------

if (!function_exists('_bv_sb_refund_seller_exposures')) {
    function _bv_sb_refund_seller_exposures(PDO $pdo, int $refundId): array
    {
        if ($refundId <= 0 || !_bv_sb_table_exists($pdo, 'order_refunds') || !_bv_sb_table_exists($pdo, 'order_refund_items')) {
            return [];
        }
        $amountExpr = 'COALESCE(NULLIF(ri.actual_refund_amount, 0), NULLIF(ri.actual_refunded_amount, 0), NULLIF(ri.approved_refund_amount, 0), NULLIF(ri.refund_line_amount, 0), NULLIF(ri.requested_refund_amount, 0), 0)';
        if (!_bv_sb_column_exists($pdo, 'order_refund_items', 'actual_refund_amount')) {
            $amountExpr = 'COALESCE(NULLIF(ri.actual_refunded_amount, 0), NULLIF(ri.approved_refund_amount, 0), NULLIF(ri.refund_line_amount, 0), NULLIF(ri.requested_refund_amount, 0), 0)';
        }
        if (!_bv_sb_column_exists($pdo, 'order_refund_items', 'actual_refunded_amount')) {
            $amountExpr = 'COALESCE(NULLIF(ri.approved_refund_amount, 0), NULLIF(ri.refund_line_amount, 0), NULLIF(ri.requested_refund_amount, 0), 0)';
        }

        $stmt = $pdo->prepare(
            "SELECT r.id AS refund_id,
                    r.order_id,
                    r.status,
                    r.currency,
                    ri.order_item_id,
                    oi.seller_id,
                    oi.line_total,
                    $amountExpr AS refund_amount,
                    COALESCE(e.amount, oi.line_total, 0) AS gross_amount,
                    COALESCE(f.amount, 0) AS fee_amount
             FROM order_refunds r
             JOIN order_refund_items ri ON ri.refund_id = r.id
             JOIN order_items oi ON oi.id = ri.order_item_id AND oi.seller_id > 0
             LEFT JOIN seller_ledger e
               ON e.seller_id = oi.seller_id AND e.type = 'earning'
              AND e.reference_type = 'order_item' AND e.reference_id = oi.id
              JOIN seller_ledger f
               ON f.seller_id = oi.seller_id AND f.type = 'platform_fee'
              AND f.reference_type = 'order_item' AND f.reference_id = oi.id
             WHERE r.id = :rid"
        );
        $stmt->execute([':rid' => $refundId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $bySeller = [];
        foreach ($rows as $row) {
            $sellerId = (int)($row['seller_id'] ?? 0);
            $lineTotal = round((float)($row['line_total'] ?? 0), 4);
            $refundAmount = round((float)($row['refund_amount'] ?? 0), 4);
            if ($sellerId <= 0 || $refundAmount <= 0) {
                continue;
            }
            $gross = round((float)($row['gross_amount'] ?? $lineTotal), 4);
            $fee = round((float)($row['fee_amount'] ?? 0), 4);
            $net = max(0.0, round($gross - $fee, 4));
            $ratio = $lineTotal > 0 ? min(1.0, $refundAmount / $lineTotal) : 1.0;
            $exposure = round($net * $ratio, 4);
            if ($exposure <= 0) {
                continue;
            }
            if (!isset($bySeller[$sellerId])) {
                $bySeller[$sellerId] = [
                    'seller_id' => $sellerId,
                    'currency' => (string)($row['currency'] ?? 'USD'),
                    'amount' => 0.0,
                    'items' => [],
                ];
            }
            $bySeller[$sellerId]['amount'] = round($bySeller[$sellerId]['amount'] + $exposure, 4);
            $bySeller[$sellerId]['items'][] = [
                'order_item_id' => (int)$row['order_item_id'],
                'refund_amount' => $refundAmount,
                'seller_exposure' => $exposure,
            ];
        }
        return array_values($bySeller);
    }
}

if (!function_exists('bv_seller_balance_hold_for_refund')) {
    function bv_seller_balance_hold_for_refund(int $refundId): bool
    {
        if ($refundId <= 0) {
            return false;
        }
        $pdo = bv_seller_balance_pdo();
        $groups = _bv_sb_refund_seller_exposures($pdo, $refundId);
        if ($groups === []) {
            return false;
        }
        try {
            $pdo->beginTransaction();
            foreach ($groups as $group) {
                $sellerId = (int)$group['seller_id'];
                $needed = round((float)$group['amount'], 4);
                if ($sellerId <= 0 || $needed <= 0) {
                    continue;
                }
                $availableKey = 'refund_hold_available:' . $refundId . ':' . $sellerId;
                $heldKey = 'refund_hold_held:' . $refundId . ':' . $sellerId;
                if (_bv_sb_ledger_exists($pdo, $availableKey) || _bv_sb_ledger_exists($pdo, $heldKey)) {
                    continue;
                }

                $pdo->prepare('INSERT IGNORE INTO seller_balances (seller_id, currency) VALUES (?, ?)')
                    ->execute([$sellerId, (string)$group['currency']]);
                $balStmt = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE');
                $balStmt->execute([$sellerId]);
                $balance = $balStmt->fetch(PDO::FETCH_ASSOC);
                $availableNow = round((float)($balance['available_balance'] ?? 0), 4);
                $heldNow = round((float)($balance['held_balance'] ?? 0), 4);
                $holdAmt = min($needed, $availableNow);
                $shortage = round($needed - $holdAmt, 4);
                if ($holdAmt <= 0 && $shortage <= 0) {
                    continue;
                }
                $meta = ['refund_id' => $refundId, 'needed' => $needed, 'held' => $holdAmt, 'shortage' => $shortage, 'items' => $group['items']];

                if ($holdAmt > 0) {
                    _bv_sb_insert_ledger_once($pdo, [
                        'seller_id' => $sellerId,
                        'type' => 'refund_hold',
                        'balance_type' => 'available',
                        'direction' => 'debit',
                        'amount' => $holdAmt,
                        'currency' => (string)$group['currency'],
                        'balance_before' => $availableNow,
                        'balance_after' => round($availableNow - $holdAmt, 4),
                        'reference_type' => 'refund',
                        'reference_id' => $refundId,
                        'idempotency_key' => $availableKey,
                        'note' => 'Refund hold #' . $refundId,
                        'meta_json' => $meta,
                        'created_by_type' => 'system',
                    ]);
                    _bv_sb_insert_ledger_once($pdo, [
                        'seller_id' => $sellerId,
                        'type' => 'refund_hold',
                        'balance_type' => 'held',
                        'direction' => 'credit',
                        'amount' => $holdAmt,
                        'currency' => (string)$group['currency'],
                        'balance_before' => $heldNow,
                        'balance_after' => round($heldNow + $holdAmt, 4),
                        'reference_type' => 'refund',
                        'reference_id' => $refundId,
                        'idempotency_key' => $heldKey,
                        'note' => 'Refund hold #' . $refundId,
                        'meta_json' => $meta,
                        'created_by_type' => 'system',
                    ]);
                    $pdo->prepare(
                        'UPDATE seller_balances SET available_balance = available_balance - :amt, held_balance = held_balance + :amt WHERE seller_id = :sid'
                    )->execute([':amt' => $holdAmt, ':sid' => $sellerId]);
                } elseif ($shortage > 0) {
                    _bv_sb_insert_ledger_once($pdo, [
                        'seller_id' => $sellerId,
                        'type' => 'refund_hold',
                        'balance_type' => 'available',
                        'direction' => 'debit',
                        'amount' => 0,
                        'currency' => (string)$group['currency'],
                        'balance_before' => $availableNow,
                        'balance_after' => $availableNow,
                        'reference_type' => 'refund',
                        'reference_id' => $refundId,
                        'idempotency_key' => $availableKey,
                        'note' => 'Refund hold shortage #' . $refundId,
                        'meta_json' => $meta,
                        'created_by_type' => 'system',
                    ]);
                }
            }
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[seller_balance] hold_for_refund #' . $refundId . ': ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('bv_seller_balance_apply_refund_deduction')) {
    function bv_seller_balance_apply_refund_deduction(int $refundId): bool
    {
        if ($refundId <= 0) {
            return false;
        }
        $pdo = bv_seller_balance_pdo();
        $groups = _bv_sb_refund_seller_exposures($pdo, $refundId);
        if ($groups === []) {
            return false;
        }
        try {
            $pdo->beginTransaction();
            foreach ($groups as $group) {
                $sellerId = (int)$group['seller_id'];
                $needed = round((float)$group['amount'], 4);
                $currency = (string)$group['currency'];
                if ($sellerId <= 0 || $needed <= 0) {
                    continue;
                }
                $doneKey = 'refund_deduction:' . $refundId . ':' . $sellerId;
                if (_bv_sb_ledger_exists($pdo, $doneKey . ':held') || _bv_sb_ledger_exists($pdo, $doneKey . ':available') || _bv_sb_ledger_exists($pdo, $doneKey . ':pending')) {
                    continue;
                }
                $balStmt = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE');
                $balStmt->execute([$sellerId]);
                $balance = $balStmt->fetch(PDO::FETCH_ASSOC);
                if (!$balance) {
                    continue;
                }
                $remaining = $needed;
                // Deduction order: prefer pending first (cheapest to the seller),
                // then available, finally held (which may already be ear-marked for payout).
                foreach ([
                    'pending'   => 'pending_balance',
                    'available' => 'available_balance',
                    'held'      => 'held_balance',
                ] as $balanceType => $column) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $before = round((float)($balance[$column] ?? 0), 4);
                    $deduct = min($remaining, $before);
                    if ($deduct <= 0) {
                        continue;
                    }
                    // Anti-negative guard before each partial deduction.
                    _bv_sb_guard_no_negative($before, $deduct, 'refund_deduction ' . $balanceType . ' seller #' . $sellerId);

                    _bv_sb_insert_ledger_once($pdo, [
                        'seller_id' => $sellerId,
                        'type' => 'refund_deduction',
                        'balance_type' => $balanceType,
                        'direction' => 'debit',
                        'amount' => $deduct,
                        'currency' => $currency,
                        'balance_before' => $before,
                        'balance_after' => round($before - $deduct, 4),
                        'reference_type' => 'refund',
                        'reference_id' => $refundId,
                        'idempotency_key' => $doneKey . ':' . $balanceType,
                        'note' => 'Refund deduction #' . $refundId,
                        'meta_json' => ['refund_id' => $refundId, 'needed' => $needed, 'items' => $group['items']],
                        'created_by_type' => 'system',
                    ]);
                    $pdo->prepare('UPDATE seller_balances SET ' . $column . ' = ' . $column . ' - :amt WHERE seller_id = :sid')
                        ->execute([':amt' => $deduct, ':sid' => $sellerId]);
                    $balance[$column] = round($before - $deduct, 4);
                    $remaining = round($remaining - $deduct, 4);
                }
            }
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[seller_balance] apply_refund_deduction #' . $refundId . ': ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('bv_seller_balance_release_refund_hold')) {
    function bv_seller_balance_release_refund_hold(int $refundId): bool
    {
        if ($refundId <= 0) {
            return false;
        }
        $pdo = bv_seller_balance_pdo();
        $stmt = $pdo->prepare(
            "SELECT seller_id, currency, SUM(CASE WHEN balance_type = 'held' AND direction = 'credit' THEN amount ELSE 0 END) AS held_amount
             FROM seller_ledger
             WHERE type = 'refund_hold' AND reference_type = 'refund' AND reference_id = ?
             GROUP BY seller_id, currency"
        );
        $stmt->execute([$refundId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return false;
        }
        try {
            $pdo->beginTransaction();
            foreach ($rows as $row) {
                $sellerId = (int)$row['seller_id'];
                $amount = round((float)$row['held_amount'], 4);
                $currency = (string)$row['currency'];
                $heldKey = 'refund_hold_release_held:' . $refundId . ':' . $sellerId;
                $availableKey = 'refund_hold_release_available:' . $refundId . ':' . $sellerId;
                if ($sellerId <= 0 || $amount <= 0 || _bv_sb_ledger_exists($pdo, $heldKey)) {
                    continue;
                }
                $balStmt = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE');
                $balStmt->execute([$sellerId]);
                $balance = $balStmt->fetch(PDO::FETCH_ASSOC);
                if (!$balance) {
                    continue;
                }
                $heldNow = round((float)($balance['held_balance'] ?? 0), 4);
                $availableNow = round((float)($balance['available_balance'] ?? 0), 4);
                $releaseAmt = min($amount, $heldNow);
                if ($releaseAmt <= 0) {
                    continue;
                }
                _bv_sb_insert_ledger_once($pdo, [
                    'seller_id' => $sellerId,
                    'type' => 'refund_hold',
                    'balance_type' => 'held',
                    'direction' => 'debit',
                    'amount' => $releaseAmt,
                    'currency' => $currency,
                    'balance_before' => $heldNow,
                    'balance_after' => round($heldNow - $releaseAmt, 4),
                    'reference_type' => 'refund',
                    'reference_id' => $refundId,
                    'idempotency_key' => $heldKey,
                    'note' => 'Refund hold released #' . $refundId,
                    'created_by_type' => 'system',
                ]);
                _bv_sb_insert_ledger_once($pdo, [
                    'seller_id' => $sellerId,
                    'type' => 'refund_hold',
                    'balance_type' => 'available',
                    'direction' => 'credit',
                    'amount' => $releaseAmt,
                    'currency' => $currency,
                    'balance_before' => $availableNow,
                    'balance_after' => round($availableNow + $releaseAmt, 4),
                    'reference_type' => 'refund',
                    'reference_id' => $refundId,
                    'idempotency_key' => $availableKey,
                    'note' => 'Refund hold released #' . $refundId,
                    'created_by_type' => 'system',
                ]);
                $pdo->prepare('UPDATE seller_balances SET held_balance = held_balance - :amt, available_balance = available_balance + :amt WHERE seller_id = :sid')
                    ->execute([':amt' => $releaseAmt, ':sid' => $sellerId]);
            }
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[seller_balance] release_refund_hold #' . $refundId . ': ' . $e->getMessage());
            return false;
        }
    }
}
// ---------------------------------------------------------------------------
// ADMIN MANUAL ADJUSTMENT
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_admin_adjust')) {
    /**
     * Add or subtract from available_balance manually.
     * $direction: 'credit' or 'debit'
     */
    function bv_seller_balance_admin_adjust(
        int    $sellerId,
        float  $amount,
        string $direction,
        string $note,
        int    $adminId
    ): bool {
        if ($sellerId <= 0 || $amount <= 0 || !in_array($direction, ['credit','debit'], true)) {
            return false;
        }
        $pdo = bv_seller_balance_pdo();
        try {
            $pdo->beginTransaction();

            $pdo->prepare('INSERT IGNORE INTO seller_balances (seller_id) VALUES (?)')->execute([$sellerId]);
            $balRow = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE');
            $balRow->execute([$sellerId]);
            $balance = $balRow->fetch(PDO::FETCH_ASSOC);

            $availNow = round((float)($balance['available_balance'] ?? 0), 4);
            $delta    = round($amount, 4);
            $newAvail = $direction === 'credit'
                ? round($availNow + $delta, 4)
                : round($availNow - $delta, 4);

            if ($newAvail < 0) {
                $pdo->rollBack();
                throw new RuntimeException('Debit would result in negative available_balance.');
            }

            $type = $direction === 'credit' ? 'adjustment_credit' : 'adjustment_debit';

            _bv_sb_insert_ledger($pdo, [
                'seller_id'      => $sellerId,
                'type'           => $type,
                'balance_type'   => 'available',
                'direction'      => $direction,
                'amount'         => $delta,
                'currency'       => (string)($balance['currency'] ?? 'USD'),
                'balance_before' => $availNow,
                'balance_after'  => $newAvail,
                'note'           => $note,
                'created_by_type'=> 'admin',
                'created_by_id'  => $adminId,
            ]);

            $pdo->prepare(
                'UPDATE seller_balances SET available_balance = :avail WHERE seller_id = :sid'
            )->execute([':avail' => $newAvail, ':sid' => $sellerId]);

            $pdo->commit();
            return true;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

// ---------------------------------------------------------------------------
// AUTO PAYOUT REQUESTS
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_create_auto_payout_requests')) {
    function bv_seller_balance_create_auto_payout_requests(): int
    {
        if (bv_seller_balance_get_setting('auto_payout_enabled', '0') !== '1') {
            return 0;
        }

        $pdo = bv_seller_balance_pdo();
        $minAmount = max(0.0, (float)bv_seller_balance_get_setting('auto_payout_min_amount', '50.00'));
        $method = bv_seller_balance_get_setting('payout_method', 'manual_bank_transfer');
        $created = 0;

        $stmt = $pdo->prepare(
            "SELECT sb.*
             FROM seller_balances sb
             WHERE sb.available_balance >= :min_amount
               AND NOT EXISTS (
                   SELECT 1 FROM seller_payout_requests pr
                   WHERE pr.seller_id = sb.seller_id
                     AND pr.currency = sb.currency
                     AND pr.status IN ('requested','approved')
               )
             ORDER BY sb.seller_id ASC"
        );
        $stmt->execute([':min_amount' => $minAmount]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $sellerId = (int)$row['seller_id'];
            $amount = round((float)$row['available_balance'], 4);
            if ($sellerId <= 0 || $amount < $minAmount) {
                continue;
            }
            try {
                $id = bv_seller_balance_request_payout($sellerId, $amount, [
                    'payout_method' => $method,
                    'seller_note' => 'Automatic payout request',
                    'created_by_type' => 'system',
                    'created_by_id' => null,
                ]);
                if ($id > 0) {
                    $created++;
                }
            } catch (Throwable $e) {
                error_log('[seller_balance] auto payout seller #' . $sellerId . ': ' . $e->getMessage());
            }
        }

        return $created;
    }
}

// ---------------------------------------------------------------------------
// READ QUERIES
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_get_ledger')) {
    function bv_seller_balance_get_ledger(int $sellerId, int $limit = 50, int $offset = 0): array
    {
        try {
            $stmt = bv_seller_balance_pdo()->prepare(
                "SELECT * FROM seller_ledger
                 WHERE seller_id = ?
                 ORDER BY id DESC
                 LIMIT ? OFFSET ?"
            );
            $stmt->execute([$sellerId, $limit, $offset]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }
}

if (!function_exists('bv_seller_balance_get_payout_requests')) {
    function bv_seller_balance_get_payout_requests(
        int    $sellerId,
        string $status  = '',
        int    $limit   = 20,
        int    $offset  = 0
    ): array {
        try {
            $where = 'WHERE seller_id = ?';
            $params = [$sellerId];
            if ($status !== '') {
                $where .= ' AND status = ?';
                $params[] = $status;
            }
            $stmt = bv_seller_balance_pdo()->prepare(
                "SELECT * FROM seller_payout_requests $where ORDER BY id DESC LIMIT ? OFFSET ?"
            );
            $params[] = $limit;
            $params[] = $offset;
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }
}

if (!function_exists('bv_seller_balance_get_all_payout_requests')) {
    function bv_seller_balance_get_all_payout_requests(
        string $status = '',
        int    $limit  = 50,
        int    $offset = 0
    ): array {
        try {
            $where  = $status !== '' ? "WHERE pr.status = ?" : '';
            $params = $status !== '' ? [$status] : [];
            $params[] = $limit;
            $params[] = $offset;

            $stmt = bv_seller_balance_pdo()->prepare(
                "SELECT pr.*,
                        u.email          AS seller_email,
                        u.first_name     AS seller_first,
                        u.last_name      AS seller_last
                 FROM seller_payout_requests pr
                 JOIN users u ON u.id = pr.seller_id
                 $where
                 ORDER BY pr.id DESC
                 LIMIT ? OFFSET ?"
            );
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }
}

if (!function_exists('bv_seller_balance_all_sellers_summary')) {
    function bv_seller_balance_all_sellers_summary(): array
    {
        try {
            $stmt = bv_seller_balance_pdo()->query(
                "SELECT sb.*,
                        u.email      AS seller_email,
                        u.first_name AS seller_first,
                        u.last_name  AS seller_last
                 FROM seller_balances sb
                 JOIN users u ON u.id = sb.seller_id
                 ORDER BY sb.available_balance DESC"
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }
}

if (!function_exists('bv_seller_balance_reconcile_seller')) {
    /**
     * Reconcile seller_balances snapshot against seller_ledger source of truth.
     *
     * Runs inside a transaction with FOR UPDATE lock on seller_balances so no
     * concurrent mutation can race the recomputation.
     *
     * @return array{
     *   ok: bool,
     *   ledger: array,
     *   balance: array,
     *   diff: array,
     *   updated: bool,
     *   drift_logged: bool,
     * }
     */
    /**
     * Reconcile seller_balances snapshot against seller_ledger.
     *
     * @param array $options  Supported keys:
     *   'dry_run' => bool  When true: compute drift but DO NOT write to seller_balances.
     *                       Returns 'dry_run'=>true, 'would_update'=>bool, 'current'=>[...], 'computed'=>[...].
     *                       When false/omitted: existing behaviour (compute + update if drift found).
     */
    function bv_seller_balance_reconcile_seller(int $sellerId, string $currency = 'USD', array $options = []): array
    {
        $currency = strtoupper(trim($currency)) ?: 'USD';
        $result = [
            'ok'          => false,
            'dry_run'     => false,
            'ledger'      => ['pending' => 0.0, 'available' => 0.0, 'held' => 0.0, 'paid_out' => 0.0,
                              'total_earned_gross' => 0.0, 'total_platform_fee' => 0.0],
            'balance'     => ['pending' => 0.0, 'available' => 0.0, 'held' => 0.0, 'paid_out' => 0.0,
                              'total_earned_gross' => 0.0, 'total_platform_fee' => 0.0],
            'diff'        => ['pending' => 0.0, 'available' => 0.0, 'held' => 0.0, 'paid_out' => 0.0,
                              'total_earned_gross' => 0.0, 'total_platform_fee' => 0.0],
            'updated'     => false,
            'drift_logged'=> false,
        ];
        if ($sellerId <= 0) {
            return $result;
        }

        $isDryRun = !empty($options['dry_run']);

        $pdo = bv_seller_balance_pdo();

        try {
            $pdo->beginTransaction();

            // ── Lock snapshot row ───────────────────────────────────────────────
            $balStmt = $pdo->prepare(
                'SELECT * FROM seller_balances
                 WHERE seller_id = ? AND currency = ?
                 LIMIT 1 FOR UPDATE'
            );
            $balStmt->execute([$sellerId, $currency]);
            $balance = $balStmt->fetch(PDO::FETCH_ASSOC);

            if (!$balance) {
                $pdo->rollBack();
                return $result;
            }

            $result['balance'] = [
                'pending'            => round((float)($balance['pending_balance']    ?? 0), 4),
                'available'          => round((float)($balance['available_balance']  ?? 0), 4),
                'held'               => round((float)($balance['held_balance']       ?? 0), 4),
                'paid_out'           => round((float)($balance['paid_out_balance']   ?? 0), 4),
                'total_earned_gross' => round((float)($balance['total_earned_gross'] ?? 0), 4),
                'total_platform_fee' => round((float)($balance['total_platform_fee'] ?? 0), 4),
            ];

            // ── Recompute balance_type sums from ledger ─────────────────────────
            $ledgerStmt = $pdo->prepare(
                "SELECT balance_type,
                        SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END) AS net_amount
                 FROM seller_ledger
                 WHERE seller_id = ? AND currency = ?
                 GROUP BY balance_type"
            );
            $ledgerStmt->execute([$sellerId, $currency]);
            foreach ($ledgerStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $bt  = (string)($row['balance_type'] ?? '');
                $net = round((float)($row['net_amount'] ?? 0), 4);
                match ($bt) {
                    'pending'   => $result['ledger']['pending']   = $net,
                    'available' => $result['ledger']['available'] = $net,
                    'held'      => $result['ledger']['held']      = $net,
                    'paid_out'  => $result['ledger']['paid_out']  = $net,
                    default     => null,
                };
            }

            // ── Recompute total_earned_gross (sum of earning credits) ───────────
            $grossStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(amount), 0)
                 FROM seller_ledger
                 WHERE seller_id = ? AND currency = ?
                   AND type = 'earning' AND direction = 'credit'"
            );
            $grossStmt->execute([$sellerId, $currency]);
            $result['ledger']['total_earned_gross'] = round((float)$grossStmt->fetchColumn(), 4);

            // ── Recompute total_platform_fee (sum of platform_fee debits) ───────
            $feeStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(amount), 0)
                 FROM seller_ledger
                 WHERE seller_id = ? AND currency = ?
                   AND type = 'platform_fee' AND direction = 'debit'"
            );
            $feeStmt->execute([$sellerId, $currency]);
            $result['ledger']['total_platform_fee'] = round((float)$feeStmt->fetchColumn(), 4);

            // ── Compute diffs ───────────────────────────────────────────────────
            $driftFields = ['pending', 'available', 'held', 'paid_out',
                            'total_earned_gross', 'total_platform_fee'];
            $driftFound  = false;
            foreach ($driftFields as $k) {
                $result['diff'][$k] = round($result['balance'][$k] - $result['ledger'][$k], 4);
                if (abs($result['diff'][$k]) >= 0.0001) {
                    $driftFound = true;
                }
            }

            $result['ok'] = !$driftFound;

            // ── Dry-run: report only, no DB write ──────────────────────────────
            if ($isDryRun) {
                $pdo->rollBack(); // release the FOR UPDATE lock immediately

                $result['dry_run']      = true;
                $result['would_update'] = $driftFound;
                $result['current']      = $result['balance'];
                $result['computed']     = $result['ledger'];

                if (function_exists('bv_seller_balance_log')) {
                    bv_seller_balance_log('reconcile_preview', [
                        'seller_id'    => $sellerId,
                        'currency'     => $currency,
                        'drift_found'  => $driftFound,
                        'current'      => $result['balance'],
                        'computed'     => $result['ledger'],
                        'diff'         => $result['diff'],
                    ]);
                }

                return $result;
            }

            // ── Live run: correct snapshot + log if drift found ─────────────────
            if ($driftFound) {
                $pdo->prepare(
                    'UPDATE seller_balances
                     SET pending_balance    = :pending,
                         available_balance  = :available,
                         held_balance       = :held,
                         paid_out_balance   = :paid_out,
                         total_earned_gross = :gross,
                         total_platform_fee = :fee,
                         updated_at         = NOW()
                     WHERE seller_id = :sid AND currency = :cur'
                )->execute([
                    ':pending'   => $result['ledger']['pending'],
                    ':available' => $result['ledger']['available'],
                    ':held'      => $result['ledger']['held'],
                    ':paid_out'  => $result['ledger']['paid_out'],
                    ':gross'     => $result['ledger']['total_earned_gross'],
                    ':fee'       => $result['ledger']['total_platform_fee'],
                    ':sid'       => $sellerId,
                    ':cur'       => $currency,
                ]);
                $result['updated'] = true;

                bv_seller_balance_log('balance_drift_corrected', [
                    'seller_id'  => $sellerId,
                    'currency'   => $currency,
                    'old'        => $result['balance'],
                    'new'        => $result['ledger'],
                    'diff'       => $result['diff'],
                ]);
                $result['drift_logged'] = true;
            }

            $result['dry_run'] = false;
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            bv_seller_balance_log('reconcile_error', [
                'seller_id' => $sellerId,
                'currency'  => $currency,
                'error'     => $e->getMessage(),
            ]);
        }

        return $result;
    }
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_csrf_token')) {
    function bv_seller_balance_csrf_token(string $action = 'seller_balance'): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $key = '_bvsb_csrf_' . $action;
        if (empty($_SESSION[$key])) {
            $_SESSION[$key] = bin2hex(random_bytes(24));
        }
        return $_SESSION[$key];
    }
}

if (!function_exists('bv_seller_balance_verify_csrf')) {
    function bv_seller_balance_verify_csrf(string $token, string $action = 'seller_balance'): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $key    = '_bvsb_csrf_' . $action;
        $stored = (string)($_SESSION[$key] ?? '');
        return $stored !== '' && hash_equals($stored, $token);
    }
}

// ---------------------------------------------------------------------------
// UTILITIES
// ---------------------------------------------------------------------------

if (!function_exists('bv_sb_e')) {
    function bv_sb_e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('bv_sb_money')) {
    function bv_sb_money(float|string $amount, string $currency = 'USD'): string
    {
        return $currency . ' ' . number_format((float)$amount, 2);
    }
}

if (!function_exists('bv_sb_redirect')) {
    function bv_sb_redirect(string $url): never
    {
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('bv_sb_flash_set')) {
    function bv_sb_flash_set(string $type, string $message): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION['_bvsb_flash'] = ['type' => $type, 'message' => $message];
    }
}

if (!function_exists('bv_sb_flash_get')) {
    function bv_sb_flash_get(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $flash = $_SESSION['_bvsb_flash'] ?? [];
        unset($_SESSION['_bvsb_flash']);
        return $flash;
    }
}

// ===========================================================================
// CRON COMPATIBILITY LAYER
// Required by payout_scheduler.php and payout_processor.php.
// All functions wrapped in function_exists() — zero naming conflicts.
//
// TABLE SEPARATION — IMPORTANT:
//   seller_balances       = legacy aggregate wallet (pending_balance,
//                           available_balance, held_balance, paid_out_balance,
//                           total_earned_gross, total_platform_fee …).
//                           NEVER touched by any code below this line.
//
//   seller_balance_entries = NEW line-level payout lifecycle table.
//                            One row per seller/order_item.
//                            Tracks: pending → on_hold → available
//                                    → processing → paid_out.
//
// DDL — run once before enabling cron jobs:
//
// CREATE TABLE IF NOT EXISTS `seller_balance_entries` (
//   `id`               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
//   `seller_id`        BIGINT UNSIGNED  NOT NULL,
//   `order_id`         BIGINT UNSIGNED  NOT NULL,
//   `order_item_id`    BIGINT UNSIGNED  DEFAULT NULL,
//   `listing_id`       BIGINT UNSIGNED  DEFAULT NULL,
//   `amount`           DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
//   `currency`         CHAR(3)          NOT NULL DEFAULT 'USD',
//   `status`           ENUM('pending','on_hold','available','processing',
//                           'paid_out','failed','cancelled','frozen')
//                                       NOT NULL DEFAULT 'pending',
//   `risk_score`       INT              NOT NULL DEFAULT 0,
//   `risk_flags_json`  TEXT             DEFAULT NULL,
//   `hold_reason`      VARCHAR(191)     DEFAULT NULL,
//   `release_at`       DATETIME         DEFAULT NULL,
//   `available_at`     DATETIME         DEFAULT NULL,
//   `paid_out_at`      DATETIME         DEFAULT NULL,
//   `payout_id`        BIGINT UNSIGNED  DEFAULT NULL,
//   `source`           VARCHAR(50)      NOT NULL DEFAULT 'order_paid',
//   `created_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP(),
//   `updated_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP()
//                                       ON UPDATE CURRENT_TIMESTAMP(),
//   PRIMARY KEY (`id`),
//   UNIQUE KEY `uniq_seller_balance_entry_item` (`seller_id`,`order_id`,`order_item_id`),
//   KEY `idx_seller_balance_entries_status_release` (`status`,`release_at`),
//   KEY `idx_seller_balance_entries_seller_status`  (`seller_id`,`status`),
//   KEY `idx_seller_balance_entries_order`          (`order_id`)
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
//
// ===========================================================================

// ---------------------------------------------------------------------------
// PDO alias
// ---------------------------------------------------------------------------
if (!function_exists('bv_seller_balance_db')) {
    function bv_seller_balance_db(): PDO
    {
        return bv_seller_balance_pdo();
    }
}

// ---------------------------------------------------------------------------
// Timestamp
// ---------------------------------------------------------------------------
if (!function_exists('bv_seller_balance_now')) {
    function bv_seller_balance_now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

// ---------------------------------------------------------------------------
// File log
// ---------------------------------------------------------------------------
if (!function_exists('_bvsbe_log_path')) {
    function _bvsbe_log_path(): string
    {
        $private = dirname(__DIR__, 2) . '/private_html/logs';
        if (is_dir($private) && is_writable($private)) {
            return $private . '/seller_balance.log';
        }
        $pub = dirname(__DIR__) . '/logs';
        if (!is_dir($pub)) {
            @mkdir($pub, 0775, true);
        }
        return $pub . '/seller_balance.log';
    }
}

if (!function_exists('bv_seller_balance_log')) {
    function bv_seller_balance_log(string $event, array $context = []): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $event . ' '
            . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        @file_put_contents(_bvsbe_log_path(), $line, FILE_APPEND | LOCK_EX);
        @error_log(trim($line));
    }
}

// ---------------------------------------------------------------------------
// Schema helpers — thin wrappers over existing _bv_sb_* internals
// ---------------------------------------------------------------------------
if (!function_exists('bv_seller_balance_table_exists')) {
    function bv_seller_balance_table_exists(PDO $pdo, string $table): bool
    {
        return _bv_sb_table_exists($pdo, $table);
    }
}

if (!function_exists('bv_seller_balance_has_col')) {
    function bv_seller_balance_has_col(PDO $pdo, string $table, string $col): bool
    {
        return _bv_sb_column_exists($pdo, $table, $col);
    }
}

// ---------------------------------------------------------------------------
// Transaction helpers
// ---------------------------------------------------------------------------
if (!function_exists('bv_seller_balance_begin')) {
    function bv_seller_balance_begin(PDO $pdo): void
    {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }
    }
}

if (!function_exists('bv_seller_balance_commit')) {
    function bv_seller_balance_commit(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    }
}

if (!function_exists('bv_seller_balance_rollback')) {
    function bv_seller_balance_rollback(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

// ---------------------------------------------------------------------------
// Structured event log (writes to seller_payout_logs if table exists)
// seller_balance_id column references seller_balance_entries.id logically;
// no FK is enforced so the log table is safe even if entries are missing.
// ---------------------------------------------------------------------------
if (!function_exists('_bvsbe_write_log')) {
    function _bvsbe_write_log(
        PDO $pdo,
        string $event,
        string $message = '',
        array $context = [],
        ?int $sellerId = null,
        ?int $payoutId = null,
        ?int $entryId = null,
        ?int $orderId = null
    ): void {
        if (!_bv_sb_table_exists($pdo, 'seller_payout_logs')) {
            bv_seller_balance_log($event, array_merge($context, [
                'seller_id' => $sellerId,
                'payout_id' => $payoutId,
                'entry_id'  => $entryId,
                'order_id'  => $orderId,
                'message'   => $message,
            ]));
            return;
        }
        try {
            $pdo->prepare(
                'INSERT INTO seller_payout_logs
                 (seller_id, payout_id, seller_balance_id, order_id,
                  event, message, context_json, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $sellerId,
                $payoutId,
                $entryId,
                $orderId,
                substr($event, 0, 80),
                $message ?: null,
                $context
                    ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                bv_seller_balance_now(),
            ]);
        } catch (Throwable $e) {
            bv_seller_balance_log($event, array_merge($context, [
                'log_write_error' => $e->getMessage(),
            ]));
        }
    }
}

// ---------------------------------------------------------------------------
// bv_seller_balance_release_eligible()
//
// Moves seller_balance_entries rows: pending/on_hold → available.
// Fulfillment safety: only releases when fulfillment_status is explicitly
// shipped/completed/delivered. Empty or NULL blocks release.
// NEVER reads or writes to legacy seller_balances.
// ---------------------------------------------------------------------------
if (!function_exists('bv_seller_balance_release_eligible')) {
    function bv_seller_balance_release_eligible(PDO $pdo, int $limit = 100): array
    {
        $result = ['released' => 0, 'skipped' => 0, 'balance_ids' => []];

        if (!_bv_sb_table_exists($pdo, 'seller_balance_entries')) {
            return $result;
        }

        $now = bv_seller_balance_now();

        $stmt = $pdo->prepare(
            "SELECT sbe.*
             FROM seller_balance_entries sbe
             WHERE sbe.status IN ('pending','on_hold')
               AND sbe.release_at <= ?
             ORDER BY sbe.release_at ASC
             LIMIT " . max(1, $limit)
        );
        $stmt->execute([$now]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (!$rows) {
            return $result;
        }

        $hasOrderItems        = _bv_sb_table_exists($pdo, 'order_items');
        $hasFulfillmentStatus = $hasOrderItems
            && _bv_sb_column_exists($pdo, 'order_items', 'fulfillment_status');
        $hasOrders            = _bv_sb_table_exists($pdo, 'orders');
        $hasOrderRefunds      = _bv_sb_table_exists($pdo, 'order_refunds');

        foreach ($rows as $row) {
            $entryId  = (int) $row['id'];
            $orderId  = (int) $row['order_id'];
            $itemId   = (int) ($row['order_item_id'] ?? 0);
            $sellerId = (int) $row['seller_id'];

            $status = strtolower($row['status'] ?? '');
            if (in_array($status, ['frozen', 'cancelled', 'paid_out', 'available', 'processing'], true)) {
                $result['skipped']++;
                continue;
            }

            // Fulfillment safety: require explicit shipped/completed/delivered.
            // Empty or NULL fulfillment_status = not yet shipped → block release.
            if ($hasFulfillmentStatus && $itemId > 0) {
                $stmtFs = $pdo->prepare(
                    'SELECT fulfillment_status
                     FROM order_items
                     WHERE id = ? AND order_id = ? LIMIT 1'
                );
                $stmtFs->execute([$itemId, $orderId]);
                $fs = strtolower(trim((string) $stmtFs->fetchColumn()));
                if (!in_array($fs, ['shipped', 'completed', 'delivered'], true)) {
                    $result['skipped']++;
                    continue;
                }
            } elseif ($hasOrders) {
                // Fallback: check order status when no item-level tracking
                $stmtOs = $pdo->prepare(
                    'SELECT status FROM orders WHERE id = ? LIMIT 1'
                );
                $stmtOs->execute([$orderId]);
                $os = strtolower(trim((string) $stmtOs->fetchColumn()));
                if (!in_array($os, ['paid', 'confirmed', 'completed', 'shipped', 'delivered'], true)) {
                    $result['skipped']++;
                    continue;
                }
            }

            // Block release when an active refund exists for this order
            if ($hasOrderRefunds) {
                try {
                    $blockStatuses = [
                        'draft', 'pending_approval', 'partially_approved',
                        'approved', 'processing', 'partially_refunded', 'pending',
                    ];
                    $ph      = implode(',', array_fill(0, count($blockStatuses), '?'));
                    $stmtRef = $pdo->prepare(
                        "SELECT COUNT(*) FROM order_refunds
                         WHERE order_id = ? AND status IN ({$ph})"
                    );
                    $stmtRef->execute(array_merge([$orderId], $blockStatuses));
                    if ((int) $stmtRef->fetchColumn() > 0) {
                        $result['skipped']++;
                        continue;
                    }
                } catch (Throwable $e) {
                    // ignore schema mismatch
                }
            }

            try {
                $upd = $pdo->prepare(
                    "UPDATE seller_balance_entries
                     SET status = 'available', available_at = ?, updated_at = ?
                     WHERE id = ? AND status IN ('pending','on_hold')"
                );
                $upd->execute([$now, $now, $entryId]);

                if ($upd->rowCount() > 0) {
                    $result['released']++;
                    $result['balance_ids'][] = $entryId;
                    _bvsbe_write_log(
                        $pdo, 'entry_released',
                        "Entry {$entryId} released to available",
                        [],
                        $sellerId, null, $entryId, $orderId
                    );
                } else {
                    $result['skipped']++;
                }
            } catch (Throwable $e) {
                bv_seller_balance_log('entry_release_error', [
                    'entry_id' => $entryId,
                    'error'    => $e->getMessage(),
                ]);
                $result['skipped']++;
            }
        }

        return $result;
    }
}

// ---------------------------------------------------------------------------
// bv_seller_balance_build_payout_queue()
//
// Groups available seller_balance_entries rows into payout queue batches.
// Idempotency key = md5 of sorted entry IDs (not date):
//   same set → same key (safe retry); new set → new key (no blocking).
// NEVER reads or writes to legacy seller_balances.
// ---------------------------------------------------------------------------
if (!function_exists('bv_seller_balance_build_payout_queue')) {
    function bv_seller_balance_build_payout_queue(PDO $pdo, int $limitSellers = 50): array
    {
        $result = ['created' => 0, 'payout_ids' => []];

        if (
            !_bv_sb_table_exists($pdo, 'seller_balance_entries') ||
            !_bv_sb_table_exists($pdo, 'seller_payout_queue')    ||
            !_bv_sb_table_exists($pdo, 'seller_payout_items')
        ) {
            return $result;
        }

        $now = bv_seller_balance_now();

        // Aggregate available entries per seller + currency
        $stmtAgg = $pdo->prepare(
            "SELECT seller_id, currency, SUM(amount) AS total_amount
             FROM seller_balance_entries
             WHERE status = 'available'
             GROUP BY seller_id, currency
             HAVING total_amount > 0
             ORDER BY total_amount DESC
             LIMIT " . max(1, $limitSellers)
        );
        $stmtAgg->execute();
        $groups = $stmtAgg->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (!$groups) {
            return $result;
        }

        foreach ($groups as $group) {
            $sellerId = (int) $group['seller_id'];
            $currency = (string) $group['currency'];

            if ($sellerId <= 0) {
                continue;
            }

            try {
                bv_seller_balance_begin($pdo);

                // Lock the available entries for this seller + currency
                $stmtEntries = $pdo->prepare(
                    "SELECT * FROM seller_balance_entries
                     WHERE seller_id = ? AND currency = ? AND status = 'available'
                     ORDER BY id ASC
                     FOR UPDATE"
                );
                $stmtEntries->execute([$sellerId, $currency]);
                $entries = $stmtEntries->fetchAll(PDO::FETCH_ASSOC) ?: [];

                if (!$entries) {
                    bv_seller_balance_rollback($pdo);
                    continue;
                }

                $actualAmount = round(array_sum(array_column($entries, 'amount')), 2);
                if ($actualAmount <= 0) {
                    bv_seller_balance_rollback($pdo);
                    continue;
                }

                // Deterministic idempotency key from sorted entry IDs.
                // Same set → same key (safe retry). New entry added → new hash.
                $entryIds = array_map(static fn($e) => (int) $e['id'], $entries);
                sort($entryIds);
                $idemKey = 'payout:' . $sellerId . ':' . strtolower($currency)
                    . ':' . md5(implode(',', $entryIds));

                $stmtIns = $pdo->prepare(
                    "INSERT INTO seller_payout_queue
                     (seller_id, amount, currency, status, provider,
                      idempotency_key, scheduled_at, created_at, updated_at)
                     VALUES (?, ?, ?, 'pending', 'manual', ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE id = id"
                );
                $stmtIns->execute([
                    $sellerId, $actualAmount, $currency,
                    $idemKey, $now, $now, $now,
                ]);

                $payoutId = (int) $pdo->lastInsertId();

                if ($payoutId <= 0) {
                    // Exact same set already queued
                    bv_seller_balance_rollback($pdo);
                    continue;
                }

                // Link each entry to the payout and mark it processing
                foreach ($entries as $entry) {
                    $entryId  = (int) $entry['id'];
                    $entryAmt = (float) $entry['amount'];
                    $entryOid = (int) $entry['order_id'];
                    $entryIid = isset($entry['order_item_id'])
                        ? (int) $entry['order_item_id']
                        : null;

                    $pdo->prepare(
                        'INSERT IGNORE INTO seller_payout_items
                         (payout_id, seller_balance_id, seller_id, order_id,
                          order_item_id, amount, currency, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $payoutId, $entryId, $sellerId,
                        $entryOid, $entryIid, $entryAmt, $currency, $now,
                    ]);

                    // seller_balance_id in seller_payout_items = seller_balance_entries.id
                    $pdo->prepare(
                        "UPDATE seller_balance_entries
                         SET status = 'processing', payout_id = ?, updated_at = ?
                         WHERE id = ? AND status = 'available'"
                    )->execute([$payoutId, $now, $entryId]);
                }

                bv_seller_balance_commit($pdo);

                $result['created']++;
                $result['payout_ids'][] = $payoutId;

                _bvsbe_write_log(
                    $pdo, 'payout_queue_created',
                    "Payout {$payoutId} queued for seller {$sellerId}: {$actualAmount} {$currency}",
                    ['amount' => $actualAmount, 'entry_count' => count($entries)],
                    $sellerId, $payoutId
                );
            } catch (Throwable $e) {
                bv_seller_balance_rollback($pdo);
                bv_seller_balance_log('payout_queue_error', [
                    'seller_id' => $sellerId,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }
}

// ---------------------------------------------------------------------------
// bv_seller_balance_mark_payout_success()
//
// Called only after real external confirmation (admin or webhook).
// Marks seller_balance_entries rows as paid_out.
// NEVER called automatically — payout_processor.php requires manual confirm.
// NEVER touches legacy seller_balances.
// ---------------------------------------------------------------------------
if (!function_exists('bv_seller_balance_mark_payout_success')) {
    function bv_seller_balance_mark_payout_success(
        PDO $pdo,
        int $payoutId,
        string $providerReference = ''
    ): array {
        $result = ['ok' => false, 'payout_id' => $payoutId];

        if (!_bv_sb_table_exists($pdo, 'seller_payout_queue')) {
            return $result;
        }

        $now = bv_seller_balance_now();

        try {
            bv_seller_balance_begin($pdo);

            $stmtPay = $pdo->prepare(
                'SELECT * FROM seller_payout_queue WHERE id = ? LIMIT 1 FOR UPDATE'
            );
            $stmtPay->execute([$payoutId]);
            $payout = $stmtPay->fetch(PDO::FETCH_ASSOC);

            if (!$payout) {
                throw new RuntimeException("Payout {$payoutId} not found.");
            }

            $status = strtolower($payout['status'] ?? '');
            if (!in_array($status, ['pending', 'processing'], true)) {
                throw new RuntimeException(
                    "Payout {$payoutId} status '{$status}' cannot be marked success."
                );
            }

            $pdo->prepare(
                "UPDATE seller_payout_queue
                 SET status = 'success',
                     provider_reference = ?,
                     processed_at = ?,
                     updated_at = ?
                 WHERE id = ?"
            )->execute([$providerReference ?: null, $now, $now, $payoutId]);

            // Mark the linked seller_balance_entries rows as paid_out
            if (_bv_sb_table_exists($pdo, 'seller_payout_items')) {
                $stmtItems = $pdo->prepare(
                    'SELECT seller_balance_id FROM seller_payout_items WHERE payout_id = ?'
                );
                $stmtItems->execute([$payoutId]);
                foreach ($stmtItems->fetchAll(PDO::FETCH_COLUMN) ?: [] as $entryId) {
                    $pdo->prepare(
                        "UPDATE seller_balance_entries
                         SET status = 'paid_out', paid_out_at = ?, updated_at = ?
                         WHERE id = ? AND status IN ('processing','available')"
                    )->execute([$now, $now, (int) $entryId]);
                }
            }

            bv_seller_balance_commit($pdo);

            $sellerId = (int) ($payout['seller_id'] ?? 0);
            _bvsbe_write_log(
                $pdo, 'payout_success',
                "Payout {$payoutId} marked success. Ref: {$providerReference}",
                ['provider_reference' => $providerReference],
                $sellerId, $payoutId
            );

            $result['ok']        = true;
            $result['seller_id'] = $sellerId;
        } catch (Throwable $e) {
            bv_seller_balance_rollback($pdo);
            bv_seller_balance_log('payout_success_error', [
                'payout_id' => $payoutId,
                'error'     => $e->getMessage(),
            ]);
            $result['error'] = $e->getMessage();
        }

        return $result;
    }
}

// ---------------------------------------------------------------------------
// bv_seller_balance_mark_payout_failed()
//
// Returns linked seller_balance_entries rows to available (retryable).
// NEVER touches legacy seller_balances.
// ---------------------------------------------------------------------------
if (!function_exists('bv_seller_balance_mark_payout_failed')) {
    function bv_seller_balance_mark_payout_failed(
        PDO $pdo,
        int $payoutId,
        string $reason
    ): array {
        $result = ['ok' => false, 'payout_id' => $payoutId];

        if (!_bv_sb_table_exists($pdo, 'seller_payout_queue')) {
            return $result;
        }

        $now = bv_seller_balance_now();

        try {
            bv_seller_balance_begin($pdo);

            $stmtPay = $pdo->prepare(
                'SELECT * FROM seller_payout_queue WHERE id = ? LIMIT 1 FOR UPDATE'
            );
            $stmtPay->execute([$payoutId]);
            $payout = $stmtPay->fetch(PDO::FETCH_ASSOC);

            if (!$payout) {
                throw new RuntimeException("Payout {$payoutId} not found.");
            }

            $pdo->prepare(
                "UPDATE seller_payout_queue
                 SET status = 'failed',
                     failure_reason = ?,
                     failed_at = ?,
                     updated_at = ?
                 WHERE id = ?"
            )->execute([$reason, $now, $now, $payoutId]);

            // Return linked seller_balance_entries rows to available so they can be retried
            if (_bv_sb_table_exists($pdo, 'seller_payout_items')) {
                $stmtItems = $pdo->prepare(
                    'SELECT seller_balance_id FROM seller_payout_items WHERE payout_id = ?'
                );
                $stmtItems->execute([$payoutId]);
                foreach ($stmtItems->fetchAll(PDO::FETCH_COLUMN) ?: [] as $entryId) {
                    $pdo->prepare(
                        "UPDATE seller_balance_entries
                         SET status = 'available', payout_id = NULL, updated_at = ?
                         WHERE id = ? AND status = 'processing'"
                    )->execute([$now, (int) $entryId]);
                }
            }

            bv_seller_balance_commit($pdo);

            $sellerId = (int) ($payout['seller_id'] ?? 0);
            _bvsbe_write_log(
                $pdo, 'payout_failed',
                "Payout {$payoutId} marked failed. Reason: {$reason}",
                ['reason' => $reason],
                $sellerId, $payoutId
            );

            $result['ok'] = true;
        } catch (Throwable $e) {
            bv_seller_balance_rollback($pdo);
            bv_seller_balance_log('payout_failed_error', [
                'payout_id' => $payoutId,
                'error'     => $e->getMessage(),
            ]);
            $result['error'] = $e->getMessage();
        }

        return $result;
    }
}

// ---------------------------------------------------------------------------
// bv_seller_balance_freeze_seller()  /  bv_seller_balance_unfreeze_seller()
//
// Targets seller_balance_entries only. NEVER touches legacy seller_balances.
// ---------------------------------------------------------------------------
if (!function_exists('bv_seller_balance_freeze_seller')) {
    function bv_seller_balance_freeze_seller(
        PDO $pdo,
        int $sellerId,
        string $reason
    ): array {
        $result = ['ok' => false, 'frozen' => 0, 'seller_id' => $sellerId];

        if (!_bv_sb_table_exists($pdo, 'seller_balance_entries') || $sellerId <= 0) {
            return $result;
        }

        $now = bv_seller_balance_now();

        try {
            $stmt = $pdo->prepare(
                "UPDATE seller_balance_entries
                 SET status = 'frozen', hold_reason = ?, updated_at = ?
                 WHERE seller_id = ? AND status IN ('pending','on_hold','available')"
            );
            $stmt->execute([$reason, $now, $sellerId]);
            $frozen = (int) $stmt->rowCount();

            _bvsbe_write_log(
                $pdo, 'seller_frozen',
                "Seller {$sellerId} frozen. {$frozen} entries. Reason: {$reason}",
                ['reason' => $reason, 'frozen_count' => $frozen],
                $sellerId
            );

            $result['ok']     = true;
            $result['frozen'] = $frozen;
        } catch (Throwable $e) {
            bv_seller_balance_log('freeze_seller_error', [
                'seller_id' => $sellerId,
                'error'     => $e->getMessage(),
            ]);
            $result['error'] = $e->getMessage();
        }

        return $result;
    }
}

if (!function_exists('bv_seller_balance_unfreeze_seller')) {
    function bv_seller_balance_unfreeze_seller(PDO $pdo, int $sellerId): array
    {
        $result = ['ok' => false, 'unfrozen' => 0, 'seller_id' => $sellerId];

        if (!_bv_sb_table_exists($pdo, 'seller_balance_entries') || $sellerId <= 0) {
            return $result;
        }

        $now        = bv_seller_balance_now();
        $newRelease = date('Y-m-d H:i:s', strtotime('+1 day'));

        try {
            $stmt = $pdo->prepare(
                "UPDATE seller_balance_entries
                 SET status = 'on_hold', hold_reason = NULL, release_at = ?, updated_at = ?
                 WHERE seller_id = ? AND status = 'frozen'"
            );
            $stmt->execute([$newRelease, $now, $sellerId]);
            $unfrozen = (int) $stmt->rowCount();

            _bvsbe_write_log(
                $pdo, 'seller_unfrozen',
                "Seller {$sellerId} unfrozen. {$unfrozen} entries returned to on_hold.",
                ['unfrozen_count' => $unfrozen, 'new_release_at' => $newRelease],
                $sellerId
            );

            $result['ok']       = true;
            $result['unfrozen'] = $unfrozen;
        } catch (Throwable $e) {
            bv_seller_balance_log('unfreeze_seller_error', [
                'seller_id' => $sellerId,
                'error'     => $e->getMessage(),
            ]);
            $result['error'] = $e->getMessage();
        }

        return $result;
    }
}
