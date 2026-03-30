<?php

declare(strict_types=1);

// saveTaps.php
// Backend receiver for the UI click-logging research system.

// ============================================================
// STEP 2 — CORS (Cross-Origin Resource Sharing)
// ============================================================
define('ALLOWED_ORIGIN', 'https://anjana-dodampe.github.io');

$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($requestOrigin === ALLOWED_ORIGIN) {
    header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept');
    header('Access-Control-Max-Age: 86400');
    header('Vary: Origin');
} else {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['status' => 'error', 'message' => 'CORS policy: origin not permitted.']);
    exit();
}

// ============================================================
// STEP 3 — Pre-flight & Method Guard
// ============================================================

// Handle the browser's OPTIONS pre-flight request.
// The browser sends OPTIONS *before* the real POST to ask:
// "are you allowing this cross-origin call?"
// We reply 204 No Content — permission granted, no body needed.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

// Only POST is accepted for data submission.
// Any other method (GET, PUT, DELETE...) is rejected immediately.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed. Use POST.']);
    exit();
}


// ============================================================
// STEP 4 — Response Helper Functions
// ============================================================

// Sends a JSON error response and stops execution.
// Using a function avoids repeating header() + echo + exit()
// every time we need to reject something.
function sendError(int $code, string $message): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'status'  => 'error',
        'code'    => $code,
        'message' => $message,
    ]);
    exit();
}

// Sends a 200 JSON success response and stops execution.
function sendSuccess(array $data): never
{
    http_response_code(200);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(array_merge(['status' => 'success'], $data));
    exit();
}


// ============================================================
// STEP 5 — Parse & Validate the POST Body
// ============================================================

// index.html sends data as URL-encoded form (XMLHttpRequest).
// Fields sent: id (sessionId), var (platform), taps (JSON array)
$sessionId = trim($_POST['id']   ?? '');
$platform  = strtolower(trim($_POST['var']  ?? ''));
$tapsRaw   = trim($_POST['taps'] ?? '');

// Check all three fields are present.
if ($sessionId === '') {
    sendError(400, 'Missing required field: id (sessionId).');
}
if ($platform === '') {
    sendError(400, 'Missing required field: var (platform).');
}
if ($tapsRaw === '') {
    sendError(400, 'Missing required field: taps.');
}

// Validate platform is one of the two expected device types.
if (!in_array($platform, ['android', 'pc'], true)) {
    sendError(400, "Invalid platform '{$platform}'. Expected 'android' or 'pc'.");
}

// Parse the taps JSON array.
// The frontend pushes JSON.stringify(tap) into an array, so each
// element is itself a JSON string — we need to decode the outer array first.
$tapsArray = json_decode($tapsRaw, true);

if (!is_array($tapsArray) || count($tapsArray) === 0) {
    sendError(400, 'Taps field is missing or not a valid JSON array.');
}

// ============================================================
// STEP 6 — Validate & Enrich Each Tap
// ============================================================

$enrichedDocs = [];

foreach ($tapsArray as $index => $rawTap) {

    // Each element was JSON.stringify()'d by the frontend — decode it.
    $tap = is_string($rawTap) ? json_decode($rawTap, true) : $rawTap;

    if (!is_array($tap)) {
        sendError(400, "Tap at index {$index} is not a valid JSON object.");
    }

    // --- Presence check ---
    foreach (['tapSequenceNumber', 'startTimestamp', 'endTimestamp', 'interface'] as $field) {
        if (!array_key_exists($field, $tap)) {
            sendError(400, "Tap #{$index} is missing field: {$field}.");
        }
    }

    // --- sequenceNum: must be integer between 1 and 50 ---
    $seqNum = filter_var($tap['tapSequenceNumber'], FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 50]]
    );
    if ($seqNum === false) {
        sendError(400, "Tap #{$index}: sequenceNum must be an integer between 1 and 50.");
    }

    // --- Timestamps: must be positive numbers, end >= start ---
    $startMs = filter_var($tap['startTimestamp'], FILTER_VALIDATE_FLOAT);
    $endMs   = filter_var($tap['endTimestamp'],   FILTER_VALIDATE_FLOAT);

    if ($startMs === false || $startMs <= 0) {
        sendError(400, "Tap #{$index}: startTimestamp must be a positive number.");
    }
    if ($endMs === false || $endMs <= 0) {
        sendError(400, "Tap #{$index}: endTimestamp must be a positive number.");
    }
    if ($endMs < $startMs) {
        sendError(400, "Tap #{$index}: endTimestamp cannot be earlier than startTimestamp.");
    }

    // --- interfaceType: normalise legacy JS values to clean labels ---
    $ifaceMap = [
        'feedbackshown' => 'feedback',
        'nofeedback'    => 'no-feedback',
        'feedback'      => 'feedback',
        'no-feedback'   => 'no-feedback',
    ];
    $rawIface = strtolower(trim((string) $tap['interface']));
    if (!array_key_exists($rawIface, $ifaceMap)) {
        sendError(400, "Tap #{$index}: invalid interface value '{$rawIface}'.");
    }

    // Build the flat document — one document per tap.
    // WHY flat? Allows $match(platform) + $group($avg:duration)
    // in MongoDB/Firestore without needing $unwind on nested arrays.
    $enrichedDocs[] = [
        'sessionId'     => $sessionId,
        'sequenceNum'   => (int) $seqNum,
        'platform'      => $platform,
        'interfaceType' => $ifaceMap[$rawIface],
        'startTime'     => $startMs,
        'endTime'       => $endMs,
    ];
}


// ============================================================
// STEP 7 — Persistence & Success Response
// ============================================================

// --- Firestore via REST API (no gRPC extension needed) ---
// Uses a Firebase service account key to get an OAuth2 token,
// then POSTs each tap document to Firestore's REST endpoint.
// WHY REST instead of the PHP library?
//   The google/cloud-firestore library requires ext-grpc which is
//   not available in standard XAMPP. REST works with built-in curl.

$keyFile    = __DIR__ . '/firebase_key.json';
$projectId  = 'adb-cw-clicklogs'; // replace with your Firebase project ID

if (file_exists($keyFile)) {
    $key = json_decode(file_get_contents($keyFile), true);

    // Step 1: Get an OAuth2 access token using JWT
    $now    = time();
    $claim  = [
        'iss'   => $key['client_email'],
        'scope' => 'https://www.googleapis.com/auth/datastore',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ];

    // Build JWT: header.payload.signature
    $b64Header  = rtrim(strtr(base64_encode(json_encode(['alg'=>'RS256','typ'=>'JWT'])), '+/', '-_'), '=');
    $b64Claim   = rtrim(strtr(base64_encode(json_encode($claim)), '+/', '-_'), '=');
    $toSign     = $b64Header . '.' . $b64Claim;
    openssl_sign($toSign, $signature, $key['private_key'], 'SHA256');
    $b64Sig     = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    $jwt        = $toSign . '.' . $b64Sig;

    // Step 2: Exchange JWT for access token
    $tokenResp = json_decode(file_get_contents('https://oauth2.googleapis.com/token', false,
        stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
        ]])
    ), true);

    $accessToken = $tokenResp['access_token'] ?? null;

    // Step 3: Write each tap document to Firestore
    if ($accessToken) {
        $firestoreUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/tap_logs";

        foreach ($enrichedDocs as $doc) {
            // Convert PHP array to Firestore REST field format
            $fields = [];
            foreach ($doc as $k => $v) {
                if (is_int($v))    $fields[$k] = ['integerValue'  => $v];
                elseif (is_float($v)) $fields[$k] = ['doubleValue' => $v];
                else               $fields[$k] = ['stringValue'  => (string)$v];
            }

            $ch = curl_init($firestoreUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['fields' => $fields]),
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json',
                ],
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    } else {
        error_log('[saveTaps.php] Could not get Firestore access token.');
    }
}

// --- Fallback: NDJSON local file store ---
// While Firestore is not yet configured, each tap document is appended
// as one JSON line to tap_logs.ndjson.
// WHY NDJSON? Easy to import into MongoDB later:
//   mongoimport --db clicklogs_db --collection tap_logs --file tap_logs.ndjson
// FILE_APPEND | LOCK_EX prevents data corruption under concurrent requests.
$logFile = __DIR__ . '/tap_logs.ndjson';
foreach ($enrichedDocs as $doc) {
    file_put_contents($logFile, json_encode($doc) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// --- Success response ---
// "Data saved successfully" is the exact string index.html checks for.
// We include it inside the JSON so both the legacy frontend and new
// JSON-aware clients both get a meaningful response.
sendSuccess([
    'message'      => 'Data saved successfully',
    'sessionId'    => $sessionId,
    'tapsRecorded' => count($enrichedDocs),
]);