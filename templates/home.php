<?php
/** @var ?array $user @var ?string $message @var array $notes @var ?array $editing */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Campus Notes</title>
<link rel="stylesheet" href="/style.css">
</head>
<body>
<h1>Campus Notes</h1>
<?php if ($message): ?><p role="status"><?= e($message) ?></p><?php endif; ?>

<?php if ($user === null): ?>
<h2>Register or sign in</h2>
<form method="post" action="/">
  <?= Csrf::field() ?>
  <label>Email <input name="email" type="email" maxlength="254" required autocomplete="username"></label>
  <label>Password <input name="password" type="password" minlength="10" maxlength="72" required autocomplete="current-password"></label>
  <button name="action" value="register">Register</button>
  <button name="action" value="login">Sign in</button>
</form>
<?php else: ?>
<form method="post" action="/" class="inline">
  <?= Csrf::field() ?>
  <span>Signed in as <?= e($user['email']) ?> (<?= e($user['role']) ?>)</span>
  <button name="action" value="logout">Log out</button>
</form>

<?php if ($editing): ?>
<h2>Edit note</h2>
<form method="post" action="/">
  <?= Csrf::field() ?>
  <input type="hidden" name="id" value="<?= e($editing['id']) ?>">
  <label>Title <input name="title" value="<?= e($editing['title']) ?>" maxlength="120" required></label>
  <label>Body <textarea name="body" maxlength="10000" required><?= e($editing['body']) ?></textarea></label>
  <button name="action" value="update">Update</button>
  <a href="/">Cancel</a>
</form>
<?php endif; ?>

<h2>Create a note</h2>
<form method="post" action="/">
  <?= Csrf::field() ?>
  <label>Title <input name="title" maxlength="120" required></label>
  <label>Body <textarea name="body" maxlength="10000" required></textarea></label>
  <button name="action" value="save">Save</button>
</form>

<h2>Your notes</h2>
<?php foreach ($notes as $note): ?>
<article>
  <h3><?= e($note['title']) ?></h3>
  <p><?= e($note['body']) ?></p>
  <a href="/?edit=<?= e($note['id']) ?>">Edit</a>
  <form method="post" action="/" class="inline">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= e($note['id']) ?>">
    <button name="action" value="delete">Delete</button>
  </form>
</article>
<?php endforeach; ?>
<?php if (!$notes): ?><p>No notes yet.</p><?php endif; ?>
<?php endif; ?>
</body>
</html>
