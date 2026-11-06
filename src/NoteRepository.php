<?php
declare(strict_types=1);

/**
 * Every query is parameterised AND scoped to the owning user (issues 1, 3, 9).
 * Callers pass the user ID from the SESSION, never from request data.
 */
final class NoteRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function listForUser(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT id, user_id, title, body FROM notes WHERE user_id = ? ORDER BY id DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function findForUser(int $noteId, int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, user_id, title, body FROM notes WHERE id = ? AND user_id = ?');
        $stmt->execute([$noteId, $userId]);
        return $stmt->fetch() ?: null;
    }

    public function create(int $userId, string $title, string $body): int
    {
        $stmt = $this->db->prepare('INSERT INTO notes (user_id, title, body) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $title, $body]);
        return (int) $this->db->lastInsertId();
    }

    /** True if a note owned by $userId matched (even if the values were unchanged). */
    public function update(int $noteId, int $userId, string $title, string $body): bool
    {
        $stmt = $this->db->prepare('UPDATE notes SET title = ?, body = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$title, $body, $noteId, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function delete(int $noteId, int $userId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM notes WHERE id = ? AND user_id = ?');
        $stmt->execute([$noteId, $userId]);
        return $stmt->rowCount() > 0;
    }
}
