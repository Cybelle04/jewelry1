<?php
// admin/layaway.php — Layaway Management
// All preferences (appearance + filters + account + security) sync across all browsers/devices.
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$db = (new Database())->getConnection();

/* ============================================================
   INLINE SYNC ENDPOINT — notifications + all preferences
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_notif_sync'])) {
    header('Content-Type: application/json; charset=utf-8');

    $db->exec("CREATE TABLE IF NOT EXISTS notification_reads (
        notification_id VARCHAR(64) NOT NULL PRIMARY KEY,
        read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS notification_deletes (
        notification_id VARCHAR(64) NOT NULL PRIMARY KEY,
        deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS admin_preferences (
        pref_key VARCHAR(64) NOT NULL PRIMARY KEY,
        pref_value TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $action = strtolower((string)($_POST['action'] ?? ''));

    try {
        // ---------- Save appearance / notification prefs ----------
        if (in_array($action, ['save_appearance','save_prefs','save_all_prefs'], true)) {
            $prefs = [];
            $appearanceKeys = ['setTheme','setAccent','setSidebar','toggleCompact','toggleAnimations'];
            $notifKeys      = ['toggleNewOrders','toggleLowStock','toggleNewCustomer','toggleOrderStatus','toggleWeekly'];

            if ($action === 'save_appearance' || $action === 'save_all_prefs') {
                foreach ($appearanceKeys as $k) {
                    if (in_array($k, ['toggleCompact','toggleAnimations'], true)) {
                        $prefs[$k] = (($_POST[$k] ?? '0') === '1') ? '1' : '0';
                    } else if (isset($_POST[$k])) {
                        $prefs[$k] = (string)$_POST[$k];
                    }
                }
            }
            if ($action === 'save_prefs' || $action === 'save_all_prefs') {
                foreach ($notifKeys as $k) {
                    $prefs[$k] = (($_POST[$k] ?? '0') === '1') ? '1' : '0';
                }
            }

            if (!empty($prefs)) {
                $stmt = $db->prepare("
                    INSERT INTO admin_preferences (pref_key, pref_value) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value)
                ");
                foreach ($prefs as $k => $v) $stmt->execute([$k, $v]);
            }
            echo json_encode(['success' => true, 'saved' => $prefs]);
            exit();
        }

        // ---------- Account (name, email, phone) ----------
        if ($action === 'save_account') {
            if (!isset($_SESSION['user_id'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Forbidden']);
                exit();
            }
            $userId   = (int)$_SESSION['user_id'];
            $fullName = trim((string)($_POST['setFullName'] ?? ''));
            $username = trim((string)($_POST['setUsername'] ?? ''));
            $email    = trim((string)($_POST['setEmail']    ?? ''));
            $phone    = trim((string)($_POST['setPhone']    ?? ''));

            if ($fullName === '' || $username === '') {
                echo json_encode(['success' => false, 'error' => 'Full name and username are required.']);
                exit();
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'error' => 'Enter a valid email address.']);
                exit();
            }

            try {
                $dup = $db->prepare("SELECT id FROM users WHERE (email = ? OR username = ?) AND id <> ? LIMIT 1");
                $dup->execute([$email, $username, $userId]);
                if ($dup->fetchColumn()) {
                    echo json_encode(['success' => false, 'error' => 'Email or username is already taken.']);
                    exit();
                }

                $stmt = $db->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, phone = ? WHERE id = ?");
                $stmt->execute([$fullName, $username, $email, $phone ?: null, $userId]);

                $_SESSION['full_name'] = $fullName;
                $_SESSION['username']  = $username;
                $_SESSION['email']     = $email;
                $_SESSION['phone']     = $phone;

                echo json_encode(['success' => true]);
            } catch (Throwable $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit();
        }

        // ---------- Password change ----------
        if ($action === 'save_password') {
            if (!isset($_SESSION['user_id'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Forbidden']);
                exit();
            }
            $userId  = (int)$_SESSION['user_id'];
            $current = (string)($_POST['setCurrentPass'] ?? '');
            $next    = (string)($_POST['setNewPass']     ?? '');
            $confirm = (string)($_POST['setConfirmPass'] ?? '');

            if ($current === '' || $next === '' || $confirm === '') {
                echo json_encode(['success' => false, 'error' => 'All password fields are required.']);
                exit();
            }
            if ($next !== $confirm) {
                echo json_encode(['success' => false, 'error' => 'New password and confirmation do not match.']);
                exit();
            }
            if (strlen($next) < 8 || !preg_match('/[A-Za-z]/', $next) || !preg_match('/\d/', $next)) {
                echo json_encode(['success' => false, 'error' => 'New password must be 8+ characters with letters and numbers.']);
                exit();
            }

            try {
                $stmt = $db->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$userId]);
                $hash = $stmt->fetchColumn();

                $ok = false;
                if (is_string($hash) && $hash !== '') {
                    if (password_verify($current, $hash)) $ok = true;
                    elseif (hash_equals($hash, md5($current))) $ok = true;
                    elseif (hash_equals($hash, sha1($current))) $ok = true;
                }
                if (!$ok) {
                    echo json_encode(['success' => false, 'error' => 'Current password is incorrect.']);
                    exit();
                }

                $newHash = password_hash($next, PASSWORD_DEFAULT);
                $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$newHash, $userId]);
                echo json_encode(['success' => true]);
            } catch (Throwable $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit();
        }

        // ---------- Security toggles (2FA + login alerts) ----------
        if ($action === 'save_security') {
            if (!isset($_SESSION['user_id'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Forbidden']);
                exit();
            }
            $userId      = (int)$_SESSION['user_id'];
            $twoFA       = (($_POST['toggle2FA']          ?? '0') === '1') ? 1 : 0;
            $loginAlerts = (($_POST['toggleLoginAlerts']  ?? '0') === '1') ? 1 : 0;

            try {
                try { $db->exec("ALTER TABLE users ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
                try { $db->exec("ALTER TABLE users ADD COLUMN login_alerts TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}

                $db->prepare("UPDATE users SET two_factor_enabled = ?, login_alerts = ? WHERE id = ?")
                   ->execute([$twoFA, $loginAlerts, $userId]);
                echo json_encode(['success' => true]);
            } catch (Throwable $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit();
        }

        // ---------- Notification read/delete ----------
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) $ids = [$ids];
        $ids = array_values(array_filter(
            array_map(fn($v) => trim((string)$v), $ids),
            fn($v) => $v !== '' && strlen($v) <= 64
        ));

        if (empty($ids)) {
            echo json_encode(['success' => true, 'count' => 0]);
            exit();
        }

        if ($action === 'read') {
            $ph = implode(',', array_fill(0, count($ids), '(?, NOW())'));
            $stmt = $db->prepare("INSERT INTO notification_reads (notification_id, read_at) VALUES $ph
                                  ON DUPLICATE KEY UPDATE read_at = read_at");
            $stmt->execute($ids);
            echo json_encode(['success' => true, 'count' => count($ids)]);
        } elseif ($action === 'unread') {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("DELETE FROM notification_reads WHERE notification_id IN ($ph)");
            $stmt->execute($ids);
            echo json_encode(['success' => true, 'count' => $stmt->rowCount()]);
        } elseif ($action === 'delete') {
            $ph = implode(',', array_fill(0, count($ids), '(?, NOW())'));
            $stmt = $db->prepare("INSERT INTO notification_deletes (notification_id, deleted_at) VALUES $ph
                                  ON DUPLICATE KEY UPDATE deleted_at = deleted_at");
            $stmt->execute($ids);
            $stmt2 = $db->prepare("INSERT INTO notification_reads (notification_id, read_at) VALUES $ph
                                   ON DUPLICATE KEY UPDATE read_at = read_at");
            $stmt2->execute($ids);
            echo json_encode(['success' => true, 'count' => count($ids)]);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

/* ============================================================
   HELPERS
   ============================================================ */
function adminFormatTime($value) {
    if (!$value) return '—';
    try { $dt = new DateTime($value); } catch (Throwable $e) { return '—'; }
    return $dt->format('M j, Y g:i A');
}
function adminIsRecent($value, $hours = 24) {
    if (!$value) return false;
    $ts = strtotime($value);
    if (!$ts) return false;
    return (time() - $ts) < ($hours * 3600);
}
function notifReadIds(PDO $db): array {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS notification_reads (
            notification_id VARCHAR(64) NOT NULL PRIMARY KEY,
            read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return array_flip($db->query("SELECT notification_id FROM notification_reads")->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return []; }
}
function notifDeletedIds(PDO $db): array {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS notification_deletes (
            notification_id VARCHAR(64) NOT NULL PRIMARY KEY,
            deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return array_flip($db->query("SELECT notification_id FROM notification_deletes")->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return []; }
}
function adminPrefs(PDO $db): array {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS admin_preferences (
            pref_key VARCHAR(64) NOT NULL PRIMARY KEY,
            pref_value TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $rows = $db->query("SELECT pref_key, pref_value FROM admin_preferences")->fetchAll(PDO::FETCH_KEY_PAIR);
        return $rows ?: [];
    } catch (Throwable $e) { return []; }
}
function getCurrentAdmin(PDO $db, int $userId): array {
    $default = [
        'full_name'          => $_SESSION['full_name'] ?? 'Admin User',
        'username'           => $_SESSION['username']  ?? 'admin',
        'email'              => '',
        'phone'              => '',
        'two_factor_enabled' => 0,
        'login_alerts'       => 1,
    ];
    if ($userId <= 0) return $default;
    try {
        $stmt = $db->prepare("SELECT full_name, username, email, phone,
                                     COALESCE(two_factor_enabled,0) AS two_factor_enabled,
                                     COALESCE(login_alerts,1)       AS login_alerts
                              FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return array_merge($default, $row);
    } catch (Throwable $e) {}
    return $default;
}

function push_user_notification(PDO $db, int $userId, string $type, string $title, string $message = '', string $link = ''): int|false {
    if ($userId <= 0) return false;
    $allowed = ['order','layaway','payment','due','system'];
    if (!in_array($type, $allowed, true)) $type = 'system';
    try {
        $stmt = $db->prepare("
            INSERT INTO notifications (user_id, type, title, message, link, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, 0, NOW())
        ");
        $stmt->execute([$userId, $type, $title, $message, $link]);
        return (int)$db->lastInsertId();
    } catch (Throwable $e) {
        return false;
    }
}

function ensureLayawayTables(PDO $db) {
    $db->exec("CREATE TABLE IF NOT EXISTS layaway_plans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        product_id INT NOT NULL,
        total_amount DECIMAL(12,2) NOT NULL,
        down_payment DECIMAL(12,2) NOT NULL,
        balance DECIMAL(12,2) NOT NULL,
        period_days INT NOT NULL DEFAULT 60,
        status ENUM('pending','approved','rejected','active','overdue','completed','cancelled') NOT NULL DEFAULT 'pending',
        start_date DATE NULL,
        due_date DATE NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_product (product_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS layaway_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        plan_id INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        payment_method VARCHAR(50) NULL,
        reference_no VARCHAR(100) NULL,
        proof_image VARCHAR(255) NULL,
        status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
        paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        verified_at TIMESTAMP NULL,
        verified_by INT NULL,
        notes TEXT NULL,
        INDEX idx_plan (plan_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS layaway_settings (
        id INT PRIMARY KEY DEFAULT 1,
        periods VARCHAR(255) NOT NULL DEFAULT '30,60,90,120',
        min_down_payment_percent DECIMAL(5,2) NOT NULL DEFAULT 20.00,
        reminder_days VARCHAR(100) NOT NULL DEFAULT '7,3,1',
        overdue_grace_days INT NOT NULL DEFAULT 3,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("INSERT IGNORE INTO layaway_settings (id) VALUES (1)");

    $db->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        type ENUM('order','layaway','payment','due','system') NOT NULL DEFAULT 'system',
        title VARCHAR(255) NOT NULL,
        message TEXT NULL,
        link VARCHAR(255) NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_read (is_read)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

ensureLayawayTables($db);

/* ============================================================
   HANDLE LAYAWAY AJAX ACTIONS
   ============================================================ */
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Unknown action'];

    try {
        switch ($action) {

            case 'approve_plan':
                $id = (int)($_POST['id'] ?? 0);
                $period = (int)($_POST['period_days'] ?? 60);
                $stmt = $db->prepare("SELECT lp.user_id, lp.balance, p.name AS product_name
                                      FROM layaway_plans lp LEFT JOIN products p ON p.id = lp.product_id
                                      WHERE lp.id = ?");
                $stmt->execute([$id]);
                $plan = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$plan) throw new Exception('Plan not found');
                $dueDate = date('Y-m-d', strtotime("+$period days"));
                $db->prepare("UPDATE layaway_plans SET status='active', start_date=CURDATE(), due_date=?, period_days=? WHERE id=?")
                   ->execute([$dueDate, $period, $id]);
                push_user_notification($db, (int)$plan['user_id'], 'layaway',
                    'Layaway approved — #LY-' . str_pad($id, 4, '0', STR_PAD_LEFT),
                    'Your layaway for "' . ($plan['product_name'] ?? 'item') . '" has been approved. Balance: ₱' . number_format((float)$plan['balance'], 2) . '. Due: ' . date('M j, Y', strtotime($dueDate)) . '.',
                    'account.php');
                $response = ['success' => true, 'message' => 'Layaway approved and activated.'];
                break;

            case 'reject_plan':
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $db->prepare("SELECT lp.user_id, p.name AS product_name
                                      FROM layaway_plans lp LEFT JOIN products p ON p.id = lp.product_id
                                      WHERE lp.id = ?");
                $stmt->execute([$id]);
                $plan = $stmt->fetch(PDO::FETCH_ASSOC);
                $db->prepare("UPDATE layaway_plans SET status='rejected' WHERE id=?")->execute([$id]);
                if ($plan) {
                    push_user_notification($db, (int)$plan['user_id'], 'layaway',
                        'Layaway application rejected — #LY-' . str_pad($id, 4, '0', STR_PAD_LEFT),
                        'Unfortunately your layaway application for "' . ($plan['product_name'] ?? 'item') . '" was rejected. Please contact us for more information.',
                        'account.php');
                }
                $response = ['success' => true, 'message' => 'Layaway rejected.'];
                break;

            case 'verify_payment':
                $paymentId = (int)($_POST['payment_id'] ?? 0);
                $adminId = (int)($_SESSION['user_id'] ?? 0);
                $db->prepare("UPDATE layaway_payments SET status='verified', verified_at=NOW(), verified_by=? WHERE id=?")
                   ->execute([$adminId, $paymentId]);
                $stmt = $db->prepare("SELECT lpay.plan_id, lpay.amount, lp.user_id, lp.balance, lp.status, p.name AS product_name
                                      FROM layaway_payments lpay
                                      JOIN layaway_plans lp ON lp.id = lpay.plan_id
                                      LEFT JOIN products p ON p.id = lp.product_id
                                      WHERE lpay.id = ?");
                $stmt->execute([$paymentId]);
                $p = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($p) {
                    $db->prepare("UPDATE layaway_plans SET balance = GREATEST(0, balance - ?),
                                  status = IF(balance - ? <= 0, 'completed', status) WHERE id=?")
                       ->execute([$p['amount'], $p['amount'], $p['plan_id']]);
                    $stmt2 = $db->prepare("SELECT balance, status FROM layaway_plans WHERE id=?");
                    $stmt2->execute([$p['plan_id']]);
                    $updated = $stmt2->fetch(PDO::FETCH_ASSOC);
                    $newBalance = (float)($updated['balance'] ?? 0);
                    $newStatus  = strtolower((string)($updated['status'] ?? 'active'));
                    $title = 'Payment received — #LY-' . str_pad((int)$p['plan_id'], 4, '0', STR_PAD_LEFT);
                    $msg = 'We received your payment of ₱' . number_format((float)$p['amount'], 2) . ' for "' . ($p['product_name'] ?? 'item') . '".';
                    if ($newStatus === 'completed' || $newBalance <= 0) {
                        $msg .= ' Your layaway is now fully paid and completed. 🎉';
                    } else {
                        $msg .= ' Remaining balance: ₱' . number_format($newBalance, 2) . '.';
                    }
                    push_user_notification($db, (int)$p['user_id'], 'payment', $title, $msg, 'account.php');
                }
                $response = ['success' => true, 'message' => 'Payment verified and balance updated.'];
                break;

            case 'reject_payment':
                $paymentId = (int)($_POST['payment_id'] ?? 0);
                $stmt = $db->prepare("SELECT lpay.plan_id, lpay.amount, lp.user_id, p.name AS product_name
                                      FROM layaway_payments lpay
                                      JOIN layaway_plans lp ON lp.id = lpay.plan_id
                                      LEFT JOIN products p ON p.id = lp.product_id
                                      WHERE lpay.id = ?");
                $stmt->execute([$paymentId]);
                $p = $stmt->fetch(PDO::FETCH_ASSOC);
                $db->prepare("UPDATE layaway_payments SET status='rejected' WHERE id=?")->execute([$paymentId]);
                if ($p) {
                    push_user_notification($db, (int)$p['user_id'], 'payment',
                        'Payment rejected — #LY-' . str_pad((int)$p['plan_id'], 4, '0', STR_PAD_LEFT),
                        'Your payment proof of ₱' . number_format((float)$p['amount'], 2) . ' for "' . ($p['product_name'] ?? 'item') . '" was rejected. Please re-upload a clear proof of payment or contact us.',
                        'account.php');
                }
                $response = ['success' => true, 'message' => 'Payment proof rejected.'];
                break;

            case 'send_reminder':
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $db->prepare("SELECT lp.user_id, lp.balance, lp.due_date, p.name AS product_name
                                      FROM layaway_plans lp LEFT JOIN products p ON p.id = lp.product_id WHERE lp.id = ?");
                $stmt->execute([$id]);
                $plan = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$plan) throw new Exception('Plan not found');
                $dueTxt = $plan['due_date'] ? date('M j, Y', strtotime($plan['due_date'])) : 'soon';
                push_user_notification($db, (int)$plan['user_id'], 'due',
                    'Payment reminder — #LY-' . str_pad($id, 4, '0', STR_PAD_LEFT),
                    'Friendly reminder: your layaway for "' . ($plan['product_name'] ?? 'item') . '" has a remaining balance of ₱' . number_format((float)$plan['balance'], 2) . ' due on ' . $dueTxt . '.',
                    'account.php');
                $response = ['success' => true, 'message' => 'Reminder sent to customer for layaway #' . $id];
                break;

            case 'mark_overdue':
                $grace = (int)($db->query("SELECT overdue_grace_days FROM layaway_settings WHERE id=1")->fetchColumn() ?: 3);
                $stmt = $db->prepare("SELECT lp.id, lp.user_id, lp.balance, p.name AS product_name
                                      FROM layaway_plans lp LEFT JOIN products p ON p.id = lp.product_id
                                      WHERE lp.status='active' AND lp.due_date IS NOT NULL
                                        AND lp.due_date < DATE_SUB(CURDATE(), INTERVAL ? DAY)");
                $stmt->execute([$grace]);
                $affected = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $stmt2 = $db->prepare("UPDATE layaway_plans SET status='overdue'
                                       WHERE status='active' AND due_date IS NOT NULL
                                         AND due_date < DATE_SUB(CURDATE(), INTERVAL ? DAY)");
                $stmt2->execute([$grace]);
                $count = $stmt2->rowCount();
                foreach ($affected as $plan) {
                    push_user_notification($db, (int)$plan['user_id'], 'due',
                        'Layaway overdue — #LY-' . str_pad((int)$plan['id'], 4, '0', STR_PAD_LEFT),
                        'Your layaway for "' . ($plan['product_name'] ?? 'item') . '" is now overdue. Outstanding balance: ₱' . number_format((float)$plan['balance'], 2) . '. Please settle as soon as possible.',
                        'account.php');
                }
                $response = ['success' => true, 'message' => "$count account(s) marked as overdue."];
                break;

            case 'save_settings':
                $periods = trim($_POST['periods'] ?? '30,60,90,120');
                $minDown = (float)($_POST['min_down_payment_percent'] ?? 20);
                $reminders = trim($_POST['reminder_days'] ?? '7,3,1');
                $grace = (int)($_POST['overdue_grace_days'] ?? 3);
                $db->prepare("UPDATE layaway_settings SET periods=?, min_down_payment_percent=?, reminder_days=?, overdue_grace_days=? WHERE id=1")
                   ->execute([$periods, $minDown, $reminders, $grace]);
                $response = ['success' => true, 'message' => 'Layaway settings saved.'];
                break;

            case 'create_layaway':
                $userId = (int)($_POST['user_id'] ?? 0);
                $productId = (int)($_POST['product_id'] ?? 0);
                $total = (float)($_POST['total_amount'] ?? 0);
                $down = (float)($_POST['down_payment'] ?? 0);
                $period = (int)($_POST['period_days'] ?? 60);
                if ($userId <= 0 || $productId <= 0 || $total <= 0) throw new Exception('Missing required fields');
                $balance = $total - $down;
                $dueDate = date('Y-m-d', strtotime("+$period days"));
                $stmt = $db->prepare("INSERT INTO layaway_plans (user_id, product_id, total_amount, down_payment, balance, period_days, status, start_date, due_date)
                                      VALUES (?, ?, ?, ?, ?, ?, 'active', CURDATE(), ?)");
                $stmt->execute([$userId, $productId, $total, $down, $balance, $period, $dueDate]);
                $newId = (int)$db->lastInsertId();
                $pstmt = $db->prepare("SELECT name FROM products WHERE id=?");
                $pstmt->execute([$productId]);
                $pname = $pstmt->fetchColumn() ?: 'item';
                push_user_notification($db, $userId, 'layaway',
                    'New layaway created — #LY-' . str_pad($newId, 4, '0', STR_PAD_LEFT),
                    'An admin has set up a layaway plan for "' . $pname . '". Total: ₱' . number_format($total, 2) . '. Down paid: ₱' . number_format($down, 2) . '. Balance: ₱' . number_format($balance, 2) . '. Due: ' . date('M j, Y', strtotime($dueDate)) . '.',
                    'account.php');
                $response = ['success' => true, 'message' => 'Layaway plan created.', 'id' => $newId];
                break;

            case 'cancel_plan':
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $db->prepare("SELECT user_id FROM layaway_plans WHERE id=?");
                $stmt->execute([$id]);
                $uid = (int)($stmt->fetchColumn() ?: 0);
                $db->prepare("UPDATE layaway_plans SET status='cancelled' WHERE id=?")->execute([$id]);
                if ($uid > 0) {
                    push_user_notification($db, $uid, 'layaway',
                        'Layaway cancelled — #LY-' . str_pad($id, 4, '0', STR_PAD_LEFT),
                        'Your layaway plan #LY-' . str_pad($id, 4, '0', STR_PAD_LEFT) . ' has been cancelled by an admin.',
                        'account.php');
                }
                $response = ['success' => true, 'message' => 'Layaway cancelled.'];
                break;

            default:
                $response = ['success' => false, 'message' => 'Action not implemented: ' . $action];
        }
    } catch (Throwable $e) {
        $response = ['success' => false, 'message' => $e->getMessage()];
    }

    echo json_encode($response);
    exit();
}

/* ============================================================
   FETCH DATA FOR RENDERING
   ============================================================ */
$settings = $db->query("SELECT * FROM layaway_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: [
    'periods' => '30,60,90,120',
    'min_down_payment_percent' => 20,
    'reminder_days' => '7,3,1',
    'overdue_grace_days' => 3
];

$stats = [];
$stats['active']    = (int)$db->query("SELECT COUNT(*) FROM layaway_plans WHERE status IN ('active','approved')")->fetchColumn();
$stats['pending']   = (int)$db->query("SELECT COUNT(*) FROM layaway_plans WHERE status='pending'")->fetchColumn();
$stats['overdue']   = (int)$db->query("SELECT COUNT(*) FROM layaway_plans WHERE status='overdue'")->fetchColumn();
$stats['completed'] = (int)$db->query("SELECT COUNT(*) FROM layaway_plans WHERE status='completed'")->fetchColumn();
$stats['total_down'] = (float)$db->query("SELECT COALESCE(SUM(down_payment),0) FROM layaway_plans WHERE status NOT IN ('rejected','cancelled')")->fetchColumn();
$stats['outstanding'] = (float)$db->query("SELECT COALESCE(SUM(balance),0) FROM layaway_plans WHERE status IN ('active','approved','overdue')")->fetchColumn();
$stats['due_week']   = (int)$db->query("SELECT COUNT(*) FROM layaway_plans WHERE status='active' AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();

$plans = $db->query("
    SELECT lp.*, 
           u.full_name AS customer_name, u.email AS customer_email, u.phone AS customer_phone,
           p.name AS product_name, p.price AS product_price
    FROM layaway_plans lp
    LEFT JOIN users u ON u.id = lp.user_id
    LEFT JOIN products p ON p.id = lp.product_id
    ORDER BY lp.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$proofs = $db->query("
    SELECT lpay.*, lp.id AS plan_ref, u.full_name AS customer_name
    FROM layaway_payments lpay
    JOIN layaway_plans lp ON lp.id = lpay.plan_id
    LEFT JOIN users u ON u.id = lp.user_id
    WHERE lpay.status = 'pending'
    ORDER BY lpay.paid_at DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

$upcoming = $db->query("
    SELECT lp.id, lp.due_date, lp.balance, u.full_name AS customer_name
    FROM layaway_plans lp
    LEFT JOIN users u ON u.id = lp.user_id
    WHERE lp.status = 'active' AND lp.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY lp.due_date ASC LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

$transactions = $db->query("
    SELECT lpay.*, lp.id AS plan_ref, u.full_name AS customer_name
    FROM layaway_payments lpay
    JOIN layaway_plans lp ON lp.id = lpay.plan_id
    LEFT JOIN users u ON u.id = lp.user_id
    ORDER BY lpay.paid_at DESC LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

$customers = $db->query("SELECT id, full_name, email FROM users WHERE role='user' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
$products  = $db->query("SELECT id, name, price FROM products ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* ============================================================
   BUILD ADMIN NOTIFICATIONS
   ============================================================ */
$admin_notifications = [];

try {
    $rows = $db->query("SELECT o.id, o.total_amount, o.status, o.created_at, u.full_name
                        FROM orders o LEFT JOIN users u ON u.id = o.user_id
                        ORDER BY o.created_at DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $status = strtolower((string)($r['status'] ?? 'pending'));
        $isNew = in_array($status, ['pending','new','placed'], true) && adminIsRecent($r['created_at'] ?? null, 24);
        $admin_notifications[] = [
            'id' => 'order-' . $r['id'], 'icon' => 'shopping-cart', 'iconClass' => 'blue',
            'title' => ($isNew ? 'New order #' : 'Order #') . $r['id'] . ($isNew ? ' received' : ' · ' . ucfirst($status)),
            'desc' => trim((string)($r['full_name'] ?? 'Customer')) . ' · ' . number_format((float)($r['total_amount'] ?? 0), 2) . ' · Status: ' . ucfirst($status),
            'meta' => adminFormatTime($r['created_at'] ?? null),
            'unread' => $isNew, 'link' => 'orders.php?id=' . (int)$r['id'],
            '_ts' => strtotime($r['created_at'] ?? '') ?: 0,
        ];
    }
} catch (Throwable $e) { }

try {
    $rows = $db->query("SELECT lp.id, lp.total_amount, lp.status, lp.created_at, u.full_name
                        FROM layaway_plans lp LEFT JOIN users u ON u.id = lp.user_id
                        ORDER BY lp.created_at DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $status = strtolower((string)($r['status'] ?? 'pending'));
        $isNew = in_array($status, ['pending'], true) && adminIsRecent($r['created_at'] ?? null, 24);
        $admin_notifications[] = [
            'id' => 'layaway-' . $r['id'], 'icon' => 'hand-holding-heart', 'iconClass' => 'purple',
            'title' => ($isNew ? 'New layaway #LY-' : 'Layaway #LY-') . str_pad($r['id'], 4, '0', STR_PAD_LEFT) . ($isNew ? ' applied' : ' · ' . ucfirst($status)),
            'desc' => trim((string)($r['full_name'] ?? 'Customer')) . ' · ₱' . number_format((float)($r['total_amount'] ?? 0), 2) . ' · Status: ' . ucfirst($status),
            'meta' => adminFormatTime($r['created_at'] ?? null),
            'unread' => $isNew, 'link' => 'layaway.php?id=' . (int)$r['id'],
            '_ts' => strtotime($r['created_at'] ?? '') ?: 0,
        ];
    }
} catch (Throwable $e) { }

try {
    $rows = $db->query("SELECT id, name, stock FROM products WHERE stock <= 5 ORDER BY stock ASC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $admin_notifications[] = [
            'id' => 'stock-' . $r['id'], 'icon' => 'gem', 'iconClass' => 'gold',
            'title' => 'Low stock: ' . (string)$r['name'],
            'desc' => 'Only ' . (int)$r['stock'] . ' unit(s) remaining.',
            'meta' => date('M j, Y g:i A'),
            'unread' => true, 'link' => 'products.php?id=' . (int)$r['id'],
            '_ts' => time(),
        ];
    }
} catch (Throwable $e) { }

try {
    $rows = $db->query("SELECT id, full_name, created_at FROM users WHERE role = 'user' ORDER BY created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $isRecent = adminIsRecent($r['created_at'] ?? null, 24);
        $admin_notifications[] = [
            'id' => 'customer-' . $r['id'], 'icon' => 'user-plus', 'iconClass' => 'green',
            'title' => 'New user: ' . (string)$r['full_name'],
            'desc' => 'User account registered.' . ($isRecent ? '' : ' (older)'),
            'meta' => adminFormatTime($r['created_at'] ?? null),
            'unread' => $isRecent, 'link' => 'users.php?open=' . (int)$r['id'],
            '_ts' => strtotime($r['created_at'] ?? '') ?: 0,
        ];
    }
} catch (Throwable $e) { }

/* ============================================================
   APPLY GLOBAL SERVER STATE
   ============================================================ */
$readIds    = notifReadIds($db);
$deletedIds = notifDeletedIds($db);
$savedPrefs = adminPrefs($db);
$currentAdmin = getCurrentAdmin($db, (int)$_SESSION['user_id']);

$admin_notifications = array_values(array_filter(
    $admin_notifications,
    fn($n) => !isset($deletedIds[(string)$n['id']])
));
foreach ($admin_notifications as &$n) {
    if (isset($readIds[(string)$n['id']])) $n['unread'] = false;
}
unset($n);

usort($admin_notifications, function ($a, $b) {
    return ($b['_ts'] ?? 0) <=> ($a['_ts'] ?? 0);
});
foreach ($admin_notifications as &$n) { unset($n['_ts']); }
unset($n);

$savedPrefs = array_merge([
    'setTheme'          => 'cream',
    'setAccent'         => '#5b5fd4',
    'setSidebar'        => 'dark',
    'toggleCompact'     => '0',
    'toggleAnimations'  => '1',
    'toggleNewOrders'   => '1',
    'toggleLowStock'    => '1',
    'toggleNewCustomer' => '1',
    'toggleOrderStatus' => '0',
    'toggleWeekly'      => '0',
], $savedPrefs);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Layaway Management | R-Jewel&Gold</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400;1,700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --bg: #f2ede4; --bg-soft: #faf7f2; --card: #ffffff; --card-hover: #faf8f4;
            --border: #e5ddd0; --border-light: #ede7db; --text: #5a5245; --text-bright: #2b2620;
            --text-muted: #8a8172; --text-dim: #b3a998; --primary: #5b5fd4;
            --primary-soft: rgba(91, 95, 212, 0.08); --gold: #b8941f; --gold-light: #d4af37;
            --gold-bright: #f0d060; --gold-ref: #c9a227; --gold-soft: rgba(184, 148, 31, 0.1);
            --success: #0d9668; --warning: #d97706; --danger: #dc2626; --info: #2563eb;
            --nav-bg: #1e2533; --nav-text: #8b92a5; --nav-text-bright: #ffffff;
            --nav-border: #2a3142; --nav-hover: #252d3d; --sidebar-bg: #1a2030;
            --sidebar-hover: #252d3d; --sidebar-active: rgba(91, 95, 212, 0.15);
            --sidebar-text: #8b92a5; --sidebar-text-bright: #ffffff; --sidebar-border: #2a3142;
        }

        * { margin:0; padding:0; box-sizing:border-box; -webkit-tap-highlight-color: transparent; }
        html { scroll-behavior: smooth; }

        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); overflow-x: hidden; min-height: 100vh; font-size: 15px; line-height: 1.5; }

        .sidebar-overlay { display:none; position:fixed; inset:0; background: rgba(30,37,51,0.4); backdrop-filter: blur(3px); z-index:998; opacity:0; transition: opacity .3s ease; }
        .sidebar-overlay.active { display:block; opacity:1; }

        .top-nav { position: fixed; top:0; left:340px; right:0; height:80px; background: var(--nav-bg); border-bottom: 1px solid var(--nav-border); z-index: 997; display:flex; align-items:center; justify-content:space-between; padding: 0 30px; transition: left .3s cubic-bezier(.4,0,.2,1); box-shadow: 0 2px 12px rgba(0,0,0,.15); }
        .nav-left { display:flex; align-items:center; gap:16px; flex:1; min-width:0; }
        .nav-menu-btn { display:none; width:44px; height:44px; border-radius:12px; background: rgba(255,255,255,.06); border:1px solid var(--nav-border); color: var(--nav-text); font-size:1.1rem; align-items:center; justify-content:center; cursor:pointer; transition: all .2s ease; flex-shrink:0; }
        .nav-menu-btn:hover { background: rgba(255,255,255,.1); color: var(--nav-text-bright); }

        .nav-search { position:relative; max-width:420px; width:100%; min-width:0; }
        .nav-search i { position:absolute; left:16px; top:50%; transform:translateY(-50%); color:#6b7280; font-size:.95rem; pointer-events:none; }
        .nav-search input { width:100%; background: rgba(255,255,255,.06); border:1px solid var(--nav-border); border-radius:12px; padding: 11px 16px 11px 44px; color: var(--nav-text-bright); font-size:.9rem; font-family:'Inter', sans-serif; transition: all .2s ease; }
        .nav-search input::placeholder { color:#6b7280; }
        .nav-search input:focus { outline:none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(91,95,212,.2); background: rgba(255,255,255,.08); }

        .nav-right { display:flex; align-items:center; gap:10px; flex-shrink:0; }
        .nav-dropdown-wrapper { position:relative; }

        .nav-icon-btn { position:relative; width:46px; height:46px; border-radius:12px; background: transparent; border:1px solid transparent; color:#8b92a5; font-size:1.1rem; display:flex; align-items:center; justify-content:center; cursor:pointer; transition: all .2s ease; text-decoration:none; }
        .nav-icon-btn:hover, .nav-icon-btn.active { background: var(--nav-hover); border-color: var(--nav-border); color: var(--nav-text-bright); }
        .nav-badge { position:absolute; top:6px; right:6px; min-width:20px; height:20px; padding: 0 5px; background: var(--danger); border-radius:10px; color:#fff; font-size:.65rem; font-weight:700; display:flex; align-items:center; justify-content:center; border:2px solid var(--nav-bg); line-height:1; }
        .nav-badge.hidden { display:none; }

        .nav-dropdown-panel { position:absolute; top:calc(100% + 12px); right:0; width: 400px; max-width: calc(100vw - 24px); background: var(--nav-bg); border:1px solid var(--nav-border); border-radius:16px; box-shadow: 0 20px 40px -10px rgba(0,0,0,.6); opacity:0; visibility:hidden; transform: translateY(-8px); transition: all .22s ease; z-index:1001; overflow:hidden; }
        .nav-dropdown-panel.open { opacity:1; visibility:visible; transform: translateY(0); }

        .panel-header { display:flex; justify-content:space-between; align-items:center; padding: 16px 18px; border-bottom:1px solid var(--nav-border); }
        .panel-header h5 { margin:0; color: var(--nav-text-bright); font-size:.95rem; font-weight:600; }
        .panel-header .mark-all { background:none; border:none; color:#818cf8; font-size:.78rem; font-weight:600; cursor:pointer; padding: 4px 8px; border-radius:6px; transition: background .15s ease; }
        .panel-header .mark-all:hover { background: rgba(129,140,248,.1); }

        .panel-body { max-height:400px; overflow-y:auto; overscroll-behavior:contain; }

        .panel-item { display:flex; gap:14px; padding: 14px 18px; border-bottom:1px solid rgba(42,49,66,.5); cursor:pointer; transition: background .15s ease; text-decoration:none; position:relative; color: inherit; }
        .panel-item:last-child { border-bottom:none; }
        .panel-item:hover { background: var(--nav-hover); }
        .panel-item.unread { background: rgba(91,95,212,.06); }
        .panel-item.unread::before { content:''; position:absolute; left:8px; top:50%; transform: translateY(-50%); width:6px; height:6px; background:#818cf8; border-radius:50%; }
        a.panel-item { cursor: pointer; }

        .item-icon { width:42px; height:42px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1rem; flex-shrink:0; }
        .item-icon.blue    { background: rgba(37,99,235,.15);  color:#60a5fa; }
        .item-icon.green   { background: rgba(13,150,104,.15); color:#34d399; }
        .item-icon.gold    { background: rgba(184,148,31,.15); color:#d4af37; }
        .item-icon.red     { background: rgba(220,38,38,.15);  color:#f87171; }
        .item-icon.purple  { background: rgba(139,92,246,.15); color:#a78bfa; }

        .item-content { flex:1; min-width:0; }
        .item-content p { margin:0; color: var(--nav-text-bright); font-size:.86rem; font-weight:500; line-height:1.4; }
        .item-content .item-meta { margin:5px 0 0; color:#9ca3af; font-size:.74rem; display:flex; align-items:center; gap:6px; font-variant-numeric: tabular-nums; }
        .item-content .item-meta i { font-size:.65rem; color:#6b7280; }

        .panel-footer { padding: 12px 18px; border-top:1px solid var(--nav-border); text-align:center; }
        .panel-footer a { color:#818cf8; font-size:.8rem; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
        .panel-footer a:hover { color:#a5b4fc; }

        .panel-empty { padding: 44px 20px; text-align:center; color:#6b7280; }
        .panel-empty i { font-size:2.2rem; margin-bottom:12px; opacity:.5; }
        .panel-empty p { margin:0; font-size:.88rem; }

        .nav-divider { width:1px; height:32px; background: var(--nav-border); margin:0 8px; }

        .nav-user { display:flex; align-items:center; gap:12px; padding: 6px 12px 6px 6px; border-radius:12px; cursor:pointer; transition: background .2s ease; position:relative; border:1px solid transparent; }
        .nav-user:hover { background: var(--nav-hover); border-color: var(--nav-border); }
        .nav-user-avatar { width:40px; height:40px; background: linear-gradient(135deg, var(--gold), #8a6e14); border-radius:11px; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:600; font-size:1.35rem; flex-shrink:0; line-height:1; }
        .nav-user-info { text-align:left; line-height:1.25; }
        .nav-user-info strong { display:block; color: var(--nav-text-bright); font-size:.86rem; font-weight:600; }
        .nav-user-info small { color:#6b7280; font-size:.7rem; }
        .nav-user-chevron { color:#6b7280; font-size:.75rem; transition: transform .2s ease; }
        .nav-user.open .nav-user-chevron { transform: rotate(180deg); }

        .nav-dropdown { position:absolute; top:calc(100% + 10px); right:0; min-width:220px; background:#252d3d; border:1px solid var(--nav-border); border-radius:12px; padding:8px; box-shadow: 0 15px 35px -10px rgba(0,0,0,.5); opacity:0; visibility:hidden; transform: translateY(-6px); transition: all .2s ease; z-index:1000; }
        .nav-user.open .nav-dropdown { opacity:1; visibility:visible; transform: translateY(0); }
        .nav-dropdown a { display:flex; align-items:center; gap:12px; padding: 10px 14px; border-radius:8px; color:#8b92a5; text-decoration:none; font-size:.86rem; font-weight:500; transition: all .15s ease; }
        .nav-dropdown a:hover { background: rgba(255,255,255,.06); color: var(--nav-text-bright); }
        .nav-dropdown a.danger { color:#f87171; }
        .nav-dropdown a.danger:hover { background: rgba(220,38,38,.12); }
        .nav-dropdown a i { width:20px; text-align:center; font-size:.9rem; }
        .nav-dropdown-divider { height:1px; background: var(--nav-border); margin:6px 8px; }

        .sidebar { background: var(--sidebar-bg); border-right:1px solid var(--sidebar-border); min-height:100vh; position:fixed; top:0; left:0; z-index:999; width:340px; transition: transform .3s cubic-bezier(.4,0,.2,1); display:flex; flex-direction:column; box-shadow: 4px 0 25px rgba(0,0,0,.2); }
        .sidebar .brand { padding: 18px 24px; border-bottom: 1px solid var(--sidebar-border); display: flex; align-items: center; gap: 14px; flex-shrink: 0; height: 96px; background: linear-gradient(180deg, #1f2632 0%, #1a2030 100%); }
        .brand-icon { width: 38px; height: 54px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .brand-icon svg { width: 100%; height: 100%; filter: drop-shadow(0 0 6px rgba(201, 162, 39, 0.35)); }
        .brand-text { min-width: 0; display: flex; flex-direction: column; justify-content: center; }
        .brand-text h4 { font-family: 'Playfair Display', 'Georgia', 'Times New Roman', serif; font-size: 1.5rem; font-weight: 700; margin: 0; line-height: 1.05; letter-spacing: -0.01em; color: #ffffff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .brand-text h4 .amp { font-family: 'Playfair Display', 'Georgia', serif; font-style: italic; font-weight: 400; color: #c9a227; -webkit-text-fill-color: #c9a227; margin: 0 1px; letter-spacing: 0; }
        .brand-text p { font-family: 'Inter', sans-serif; font-size: 0.62rem; color: #8b92a5; margin: 5px 0 0; letter-spacing: 0.24em; text-transform: uppercase; font-weight: 500; white-space: nowrap; }

        .sidebar-nav { padding: 18px 14px; flex:1; overflow-y:auto; overscroll-behavior:contain; }
        .nav-item { margin-bottom:4px; }

        .sidebar .sidebar-nav .nav-link,
        .sidebar .sidebar-nav .nav-link:link,
        .sidebar .sidebar-nav .nav-link:visited {
            color: var(--sidebar-text); background: transparent; text-decoration: none;
            padding: 14px 18px; border-radius: 12px; transition: all .2s ease;
            display: flex; align-items: center; gap: 14px; font-size: .94rem;
            font-weight: 500; white-space: nowrap;
        }
        .sidebar .sidebar-nav .nav-link i { width: 24px; font-size: 1.1rem; text-align: center; flex-shrink: 0; color: inherit; }
        .sidebar .sidebar-nav .nav-link:hover,
        .sidebar .sidebar-nav .nav-link:hover i { background: var(--sidebar-hover); color: var(--sidebar-text-bright); }
        .sidebar .sidebar-nav .nav-link.active,
        .sidebar .sidebar-nav .nav-link.active i { background: var(--sidebar-active); color: #a5b4fc; font-weight: 600; box-shadow: inset 4px 0 0 var(--primary); }

        .main-content { margin-left:340px; padding: 104px 30px 30px; min-height:100vh; transition: margin-left .3s ease; }

        .page-header { background: var(--card); border-radius:16px; padding: 20px 26px; margin-bottom:24px; border:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; box-shadow: 0 1px 3px rgba(90,82,69,.05); gap:16px; flex-wrap:wrap; }
        .page-header h2 { font-size:1.4rem; font-weight:700; margin:0; color: var(--text-bright); letter-spacing:-.02em; }
        .page-header p { margin:4px 0 0; color: var(--text-dim); font-size:.82rem; }
        .page-header-actions { display:flex; align-items:center; gap:10px; flex-shrink:0; }

        .stats-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:20px; margin-bottom:24px; }
        .stat-card { background: var(--card); border-radius:16px; padding:24px; border:1px solid var(--border); transition: all .25s ease; box-shadow: 0 1px 3px rgba(90,82,69,.05); position:relative; overflow:hidden; }
        .stat-card::before { content:''; position:absolute; top:0; left:0; width:4px; height:100%; background: var(--primary); opacity:.7; }
        .stat-card.gold::before    { background: var(--gold); }
        .stat-card.success::before { background: var(--success); }
        .stat-card.warning::before { background: var(--warning); }
        .stat-card.danger::before  { background: var(--danger); }
        .stat-card.info::before    { background: var(--info); }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 10px 25px -8px rgba(90,82,69,.15); border-color: var(--border-light); }
        .stat-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px; gap:10px; }
        .stat-icon { width:52px; height:52px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; background: var(--primary-soft); color: var(--primary); flex-shrink:0; }
        .stat-icon.gold    { background: var(--gold-soft); color: var(--gold); }
        .stat-icon.success { background: rgba(13,150,104,.08); color: var(--success); }
        .stat-icon.warning { background: rgba(217,119,6,.08);  color: var(--warning); }
        .stat-icon.danger  { background: rgba(220,38,38,.08);  color: var(--danger); }
        .stat-icon.info    { background: rgba(37,99,235,.08);  color: var(--info); }
        .stat-change { font-size:.72rem; font-weight:600; padding: 5px 11px; border-radius:20px; background: rgba(13,150,104,.08); color: var(--success); display:inline-flex; align-items:center; gap:4px; white-space:nowrap; }
        .stat-change.down { background: rgba(220,38,38,.08); color: var(--danger); }
        .stat-info h3 { font-size:1.8rem; font-weight:700; margin:0; color: var(--text-bright); letter-spacing:-.03em; }
        .stat-info p { margin:4px 0 0; color: var(--text-muted); font-size:.82rem; font-weight:500; }

        .card-modern { background: var(--card); border-radius:16px; padding:26px; margin-bottom:24px; border:1px solid var(--border); box-shadow: 0 1px 3px rgba(90,82,69,.05); }
        .card-header-modern { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding-bottom:16px; border-bottom:1px solid var(--border); gap:12px; flex-wrap:wrap; }
        .card-header-modern h4 { margin:0; font-weight:700; color: var(--text-bright); font-size:1.05rem; letter-spacing:-.01em; }
        .card-header-modern p { margin:4px 0 0; color: var(--text-dim); font-size:.78rem; }

        .table-modern { margin-bottom:0; color: var(--text); width:100%; }
        .table-modern th { border:none; border-bottom:1px solid var(--border); font-weight:600; color: var(--text-dim); padding: 13px 10px; font-size:.74rem; letter-spacing:.08em; text-transform:uppercase; background: transparent; white-space:nowrap; }
        .table-modern td { padding: 15px 10px; vertical-align:middle; border-bottom:1px solid var(--border); color: var(--text); font-size:.88rem; background: transparent; white-space:nowrap; }
        .table-modern tbody tr { transition: background .15s ease; }
        .table-modern tbody tr:hover { background: var(--bg); }
        .table-modern tbody tr:last-child td { border-bottom:none; }
        .table-modern strong { color: var(--text-bright); font-weight:600; }

        .badge-status { padding: 5px 13px; border-radius:20px; font-size:.72rem; font-weight:600; display:inline-block; white-space:nowrap; }
        .badge-delivered  { background: rgba(13,150,104,.1); color:#0a7a54; }
        .badge-pending    { background: rgba(217,119,6,.1);  color:#b45309; }
        .badge-processing { background: rgba(37,99,235,.1);  color:#1d4ed8; }
        .badge-shipped    { background: rgba(91,95,212,.1);  color:#4f46e5; }
        .badge-cancelled  { background: rgba(220,38,38,.1);  color:#b91c1c; }
        .badge-active     { background: rgba(13,150,104,.1); color:#0a7a54; }
        .badge-overdue    { background: rgba(220,38,38,.1);  color:#b91c1c; }
        .badge-approved   { background: rgba(37,99,235,.1);  color:#1d4ed8; }
        .badge-rejected   { background: rgba(220,38,38,.1);  color:#b91c1c; }
        .badge-completed  { background: rgba(13,150,104,.1); color:#0a7a54; }
        .badge-verified   { background: rgba(13,150,104,.1); color:#0a7a54; }

        .btn-modern { background: var(--primary); border:none; color:#fff; border-radius:10px; font-size:.82rem; font-weight:600; padding: 10px 20px; transition: all .2s ease; text-decoration:none; display:inline-flex; align-items:center; gap:8px; white-space:nowrap; cursor:pointer; }
        .btn-modern:hover { background:#4f46e5; color:#fff; transform: translateY(-1px); box-shadow: 0 6px 18px -3px rgba(91,95,212,.35); }
        .btn-outline-modern { background: transparent; border:1px solid var(--border); color: var(--text-muted); border-radius:8px; font-size:.78rem; font-weight:500; padding: 6px 14px; transition: all .2s ease; text-decoration:none; display:inline-flex; align-items:center; gap:6px; white-space:nowrap; cursor:pointer; }
        .btn-outline-modern:hover { background: var(--primary-soft); border-color: var(--primary); color: var(--primary); }
        .btn-sm-modern { padding: 6px 14px; font-size:.76rem; }

        .btn-success-modern { background: var(--success); border:none; color:#fff; border-radius:8px; font-size:.76rem; font-weight:600; padding: 6px 14px; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }
        .btn-success-modern:hover { background: #0b7d55; }
        .btn-danger-modern { background: var(--danger); border:none; color:#fff; border-radius:8px; font-size:.76rem; font-weight:600; padding: 6px 14px; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }
        .btn-danger-modern:hover { background: #b91c1c; }

        .top-product-item { display:flex; justify-content:space-between; align-items:center; padding: 13px 0; border-bottom:1px solid var(--border); gap:12px; }
        .top-product-item:last-child { border-bottom:none; }

        ::-webkit-scrollbar { width:7px; height:7px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--border-light); border-radius:4px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--text-dim); }
        .panel-body::-webkit-scrollbar-thumb { background: var(--nav-border); }

        .view-all-page { position: fixed; inset: 0; background: var(--bg); z-index: 2000; display: none; overflow-y: auto; animation: fadeIn .25s ease; }
        .view-all-page.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        .view-all-header { position: sticky; top: 0; background: var(--card); border-bottom: 1px solid var(--border); padding: 18px 30px; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; z-index: 10; box-shadow: 0 2px 10px rgba(90,82,69,.06); }
        .view-all-title { display: flex; align-items: center; gap: 16px; }
        .back-btn { width: 44px; height: 44px; border-radius: 12px; background: var(--card); border: 1px solid var(--border); color: var(--text); font-size: 1rem; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all .2s ease; flex-shrink: 0; }
        .back-btn:hover { background: var(--bg-soft); border-color: var(--border-light); color: var(--text-bright); transform: translateX(-2px); }
        .view-all-title h2 { font-size: 1.3rem; font-weight: 700; color: var(--text-bright); margin: 0; letter-spacing: -.02em; }
        .view-all-title p { font-size: .8rem; color: var(--text-dim); margin: 3px 0 0; }

        .view-all-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .view-all-search { position: relative; min-width: 240px; }
        .view-all-search i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text-dim); font-size: .88rem; pointer-events: none; }
        .view-all-search input { width: 100%; background: var(--bg-soft); border: 1px solid var(--border); border-radius: 10px; padding: 9px 14px 9px 38px; color: var(--text); font-size: .86rem; font-family: 'Inter', sans-serif; transition: all .2s ease; }
        .view-all-search input::placeholder { color: var(--text-dim); }
        .view-all-search input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-soft); background: var(--card); }

        .filter-chip { padding: 8px 16px; border-radius: 20px; background: var(--card); border: 1px solid var(--border); color: var(--text-muted); font-size: .78rem; font-weight: 500; cursor: pointer; transition: all .2s ease; white-space: nowrap; }
        .filter-chip:hover { border-color: var(--primary); color: var(--primary); }
        .filter-chip.active { background: var(--primary); border-color: var(--primary); color: #fff; }

        .view-all-body { max-width: 1000px; margin: 0 auto; padding: 28px 30px; }
        .view-all-list { background: var(--card); border-radius: 16px; border: 1px solid var(--border); overflow: hidden; box-shadow: 0 1px 3px rgba(90,82,69,.05); }
        .view-all-item { display: flex; gap: 18px; padding: 20px 26px; border-bottom: 1px solid var(--border); cursor: pointer; transition: background .15s ease; position: relative; text-decoration: none; color: inherit; }
        .view-all-item:last-child { border-bottom: none; }
        .view-all-item:hover { background: var(--bg-soft); }
        .view-all-item.unread { background: rgba(91,95,212,.04); }
        .view-all-item.unread::before { content: ''; position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 7px; height: 7px; background: var(--primary); border-radius: 50%; }
        .view-all-item .item-icon { width: 48px; height: 48px; border-radius: 12px; flex-shrink: 0; }
        .view-all-item .item-content { flex: 1; min-width: 0; }
        .view-all-item .item-content p { color: var(--text-bright); font-size: .94rem; font-weight: 600; margin: 0; line-height: 1.4; }
        .view-all-item .item-content .item-desc { color: var(--text); font-size: .86rem; margin: 5px 0 0; font-weight: 400; }
        .view-all-item .item-content .item-meta { color: var(--text-dim); font-size: .78rem; margin: 7px 0 0; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; font-variant-numeric: tabular-nums; }
        .view-all-item .item-content .item-meta span { display: inline-flex; align-items: center; gap: 5px; }
        .view-all-item .item-content .item-meta i { font-size: .7rem; }
        .view-all-item .item-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
        .icon-action { width: 38px; height: 38px; border-radius: 10px; background: transparent; border: 1px solid var(--border); color: var(--text-muted); font-size: .88rem; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all .2s ease; }
        .icon-action:hover { background: var(--primary-soft); border-color: var(--primary); color: var(--primary); }
        .icon-action.danger:hover { background: rgba(220,38,38,.08); border-color: var(--danger); color: var(--danger); }
        .view-all-empty { padding: 70px 20px; text-align: center; color: var(--text-dim); }
        .view-all-empty i { font-size: 3rem; margin-bottom: 16px; opacity: .4; }
        .view-all-empty p { font-size: .94rem; margin: 0; }

        .settings-layout { max-width: 1100px; margin: 0 auto; padding: 28px 30px; display: grid; grid-template-columns: 260px 1fr; gap: 28px; align-items: start; }
        .settings-sidebar { background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 12px; position: sticky; top: 110px; box-shadow: 0 1px 3px rgba(90,82,69,.05); }
        .settings-nav-item { display: flex; align-items: center; gap: 14px; padding: 13px 16px; border-radius: 10px; color: var(--text-muted); font-size: .88rem; font-weight: 500; cursor: pointer; transition: all .2s ease; text-decoration: none; border: none; background: transparent; width: 100%; text-align: left; }
        .settings-nav-item:hover { background: var(--bg-soft); color: var(--text-bright); }
        .settings-nav-item.active { background: var(--primary-soft); color: var(--primary); font-weight: 600; box-shadow: inset 3px 0 0 var(--primary); }
        .settings-nav-item i { width: 22px; text-align: center; font-size: .95rem; }
        .settings-content { background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 30px; box-shadow: 0 1px 3px rgba(90,82,69,.05); }
        .settings-section { display: none; }
        .settings-section.active { display: block; animation: fadeIn .25s ease; }
        .settings-section h3 { font-size: 1.15rem; font-weight: 700; color: var(--text-bright); margin: 0 0 5px; letter-spacing: -.01em; }
        .settings-section .section-desc { color: var(--text-dim); font-size: .82rem; margin: 0 0 26px; padding-bottom: 18px; border-bottom: 1px solid var(--border); }
        .settings-field { margin-bottom: 20px; }
        .settings-field label { display: block; font-size: .78rem; font-weight: 600; color: var(--text); margin-bottom: 8px; letter-spacing: .04em; text-transform: uppercase; }
        .settings-field input, .settings-field select, .settings-field textarea { width: 100%; background: var(--bg-soft); border: 1px solid var(--border); border-radius: 10px; padding: 12px 16px; color: var(--text); font-size: .9rem; font-family: 'Inter', sans-serif; transition: all .2s ease; }
        .settings-field input:focus, .settings-field select:focus, .settings-field textarea:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-soft); background: var(--card); }
        .settings-field textarea { resize: vertical; min-height: 90px; }
        .settings-field .field-hint { font-size: .74rem; color: var(--text-dim); margin-top: 6px; }
        .settings-row { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .settings-toggle { display: flex; justify-content: space-between; align-items: center; padding: 16px 0; border-bottom: 1px solid var(--border); gap: 18px; }
        .settings-toggle:last-child { border-bottom: none; }
        .toggle-info h5 { margin: 0; font-size: .9rem; font-weight: 600; color: var(--text-bright); }
        .toggle-info p { margin: 4px 0 0; font-size: .78rem; color: var(--text-dim); }
        .toggle-switch { position: relative; width: 50px; height: 28px; flex-shrink: 0; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider { position: absolute; cursor: pointer; inset: 0; background: var(--border); border-radius: 28px; transition: .25s; }
        .toggle-slider::before { content: ''; position: absolute; height: 22px; width: 22px; left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: .25s; box-shadow: 0 2px 4px rgba(0,0,0,.15); }
        .toggle-switch input:checked + .toggle-slider { background: var(--primary); }
        .toggle-switch input:checked + .toggle-slider::before { transform: translateX(22px); }
        .settings-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 28px; padding-top: 20px; border-top: 1px solid var(--border); }
        .btn-save { background: var(--primary); border: none; color: #fff; border-radius: 10px; font-size: .86rem; font-weight: 600; padding: 12px 26px; cursor: pointer; transition: all .2s ease; display: inline-flex; align-items: center; gap: 7px; }
        .btn-save:hover { background: #4f46e5; transform: translateY(-1px); box-shadow: 0 6px 18px -3px rgba(91,95,212,.35); }
        .btn-cancel { background: transparent; border: 1px solid var(--border); color: var(--text-muted); border-radius: 10px; font-size: .86rem; font-weight: 500; padding: 12px 24px; cursor: pointer; transition: all .2s ease; }
        .btn-cancel:hover { background: var(--bg-soft); border-color: var(--border-light); color: var(--text-bright); }

        .save-toast { position: fixed; bottom: 28px; right: 28px; background: var(--success); color: #fff; padding: 14px 24px; border-radius: 12px; font-size: .88rem; font-weight: 600; box-shadow: 0 10px 30px -8px rgba(13,150,104,.5); display: flex; align-items: center; gap: 10px; opacity: 0; visibility: hidden; transform: translateY(20px); transition: all .3s ease; z-index: 3000; }
        .save-toast.show { opacity: 1; visibility: visible; transform: translateY(0); }

        .modal-modern { display: none; position: fixed; inset: 0; background: rgba(30,37,51,0.5); backdrop-filter: blur(4px); z-index: 2500; align-items: center; justify-content: center; padding: 20px; }
        .modal-modern.active { display: flex; }
        .modal-modern-content { background: var(--card); border-radius: 16px; max-width: 520px; width: 100%; padding: 28px; box-shadow: 0 25px 60px -12px rgba(0,0,0,.35); max-height: 90vh; overflow-y: auto; }
        .modal-modern-content h3 { margin: 0 0 6px; font-size: 1.15rem; font-weight: 700; color: var(--text-bright); }
        .modal-modern-content .modal-desc { color: var(--text-dim); font-size: .82rem; margin: 0 0 20px; }

        .payment-progress { height: 6px; background: var(--border-light); border-radius: 4px; overflow: hidden; margin-top: 4px; width: 100px; }
        .payment-progress-bar { height: 100%; background: var(--success); border-radius: 4px; }

        @media (max-width: 1200px) {
            .main-content { padding: 100px 24px 24px; }
            .stats-grid { grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
            .settings-layout { grid-template-columns: 220px 1fr; gap: 22px; }
            .brand-text h4 { font-size: 1.35rem; }
            .brand-icon { width: 34px; height: 48px; }
        }
        @media (max-width: 1024px) {
            .sidebar { width: 300px; }
            .top-nav { left: 300px; }
            .main-content { margin-left: 300px; padding: 96px 22px 22px; }
            .stats-grid { grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
            .nav-search { max-width: 300px; }
            .settings-layout { grid-template-columns: 200px 1fr; gap: 18px; }
            .brand-text h4 { font-size: 1.25rem; }
            .brand-text p { font-size: 0.56rem; letter-spacing: 0.2em; }
            .brand-icon { width: 32px; height: 46px; }
        }
        @media (max-width: 992px) {
            .nav-menu-btn { display: flex; }
            .sidebar { transform: translateX(-100%); box-shadow: 4px 0 30px rgba(30,37,51,.25); width: 320px; }
            .sidebar.active { transform: translateX(0); }
            .top-nav { left: 0; padding: 0 20px; }
            .main-content { margin-left: 0; padding: 96px 22px 22px; }
            .stats-grid { grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); }
            .stat-info h3 { font-size: 1.5rem; }
            .settings-layout { grid-template-columns: 1fr; padding: 18px; }
            .settings-sidebar { position: static; display: flex; gap: 6px; overflow-x: auto; padding: 10px; }
            .settings-nav-item { white-space: nowrap; flex-shrink: 0; }
        }
        @media (max-width: 768px) {
            body { font-size: 14px; }
            .top-nav { height: 70px; padding: 0 16px; }
            .nav-search { display: none; }
            .nav-user-info, .nav-user-chevron { display: none; }
            .nav-user { padding: 5px; }
            .nav-divider { display: none; }
            .nav-dropdown-panel { width: 340px; right: -70px; }
            .main-content { padding: 88px 18px 18px; }
            .page-header { flex-direction: column; align-items: flex-start; padding: 18px; }
            .page-header h2 { font-size: 1.2rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 14px; }
            .stat-card { padding: 18px; }
            .stat-icon { width: 46px; height: 46px; font-size: 1.15rem; }
            .stat-info h3 { font-size: 1.3rem; }
            .stat-info p { font-size: .76rem; }
            .stat-change { font-size: .66rem; padding: 4px 9px; }
            .card-modern { padding: 20px; border-radius: 14px; }
            .card-header-modern { flex-direction: column; align-items: flex-start; gap: 10px; }
            .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 0 -4px; }
            .table-modern th, .table-modern td { padding: 11px 7px; font-size: .8rem; }
            .badge-status { font-size: .68rem; padding: 4px 10px; }
            .btn-outline-modern { padding: 5px 11px; font-size: .74rem; }
            .view-all-header { padding: 16px 18px; }
            .view-all-title h2 { font-size: 1.1rem; }
            .view-all-body { padding: 18px; }
            .view-all-item { padding: 16px 18px; gap: 14px; }
            .view-all-item .item-icon { width: 42px; height: 42px; }
            .view-all-search { min-width: 100%; }
            .settings-content { padding: 24px; }
            .settings-row { grid-template-columns: 1fr; }
            .settings-actions { flex-direction: column-reverse; }
            .btn-save, .btn-cancel { width: 100%; justify-content: center; }
        }
        @media (max-width: 576px) {
            .top-nav { height: 66px; }
            .nav-dropdown-panel { width: 320px; right: -60px; }
            .panel-item { padding: 12px 16px; }
            .main-content { padding: 82px 14px 14px; }
            .page-header { border-radius: 14px; padding: 16px; }
            .page-header h2 { font-size: 1.1rem; }
            .page-header p { font-size: .76rem; }
            .stats-grid { grid-template-columns: 1fr; gap: 12px; }
            .stat-card { padding: 16px; }
            .stat-card:hover { transform: none; }
            .stat-info h3 { font-size: 1.25rem; }
            .card-modern { padding: 18px; border-radius: 13px; margin-bottom: 16px; }
            .card-header-modern h4 { font-size: .95rem; }
            .table-modern th, .table-modern td { padding: 10px 6px; font-size: .74rem; }
            .btn-modern { padding: 8px 15px; font-size: .78rem; }
            .sidebar { width: 290px; }
            .brand-text h4 { font-size: 1.15rem; }
            .brand-text p { font-size: 0.55rem; letter-spacing: 0.18em; }
            .brand-icon { width: 28px; height: 42px; }
            .sidebar .brand { padding: 16px 20px; height: 88px; gap: 12px; }
            .view-all-header { padding: 14px; }
            .view-all-title h2 { font-size: 1rem; }
            .view-all-title p { font-size: .74rem; }
            .view-all-item { padding: 14px 16px; }
            .view-all-item .item-content p { font-size: .88rem; }
            .view-all-item .item-actions { flex-direction: column; gap: 5px; }
            .icon-action { width: 34px; height: 34px; font-size: .78rem; }
        }
        @media (max-width: 380px) {
            .top-nav { height: 64px; padding: 0 12px; }
            .nav-menu-btn { width: 40px; height: 40px; font-size: 1rem; }
            .nav-icon-btn { width: 40px; height: 40px; font-size: 1rem; }
            .nav-user-avatar { width: 36px; height: 36px; font-size: 1.15rem; }
            .nav-dropdown-panel { width: 300px; right: -50px; }
            .main-content { padding: 78px 12px 12px; }
            .page-header h2 { font-size: 1.02rem; }
            .stat-info h3 { font-size: 1.15rem; }
            .stat-info p { font-size: .72rem; }
            .stat-icon { width: 42px; height: 42px; font-size: 1.05rem; }
            .card-modern { padding: 16px; }
            .brand-text h4 { font-size: 1rem; }
            .brand-text p { font-size: 0.5rem; letter-spacing: 0.15em; }
            .brand-icon { width: 24px; height: 38px; }
        }
        @media (min-width: 1400px) {
            .main-content { padding: 104px 40px 34px; max-width: 1600px; }
            .stats-grid { grid-template-columns: repeat(4, 1fr); }
            .stat-info h3 { font-size: 2rem; }
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .stat-card, .card-modern { animation: fadeInUp .4s ease-out; }

        @media (prefers-reduced-motion: reduce) {
            * { animation-duration: .01ms !important; animation-iteration-count: 1 !important; transition-duration: .01ms !important; }
        }
        body.compact-mode .stat-card, body.compact-mode .card, body.compact-mode .panel,
        body.compact-mode .settings-section, body.compact-mode .settings-toggle { padding-top: 10px; padding-bottom: 10px; }
        body.compact-mode .nav-link { padding-top: 7px; padding-bottom: 7px; }
        body.no-animations *, body.no-animations *::before, body.no-animations *::after {
            animation: none !important; transition: none !important; scroll-behavior: auto !important;
        }

        .proof-modal-content { max-width: 720px; padding: 22px; position: relative; }
        .proof-modal-header { display: flex; align-items: flex-start; justify-content: center; position: relative; padding-bottom: 12px; margin-bottom: 14px; border-bottom: 1px solid var(--border); min-height: 44px; }
        .proof-modal-title-wrap { text-align: center; padding: 0 60px; }
        .proof-modal-title-wrap h3 { margin: 0 0 6px; font-size: 1.1rem; font-weight: 700; color: var(--text-bright); }
        .proof-modal-title-wrap .badge-status { font-size: .68rem; padding: 4px 12px; }
        .proof-modal-close { position: absolute; top: 0; right: 0; width: 36px; height: 36px; border-radius: 10px; background: transparent; border: 1px solid var(--border); color: var(--text-muted); font-size: .9rem; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all .2s ease; flex-shrink: 0; }
        .proof-modal-close:hover { background: rgba(220,38,38,.08); border-color: var(--danger); color: var(--danger); }
        .proof-modal-grid { display: grid; grid-template-columns: 240px 1fr; gap: 18px; align-items: start; }
        .proof-meta-box { background: var(--bg-soft); border: 1px solid var(--border); border-radius: 10px; padding: 14px; }
        .proof-meta-row { display: flex; justify-content: space-between; align-items: baseline; padding: 6px 0; font-size: .82rem; border-bottom: 1px dashed var(--border-light); }
        .proof-meta-row:last-child { border-bottom: none; }
        .proof-meta-row .label { color: var(--text-dim); font-size: .68rem; text-transform: uppercase; letter-spacing: .05em; font-weight: 600; }
        .proof-meta-row .value { color: var(--text-bright); font-weight: 600; text-align: right; margin-left: 8px; }
        .proof-meta-row .value.green { color: var(--success); }
        .proof-image-box { background: var(--bg-soft); border: 1px solid var(--border); border-radius: 10px; padding: 8px; text-align: center; min-height: 220px; max-height: 340px; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .proof-image-box img { max-width: 100%; max-height: 320px; border-radius: 6px; display: none; cursor: zoom-in; object-fit: contain; }
        .proof-image-box .empty { color: var(--text-dim); font-size: .82rem; padding: 20px; }
        .proof-image-box .empty i { font-size: 2rem; display: block; margin-bottom: 8px; opacity: .4; }
        .proof-notes { margin-top: 10px; background: var(--bg-soft); border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; font-size: .82rem; color: var(--text-muted); display: none; }
        .proof-notes strong { display: block; font-size: .66rem; text-transform: uppercase; letter-spacing: .06em; color: var(--text-dim); margin-bottom: 3px; }
        .proof-modal-actions { display: flex; justify-content: flex-end; align-items: center; gap: 10px; margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--border); flex-wrap: wrap; }
        @media (max-width: 640px) {
            .proof-modal-grid { grid-template-columns: 1fr; }
            .proof-modal-content { padding: 18px; }
            .proof-image-box { min-height: 180px; max-height: 260px; }
            .proof-image-box img { max-height: 240px; }
            .proof-modal-title-wrap { padding: 0 50px; }
            .proof-modal-title-wrap h3 { font-size: 1rem; }
        }
    </style>
</head>
<body>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <header class="top-nav">
        <div class="nav-left">
            <button class="nav-menu-btn" id="menuToggle" aria-label="Toggle sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <div class="nav-search">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search layaway, customers...">
            </div>
        </div>
        <div class="nav-right">
            <div class="nav-dropdown-wrapper">
                <button class="nav-icon-btn" id="notifBtn" title="Notifications" aria-label="Notifications">
                    <i class="fas fa-bell"></i>
                    <span class="nav-badge" id="notifBadge">0</span>
                </button>
                <div class="nav-dropdown-panel" id="notifPanel">
                    <div class="panel-header">
                        <h5>Notifications</h5>
                        <button class="mark-all" id="markAllNotif">Mark all as read</button>
                    </div>
                    <div class="panel-body" id="notifList"></div>
                    <div class="panel-footer">
                        <a href="#" id="viewAllNotif"><i class="fas fa-eye"></i> View all notifications</a>
                    </div>
                </div>
            </div>
            <div class="nav-divider"></div>
            <div class="nav-user" id="navUser">
                <div class="nav-user-avatar">👤</div>
                <div class="nav-user-info">
                    <strong id="headerAdminName"><?php echo htmlspecialchars($currentAdmin['full_name']); ?></strong>
                    <small>Administrator</small>
                </div>
                <i class="fas fa-chevron-down nav-user-chevron"></i>
                <div class="nav-dropdown">
                    <a href="#" id="openSettings"><i class="fas fa-cog"></i> Settings</a>
                    <div class="nav-dropdown-divider"></div>
                    <a href="logout.php" class="danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
        </div>
    </header>

    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <div class="brand-icon">
                <svg viewBox="0 0 40 56" xmlns="http://www.w3.org/2000/svg" fill="none">
                    <path d="M4 18 L20 4 L36 18 L20 52 Z" stroke="#c9a227" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round" fill="none"/>
                    <line x1="4" y1="18" x2="36" y2="18" stroke="#c9a227" stroke-width="1.2" stroke-linecap="round"/>
                    <line x1="4" y1="18" x2="14" y2="30" stroke="#c9a227" stroke-width="0.9" opacity="0.65"/>
                    <line x1="36" y1="18" x2="26" y2="30" stroke="#c9a227" stroke-width="0.9" opacity="0.65"/>
                </svg>
            </div>
            <div class="brand-text">
                <h4>R-Jewel<span class="amp">&amp;Gold</span></h4>
                <p>Admin Panel</p>
            </div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-item"><a href="index.php" class="nav-link"><i class="fas fa-chart-line"></i><span>Dashboard</span></a></div>
            <div class="nav-item"><a href="layaway.php" class="nav-link active"><i class="fas fa-hand-holding-heart"></i><span>Lay-Away</span></a></div>
            <div class="nav-item"><a href="products.php" class="nav-link"><i class="fas fa-gem"></i><span>Products</span></a></div>
            <div class="nav-item"><a href="categories.php" class="nav-link"><i class="fas fa-tags"></i><span>Categories</span></a></div>
            <div class="nav-item"><a href="orders.php" class="nav-link"><i class="fas fa-shopping-cart"></i><span>Orders</span></a></div>
            <div class="nav-item"><a href="users.php" class="nav-link"><i class="fas fa-users"></i><span>Customers</span></a></div>
            <div class="nav-item"><a href="logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></div>
        </nav>
    </aside>

    <main class="main-content">
        <div class="page-header">
            <div>
                <h2>Layaway Management</h2>
                <p>Manage layaway orders, payments, and settings</p>
            </div>
            <div class="page-header-actions">
                <button class="btn-outline-modern btn-sm-modern" id="openLayawaySettingsBtn"><i class="fas fa-cog"></i> Settings</button>
                <button class="btn-modern btn-sm-modern" id="openNewLayawayBtn"><i class="fas fa-plus"></i> New Layaway</button>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card gold">
                <div class="stat-header">
                    <div class="stat-icon gold"><i class="fas fa-file-invoice"></i></div>
                    <span class="stat-change"><i class="fas fa-arrow-up"></i> Active</span>
                </div>
                <div class="stat-info"><h3><?php echo $stats['active']; ?></h3><p>Active Layaways</p></div>
            </div>
            <div class="stat-card success">
                <div class="stat-header">
                    <div class="stat-icon success"><i class="fas fa-peso-sign"></i></div>
                    <span class="stat-change"><i class="fas fa-arrow-up"></i> Collected</span>
                </div>
                <div class="stat-info"><h3>₱<?php echo number_format($stats['total_down'], 0); ?></h3><p>Total Down Payments</p></div>
            </div>
            <div class="stat-card warning">
                <div class="stat-header">
                    <div class="stat-icon warning"><i class="fas fa-clock"></i></div>
                    <span class="stat-change down"><i class="fas fa-exclamation-triangle"></i> <?php echo $stats['overdue']; ?> overdue</span>
                </div>
                <div class="stat-info"><h3>₱<?php echo number_format($stats['outstanding'], 0); ?></h3><p>Outstanding Balance</p></div>
            </div>
            <div class="stat-card info">
                <div class="stat-header">
                    <div class="stat-icon info"><i class="fas fa-hourglass-half"></i></div>
                    <span class="stat-change"><i class="fas fa-arrow-up"></i> This week</span>
                </div>
                <div class="stat-info"><h3><?php echo $stats['due_week']; ?></h3><p>Due This Week</p></div>
            </div>
        </div>

        <div class="card-modern" style="padding:18px 24px;">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="layaway-filters d-flex flex-wrap gap-2">
                    <button class="filter-chip active" data-filter="all">All (<?php echo count($plans); ?>)</button>
                    <button class="filter-chip" data-filter="pending">Pending (<?php echo $stats['pending']; ?>)</button>
                    <button class="filter-chip" data-filter="active">Active (<?php echo $stats['active']; ?>)</button>
                    <button class="filter-chip" data-filter="overdue">Overdue (<?php echo $stats['overdue']; ?>)</button>
                    <button class="filter-chip" data-filter="completed">Completed (<?php echo $stats['completed']; ?>)</button>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <button class="btn-outline-modern" id="sendRemindersBtn"><i class="fas fa-bell"></i> Send Reminders</button>
                    <button class="btn-outline-modern" id="markOverdueBtn"><i class="fas fa-exclamation-triangle"></i> Mark Overdue</button>
                    <button class="btn-outline-modern" id="exportReportBtn"><i class="fas fa-download"></i> Export</button>
                </div>
            </div>
        </div>

        <div class="card-modern">
            <div class="card-header-modern">
                <div><h4>Layaway Orders</h4><p>Manage applications, payments, and balances</p></div>
                <div class="view-all-search" style="min-width:220px; position:relative;">
                    <i class="fas fa-search" style="position:absolute; left:14px; top:50%; transform:translateY(-50%); color:var(--text-dim);"></i>
                    <input type="text" id="layawaySearch" placeholder="Search customer or ID..." style="width:100%; background:var(--bg-soft); border:1px solid var(--border); border-radius:10px; padding:9px 14px 9px 38px; font-size:.86rem; color:var(--text);">
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-modern" id="layawayTable">
                    <thead>
                        <tr><th>ID</th><th>Customer</th><th>Product</th><th>Total</th><th>Down</th><th>Paid</th><th>Balance</th><th>Due Date</th><th>Status</th><th>Actions</th></tr>
                    </thead>
                    <tbody id="layawayTableBody">
                        <?php if (empty($plans)): ?>
                            <tr><td colspan="10" style="text-align:center; padding:40px; color:var(--text-dim);">No layaway plans found. Click "New Layaway" to create one.</td></tr>
                        <?php else: ?>
                            <?php foreach ($plans as $plan):
                                $paid = (float)$plan['total_amount'] - (float)$plan['balance'];
                                $progress = $plan['total_amount'] > 0 ? min(100, ($paid / $plan['total_amount']) * 100) : 0;
                                $statusLabel = ucfirst($plan['status']);
                                $badgeClass = 'badge-' . $plan['status'];
                            ?>
                            <tr data-id="<?php echo $plan['id']; ?>" data-status="<?php echo $plan['status']; ?>">
                                <td><strong>#LY-<?php echo str_pad($plan['id'], 4, '0', STR_PAD_LEFT); ?></strong></td>
                                <td><?php echo htmlspecialchars($plan['customer_name'] ?? 'Unknown'); ?></td>
                                <td><?php echo htmlspecialchars($plan['product_name'] ?? 'Unknown'); ?></td>
                                <td>₱<?php echo number_format($plan['total_amount'], 2); ?></td>
                                <td>₱<?php echo number_format($plan['down_payment'], 2); ?></td>
                                <td>
                                    ₱<?php echo number_format($paid, 2); ?>
                                    <div class="payment-progress"><div class="payment-progress-bar" style="width:<?php echo $progress; ?>%"></div></div>
                                </td>
                                <td>₱<?php echo number_format($plan['balance'], 2); ?></td>
                                <td><?php echo $plan['due_date'] ? date('M j, Y', strtotime($plan['due_date'])) : '—'; ?></td>
                                <td><span class="badge-status <?php echo $badgeClass; ?>"><?php echo $statusLabel; ?></span></td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <?php if ($plan['status'] === 'pending'): ?>
                                            <button class="btn-success-modern btn-sm-modern" onclick="approvePlan(<?php echo $plan['id']; ?>)"><i class="fas fa-check"></i></button>
                                            <button class="btn-danger-modern btn-sm-modern" onclick="rejectPlan(<?php echo $plan['id']; ?>)"><i class="fas fa-times"></i></button>
                                        <?php elseif (in_array($plan['status'], ['active','overdue'])): ?>
                                            <button class="btn-outline-modern btn-sm-modern" onclick="sendReminder(<?php echo $plan['id']; ?>)" title="Send reminder"><i class="fas fa-bell"></i></button>
                                            <button class="btn-outline-modern btn-sm-modern" onclick="viewPlan(<?php echo $plan['id']; ?>)" title="View details"><i class="fas fa-eye"></i></button>
                                        <?php else: ?>
                                            <button class="btn-outline-modern btn-sm-modern" onclick="viewPlan(<?php echo $plan['id']; ?>)" title="View"><i class="fas fa-eye"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card-modern">
                    <div class="card-header-modern"><div><h4>Pending Payment Proofs</h4><p>Verify uploaded receipts</p></div></div>
                    <div id="proofList">
                        <?php if (empty($proofs)): ?>
                            <div style="padding:20px; text-align:center; color:var(--text-dim);">No pending proofs.</div>
                        <?php else: ?>
                            <?php foreach ($proofs as $proof): ?>
                                <div class="top-product-item">
                                    <div>
                                        <strong><?php echo htmlspecialchars($proof['customer_name'] ?? 'Unknown'); ?></strong>
                                        <span class="text-muted">· #LY-<?php echo str_pad($proof['plan_ref'], 4, '0', STR_PAD_LEFT); ?> · ₱<?php echo number_format($proof['amount'], 2); ?></span>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button class="btn-outline-modern btn-sm-modern"
                                                onclick='openProofModal(<?php echo json_encode([
                                                    "id" => $proof["id"],
                                                    "customer" => $proof["customer_name"] ?? "Unknown",
                                                    "plan" => "#LY-" . str_pad($proof["plan_ref"], 4, "0", STR_PAD_LEFT),
                                                    "amount" => number_format((float)$proof["amount"], 2),
                                                    "method" => $proof["payment_method"] ?? "—",
                                                    "reference" => $proof["reference_no"] ?? "—",
                                                    "paid_at" => date("M j, Y g:i A", strtotime($proof["paid_at"])),
                                                    "notes" => $proof["notes"] ?? "",
                                                    "image" => $proof["proof_image"] ?? "",
                                                    "status" => $proof["status"] ?? "pending"
                                                ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>)'
                                                title="View proof">
                                            <i class="fas fa-image"></i> View
                                        </button>
                                        <button class="btn-success-modern btn-sm-modern" onclick="verifyPayment(<?php echo $proof['id']; ?>)"><i class="fas fa-check"></i></button>
                                        <button class="btn-danger-modern btn-sm-modern" onclick="rejectPayment(<?php echo $proof['id']; ?>)"><i class="fas fa-times"></i></button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card-modern">
                    <div class="card-header-modern"><div><h4>Upcoming Payments</h4><p>Next 7 days</p></div></div>
                    <?php if (empty($upcoming)): ?>
                        <div style="padding:20px; text-align:center; color:var(--text-dim);">No upcoming payments.</div>
                    <?php else: ?>
                        <?php foreach ($upcoming as $up): ?>
                            <div class="top-product-item">
                                <div><strong>#LY-<?php echo str_pad($up['id'], 4, '0', STR_PAD_LEFT); ?></strong> · <?php echo htmlspecialchars($up['customer_name'] ?? 'Unknown'); ?></div>
                                <div><span class="badge-status badge-pending">₱<?php echo number_format($up['balance'], 2); ?> due <?php echo date('M j', strtotime($up['due_date'])); ?></span></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card-modern">
            <div class="card-header-modern"><div><h4>Layaway Transaction History</h4><p>Recent payments and adjustments</p></div></div>
            <div class="table-responsive">
                <table class="table table-modern">
                    <thead><tr><th>Date</th><th>Layaway ID</th><th>Customer</th><th>Amount</th><th>Status</th><th>Proof</th></tr></thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr><td colspan="6" style="text-align:center; padding:20px; color:var(--text-dim);">No transactions yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $tx): ?>
                                <tr>
                                    <td><?php echo date('M j, Y', strtotime($tx['paid_at'])); ?></td>
                                    <td>#LY-<?php echo str_pad($tx['plan_ref'], 4, '0', STR_PAD_LEFT); ?></td>
                                    <td><?php echo htmlspecialchars($tx['customer_name'] ?? 'Unknown'); ?></td>
                                    <td>₱<?php echo number_format($tx['amount'], 2); ?></td>
                                    <td><span class="badge-status badge-<?php echo $tx['status'] === 'verified' ? 'verified' : ($tx['status'] === 'rejected' ? 'rejected' : 'pending'); ?>"><?php echo ucfirst($tx['status']); ?></span></td>
                                    <td>
                                        <?php if (!empty($tx['proof_image'])): ?>
                                            <button class="btn-outline-modern btn-sm-modern"
                                                    onclick='openProofModal(<?php echo json_encode([
                                                        "id" => $tx["id"],
                                                        "customer" => $tx["customer_name"] ?? "Unknown",
                                                        "plan" => "#LY-" . str_pad($tx["plan_ref"], 4, "0", STR_PAD_LEFT),
                                                        "amount" => number_format((float)$tx["amount"], 2),
                                                        "method" => $tx["payment_method"] ?? "—",
                                                        "reference" => $tx["reference_no"] ?? "—",
                                                        "paid_at" => date("M j, Y g:i A", strtotime($tx["paid_at"])),
                                                        "notes" => $tx["notes"] ?? "",
                                                        "image" => $tx["proof_image"] ?? "",
                                                        "status" => $tx["status"] ?? "pending"
                                                    ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>)'
                                                    title="View proof">
                                                <i class="fas fa-image"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size:.78rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <div class="view-all-page" id="viewAllNotifPage">
        <div class="view-all-header">
            <div class="view-all-title">
                <button class="back-btn" data-close="viewAllNotifPage" aria-label="Go back"><i class="fas fa-arrow-left"></i></button>
                <div><h2>All Notifications</h2><p><span id="notifCount">0</span> notifications</p></div>
            </div>
            <div class="view-all-actions">
                <div class="view-all-search"><i class="fas fa-search"></i><input type="text" id="notifSearch" placeholder="Search notifications..."></div>
                <button class="filter-chip active" data-filter="all" data-target="notif">All</button>
                <button class="filter-chip" data-filter="unread" data-target="notif">Unread</button>
                <button class="btn-outline-modern" id="markAllNotifFull"><i class="fas fa-check-double"></i> Mark all read</button>
            </div>
        </div>
        <div class="view-all-body"><div class="view-all-list" id="notifFullList"></div></div>
    </div>

    <div class="view-all-page" id="settingsPage">
        <div class="view-all-header">
            <div class="view-all-title">
                <button class="back-btn" data-close="settingsPage" aria-label="Go back"><i class="fas fa-arrow-left"></i></button>
                <div><h2>Settings</h2><p>All preferences sync across every browser and device</p></div>
            </div>
        </div>

        <div class="settings-layout">
            <aside class="settings-sidebar">
                <button class="settings-nav-item active" data-section="account"><i class="fas fa-user-circle"></i> Account</button>
                <button class="settings-nav-item" data-section="security"><i class="fas fa-shield-alt"></i> Security</button>
                <button class="settings-nav-item" data-section="notifications"><i class="fas fa-bell"></i> Notifications</button>
                <button class="settings-nav-item" data-section="appearance"><i class="fas fa-palette"></i> Appearance</button>
            </aside>

            <div class="settings-content">
                <div class="settings-section active" id="section-account">
                    <h3>Account Settings</h3>
                    <p class="section-desc">Update your admin account details. Changes sync across all devices.</p>
                    <div class="settings-row">
                        <div class="settings-field">
                            <label>Full Name</label>
                            <input type="text" id="setFullName" value="<?php echo htmlspecialchars($currentAdmin['full_name']); ?>">
                        </div>
                        <div class="settings-field">
                            <label>Username</label>
                            <input type="text" id="setUsername" value="<?php echo htmlspecialchars($currentAdmin['username']); ?>">
                        </div>
                    </div>
                    <div class="settings-field">
                        <label>Email Address</label>
                        <input type="email" id="setEmail" value="<?php echo htmlspecialchars($currentAdmin['email'] ?? ''); ?>">
                        <div class="field-hint">Used for system notifications and password recovery.</div>
                    </div>
                    <div class="settings-row">
                        <div class="settings-field">
                            <label>Phone Number</label>
                            <input type="text" id="setPhone" value="<?php echo htmlspecialchars($currentAdmin['phone'] ?? ''); ?>">
                        </div>
                        <div class="settings-field">
                            <label>Role</label>
                            <input type="text" value="Administrator" disabled style="opacity:.6;cursor:not-allowed;">
                        </div>
                    </div>
                    <div class="settings-actions">
                        <button class="btn-cancel" data-reset="account">Cancel</button>
                        <button class="btn-save" data-save="account"><i class="fas fa-check"></i> Save Changes</button>
                    </div>
                </div>

                <div class="settings-section" id="section-security">
                    <h3>Security</h3>
                    <p class="section-desc">Manage your password and account security. Changes sync across all devices.</p>
                    <div class="settings-field">
                        <label>Current Password</label>
                        <input type="password" id="setCurrentPass" placeholder="Enter current password">
                    </div>
                    <div class="settings-row">
                        <div class="settings-field">
                            <label>New Password</label>
                            <input type="password" id="setNewPass" placeholder="Enter new password">
                            <div class="field-hint">Minimum 8 characters with letters and numbers.</div>
                        </div>
                        <div class="settings-field">
                            <label>Confirm New Password</label>
                            <input type="password" id="setConfirmPass" placeholder="Re-enter new password">
                        </div>
                    </div>
                    <div class="settings-toggle">
                        <div class="toggle-info"><h5>Two-Factor Authentication</h5><p>Add an extra layer of security to your account.</p></div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="toggle2FA" <?php echo !empty($currentAdmin['two_factor_enabled']) ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="settings-toggle">
                        <div class="toggle-info"><h5>Login Alerts</h5><p>Get notified when a new device signs in.</p></div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="toggleLoginAlerts" <?php echo !empty($currentAdmin['login_alerts']) ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="settings-actions">
                        <button class="btn-cancel" data-reset="security">Cancel</button>
                        <button class="btn-save" data-save="security"><i class="fas fa-check"></i> Update Security</button>
                    </div>
                </div>

                <div class="settings-section" id="section-notifications">
                    <h3>Notification Preferences</h3>
                    <p class="section-desc">Choose what updates you want to receive. Changes sync across all devices.</p>
                    <div class="settings-toggle">
                        <div class="toggle-info"><h5>New Orders</h5><p>Notify me when a customer places a new order.</p></div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="toggleNewOrders" <?php echo ($savedPrefs['toggleNewOrders'] === '1') ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="settings-toggle">
                        <div class="toggle-info"><h5>Low Stock Alerts</h5><p>Notify me when a product is running low.</p></div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="toggleLowStock" <?php echo ($savedPrefs['toggleLowStock'] === '1') ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="settings-toggle">
                        <div class="toggle-info"><h5>New Customer Registrations</h5><p>Notify me when a new customer signs up.</p></div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="toggleNewCustomer" <?php echo ($savedPrefs['toggleNewCustomer'] === '1') ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="settings-toggle">
                        <div class="toggle-info"><h5>Order Status Updates</h5><p>Notify me when order status changes.</p></div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="toggleOrderStatus" <?php echo ($savedPrefs['toggleOrderStatus'] === '1') ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="settings-toggle">
                        <div class="toggle-info"><h5>Weekly Reports</h5><p>Send me a summary every Monday morning.</p></div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="toggleWeekly" <?php echo ($savedPrefs['toggleWeekly'] === '1') ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="settings-actions">
                        <button class="btn-cancel" data-reset="notifications">Cancel</button>
                        <button class="btn-save" data-save="notifications"><i class="fas fa-check"></i> Save Preferences</button>
                    </div>
                </div>

                <div class="settings-section" id="section-appearance">
                    <h3>Appearance</h3>
                    <p class="section-desc">Customize how the dashboard looks. Changes sync across all devices.</p>
                    <div class="settings-field">
                        <label>Theme</label>
                        <select id="setTheme">
                            <option value="cream"  <?php echo ($savedPrefs['setTheme'] === 'cream') ? 'selected' : ''; ?>>Cream (Default)</option>
                            <option value="light"  <?php echo ($savedPrefs['setTheme'] === 'light') ? 'selected' : ''; ?>>Light</option>
                            <option value="dark"   <?php echo ($savedPrefs['setTheme'] === 'dark') ? 'selected' : ''; ?>>Dark</option>
                            <option value="auto"   <?php echo ($savedPrefs['setTheme'] === 'auto') ? 'selected' : ''; ?>>Auto (System)</option>
                        </select>
                    </div>
                    <div class="settings-field">
                        <label>Accent Color</label>
                        <select id="setAccent">
                            <option value="#5b5fd4" <?php echo ($savedPrefs['setAccent'] === '#5b5fd4') ? 'selected' : ''; ?>>Indigo (Default)</option>
                            <option value="#b8941f" <?php echo ($savedPrefs['setAccent'] === '#b8941f') ? 'selected' : ''; ?>>Gold</option>
                            <option value="#0d9668" <?php echo ($savedPrefs['setAccent'] === '#0d9668') ? 'selected' : ''; ?>>Emerald</option>
                            <option value="#dc2626" <?php echo ($savedPrefs['setAccent'] === '#dc2626') ? 'selected' : ''; ?>>Ruby</option>
                            <option value="#2563eb" <?php echo ($savedPrefs['setAccent'] === '#2563eb') ? 'selected' : ''; ?>>Sapphire</option>
                        </select>
                    </div>
                    <div class="settings-field">
                        <label>Sidebar Style</label>
                        <select id="setSidebar">
                            <option value="dark"     <?php echo ($savedPrefs['setSidebar'] === 'dark') ? 'selected' : ''; ?>>Dark (Default)</option>
                            <option value="midnight" <?php echo ($savedPrefs['setSidebar'] === 'midnight') ? 'selected' : ''; ?>>Midnight Blue</option>
                            <option value="charcoal" <?php echo ($savedPrefs['setSidebar'] === 'charcoal') ? 'selected' : ''; ?>>Charcoal</option>
                        </select>
                    </div>
                    <div class="settings-toggle">
                        <div class="toggle-info"><h5>Compact Mode</h5><p>Reduce padding to fit more content on screen.</p></div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="toggleCompact" <?php echo ($savedPrefs['toggleCompact'] === '1') ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="settings-toggle">
                        <div class="toggle-info"><h5>Show Animations</h5><p>Enable smooth transitions and hover effects.</p></div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="toggleAnimations" <?php echo ($savedPrefs['toggleAnimations'] === '1') ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="settings-actions">
                        <button class="btn-cancel" data-reset="appearance">Cancel</button>
                        <button class="btn-save" data-save="appearance"><i class="fas fa-check"></i> Apply Appearance</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="view-all-page" id="layawaySettingsPage">
        <div class="view-all-header">
            <div class="view-all-title">
                <button class="back-btn" data-close="layawaySettingsPage" aria-label="Go back"><i class="fas fa-arrow-left"></i></button>
                <div><h2>Layaway Settings</h2><p>Configure periods, down payment percentages, and reminders</p></div>
            </div>
        </div>
        <div class="settings-layout" style="grid-template-columns:1fr; max-width:700px;">
            <div class="settings-content">
                <div class="settings-section active">
                    <h3>General Layaway Configuration</h3>
                    <p class="section-desc">Set default terms for new layaway plans.</p>
                    <div class="settings-field">
                        <label>Available Layaway Periods (days)</label>
                        <input type="text" id="setPeriods" value="<?php echo htmlspecialchars($settings['periods']); ?>" placeholder="e.g. 30,60,90,120">
                        <div class="field-hint">Comma-separated list of allowed periods in days.</div>
                    </div>
                    <div class="settings-field">
                        <label>Minimum Down Payment (%)</label>
                        <input type="number" id="setMinDown" value="<?php echo htmlspecialchars($settings['min_down_payment_percent']); ?>" min="0" max="100" step="0.01">
                        <div class="field-hint">Required initial payment percentage.</div>
                    </div>
                    <div class="settings-field">
                        <label>Payment Reminder Schedule (days before due)</label>
                        <input type="text" id="setReminderDays" value="<?php echo htmlspecialchars($settings['reminder_days']); ?>" placeholder="e.g. 7,3,1">
                        <div class="field-hint">When to send reminders before a due date.</div>
                    </div>
                    <div class="settings-field">
                        <label>Overdue Grace Period (days)</label>
                        <input type="number" id="setGraceDays" value="<?php echo htmlspecialchars($settings['overdue_grace_days']); ?>" min="0">
                        <div class="field-hint">Days after due date before marking as overdue.</div>
                    </div>
                    <div class="settings-actions">
                        <button class="btn-cancel" data-close="layawaySettingsPage">Cancel</button>
                        <button class="btn-save" id="saveLayawaySettingsBtn"><i class="fas fa-check"></i> Save Settings</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-modern" id="newLayawayModal">
        <div class="modal-modern-content">
            <h3>Create New Layaway</h3>
            <p class="modal-desc">Set up a new layaway plan for a customer.</p>
            <div class="settings-field">
                <label>Customer</label>
                <select id="newUserId">
                    <option value="">Select customer...</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['full_name']); ?> (<?php echo htmlspecialchars($c['email']); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="settings-field">
                <label>Product</label>
                <select id="newProductId">
                    <option value="">Select product...</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?php echo $p['id']; ?>" data-price="<?php echo $p['price']; ?>"><?php echo htmlspecialchars($p['name']); ?> — ₱<?php echo number_format($p['price'], 2); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="settings-row">
                <div class="settings-field"><label>Total Amount (₱)</label><input type="number" id="newTotalAmount" step="0.01" min="0" value="0"></div>
                <div class="settings-field"><label>Down Payment (₱)</label><input type="number" id="newDownPayment" step="0.01" min="0" value="0"></div>
            </div>
            <div class="settings-field">
                <label>Period (days)</label>
                <select id="newPeriodDays">
                    <?php foreach (explode(',', $settings['periods']) as $p): ?>
                        <option value="<?php echo (int)trim($p); ?>"><?php echo (int)trim($p); ?> days</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="settings-actions">
                <button class="btn-cancel" data-close="newLayawayModal">Cancel</button>
                <button class="btn-save" id="createLayawayBtn"><i class="fas fa-plus"></i> Create Layaway</button>
            </div>
        </div>
    </div>

    <div class="modal-modern" id="viewPlanModal"><div class="modal-modern-content" id="viewPlanContent"></div></div>

    <div class="modal-modern" id="proofModal">
        <div class="modal-modern-content proof-modal-content">
            <div class="proof-modal-header">
                <button class="proof-modal-close" id="proofCloseBtn" title="Close" aria-label="Close"><i class="fas fa-times"></i></button>
                <div class="proof-modal-title-wrap">
                    <h3>Payment Proof</h3>
                    <span class="badge-status badge-pending" id="proofStatusBadge">Pending</span>
                </div>
            </div>
            <div class="proof-modal-grid">
                <div>
                    <div class="proof-meta-box">
                        <div class="proof-meta-row"><span class="label">Customer</span><span class="value" id="proofCustomer">—</span></div>
                        <div class="proof-meta-row"><span class="label">Layaway</span><span class="value" id="proofPlan">—</span></div>
                        <div class="proof-meta-row"><span class="label">Amount</span><span class="value green" id="proofAmount">₱0.00</span></div>
                        <div class="proof-meta-row"><span class="label">Method</span><span class="value" id="proofMethod">—</span></div>
                        <div class="proof-meta-row"><span class="label">Reference</span><span class="value" id="proofReference">—</span></div>
                        <div class="proof-meta-row"><span class="label">Paid At</span><span class="value" id="proofPaidAt">—</span></div>
                    </div>
                    <div class="proof-notes" id="proofNotesWrap"><strong>Notes</strong><div id="proofNotes">—</div></div>
                </div>
                <div>
                    <div class="proof-image-box">
                        <img id="proofImage" src="" alt="Payment proof" onclick="window.open(this.src, '_blank')">
                        <div id="proofImageEmpty" class="empty"><i class="fas fa-image"></i> No proof image uploaded.</div>
                    </div>
                </div>
            </div>
            <div class="proof-modal-actions" id="proofActionButtonsWrap" style="display:none;">
                <div class="d-flex gap-2" id="proofActionButtons" style="margin-left:auto;">
                    <button class="btn-danger-modern" id="proofRejectBtn"><i class="fas fa-times"></i> Reject</button>
                    <button class="btn-success-modern" id="proofVerifyBtn"><i class="fas fa-check"></i> Verify Payment</button>
                </div>
            </div>
        </div>
    </div>

    <div class="save-toast" id="saveToast">
        <i class="fas fa-check-circle"></i> <span id="saveToastMsg">Action completed.</span>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const SERVER_PREFS = <?php echo json_encode($savedPrefs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const SERVER_ADMIN = <?php echo json_encode($currentAdmin, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

    (function () {
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        function openSidebar() { sidebar.classList.add('active'); overlay.classList.add('active'); document.body.style.overflow = 'hidden'; }
        function closeSidebar() { sidebar.classList.remove('active'); overlay.classList.remove('active'); document.body.style.overflow = ''; }
        menuToggle && menuToggle.addEventListener('click', function () {
            sidebar.classList.contains('active') ? closeSidebar() : openSidebar();
        });
        overlay && overlay.addEventListener('click', closeSidebar);
        document.querySelectorAll('.sidebar .nav-link').forEach(function (link) {
            link.addEventListener('click', function () { if (window.innerWidth <= 992) closeSidebar(); });
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && sidebar.classList.contains('active')) closeSidebar(); });
        let resizeTimer;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () { if (window.innerWidth > 992) closeSidebar(); }, 150);
        });
    })();

    (function () {
        const navUser = document.getElementById('navUser');
        if (!navUser) return;
        navUser.addEventListener('click', function (e) { e.stopPropagation(); navUser.classList.toggle('open'); });
        document.addEventListener('click', function () { navUser.classList.remove('open'); });
    })();

    function showToast(msg, type) {
        const toast = document.getElementById('saveToast');
        const toastMsg = document.getElementById('saveToastMsg');
        if (!toast || !toastMsg) return;
        toastMsg.textContent = msg || 'Action completed.';
        toast.style.background = type === 'error' ? 'var(--danger)' : 'var(--success)';
        toast.style.boxShadow = type === 'error' ? '0 10px 30px -8px rgba(220,38,38,.5)' : '0 10px 30px -8px rgba(13,150,104,.5)';
        toast.classList.add('show');
        clearTimeout(window.__toastTimer);
        window.__toastTimer = setTimeout(() => toast.classList.remove('show'), 3000);
    }

    async function apiPost(action, data) {
        const fd = new FormData();
        fd.append('action', action);
        Object.keys(data || {}).forEach(k => fd.append(k, data[k]));
        const res = await fetch(window.location.href, { method: 'POST', body: fd });
        return res.json();
    }

    async function syncServerPrefs(action, extraFields) {
        const fd = new FormData();
        fd.append('_notif_sync', '1');
        fd.append('action', action);
        Object.keys(extraFields || {}).forEach(k => fd.append(k, extraFields[k]));
        try {
            const res = await fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' });
            return await res.json();
        } catch (e) {
            console.warn('sync failed:', e);
            return { success: false };
        }
    }

    // ============================================================
    // NOTIFICATIONS — global sync across all devices
    // ============================================================
    (function () {
        const notificationsData = <?php echo json_encode($admin_notifications, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const state = { notif: { data: notificationsData.map(n => ({ ...n, unread: Boolean(n.unread) })), filter: 'all', search: '' } };

        function getNotifPrefs() { return SERVER_PREFS || {}; }

        function getFiltered(key) {
            const { data, filter, search } = state[key];
            let list = data.slice();
            const prefs = getNotifPrefs();

            if (key === 'notif') {
                list = list.filter(item => {
                    const id = String(item.id || '');
                    if (id.startsWith('order-')) {
                        if (String(item.title || '').startsWith('New order')) return prefs.toggleNewOrders !== '0';
                        return prefs.toggleOrderStatus === '1';
                    }
                    if (id.startsWith('stock-'))    return prefs.toggleLowStock !== '0';
                    if (id.startsWith('customer-')) return prefs.toggleNewCustomer !== '0';
                    if (id.startsWith('layaway-'))  return prefs.toggleNewOrders !== '0';
                    return true;
                });
            }
            if (filter === 'unread') list = list.filter(i => i.unread);
            if (search.trim()) {
                const q = search.trim().toLowerCase();
                list = list.filter(i =>
                    String(i.title || '').toLowerCase().includes(q) ||
                    String(i.desc  || '').toLowerCase().includes(q) ||
                    String(i.meta  || '').toLowerCase().includes(q)
                );
            }
            return list;
        }

        function escapeHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        async function syncServer(action, ids) {
            if (!ids || !ids.length) return;
            try {
                const fd = new FormData();
                fd.append('_notif_sync', '1');
                fd.append('action', action);
                ids.forEach(id => fd.append('ids[]', String(id)));
                await fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            } catch (e) { console.warn('[notifications] sync failed:', action, e); }
        }

        function renderDropdown(key) {
            const listEl  = document.getElementById('notifList');
            const badgeEl = document.getElementById('notifBadge');
            const items = getFiltered(key);
            listEl.innerHTML = '';
            if (items.length === 0) {
                listEl.innerHTML = `<div class="panel-empty"><i class="fas fa-inbox"></i><p>No notifications</p></div>`;
            } else {
                items.slice(0, 6).forEach(function (item) {
                    const el = document.createElement(item.link ? 'a' : 'div');
                    el.className = 'panel-item' + (item.unread ? ' unread' : '');
                    if (item.link) el.href = item.link;
                    el.innerHTML = `
                        <div class="item-icon ${item.iconClass}"><i class="fas fa-${item.icon}"></i></div>
                        <div class="item-content">
                            <p>${escapeHtml(item.title)}</p>
                            <div class="item-meta"><i class="fas fa-clock"></i> ${escapeHtml(item.meta)}</div>
                        </div>
                    `;
                    el.addEventListener('click', function (e) {
                        if (item.unread) {
                            item.unread = false;
                            renderDropdown(key); renderFullPage(key);
                            syncServer('read', [String(item.id)]);
                        }
                        if (!item.link) { e.preventDefault(); e.stopPropagation(); }
                    });
                    listEl.appendChild(el);
                });
            }
            const count = state[key].data.filter(i => i.unread).length;
            if (count > 0) { badgeEl.textContent = count > 9 ? '9+' : count; badgeEl.classList.remove('hidden'); }
            else { badgeEl.classList.add('hidden'); }
        }

        function renderFullPage(key) {
            const listEl  = document.getElementById('notifFullList');
            const countEl = document.getElementById('notifCount');
            const items = getFiltered(key);
            listEl.innerHTML = '';
            countEl.textContent = state[key].data.length;
            if (items.length === 0) {
                listEl.innerHTML = `<div class="view-all-empty"><i class="fas fa-inbox"></i><p>No notifications found</p></div>`;
                return;
            }
            items.forEach(function (item) {
                const row = document.createElement(item.link ? 'a' : 'div');
                row.className = 'view-all-item' + (item.unread ? ' unread' : '');
                if (item.link) row.href = item.link;
                row.innerHTML = `
                    <div class="item-icon ${item.iconClass}"><i class="fas fa-${item.icon}"></i></div>
                    <div class="item-content">
                        <p>${escapeHtml(item.title)}</p>
                        <div class="item-desc">${escapeHtml(item.desc || '')}</div>
                        <div class="item-meta">
                            <span><i class="fas fa-calendar-alt"></i> ${escapeHtml(item.meta)}</span>
                            ${item.unread ? '<span style="color:var(--primary);font-weight:600;"><i class="fas fa-circle" style="font-size:.4rem;"></i> Unread</span>' : ''}
                        </div>
                    </div>
                    <div class="item-actions">
                        <button class="icon-action" title="Mark as read" data-action="read"><i class="fas fa-check"></i></button>
                        <button class="icon-action danger" title="Delete" data-action="delete"><i class="fas fa-trash"></i></button>
                    </div>
                `;
                row.querySelector('[data-action="read"]').addEventListener('click', function (e) {
                    e.preventDefault(); e.stopPropagation();
                    if (item.unread) {
                        item.unread = false;
                        renderFullPage(key); renderDropdown(key);
                        syncServer('read', [String(item.id)]);
                    }
                });
                row.querySelector('[data-action="delete"]').addEventListener('click', function (e) {
                    e.preventDefault(); e.stopPropagation();
                    const idx = state[key].data.findIndex(d => d.id === item.id);
                    if (idx > -1) state[key].data.splice(idx, 1);
                    renderFullPage(key); renderDropdown(key);
                    syncServer('delete', [String(item.id)]);
                });
                row.addEventListener('click', function (e) {
                    if (item.unread) {
                        item.unread = false;
                        renderFullPage(key); renderDropdown(key);
                        syncServer('read', [String(item.id)]);
                    }
                    if (!item.link) { e.preventDefault(); e.stopPropagation(); }
                });
                listEl.appendChild(row);
            });
        }

        function closeAllPanels() {
            document.getElementById('notifPanel').classList.remove('open');
            document.getElementById('notifBtn').classList.remove('active');
        }

        document.getElementById('notifBtn').addEventListener('click', function (e) {
            e.stopPropagation();
            const panel = document.getElementById('notifPanel');
            const isOpen = panel.classList.contains('open');
            closeAllPanels();
            if (!isOpen) { panel.classList.add('open'); this.classList.add('active'); }
        });
        document.addEventListener('click', function (e) { if (!e.target.closest('.nav-dropdown-wrapper')) closeAllPanels(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAllPanels(); });

        document.getElementById('markAllNotif').addEventListener('click', function (e) {
            e.stopPropagation();
            const unreadIds = state.notif.data.filter(n => n.unread).map(n => String(n.id));
            state.notif.data.forEach(n => n.unread = false);
            renderDropdown('notif'); renderFullPage('notif');
            syncServer('read', unreadIds);
        });

        document.getElementById('viewAllNotif').addEventListener('click', function (e) {
            e.preventDefault(); e.stopPropagation();
            closeAllPanels();
            state.notif.filter = 'all'; state.notif.search = '';
            document.getElementById('notifSearch').value = '';
            setActiveFilter('notif', 'all');
            renderFullPage('notif');
            document.getElementById('viewAllNotifPage').classList.add('active');
            document.body.style.overflow = 'hidden';
        });

        document.querySelectorAll('[data-close]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.getElementById(this.dataset.close).classList.remove('active');
                document.body.style.overflow = '';
            });
        });

        function setActiveFilter(key, filter) {
            document.querySelectorAll(`.filter-chip[data-target="notif"]`).forEach(function (chip) {
                chip.classList.toggle('active', chip.dataset.filter === filter);
            });
        }

        document.querySelectorAll('.filter-chip').forEach(function (chip) {
            chip.addEventListener('click', function () {
                const key = this.dataset.target;
                if (!key) return;
                state[key].filter = this.dataset.filter;
                setActiveFilter(key, this.dataset.filter);
                renderFullPage(key);
            });
        });

        document.getElementById('notifSearch').addEventListener('input', function () {
            state.notif.search = this.value;
            renderFullPage('notif');
        });

        document.getElementById('markAllNotifFull').addEventListener('click', function () {
            const unreadIds = state.notif.data.filter(n => n.unread).map(n => String(n.id));
            state.notif.data.forEach(n => n.unread = false);
            renderFullPage('notif'); renderDropdown('notif');
            syncServer('read', unreadIds);
        });

        window.addEventListener('rjewel:settings-changed', function () {
            renderDropdown('notif');
            renderFullPage('notif');
        });

        renderDropdown('notif');
        renderFullPage('notif');
    })();

    // ============================================================
    // SETTINGS — every preference syncs across all devices
    // ============================================================
    (function () {
        const settingsPage = document.getElementById('settingsPage');
        const openSettings = document.getElementById('openSettings');
        if (!settingsPage) return;

        const ids = [
            'setFullName','setUsername','setEmail','setPhone',
            'toggle2FA','toggleLoginAlerts',
            'toggleNewOrders','toggleLowStock','toggleNewCustomer',
            'toggleOrderStatus','toggleWeekly',
            'setTheme','setAccent','setSidebar','toggleCompact','toggleAnimations',
        ];
        const appearanceIds = ['setTheme','setAccent','setSidebar','toggleCompact','toggleAnimations'];
        const notifPrefIds   = ['toggleNewOrders','toggleLowStock','toggleNewCustomer','toggleOrderStatus','toggleWeekly'];
        const passwordIds    = ['setCurrentPass','setNewPass','setConfirmPass'];
        const controls = {};
        ids.concat(passwordIds).forEach(id => {
            const el = document.getElementById(id);
            if (el) controls[id] = el;
        });

        function readControls() {
            const data = {};
            Object.keys(controls).forEach(id => {
                if (passwordIds.includes(id)) return;
                const el = controls[id];
                data[id] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
            });
            return data;
        }

        function applyAppearance(data) {
            const root = document.documentElement;
            const theme = data.setTheme || 'cream';
            const accent = data.setAccent || '#5b5fd4';
            const sidebar = data.setSidebar || 'dark';
            const compact = data.toggleCompact === '1' || data.toggleCompact === true;
            const animsOn = data.toggleAnimations !== '0' && data.toggleAnimations !== false;

            const effectiveTheme = theme === 'auto'
                ? (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                : theme;

            root.style.setProperty('--primary', accent);
            root.style.setProperty('--primary-soft', accent + '18');
            root.style.setProperty('--sidebar-active', accent + '26');

            if (effectiveTheme === 'dark') {
                root.style.setProperty('--bg', '#151821'); root.style.setProperty('--bg-soft', '#1c202b');
                root.style.setProperty('--card', '#222735'); root.style.setProperty('--card-hover', '#292f3e');
                root.style.setProperty('--border', '#343b4d'); root.style.setProperty('--border-light', '#2d3444');
                root.style.setProperty('--text', '#d5d9e2'); root.style.setProperty('--text-bright', '#ffffff');
                root.style.setProperty('--text-muted', '#a0a8ba'); root.style.setProperty('--text-dim', '#777f91');
            } else if (effectiveTheme === 'light') {
                root.style.setProperty('--bg', '#f5f7fb'); root.style.setProperty('--bg-soft', '#ffffff');
                root.style.setProperty('--card', '#ffffff'); root.style.setProperty('--card-hover', '#f8fafc');
                root.style.setProperty('--border', '#e2e8f0'); root.style.setProperty('--border-light', '#edf1f6');
                root.style.setProperty('--text', '#475569'); root.style.setProperty('--text-bright', '#172033');
                root.style.setProperty('--text-muted', '#64748b'); root.style.setProperty('--text-dim', '#94a3b8');
            } else {
                root.style.setProperty('--bg', '#f2ede4'); root.style.setProperty('--bg-soft', '#faf7f2');
                root.style.setProperty('--card', '#ffffff'); root.style.setProperty('--card-hover', '#faf8f4');
                root.style.setProperty('--border', '#e5ddd0'); root.style.setProperty('--border-light', '#ede7db');
                root.style.setProperty('--text', '#5a5245'); root.style.setProperty('--text-bright', '#2b2620');
                root.style.setProperty('--text-muted', '#8a8172'); root.style.setProperty('--text-dim', '#b3a998');
            }

            const sidebarColors = {
                dark: ['#1a2030','#252d3d','#2a3142'],
                midnight: ['#101b35','#1b2a4a','#293958'],
                charcoal: ['#292929','#383838','#454545']
            }[sidebar] || ['#1a2030','#252d3d','#2a3142'];
            root.style.setProperty('--sidebar-bg', sidebarColors[0]);
            root.style.setProperty('--sidebar-hover', sidebarColors[1]);
            root.style.setProperty('--sidebar-border', sidebarColors[2]);
            root.style.setProperty('--sidebar-active', accent + '26');

            document.body.classList.toggle('compact-mode', compact);
            document.body.classList.toggle('no-animations', !animsOn);
        }

        // Apply appearance from server prefs immediately on page load
        applyAppearance(SERVER_PREFS);

        if (window.matchMedia) {
            const scheme = window.matchMedia('(prefers-color-scheme: dark)');
            if (scheme.addEventListener) scheme.addEventListener('change', () => {
                if ((SERVER_PREFS.setTheme || 'cream') === 'auto') {
                    applyAppearance(SERVER_PREFS);
                }
            });
        }

        if (openSettings) openSettings.addEventListener('click', function (e) {
            e.preventDefault(); e.stopPropagation();
            const navUser = document.getElementById('navUser');
            if (navUser) navUser.classList.remove('open');
            settingsPage.classList.add('active');
            document.body.style.overflow = 'hidden';
        });

        const navItems = document.querySelectorAll('.settings-nav-item');
        const sections = document.querySelectorAll('.settings-section');
        navItems.forEach(item => item.addEventListener('click', function () {
            navItems.forEach(n => n.classList.remove('active'));
            sections.forEach(section => section.classList.remove('active'));
            this.classList.add('active');
            const section = document.getElementById('section-' + this.dataset.section);
            if (section) section.classList.add('active');
        }));

        // Appearance auto-save
        appearanceIds.forEach(id => {
            if (controls[id]) controls[id].addEventListener('change', async () => {
                const payload = {};
                appearanceIds.forEach(k => {
                    const el = controls[k];
                    if (el) payload[k] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
                });
                applyAppearance(Object.assign({}, SERVER_PREFS, payload));
                const res = await syncServerPrefs('save_appearance', payload);
                if (res.success) {
                    Object.keys(payload).forEach(k => { SERVER_PREFS[k] = payload[k]; });
                    showToast('Appearance saved — synced across all devices.');
                } else {
                    showToast('Could not save appearance.', 'error');
                }
            });
        });

        // Notification filter prefs auto-save
        notifPrefIds.forEach(id => {
            if (controls[id]) controls[id].addEventListener('change', async () => {
                const payload = {};
                notifPrefIds.forEach(k => {
                    const el = controls[k];
                    if (el) payload[k] = el.checked ? '1' : '0';
                });
                const res = await syncServerPrefs('save_prefs', payload);
                if (res.success) {
                    Object.keys(payload).forEach(k => { SERVER_PREFS[k] = payload[k]; });
                    window.dispatchEvent(new Event('rjewel:settings-changed'));
                    showToast('Notification preferences saved — synced across all devices.');
                } else {
                    showToast('Could not save notification preferences.', 'error');
                }
            });
        });

        // Security toggles auto-save
        ['toggle2FA','toggleLoginAlerts'].forEach(id => {
            if (controls[id]) controls[id].addEventListener('change', async () => {
                const payload = {
                    toggle2FA:         controls.toggle2FA?.checked         ? '1' : '0',
                    toggleLoginAlerts: controls.toggleLoginAlerts?.checked ? '1' : '0'
                };
                const res = await syncServerPrefs('save_security', payload);
                if (res.success) showToast('Security setting saved — synced across all devices.');
                else showToast('Could not save security setting.', 'error');
            });
        });

        // Save buttons
        document.querySelectorAll('[data-save]').forEach(button => button.addEventListener('click', async function () {
            const section = this.dataset.save;

            if (section === 'account') {
                const fullName = (controls.setFullName?.value || '').trim();
                const username = (controls.setUsername?.value || '').trim();
                const email    = (controls.setEmail?.value    || '').trim();
                const phone    = (controls.setPhone?.value    || '').trim();

                if (!fullName) return showToast('Full name is required.', 'error');
                if (!username) return showToast('Username is required.', 'error');
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return showToast('Enter a valid email address.', 'error');

                const res = await syncServerPrefs('save_account', {
                    setFullName: fullName, setUsername: username, setEmail: email, setPhone: phone
                });
                if (!res.success) return showToast(res.error || 'Could not save account.', 'error');

                SERVER_ADMIN.full_name = fullName;
                SERVER_ADMIN.username  = username;
                SERVER_ADMIN.email     = email;
                SERVER_ADMIN.phone     = phone;
                const headerName = document.getElementById('headerAdminName');
                if (headerName) headerName.textContent = fullName;
                showToast('Account updated — synced across all devices.');
                return;
            }

            if (section === 'security') {
                const current = controls.setCurrentPass?.value || '';
                const next    = controls.setNewPass?.value     || '';
                const confirm = controls.setConfirmPass?.value || '';

                if (current || next || confirm) {
                    if (!current) return showToast('Enter your current password first.', 'error');
                    if (next.length < 8 || !/[A-Za-z]/.test(next) || !/\d/.test(next))
                        return showToast('New password must be 8+ characters with letters and numbers.', 'error');
                    if (next !== confirm) return showToast('New password and confirmation do not match.', 'error');

                    const res = await syncServerPrefs('save_password', {
                        setCurrentPass: current, setNewPass: next, setConfirmPass: confirm
                    });
                    if (!res.success) return showToast(res.error || 'Could not update password.', 'error');
                    passwordIds.forEach(id => { if (controls[id]) controls[id].value = ''; });
                    showToast('Password updated — synced across all devices.');
                }

                const secRes = await syncServerPrefs('save_security', {
                    toggle2FA:         controls.toggle2FA?.checked         ? '1' : '0',
                    toggleLoginAlerts: controls.toggleLoginAlerts?.checked ? '1' : '0'
                });
                if (secRes.success && !current && !next && !confirm) {
                    showToast('Security preferences saved — synced across all devices.');
                }
                return;
            }

            if (section === 'notifications') {
                const payload = {};
                notifPrefIds.forEach(k => {
                    const el = controls[k];
                    if (el) payload[k] = el.checked ? '1' : '0';
                });
                const res = await syncServerPrefs('save_prefs', payload);
                if (!res.success) return showToast(res.error || 'Could not save notification prefs.', 'error');
                Object.keys(payload).forEach(k => { SERVER_PREFS[k] = payload[k]; });
                window.dispatchEvent(new Event('rjewel:settings-changed'));
                showToast('Notification preferences saved — synced across all devices.');
                return;
            }

            if (section === 'appearance') {
                const payload = {};
                appearanceIds.forEach(k => {
                    const el = controls[k];
                    if (el) payload[k] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
                });
                applyAppearance(Object.assign({}, SERVER_PREFS, payload));
                const res = await syncServerPrefs('save_appearance', payload);
                if (!res.success) return showToast(res.error || 'Could not save appearance.', 'error');
                Object.keys(payload).forEach(k => { SERVER_PREFS[k] = payload[k]; });
                showToast('Appearance saved — synced across all devices.');
                return;
            }
        }));

        // Reset buttons
        document.querySelectorAll('[data-reset]').forEach(button => button.addEventListener('click', function () {
            const section = this.dataset.reset;
            const groups = {
                account: ['setFullName','setUsername','setEmail','setPhone'],
                security: ['toggle2FA','toggleLoginAlerts'],
                notifications: notifPrefIds,
                appearance: appearanceIds
            };
            (groups[section] || []).forEach(id => {
                const el = controls[id];
                if (!el) return;
                if (id === 'setFullName') el.value = SERVER_ADMIN.full_name || '';
                else if (id === 'setUsername') el.value = SERVER_ADMIN.username || '';
                else if (id === 'setEmail') el.value = SERVER_ADMIN.email || '';
                else if (id === 'setPhone') el.value = SERVER_ADMIN.phone || '';
                else if (id === 'toggle2FA') el.checked = !!Number(SERVER_ADMIN.two_factor_enabled);
                else if (id === 'toggleLoginAlerts') el.checked = !!Number(SERVER_ADMIN.login_alerts);
                else if (notifPrefIds.includes(id) || appearanceIds.includes(id)) {
                    if (el.type === 'checkbox') el.checked = SERVER_PREFS[id] === '1';
                    else el.value = SERVER_PREFS[id] || el.value;
                }
            });
            passwordIds.forEach(id => { if (controls[id]) controls[id].value = ''; });
            applyAppearance(SERVER_PREFS);
            showToast('Unsaved changes discarded.');
        }));
    })();

    // ================================================================
    // LAYAWAY ACTIONS
    // ================================================================
    async function approvePlan(id) {
        const period = prompt('Enter layaway period in days (e.g. 30, 60, 90):', '60');
        if (!period) return;
        const r = await apiPost('approve_plan', { id, period_days: period });
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) setTimeout(() => location.reload(), 800);
    }
    async function rejectPlan(id) {
        if (!confirm('Reject this layaway application?')) return;
        const r = await apiPost('reject_plan', { id });
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) setTimeout(() => location.reload(), 800);
    }
    async function verifyPayment(paymentId) {
        const r = await apiPost('verify_payment', { payment_id: paymentId });
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) setTimeout(() => location.reload(), 800);
    }
    async function rejectPayment(paymentId) {
        if (!confirm('Reject this payment proof?')) return;
        const r = await apiPost('reject_payment', { payment_id: paymentId });
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) setTimeout(() => location.reload(), 800);
    }
    async function sendReminder(id) {
        const r = await apiPost('send_reminder', { id });
        showToast(r.message, r.success ? 'success' : 'error');
    }

    function viewPlan(id) {
        const row = document.querySelector(`tr[data-id="${id}"]`);
        if (!row) return;
        const cells = row.querySelectorAll('td');
        const content = document.getElementById('viewPlanContent');
        content.innerHTML = `
            <h3>Layaway #LY-${String(id).padStart(4, '0')}</h3>
            <p class="modal-desc">Detailed view of layaway plan.</p>
            <div class="settings-field"><label>Customer</label><div style="padding:10px 0;">${cells[1].textContent}</div></div>
            <div class="settings-field"><label>Product</label><div style="padding:10px 0;">${cells[2].textContent}</div></div>
            <div class="settings-row">
                <div class="settings-field"><label>Total Amount</label><div style="padding:10px 0;">${cells[3].textContent}</div></div>
                <div class="settings-field"><label>Down Payment</label><div style="padding:10px 0;">${cells[4].textContent}</div></div>
            </div>
            <div class="settings-row">
                <div class="settings-field"><label>Paid</label><div style="padding:10px 0;">${cells[5].textContent.trim()}</div></div>
                <div class="settings-field"><label>Balance</label><div style="padding:10px 0;">${cells[6].textContent}</div></div>
            </div>
            <div class="settings-row">
                <div class="settings-field"><label>Due Date</label><div style="padding:10px 0;">${cells[7].textContent}</div></div>
                <div class="settings-field"><label>Status</label><div style="padding:10px 0;">${cells[8].textContent}</div></div>
            </div>
            <div class="settings-actions">
                <button class="btn-cancel" onclick="document.getElementById('viewPlanModal').classList.remove('active')">Close</button>
            </div>
        `;
        document.getElementById('viewPlanModal').classList.add('active');
    }

    window.openProofModal = function (data) {
        document.getElementById('proofCustomer').textContent  = data.customer || 'Unknown';
        document.getElementById('proofPlan').textContent      = data.plan || '—';
        document.getElementById('proofAmount').textContent    = '₱' + (data.amount || '0.00');
        document.getElementById('proofMethod').textContent    = data.method || '—';
        document.getElementById('proofReference').textContent = data.reference || '—';
        document.getElementById('proofPaidAt').textContent    = data.paid_at || '—';

        const notesWrap = document.getElementById('proofNotesWrap');
        if (data.notes && String(data.notes).trim() !== '') {
            document.getElementById('proofNotes').textContent = data.notes;
            notesWrap.style.display = 'block';
        } else {
            notesWrap.style.display = 'none';
        }

        const img = document.getElementById('proofImage');
        const empty = document.getElementById('proofImageEmpty');
        if (data.image && String(data.image).trim() !== '') {
            let src = data.image;
            if (!/^https?:\/\//i.test(src) && !src.startsWith('/')) {
                src = '../' + src.replace(/^\.\.\//, '');
            }
            img.src = src;
            img.style.display = 'block';
            empty.style.display = 'none';
        } else {
            img.style.display = 'none';
            img.removeAttribute('src');
            empty.style.display = 'block';
        }

        const status = (data.status || 'pending').toLowerCase();
        const badge = document.getElementById('proofStatusBadge');
        badge.className = 'badge-status';
        if (status === 'verified') { badge.classList.add('badge-verified'); badge.textContent = 'Verified'; }
        else if (status === 'rejected') { badge.classList.add('badge-rejected'); badge.textContent = 'Rejected'; }
        else { badge.classList.add('badge-pending'); badge.textContent = 'Pending'; }

        const actionsWrap = document.getElementById('proofActionButtonsWrap');
        const actionsInner = document.getElementById('proofActionButtons');

        if (status === 'pending') {
            actionsWrap.style.display = 'flex';
            actionsInner.innerHTML = `
                <button class="btn-danger-modern" id="proofRejectBtn"><i class="fas fa-times"></i> Reject</button>
                <button class="btn-success-modern" id="proofVerifyBtn"><i class="fas fa-check"></i> Verify Payment</button>
            `;
            document.getElementById('proofVerifyBtn').addEventListener('click', async function () {
                document.getElementById('proofModal').classList.remove('active');
                const r = await apiPost('verify_payment', { payment_id: data.id });
                showToast(r.message, r.success ? 'success' : 'error');
                if (r.success) setTimeout(() => location.reload(), 800);
            });
            document.getElementById('proofRejectBtn').addEventListener('click', async function () {
                if (!confirm('Reject this payment proof?')) return;
                document.getElementById('proofModal').classList.remove('active');
                const r = await apiPost('reject_payment', { payment_id: data.id });
                showToast(r.message, r.success ? 'success' : 'error');
                if (r.success) setTimeout(() => location.reload(), 800);
            });
        } else {
            actionsWrap.style.display = 'none';
            actionsInner.innerHTML = '';
        }

        document.getElementById('proofModal').classList.add('active');
    };

    document.getElementById('proofCloseBtn').addEventListener('click', function () {
        document.getElementById('proofModal').classList.remove('active');
    });

    document.getElementById('openLayawaySettingsBtn')?.addEventListener('click', function () {
        document.getElementById('layawaySettingsPage').classList.add('active');
        document.body.style.overflow = 'hidden';
    });

    document.getElementById('saveLayawaySettingsBtn')?.addEventListener('click', async function () {
        const r = await apiPost('save_settings', {
            periods: document.getElementById('setPeriods').value,
            min_down_payment_percent: document.getElementById('setMinDown').value,
            reminder_days: document.getElementById('setReminderDays').value,
            overdue_grace_days: document.getElementById('setGraceDays').value
        });
        showToast(r.message, r.success ? 'success' : 'error');
    });

    document.getElementById('openNewLayawayBtn')?.addEventListener('click', function () {
        document.getElementById('newLayawayModal').classList.add('active');
    });

    document.getElementById('newProductId')?.addEventListener('change', function () {
        const opt = this.options[this.selectedIndex];
        const price = opt?.dataset?.price || 0;
        document.getElementById('newTotalAmount').value = price;
    });

    document.getElementById('createLayawayBtn')?.addEventListener('click', async function () {
        const data = {
            user_id: document.getElementById('newUserId').value,
            product_id: document.getElementById('newProductId').value,
            total_amount: document.getElementById('newTotalAmount').value,
            down_payment: document.getElementById('newDownPayment').value,
            period_days: document.getElementById('newPeriodDays').value
        };
        if (!data.user_id || !data.product_id || !data.total_amount) {
            showToast('Please fill in customer, product, and total amount.', 'error');
            return;
        }
        const r = await apiPost('create_layaway', data);
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) setTimeout(() => location.reload(), 800);
    });

    document.getElementById('sendRemindersBtn')?.addEventListener('click', function () {
        const duePlans = document.querySelectorAll('tr[data-status="active"], tr[data-status="overdue"]');
        let count = 0;
        duePlans.forEach(row => { apiPost('send_reminder', { id: row.dataset.id }); count++; });
        showToast(`Reminders sent to ${count} customer(s).`);
    });

    document.getElementById('markOverdueBtn')?.addEventListener('click', async function () {
        if (!confirm('Mark all past-due active layaways as overdue?')) return;
        const r = await apiPost('mark_overdue', {});
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) setTimeout(() => location.reload(), 800);
    });

    document.getElementById('exportReportBtn')?.addEventListener('click', function () {
        const rows = document.querySelectorAll('#layawayTable tbody tr');
        let csv = 'ID,Customer,Product,Total,Down,Paid,Balance,Due Date,Status\n';
        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            if (cells.length < 10) return;
            const values = [
                cells[0].textContent.trim(), cells[1].textContent.trim(),
                cells[2].textContent.trim(), cells[3].textContent.trim(),
                cells[4].textContent.trim(), cells[5].textContent.trim().split('\n')[0],
                cells[6].textContent.trim(), cells[7].textContent.trim(),
                cells[8].textContent.trim()
            ].map(v => '"' + v.replace(/"/g, '""') + '"');
            csv += values.join(',') + '\n';
        });
        const blob = new Blob([csv], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'layaway-report-' + new Date().toISOString().slice(0,10) + '.csv';
        a.click();
        URL.revokeObjectURL(url);
        showToast('Layaway report exported.');
    });

    document.querySelectorAll('.filter-chip').forEach(chip => {
        if (!chip.dataset.target) {
            chip.addEventListener('click', function () {
                document.querySelectorAll('.layaway-filters .filter-chip').forEach(c => c.classList.remove('active'));
                this.classList.add('active');
                const filter = this.dataset.filter;
                document.querySelectorAll('#layawayTableBody tr').forEach(row => {
                    if (!row.dataset.status) return;
                    row.style.display = (filter === 'all' || row.dataset.status === filter) ? '' : 'none';
                });
            });
        }
    });

    document.getElementById('layawaySearch')?.addEventListener('input', function () {
        const q = this.value.toLowerCase();
        document.querySelectorAll('#layawayTableBody tr').forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(q) ? '' : 'none';
        });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.view-all-page.active, .modal-modern.active').forEach(el => el.classList.remove('active'));
            document.body.style.overflow = '';
        }
    });

    document.querySelectorAll('.modal-modern').forEach(modal => {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    });
    </script>
</body>
</html>