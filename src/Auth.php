<?php
declare(strict_types=1);

/** Registration, login, logout and "who is logged in" (issues 2, 6, 7). */
final class Auth
{
    // Valid bcrypt hash of a random string; used so unknown emails cost the same time as known ones.
    private const DUMMY_HASH = '$2y$10$pgCkANymAZVMsHIfF/0hl.oioKQs5bcso1qHzGxCnO8RTQWlq6biO';

    public function __construct(private PDO $db, private array $config)
    {
    }

    /** Returns an error message, or null on success. The role is NEVER taken from the client. */
    public function register(string $email, string $password): ?string
    {
        $email = trim(mb_strtolower($email));
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'Enter a valid email address.';
        }
        if (strlen($password) < $this->config['min_password_length']) {
            return 'Password must be at least ' . $this->config['min_password_length'] . ' characters.';
        }
        if (strlen($password) > 72) {   // bcrypt only uses the first 72 bytes
            return 'Password must be 72 bytes or fewer.';
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);   // salted bcrypt (issue 2)
        try {
            $stmt = $this->db->prepare("INSERT INTO users (email, password_hash, role) VALUES (?, ?, 'student')");
            $stmt->execute([$email, $hash]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {   // duplicate email; same message either way
                return 'Registration failed. Try a different email or sign in.';
            }
            throw $e;
        }
        return null;
    }

    /** Returns true on success. Rotates the session ID to prevent session fixation. */
    public function login(string $email, string $password): bool
    {
        $email = trim(mb_strtolower($email));
        $stmt = $this->db->prepare('SELECT id, password_hash FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        $ok = password_verify($password, $user['password_hash'] ?? self::DUMMY_HASH);
        if (!$user || !$ok) {
            return false;
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $upd = $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $upd->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }

        session_regenerate_id(true);               // issue 6: new ID at privilege change
        $_SESSION['user_id']   = (int) $user['id'];
        $_SESSION['csrf']      = bin2hex(random_bytes(32));
        $_SESSION['last_seen'] = time();
        return true;
    }

    /** Current user from the DB (role is read server-side, not cached from the client). */
    public function currentUser(): ?array
    {
        $id = $_SESSION['user_id'] ?? null;
        if (!is_int($id)) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT id, email, role FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) {            // account deleted -> session no longer valid
            self::destroySession();
            return null;
        }
        return $user;
    }

    /** Fully ends the session: clears data, expires the cookie, destroys server-side state. */
    public static function destroySession(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?: 'Lax',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
