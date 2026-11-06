<?php
declare(strict_types=1);

/** Per-session CSRF token (issue 5 and 11). */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function valid(?string $submitted): bool
    {
        return is_string($submitted)
            && isset($_SESSION['csrf'])
            && is_string($_SESSION['csrf'])
            && hash_equals($_SESSION['csrf'], $submitted);   // constant-time compare
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . e(self::token()) . '">';
    }
}
