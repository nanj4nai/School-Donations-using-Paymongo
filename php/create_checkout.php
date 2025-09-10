<?php
// api/create_checkout.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json');

// --- 1. Parse input ---
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Invalid JSON request payload.'
    ]);
    exit;
}

$project_id = isset($input['project_id']) ? intval($input['project_id']) : null;
$amount   = isset($input['amount']) ? floatval($input['amount']) : 0.0;
$currency = isset($input['currency']) ? strtoupper(trim($input['currency'])) : 'PHP';
$donor_name = isset($input['donor_name']) ? trim($input['donor_name']) : null;
$donor_email = isset($input['donor_email']) ? trim($input['donor_email']) : null;
$donor_is_anonymous = !empty($input['donor_is_anonymous']) ? 1 : 0;

if ($amount <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Invalid donation amount.'
    ]);
    exit;
}

// --- 2. Generate tracking ID ---
$external_id = uuid_v4();

// --- 3. Insert initial donation record ---
try {
    $stmt = $pdo->prepare("
        INSERT INTO donations 
        (external_id, project_id, donor_name, donor_email, donor_is_anonymous, amount, currency, status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([
        $external_id,
        $project_id,
        $donor_name,
        $donor_email,
        $donor_is_anonymous,
        number_format($amount, 2, '.', ''),
        $currency
    ]);
    $donation_id = $pdo->lastInsertId();
} catch (Exception $e) {
    error_log("DB insert donation failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Failed to create donation record.'
    ]);
    exit;
}

// --- 4. Build PayMongo checkout session payload ---
$paymongo_secret = "sk_test_4wdsNiPHrruruKzQ9f7iSfLR";

$success_url = "https://d88757e41b52.ngrok-free.app/endonela/success.html?external_id=" . urlencode($external_id);
$failure_url = "https://d88757e41b52.ngrok-free.app/endonela/failed.html?external_id=" . urlencode($external_id);

$amount_cents = intval(round($amount * 100));

$payload = [
    'data' => [
        'attributes' => [
            'line_items' => [[
                'currency'    => strtoupper($currency),
                'amount'      => $amount_cents,
                'name'        => 'Donation',
                'description' => 'Support our project',
                'quantity'    => 1
            ]],
            'payment_method_types' => ['card', 'gcash', 'paymaya'],
            'success_url' => $success_url,
            'cancel_url'  => $failure_url,
            'metadata'    => [
                'external_id' => $external_id,
                'project_id'  => $project_id,
                'donor_name'  => $donor_is_anonymous ? 'Anonymous' : $donor_name,
                'donor_email' => $donor_email
            ],
            'customer' => [
                'name'  => $donor_is_anonymous ? 'Anonymous' : $donor_name,
                'email' => $donor_email,
                'phone' => $input['donor_phone'] ?? null
            ],
            // <-- PayMongo email receipt flag -->
            'send_email_receipt' => !empty($donor_email) ? true : false
        ]
    ]
];


// --- 5. Send request to PayMongo ---
$ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD        => $paymongo_secret . ':',
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 30
]);

$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($response === false) {
    $curl_error = curl_error($ch);
    curl_close($ch);
    error_log("PayMongo cURL error: $curl_error");
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'error'   => 'Payment gateway connection failed.',
        'details' => $curl_error
    ]);
    exit;
}

curl_close($ch);
$resp = json_decode($response, true);

// --- 6. Validate PayMongo response ---
if ($httpcode < 200 || $httpcode >= 300 || empty($resp['data']['id'])) {
    error_log("PayMongo create checkout failed: HTTP $httpcode - " . $response);
    http_response_code($httpcode ?: 502);
    echo json_encode([
        'success' => false,
        'error'   => $resp['errors'][0]['detail'] ?? 'Failed to create checkout session.',
        'raw'     => $resp
    ]);
    exit;
}

// --- 7. Save checkout ID to DB ---
$checkout_url = $resp['data']['attributes']['redirect_checkout_url'] ?? null;
$provider_payment_id = $resp['data']['id'];

try {
    $stmt = $pdo->prepare("
        UPDATE donations 
        SET payment_provider = 'paymongo', 
            payment_provider_payment_id = ?, 
            metadata = JSON_MERGE_COALESCE(IFNULL(metadata, JSON_OBJECT()), JSON_OBJECT('checkout_resp', ?)) 
        WHERE id = ?
    ");
    $stmt->execute([$provider_payment_id, json_encode($resp), $donation_id]);
} catch (Exception $e) {
    error_log("Failed to update donation after checkout creation: " . $e->getMessage());
}

// --- 8. Respond to frontend ---
echo json_encode([
    'success'      => true,
    'checkout_url' => $resp['data']['attributes']['checkout_url'] ?? $resp['data']['attributes']['redirect']['checkout_url'] ?? null,
    'raw'          => $resp,
    'external_id'  => $external_id,
    'project_id'   => $project_id
]);
