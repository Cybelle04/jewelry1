<?php
// includes/header.php — Full message system + View All link + Auth Modals + Logout toast + Wishlist + Logout confirm
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!ini_get('date.timezone')) {
    date_default_timezone_set('Asia/Manila');
}

/* ---------------- Appearance ---------------- */
$theme         = 'cream';
$accent        = '#5b5fd4';
$sidebar_style = 'dark';

if (!empty($_COOKIE['rjewel_theme'])) {
    $parsed = json_decode(urldecode($_COOKIE['rjewel_theme']), true);
    if (is_array($parsed)) {
        $theme         = $parsed['theme']   ?? 'cream';
        $accent        = $parsed['accent']  ?? '#5b5fd4';
        $sidebar_style = $parsed['sidebar'] ?? 'dark';
    }
}
$theme = in_array($theme, ['cream', 'light', 'dark', 'auto'], true) ? $theme : 'cream';
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) $accent = '#5b5fd4';
if (!in_array($sidebar_style, ['dark', 'light', 'gold'], true)) $sidebar_style = 'dark';
$resolved_theme = $theme === 'auto' ? 'cream' : $theme;

/* ---------------- Current page detection ---------------- */
$__script   = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
$__is_home  = ($__script === 'index.php');
$__is_shop  = ($__script === 'shop.php' || $__script === 'product-details.php');
$__is_tryon = ($__script === 'virtual-tryon.php');
$__is_about = ($__script === 'about.php');
$__is_admin = (strpos(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/admin/') !== false);
$__is_cart  = ($__script === 'cart.php');

$__current_page = $_SERVER['REQUEST_URI'] ?? '/index.php';
$__logout_url   = 'logout.php?return=' . urlencode($__current_page);

/* ---------------- AJAX self URL ---------------- */
$__header_self_url = (function () {
    $doc_root = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    $self_abs = str_replace('\\', '/', realpath(__FILE__));
    if ($doc_root && $self_abs && strpos($self_abs, $doc_root) === 0) {
        return substr($self_abs, strlen($doc_root));
    }
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir    = rtrim(dirname($script), '/\\');
    return $dir . '/../includes/header.php';
})();

/* ---------------- DB helpers ---------------- */
function header_get_db() {
    static $db = null, $done = false;
    if ($done) return $db;
    $done = true;
    if (file_exists(__DIR__ . '/../config/database.php')) {
        require_once __DIR__ . '/../config/database.php';
    }
    if (isset($pdo) && $pdo instanceof PDO)                   return $db = $pdo;
    if (isset($conn) && $conn instanceof PDO)                 return $db = $conn;
    if (isset($connection) && $connection instanceof PDO)     return $db = $connection;
    if (isset($pdo) && $pdo instanceof mysqli)                return $db = $pdo;
    if (isset($conn) && $conn instanceof mysqli)              return $db = $conn;
    if (isset($connection) && $connection instanceof mysqli)  return $db = $connection;
    if (class_exists('Database')) {
        try {
            $tmp = (new Database())->getConnection();
            if ($tmp instanceof PDO || $tmp instanceof mysqli) return $db = $tmp;
        } catch (Throwable $e) {}
    }
    return null;
}

function header_query($sql, $params = []) {
    $db = header_get_db();
    if (!$db) return [];
    if ($db instanceof PDO) {
        try { $s = $db->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable $e) { return []; }
    }
    try {
        $s = $db->prepare($sql);
        if (!$s) return [];
        if (!empty($params)) {
            $types = '';
            foreach ($params as $p) { $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's'); }
            $s->bind_param($types, ...$params);
        }
        $s->execute();
        $res = $s->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        $s->close();
        return $rows;
    } catch (Throwable $e) { return []; }
}

function header_exec($sql, $params = []) {
    $db = header_get_db();
    if (!$db) return false;
    if ($db instanceof PDO) {
        try { $s = $db->prepare($sql); return $s->execute($params); }
        catch (Throwable $e) { return false; }
    }
    try {
        $s = $db->prepare($sql);
        if (!$s) return false;
        if (!empty($params)) {
            $types = '';
            foreach ($params as $p) { $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's'); }
            $s->bind_param($types, ...$params);
        }
        $ok = $s->execute();
        $s->close();
        return $ok;
    } catch (Throwable $e) { return false; }
}

/* ---------------- Date helpers ---------------- */
function header_full_date($dt) {
    if (!$dt) return '';
    try {
        $tz = new DateTimeZone(date_default_timezone_get());
        $d  = new DateTime($dt, $tz);
        return $d->format('M j, Y · g:i A');
    } catch (Throwable $e) {
        $ts = strtotime($dt);
        return $ts ? date('M j, Y · g:i A', $ts) : '';
    }
}
function header_icon($type) {
    $map = [
        'order' => 'shopping-bag', 'layaway' => 'hand-holding-heart',
        'payment' => 'credit-card', 'due' => 'clock', 'system' => 'info-circle',
    ];
    return $map[strtolower((string)$type)] ?? 'bell';
}

/* ---------------- AJAX handlers ---------------- */
$__action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($__action && isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    $uid = (int)$_SESSION['user_id'];
    $out = ['success' => false, 'message' => 'Unknown action'];

    try {
        switch ($__action) {
            case 'save_appearance':
            case 'save_appearance_guest':
                $t = $_POST['theme']   ?? 'cream';
                $a = $_POST['accent']  ?? '#5b5fd4';
                $s = $_POST['sidebar'] ?? 'dark';
                if (!in_array($t, ['cream','light','dark','auto'], true)) $t = 'cream';
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $a)) $a = '#5b5fd4';
                if (!in_array($s, ['dark','light','gold'], true)) $s = 'dark';
                setcookie('rjewel_theme', urlencode(json_encode(['theme'=>$t,'accent'=>$a,'sidebar'=>$s])), [
                    'expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax',
                ]);
                $out = ['success' => true, 'theme' => $t, 'accent' => $a, 'sidebar' => $s];
                break;

            case 'get_message_count':
                $count = 0;
                try {
                    $rows  = header_query(
                        "SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0",
                        [$uid]
                    );
                    $count = (int)($rows[0]['cnt'] ?? 0);
                } catch (Throwable $e) { $count = 0; }
                $out = ['success' => true, 'count' => $count];
                break;

            case 'get_messages':
                $limit = max(1, min(200, (int)($_GET['limit'] ?? 10)));
                $rows  = header_query(
                    "SELECT id,type,title,message,link,is_read,created_at
                     FROM notifications WHERE user_id = ?
                     ORDER BY created_at DESC LIMIT $limit",
                    [$uid]
                );
                $msgs = [];
                foreach ($rows as $r) {
                    $msgs[] = [
                        'id'        => (int)$r['id'],
                        'title'     => $r['title'] ?? 'Notification',
                        'message'   => $r['message'] ?? '',
                        'link'      => $r['link'] ?? '',
                        'is_read'   => (int)($r['is_read'] ?? 0),
                        'full_date' => header_full_date($r['created_at'] ?? ''),
                        'icon'      => header_icon($r['type'] ?? 'system'),
                    ];
                }
                $out = ['success' => true, 'messages' => $msgs];
                break;

            case 'mark_message_read':
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) header_exec("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?", [$id, $uid]);
                $out = ['success' => true, 'message' => 'Marked as read'];
                break;

            case 'mark_all_messages_read':
                header_exec("UPDATE notifications SET is_read = 1 WHERE user_id = ?", [$uid]);
                $out = ['success' => true, 'message' => 'All marked as read'];
                break;

            case 'delete_message':
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) header_exec("DELETE FROM notifications WHERE id = ? AND user_id = ?", [$id, $uid]);
                $out = ['success' => true, 'message' => 'Deleted'];
                break;

            case 'delete_all_read_messages':
                header_exec("DELETE FROM notifications WHERE user_id = ? AND is_read = 1", [$uid]);
                $out = ['success' => true, 'message' => 'Read messages deleted'];
                break;

            case 'delete_all_messages':
                header_exec("DELETE FROM notifications WHERE user_id = ?", [$uid]);
                $out = ['success' => true, 'message' => 'All messages deleted'];
                break;
        }
    } catch (Throwable $e) {
        $out = ['success' => false, 'message' => $e->getMessage()];
    }

    echo json_encode($out);
    exit();
}

if ($__action === 'save_appearance_guest') {
    header('Content-Type: application/json');
    $t = $_POST['theme']   ?? 'cream';
    $a = $_POST['accent']  ?? '#5b5fd4';
    $s = $_POST['sidebar'] ?? 'dark';
    if (!in_array($t, ['cream','light','dark','auto'], true)) $t = 'cream';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $a)) $a = '#5b5fd4';
    if (!in_array($s, ['dark','light','gold'], true)) $s = 'dark';
    setcookie('rjewel_theme', urlencode(json_encode(['theme'=>$t,'accent'=>$a,'sidebar'=>$s])), [
        'expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax',
    ]);
    echo json_encode(['success' => true, 'theme' => $t, 'accent' => $a, 'sidebar' => $s]);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo htmlspecialchars($resolved_theme); ?>" data-saved-theme="<?php echo htmlspecialchars($theme); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) : 'R-Jewel&Gold'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500;1,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <script>
    (function () {
        try {
            var saved = <?php echo json_encode($theme); ?>;
            var root  = document.documentElement;
            var resolved = saved;
            if (saved === 'auto') {
                resolved = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)
                    ? 'dark' : 'cream';
            }
            root.setAttribute('data-theme', resolved);
            root.setAttribute('data-saved-theme', saved);
        } catch (e) {}
    })();
    </script>

    <style>
        :root {
            --bg:#f2ede4; --bg-soft:#faf7f2; --card:#ffffff;
            --border:#e5ddd0; --border-light:#ede7db;
            --text:#5a5245; --text-bright:#2b2620;
            --text-muted:#8a8172; --text-dim:#b3a998;
            --primary:<?php echo htmlspecialchars($accent); ?>;
            --primary-soft:<?php echo htmlspecialchars($accent); ?>18;
            --gold:#b8941f; --gold-soft:rgba(184,148,31,0.1); --gold-strong:#8a6e14;
            --success:#0d9668; --danger:#dc2626;
            --section-alt:#faf7f2;
            --nav-bg:rgba(242,237,228,0.94);
            --nav-border:rgba(184,148,31,0.25);
            --dropdown-bg:#ffffff; --dropdown-border:#e5ddd0;
        }
        html[data-theme="dark"] {
            --bg:#151821; --bg-soft:#1c202b; --card:#222735;
            --border:#343b4d; --border-light:#2d3444;
            --text:#d5d9e2; --text-bright:#ffffff;
            --text-muted:#a0a8ba; --text-dim:#777f91;
            --section-alt:#1c202b; --nav-bg:rgba(21,24,33,0.94);
            --nav-border:rgba(184,148,31,0.3);
            --dropdown-bg:#222735; --dropdown-border:#343b4d;
        }
        html[data-theme="light"] {
            --bg:#f5f7fb; --bg-soft:#ffffff; --card:#ffffff;
            --border:#e2e8f0; --border-light:#edf1f6;
            --text:#475569; --text-bright:#172033;
            --text-muted:#64748b; --text-dim:#94a3b8;
            --section-alt:#f5f7fb; --nav-bg:rgba(255,255,255,0.94);
            --nav-border:rgba(184,148,31,0.25);
            --dropdown-bg:#ffffff; --dropdown-border:#e2e8f0;
        }

        body { font-family:'Inter',sans-serif; background:var(--bg); color:var(--text); transition:background-color .3s ease,color .3s ease; margin:0; }
        h1,h2,h3,h4,h5 { font-family:'Cormorant Garamond',serif; color:var(--text-bright); font-weight:600; }

        .lux-nav {
            background: var(--nav-bg); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
            border-bottom: 1px solid var(--nav-border);
            padding: 0.65rem 0;
            position: sticky; top: 0; z-index: 1050;
            box-shadow: 0 6px 20px -12px rgba(0,0,0,0.08);
        }
        .lux-nav .container {
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: nowrap; gap: 1rem; max-width: 1440px;
        }

        .lux-brand { display:flex; align-items:center; gap:10px; text-decoration:none; line-height:1; flex-shrink:0; }
        .lux-brand-icon { width:44px; height:44px; flex-shrink:0; display:flex; align-items:center; justify-content:center; }
        .lux-brand-icon svg { width:100%; height:100%; display:block; filter: drop-shadow(0 2px 5px rgba(184,148,31,0.25)); }
        .lux-brand-text { font-family:'Cormorant Garamond',serif; font-size:1.85rem; font-weight:700; display:flex; align-items:baseline; line-height:1; white-space:nowrap; }
        .lux-brand-text .part-1 { color: var(--text-bright); }
        .lux-brand-text .part-2 { color: var(--gold); font-style:italic; font-weight:600; margin-left:1px; }
        html[data-theme="dark"] .lux-brand-text .part-1 { color:#fff; }
        html[data-theme="dark"] .lux-brand-text .part-2 { color:#d4a72c; }

        .lux-menu {
            display: flex; align-items: center; gap: 0.35rem;
            list-style: none; margin: 0; padding: 0;
            flex-wrap: nowrap; min-width: 0;
        }
        .lux-menu > li { position:relative; display:flex; align-items:center; flex-shrink:0; }

        .lux-menu > li > a,
        .lux-menu > li > .lux-settings-trigger,
        .lux-menu > li > .lux-msg-trigger {
            display:inline-flex; align-items:center; justify-content:center; gap:8px;
            padding: 0.55rem 0.95rem;
            font-family:'Inter',sans-serif; font-size:0.8rem; font-weight:600;
            letter-spacing:0.06em; text-transform:uppercase;
            color: var(--text-muted); text-decoration:none; background: transparent;
            border: 1px solid transparent; border-radius: 6px;
            border-bottom: 2px solid transparent;
            transition: color 0.2s ease, border-color 0.2s ease, background-color 0.2s ease;
            white-space:nowrap; cursor:pointer; line-height:1.2;
            -webkit-tap-highlight-color: transparent; position: relative;
        }
        .lux-menu > li > a i,
        .lux-menu > li > .lux-settings-trigger i,
        .lux-menu > li > .lux-msg-trigger i {
            font-size: 0.82rem; color: var(--gold); opacity: 0.85;
            pointer-events: none; flex-shrink: 0;
        }
        .lux-menu > li > a.nav-link-box:hover,
        .lux-menu > li > a.nav-link-box:focus,
        .lux-menu > li > a.nav-link-box:active {
            color: var(--text-bright);
            border-color: var(--border); border-bottom-color: var(--gold);
            background: var(--gold-soft); outline: none;
        }
        .lux-menu > li > a.nav-link-box.is-active {
            color: var(--text-bright);
            border-color: var(--gold); border-bottom-color: var(--gold);
            background: var(--gold-soft);
        }
        .lux-menu > li > a.nav-link-box.is-active i { opacity: 1; }

        .lux-menu > li > .lux-settings-trigger:hover,
        .lux-menu > li > .lux-msg-trigger:hover,
        .lux-menu > li > .lux-settings-trigger:focus,
        .lux-menu > li > .lux-msg-trigger:focus,
        .lux-msg-wrapper.open > .lux-msg-trigger,
        .lux-dropdown.open  > .lux-settings-trigger {
            color: var(--text-bright);
            background: transparent;
            border-color: transparent; border-bottom-color: transparent;
            outline: none;
        }
        .lux-msg-wrapper.open > .lux-msg-trigger i,
        .lux-dropdown.open  > .lux-settings-trigger i { opacity: 1; }

        .lux-menu > li > .lux-msg-trigger.lux-msg-trigger--icon { padding: 0.55rem 0.75rem; gap: 0; position: relative; }
        .lux-menu > li > .lux-msg-trigger.lux-msg-trigger--icon i { font-size: 1rem; color: var(--gold); opacity: 0.9; transition: transform 0.2s ease, opacity 0.2s ease; }
        .lux-menu > li > .lux-msg-trigger.lux-msg-trigger--icon:hover i,
        .lux-menu > li > .lux-msg-trigger.lux-msg-trigger--icon:focus i,
        .lux-msg-wrapper.open > .lux-msg-trigger.lux-msg-trigger--icon i { transform: scale(1.08); opacity: 1; }
        .lux-menu > li > .lux-msg-trigger.lux-msg-trigger--icon .lux-msg-badge {
            position: absolute; top: 2px; right: 2px;
            min-width: 16px; height: 16px; font-size: 0.58rem; padding: 0 4px;
            box-shadow: 0 1px 4px rgba(220,38,38,0.45);
        }
        .lux-menu > li > .lux-msg-trigger.lux-msg-trigger--icon .msg-dot { top: 6px; right: 8px; width: 6px; height: 6px; }

        .lux-cart-badge {
            display:inline-flex; align-items:center; justify-content:center;
            background:linear-gradient(135deg,var(--gold),var(--gold-strong));
            color:#fff; font-size:0.62rem; font-weight:700;
            min-width:18px; height:18px; padding:0 5px; border-radius:20px;
            box-shadow:0 2px 6px rgba(184,148,31,0.4);
            flex-shrink:0; pointer-events:none;
        }
        .lux-msg-badge {
            display:inline-flex; align-items:center; justify-content:center;
            background:linear-gradient(135deg,#dc2626,#b91c1c);
            color:#fff; font-size:0.62rem; font-weight:700;
            min-width:18px; height:18px; padding:0 5px; border-radius:20px;
            box-shadow:0 2px 6px rgba(220,38,38,0.4);
            flex-shrink:0; pointer-events:none;
        }
        .lux-msg-badge.hidden { display:none; }
        .lux-msg-badge.bump { animation: bump 0.4s ease; }
        @keyframes bump { 0%,100%{transform:scale(1);} 50%{transform:scale(1.3);} }

        .lux-msg-trigger .msg-dot {
            position:absolute; top:5px; right:10px;
            width:7px; height:7px; background:#dc2626;
            border-radius:50%; border:1.5px solid var(--card);
            animation: pulse-msg 1.8s infinite; pointer-events:none;
        }
        .lux-msg-trigger .msg-dot.hidden { display:none; }
        @keyframes pulse-msg {
            0% { box-shadow:0 0 0 0 rgba(220,38,38,0.6); }
            70% { box-shadow:0 0 0 6px rgba(220,38,38,0); }
            100% { box-shadow:0 0 0 0 rgba(220,38,38,0); }
        }

        .lux-msg-wrapper { position:relative; }
        .lux-msg-panel {
            position:absolute; top:calc(100% + 10px); right:0;
            width:420px; max-width:calc(100vw - 24px);
            background:var(--dropdown-bg);
            border:1px solid var(--dropdown-border); border-radius:14px;
            box-shadow:0 24px 48px -18px rgba(0,0,0,0.22);
            opacity:0; visibility:hidden; transform:translateY(-8px);
            transition:opacity 0.25s ease, transform 0.25s ease, visibility 0.25s;
            z-index:1150; overflow:hidden; backdrop-filter:blur(10px);
            display:flex; flex-direction:column;
        }
        .lux-msg-wrapper.open .lux-msg-panel { opacity:1; visibility:visible; transform:translateY(0); }

        .lux-msg-header {
            display:flex; align-items:center; justify-content:space-between;
            padding:14px 18px; border-bottom:1px solid var(--border-light);
            flex-shrink:0;
        }
        .lux-msg-header h6 {
            font-family:'Cormorant Garamond',serif; font-size:1.2rem;
            color:var(--text-bright); font-weight:600; margin:0;
            display:flex; align-items:center; gap:8px;
        }
        .lux-msg-header h6 i { color:var(--gold); font-size:1rem; }
        .lux-msg-mark-all {
            background:transparent; border:none; color:var(--primary);
            font-size:0.75rem; font-weight:600; cursor:pointer;
            padding:5px 9px; border-radius:6px; transition:background-color 0.2s ease;
        }
        .lux-msg-mark-all:hover { background:var(--primary-soft); }
        .lux-msg-mark-all:disabled { opacity:0.4; cursor:not-allowed; }

        .lux-msg-body { max-height:400px; overflow-y:auto; overscroll-behavior:contain; }
        .lux-msg-body::-webkit-scrollbar { width:6px; }
        .lux-msg-body::-webkit-scrollbar-thumb { background:var(--border); border-radius:3px; }

        .lux-msg-item {
            display:flex; gap:12px; padding:13px 18px;
            border-bottom:1px solid var(--border-light);
            transition:background-color 0.15s ease; position:relative;
        }
        .lux-msg-item:last-child { border-bottom:none; }
        .lux-msg-item:hover { background:var(--bg-soft); }
        .lux-msg-item.unread { background:var(--primary-soft); }
        .lux-msg-item.unread::before {
            content:''; position:absolute; left:7px; top:50%;
            transform:translateY(-50%); width:5px; height:5px;
            background:var(--primary); border-radius:50%;
        }
        .lux-msg-item-icon {
            width:42px; height:42px; border-radius:10px;
            background:var(--gold-soft); color:var(--gold);
            display:flex; align-items:center; justify-content:center;
            font-size:0.95rem; flex-shrink:0;
        }
        .lux-msg-item.unread .lux-msg-item-icon { background:var(--primary-soft); color:var(--primary); }
        .lux-msg-item-content { flex:1; min-width:0; }
        .lux-msg-item-title {
            font-size:0.85rem; font-weight:600; color:var(--text-bright);
            margin:0 0 3px; line-height:1.35;
        }
        .lux-msg-item-text {
            font-size:0.78rem; color:var(--text-muted);
            margin:0 0 5px; line-height:1.45;
            display:-webkit-box; -webkit-line-clamp:2;
            -webkit-box-orient:vertical; overflow:hidden;
        }
        .lux-msg-item-time {
            font-size:0.7rem; color:var(--text-dim);
            display:flex; align-items:center; gap:5px;
        }
        .lux-msg-item-actions { display:flex; gap:6px; margin-top:8px; flex-wrap:wrap; }
        .lux-msg-item-btn {
            display:inline-flex; align-items:center; gap:4px;
            padding:4px 9px; border-radius:6px;
            font-size:0.68rem; font-weight:600;
            cursor:pointer; border:1px solid var(--border);
            background:transparent; color:var(--text-muted);
            text-decoration:none; transition:all 0.15s ease;
            font-family:'Inter',sans-serif;
        }
        .lux-msg-item-btn:hover { border-color:var(--gold); color:var(--gold); background:var(--gold-soft); }
        .lux-msg-item-btn.danger:hover { border-color:var(--danger); color:var(--danger); background:rgba(220,38,38,0.08); }

        .lux-msg-empty { padding:48px 20px; text-align:center; color:var(--text-dim); }
        .lux-msg-empty i { font-size:2.2rem; margin-bottom:12px; opacity:0.5; display:block; color:var(--gold); }
        .lux-msg-empty p { margin:0; font-size:0.86rem; }

        .lux-msg-footer {
            padding:12px 18px; border-top:1px solid var(--border-light);
            display:flex; gap:8px; justify-content:space-between; align-items:center;
            flex-shrink:0; flex-wrap:wrap;
        }
        .lux-msg-footer-left { font-size:0.74rem; color:var(--text-muted); font-weight:600; }
        .lux-msg-footer-left strong { color:var(--text-bright); }
        .lux-msg-footer-actions { display:flex; gap:6px; flex-wrap:wrap; }

        .lux-msg-viewall {
            display:inline-flex; align-items:center; gap:6px;
            background:var(--primary); color:#fff; border:none;
            font-size:0.74rem; font-weight:600; padding:6px 12px;
            border-radius:6px; cursor:pointer; text-decoration:none;
            transition:background-color 0.2s ease;
            font-family:'Inter',sans-serif;
        }
        .lux-msg-viewall:hover { background:#4f46e5; color:#fff; }

        .lux-msg-footer-btn {
            background:transparent; border:1px solid var(--border);
            color:var(--text-muted); font-size:0.7rem; font-weight:600;
            padding:5px 10px; border-radius:6px; cursor:pointer;
            transition:all 0.15s ease; font-family:'Inter',sans-serif;
            display:inline-flex; align-items:center; gap:5px;
        }
        .lux-msg-footer-btn:hover { border-color:var(--primary); color:var(--primary); }
        .lux-msg-footer-btn.danger:hover { border-color:var(--danger); color:var(--danger); }
        .lux-msg-footer-btn:disabled { opacity:0.4; cursor:not-allowed; }

        .lux-dropdown { position:relative; }
        .lux-settings-trigger { display:inline-flex !important; align-items:center; justify-content:center; gap:8px; }
        .lux-settings-trigger .chevron { font-size:0.64rem; opacity:0.6; transition:transform 0.25s ease; color:var(--text-muted); pointer-events:none; }
        .lux-dropdown.open .lux-settings-trigger .chevron { transform:rotate(180deg); }

        .lux-settings-panel {
            position:absolute; top:calc(100% + 10px); right:0;
            width:330px; background:var(--dropdown-bg);
            border:1px solid var(--dropdown-border); border-radius:14px;
            box-shadow:0 24px 48px -18px rgba(0,0,0,0.22);
            padding:1.15rem 1.25rem 1.25rem;
            opacity:0; visibility:hidden; transform:translateY(-8px);
            transition:opacity 0.28s ease, transform 0.28s ease, visibility 0.28s;
            z-index:1150; backdrop-filter:blur(10px);
        }
        .lux-dropdown.open .lux-settings-panel { opacity:1; visibility:visible; transform:translateY(0); }

        .lux-settings-header { display:flex; align-items:center; justify-content:space-between; padding-bottom:0.8rem; margin-bottom:0.95rem; border-bottom:1px solid var(--border-light); }
        .lux-settings-header h6 { font-family:'Cormorant Garamond',serif; font-size:1.25rem; color:var(--text-bright); font-weight:600; margin:0; display:flex; align-items:center; gap:8px; }
        .lux-settings-header h6 i { color:var(--gold); font-size:1rem; }
        .lux-settings-close { background:transparent; border:none; color:var(--text-muted); cursor:pointer; font-size:0.9rem; padding:5px 7px; border-radius:6px; transition:color 0.2s ease, background-color 0.2s ease; }
        .lux-settings-close:hover { color:var(--gold); background:var(--gold-soft); }

        .lux-settings-group { margin-bottom:1.1rem; }
        .lux-settings-group:last-child { margin-bottom:0; }
        .lux-settings-label { font-family:'Inter',sans-serif; font-size:0.64rem; font-weight:700; letter-spacing:0.2em; text-transform:uppercase; color:var(--text-muted); margin-bottom:0.6rem; display:block; }
        .lux-account-links { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:3px; }
        .lux-account-links li a { display:flex; align-items:center; gap:11px; padding:0.65rem 0.75rem; font-family:'Inter',sans-serif; font-size:0.8rem; font-weight:500; color:var(--text); text-decoration:none; border-radius:8px; transition:background-color 0.2s ease, color 0.2s ease; }
        .lux-account-links li a i { color:var(--gold); width:19px; font-size:0.86rem; opacity:0.85; text-align:center; }
        .lux-account-links li a:hover { background:var(--gold-soft); color:var(--text-bright); }

        .lux-account-links li a.lux-logout-link { color:var(--danger); }
        .lux-account-links li a.lux-logout-link i { color:var(--danger); }
        .lux-account-links li a.lux-logout-link:hover { background:rgba(220,38,38,0.08); color:var(--danger); }

        .lux-theme-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:8px; }
        .lux-theme-btn { position:relative; cursor:pointer; border:2px solid var(--border); border-radius:9px; overflow:hidden; transition:transform 0.22s ease, border-color 0.22s ease, box-shadow 0.22s ease; background:var(--card); aspect-ratio:1/1; display:flex; flex-direction:column; }
        .lux-theme-btn:hover { transform:translateY(-3px); }
        .lux-theme-btn.active { border-color:var(--gold); box-shadow:0 0 0 3px rgba(184,148,31,0.15); }
        .lux-theme-btn input { position:absolute; opacity:0; pointer-events:none; }
        .lux-theme-swatch { flex:1; width:100%; }
        .lux-theme-swatch.cream { background:#f2ede4; }
        .lux-theme-swatch.light { background:#f5f7fb; }
        .lux-theme-swatch.dark  { background:#151821; }
        .lux-theme-swatch.auto  { background:linear-gradient(135deg,#f2ede4 50%,#151821 50%); }
        .lux-theme-name { font-family:'Inter',sans-serif; font-size:0.56rem; font-weight:700; letter-spacing:0.08em; text-transform:uppercase; text-align:center; padding:4px 2px; color:var(--text-muted); background:var(--card); border-top:1px solid var(--border-light); }
        .lux-theme-btn.active .lux-theme-name { color:var(--gold); }

        .lux-settings-reset { width:100%; margin-top:0.8rem; padding:0.62rem; border-radius:8px; border:1px dashed var(--border); background:transparent; color:var(--text-muted); font-family:'Inter',sans-serif; font-size:0.66rem; font-weight:600; letter-spacing:0.16em; text-transform:uppercase; cursor:pointer; transition:border-color 0.2s ease, color 0.2s ease; display:inline-flex; align-items:center; justify-content:center; gap:6px; }
        .lux-settings-reset:hover { border-color:var(--gold); color:var(--gold); }
        .lux-settings-status { font-size:0.66rem; color:var(--success); font-weight:600; letter-spacing:0.1em; text-transform:uppercase; opacity:0; transition:opacity 0.3s; display:inline-flex; align-items:center; gap:4px; }
        .lux-settings-status.show { opacity:1; }

        .lux-toggle { display:none; background:transparent; border:1px solid var(--border); border-radius:8px; padding:0.5rem 0.8rem; cursor:pointer; color:var(--text-bright); font-size:1.3rem; transition:color 0.2s ease, border-color 0.2s ease; line-height:1; }
        .lux-toggle:hover { border-color:var(--gold); color:var(--gold); }

        .lux-toast {
            position:fixed; bottom:24px; right:24px;
            background:var(--card); border:1px solid var(--border);
            border-left:4px solid var(--success); border-radius:10px;
            padding:13px 18px; box-shadow:0 12px 30px -8px rgba(0,0,0,0.15);
            display:flex; gap:10px; align-items:center;
            font-size:0.84rem; font-weight:500; color:var(--text-bright);
            z-index:3000; opacity:0; transform:translateY(20px); visibility:hidden;
            transition:all 0.3s ease; max-width:340px;
        }
        .lux-toast.show { opacity:1; transform:translateY(0); visibility:visible; }
        .lux-toast i { color:var(--success); font-size:1rem; flex-shrink:0; }
        .lux-toast.error { border-left-color:var(--danger); }
        .lux-toast.error i { color:var(--danger); }

        .wish-heart {
            position: absolute; top: 12px; right: 12px;
            width: 36px; height: 36px; border-radius: 50%;
            background: rgba(255,255,255,0.95);
            border: 1px solid var(--border); color: var(--text-muted);
            cursor: pointer; display: inline-flex;
            align-items: center; justify-content: center;
            font-size: 0.95rem; transition: all 0.25s ease;
            z-index: 5; box-shadow: 0 4px 12px -4px rgba(0,0,0,0.2);
        }
        .wish-heart:hover { color: var(--danger); border-color: var(--danger); transform: scale(1.08); }
        .wish-heart.is-active {
            color: #fff;
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            border-color: transparent;
            box-shadow: 0 4px 14px -3px rgba(220,38,38,0.6);
        }
        .wish-heart.is-active i::before { font-family: "Font Awesome 6 Free"; font-weight: 900; }
        .wish-heart.busy { opacity: 0.6; pointer-events: none; }

        .lux-modal-overlay {
            position: fixed; inset: 0;
            background: rgba(20, 16, 10, 0.55);
            backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
            display: flex; align-items: center; justify-content: center;
            padding: 1.25rem; z-index: 5000;
            opacity: 0; visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
            overflow-y: auto;
        }
        .lux-modal-overlay.open { opacity: 1; visibility: visible; }
        .lux-modal-overlay .lux-modal { transform: translateY(20px) scale(0.98); transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1); }
        .lux-modal-overlay.open .lux-modal { transform: translateY(0) scale(1); }

        .lux-modal {
            width: 100%; max-width: 560px;
            background: var(--card); border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 40px 80px -24px rgba(0,0,0,0.35), 0 12px 28px -10px rgba(0,0,0,0.15), 0 0 0 1px rgba(255,255,255,0.4) inset;
            padding: 1.75rem 2rem 1.5rem; position: relative; margin: auto;
        }
        #loginModal { max-width: 440px; }
        #registerModal { max-width: 600px; }
        html[data-theme="dark"] .lux-modal { box-shadow: 0 40px 80px -24px rgba(0,0,0,0.7), 0 12px 28px -10px rgba(0,0,0,0.4), 0 0 0 1px rgba(255,255,255,0.05) inset; }

        .lux-modal::before, .lux-modal::after {
            content: ''; position: absolute; width: 26px; height: 26px;
            border-color: var(--gold); border-style: solid;
            opacity: 0.7; pointer-events: none;
        }
        .lux-modal::before { top: 11px; left: 11px; border-width: 1.5px 0 0 1.5px; border-top-left-radius: 16px; }
        .lux-modal::after { bottom: 11px; right: 11px; border-width: 0 1.5px 1.5px 0; border-bottom-right-radius: 16px; }

        .lux-modal-close {
            position: absolute; top: 12px; right: 12px;
            width: 32px; height: 32px; border-radius: 50%;
            border: none; background: transparent;
            color: var(--text-muted); font-size: 0.9rem;
            cursor: pointer; display: flex; align-items: center; justify-content: center;
            transition: background-color 0.2s ease, color 0.2s ease; z-index: 10;
        }
        .lux-modal-close:hover { background: var(--gold-soft); color: var(--gold); }

        .lux-modal-head { display: flex; align-items: center; gap: 0.9rem; margin-bottom: 1rem; padding-right: 2rem; }
        .lux-modal-icon { width: 48px; height: 48px; flex-shrink: 0; }
        .lux-modal-icon svg { width: 100%; height: 100%; filter: drop-shadow(0 4px 12px rgba(184,148,31,0.35)); }
        .lux-modal-head-text { flex: 1; min-width: 0; }
        .lux-modal-title { font-family: 'Cormorant Garamond', serif; font-size: 1.6rem; font-weight: 600; color: var(--text-bright); letter-spacing: 0.02em; margin: 0; line-height: 1.1; }
        .lux-modal-sub { font-family: 'Inter', sans-serif; font-size: 0.6rem; color: var(--text-dim); letter-spacing: 0.3em; text-transform: uppercase; margin: 3px 0 0; font-weight: 600; }

        .lux-modal-divider { display: flex; align-items: center; justify-content: center; margin-bottom: 1rem; gap: 10px; }
        .lux-divider-line { flex: 1; height: 1px; background: linear-gradient(90deg, transparent, rgba(184,148,31,0.35), transparent); }
        .lux-divider-diamond { width: 5px; height: 5px; background: var(--gold); transform: rotate(45deg); box-shadow: 0 0 8px rgba(184,148,31,0.5); flex-shrink: 0; }

        .lux-modal-alert {
            background: rgba(220,38,38,0.06); border: 1px solid rgba(220,38,38,0.2);
            color: #b91c1c; border-radius: 9px;
            padding: 0.6rem 0.85rem; font-size: 0.76rem; font-weight: 500;
            display: flex; align-items: flex-start; gap: 8px;
            margin-bottom: 0.85rem; line-height: 1.45;
        }
        .lux-modal-alert i { font-size: 0.85rem; margin-top: 2px; flex-shrink: 0; }
        html[data-theme="dark"] .lux-modal-alert { background: rgba(220,38,38,0.12); color: #fca5a5; }
        .lux-modal-alert.success { background: rgba(13,150,104,0.08); border-color: rgba(13,150,104,0.25); color: #0d9668; }
        html[data-theme="dark"] .lux-modal-alert.success { background: rgba(13,150,104,0.15); color: #4ade80; }

        .lux-modal-field { margin-bottom: 0.7rem; }
        .lux-modal-label { display: block; font-size: 0.62rem; font-weight: 700; color: var(--text); letter-spacing: 0.1em; text-transform: uppercase; margin-bottom: 0.35rem; }
        .lux-req { color: var(--danger); }
        .lux-opt { color: var(--text-dim); font-weight: 500; text-transform: none; letter-spacing: 0; font-size: 0.64rem; margin-left: 3px; }

        .lux-input-wrap { position: relative; }
        .lux-field-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-dim); font-size: 0.8rem; pointer-events: none; transition: color 0.2s ease; z-index: 2; }
        .lux-field-icon.lux-textarea-icon { top: 12px; transform: none; }
        .lux-modal-input {
            width: 100%; border: 1px solid var(--border); border-radius: 8px;
            padding: 0.6rem 0.85rem 0.6rem 2.25rem;
            font-size: 0.84rem; font-family: 'Inter', sans-serif;
            color: var(--text-bright); background: var(--bg-soft);
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
            height: auto; outline: none;
        }
        .lux-modal-input:focus { border-color: var(--primary); background: var(--card); box-shadow: 0 0 0 3px var(--primary-soft); }
        .lux-input-wrap:focus-within .lux-field-icon { color: var(--primary); }
        .lux-modal-input::placeholder { color: var(--text-dim); font-size: 0.8rem; }
        .lux-modal-textarea { resize: none; min-height: 54px; padding-top: 0.55rem; line-height: 1.45; }

        .lux-toggle-pw { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: transparent; border: none; color: var(--text-dim); font-size: 0.85rem; cursor: pointer; padding: 4px; line-height: 1; transition: color 0.2s ease; z-index: 3; }
        .lux-toggle-pw:hover { color: var(--gold); }

        .lux-field-hint { display: block; font-size: 0.62rem; color: var(--text-dim); margin-top: 0.2rem; letter-spacing: 0.02em; }

        .lux-modal-extras { display: flex; align-items: center; justify-content: space-between; margin: 0.35rem 0 0.85rem; gap: 10px; }
        .lux-modal-check { display: flex; align-items: center; gap: 7px; font-size: 0.72rem; color: var(--text-muted); cursor: pointer; user-select: none; margin: 0; }
        .lux-modal-check input[type="checkbox"] {
            appearance: none; -webkit-appearance: none;
            width: 14px; height: 14px;
            border: 1.5px solid var(--border); border-radius: 4px;
            background: var(--bg-soft); cursor: pointer; position: relative;
            transition: all 0.2s ease; flex-shrink: 0; margin: 0;
        }
        .lux-modal-check input[type="checkbox"]:checked { background: var(--gold); border-color: var(--gold); }
        .lux-modal-check input[type="checkbox"]:checked::after {
            content: ''; position: absolute;
            left: 3px; top: 0.5px; width: 4px; height: 7px;
            border: solid #fff; border-width: 0 1.6px 1.6px 0;
            transform: rotate(45deg);
        }
        .lux-modal-forgot { font-size: 0.7rem; font-weight: 600; color: var(--text-muted); text-decoration: none; letter-spacing: 0.04em; transition: color 0.2s ease; }
        .lux-modal-forgot:hover { color: var(--gold); }

        .lux-modal-btn {
            width: 100%; padding: 0.7rem; border: none; border-radius: 8px;
            background: linear-gradient(135deg, var(--gold), var(--gold-strong));
            color: #fff; font-weight: 600; font-size: 0.74rem;
            font-family: 'Inter', sans-serif; letter-spacing: 0.2em;
            text-transform: uppercase; margin-top: 0.3rem;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
            position: relative; overflow: hidden;
            box-shadow: 0 4px 14px -3px rgba(184,148,31,0.45); cursor: pointer;
        }
        .lux-modal-btn::before {
            content: ''; position: absolute; top: 0; left: -100%;
            width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.25), transparent);
            transition: left 0.6s ease;
        }
        .lux-modal-btn:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 8px 22px -5px rgba(184,148,31,0.6); }
        .lux-modal-btn:hover:not(:disabled)::before { left: 100%; }
        .lux-modal-btn:active:not(:disabled) { transform: translateY(0); }
        .lux-modal-btn:disabled { opacity: 0.75; cursor: not-allowed; }

        .lux-modal-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 0.85rem; }
        .lux-modal-grid .lux-col-full { grid-column: 1 / -1; }

        .lux-modal-bottom { text-align: center; margin-top: 1rem; padding-top: 0.85rem; border-top: 1px solid var(--border-light); }
        .lux-modal-bottom p { font-size: 0.74rem; color: var(--text-muted); margin: 0; }
        .lux-modal-bottom a { color: var(--gold); font-weight: 600; text-decoration: none; transition: color 0.2s ease; }
        .lux-modal-bottom a:hover { color: var(--gold-strong); text-decoration: underline; }

        /* ---------- SIMPLE LOGOUT CONFIRM MODAL ---------- */
        #logoutModal {
            max-width: 400px;
            padding: 1.75rem 1.85rem 1.5rem;
        }
        .logout-title {
            font-family: 'Inter', sans-serif;
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--text-bright);
            margin: 0 0 0.6rem;
            letter-spacing: -0.01em;
        }
        .logout-desc {
            font-family: 'Inter', sans-serif;
            font-size: 0.88rem;
            line-height: 1.55;
            color: var(--text-muted);
            margin: 0 0 1.35rem;
        }
        .logout-desc strong { color: var(--text-bright); font-weight: 600; }
        .logout-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .logout-btn {
            padding: 0.7rem 1rem;
            border-radius: 8px;
            border: 1px solid transparent;
            font-family: 'Inter', sans-serif;
            font-size: 0.74rem;
            font-weight: 600;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .logout-btn-no {
            background: transparent;
            color: var(--text-bright);
            border-color: var(--border);
        }
        .logout-btn-no:hover {
            border-color: var(--gold);
            color: var(--gold);
            background: var(--gold-soft);
        }
        .logout-btn-yes {
            background: var(--danger);
            color: #fff;
        }
        .logout-btn-yes:hover {
            background: #b91c1c;
            color: #fff;
        }
        @media (max-width: 420px) {
            .logout-actions { grid-template-columns: 1fr; }
        }

        body.lux-modal-open { overflow: hidden; }

        @media (max-width: 991px) {
            .lux-nav { padding:0.6rem 0; }
            .lux-toggle { display:block; }
            .lux-nav .container { flex-wrap: wrap; }
            .lux-brand { gap:8px; }
            .lux-brand-icon { width:36px; height:36px; }
            .lux-brand-text { font-size:1.45rem; }

            .lux-menu {
                display:none; width:100%; flex-direction:column; align-items:stretch;
                gap:0.3rem; padding:0.9rem 0 0.75rem;
                border-top:1px solid var(--border-light); margin-top:0.75rem;
                flex-wrap: wrap;
            }
            .lux-menu.open { display:flex; }
            .lux-menu > li { width:100%; display:block; }

            .lux-menu > li > a,
            .lux-menu > li > .lux-settings-trigger,
            .lux-menu > li > .lux-msg-trigger {
                width:100%; justify-content:flex-start; padding:0.95rem 1.05rem;
                border-radius:8px; font-size:0.9rem; letter-spacing:0.08em;
                gap:12px; text-align:left;
            }
            .lux-menu > li > a i,
            .lux-menu > li > .lux-settings-trigger i,
            .lux-menu > li > .lux-msg-trigger i { font-size:0.95rem; width:20px; text-align:center; }

            .lux-menu > li > .lux-msg-trigger.lux-msg-trigger--icon {
                justify-content: flex-start;
                padding: 0.95rem 1.05rem;
                gap: 12px;
            }
            .lux-menu > li > .lux-msg-trigger.lux-msg-trigger--icon::after {
                content: 'Messages';
                font-family: 'Inter', sans-serif;
                font-size: 0.9rem;
                font-weight: 600;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                color: var(--text-muted);
            }
            .lux-menu > li > .lux-msg-trigger.lux-msg-trigger--icon .lux-msg-badge {
                position: static; margin-left: auto;
                min-width: 20px; height: 20px; font-size: 0.66rem; padding: 0 6px;
            }
            .lux-menu > li > .lux-msg-trigger.lux-msg-trigger--icon .msg-dot { display: none; }

            .lux-menu > li > a.nav-link-box,
            .lux-menu > li > a.nav-link-box:hover,
            .lux-menu > li > a.nav-link-box.is-active { border-color: var(--border); }
            .lux-menu > li > a.nav-link-box:hover,
            .lux-menu > li > a.nav-link-box.is-active { border-bottom-color: var(--gold); }

            .lux-msg-panel { position:static; width:100%; max-width:none; opacity:1; visibility:visible; transform:none; box-shadow:none; border:1px solid var(--border); margin-top:0.45rem; display:none; border-radius:12px; }
            .lux-msg-wrapper.open .lux-msg-panel { display:flex; }
            .lux-msg-body { max-height:320px; }

            .lux-settings-panel { position:static; width:100%; opacity:1; visibility:visible; transform:none; box-shadow:none; border:1px solid var(--border); margin-top:0.45rem; display:none; padding:1.05rem 1.15rem 1.15rem; border-radius:12px; }
            .lux-dropdown.open .lux-settings-panel { display:block; }

            .lux-cart-badge, .lux-msg-badge { min-width:20px; height:20px; font-size:0.66rem; padding:0 6px; }
        }

        @media (max-width: 575px) {
            .lux-brand { gap:7px; }
            .lux-brand-icon { width:32px; height:32px; }
            .lux-brand-text { font-size:1.25rem; }
            .lux-toggle { font-size:1.2rem; padding:0.45rem 0.7rem; }
        }

        @media (max-width: 620px) {
            .lux-modal-overlay { padding: 0.85rem; }
            .lux-modal { padding: 1.4rem 1.35rem 1.15rem; border-radius: 14px; }
            #registerModal { max-width: 100%; }
            .lux-modal::before, .lux-modal::after { width: 20px; height: 20px; }
            .lux-modal-title { font-size: 1.35rem; }
            .lux-modal-icon { width: 42px; height: 42px; }
            .lux-modal-grid { grid-template-columns: 1fr; gap: 0; }
            .lux-modal-grid .lux-col-full { grid-column: auto; }
            .lux-modal-field { margin-bottom: 0.6rem; }
        }
        @media (max-width: 380px) {
            .lux-modal { padding: 1.25rem 1.1rem 1rem; }
            .lux-modal-title { font-size: 1.2rem; }
        }

        @media (prefers-reduced-motion: reduce) { * { animation-duration:.01ms !important; transition-duration:.01ms !important; } }
    </style>
</head>
<body>
    <nav class="lux-nav">
        <div class="container">
            <a class="lux-brand" href="index.php" aria-label="R-Jewel&Gold Home">
                <span class="lux-brand-icon">
                    <svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M50 5 L90 35 L50 95 L10 35 Z" stroke="#b8941f" stroke-width="2.5" fill="none" stroke-linejoin="round"/>
                        <path d="M10 35 L90 35" stroke="#b8941f" stroke-width="2" opacity="0.9"/>
                        <path d="M50 5 L50 35" stroke="#b8941f" stroke-width="1.6" opacity="0.75"/>
                        <path d="M50 35 L50 95" stroke="#b8941f" stroke-width="1.6" opacity="0.75"/>
                        <path d="M50 5 L10 35" stroke="#b8941f" stroke-width="1.6" opacity="0.75"/>
                        <path d="M50 5 L90 35" stroke="#b8941f" stroke-width="1.6" opacity="0.75"/>
                    </svg>
                </span>
                <span class="lux-brand-text">
                    <span class="part-1">R-Jewel</span><span class="part-2">&amp;Gold</span>
                </span>
            </a>

            <button class="lux-toggle" id="luxToggle" aria-label="Toggle navigation" type="button">
                <i class="fas fa-bars"></i>
            </button>

            <ul class="lux-menu" id="luxMenu">
                <li>
                    <a href="index.php" class="nav-link-box <?php echo $__is_home ? 'is-active' : ''; ?>">
                        <i class="fas fa-home"></i> Home
                    </a>
                </li>
                <li>
                    <a href="shop.php" class="nav-link-box <?php echo $__is_shop ? 'is-active' : ''; ?>">
                        <i class="fas fa-shopping-bag"></i> Shop
                    </a>
                </li>
                <li>
                    <a href="virtual-tryon.php" class="nav-link-box <?php echo $__is_tryon ? 'is-active' : ''; ?>">
                        <i class="fas fa-camera"></i> Virtual Try-On
                    </a>
                </li>
                <li>
                    <a href="about.php" class="nav-link-box <?php echo $__is_about ? 'is-active' : ''; ?>">
                        <i class="fas fa-info-circle"></i> About
                    </a>
                </li>

                <?php if(isset($_SESSION['user_id'])): ?>
                    <?php if($_SESSION['role'] == 'admin'): ?>
                        <li>
                            <a href="../admin/index.php" class="nav-link-box <?php echo $__is_admin ? 'is-active' : ''; ?>">
                                <i class="fas fa-shield-alt"></i> Admin
                            </a>
                        </li>
                    <?php endif; ?>

                    <li>
                        <a href="cart.php" class="nav-link-box <?php echo $__is_cart ? 'is-active' : ''; ?>">
                            <i class="fas fa-shopping-cart"></i> Cart
                            <span class="lux-cart-badge cart-count">0</span>
                        </a>
                    </li>

                    <li class="lux-msg-wrapper" id="luxMsgWrapper">
                        <a href="javascript:void(0);"
                           class="lux-msg-trigger lux-msg-trigger--icon"
                           id="luxMsgLink"
                           aria-label="Messages"
                           title="Messages"
                           aria-haspopup="true"
                           aria-expanded="false">
                            <i class="fas fa-envelope"></i>
                            <span class="msg-dot hidden" id="luxMsgDot"></span>
                            <span class="lux-msg-badge hidden" id="luxMsgBadge">0</span>
                        </a>

                        <div class="lux-msg-panel" id="luxMsgPanel">
                            <div class="lux-msg-header">
                                <h6><i class="fas fa-envelope-open-text"></i> Messages</h6>
                                <button type="button" class="lux-msg-mark-all" id="luxMsgMarkAll">Mark all read</button>
                            </div>
                            <div class="lux-msg-body" id="luxMsgBody">
                                <div class="lux-msg-empty">
                                    <i class="fas fa-spinner fa-spin"></i>
                                    <p>Loading messages...</p>
                                </div>
                            </div>
                            <div class="lux-msg-footer">
                                <div class="lux-msg-footer-left">
                                    <strong id="luxMsgUnreadCount">0</strong> unread of <strong id="luxMsgTotalCount">0</strong>
                                </div>
                                <div class="lux-msg-footer-actions">
                                    <a href="all-messages.php" class="lux-msg-viewall">
                                        <i class="fas fa-external-link-alt"></i> View All
                                    </a>
                                    <button type="button" class="lux-msg-footer-btn" id="luxMsgDeleteRead" title="Delete all read">
                                        <i class="fas fa-broom"></i>
                                    </button>
                                    <button type="button" class="lux-msg-footer-btn danger" id="luxMsgDeleteAll" title="Delete all">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </li>

                    <li class="lux-dropdown" id="luxSettingsDropdown">
                        <a href="javascript:void(0);" class="lux-settings-trigger" id="luxSettingsTrigger" role="button" aria-label="Settings" aria-haspopup="true" aria-expanded="false">
                            <i class="fas fa-cog"></i> Settings
                            <i class="fas fa-chevron-down chevron"></i>
                        </a>

                        <div class="lux-settings-panel" id="luxSettingsPanel">
                            <div class="lux-settings-header">
                                <h6><i class="fas fa-sliders-h"></i> Settings</h6>
                                <button type="button" class="lux-settings-close" id="luxSettingsClose" aria-label="Close">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>

                            <div class="lux-settings-group">
                                <span class="lux-settings-label">My Account</span>
                                <ul class="lux-account-links">
                                    <li><a href="account.php"><i class="fas fa-user-circle"></i> Account Overview</a></li>
                                    <li><a href="orders.php"><i class="fas fa-truck"></i> My Orders</a></li>
                                    <li><a href="account.php#wishlist"><i class="fas fa-heart"></i> My Wishlist</a></li>
                                    <li>
                                        <a href="<?php echo htmlspecialchars($__logout_url); ?>"
                                           class="lux-logout-link"
                                           id="luxLogoutLink"
                                           data-logout-url="<?php echo htmlspecialchars($__logout_url); ?>">
                                            <i class="fas fa-sign-out-alt"></i> Sign Out
                                        </a>
                                    </li>
                                </ul>
                            </div>

                            <div class="lux-settings-group">
                                <span class="lux-settings-label">Theme</span>
                                <div class="lux-theme-grid">
                                    <label class="lux-theme-btn <?php echo $theme === 'cream' ? 'active' : ''; ?>" data-theme="cream">
                                        <input type="radio" name="lux_theme" value="cream" <?php echo $theme === 'cream' ? 'checked' : ''; ?>>
                                        <div class="lux-theme-swatch cream"></div>
                                        <div class="lux-theme-name">Cream</div>
                                    </label>
                                    <label class="lux-theme-btn <?php echo $theme === 'light' ? 'active' : ''; ?>" data-theme="light">
                                        <input type="radio" name="lux_theme" value="light" <?php echo $theme === 'light' ? 'checked' : ''; ?>>
                                        <div class="lux-theme-swatch light"></div>
                                        <div class="lux-theme-name">Light</div>
                                    </label>
                                    <label class="lux-theme-btn <?php echo $theme === 'dark' ? 'active' : ''; ?>" data-theme="dark">
                                        <input type="radio" name="lux_theme" value="dark" <?php echo $theme === 'dark' ? 'checked' : ''; ?>>
                                        <div class="lux-theme-swatch dark"></div>
                                        <div class="lux-theme-name">Dark</div>
                                    </label>
                                    <label class="lux-theme-btn <?php echo $theme === 'auto' ? 'active' : ''; ?>" data-theme="auto">
                                        <input type="radio" name="lux_theme" value="auto" <?php echo $theme === 'auto' ? 'checked' : ''; ?>>
                                        <div class="lux-theme-swatch auto"></div>
                                        <div class="lux-theme-name">Auto</div>
                                    </label>
                                </div>
                            </div>

                            <button type="button" class="lux-settings-reset" id="luxSettingsReset">
                                <i class="fas fa-undo"></i> Reset to Default
                            </button>

                            <div style="text-align:center;margin-top:0.6rem;">
                                <span class="lux-settings-status" id="luxSettingsStatus">
                                    <i class="fas fa-check-circle"></i> Saved
                                </span>
                            </div>
                        </div>
                    </li>

                <?php else: ?>
                    <li>
                        <a href="login.php" id="navLoginBtn" data-auth-modal="login">
                            <i class="fas fa-sign-in-alt"></i> Login
                        </a>
                    </li>
                    <li>
                        <a href="register.php" id="navRegisterBtn" data-auth-modal="register">
                            <i class="fas fa-user-plus"></i> Register
                        </a>
                    </li>

                    <li class="lux-dropdown" id="luxSettingsDropdown">
                        <a href="javascript:void(0);" class="lux-settings-trigger" id="luxSettingsTrigger" role="button" aria-label="Settings" aria-haspopup="true" aria-expanded="false">
                            <i class="fas fa-cog"></i> Settings
                            <i class="fas fa-chevron-down chevron"></i>
                        </a>

                        <div class="lux-settings-panel" id="luxSettingsPanel">
                            <div class="lux-settings-header">
                                <h6><i class="fas fa-palette"></i> Appearance</h6>
                                <button type="button" class="lux-settings-close" id="luxSettingsClose" aria-label="Close">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>

                            <div class="lux-settings-group">
                                <span class="lux-settings-label">Theme</span>
                                <div class="lux-theme-grid">
                                    <label class="lux-theme-btn <?php echo $theme === 'cream' ? 'active' : ''; ?>" data-theme="cream">
                                        <input type="radio" name="lux_theme" value="cream" <?php echo $theme === 'cream' ? 'checked' : ''; ?>>
                                        <div class="lux-theme-swatch cream"></div>
                                        <div class="lux-theme-name">Cream</div>
                                    </label>
                                    <label class="lux-theme-btn <?php echo $theme === 'light' ? 'active' : ''; ?>" data-theme="light">
                                        <input type="radio" name="lux_theme" value="light" <?php echo $theme === 'light' ? 'checked' : ''; ?>>
                                        <div class="lux-theme-swatch light"></div>
                                        <div class="lux-theme-name">Light</div>
                                    </label>
                                    <label class="lux-theme-btn <?php echo $theme === 'dark' ? 'active' : ''; ?>" data-theme="dark">
                                        <input type="radio" name="lux_theme" value="dark" <?php echo $theme === 'dark' ? 'checked' : ''; ?>>
                                        <div class="lux-theme-swatch dark"></div>
                                        <div class="lux-theme-name">Dark</div>
                                    </label>
                                    <label class="lux-theme-btn <?php echo $theme === 'auto' ? 'active' : ''; ?>" data-theme="auto">
                                        <input type="radio" name="lux_theme" value="auto" <?php echo $theme === 'auto' ? 'checked' : ''; ?>>
                                        <div class="lux-theme-swatch auto"></div>
                                        <div class="lux-theme-name">Auto</div>
                                    </label>
                                </div>
                            </div>

                            <button type="button" class="lux-settings-reset" id="luxSettingsReset">
                                <i class="fas fa-undo"></i> Reset to Default
                            </button>

                            <div style="text-align:center;margin-top:0.6rem;">
                                <span class="lux-settings-status" id="luxSettingsStatus">
                                    <i class="fas fa-check-circle"></i> Saved
                                </span>
                            </div>
                        </div>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>

    <div class="lux-toast" id="luxToast">
        <i class="fas fa-check-circle" id="luxToastIcon"></i>
        <span id="luxToastText">Done</span>
    </div>

    <?php if(isset($_SESSION['user_id'])): ?>
    <!-- ============================================================
         LOGOUT CONFIRM MODAL (Text only)
         ============================================================ -->
    <div class="lux-modal-overlay" id="logoutModalOverlay">
        <div class="lux-modal" id="logoutModal" role="dialog" aria-modal="true" aria-labelledby="logoutModalTitle">
            <button type="button" class="lux-modal-close" id="logoutModalClose" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>

            <h3 class="logout-title" id="logoutModalTitle">Sign Out</h3>

            <p class="logout-desc">
                Are you sure you want to <strong>log out</strong> of your account?
            </p>

            <div class="logout-actions">
                <button type="button" class="logout-btn logout-btn-no" id="logoutNoBtn">No</button>
                <button type="button" class="logout-btn logout-btn-yes" id="logoutYesBtn">Yes, Log Out</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if(!isset($_SESSION['user_id'])): ?>
    <div class="lux-modal-overlay" id="loginModalOverlay">
        <div class="lux-modal" id="loginModal">
            <button type="button" class="lux-modal-close" data-close-modal aria-label="Close">
                <i class="fas fa-times"></i>
            </button>

            <div class="lux-modal-head">
                <div class="lux-modal-icon">
                    <svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M50 5 L90 35 L50 95 L10 35 Z" stroke="#b8941f" stroke-width="1.8" fill="none" opacity="0.95"/>
                        <path d="M50 5 L90 35 L50 35 Z" fill="#b8941f" opacity="0.18"/>
                        <path d="M50 5 L10 35 L50 35 Z" fill="#b8941f" opacity="0.1"/>
                        <path d="M10 35 L50 95 L50 35 Z" fill="#b8941f" opacity="0.06"/>
                        <path d="M90 35 L50 95 L50 35 Z" fill="#b8941f" opacity="0.12"/>
                        <path d="M10 35 L90 35" stroke="#b8941f" stroke-width="1.2" opacity="0.75"/>
                        <path d="M50 35 L50 95" stroke="#b8941f" stroke-width="1" opacity="0.55"/>
                        <circle cx="50" cy="5" r="2.2" fill="#b8941f"/>
                        <circle cx="90" cy="35" r="1.6" fill="#b8941f" opacity="0.85"/>
                        <circle cx="10" cy="35" r="1.6" fill="#b8941f" opacity="0.85"/>
                        <circle cx="50" cy="95" r="2.2" fill="#b8941f"/>
                    </svg>
                </div>
                <div class="lux-modal-head-text">
                    <h3 class="lux-modal-title">R-Jewel&Gold</h3>
                    <p class="lux-modal-sub">Member Portal</p>
                </div>
            </div>

            <div class="lux-modal-divider">
                <span class="lux-divider-line"></span>
                <span class="lux-divider-diamond"></span>
                <span class="lux-divider-line"></span>
            </div>

            <div class="lux-modal-alert" id="loginModalError" style="display:none;">
                <i class="fas fa-exclamation-circle"></i>
                <span id="loginModalErrorText"></span>
            </div>

            <div class="lux-modal-alert success" id="loginModalSuccess" style="display:none;">
                <i class="fas fa-check-circle"></i>
                <span id="loginModalSuccessText"></span>
            </div>

            <form id="loginModalForm" autocomplete="on">
                <div class="lux-modal-field">
                    <label class="lux-modal-label">Username or Email</label>
                    <div class="lux-input-wrap">
                        <i class="fas fa-user lux-field-icon"></i>
                        <input type="text" name="username" id="loginModalUsername" class="lux-modal-input" placeholder="Enter your username or email" required>
                    </div>
                </div>

                <div class="lux-modal-field">
                    <label class="lux-modal-label">Password</label>
                    <div class="lux-input-wrap">
                        <i class="fas fa-lock lux-field-icon"></i>
                        <input type="password" name="password" id="loginModalPassword" class="lux-modal-input" placeholder="Enter your password" required>
                        <button type="button" class="lux-toggle-pw" data-toggle-pw="loginModalPassword" aria-label="Show password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="lux-modal-extras">
                    <label class="lux-modal-check">
                        <input type="checkbox" name="remember">
                        Remember me
                    </label>
                    <a href="#" class="lux-modal-forgot">Forgot password?</a>
                </div>

                <button type="submit" class="lux-modal-btn">
                    <span class="lux-btn-text">Sign In</span>
                    <span class="lux-btn-spinner" style="display:none;"><i class="fas fa-spinner fa-spin"></i></span>
                </button>
            </form>

            <div class="lux-modal-bottom">
                <p>Don't have an account? <a href="#" data-switch-modal="register">Register here</a></p>
            </div>
        </div>
    </div>

    <div class="lux-modal-overlay" id="registerModalOverlay">
        <div class="lux-modal" id="registerModal">
            <button type="button" class="lux-modal-close" data-close-modal aria-label="Close">
                <i class="fas fa-times"></i>
            </button>

            <div class="lux-modal-head">
                <div class="lux-modal-icon">
                    <svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M50 5 L90 35 L50 95 L10 35 Z" stroke="#b8941f" stroke-width="1.8" fill="none" opacity="0.95"/>
                        <path d="M50 5 L90 35 L50 35 Z" fill="#b8941f" opacity="0.18"/>
                        <path d="M50 5 L10 35 L50 35 Z" fill="#b8941f" opacity="0.1"/>
                        <path d="M10 35 L50 95 L50 35 Z" fill="#b8941f" opacity="0.06"/>
                        <path d="M90 35 L50 95 L50 35 Z" fill="#b8941f" opacity="0.12"/>
                        <path d="M10 35 L90 35" stroke="#b8941f" stroke-width="1.2" opacity="0.75"/>
                        <path d="M50 35 L50 95" stroke="#b8941f" stroke-width="1" opacity="0.55"/>
                        <circle cx="50" cy="5" r="2.2" fill="#b8941f"/>
                        <circle cx="90" cy="35" r="1.6" fill="#b8941f" opacity="0.85"/>
                        <circle cx="10" cy="35" r="1.6" fill="#b8941f" opacity="0.85"/>
                        <circle cx="50" cy="95" r="2.2" fill="#b8941f"/>
                    </svg>
                </div>
                <div class="lux-modal-head-text">
                    <h3 class="lux-modal-title">R-Jewel&Gold</h3>
                    <p class="lux-modal-sub">Create Account</p>
                </div>
            </div>

            <div class="lux-modal-divider">
                <span class="lux-divider-line"></span>
                <span class="lux-divider-diamond"></span>
                <span class="lux-divider-line"></span>
            </div>

            <div class="lux-modal-alert" id="registerModalError" style="display:none;">
                <i class="fas fa-exclamation-circle"></i>
                <span id="registerModalErrorText"></span>
            </div>

            <form id="registerModalForm" autocomplete="on">
                <div class="lux-modal-grid">
                    <div class="lux-modal-field lux-col-full">
                        <label class="lux-modal-label">Full Name <span class="lux-req">*</span></label>
                        <div class="lux-input-wrap">
                            <i class="fas fa-id-card lux-field-icon"></i>
                            <input type="text" name="full_name" class="lux-modal-input" placeholder="Enter your full name" required>
                        </div>
                    </div>

                    <div class="lux-modal-field">
                        <label class="lux-modal-label">Username <span class="lux-req">*</span></label>
                        <div class="lux-input-wrap">
                            <i class="fas fa-user lux-field-icon"></i>
                            <input type="text" name="username" class="lux-modal-input" placeholder="Choose a username" required>
                        </div>
                    </div>

                    <div class="lux-modal-field">
                        <label class="lux-modal-label">Email <span class="lux-req">*</span></label>
                        <div class="lux-input-wrap">
                            <i class="fas fa-envelope lux-field-icon"></i>
                            <input type="email" name="email" class="lux-modal-input" placeholder="your@email.com" required>
                        </div>
                    </div>

                    <div class="lux-modal-field">
                        <label class="lux-modal-label">Phone <span class="lux-opt">(optional)</span></label>
                        <div class="lux-input-wrap">
                            <i class="fas fa-phone lux-field-icon"></i>
                            <input type="tel" name="phone" class="lux-modal-input" placeholder="+63 912 345 6789">
                        </div>
                    </div>

                    <div class="lux-modal-field">
                        <label class="lux-modal-label">Password <span class="lux-req">*</span></label>
                        <div class="lux-input-wrap">
                            <i class="fas fa-lock lux-field-icon"></i>
                            <input type="password" name="password" id="registerModalPassword" class="lux-modal-input" placeholder="Min 6 characters" required>
                            <button type="button" class="lux-toggle-pw" data-toggle-pw="registerModalPassword" aria-label="Show password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="lux-modal-field lux-col-full">
                        <label class="lux-modal-label">Address <span class="lux-opt">(optional)</span></label>
                        <div class="lux-input-wrap">
                            <i class="fas fa-map-marker-alt lux-field-icon lux-textarea-icon"></i>
                            <textarea name="address" class="lux-modal-input lux-modal-textarea" rows="2" placeholder="Street, City, Province"></textarea>
                        </div>
                    </div>
                </div>

                <button type="submit" class="lux-modal-btn">
                    <span class="lux-btn-text">Create Account</span>
                    <span class="lux-btn-spinner" style="display:none;"><i class="fas fa-spinner fa-spin"></i></span>
                </button>
            </form>

            <div class="lux-modal-bottom">
                <p>Already have an account? <a href="#" data-switch-modal="login">Login here</a></p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
    (function () {
        'use strict';

        var baseUrl = <?php echo json_encode($__header_self_url); ?>;

        var toast     = document.getElementById('luxToast');
        var toastText = document.getElementById('luxToastText');
        var toastIcon = document.getElementById('luxToastIcon');
        var toastTimer;

        function showToast(msg, type) {
            if (!toast) return;
            type = type || 'success';
            toastText.textContent = msg;
            toastIcon.className = type === 'error' ? 'fas fa-exclamation-circle' : 'fas fa-check-circle';
            toast.className = 'lux-toast show' + (type === 'error' ? ' error' : '');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(function () { toast.classList.remove('show'); }, 2200);
        }

        var toggle = document.getElementById('luxToggle');
        var menu   = document.getElementById('luxMenu');
        if (toggle && menu) {
            toggle.addEventListener('click', function (e) {
                e.preventDefault(); e.stopPropagation();
                menu.classList.toggle('open');
                var icon = this.querySelector('i');
                if (icon) { icon.classList.toggle('fa-bars'); icon.classList.toggle('fa-times'); }
            });
        }

        var settingsDropdown = document.getElementById('luxSettingsDropdown');
        var settingsTrigger  = document.getElementById('luxSettingsTrigger');
        var settingsClose    = document.getElementById('luxSettingsClose');
        var settingsStatus   = document.getElementById('luxSettingsStatus');
        var settingsReset    = document.getElementById('luxSettingsReset');

        var msgWrapper  = document.getElementById('luxMsgWrapper');
        var msgLink     = document.getElementById('luxMsgLink');
        var msgBody     = document.getElementById('luxMsgBody');
        var msgBadge    = document.getElementById('luxMsgBadge');
        var msgDot      = document.getElementById('luxMsgDot');
        var msgMarkAll  = document.getElementById('luxMsgMarkAll');
        var msgDelRead  = document.getElementById('luxMsgDeleteRead');
        var msgDelAll   = document.getElementById('luxMsgDeleteAll');
        var unreadEl    = document.getElementById('luxMsgUnreadCount');
        var totalEl     = document.getElementById('luxMsgTotalCount');
        var currentCount = 0;

        function showStatus() {
            if (!settingsStatus) return;
            settingsStatus.classList.add('show');
            clearTimeout(settingsStatus._t);
            settingsStatus._t = setTimeout(function () { settingsStatus.classList.remove('show'); }, 1500);
        }

        var THEME_PALETTES = {
            cream: {
                '--bg':'#f2ede4','--bg-soft':'#faf7f2','--card':'#ffffff',
                '--border':'#e5ddd0','--border-light':'#ede7db',
                '--text':'#5a5245','--text-bright':'#2b2620',
                '--text-muted':'#8a8172','--text-dim':'#b3a998',
                '--section-alt':'#faf7f2','--nav-bg':'rgba(242,237,228,0.94)',
                '--nav-border':'rgba(184,148,31,0.25)','--dropdown-bg':'#ffffff','--dropdown-border':'#e5ddd0'
            },
            light: {
                '--bg':'#f5f7fb','--bg-soft':'#ffffff','--card':'#ffffff',
                '--border':'#e2e8f0','--border-light':'#edf1f6',
                '--text':'#475569','--text-bright':'#172033',
                '--text-muted':'#64748b','--text-dim':'#94a3b8',
                '--section-alt':'#f5f7fb','--nav-bg':'rgba(255,255,255,0.94)',
                '--nav-border':'rgba(184,148,31,0.25)','--dropdown-bg':'#ffffff','--dropdown-border':'#e2e8f0'
            },
            dark: {
                '--bg':'#151821','--bg-soft':'#1c202b','--card':'#222735',
                '--border':'#343b4d','--border-light':'#2d3444',
                '--text':'#d5d9e2','--text-bright':'#ffffff',
                '--text-muted':'#a0a8ba','--text-dim':'#777f91',
                '--section-alt':'#1c202b','--nav-bg':'rgba(21,24,33,0.94)',
                '--nav-border':'rgba(184,148,31,0.3)','--dropdown-bg':'#222735','--dropdown-border':'#343b4d'
            }
        };

        var ALL_THEME_VARS = [
            '--bg','--bg-soft','--card','--border','--border-light',
            '--text','--text-bright','--text-muted','--text-dim',
            '--section-alt','--nav-bg','--nav-border',
            '--dropdown-bg','--dropdown-border'
        ];

        function resolveTheme(theme) {
            if (theme !== 'auto') return theme;
            var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            return prefersDark ? 'dark' : 'cream';
        }

        function applyThemeInstant(theme, accent) {
            var root = document.documentElement;
            ALL_THEME_VARS.forEach(function (p) { root.style.removeProperty(p); });
            var resolved = resolveTheme(theme);
            root.setAttribute('data-theme', resolved);
            root.setAttribute('data-saved-theme', theme);
            var palette = THEME_PALETTES[resolved];
            if (palette) {
                Object.keys(palette).forEach(function (k) { root.style.setProperty(k, palette[k]); });
            }
            if (accent) {
                root.style.setProperty('--primary', accent);
                root.style.setProperty('--primary-soft', accent + '18');
            }
        }

        function saveAppearance() {
            var theme = document.querySelector('input[name="lux_theme"]:checked');
            if (!theme) return;
            var fd = new URLSearchParams();
            fd.append('action', 'save_appearance_guest');
            fd.append('theme', theme.value);
            fd.append('accent', <?php echo json_encode($accent); ?>);
            fd.append('sidebar', <?php echo json_encode($sidebar_style); ?>);

            fetch(baseUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: fd.toString()
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.success) {
                    showStatus();
                    applyThemeInstant(res.theme, res.accent);
                }
            })
            .catch(function () {});
        }

        document.querySelectorAll('.lux-theme-btn').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var newTheme = this.getAttribute('data-theme');
                document.querySelectorAll('.lux-theme-btn').forEach(function (b) { b.classList.remove('active'); });
                this.classList.add('active');
                var input = this.querySelector('input');
                if (input) input.checked = true;
                applyThemeInstant(newTheme, <?php echo json_encode($accent); ?>);
                saveAppearance();
            });
        });

        if (settingsReset) {
            settingsReset.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var defaultBtn = document.querySelector('.lux-theme-btn[data-theme="cream"]');
                document.querySelectorAll('.lux-theme-btn').forEach(function (b) { b.classList.remove('active'); });
                if (defaultBtn) {
                    defaultBtn.classList.add('active');
                    var input = defaultBtn.querySelector('input');
                    if (input) input.checked = true;
                }
                applyThemeInstant('cream', <?php echo json_encode($accent); ?>);
                saveAppearance();
            });
        }

        (function syncThemeOnLoad() {
            var savedTheme  = document.documentElement.getAttribute('data-saved-theme')
                           || <?php echo json_encode($theme); ?>;
            var savedAccent = <?php echo json_encode($accent); ?>;
            applyThemeInstant(savedTheme, savedAccent);
            document.querySelectorAll('.lux-theme-btn').forEach(function (b) {
                var isMatch = b.getAttribute('data-theme') === savedTheme;
                b.classList.toggle('active', isMatch);
                var input = b.querySelector('input');
                if (input) input.checked = isMatch;
            });
        })();

        if (window.matchMedia) {
            try {
                var mq = window.matchMedia('(prefers-color-scheme: dark)');
                var mqHandler = function () {
                    var saved = document.documentElement.getAttribute('data-saved-theme');
                    if (saved === 'auto') {
                        applyThemeInstant('auto', <?php echo json_encode($accent); ?>);
                    }
                };
                if (mq.addEventListener) mq.addEventListener('change', mqHandler);
                else if (mq.addListener) mq.addListener(mqHandler);
            } catch (e) {}
        }

        if (settingsTrigger && settingsDropdown) {
            settingsTrigger.addEventListener('click', function (e) {
                e.preventDefault(); e.stopPropagation();
                if (msgWrapper) msgWrapper.classList.remove('open');
                settingsDropdown.classList.toggle('open');
                if (window.innerWidth <= 991 && menu) menu.classList.add('open');
            });
        }
        if (settingsClose && settingsDropdown) {
            settingsClose.addEventListener('click', function (e) {
                e.preventDefault(); e.stopPropagation();
                settingsDropdown.classList.remove('open');
            });
        }
        document.addEventListener('click', function (e) {
            if (settingsDropdown && !settingsDropdown.contains(e.target)) settingsDropdown.classList.remove('open');
        });

        document.addEventListener('click', function (e) {
            if (window.innerWidth <= 991 && menu && !menu.contains(e.target) && toggle && !toggle.contains(e.target)) {
                menu.classList.remove('open');
                var icon = toggle.querySelector('i');
                if (icon) { icon.classList.add('fa-bars'); icon.classList.remove('fa-times'); }
            }
        });

        function esc(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        function setBadge(count) {
            if (!msgBadge) return;
            count = parseInt(count, 10) || 0;
            if (count > 0) {
                msgBadge.textContent = count > 99 ? '99+' : count;
                msgBadge.classList.remove('hidden');
                if (msgDot) msgDot.classList.remove('hidden');
                if (count > currentCount) {
                    msgBadge.classList.remove('bump');
                    void msgBadge.offsetWidth;
                    msgBadge.classList.add('bump');
                }
            } else {
                msgBadge.classList.add('hidden');
                if (msgDot) msgDot.classList.add('hidden');
            }
            currentCount = count;
        }

        function apiPost(action, data) {
            var fd = new URLSearchParams();
            fd.append('action', action);
            Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
            return fetch(baseUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: fd.toString()
            }).then(function (r) { return r.json(); });
        }

        function fetchCount() {
            if (!msgBadge) return;
            fetch(baseUrl + '?action=get_message_count', {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store'
            })
            .then(function (r) { return r.json(); })
            .then(function (res) { if (res && res.success) setBadge(res.count); })
            .catch(function () {});
        }

        function renderMessages(messages) {
            if (!msgBody) return;
            var list = messages || [];
            msgBody._all = list;

            if (list.length === 0) {
                msgBody.innerHTML =
                    '<div class="lux-msg-empty">' +
                        '<i class="fas fa-inbox"></i>' +
                        '<p>No messages yet</p>' +
                    '</div>';
                if (unreadEl) unreadEl.textContent = '0';
                if (totalEl) totalEl.textContent = '0';
                if (msgMarkAll) msgMarkAll.disabled = true;
                if (msgDelRead) msgDelRead.disabled = true;
                if (msgDelAll)  msgDelAll.disabled  = true;
                return;
            }

            var unreadCount = list.filter(function (m) { return m.is_read == 0; }).length;
            if (unreadEl) unreadEl.textContent = unreadCount;
            if (totalEl) totalEl.textContent = list.length;
            if (msgMarkAll) msgMarkAll.disabled = unreadCount === 0;
            if (msgDelRead) msgDelRead.disabled = list.length === 0;
            if (msgDelAll)  msgDelAll.disabled  = list.length === 0;

            msgBody.innerHTML = '';
            list.forEach(function (m) {
                var item = document.createElement('div');
                item.className = 'lux-msg-item' + (m.is_read == 0 ? ' unread' : '');
                item.dataset.id = m.id;

                var actionButtons = '';
                if (m.is_read == 0) {
                    actionButtons += '<button class="lux-msg-item-btn" data-act="mark"><i class="fas fa-check"></i> Mark read</button>';
                }
                actionButtons += '<button class="lux-msg-item-btn danger" data-act="delete"><i class="fas fa-trash"></i> Delete</button>';

                item.innerHTML =
                    '<div class="lux-msg-item-icon"><i class="fas fa-' + esc(m.icon) + '"></i></div>' +
                    '<div class="lux-msg-item-content">' +
                        '<p class="lux-msg-item-title">' + esc(m.title) + '</p>' +
                        (m.message ? '<p class="lux-msg-item-text">' + esc(m.message) + '</p>' : '') +
                        '<div class="lux-msg-item-time"><i class="fas fa-calendar-alt"></i> ' + esc(m.full_date) + '</div>' +
                        '<div class="lux-msg-item-actions">' + actionButtons + '</div>' +
                    '</div>';

                msgBody.appendChild(item);
            });
        }

        function fetchMessages() {
            if (!msgBody) return;
            msgBody.innerHTML =
                '<div class="lux-msg-empty">' +
                    '<i class="fas fa-spinner fa-spin"></i>' +
                    '<p>Loading messages...</p>' +
                '</div>';

            fetch(baseUrl + '?action=get_messages&limit=20', {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store'
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.success) renderMessages(res.messages || []);
                else msgBody.innerHTML = '<div class="lux-msg-empty"><i class="fas fa-exclamation-circle"></i><p>Could not load messages</p></div>';
            })
            .catch(function () {
                msgBody.innerHTML = '<div class="lux-msg-empty"><i class="fas fa-exclamation-circle"></i><p>Could not load messages</p></div>';
            });
        }

        if (msgLink && msgWrapper) {
            msgLink.addEventListener('click', function (e) {
                e.preventDefault(); e.stopPropagation();
                if (settingsDropdown) settingsDropdown.classList.remove('open');
                var isOpen = msgWrapper.classList.contains('open');
                if (!isOpen) {
                    msgWrapper.classList.add('open');
                    msgLink.setAttribute('aria-expanded', 'true');
                    fetchMessages();
                } else {
                    msgWrapper.classList.remove('open');
                    msgLink.setAttribute('aria-expanded', 'false');
                }
                if (window.innerWidth <= 991 && menu) menu.classList.add('open');
            });
        }

        document.addEventListener('click', function (e) {
            if (msgWrapper && !msgWrapper.contains(e.target)) {
                msgWrapper.classList.remove('open');
                if (msgLink) msgLink.setAttribute('aria-expanded', 'false');
            }
        });

        if (msgBody) {
            msgBody.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-act]');
                if (!btn) return;
                var item = btn.closest('.lux-msg-item');
                if (!item) return;
                var id = item.dataset.id;
                var act = btn.dataset.act;

                if (act === 'mark') {
                    apiPost('mark_message_read', { id: id }).then(function (r) {
                        if (r.success) {
                            item.classList.remove('unread');
                            btn.remove();
                            var all = msgBody._all || [];
                            for (var i = 0; i < all.length; i++) {
                                if (all[i].id == id) { all[i].is_read = 1; break; }
                            }
                            var unreadLeft = all.filter(function (m) { return m.is_read == 0; }).length;
                            if (unreadEl) unreadEl.textContent = unreadLeft;
                            setBadge(unreadLeft);
                            if (msgMarkAll) msgMarkAll.disabled = unreadLeft === 0;
                            showToast('Marked as read');
                        }
                    });
                    return;
                }

                if (act === 'delete') {
                    if (!confirm('Delete this message?')) return;
                    apiPost('delete_message', { id: id }).then(function (r) {
                        if (r.success) {
                            item.style.transition = 'opacity 0.2s';
                            item.style.opacity = '0';
                            setTimeout(function () {
                                item.remove();
                                var all = (msgBody._all || []).filter(function (m) { return m.id != id; });
                                msgBody._all = all;
                                renderMessages(all);
                                fetchCount();
                                showToast('Message deleted');
                            }, 200);
                        }
                    });
                    return;
                }
            });
        }

        if (msgMarkAll) {
            msgMarkAll.addEventListener('click', function (e) {
                e.stopPropagation();
                apiPost('mark_all_messages_read').then(function (r) {
                    if (r.success) {
                        var all = msgBody._all || [];
                        all.forEach(function (m) { m.is_read = 1; });
                        renderMessages(all);
                        setBadge(0);
                        showToast('All marked as read');
                    }
                });
            });
        }

        if (msgDelRead) {
            msgDelRead.addEventListener('click', function (e) {
                e.stopPropagation();
                if (!confirm('Delete all read messages?')) return;
                apiPost('delete_all_read_messages').then(function (r) {
                    if (r.success) {
                        var all = (msgBody._all || []).filter(function (m) { return m.is_read == 0; });
                        renderMessages(all);
                        fetchCount();
                        showToast('Read messages deleted');
                    }
                });
            });
        }

        if (msgDelAll) {
            msgDelAll.addEventListener('click', function (e) {
                e.stopPropagation();
                if (!confirm('Delete ALL messages? This cannot be undone.')) return;
                apiPost('delete_all_messages').then(function (r) {
                    if (r.success) {
                        msgBody._all = [];
                        renderMessages([]);
                        setBadge(0);
                        showToast('All messages deleted');
                    }
                });
            });
        }

        <?php if (isset($_SESSION['user_id'])): ?>
        fetchCount();
        setInterval(fetchCount, 10000);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') fetchCount();
        });
        window.addEventListener('focus', fetchCount);
        window.addEventListener('pageshow', fetchCount);
        <?php endif; ?>

        window.updateMessageBadge = setBadge;
        window.refreshMessages    = fetchMessages;
        window.refreshMessageCount = fetchCount;
        window.RJEWEL_THEME       = <?php echo json_encode($theme); ?>;
        window.RJEWEL_AJAX_URL    = baseUrl;
        window.applyThemeInstant  = applyThemeInstant;
        window.showLuxToast       = showToast;

        <?php if (isset($_SESSION['user_id'])): ?>
        var wishlistIds = new Set();

        function markHeart(btn, active) {
            if (!btn) return;
            btn.classList.toggle('is-active', !!active);
            btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        }

        function syncHearts() {
            document.querySelectorAll('.wish-heart[data-product-id]').forEach(function (btn) {
                var pid = parseInt(btn.getAttribute('data-product-id'), 10);
                markHeart(btn, wishlistIds.has(pid));
            });
        }

        function fetchWishIds() {
            fetch('wishlist.php?action=ids', {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store'
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.success && Array.isArray(res.ids)) {
                    wishlistIds = new Set(res.ids);
                    window.WISHLIST_IDS = wishlistIds;
                    syncHearts();
                }
            })
            .catch(function () {});
        }

        window.toggleWishlist = function (productId, btn) {
            if (btn) btn.classList.add('busy');
            var fd = new URLSearchParams();
            fd.append('action', 'toggle');
            fd.append('product_id', productId);

            fetch('wishlist.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: fd.toString()
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (btn) btn.classList.remove('busy');
                if (!res || !res.success) {
                    if (res && res.need_login) {
                        showToast('Please sign in to use your wishlist.', 'error');
                        if (window.openLoginModal) window.openLoginModal();
                    } else {
                        showToast((res && res.message) || 'Could not update wishlist.', 'error');
                    }
                    return;
                }
                if (res.in_wishlist) wishlistIds.add(parseInt(productId, 10));
                else                 wishlistIds.delete(parseInt(productId, 10));
                window.WISHLIST_IDS = wishlistIds;
                markHeart(btn, res.in_wishlist);
                showToast(res.message);
            })
            .catch(function () {
                if (btn) btn.classList.remove('busy');
                showToast('Network error.', 'error');
            });
        };

        window.refreshWishlist = function () { fetchWishIds(); };
        window.WISHLIST_IDS = wishlistIds;

        fetchWishIds();
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') fetchWishIds();
        });
        <?php else: ?>
        window.toggleWishlist = function (productId, btn) {
            showToast('Please sign in to use your wishlist.', 'error');
            if (window.openLoginModal) window.openLoginModal();
        };
        window.refreshWishlist = function () {};
        window.WISHLIST_IDS = new Set();
        <?php endif; ?>

        (function initAuthModals() {
            var loginOverlay    = document.getElementById('loginModalOverlay');
            var registerOverlay = document.getElementById('registerModalOverlay');
            if (!loginOverlay || !registerOverlay) return;

            var loginForm       = document.getElementById('loginModalForm');
            var registerForm    = document.getElementById('registerModalForm');
            var loginError      = document.getElementById('loginModalError');
            var loginErrorText  = document.getElementById('loginModalErrorText');
            var loginSuccess    = document.getElementById('loginModalSuccess');
            var loginSuccessText = document.getElementById('loginModalSuccessText');
            var registerError      = document.getElementById('registerModalError');
            var registerErrorText  = document.getElementById('registerModalErrorText');
            var loginUsernameInput = document.getElementById('loginModalUsername');

            function openModal(overlay) {
                if (!overlay) return;
                overlay.classList.add('open');
                document.body.classList.add('lux-modal-open');
                setTimeout(function () {
                    var firstInput = overlay.querySelector('input:not([type="hidden"]):not([type="checkbox"])');
                    if (firstInput) firstInput.focus();
                }, 250);
            }
            function closeModal(overlay) {
                if (!overlay) return;
                overlay.classList.remove('open');
                if (!document.querySelector('.lux-modal-overlay.open')) {
                    document.body.classList.remove('lux-modal-open');
                }
            }
            function closeAllModals() {
                closeModal(loginOverlay);
                closeModal(registerOverlay);
            }

            document.querySelectorAll('[data-auth-modal]').forEach(function (link) {
                if (link.closest('.lux-modal')) return;
                link.addEventListener('click', function (e) {
                    var path = window.location.pathname;
                    if (path.indexOf('login.php') !== -1 || path.indexOf('register.php') !== -1) return;
                    e.preventDefault();
                    e.stopPropagation();
                    closeAllModals();
                    if (loginError) loginError.style.display = 'none';
                    if (loginSuccess) loginSuccess.style.display = 'none';
                    if (registerError) registerError.style.display = 'none';
                    var which = this.getAttribute('data-auth-modal');
                    if (which === 'register') openModal(registerOverlay);
                    else                      openModal(loginOverlay);
                });
            });

            document.querySelectorAll('[data-close-modal]').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    closeModal(this.closest('.lux-modal-overlay'));
                });
            });

            [loginOverlay, registerOverlay].forEach(function (overlay) {
                overlay.addEventListener('click', function (e) {
                    if (e.target === overlay) closeModal(overlay);
                });
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') closeAllModals();
            });

            document.querySelectorAll('[data-switch-modal]').forEach(function (link) {
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    var target = this.getAttribute('data-switch-modal');
                    closeAllModals();
                    if (loginError) loginError.style.display = 'none';
                    if (loginSuccess) loginSuccess.style.display = 'none';
                    if (registerError) registerError.style.display = 'none';
                    if (target === 'register') openModal(registerOverlay);
                    else                        openModal(loginOverlay);
                });
            });

            document.querySelectorAll('[data-toggle-pw]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var inputId = this.getAttribute('data-toggle-pw');
                    var input = document.getElementById(inputId);
                    if (!input) return;
                    var icon = this.querySelector('i');
                    var isHidden = input.type === 'password';
                    input.type = isHidden ? 'text' : 'password';
                    if (icon) {
                        icon.classList.toggle('fa-eye', !isHidden);
                        icon.classList.toggle('fa-eye-slash', isHidden);
                    }
                    this.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
                    input.focus();
                });
            });

            function setLoading(form, loading) {
                var btn = form.querySelector('.lux-modal-btn');
                if (!btn) return;
                var text = btn.querySelector('.lux-btn-text');
                var spinner = btn.querySelector('.lux-btn-spinner');
                btn.disabled = loading;
                if (text)    text.style.display    = loading ? 'none' : '';
                if (spinner) spinner.style.display = loading ? '' : 'none';
            }
            function showError(el, textEl, msg) {
                if (!el || !textEl) return;
                textEl.innerHTML = msg;
                el.style.display = 'flex';
            }
            function hideError(el) { if (el) el.style.display = 'none'; }

            var authEndpoint = 'auth-modal.php';

            if (loginForm) {
                loginForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    hideError(loginError);
                    hideError(loginSuccess);
                    setLoading(loginForm, true);
                    var fd = new FormData(loginForm);
                    fetch(authEndpoint + '?action=login', {
                        method: 'POST', body: fd, credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.success) {
                            showError(loginSuccess, loginSuccessText, res.message || 'Login successful! Redirecting...');
                            setTimeout(function () {
                                window.location.href = res.redirect || 'index.php';
                            }, 400);
                        } else {
                            setLoading(loginForm, false);
                            showError(loginError, loginErrorText, (res && res.message) ? res.message : 'Invalid username or password.');
                        }
                    })
                    .catch(function () {
                        setLoading(loginForm, false);
                        showError(loginError, loginErrorText, 'Connection error. Please try again.');
                    });
                });
            }

            if (registerForm) {
                registerForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    hideError(registerError);
                    setLoading(registerForm, true);
                    var fd = new FormData(registerForm);
                    fetch(authEndpoint + '?action=register', {
                        method: 'POST', body: fd, credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        setLoading(registerForm, false);
                        if (res && res.success) {
                            var uname = (res.username || registerForm.querySelector('input[name="username"]').value || '').trim();
                            registerForm.reset();
                            closeModal(registerOverlay);
                            openModal(loginOverlay);
                            if (loginUsernameInput && uname) loginUsernameInput.value = uname;
                            var pwField = document.getElementById('loginModalPassword');
                            if (pwField) setTimeout(function () { pwField.focus(); }, 320);
                            hideError(loginError);
                            showError(loginSuccess, loginSuccessText, res.message || 'Account created! Please sign in.');
                        } else {
                            showError(registerError, registerErrorText, (res && res.message) ? res.message : 'Registration failed. Please try again.');
                        }
                    })
                    .catch(function () {
                        setLoading(registerForm, false);
                        showError(registerError, registerErrorText, 'Connection error. Please try again.');
                    });
                });
            }

            window.openLoginModal    = function () { closeAllModals(); openModal(loginOverlay); };
            window.openRegisterModal = function () { closeAllModals(); openModal(registerOverlay); };
            window.closeAuthModals   = closeAllModals;
        })();

        /* ---------- LOGOUT CONFIRMATION ---------- */
        (function initLogoutConfirm() {
            var overlay    = document.getElementById('logoutModalOverlay');
            var closeBtn   = document.getElementById('logoutModalClose');
            var noBtn      = document.getElementById('logoutNoBtn');
            var yesBtn     = document.getElementById('logoutYesBtn');
            var logoutLink = document.getElementById('luxLogoutLink');
            if (!overlay || !logoutLink) return;

            function openLogout() {
                overlay.classList.add('open');
                document.body.classList.add('lux-modal-open');
            }
            function closeLogout() {
                overlay.classList.remove('open');
                if (!document.querySelector('.lux-modal-overlay.open')) {
                    document.body.classList.remove('lux-modal-open');
                }
            }

            logoutLink.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (settingsDropdown) settingsDropdown.classList.remove('open');
                openLogout();
            });

            if (closeBtn) closeBtn.addEventListener('click', function (e) { e.preventDefault(); closeLogout(); });
            if (noBtn)    noBtn.addEventListener('click',    function (e) { e.preventDefault(); closeLogout(); });

            if (yesBtn) {
                yesBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    var target = logoutLink.getAttribute('data-logout-url') || logoutLink.getAttribute('href') || 'logout.php';
                    yesBtn.disabled = true;
                    window.location.href = target;
                });
            }

            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) closeLogout();
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && overlay.classList.contains('open')) {
                    closeLogout();
                }
            });
        })();

        (function initLogoutToast() {
            try {
                var params = new URLSearchParams(window.location.search);
                if (params.get('logout') === '1') {
                    showToast('You have been logged out.');
                    if (window.history && window.history.replaceState) {
                        var url = window.location.pathname + window.location.hash;
                        window.history.replaceState({}, document.title, url);
                    }
                }
            } catch (e) {}
        })();
    })();
    </script>
    <main>