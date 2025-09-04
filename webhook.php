<?php
// --- Webhook endpoint for PayMongo events ---
header("Content-Type: application/json");

// Read raw body from PayMongo
$input = file_get_contents("php://input");

// --- (1) Verify webhook signature ---
$signatureHeader = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';
$secret = "your_webhook_secret_here"; // 🔑 from PayMongo dashboard

$computedSignature = hash_hmac('sha256', $input, $secret);

if (!hash_equals($computedSignature, $signatureHeader)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid signature"]);
    exit;
}

// Decode event payload
$event = json_decode($input, true);

// ✅ Log every incoming webhook
file_put_contents("webhook_debug.log", date("Y-m-d H:i:s") . " | " . $input . "\n", FILE_APPEND);

if (!isset($event['data']['attributes']['type'])) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid webhook payload"]);
    exit;
}

$eventType = $event['data']['attributes']['type'];

switch ($eventType) {
    case 'payment.paid':
        $paymentId = $event['data']['id'];
        $amount = $event['data']['attributes']['amount'] / 100; // centavos → pesos

        // ✅ Try saving to DB (if available)
        try {
            $conn = @new mysqli("localhost", "root", "", "donations_db"); // adjust credentials

            if ($conn && !$conn->connect_errno) {
                $stmt = $conn->prepare("INSERT INTO donations (payment_id, amount, status, created_at) VALUES (?, ?, 'paid', NOW())");
                $stmt->bind_param("sd", $paymentId, $amount);
                $stmt->execute();
                $stmt->close();
                $conn->close();
            } else {
                // DB not ready → log only
                file_put_contents("donations.log", "✅ Donation received (DB skipped): ₱$amount | Payment ID: $paymentId\n", FILE_APPEND);
            }
        } catch (Exception $e) {
            // DB error → log only
            file_put_contents("donations.log", "✅ Donation received (DB error): ₱$amount | Payment ID: $paymentId | Error: " . $e->getMessage() . "\n", FILE_APPEND);
        }
        break;

    case 'payment.failed':
        $paymentId = $event['data']['id'];

        // Log failure (DB not needed)
        file_put_contents("donations.log", "❌ Failed payment: $paymentId\n", FILE_APPEND);
        break;

    default:
        file_put_contents("donations.log", "ℹ️ Unhandled event type: $eventType\n", FILE_APPEND);
        break;
}

// Always respond with 200 so PayMongo knows webhook was received
http_response_code(200);
echo json_encode(["status" => "ok"]);
