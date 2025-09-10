<?php
// helpers.php

function uuid_v4() {
    // Simple UUIDv4 generator (not cryptographically unique for very strict needs)
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Verify PayMongo webhook signature.
 * PayMongo sends header: Paymongo-Signature: t=TIMESTAMP,te=SIG_FOR_TEST,li=SIG_FOR_LIVE
 * Compute HMAC_SHA256( "<t>.<raw_body>", webhook_secret ) => hex
 * Compare to te (test mode) or li (live mode). We'll check both (if present)
 */
function verify_paymongo_signature($rawBody, $signatureHeader, $webhookSecret) {
    if (empty($signatureHeader) || empty($webhookSecret)) return false;

    // Normalize header (some servers change header casing)
    // Header format: "t=TIMESTAMP,te=HEX,li=HEX"
    $parts = array_map('trim', explode(',', $signatureHeader));
    $map = [];
    foreach ($parts as $p) {
        $kv = explode('=', $p, 2);
        if (count($kv) == 2) $map[$kv[0]] = $kv[1];
    }
    if (empty($map['t'])) return false;
    $t = $map['t'];

    // signed payload = "<t>.<raw_body>"
    $signed = $t . '.' . $rawBody;
    $expected = hash_hmac('sha256', $signed, $webhookSecret);

    // Compare to te (test) OR li (live) if present.
    if (!empty($map['te']) && hash_equals($map['te'], $expected)) return true;
    if (!empty($map['li']) && hash_equals($map['li'], $expected)) return true;

    return false;
}
