<?php
// user/messages.php — Minimal working version
error_reporting(E_ALL);
ini_set('display_errors', 1);   // keep ON so you can see the real error

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* -------------------------------------------------------------
   Require login
   ------------------------------------------------------------- */
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$uid = (int)$_SESSION['user_id'];

/* -------------------------------------------------------------
   Load config/database.php — support common variable names
   ------------------------------------------------------------- */
$db = null;

if (file_exists(__DIR__ . '/../config/database.php')) {
    require_once __DIR__ . '/../config/database.php';
}

/* Try every common connection variable */
if (isset($pdo) && $pdo instanceof PDO)                  $db = $pdo;
elseif (isset($conn) && $conn instanceof PDO)            $db = $conn;
elseif (isset($connection) && $connection instanceof PDO)$db = $connection;
elseif (isset($db) && $db instanceof PDO)                $db = $db;
elseif (isset($pdo) && $pdo instanceof mysqli)           $db = $pdo;
elseif (isset($conn) && $conn instanceof mysqli)         $db = $conn;
elseif (isset($connection) && $connection instanceof mysqli) $db = $connection;

/* Try the Database class as a last resort */
if (!$db && class_exists('Database')) {
    try {
        $tmp = (new Database())->getConnection();
        if ($tmp instanceof PDO || $tmp instanceof mysqli) $db = $tmp;
    } catch (Throwable $e) { /* ignore */ }
}

/* -------------------------------------------------------------
   If no DB found, show clear error (don't 500)
   ------------------------------------------------------------- */
if (!$db) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>DB Error</title>';
    echo '<style>body{font-family:sans-serif;padding:40px;background:#faf7f2;color:#2b2620;}';
    echo '.box{max-width:600px;margin:0 auto;background:#fff;border:1px solid #e5ddd0;border-radius:12px;padding:28px;}';
    echo 'h1{color:#dc2626;font-size:1.3rem;}code{background:#f5f5f5;padding:2px 6px;border-radius:4px;}</style>';
    echo '</head><body><div class="box"><h1>Database connection not found</h1>';
    echo '<p>Could not find a database connection. Check <code>config/database.php</code>.</p>';
    echo '<p>Expected one of: <code>$pdo</code>, <code>$conn</code>, <code>$connection</code> as PDO or mysqli, or a <code>Database</code> class.</p>';
    echo '<p>Add <code>var_dump(get_defined_vars());</code> to your config to see what variables exist.</p>';
    echo '</div></body></html>';
    exit();
}

/* -------------------------------------------------------------
   Small helpers
   ------------------------------------------------------------- */
function run_query($db, $sql, $params = []) {
    if ($db instanceof PDO) {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    // mysqli
    $stmt = $db->prepare($sql);
    if (!$stmt) return [];
    if (!empty($params)) {
        $types = '';
        foreach ($params as $p) {
            if (is_int($p)) $types .= 'i';
            elseif (is_float($p)) $types .= 'd';
            else $types .= 's';
        }
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

function run_exec($db, $sql, $params = []) {
    if ($db instanceof PDO) {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return true;
    }
    $stmt = $db->prepare($sql);
    if (!$stmt) return false;
    if (!empty($params)) {
        $types = '';
        foreach ($params as $p) {
            if (is_int($p)) $types .= 'i';
            elseif (is_float($p)) $types .= 'd';
            else $types .= 's';
        }
        $stmt->bind_param($types, ...$params);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/* -------------------------------------------------------------
   AJAX actions
   ------------------------------------------------------------- */
if (!empty($_POST['action']) || !empty($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? $_GET['action'];
    $out = ['success' => false, 'message' => 'Unknown action'];

    try {
        if ($action === 'mark_read') {
            $id = (int)($_POST['id'] ?? 0);
            run_exec($db, "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?", [$id, $uid]);
            $out = ['success' => true, 'message' => 'Marked as read'];

        } elseif ($action === 'mark_all_read') {
            run_exec($db, "UPDATE notifications SET is_read = 1 WHERE user_id = ?", [$uid]);
            $out = ['success' => true, 'message' => 'All marked as read'];

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            run_exec($db, "DELETE FROM notifications WHERE id = ? AND user_id = ?", [$id, $uid]);
            $out = ['success' => true, 'message' => 'Deleted'];

        } elseif ($action === 'delete_all_read') {
            run_exec($db, "DELETE FROM notifications WHERE user_id = ? AND is_read = 1", [$uid]);
            $out = ['success' => true, 'message' => 'Read messages deleted'];

        } elseif ($action === 'delete_all') {
            run_exec($db, "DELETE FROM notifications WHERE user_id = ?", [$uid]);
            $out = ['success' => true, 'message' => 'All messages deleted'];

        } elseif ($action === 'get_message_count') {
            $rows = run_query($db, "SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0", [$uid]);
            $out = ['success' => true, 'count' => (int)($rows[0]['cnt'] ?? 0)];

        } elseif ($action === 'get_messages') {
            $rows = run_query($db, "SELECT id, type, title, message, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10", [$uid]);
            $out = ['success' => true, 'messages' => $rows];
        }
    } catch (Throwable $e) {
        $out = ['success' => false, 'message' => $e->getMessage()];
    }

    echo json_encode($out);
    exit();
}

/* -------------------------------------------------------------
   Load messages
   ------------------------------------------------------------- */
try {
    $messages = run_query($db,
        "SELECT id, type, title, message, link, is_read, created_at
         FROM notifications WHERE user_id = ? ORDER BY created_at DESC",
        [$uid]);
} catch (Throwable $e) {
    $messages = [];
}

$total  = count($messages);
$unread = 0;
foreach ($messages as $m) if (empty($m['is_read'])) $unread++;

function icon_for($t) {
    $map = ['order'=>'shopping-bag','layaway'=>'hand-holding-heart','payment'=>'credit-card','due'=>'clock','system'=>'info-circle'];
    return $map[strtolower((string)$t)] ?? 'bell';
}
function ago($dt) {
    if (!$dt) return '';
    $ts = strtotime($dt); if (!$ts) return '';
    $d = time() - $ts;
    if ($d < 60) return 'Just now';
    if ($d < 3600) return floor($d/60).'m ago';
    if ($d < 86400) return floor($d/3600).'h ago';
    if ($d < 604800) return floor($d/86400).'d ago';
    return date('M j, Y', $ts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages | R-Jewel&Gold</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:#f2ede4; --card:#fff; --border:#e5ddd0; --border-light:#ede7db;
            --text:#5a5245; --text-bright:#2b2620; --text-muted:#8a8172; --text-dim:#b3a998;
            --primary:#5b5fd4; --primary-soft:rgba(91,95,212,0.08);
            --gold:#b8941f; --gold-soft:rgba(184,148,31,0.1); --gold-strong:#8a6e14;
            --success:#0d9668; --danger:#dc2626;
        }
        body { font-family:'Inter',sans-serif; background:var(--bg); color:var(--text); margin:0; }
        h1,h3 { font-family:'Cormorant Garamond',serif; color:var(--text-bright); }

        .wrap { max-width:900px; margin:0 auto; padding:2.5rem 1.5rem 4rem; }
        .head { background:var(--card); border:1px solid var(--border); border-radius:16px; padding:1.5rem 1.75rem; margin-bottom:1.5rem; }
        .head-top { display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; flex-wrap:wrap; }
        .title { display:flex; gap:12px; align-items:center; }
        .title-icon { width:48px; height:48px; border-radius:12px; background:var(--gold-soft); color:var(--gold); display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0; }
        .title h1 { font-size:1.75rem; margin:0 0 3px; font-weight:700; }
        .title p { margin:0; font-size:.82rem; color:var(--text-muted); display:flex; gap:10px; flex-wrap:wrap; }
        .pill { display:inline-flex; gap:5px; align-items:center; padding:3px 10px; border-radius:20px; font-size:.7rem; font-weight:700; }
        .pill.total { background:var(--gold-soft); color:var(--gold-strong); }
        .pill.unread { background:rgba(220,38,38,.1); color:#b91c1c; }

        .toolbar { display:flex; gap:8px; flex-wrap:wrap; margin-top:1.25rem; padding-top:1.25rem; border-top:1px solid var(--border-light); }
        .btn { display:inline-flex; gap:7px; align-items:center; padding:9px 16px; border-radius:9px; font-family:'Inter',sans-serif; font-size:.76rem; font-weight:600; cursor:pointer; transition:all .2s; border:1px solid transparent; white-space:nowrap; }
        .btn-primary { background:var(--primary); color:#fff; }
        .btn-primary:hover { background:#4f46e5; }
        .btn-outline { background:transparent; color:var(--text); border-color:var(--border); }
        .btn-outline:hover { border-color:var(--gold); color:var(--gold); }
        .btn-danger { background:transparent; color:var(--danger); border-color:rgba(220,38,38,.3); }
        .btn-danger:hover { background:rgba(220,38,38,.08); border-color:var(--danger); }
        .btn:disabled { opacity:.45; cursor:not-allowed; }

        .filters { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:1.25rem; }
        .chip { padding:8px 16px; border-radius:20px; background:var(--card); border:1px solid var(--border); color:var(--text-muted); font-size:.78rem; font-weight:600; cursor:pointer; transition:all .2s; }
        .chip:hover { border-color:var(--primary); color:var(--primary); }
        .chip.active { background:var(--primary); border-color:var(--primary); color:#fff; }

        .list { background:var(--card); border:1px solid var(--border); border-radius:16px; overflow:hidden; }
        .row { display:flex; gap:14px; padding:18px 22px; border-bottom:1px solid var(--border-light); position:relative; }
        .row:last-child { border-bottom:none; }
        .row.unread { background:var(--primary-soft); }
        .row.unread::before { content:''; position:absolute; left:8px; top:50%; transform:translateY(-50%); width:6px; height:6px; border-radius:50%; background:var(--primary); }
        .row-icon { width:44px; height:44px; border-radius:11px; background:var(--gold-soft); color:var(--gold); display:flex; align-items:center; justify-content:center; font-size:1rem; flex-shrink:0; }
        .row.unread .row-icon { background:var(--primary-soft); color:var(--primary); }
        .row-body { flex:1; min-width:0; }
        .row-head { display:flex; justify-content:space-between; align-items:baseline; gap:12px; margin-bottom:6px; flex-wrap:wrap; }
        .row-title { font-size:.9rem; font-weight:600; color:var(--text-bright); margin:0; }
        .row-time { font-size:.7rem; color:var(--text-dim); display:inline-flex; align-items:center; gap:5px; }
        .row-text { font-size:.82rem; color:var(--text-muted); line-height:1.55; margin:0 0 8px; }
        .row-date { font-size:.68rem; color:var(--text-dim); margin:0 0 8px; }
        .row-actions { display:flex; gap:6px; margin-top:8px; flex-wrap:wrap; }
        .row-btn { display:inline-flex; gap:5px; align-items:center; padding:5px 11px; border-radius:7px; font-size:.68rem; font-weight:600; cursor:pointer; border:1px solid var(--border); background:transparent; color:var(--text-muted); text-decoration:none; transition:all .2s; }
        .row-btn:hover { border-color:var(--gold); color:var(--gold); background:var(--gold-soft); }
        .row-btn.danger:hover { border-color:var(--danger); color:var(--danger); background:rgba(220,38,38,.08); }
        .row-btn.link { border-color:var(--primary); color:var(--primary); }
        .row-btn.link:hover { background:var(--primary-soft); }

        .empty { padding:5rem 2rem; text-align:center; color:var(--text-dim); }
        .empty i { font-size:3.5rem; margin-bottom:1rem; opacity:.4; display:block; color:var(--gold); }
        .empty h3 { font-size:1.5rem; margin:0 0 .5rem; }
        .empty p { font-size:.88rem; margin:0 0 1.5rem; color:var(--text-muted); }

        .toast { position:fixed; bottom:24px; right:24px; background:var(--card); border:1px solid var(--border); border-left:4px solid var(--success); border-radius:10px; padding:14px 20px; box-shadow:0 12px 30px -8px rgba(0,0,0,.15); display:flex; gap:10px; align-items:center; font-size:.86rem; font-weight:500; color:var(--text-bright); z-index:3000; opacity:0; transform:translateY(20px); visibility:hidden; transition:all .3s; max-width:340px; }
        .toast.show { opacity:1; transform:translateY(0); visibility:visible; }
        .toast i { color:var(--success); font-size:1.05rem; flex-shrink:0; }
        .toast.error { border-left-color:var(--danger); }
        .toast.error i { color:var(--danger); }

        .loader { position:fixed; inset:0; background:rgba(242,237,228,.5); backdrop-filter:blur(2px); display:none; align-items:center; justify-content:center; z-index:4000; }
        .loader.active { display:flex; }
        .spinner { width:44px; height:44px; border-radius:50%; border:4px solid var(--border); border-top-color:var(--primary); animation:spin .7s linear infinite; }
        @keyframes spin { to { transform:rotate(360deg); } }
    </style>
</head>
<body>

<div class="wrap">
    <div class="head">
        <div class="head-top">
            <div class="title">
                <div class="title-icon"><i class="fas fa-envelope-open-text"></i></div>
                <div>
                    <h1>Messages</h1>
                    <p>
                        <span class="pill total"><i class="fas fa-inbox"></i> <?php echo $total; ?> total</span>
                        <span class="pill unread" style="<?php echo $unread > 0 ? '' : 'display:none;'; ?>">
                            <i class="fas fa-circle" style="font-size:.45rem;"></i> <?php echo $unread; ?> unread
                        </span>
                    </p>
                </div>
            </div>
            <div class="toolbar">
                <button class="btn btn-primary" id="btnMarkAllRead" <?php echo $unread > 0 ? '' : 'disabled'; ?>><i class="fas fa-check-double"></i> Mark All Read</button>
                <button class="btn btn-outline" id="btnDeleteAllRead" <?php echo $total > 0 ? '' : 'disabled'; ?>><i class="fas fa-broom"></i> Delete Read</button>
                <button class="btn btn-danger" id="btnDeleteAll" <?php echo $total > 0 ? '' : 'disabled'; ?>><i class="fas fa-trash-alt"></i> Delete All</button>
            </div>
        </div>
    </div>

    <div class="filters">
        <button class="chip active" data-filter="all">All (<?php echo $total; ?>)</button>
        <button class="chip" data-filter="unread">Unread (<?php echo $unread; ?>)</button>
        <button class="chip" data-filter="read">Read (<?php echo $total - $unread; ?>)</button>
    </div>

    <div class="list" id="msgList">
        <?php if (empty($messages)): ?>
            <div class="empty">
                <i class="fas fa-inbox"></i>
                <h3>No messages yet</h3>
                <p>When you receive updates about orders, layaways, or payments, they'll appear here.</p>
                <a href="shop.php" class="btn btn-primary" style="text-decoration:none;"><i class="fas fa-shopping-bag"></i> Start Shopping</a>
            </div>
        <?php else: ?>
            <?php foreach ($messages as $m): ?>
                <?php
                    $isUnread = empty($m['is_read']);
                    $icon     = icon_for($m['type'] ?? 'system');
                    $timeAgo  = ago($m['created_at'] ?? '');
                    $fullDate = !empty($m['created_at']) ? date('M j, Y · g:i A', strtotime($m['created_at'])) : '';
                    $link     = trim((string)($m['link'] ?? ''));
                ?>
                <div class="row <?php echo $isUnread ? 'unread' : ''; ?>" data-id="<?php echo (int)$m['id']; ?>">
                    <div class="row-icon"><i class="fas fa-<?php echo htmlspecialchars($icon); ?>"></i></div>
                    <div class="row-body">
                        <div class="row-head">
                            <p class="row-title"><?php echo htmlspecialchars($m['title'] ?? 'Notification'); ?></p>
                            <span class="row-time"><i class="fas fa-clock"></i> <?php echo htmlspecialchars($timeAgo); ?></span>
                        </div>
                        <?php if (!empty($m['message'])): ?>
                            <p class="row-text"><?php echo nl2br(htmlspecialchars($m['message'])); ?></p>
                        <?php endif; ?>
                        <p class="row-date"><i class="fas fa-calendar-alt" style="opacity:.6;"></i> <?php echo htmlspecialchars($fullDate); ?></p>
                        <div class="row-actions">
                            <?php if ($link !== ''): ?>
                                <button class="row-btn link" data-action="open" data-link="<?php echo htmlspecialchars($link); ?>"><i class="fas fa-external-link-alt"></i> Open</button>
                            <?php endif; ?>
                            <?php if ($isUnread): ?>
                                <button class="row-btn" data-action="mark-read"><i class="fas fa-check"></i> Mark read</button>
                            <?php endif; ?>
                            <button class="row-btn danger" data-action="delete"><i class="fas fa-trash"></i> Delete</button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="toast" id="toast"><i class="fas fa-check-circle" id="toastIcon"></i><span id="toastText">Done</span></div>
<div class="loader" id="loader"><div class="spinner"></div></div>

<script>
(function(){
    var base = window.location.pathname;
    var toast = document.getElementById('toast');
    var toastText = document.getElementById('toastText');
    var toastIcon = document.getElementById('toastIcon');
    var toastTimer;
    var loader = document.getElementById('loader');

    function showToast(msg, type) {
        toastText.textContent = msg;
        toast.className = 'toast show' + (type === 'error' ? ' error' : '');
        toastIcon.className = type === 'error' ? 'fas fa-exclamation-circle' : 'fas fa-check-circle';
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function(){ toast.classList.remove('show'); }, 1800);
    }
    function showLoader(){ loader.classList.add('active'); }
    function hideLoader(){ loader.classList.remove('active'); }
    function reload(msg, type){ showToast(msg, type); setTimeout(function(){ location.reload(); }, 700); }

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

    /* Filters */
    document.querySelectorAll('.chip').forEach(function(c){
        c.addEventListener('click', function(){
            document.querySelectorAll('.chip').forEach(function(x){ x.classList.remove('active'); });
            this.classList.add('active');
            var f = this.dataset.filter;
            document.querySelectorAll('.row').forEach(function(r){
                var unread = r.classList.contains('unread');
                var show = f === 'all' || (f === 'unread' && unread) || (f === 'read' && !unread);
                r.style.display = show ? '' : 'none';
            });
        });
    });

    /* Row actions */
    document.getElementById('msgList').addEventListener('click', function(e){
        var btn = e.target.closest('[data-action]');
        if (!btn) return;
        var row = btn.closest('.row');
        var id = row.dataset.id;
        var action = btn.dataset.action;

        if (action === 'open') {
            var link = btn.dataset.link;
            var wasUnread = row.classList.contains('unread');
            function go(){ location.href = link; }
            if (wasUnread) { showLoader(); post('mark_read', {id:id}).then(go).catch(go); }
            else go();
            return;
        }
        if (action === 'mark-read') {
            showLoader();
            post('mark_read', {id:id}).then(function(r){
                hideLoader();
                if (r.success) reload('Marked as read');
                else showToast(r.message || 'Failed', 'error');
            }).catch(function(){ hideLoader(); showToast('Network error', 'error'); });
            return;
        }
        if (action === 'delete') {
            if (!confirm('Delete this message?')) return;
            showLoader();
            post('delete', {id:id}).then(function(r){
                hideLoader();
                if (r.success) reload('Message deleted');
                else showToast(r.message || 'Failed', 'error');
            }).catch(function(){ hideLoader(); showToast('Network error', 'error'); });
            return;
        }
    });

    /* Bulk actions */
    document.getElementById('btnMarkAllRead').addEventListener('click', function(){
        showLoader();
        post('mark_all_read').then(function(r){
            hideLoader();
            if (r.success) reload('All marked as read');
            else showToast(r.message || 'Failed', 'error');
        }).catch(function(){ hideLoader(); showToast('Network error', 'error'); });
    });

    document.getElementById('btnDeleteAllRead').addEventListener('click', function(){
        if (!confirm('Delete all read messages?')) return;
        showLoader();
        post('delete_all_read').then(function(r){
            hideLoader();
            if (r.success) reload('Read messages deleted');
            else showToast(r.message || 'Failed', 'error');
        }).catch(function(){ hideLoader(); showToast('Network error', 'error'); });
    });

    document.getElementById('btnDeleteAll').addEventListener('click', function(){
        if (!confirm('Delete ALL messages? This cannot be undone.')) return;
        showLoader();
        post('delete_all').then(function(r){
            hideLoader();
            if (r.success) reload('All messages deleted');
            else showToast(r.message || 'Failed', 'error');
        }).catch(function(){ hideLoader(); showToast('Network error', 'error'); });
    });
})();
</script>
</body>
</html>