<?php
// user/save_settings.php — JSON API for header settings
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

function json_out($success, $message = '') {
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(false, 'Invalid request method.');

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data) || empty($data['section'])) json_out(false, 'Invalid payload.');

$section = $data['section'];
$userId  = $_SESSION['user_id'] ?? null;

if (!$userId) {
    if ($section === 'appearance') json_out(true, 'Appearance saved to this browser.');
    json_out(false, 'Please log in to save these settings.');
}

try { $db = (new Database())->getConnection(); }
catch (Throwable $e) { json_out(false, 'Database connection failed.'); }

// Ensure a settings row exists
$stmt = $db->prepare("SELECT 1 FROM user_settings WHERE user_id = ? LIMIT 1");
$stmt->execute([$userId]);
if (!$stmt->fetchColumn()) {
    $db->prepare("INSERT INTO user_settings (user_id) VALUES (?)")->execute([$userId]);
}

try {
    if ($section === 'account') {
        $fullName = trim($data['full_name'] ?? '');
        $username = trim($data['username']  ?? '');
        $email    = trim($data['email']     ?? '');
        $phone    = trim($data['phone']     ?? '');

        if ($fullName === '') json_out(false, 'Full name is required.');
        if ($username === '') json_out(false, 'Username is required.');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_out(false, 'Enter a valid email address.');
        }
        if ($email !== '') {
            $q = $db->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
            $q->execute([$email, $userId]);
            if ($q->fetchColumn()) json_out(false, 'That email is already in use.');
        }
        $q = $db->prepare("SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1");
        $q->execute([$username, $userId]);
        if ($q->fetchColumn()) json_out(false, 'That username is already taken.');

        $db->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, phone = ? WHERE id = ?")
           ->execute([$fullName, $username, $email, $phone, $userId]);

        $_SESSION['full_name'] = $fullName;
        $_SESSION['username']  = $username;
        $_SESSION['email']     = $email;
        $_SESSION['phone']     = $phone;

        json_out(true, 'Account settings saved.');
    }

    if ($section === 'security') {
        $cur = (string)($data['current_password'] ?? '');
        $new = (string)($data['new_password']     ?? '');
        $cfm = (string)($data['confirm_password'] ?? '');

        if ($cur !== '' || $new !== '' || $cfm !== '') {
            if ($cur === '' || $new === '' || $cfm === '') json_out(false, 'Please fill in all three password fields.');
            if (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new))
                json_out(false, 'New password must be 8+ characters with letters and numbers.');
            if ($new !== $cfm) json_out(false, 'New password and confirmation do not match.');

            $q = $db->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
            $q->execute([$userId]);
            $hash = $q->fetchColumn();
            if (!$hash || !password_verify($cur, $hash)) json_out(false, 'Current password is incorrect.');

            $db->prepare("UPDATE users SET password = ? WHERE id = ?")
               ->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
        }

        $db->prepare("UPDATE user_settings SET toggle_2fa = ?, toggle_login_alerts = ? WHERE user_id = ?")
           ->execute([!empty($data['toggle_2fa']) ? 1 : 0, !empty($data['toggle_login_alerts']) ? 1 : 0, $userId]);

        json_out(true, 'Security settings saved.');
    }

    if ($section === 'notifications') {
        $db->prepare("UPDATE user_settings
                      SET toggle_order_updates = ?, toggle_promos = ?, toggle_stock = ?, toggle_newsletter = ?
                      WHERE user_id = ?")
           ->execute([
                !empty($data['toggle_order_updates']) ? 1 : 0,
                !empty($data['toggle_promos'])        ? 1 : 0,
                !empty($data['toggle_stock'])         ? 1 : 0,
                !empty($data['toggle_newsletter'])    ? 1 : 0,
                $userId
           ]);
        json_out(true, 'Notification preferences saved.');
    }

    if ($section === 'appearance') {
        $themes  = ['cream','light','dark','auto'];
        $accents = ['#b8941f','#5b5fd4','#0d9668','#dc2626','#2563eb'];
        $theme  = in_array($data['theme']  ?? '', $themes,  true) ? $data['theme']  : 'cream';
        $accent = in_array($data['accent'] ?? '', $accents, true) ? $data['accent'] : '#b8941f';

        $db->prepare("UPDATE user_settings SET theme = ?, accent = ?, compact = ?, animations = ? WHERE user_id = ?")
           ->execute([
                $theme, $accent,
                !empty($data['compact'])    ? 1 : 0,
                !empty($data['animations']) ? 1 : 0,
                $userId
           ]);
        json_out(true, 'Appearance saved and applied.');
    }

    json_out(false, 'Unknown section.');
} catch (Throwable $e) {
    json_out(false, 'Save failed: ' . $e->getMessage());
}