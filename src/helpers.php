<?php
declare(strict_types=1);

/** HTML-escape for output (issue 4). Use for every dynamic value in templates. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

/** One-time message stored in the session instead of a ?message= URL parameter. */
function flash(?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'] = $message;
        return null;
    }
    $msg = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $msg;
}

function json_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Read a POST field only if it is a plain string (rejects arrays like title[]=x). */
function post_string(string $key): ?string
{
    $v = $_POST[$key] ?? null;
    return is_string($v) ? $v : null;
}

/** Positive integer from a string/int, or null. */
function to_positive_int(mixed $v): ?int
{
    if (is_int($v)) {
        return $v > 0 ? $v : null;
    }
    if (is_string($v) && preg_match('/^[1-9][0-9]{0,9}$/', $v) === 1) {
        return (int) $v;
    }
    return null;
}

/**
 * Validate note fields. Returns [title, body, error]. Lengths mirror the schema
 * (title VARCHAR(120)); body is capped well below TEXT's limit.
 */
function validate_note(mixed $title, mixed $body): array
{
    if (!is_string($title) || !is_string($body)) {
        return [null, null, 'Title and body must be text.'];
    }
    $title = trim($title);
    $body  = trim($body);
    if ($title === '' || $body === '') {
        return [null, null, 'Title and body are required.'];
    }
    if (!mb_check_encoding($title . $body, 'UTF-8')) {
        return [null, null, 'Invalid text encoding.'];
    }
    if (mb_strlen($title, 'UTF-8') > 120) {
        return [null, null, 'Title must be 120 characters or fewer.'];
    }
    if (mb_strlen($body, 'UTF-8') > 10000) {
        return [null, null, 'Body must be 10,000 characters or fewer.'];
    }
    return [$title, $body, null];
}

function render(string $template, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    require __DIR__ . '/../templates/' . $template . '.php';
}
