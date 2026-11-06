<?php
declare(strict_types=1);

/**
 * Shared start-up for every entry point: safe error handling, secure session,
 * security headers. Loaded first by public/index.php and public/api.php.
 */

// --- Error handling (issue 8): never show errors to the browser; log them instead.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/NoteRepository.php';

$config = require __DIR__ . '/config.php';

/**
 * Generic failure response. Logs only the exception class and code, not the message or
 * trace, because PDO connection errors/traces can carry host, user or password details.
 */
function handle_fatal(Throwable $e, bool $json): void
{
    error_log(sprintf('[campus-notes] %s (code %s) at %s:%d', get_class($e), (string) $e->getCode(), basename($e->getFile()), $e->getLine()));
    if (!headers_sent()) {
        http_response_code(500);
        header($json ? 'Content-Type: application/json; charset=utf-8' : 'Content-Type: text/plain; charset=utf-8');
    }
    echo $json ? json_encode(['error' => 'Internal server error']) : 'Something went wrong. Please try again later.';
    exit;
}

function bootstrap(bool $json): array
{
    global $config;

    set_exception_handler(static fn (Throwable $e) => handle_fatal($e, $json));
    set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
        throw new ErrorException($str, 0, $no, $file, $line);
    });

    send_security_headers($json);
    start_secure_session($config);

    return $config;
}

function send_security_headers(bool $json): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
    }
}

function start_secure_session(array $config): void
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    ini_set('session.use_strict_mode', '1');   // reject attacker-chosen session IDs
    ini_set('session.use_only_cookies', '1');  // never accept IDs from the URL
    ini_set('session.use_trans_sid', '0');
    session_name('CAMPUSNOTESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,   // true automatically when served over HTTPS (not testable on local HTTP)
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    // Idle timeout: an abandoned session stops working.
    $now = time();
    if (isset($_SESSION['last_seen']) && ($now - (int) $_SESSION['last_seen']) > $config['session_idle_seconds']) {
        Auth::destroySession();
        session_start();
    }
    $_SESSION['last_seen'] = $now;
}
