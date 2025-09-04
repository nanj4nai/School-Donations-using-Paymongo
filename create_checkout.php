<?php
// create_checkout.php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check amount from form
    $amount = 0;

    // If "other_amount" is set and valid, use it
    if (!empty($_POST['other_amount']) && intval($_POST['other_amount']) >= 50) {
        $amount = intval($_POST['other_amount']);
    } elseif (!empty($_POST['amount_choice'])) {
        $amount = intval($_POST['amount_choice']);
    }

    // Validate minimum donation
    if ($amount < 50) {
        die("Invalid donation amount.");
    }

    // PayMongo API key
    $publicapiKey = "pk_test_RNFwz8W3x41iAU13XXe4hfsD"; // this is the public key
    $sekretapiKey = "sk_test_uoQs1PxqLCALMEoKdKDitCFv"; // this is the secret key which is only the needed one

    // Setup cURL
    $ch = curl_init("https://api.paymongo.com/v1/checkout_sessions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Basic " . base64_encode($sekretapiKey . ":")
    ]);

    // Create checkout session payload
    $data = [
        "data" => [
            "attributes" => [
                "line_items" => [[
                    "currency" => "PHP",
                    "amount" => $amount * 100, // PayMongo expects centavos
                    "name" => "School Donation",
                    "quantity" => 1
                ]],
                "payment_method_types" => ["card", "gcash", "grab_pay"],
                "success_url" => "http://localhost/donation-system/success.html",
                "cancel_url" => "http://localhost/donation-system/failed.html"
            ]
        ]
    ];

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    curl_close($ch);

    $resData = json_decode($response, true);

    // Redirect donor to PayMongo hosted checkout
    if (isset($resData['data']['attributes']['checkout_url'])) {
        header("Location: " . $resData['data']['attributes']['checkout_url']);
        exit;
    } else {
        echo "<h2>Failed to create checkout session.</h2>";
        echo "<pre>";
        print_r($resData);
        echo "</pre>";
    }
} else {
    echo "Invalid request.";
    header("location: index.html");
}
