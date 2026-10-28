<?php
// user/logout.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$return = $_GET['return'] ?? $_POST['return'] ?? 'index.php';

if (
    !is_string($return) ||
    $return === '' ||
    $return[0] !== '/' ||
    strpos($return, '//') === 0 ||
    strpos($return, '\\') !== false ||
    strpos($return, "\n") !== false ||
    strpos($return, "\r") !== false
) {
    $return = 'index.php';
}

$return = preg_replace('/([?&])logout=1(&|$)/', '$1', $return);
$return = rtrim($return, '?&');

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]
    );
}

session_destroy();

if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'samesite' => 'Lax',
    ]);
}

$sep = (strpos($return, '?') !== false) ? '&' : '?';
header('Location: ' . $return . $sep . 'logout=1');
exit();