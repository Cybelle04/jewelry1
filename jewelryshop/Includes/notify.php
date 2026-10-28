<?php
// includes/notify.php — Safe notification helper
// - Works with PDO or mysqli
// - Never throws (all errors are swallowed and logged)
// - Never crashes the caller if the `notifications` table is missing
// - Provides:
//     rjewel_notify($db, $userId, $type, $title, $message = '', $link = null) : bool
//     rjewel_notify_admins($db, $type, $title, $message = '', $link = null)   : void

if (!defined('RJEWEL_NOTIFY_LOADED')) {
    define('RJEWEL_NOTIFY_LOADED', true);

    if (!function_exists('rjewel_notify')) {

        /**
         * Insert a single notification for one user.
         *
         * @param PDO|mysqli|null $db
         * @param int             $userId   Recipient user id
         * @param string          $type     'order' | 'payment' | 'layaway' | 'due' | 'system'
         * @param string          $title    Short headline (max 255 chars)
         * @param string          $message  Optional body text
         * @param string|null     $link     Optional relative or absolute link
         * @return bool                     True if the insert succeeded
         */
        function rjewel_notify($db, $userId, $type, $title, $message = '', $link = null) {
            $userId = (int)$userId;
            if ($db === null || $userId <= 0) return false;

            $title = trim((string)$title);
            if ($title === '') return false;
            if (function_exists('mb_substr')) {
                $title = mb_substr($title, 0, 255);
            } else {
                $title = substr($title, 0, 255);
            }

            $type    = trim((string)$type);
            if ($type === '') $type = 'system';
            if (function_exists('mb_substr')) $type = mb_substr($type, 0, 32);
            else                              $type = substr($type, 0, 32);

            $message = $message === null ? '' : (string)$message;
            $link    = $link    === null ? null : (string)$link;
            if ($link !== null && $link === '') $link = null;
            if ($link !== null) {
                if (function_exists('mb_substr')) $link = mb_substr($link, 0, 255);
                else                              $link = substr($link, 0, 255);
            }

            /* ---------------- PDO ---------------- */
            if ($db instanceof PDO) {
                try {
                    $sql = "INSERT INTO notifications
                                (user_id, type, title, message, link, is_read, created_at)
                            VALUES
                                (:uid, :type, :title, :message, :link, 0, NOW())";
                    $stmt = $db->prepare($sql);
                    if (!$stmt) return false;
                    $ok = $stmt->execute([
                        ':uid'     => $userId,
                        ':type'    => $type,
                        ':title'   => $title,
                        ':message' => $message,
                        ':link'    => $link,
                    ]);
                    return (bool)$ok;
                } catch (Throwable $e) {
                    @error_log('[rjewel_notify PDO] ' . $e->getMessage());
                    return false;
                }
            }

            /* ---------------- mysqli ---------------- */
            if ($db instanceof mysqli) {
                try {
                    $sql  = "INSERT INTO notifications
                                (user_id, type, title, message, link, is_read, created_at)
                             VALUES (?, ?, ?, ?, ?, 0, NOW())";
                    $stmt = $db->prepare($sql);
                    if (!$stmt) return false;

                    // mysqli bind_param cannot bind null via 's' — use null-safe wrapper
                    $linkVal = $link; // may be null
                    $stmt->bind_param('issss', $userId, $type, $title, $message, $linkVal);

                    $ok = $stmt->execute();
                    $stmt->close();
                    return (bool)$ok;
                } catch (Throwable $e) {
                    @error_log('[rjewel_notify mysqli] ' . $e->getMessage());
                    return false;
                }
            }

            return false;
        }

        /**
         * Notify every admin user (role = 'admin').
         *
         * @param PDO|mysqli|null $db
         * @param string          $type
         * @param string          $title
         * @param string          $message
         * @param string|null     $link
         * @return void
         */
        function rjewel_notify_admins($db, $type, $title, $message = '', $link = null) {
            if ($db === null) return;

            try {
                /* ---------------- PDO ---------------- */
                if ($db instanceof PDO) {
                    $stmt = $db->query("SELECT id FROM users WHERE role = 'admin'");
                    if ($stmt) {
                        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($ids as $aid) {
                            rjewel_notify($db, (int)$aid, $type, $title, $message, $link);
                        }
                    }
                    return;
                }

                /* ---------------- mysqli ---------------- */
                if ($db instanceof mysqli) {
                    $res = $db->query("SELECT id FROM users WHERE role = 'admin'");
                    if ($res) {
                        while ($row = $res->fetch_assoc()) {
                            rjewel_notify($db, (int)$row['id'], $type, $title, $message, $link);
                        }
                        if (method_exists($res, 'free')) $res->free();
                    }
                    return;
                }
            } catch (Throwable $e) {
                @error_log('[rjewel_notify_admins] ' . $e->getMessage());
            }
        }
    }
}