<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

require_once dirname(__DIR__) . '/includes/seller_balance.php';

// ── Auth: must be logged-in seller ────────────────────────────────────────
$userId = bv_seller_balance_current_user_id();
if ($userId <= 0) {
    bv_sb_redirect('../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/seller/balance.php'));
}
if (!bv_seller_balance_is_seller()) {
    http_response_code(403);
    exit('Access denied: seller account required.');
}

// ── Guard: migration must have run ────────────────────────────────────────
if (!bv_seller_balance_tables_exist()) {
    http_response_code(503);
    exit('Seller balance system is not yet available. Please contact the administrator.');
}

// seller_id = users.id (sellers table does not exist separately)
$sellerId = $userId;

// ── Data ──────────────────────────────────────────────────────────────────
$balance       = bv_seller_balance_ensure($sellerId);
$ledger        = bv_seller_balance_get_ledger($sellerId, 30, 0);
$payoutRequests= bv_seller_balance_get_payout_requests($sellerId, '', 10, 0);
$flash         = bv_sb_flash_get();

$currency = (string)($balance['currency'] ?? 'USD');

// Type labels for display
$typeLabels = [
    'earning'           => 'Sale Earning',
    'platform_fee'      => 'Platform Fee',
    'pending_release'   => 'Cleared to Available',
    'refund_hold'       => 'Refund Hold',
    'refund_deduction'  => 'Refund Deduction',
    'adjustment_credit' => 'Admin Credit',
    'adjustment_debit'  => 'Admin Debit',
    'payout_request'    => 'Payout Requested',
    'payout_paid'       => 'Payout Paid',
    'payout_cancelled'  => 'Payout Cancelled',
    'tax_withholding'   => 'Tax Withholding',
];

$statusColors = [
    'requested' => '#d8b56b',
    'approved'  => '#5bc08a',
    'rejected'  => '#e06c6c',
    'paid'      => '#5bc08a',
    'cancelled' => '#8ea29a',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Balance — Bettavaro Seller</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0a1810;color:#e7ddca;font-size:14px;line-height:1.6}
a{color:#d8b56b;text-decoration:none}a:hover{text-decoration:underline}
.wrap{max-width:1100px;margin:0 auto;padding:32px 16px 60px}
.page-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:28px}
.page-title{font-size:26px;font-weight:900;color:#f3efe6}
.btn{display:inline-flex;align-items:center;gap:6px;min-height:40px;padding:0 18px;border-radius:12px;font-weight:800;font-size:13px;cursor:pointer;border:1px solid transparent;transition:.15s}
.btn-gold{background:#d8b56b;color:#182018}.btn-gold:hover{background:#c9a45c}
.btn-outline{background:transparent;color:#e7ddca;border-color:rgba(229,201,138,.34)}.btn-outline:hover{border-color:#d8b56b;color:#d8b56b}
.flash{padding:13px 16px;border-radius:14px;font-size:13px;margin-bottom:20px}
.flash-success{background:rgba(64,166,103,.14);border:1px solid rgba(64,166,103,.26);color:#c7f0d5}
.flash-error{background:rgba(214,92,92,.14);border:1px solid rgba(214,92,92,.26);color:#ffd5d5}
.balance-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:28px}
.bal-card{border-radius:20px;padding:20px 18px;border:1px solid rgba(255,255,255,.09);background:rgba(255,255,255,.03)}
.bal-label{font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:#8ea29a;font-weight:700;margin-bottom:8px}
.bal-amount{font-size:22px;font-weight:900;color:#f3efe6;letter-spacing:-.5px}
.bal-sub{font-size:11px;color:#6b8070;margin-top:4px}
.bal-card.available{border-color:rgba(91,192,138,.28);background:rgba(91,192,138,.06)}
.bal-card.available .bal-amount{color:#5bc08a}
.section{border-radius:20px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.025);margin-bottom:22px;overflow:hidden}
.section-head{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid rgba(255,255,255,.06)}
.section-title{font-size:13px;font-weight:900;text-transform:uppercase;letter-spacing:.07em;color:#e0c98e}
.table{width:100%;border-collapse:collapse}
.table th{padding:11px 14px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#8ea29a;border-bottom:1px solid rgba(255,255,255,.06)}
.table td{padding:11px 14px;border-bottom:1px solid rgba(255,255,255,.04);font-size:13px;color:#e7ddca;vertical-align:middle}
.table tr:last-child td{border-bottom:none}
.table tr:hover td{background:rgba(255,255,255,.02)}
.badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700}
.badge-pending{background:rgba(216,181,107,.15);color:#e0c98e}
.badge-approved{background:rgba(91,192,138,.15);color:#5bc08a}
.badge-paid{background:rgba(91,192,138,.18);color:#5bc08a}
.badge-rejected{background:rgba(224,108,108,.15);color:#e06c6c}
.badge-cancelled{background:rgba(142,162,154,.12);color:#8ea29a}
.dir-credit{color:#5bc08a;font-weight:700}
.dir-debit{color:#e06c6c;font-weight:700}
.dir-none{color:#8ea29a}
.empty{padding:40px;text-align:center;color:#6b8070;font-size:13px}
.info-box{margin:20px;padding:14px 16px;border-radius:14px;background:rgba(216,181,107,.06);border:1px solid rgba(216,181,107,.16);color:#d6c89e;font-size:13px;line-height:1.6}
@media(max-width:760px){.balance-grid{grid-template-columns:repeat(2,1fr)}.table td,.table th{padding:9px 10px}}
</style>
</head>
<body>
<div class="wrap">

  <div class="page-header">
    <div class="page-title">💰 My Balance</div>
    <div style="display:flex;gap:10px;align-items:center;">
      <a href="payout_request.php" class="btn btn-gold">Request Payout</a>
      <a href="../member/index.php" class="btn btn-outline">← Dashboard</a>
    </div>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="flash flash-<?= bv_sb_e($flash['type'] ?? 'error') ?>">
      <?= bv_sb_e($flash['message'] ?? '') ?>
    </div>
  <?php endif; ?>

  <!-- Balance Overview -->
  <div class="balance-grid">
    <div class="bal-card">
      <div class="bal-label">Pending</div>
      <div class="bal-amount"><?= bv_sb_e(bv_sb_money((float)($balance['pending_balance'] ?? 0), $currency)) ?></div>
      <div class="bal-sub">Awaiting clearance</div>
    </div>
    <div class="bal-card available">
      <div class="bal-label">Available</div>
      <div class="bal-amount"><?= bv_sb_e(bv_sb_money((float)($balance['available_balance'] ?? 0), $currency)) ?></div>
      <div class="bal-sub">Ready to request</div>
    </div>
    <div class="bal-card">
      <div class="bal-label">Held</div>
      <div class="bal-amount"><?= bv_sb_e(bv_sb_money((float)($balance['held_balance'] ?? 0), $currency)) ?></div>
      <div class="bal-sub">In payout request</div>
    </div>
    <div class="bal-card">
      <div class="bal-label">Total Paid Out</div>
      <div class="bal-amount"><?= bv_sb_e(bv_sb_money((float)($balance['paid_out_balance'] ?? 0), $currency)) ?></div>
      <div class="bal-sub">Lifetime</div>
    </div>
  </div>

  <!-- Info box -->
  <div class="info-box">
    <strong>Pending</strong> = paid order money under clearance/refund window.
    <strong>Available</strong> = ready to request payout.
    <strong>Held</strong> = locked for payout or refund review.
  </div>
  <!-- Payout Requests -->
  <div class="section">
    <div class="section-head">
      <div class="section-title">Payout Requests</div>
      <a href="payout_request.php" class="btn btn-gold" style="min-height:34px;font-size:12px;padding:0 14px">+ New Request</a>
    </div>
    <?php if (empty($payoutRequests)): ?>
      <div class="empty">No payout requests yet.</div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>#</th>
            <th>Date</th>
            <th>Amount</th>
            <th>Method</th>
            <th>Status</th>
            <th>Payment Ref</th>
            <th>Admin Note</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($payoutRequests as $pr): ?>
          <tr>
            <td><?= bv_sb_e((string)$pr['id']) ?></td>
            <td><?= bv_sb_e(substr((string)$pr['requested_at'], 0, 10)) ?></td>
            <td><strong><?= bv_sb_e(bv_sb_money((float)$pr['amount'], (string)$pr['currency'])) ?></strong></td>
            <td><?= bv_sb_e(str_replace('_', ' ', (string)($pr['payout_method'] ?? '—'))) ?></td>
            <td>
              <span class="badge badge-<?= bv_sb_e((string)$pr['status']) ?>">
                <?= bv_sb_e(ucfirst((string)$pr['status'])) ?>
              </span>
            </td>
            <td><?= bv_sb_e((string)($pr['payment_reference'] ?? '—')) ?></td>
            <td style="color:#8ea29a;font-size:12px;">
              <?= bv_sb_e(mb_strimwidth(trim(strip_tags((string)($pr['admin_note'] ?? ''))), 0, 60, '…')) ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- Ledger History -->
  <div class="section">
    <div class="section-head">
      <div class="section-title">Transaction History</div>
    </div>
    <?php if (empty($ledger)): ?>
      <div class="empty">No transactions recorded yet.</div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Type</th>
            <th>Balance</th>
            <th>Amount</th>
            <th>Before</th>
            <th>After</th>
            <th>Note</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ledger as $row): ?>
          <?php
            $dir = (string)($row['direction'] ?? 'none');
            $dirClass = match($dir) {
                'credit' => 'dir-credit',
                'debit'  => 'dir-debit',
                default  => 'dir-none',
            };
            $sign = $dir === 'credit' ? '+' : ($dir === 'debit' ? '−' : '');
            $typeLabel = $typeLabels[(string)$row['type']] ?? ucwords(str_replace('_', ' ', (string)$row['type']));
          ?>
          <tr>
            <td style="white-space:nowrap;font-size:12px;color:#8ea29a;">
              <?= bv_sb_e(substr((string)$row['created_at'], 0, 16)) ?>
            </td>
            <td><?= bv_sb_e($typeLabel) ?></td>
            <td style="font-size:12px;color:#8ea29a;">
              <?= bv_sb_e(ucfirst((string)($row['balance_type'] ?? ''))) ?>
            </td>
            <td class="<?= $dirClass ?>">
              <?= $sign ?><?= bv_sb_e(number_format((float)$row['amount'], 2)) ?>
              <span style="font-size:11px;color:#6b8070;"><?= bv_sb_e((string)$row['currency']) ?></span>
            </td>
            <td style="color:#8ea29a;font-size:12px;">
              <?= bv_sb_e(number_format((float)$row['balance_before'], 2)) ?>
            </td>
            <td style="color:#e7ddca;font-size:12px;">
              <?= bv_sb_e(number_format((float)$row['balance_after'], 2)) ?>
            </td>
            <td style="color:#8ea29a;font-size:12px;max-width:220px;">
              <?= bv_sb_e(mb_strimwidth((string)($row['note'] ?? ''), 0, 60, '…')) ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- Stats footer -->
  <div style="display:flex;gap:20px;flex-wrap:wrap;padding:0 4px;color:#6b8070;font-size:12px;">
    <span>Lifetime gross sales: <strong style="color:#d6c89e;"><?= bv_sb_e(bv_sb_money((float)($balance['total_earned_gross'] ?? 0), $currency)) ?></strong></span>
    <span>Platform fees paid: <strong style="color:#d6c89e;"><?= bv_sb_e(bv_sb_money((float)($balance['total_platform_fee'] ?? 0), $currency)) ?></strong></span>
    <span>Currency: <strong style="color:#d6c89e;"><?= bv_sb_e($currency) ?></strong></span>
  </div>

</div>
</body>
</html>
