<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
// require_once __DIR__ . '/_guard.php';
require_once dirname(__DIR__) . '/includes/seller_balance.php';

// — Admin auth ---------------------------------------------------------------
$role = strtolower(trim((string)(
    $_SESSION['user']['role']
    ?? $_SESSION['auth_user']['role']
    ?? $_SESSION['admin']['role']
    ?? $_SESSION['user_role']
    ?? $_SESSION['admin_role']
    ?? $_SESSION['role']
    ?? ''
)));

if (in_array($role, ['admin', 'super_admin', 'superadmin', 'supper_admin', 'owner'], true)) {
    $role = 'admin';
}

$adminId = (int)(
    $_SESSION['user']['id']
    ?? $_SESSION['auth_user']['id']
    ?? $_SESSION['admin']['id']
    ?? $_SESSION['user_id']
    ?? $_SESSION['admin_id']
    ?? 1
);

if ($role !== 'admin') {
    http_response_code(403);
    exit('Access denied: admin only.');
}



// ── POST only ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bv_sb_redirect('seller_payouts.php');
}

// ── CSRF ──────────────────────────────────────────────────────────────────
$csrfToken = (string)($_POST['csrf_token'] ?? '');
if (!bv_seller_balance_verify_csrf($csrfToken, 'admin_payout_action')) {
    bv_sb_flash_set('error', 'Security token mismatch. Please try again.');
    bv_sb_redirect('seller_payouts.php');
}

$action     = trim((string)($_POST['action'] ?? ''));
$payoutId   = (int)($_POST['payout_id'] ?? 0);
$sellerId   = (int)($_POST['seller_id'] ?? 0);
$note       = trim((string)($_POST['note'] ?? ''));
$returnUrl  = 'seller_payouts.php';

// ── Dispatch ──────────────────────────────────────────────────────────────
switch ($action) {

    // ── APPROVE ───────────────────────────────────────────────────────────
    case 'approve':
        if ($payoutId <= 0) {
            bv_sb_flash_set('error', 'Invalid payout ID.');
            bv_sb_redirect($returnUrl);
        }
        try {
            $ok = bv_seller_balance_approve_payout($payoutId, $adminId, $note);
            if (is_array($ok) && !empty($ok['already_processed'])) {
                bv_sb_flash_set('error', 'This payout has already been processed.');
            } elseif ($ok) {
                bv_sb_flash_set('success', 'Payout #' . $payoutId . ' approved.');
            } else {
                bv_sb_flash_set('error', 'Could not approve payout #' . $payoutId . '. It may already be processed or not exist.');
            }
        } catch (Throwable $e) {
            bv_sb_flash_set('error', 'Approve failed: ' . $e->getMessage());
        }
        bv_sb_redirect($returnUrl);

    // ── REJECT ────────────────────────────────────────────────────────────
    case 'reject':
        if ($payoutId <= 0) {
            bv_sb_flash_set('error', 'Invalid payout ID.');
            bv_sb_redirect($returnUrl);
        }
        if ($note === '') {
            bv_sb_flash_set('error', 'A reason is required when rejecting a payout.');
            bv_sb_redirect($returnUrl);
        }
        try {
            $ok = bv_seller_balance_reject_payout($payoutId, $adminId, $note);
            if ($ok) {
                bv_sb_flash_set('success', 'Payout #' . $payoutId . ' rejected. Funds returned to seller available balance.');
            } else {
                bv_sb_flash_set('error', 'Could not reject payout #' . $payoutId . '. Check status.');
            }
        } catch (Throwable $e) {
            bv_sb_flash_set('error', 'Reject failed: ' . $e->getMessage());
        }
        bv_sb_redirect($returnUrl);

    // ── CANCEL (same mechanics as reject but different label) ─────────────
    case 'cancel':
        if ($payoutId <= 0) {
            bv_sb_flash_set('error', 'Invalid payout ID.');
            bv_sb_redirect($returnUrl);
        }
        try {
            $ok = bv_seller_balance_cancel_payout($payoutId, $adminId, $note ?: 'Cancelled by admin');
            if (is_array($ok) && !empty($ok['already_processed'])) {
                bv_sb_flash_set('error', 'This payout has already been processed.');
            } elseif ($ok) {
                bv_sb_flash_set('success', 'Payout #' . $payoutId . ' cancelled. Funds returned to seller available balance.');
            } else {
                bv_sb_flash_set('error', 'Could not cancel payout #' . $payoutId . '. Check status.');
            }
        } catch (Throwable $e) {
            bv_sb_flash_set('error', 'Cancel failed: ' . $e->getMessage());
        }
        bv_sb_redirect($returnUrl);

    // ── MARK PAID ─────────────────────────────────────────────────────────
    case 'mark_paid':
        if ($payoutId <= 0) {
            bv_sb_flash_set('error', 'Invalid payout ID.');
            bv_sb_redirect($returnUrl);
        }
        $paymentRef    = trim((string)($_POST['payment_reference'] ?? ''));
        $paymentMethod = trim((string)($_POST['payment_method'] ?? 'bank_transfer'));

 
        $allowedMethods = ['bank_transfer','promptpay','wise','other'];
		$allowedMethods = ['bank_transfer','promptpay','wise','other','manual'];
        if (!in_array($paymentMethod, $allowedMethods, true)) {
            $paymentMethod = 'bank_transfer';
        }

       if ($paymentRef === '' && !in_array($paymentMethod, ['manual','other'], true)) {
            bv_sb_flash_set('error', 'Payment reference is required unless method is manual/other with admin note.');
            bv_sb_redirect($returnUrl);
        }
        if ($paymentRef === '' && in_array($paymentMethod, ['manual','other'], true) && $note === '') {
            bv_sb_flash_set('error', 'Admin note is required for manual/other payouts without payment reference.');
            bv_sb_redirect($returnUrl);
        }
		
        try {
            $ok = bv_seller_balance_mark_payout_paid(
                $payoutId,
                $adminId,
                $paymentRef,
                $paymentMethod,
                $note
            );
           if (is_array($ok) && !empty($ok['already_processed'])) {
                bv_sb_flash_set('error', 'This payout has already been processed.');
            } elseif ($ok) {
                bv_sb_flash_set('success',
                    'Payout #' . $payoutId . ' marked as PAID. Ref: ' . $paymentRef
                );
            } else {
                bv_sb_flash_set('error',
                    'Could not mark payout #' . $payoutId . ' as paid. Check status and try again.'
                );
            }
        } catch (Throwable $e) {
            bv_sb_flash_set('error', 'Mark paid failed: ' . $e->getMessage());
        }
        bv_sb_redirect($returnUrl);

    // ── RELEASE PENDING ───────────────────────────────────────────────────
    case 'release_pending':
        if ($sellerId <= 0) {
            bv_sb_flash_set('error', 'Invalid seller ID.');
            bv_sb_redirect($returnUrl);
        }

        try {
            $released = bv_seller_balance_release_pending_for_seller($sellerId);
            if ($released > 0) {
                bv_sb_flash_set('success',
                    'Released ' . $released . ' eligible pending item(s) for seller #' . $sellerId . '.'
                );
            } else {
                bv_sb_flash_set('error',
                    'No eligible pending balance is ready for release.'
                );
            }
        } catch (Throwable $e) {
            bv_sb_flash_set('error', 'Release failed: ' . $e->getMessage());
        }
        bv_sb_redirect($returnUrl);

    // ── MANUAL ADJUSTMENT ─────────────────────────────────────────────────
    case 'adjust':
        if ($sellerId <= 0) {
            bv_sb_flash_set('error', 'Invalid seller ID.');
            bv_sb_redirect($returnUrl);
        }

        $amount    = (float)($_POST['amount'] ?? 0);
        $direction = trim((string)($_POST['direction'] ?? ''));

        if ($amount <= 0) {
            bv_sb_flash_set('error', 'Adjustment amount must be greater than zero.');
            bv_sb_redirect($returnUrl);
        }

        if (!in_array($direction, ['credit','debit'], true)) {
            bv_sb_flash_set('error', 'Invalid direction. Must be credit or debit.');
            bv_sb_redirect($returnUrl);
        }

        if ($note === '') {
            bv_sb_flash_set('error', 'A reason note is required for manual balance adjustment.');
            bv_sb_redirect($returnUrl);
        }

        try {
            bv_seller_balance_admin_adjust($sellerId, $amount, $direction, $note, $adminId);
            bv_sb_flash_set('success',
                ucfirst($direction) . ' adjustment of $' . number_format($amount, 2)
                . ' applied to seller #' . $sellerId . '.'
            );
        } catch (Throwable $e) {
            bv_sb_flash_set('error', 'Adjustment failed: ' . $e->getMessage());
        }
        bv_sb_redirect($returnUrl);

    // ── UNKNOWN ACTION ────────────────────────────────────────────────────
    default:
        bv_sb_flash_set('error', 'Unknown action: ' . bv_sb_e($action));
        bv_sb_redirect($returnUrl);
}
