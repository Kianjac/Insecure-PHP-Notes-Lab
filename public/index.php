<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$config = bootstrap(false);
$db     = Database::connect($config);
$auth   = new Auth($db, $config);
$notes  = new NoteRepository($db);
$user   = $auth->currentUser();

// ---- State-changing actions: POST only + CSRF token (issue 5) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::valid(post_string('csrf_token'))) {
        http_response_code(403);
        echo 'Invalid or missing security token. Go back, reload the page and try again.';
        exit;
    }

    $action = post_string('action') ?? '';

    // Actions that do not need a login
    if ($user === null) {
        if ($action === 'register') {
            // The role field is gone: new accounts are always 'student' (issue 7).
            $error = $auth->register(post_string('email') ?? '', post_string('password') ?? '');
            flash($error ?? 'Account created. You can sign in now.');
            redirect('/');
        }
        if ($action === 'login') {
            if ($auth->login(post_string('email') ?? '', post_string('password') ?? '')) {
                redirect('/');
            }
            // Generic message: does not echo the email or say which part was wrong.
            flash('Invalid email or password.');
            redirect('/');
        }
        http_response_code(401);
        echo 'Please sign in first.';
        exit;
    }

    // Everything below needs a logged-in user. The owner ALWAYS comes from the session.
    $uid = (int) $user['id'];

    switch ($action) {
        case 'logout':
            Auth::destroySession();
            session_start();             // fresh anonymous session for the flash message
            session_regenerate_id(true);
            flash('You have been logged out.');
            redirect('/');

        case 'save':
            [$title, $body, $err] = validate_note($_POST['title'] ?? null, $_POST['body'] ?? null);
            if ($err !== null) {
                flash($err);
                redirect('/');
            }
            $notes->create($uid, $title, $body);   // any posted user_id is ignored
            flash('Note saved.');
            redirect('/');

        case 'update':
            $id = to_positive_int($_POST['id'] ?? null);
            [$title, $body, $err] = validate_note($_POST['title'] ?? null, $_POST['body'] ?? null);
            if ($id === null || $err !== null) {
                flash($err ?? 'Invalid note.');
                redirect('/');
            }
            // Ownership enforced in SQL; someone else's note looks the same as a missing one.
            flash($notes->update($id, $uid, $title, $body) ? 'Note updated.' : 'Note not found.');
            redirect('/');

        case 'delete':
            $id = to_positive_int($_POST['id'] ?? null);
            flash($id !== null && $notes->delete($id, $uid) ? 'Note deleted.' : 'Note not found.');
            redirect('/');

        default:
            http_response_code(400);
            echo 'Unknown action.';
            exit;
    }
}

// ---- GET: read-only page rendering. GET never changes state. ----
$message = flash();
$myNotes = [];
$editing = null;
if ($user !== null) {
    $myNotes = $notes->listForUser((int) $user['id']);          // only this user's notes
    $editId  = to_positive_int($_GET['edit'] ?? null);
    if ($editId !== null) {
        $editing = $notes->findForUser($editId, (int) $user['id']);   // null if not theirs
    }
}

render('home', [
    'user'    => $user,
    'message' => $message,
    'notes'   => $myNotes,
    'editing' => $editing,
]);
