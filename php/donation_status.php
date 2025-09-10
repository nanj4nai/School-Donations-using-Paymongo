<?php
// api/donation_status.php?external_id=...
require_once __DIR__.'/db.php';
header('Content-Type: application/json');

$external = $_GET['external_id'] ?? null;
if (!$external) {
    http_response_code(400);
    echo json_encode(['error'=>'external_id required']);
    exit;
}

$stmt = $pdo->prepare("SELECT id, external_id, status, amount, currency, donor_is_anonymous, donor_name, receipt_url, updated_at FROM donations WHERE external_id = ? LIMIT 1");
$stmt->execute([$external]);
$r = $stmt->fetch();

if (!$r) {
    http_response_code(404);
    echo json_encode(['error'=>'not_found']);
    exit;
}

// If donor is anonymous, remove name/email before returning (optional)
if ($r['donor_is_anonymous']) {
    $r['donor_name'] = null;
}

echo json_encode(['donation' => $r]);
