<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/seller_balance_release_engine.php';



$isCli = PHP_SAPI === 'cli';
if (!$isCli && !headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

$options = ['dry_run' => true];
$realRunRequested = false;

if ($isCli) {
    global $argv;
    foreach (($argv ?? []) as $arg) {
        if ($arg === '--run') {
            $realRunRequested = true;
        } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
            $options['limit'] = max(1, min(500, (int)$m[1]));
        } elseif (preg_match('/^--seller-id=(\d+)$/', $arg, $m)) {
            $options['seller_id'] = (int)$m[1];
        }
    }
} else {
    $realRunRequested = (string)($_GET['run'] ?? '') === '1';
    if (isset($_GET['limit']) && ctype_digit((string)$_GET['limit'])) {
        $options['limit'] = max(1, min(500, (int)$_GET['limit']));
    }
    if (isset($_GET['seller_id']) && ctype_digit((string)$_GET['seller_id'])) {
        $options['seller_id'] = (int)$_GET['seller_id'];
    }
}

try {
    if ($realRunRequested) {
        if (!$isCli) {
            $expectedToken = (string)(getenv('SELLER_RELEASE_CRON_TOKEN') ?: '');
            $providedToken = (string)($_GET['token'] ?? '');
            if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
                http_response_code(403);
                bv_seller_release_log('cron_blocked', ['reason' => 'missing_or_invalid_token', 'mode' => 'web']);
                echo json_encode([
                    'ok' => false,
                    'dry_run' => true,
                    'checked' => 0,
                    'released' => 0,
                    'blocked' => 0,
                    'errors' => [['error' => 'Real web run requires SELLER_RELEASE_CRON_TOKEN and a matching token parameter.']],
                    'items' => [],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
                exit;
            }
        }
        $options['dry_run'] = false;
    }

    bv_seller_release_log('cron_started', ['dry_run' => (bool)$options['dry_run'], 'mode' => $isCli ? 'cli' : 'web', 'options' => $options]);
    $result = bv_seller_release_run($options);
    bv_seller_release_log('cron_completed', ['dry_run' => (bool)$result['dry_run'], 'ok' => (bool)$result['ok'], 'checked' => (int)$result['checked'], 'released' => (int)$result['released'], 'blocked' => (int)$result['blocked'], 'errors' => count($result['errors'])]);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    http_response_code(500);
    bv_seller_release_log('cron_error', ['error' => $e->getMessage()]);
    echo json_encode([
        'ok' => false,
        'dry_run' => (bool)($options['dry_run'] ?? true),
        'checked' => 0,
        'released' => 0,
        'blocked' => 0,
        'errors' => [['error' => $e->getMessage()]],
        'items' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
