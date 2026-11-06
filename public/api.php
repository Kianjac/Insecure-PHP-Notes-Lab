<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$config = bootstrap(true);

/*
 * No CORS headers are sent: the API is same-origin only. The starter reflected any Origin
 * together with Allow-Credentials, which let any website use a victim's cookie (issue 11).
 */

// Reject requests that browsers say come from another site or an origin we do not trust.
$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
if ($origin !== null && !in_array($origin, $config['allowed_origins'], true)) {
    json_response(403, ['error' => 'Origin not allowed']);
}
if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin') === 'cross-site') {
    json_response(403, ['error' => 'Cross-site requests are not allowed']);
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') {
    // No preflight is ever approved, so cross-origin JS cannot send credentialed requests.
    http_response_code(204);
    exit;
}
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    json_response(405, ['error' => 'Method not supported']);
}

// ---- Authentication (issue 9): identity comes from the session only ----
$db   = Database::connect($config);
$auth = new Auth($db, $config);
$user = $auth->currentUser();
if ($user === null) {
    json_response(401, ['error' => 'Authentication required']);
}
$uid   = (int) $user['id'];
$notes = new NoteRepository($db);

if ($method === 'GET') {
    // Token for same-origin JS to send back on POST. Cross-origin pages cannot read this response.
    if (isset($_GET['csrf'])) {
        json_response(200, ['csrf_token' => Csrf::token()]);
    }

    // user_id is optional; if given it must be the caller's own ID.
    if (array_key_exists('user_id', $_GET)) {
        $requested = to_positive_int($_GET['user_id']);
        if ($requested === null) {
            json_response(400, ['error' => 'user_id must be a positive integer']);
        }
        if ($requested !== $uid) {
            json_response(403, ['error' => 'Forbidden']);
        }
    }
    json_response(200, $notes->listForUser($uid));
}

// ---- POST: update a note ----
// Require a JSON content type. A cross-site HTML form cannot send application/json without a
// CORS preflight, which we never approve; this is a second layer on top of the token below.
$ctype = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
if ($ctype !== 'application/json') {
    json_response(415, ['error' => 'Content-Type must be application/json']);
}
if (!Csrf::valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    json_response(403, ['error' => 'Missing or invalid CSRF token']);
}

// Cap the body size (64 KiB) before parsing.
$max = 65536;
$raw = file_get_contents('php://input', false, null, 0, $max + 1);
if ($raw === false || $raw === '') {
    json_response(400, ['error' => 'Request body is required']);
}
if (strlen($raw) > $max) {
    json_response(413, ['error' => 'Request body too large']);
}

try {
    $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    json_response(400, ['error' => 'Malformed JSON']);
}
// Must be a JSON object (assoc array), not a scalar or a list.
if (!is_array($data) || ($data !== [] && array_is_list($data))) {
    json_response(400, ['error' => 'JSON body must be an object']);
}

$id = to_positive_int($data['id'] ?? null);
if ($id === null) {
    json_response(422, ['error' => 'id must be a positive integer']);
}
[$title, $body, $err] = validate_note($data['title'] ?? null, $data['body'] ?? null);
if ($err !== null) {
    json_response(422, ['error' => $err]);
}
// Any "user_id" or "role" key in the body is ignored on purpose (issue 7).

if (!$notes->update($id, $uid, $title, $body)) {
    json_response(404, ['error' => 'Note not found']);   // same answer for missing and not-yours
}
json_response(200, ['updated' => 1]);
