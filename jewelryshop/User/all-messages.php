<?php
// user/all-messages.php — Full inbox page (matches index.php + about.php + header theme)
error_reporting(E_ALL);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$uid = (int)$_SESSION['user_id'];

/* ------------------------------------------------------------------
   Timezone
   ------------------------------------------------------------------ */
if (!ini_get('date.timezone')) {
    date_default_timezone_set('Asia/Manila');
}

/* ------------------------------------------------------------------
   1. Page title
   ------------------------------------------------------------------ */
$page_title = 'All Messages | R-Jewel&Gold';

/* ------------------------------------------------------------------
   2. Database connection
   ------------------------------------------------------------------ */
function am_get_db() {
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

function am_query($sql, $params = []) {
    $db = am_get_db();
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

function am_exec($sql, $params = []) {
    $db = am_get_db();
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

function am_full_date($dt) {
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

function am_icon($type) {
    $map = [
        'order' => 'shopping-bag', 'layaway' => 'hand-holding-heart',
        'payment' => 'credit-card', 'due' => 'clock', 'system' => 'info-circle',
    ];
    return $map[strtolower((string)$type)] ?? 'bell';
}

/* ------------------------------------------------------------------
   3. AJAX actions
   ------------------------------------------------------------------ */
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action) {
    header('Content-Type: application/json');
    $out = ['success' => false, 'message' => 'Unknown action'];

    try {
        switch ($action) {
            /* ---- theme save (mirrors header.php) ---- */
            case 'save_appearance':
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

            /* ---- header dropdown: unread count ---- */
            case 'get_message_count':
                $rows = am_query("SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0", [$uid]);
                $out = ['success' => true, 'count' => (int)($rows[0]['cnt'] ?? 0)];
                break;

            /* ---- header dropdown: recent messages ---- */
            case 'get_messages':
                $limit = max(1, min(200, (int)($_GET['limit'] ?? 10)));
                $rows  = am_query(
                    "SELECT id, type, title, message, link, is_read, created_at
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
                        'full_date' => am_full_date($r['created_at'] ?? ''),
                        'icon'      => am_icon($r['type'] ?? 'system'),
                    ];
                }
                $out = ['success' => true, 'messages' => $msgs];
                break;

            /* ---- inbox-page specific actions ---- */
            case 'mark_read':
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) am_exec("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?", [$id, $uid]);
                $out = ['success' => true];
                break;

            case 'mark_all_read':
                am_exec("UPDATE notifications SET is_read = 1 WHERE user_id = ?", [$uid]);
                $out = ['success' => true];
                break;

            case 'delete':
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) am_exec("DELETE FROM notifications WHERE id = ? AND user_id = ?", [$id, $uid]);
                $out = ['success' => true];
                break;

            case 'delete_many':
                $ids = $_POST['ids'] ?? '';
                $idList = array_filter(array_map('intval', explode(',', (string)$ids)));
                if (!empty($idList)) {
                    $placeholders = implode(',', array_fill(0, count($idList), '?'));
                    $params = $idList;
                    $params[] = $uid;
                    am_exec("DELETE FROM notifications WHERE id IN ($placeholders) AND user_id = ?", $params);
                }
                $out = ['success' => true, 'deleted' => count($idList)];
                break;

            case 'delete_all_read':
                am_exec("DELETE FROM notifications WHERE user_id = ? AND is_read = 1", [$uid]);
                $out = ['success' => true];
                break;

            case 'delete_all':
                am_exec("DELETE FROM notifications WHERE user_id = ?", [$uid]);
                $out = ['success' => true];
                break;
        }
    } catch (Throwable $e) {
        $out = ['success' => false, 'message' => $e->getMessage()];
    }

    echo json_encode($out);
    exit();
}

/* ------------------------------------------------------------------
   4. Fetch messages
   ------------------------------------------------------------------ */
$messages = am_query(
    "SELECT id, type, title, message, link, is_read, created_at
     FROM notifications WHERE user_id = ? ORDER BY created_at DESC",
    [$uid]
);

$total  = count($messages);
$unread = 0;
foreach ($messages as $m) if (empty($m['is_read'])) $unread++;

/* ------------------------------------------------------------------
   5. Include header
   ------------------------------------------------------------------ */
include '../includes/header.php';
?>

<style>
    .am-page { max-width: 900px; margin: 0 auto; padding: 2.5rem 1.5rem 4rem; }
    .am-head {
        background: var(--card); border: 1px solid var(--border);
        border-radius: 16px; padding: 1.5rem 1.75rem; margin-bottom: 1.5rem;
        box-shadow: 0 1px 3px rgba(90, 82, 69, 0.05);
    }
    .am-head-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap; }
    .am-title { display: flex; gap: 12px; align-items: center; }
    .am-title-icon {
        width: 48px; height: 48px; border-radius: 12px;
        background: var(--gold-soft); color: var(--gold);
        display: flex; align-items: center; justify-content: center;
        font-size: 1.2rem; flex-shrink: 0;
    }
    .am-title h1 {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        font-size: 1.75rem; margin: 0 0 3px; font-weight: 700;
        color: var(--text-bright); letter-spacing: -0.01em;
    }
    .am-title p {
        margin: 0; font-size: .82rem; color: var(--text-muted);
        display: flex; gap: 10px; flex-wrap: wrap; align-items: center;
    }
    .am-pill {
        display: inline-flex; gap: 5px; align-items: center;
        padding: 3px 10px; border-radius: 20px;
        font-size: .7rem; font-weight: 700; letter-spacing: 0.04em;
    }
    .am-pill.total  { background: var(--gold-soft); color: var(--gold-strong); }
    .am-pill.unread { background: rgba(220, 38, 38, 0.1); color: #b91c1c; }

    .am-toolbar {
        display: flex; gap: 8px; flex-wrap: wrap;
        margin-top: 1.25rem; padding-top: 1.25rem;
        border-top: 1px solid var(--border-light);
    }
    .am-btn {
        display: inline-flex; gap: 7px; align-items: center;
        padding: 9px 16px; border-radius: 9px;
        font-family: 'Inter', sans-serif; font-size: .76rem; font-weight: 600;
        cursor: pointer; transition: all .2s;
        border: 1px solid transparent; white-space: nowrap; text-decoration: none;
    }
    .am-btn-primary {
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        color: #fff; box-shadow: 0 4px 14px -3px rgba(184, 148, 31, 0.45);
    }
    .am-btn-primary:hover { color: #fff; transform: translateY(-2px); box-shadow: 0 8px 22px -5px rgba(184, 148, 31, 0.6); }
    .am-btn-outline { background: transparent; color: var(--text-bright); border-color: var(--border); }
    .am-btn-outline:hover { border-color: var(--gold); color: var(--gold); transform: translateY(-2px); }
    .am-btn-danger { background: transparent; color: var(--danger); border-color: rgba(220,38,38,.3); }
    .am-btn-danger:hover { background: rgba(220,38,38,.08); border-color: var(--danger); transform: translateY(-2px); }
    .am-btn:disabled { opacity: .45; cursor: not-allowed; }

    .am-filters { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 1.25rem; }
    .am-chip {
        padding: 8px 16px; border-radius: 20px;
        background: var(--card); border: 1px solid var(--border);
        color: var(--text-muted); font-size: .78rem; font-weight: 600;
        cursor: pointer; transition: all .2s;
    }
    .am-chip:hover { border-color: var(--gold); color: var(--gold); }
    .am-chip.active {
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        border-color: transparent; color: #fff;
        box-shadow: 0 4px 14px -3px rgba(184, 148, 31, 0.45);
    }

    .am-list {
        background: var(--card); border: 1px solid var(--border);
        border-radius: 16px; overflow: hidden;
        box-shadow: 0 1px 3px rgba(90, 82, 69, 0.05);
    }
    .am-row {
        display: flex; gap: 14px; padding: 18px 22px;
        border-bottom: 1px solid var(--border-light);
        position: relative; transition: background-color .15s ease;
        align-items: flex-start;
    }
    .am-row:last-child { border-bottom: none; }
    .am-row:hover { background: var(--bg-soft); }
    .am-row.unread { background: var(--gold-soft); }
    .am-row.selected { background: rgba(220, 38, 38, 0.06); }
    .am-row.unread::before {
        content: ''; position: absolute; left: 8px; top: 50%;
        transform: translateY(-50%); width: 6px; height: 6px;
        border-radius: 50%; background: var(--gold);
    }

    /* Selection checkbox */
    .am-select { display: flex; align-items: center; justify-content: center; padding-top: 8px; flex-shrink: 0; }
    .am-checkbox {
        appearance: none; -webkit-appearance: none;
        width: 20px; height: 20px; border-radius: 6px;
        border: 2px solid var(--border);
        background: var(--card);
        cursor: pointer; position: relative;
        transition: all .18s ease;
        flex-shrink: 0;
    }
    .am-checkbox:hover { border-color: var(--gold); }
    .am-checkbox:checked {
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        border-color: var(--gold-strong);
    }
    .am-checkbox:checked::after {
        content: ''; position: absolute; left: 5px; top: 1px;
        width: 6px; height: 11px;
        border: solid #fff; border-width: 0 2.5px 2.5px 0;
        transform: rotate(45deg);
    }
    .am-checkbox:focus-visible { outline: none; box-shadow: 0 0 0 3px rgba(184,148,31,0.25); }

    .am-icon {
        width: 44px; height: 44px; border-radius: 11px;
        background: var(--gold-soft); color: var(--gold);
        display: flex; align-items: center; justify-content: center;
        font-size: 1rem; flex-shrink: 0;
    }
    .am-row.unread .am-icon { background: rgba(184,148,31,0.2); color: var(--gold-strong); }
    .am-body { flex: 1; min-width: 0; }
    .am-head2 {
        display: flex; justify-content: space-between; align-items: baseline;
        gap: 12px; margin-bottom: 6px; flex-wrap: wrap;
    }
    .am-title2 {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        font-size: .9rem; font-weight: 600; color: var(--text-bright); margin: 0;
    }
    .am-text { font-size: .82rem; color: var(--text-muted); line-height: 1.55; margin: 0 0 8px; }
    .am-date { font-size: .68rem; color: var(--text-dim); margin: 0 0 8px; }
    .am-actions { display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap; }
    .am-rowbtn {
        display: inline-flex; gap: 5px; align-items: center;
        padding: 5px 11px; border-radius: 7px;
        font-size: .68rem; font-weight: 600;
        cursor: pointer; border: 1px solid var(--border);
        background: transparent; color: var(--text-muted);
        text-decoration: none; transition: all .2s;
        font-family: 'Inter', sans-serif;
    }
    .am-rowbtn:hover { border-color: var(--gold); color: var(--gold); background: var(--gold-soft); }
    .am-rowbtn.danger:hover { border-color: var(--danger); color: var(--danger); background: rgba(220,38,38,.08); }

    /* Bulk action bar */
    .am-bulkbar {
        position: sticky; top: 80px; z-index: 20;
        display: none; align-items: center; gap: 12px;
        padding: 12px 18px; margin-bottom: 1rem;
        background: linear-gradient(135deg, #2b2620, #1a1713);
        border: 1px solid var(--gold-strong); border-radius: 12px;
        box-shadow: 0 12px 30px -12px rgba(0,0,0,0.4);
        color: #fff; font-family: 'Inter', sans-serif;
        flex-wrap: wrap;
    }
    .am-bulkbar.show { display: flex; }
    .am-bulkbar-count { font-size: .84rem; font-weight: 600; color: #fff; }
    .am-bulkbar-count strong { color: var(--gold); }
    .am-bulkbar-spacer { flex: 1; }
    .am-bulkbar button {
        display: inline-flex; gap: 6px; align-items: center;
        padding: 8px 14px; border-radius: 8px;
        font-family: 'Inter', sans-serif; font-size: .74rem; font-weight: 600;
        cursor: pointer; border: 1px solid transparent;
        transition: all .2s; text-decoration: none;
    }
    .am-bulkbar .bb-danger {
        background: linear-gradient(135deg, #dc2626, #b91c1c);
        color: #fff; box-shadow: 0 4px 14px -3px rgba(220,38,38,0.5);
    }
    .am-bulkbar .bb-danger:hover { transform: translateY(-2px); box-shadow: 0 8px 22px -5px rgba(220,38,38,0.65); }
    .am-bulkbar .bb-ghost {
        background: transparent; color: #d5d9e2; border-color: rgba(255,255,255,0.2);
    }
    .am-bulkbar .bb-ghost:hover { border-color: var(--gold); color: var(--gold); }

    .am-selectall {
        display: flex; align-items: center; gap: 10px;
        padding: 12px 22px; background: var(--bg-soft);
        border-bottom: 1px solid var(--border-light);
        font-family: 'Inter', sans-serif; font-size: .76rem; font-weight: 600;
        color: var(--text-muted);
    }
    .am-selectall label { display: flex; align-items: center; gap: 9px; cursor: pointer; user-select: none; }
    .am-selectall label:hover { color: var(--gold); }

    .am-empty { padding: 5rem 2rem; text-align: center; color: var(--text-dim); }
    .am-empty i { font-size: 3.5rem; margin-bottom: 1rem; opacity: .4; display: block; color: var(--gold); }
    .am-empty h3 {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        font-size: 1.5rem; margin: 0 0 .5rem; color: var(--text-bright);
    }
    .am-empty p { font-size: .88rem; margin: 0 0 1.5rem; color: var(--text-muted); }

    .am-toast {
        position: fixed; bottom: 24px; right: 24px;
        background: var(--card); border: 1px solid var(--border);
        border-left: 4px solid var(--success); border-radius: 10px;
        padding: 13px 18px; box-shadow: 0 12px 30px -8px rgba(0,0,0,.15);
        display: flex; gap: 10px; align-items: center;
        font-size: .84rem; font-weight: 500; color: var(--text-bright);
        z-index: 3000; opacity: 0; transform: translateY(20px);
        visibility: hidden; transition: all .3s ease; max-width: 340px;
    }
    .am-toast.show { opacity: 1; transform: translateY(0); visibility: visible; }
    .am-toast i { color: var(--success); font-size: 1rem; flex-shrink: 0; }
    .am-toast.error { border-left-color: var(--danger); }
    .am-toast.error i { color: var(--danger); }

    @media (max-width: 640px) {
        .am-page { padding: 1.5rem 1rem 3rem; }
        .am-head { padding: 1.25rem; }
        .am-title h1 { font-size: 1.4rem; }
        .am-row { padding: 15px 16px; gap: 12px; }
        .am-icon { width: 40px; height: 40px; }
        .am-bulkbar { top: 60px; }
    }

    @media (prefers-reduced-motion: reduce) {
        * { animation-duration: .01ms !important; transition-duration: .01ms !important; }
    }
</style>

<div class="am-page">

    <div class="am-head">
        <div class="am-head-top">
            <div class="am-title">
                <div class="am-title-icon">
                    <i class="fas fa-envelope-open-text"></i>
                </div>
                <div>
                    <h1>All Messages</h1>
                    <p>
                        <span class="am-pill total">
                            <i class="fas fa-inbox"></i>
                            <span id="amTotal"><?php echo $total; ?></span> total
                        </span>
                        <span class="am-pill unread" id="amUnreadPill" style="<?php echo $unread > 0 ? '' : 'display:none;'; ?>">
                            <i class="fas fa-circle" style="font-size:.45rem;"></i>
                            <span id="amUnread"><?php echo $unread; ?></span> unread
                        </span>
                    </p>
                </div>
            </div>
            <div class="am-toolbar">
                <button class="am-btn am-btn-primary" id="amMarkAll" <?php echo $unread > 0 ? '' : 'disabled'; ?>>
                    <i class="fas fa-check-double"></i> Mark All Read
                </button>
                <button class="am-btn am-btn-outline" id="amDelRead" <?php echo $total > 0 ? '' : 'disabled'; ?>>
                    <i class="fas fa-broom"></i> Delete Read
                </button>
                <button class="am-btn am-btn-danger" id="amDelAll" <?php echo $total > 0 ? '' : 'disabled'; ?>>
                    <i class="fas fa-trash-alt"></i> Delete All
                </button>
            </div>
        </div>
    </div>

    <div class="am-filters">
        <button class="am-chip active" data-f="all">All (<?php echo $total; ?>)</button>
        <button class="am-chip" data-f="unread">Unread (<?php echo $unread; ?>)</button>
        <button class="am-chip" data-f="read">Read (<?php echo $total - $unread; ?>)</button>
    </div>

    <div class="am-bulkbar" id="amBulkbar">
        <span class="am-bulkbar-count"><strong id="amSelCount">0</strong> selected</span>
        <div class="am-bulkbar-spacer"></div>
        <button type="button" class="bb-ghost" id="amClearSel">
            <i class="fas fa-times"></i> Clear
        </button>
        <button type="button" class="bb-danger" id="amDeleteSel">
            <i class="fas fa-trash-alt"></i> Delete Selected
        </button>
    </div>

    <div class="am-list" id="amList">
        <?php if (empty($messages)): ?>
            <div class="am-empty">
                <i class="fas fa-inbox"></i>
                <h3>No messages yet</h3>
                <p>When you receive updates about orders, layaways, or payments, they'll appear here.</p>
                <a href="shop.php" class="am-btn am-btn-primary" style="text-decoration:none;">
                    <i class="fas fa-shopping-bag"></i> Start Shopping
                </a>
            </div>
        <?php else: ?>
            <div class="am-selectall">
                <label>
                    <input type="checkbox" class="am-checkbox" id="amSelectAll">
                    <span>Select all</span>
                </label>
            </div>

            <?php foreach ($messages as $m): ?>
                <?php
                    $isUnread = empty($m['is_read']);
                    $icon     = am_icon($m['type'] ?? 'system');
                    $fullDate = am_full_date($m['created_at'] ?? '');
                ?>
                <div class="am-row <?php echo $isUnread ? 'unread' : ''; ?>" data-id="<?php echo (int)$m['id']; ?>">
                    <div class="am-select">
                        <input type="checkbox" class="am-checkbox am-rowcheck" data-id="<?php echo (int)$m['id']; ?>" aria-label="Select message">
                    </div>
                    <div class="am-icon"><i class="fas fa-<?php echo htmlspecialchars($icon); ?>"></i></div>
                    <div class="am-body">
                        <div class="am-head2">
                            <p class="am-title2"><?php echo htmlspecialchars($m['title'] ?? 'Notification'); ?></p>
                        </div>
                        <?php if (!empty($m['message'])): ?>
                            <p class="am-text"><?php echo nl2br(htmlspecialchars($m['message'])); ?></p>
                        <?php endif; ?>
                        <p class="am-date">
                            <i class="fas fa-calendar-alt" style="opacity:.6;"></i>
                            <?php echo htmlspecialchars($fullDate); ?>
                        </p>
                        <div class="am-actions">
                            <?php if ($isUnread): ?>
                                <button class="am-rowbtn" data-act="mark"><i class="fas fa-check"></i> Mark read</button>
                            <?php endif; ?>
                            <button class="am-rowbtn danger" data-act="delete"><i class="fas fa-trash"></i> Delete</button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="am-toast" id="amToast">
    <i class="fas fa-check-circle" id="amToastIcon"></i>
    <span id="amToastText">Done</span>
</div>

<script>
(function(){
    var base = window.location.pathname;
    var toast = document.getElementById('amToast');
    var toastText = document.getElementById('amToastText');
    var toastIcon = document.getElementById('amToastIcon');
    var toastTimer;

    function showToast(msg, type) {
        toastText.textContent = msg;
        toastIcon.className = type === 'error' ? 'fas fa-exclamation-circle' : 'fas fa-check-circle';
        toast.className = 'am-toast show' + (type === 'error' ? ' error' : '');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function(){ toast.classList.remove('show'); }, 2000);
    }

    function post(action, data) {
        var fd = new URLSearchParams();
        fd.append('action', action);
        Object.keys(data || {}).forEach(function(k){ fd.append(k, data[k]); });
        return fetch(base, {
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
            body: fd.toString()
        }).then(function(r){ return r.json(); });
    }

    function updateCounts() {
        var rows = document.querySelectorAll('.am-row');
        var total = rows.length;
        var unread = 0;
        rows.forEach(function(r){ if (r.classList.contains('unread')) unread++; });

        document.getElementById('amTotal').textContent = total;
        document.getElementById('amUnread').textContent = unread;
        document.getElementById('amUnreadPill').style.display = unread > 0 ? '' : 'none';
        document.getElementById('amMarkAll').disabled = unread === 0;
        document.getElementById('amDelRead').disabled = total === 0;
        document.getElementById('amDelAll').disabled = total === 0;

        document.querySelectorAll('.am-chip').forEach(function(c){
            var f = c.dataset.f;
            if (f === 'all') c.textContent = 'All (' + total + ')';
            if (f === 'unread') c.textContent = 'Unread (' + unread + ')';
            if (f === 'read') c.textContent = 'Read (' + (total - unread) + ')';
        });

        if (typeof window.updateMessageBadge === 'function') window.updateMessageBadge(unread);
    }

    /* ---------- Selection ---------- */
    var bulkbar   = document.getElementById('amBulkbar');
    var selCount  = document.getElementById('amSelCount');
    var selectAll = document.getElementById('amSelectAll');
    function rowChecks(){ return document.querySelectorAll('.am-rowcheck'); }

    function refreshSelection() {
        var checked = document.querySelectorAll('.am-rowcheck:checked').length;
        if (selCount) selCount.textContent = checked;
        if (bulkbar) bulkbar.classList.toggle('show', checked > 0);

        rowChecks().forEach(function(cb){
            var row = cb.closest('.am-row');
            if (row) row.classList.toggle('selected', cb.checked);
        });

        if (selectAll) {
            var total = rowChecks().length;
            selectAll.checked = total > 0 && checked === total;
            selectAll.indeterminate = checked > 0 && checked < total;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function(){
            var on = this.checked;
            rowChecks().forEach(function(cb){ cb.checked = on; });
            refreshSelection();
        });
    }

    var amList = document.getElementById('amList');
    if (amList) {
        amList.addEventListener('change', function(e){
            if (e.target.classList.contains('am-rowcheck')) refreshSelection();
        });
    }

    var clearBtn = document.getElementById('amClearSel');
    if (clearBtn) {
        clearBtn.addEventListener('click', function(){
            rowChecks().forEach(function(cb){ cb.checked = false; });
            if (selectAll) selectAll.checked = false;
            refreshSelection();
        });
    }

    var delSelBtn = document.getElementById('amDeleteSel');
    if (delSelBtn) {
        delSelBtn.addEventListener('click', function(){
            var ids = [];
            document.querySelectorAll('.am-rowcheck:checked').forEach(function(cb){
                ids.push(cb.getAttribute('data-id'));
            });
            if (ids.length === 0) return;
            if (!confirm('Delete ' + ids.length + ' selected message' + (ids.length > 1 ? 's' : '') + '?')) return;

            post('delete_many', { ids: ids.join(',') }).then(function(r){
                if (r.success) {
                    ids.forEach(function(id){
                        var row = document.querySelector('.am-row[data-id="' + id + '"]');
                        if (row) {
                            row.style.transition = 'opacity .2s';
                            row.style.opacity = '0';
                            setTimeout(function(){ row.remove(); }, 200);
                        }
                    });
                    setTimeout(function(){
                        refreshSelection();
                        updateCounts();
                        if (rowChecks().length === 0) {
                            document.getElementById('amList').innerHTML =
                                '<div class="am-empty">' +
                                    '<i class="fas fa-inbox"></i>' +
                                    '<h3>No messages yet</h3>' +
                                    '<p>When you receive updates about orders, layaways, or payments, they\'ll appear here.</p>' +
                                    '<a href="shop.php" class="am-btn am-btn-primary" style="text-decoration:none;">' +
                                        '<i class="fas fa-shopping-bag"></i> Start Shopping' +
                                    '</a>' +
                                '</div>';
                        }
                        showToast(ids.length + ' message' + (ids.length > 1 ? 's' : '') + ' deleted');
                    }, 220);
                }
            });
        });
    }

    /* ---------- Filter chips ---------- */
    document.querySelectorAll('.am-chip').forEach(function(c){
        c.addEventListener('click', function(){
            document.querySelectorAll('.am-chip').forEach(function(x){ x.classList.remove('active'); });
            this.classList.add('active');
            var f = this.dataset.f;
            document.querySelectorAll('.am-row').forEach(function(r){
                var unread = r.classList.contains('unread');
                var show = f === 'all' || (f === 'unread' && unread) || (f === 'read' && !unread);
                r.style.display = show ? '' : 'none';
            });
        });
    });

    /* ---------- Row actions ---------- */
    if (amList) {
        amList.addEventListener('click', function(e){
            var btn = e.target.closest('[data-act]');
            if (!btn) return;
            var row = btn.closest('.am-row');
            var id = row.dataset.id;
            var act = btn.dataset.act;

            if (act === 'mark') {
                post('mark_read', {id:id}).then(function(r){
                    if (r.success) {
                        row.classList.remove('unread');
                        btn.remove();
                        updateCounts();
                        showToast('Marked as read');
                    }
                });
                return;
            }
            if (act === 'delete') {
                if (!confirm('Delete this message?')) return;
                post('delete', {id:id}).then(function(r){
                    if (r.success) {
                        row.style.transition = 'opacity .2s';
                        row.style.opacity = '0';
                        setTimeout(function(){
                            row.remove();
                            refreshSelection();
                            updateCounts();
                            showToast('Deleted');
                        }, 200);
                    }
                });
                return;
            }
        });
    }

    /* ---------- Bulk actions ---------- */
    document.getElementById('amMarkAll').addEventListener('click', function(){
        post('mark_all_read').then(function(r){
            if (r.success) {
                document.querySelectorAll('.am-row.unread').forEach(function(row){
                    row.classList.remove('unread');
                    var b = row.querySelector('[data-act="mark"]');
                    if (b) b.remove();
                });
                updateCounts();
                showToast('All marked as read');
            }
        });
    });

    document.getElementById('amDelRead').addEventListener('click', function(){
        if (!confirm('Delete all read messages?')) return;
        post('delete_all_read').then(function(r){
            if (r.success) {
                document.querySelectorAll('.am-row').forEach(function(row){
                    if (!row.classList.contains('unread')) row.remove();
                });
                refreshSelection();
                updateCounts();
                showToast('Read messages deleted');
            }
        });
    });

    document.getElementById('amDelAll').addEventListener('click', function(){
        if (!confirm('Delete ALL messages? This cannot be undone.')) return;
        post('delete_all').then(function(r){
            if (r.success) {
                document.getElementById('amList').innerHTML =
                    '<div class="am-empty">' +
                        '<i class="fas fa-inbox"></i>' +
                        '<h3>No messages yet</h3>' +
                        '<p>When you receive updates about orders, layaways, or payments, they\'ll appear here.</p>' +
                        '<a href="shop.php" class="am-btn am-btn-primary" style="text-decoration:none;">' +
                            '<i class="fas fa-shopping-bag"></i> Start Shopping' +
                        '</a>' +
                    '</div>';
                if (bulkbar) bulkbar.classList.remove('show');
                updateCounts();
                showToast('All messages deleted');
            }
        });
    });

    updateCounts();
    refreshSelection();
})();
</script>

<?php include '../includes/footer.php'; ?>