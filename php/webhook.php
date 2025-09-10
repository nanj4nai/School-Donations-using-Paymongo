<?php
// webhook.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

$raw = file_get_contents('php://input');
error_log("Webhook raw payload received: " . $raw);

$signatureHeader = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? ($_SERVER['HTTP_PAYMONGO-SIGNATURE'] ?? null);
$webhookSecret   = "whsk_V7Fb6Fj9LE1AQZYNLjVCdaNy";

$isLocal = in_array($_SERVER['SERVER_NAME'], ['localhost', '127.0.0.1']);

// 🔒 Verify signature (skip on localhost for dev)
if (!$isLocal && !verify_paymongo_signature($raw, $signatureHeader, $webhookSecret)) {
    error_log("Webhook invalid signature");
    http_response_code(400);
    echo json_encode(['error' => 'invalid signature']);
    exit;
}

// Decode payload
$data = json_decode($raw, true);
if (!$data) {
    error_log("Failed to decode JSON payload.");
    http_response_code(400);
    echo json_encode(['error' => 'invalid JSON payload']);
    exit;
}

$provider_event_id = $data['data']['id'] ?? null;
$event_type        = $data['data']['attributes']['type'] ?? 'unknown';

error_log("Processing event $provider_event_id of type $event_type");

// 1) Insert raw event record
try {
    $insert = $pdo->prepare("
        INSERT INTO donation_events (event_type, provider_event_id, payload, processing_status, created_at)
        VALUES (?, ?, ?, 'queued', NOW())
    ");
    $insert->execute([$event_type, $provider_event_id, $raw]);
    $event_id = $pdo->lastInsertId();
} catch (Exception $e) {
    error_log("Failed to insert donation_event: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'failed_to_log_event']);
    exit;
}

// 2) Idempotency check
try {
    $chk = $pdo->prepare("
        SELECT id FROM donation_events 
        WHERE provider_event_id = ? AND processing_status = 'processed' 
        LIMIT 1
    ");
    $chk->execute([$provider_event_id]);
    if ($chk->fetch()) {
        error_log("Event $provider_event_id already processed.");
        http_response_code(200);
        echo json_encode(['status' => 'already_processed']);
        exit;
    }
} catch (Exception $e) {
    error_log("Failed idempotency check: " . $e->getMessage());
}

// 3) Begin main processing
try {
    $pdo->beginTransaction();

    $donation   = null;
    $donationId = null;

    $session = $data['data']['attributes']['data'] ?? null;
    $attrs   = $session['attributes'] ?? [];

    // --- Handle harmless pings ---
    $payments = $attrs['payments'] ?? [];
    if (empty($payments)) {
        error_log("No payments found in session payload. Ignoring this event.");

        $p = $pdo->prepare("
            UPDATE donation_events
            SET processing_status = 'ignored', processed_at = NOW()
            WHERE id = ?
        ");
        $p->execute([$event_id]);

        $log = $pdo->prepare("
            INSERT INTO donation_processing_log (donation_event_id, message, level) 
            VALUES (?, ?, 'info')
        ");
        $log->execute([$event_id, "Ignored event — no payments found."]);

        $pdo->commit();

        http_response_code(200);
        echo json_encode(['status' => 'ignored', 'reason' => 'no payments']);
        exit;
    }

    $paymentObj = $payments[0]['attributes'] ?? [];
    error_log("Payment object: " . print_r($paymentObj, true));

    // --- Map payment fields ---
    $paymentId     = $paymentObj['id'] ?? null;
    $amount        = $paymentObj['amount'] ?? null;
    $currency      = $paymentObj['currency'] ?? 'PHP';
    $metadata      = $paymentObj['metadata'] ?? [];

    $billing       = $paymentObj['billing'] ?? [];
    $donor_name    = $metadata['donor_name'] ?? $billing['name'] ?? null;
    $donor_email   = $metadata['donor_email'] ?? $billing['email'] ?? null;
    $donor_phone   = $billing['phone'] ?? null;
    $payment_method = $paymentObj['source']['type'] ?? null;
    $receipt       = $paymentObj['access_url'] ?? null;

    $external_id   = $metadata['external_id'] ?? null;

    // --- Find donation ---
    if ($external_id) {
        $s = $pdo->prepare("SELECT * FROM donations WHERE external_id = ? LIMIT 1");
        $s->execute([$external_id]);
        $donation = $s->fetch();
    }

    if (!$donation && $paymentId) {
        $s = $pdo->prepare("SELECT * FROM donations WHERE payment_provider_payment_id = ? LIMIT 1");
        $s->execute([$paymentId]);
        $donation = $s->fetch();
    }

    if (!$donation) {
        error_log("No matching donation found. Ignoring this event.");
        $p = $pdo->prepare("
            UPDATE donation_events
            SET processing_status = 'ignored', processed_at = NOW()
            WHERE id = ?
        ");
        $p->execute([$event_id]);

        $log = $pdo->prepare("
            INSERT INTO donation_processing_log (donation_event_id, message, level)
            VALUES (?, ?, 'info')
        ");
        $log->execute([$event_id, "Ignored event — no matching donation found."]);

        $pdo->commit();

        http_response_code(200);
        echo json_encode(['status' => 'ignored', 'reason' => 'no matching donation']);
        exit;
    }

    $donationId = $donation['id'];

    // --- Update donation ---
    $amount_php = $amount ? (floatval($amount) / 100.0) : null;
    $existing_meta = json_decode($donation['metadata'] ?? '{}', true);
    $new_meta = array_merge($existing_meta, $paymentObj);
    $meta_json = json_encode($new_meta);

    $update = $pdo->prepare("
        UPDATE donations 
        SET status = 'paid',
            payment_provider = 'paymongo',
            payment_provider_payment_id = ?,
            payment_method = ?,
            receipt_url = ?,
            donor_name = COALESCE(?, donor_name),
            donor_email = COALESCE(?, donor_email),
            donor_phone = COALESCE(?, donor_phone),
            metadata = ?,
            processed_at = NOW()
        WHERE id = ?
    ");
    $update->execute([
        $paymentId,
        $payment_method,
        $receipt,
        $donor_name,
        $donor_email,
        $donor_phone,
        $meta_json,
        $donationId
    ]);

    $log = $pdo->prepare("
        INSERT INTO donation_processing_log (donation_event_id, message, level)
        VALUES (?, ?, 'info')
    ");
    $log->execute([$event_id, "Marked donation {$donationId} as paid + updated donor info."]);

    // --- Mark event as processed ---
    $p = $pdo->prepare("
        UPDATE donation_events
        SET donation_id = ?, processing_status = 'processed', processed_at = NOW()
        WHERE id = ?
    ");
    $p->execute([$donationId, $event_id]);

    $pdo->commit();

    http_response_code(200);
    echo json_encode(['status' => 'ok']);
} catch (Exception $e) {
    $pdo->rollBack();

    error_log("Webhook processing exception: " . $e->getMessage());
    error_log($e->getTraceAsString());

    try {
        $p = $pdo->prepare("UPDATE donation_events SET processing_status = 'failed' WHERE id = ?");
        $p->execute([$event_id]);

        $log = $pdo->prepare("INSERT INTO donation_processing_log (donation_event_id, message, level) VALUES (?, ?, 'error')");
        $log->execute([$event_id, $e->getMessage()]);
    } catch (Exception $inner) {
        error_log("Failed to log internal exception: " . $inner->getMessage());
    }

    // Still return 200 to avoid alarming test pings
    http_response_code(200);
    echo json_encode(['status' => 'failed', 'details' => $e->getMessage()]);
}
