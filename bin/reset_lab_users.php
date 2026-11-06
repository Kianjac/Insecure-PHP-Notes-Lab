<?php
declare(strict_types=1);
// CLI only. Resets the two SYNTHETIC lab accounts to bcrypt hashes (use after migrating from the
// MD5 starter schema). Needs LAB_DB_USER / LAB_DB_PASSWORD with UPDATE on notes_lab.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../src/Database.php';
$config = require __DIR__ . '/../src/config.php';
$db = Database::connect($config);

$accounts = [
    'alex@example.test' => 'LabOnly-Alex-123',
    'sam@example.test'  => 'LabOnly-Sam-456',
];
$stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE email = ?');
foreach ($accounts as $email => $password) {
    $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $email]);
    echo "$email: ", $stmt->rowCount() ? 'reset' : 'not found', PHP_EOL;
}
