<?php
// admin/login.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If already logged in, redirect to dashboard
if(isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] == 'admin') {
    header("Location: index.php");
    exit();
}

$error = '';

// ---------- Read appearance from cookie (written by dashboard Settings) ----------
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
// 'auto' resolved client-side; server-side treat as cream for no-JS fallback
$resolved_theme = in_array($theme, ['cream','light','dark'], true) ? $theme : 'cream';

// ---------- Handle login ----------
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $database = new Database();
    $db = $database->getConnection();

    $query = "SELECT * FROM users WHERE username = :username AND role = 'admin'";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':username', $username);
    $stmt->execute();

    if($stmt->rowCount() > 0) {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if(password_verify($password, $user['password'])) {
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['username']   = $user['username'];
            $_SESSION['role']       = $user['role'];
            $_SESSION['full_name']  = $user['full_name'];

            header("Location: index.php");
            exit();
        } else {
            $error = "Invalid password!";
        }
    } else {
        $error = "Admin user not found!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Admin Login | R-Jewel&Gold</title>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <!-- ============================================================
         BASE VARIABLES (cream / default) — same values as dashboard
         ============================================================ -->
    <style>
        :root {
            --bg: #f2ede4;
            --bg-soft: #faf7f2;
            --card: #ffffff;
            --card-hover: #faf8f4;
            --border: #e5ddd0;
            --border-light: #ede7db;
            --text: #5a5245;
            --text-bright: #2b2620;
            --text-muted: #8a8172;
            --text-dim: #b3a998;
            --primary: <?php echo htmlspecialchars($accent); ?>;
            --primary-soft: <?php echo htmlspecialchars($accent); ?>18;
            --gold: #b8941f;
            --gold-soft: rgba(184, 148, 31, 0.1);
            --gold-strong: #8a6e14;
            --success: #0d9668;
            --danger: #dc2626;
            --nav-bg: #1e2533;
            --nav-border: #2a3142;
        }
    </style>

    <!-- ============================================================
         THEME OVERRIDE — matches dashboard palette exactly
         ============================================================ -->
    <style>
        <?php if ($resolved_theme === 'dark'): ?>
        :root {
            --bg: #151821;
            --bg-soft: #1c202b;
            --card: #222735;
            --card-hover: #292f3e;
            --border: #343b4d;
            --border-light: #2d3444;
            --text: #d5d9e2;
            --text-bright: #ffffff;
            --text-muted: #a0a8ba;
            --text-dim: #777f91;
        }
        <?php elseif ($resolved_theme === 'light'): ?>
        :root {
            --bg: #f5f7fb;
            --bg-soft: #ffffff;
            --card: #ffffff;
            --card-hover: #f8fafc;
            --border: #e2e8f0;
            --border-light: #edf1f6;
            --text: #475569;
            --text-bright: #172033;
            --text-muted: #64748b;
            --text-dim: #94a3b8;
        }
        <?php endif; ?>
    </style>

    <?php if ($theme === 'auto'): ?>
    <script>
        // 'auto' — resolve from system preference BEFORE paint
        (function () {
            var isDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            var root = document.documentElement;
            if (isDark) {
                root.style.setProperty('--bg', '#151821');
                root.style.setProperty('--bg-soft', '#1c202b');
                root.style.setProperty('--card', '#222735');
                root.style.setProperty('--card-hover', '#292f3e');
                root.style.setProperty('--border', '#343b4d');
                root.style.setProperty('--border-light', '#2d3444');
                root.style.setProperty('--text', '#d5d9e2');
                root.style.setProperty('--text-bright', '#ffffff');
                root.style.setProperty('--text-muted', '#a0a8ba');
                root.style.setProperty('--text-dim', '#777f91');
            } else {
                root.style.setProperty('--bg', '#f5f7fb');
                root.style.setProperty('--bg-soft', '#ffffff');
                root.style.setProperty('--card', '#ffffff');
                root.style.setProperty('--card-hover', '#f8fafc');
                root.style.setProperty('--border', '#e2e8f0');
                root.style.setProperty('--border-light', '#edf1f6');
                root.style.setProperty('--text', '#475569');
                root.style.setProperty('--text-bright', '#172033');
                root.style.setProperty('--text-muted', '#64748b');
                root.style.setProperty('--text-dim', '#94a3b8');
            }
        })();
    </script>
    <?php endif; ?>

    <!-- ============================================================
         LOGIN PAGE STYLES (unchanged, uses variables above)
         ============================================================ -->
    <style>
        * { margin:0; padding:0; box-sizing:border-box; -webkit-tap-highlight-color: transparent; }

        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: var(--bg);
            background-image:
                radial-gradient(ellipse at 20% 20%, rgba(184, 148, 31, 0.08), transparent 55%),
                radial-gradient(ellipse at 80% 80%, rgba(91, 95, 212, 0.05), transparent 55%);
            position: relative;
            overflow: hidden;
            transition: background-color .3s ease;
        }

        body::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                radial-gradient(1.5px 1.5px at 15% 25%, rgba(184, 148, 31, 0.35), transparent),
                radial-gradient(1px 1px at 45% 60%, rgba(138, 129, 114, 0.25), transparent),
                radial-gradient(1.5px 1.5px at 75% 15%, rgba(184, 148, 31, 0.3), transparent),
                radial-gradient(1px 1px at 85% 75%, rgba(138, 129, 114, 0.2), transparent),
                radial-gradient(1px 1px at 30% 85%, rgba(184, 148, 31, 0.25), transparent);
            pointer-events: none;
        }

        /* Card */
        .login-card {
            width: 100%;
            max-width: 440px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow:
                0 30px 60px -20px rgba(90, 82, 69, 0.18),
                0 8px 20px -8px rgba(90, 82, 69, 0.08),
                0 0 0 1px rgba(255, 255, 255, 0.5) inset;
            padding: 3rem 2.5rem;
            position: relative;
            z-index: 2;
            transition: background-color .3s ease, border-color .3s ease;
        }

        .login-card::before,
        .login-card::after {
            content: '';
            position: absolute;
            width: 28px;
            height: 28px;
            border-color: var(--gold);
            border-style: solid;
            opacity: 0.7;
        }
        .login-card::before {
            top: 12px; left: 12px;
            border-width: 1.5px 0 0 1.5px;
            border-top-left-radius: 16px;
        }
        .login-card::after {
            bottom: 12px; right: 12px;
            border-width: 0 1.5px 1.5px 0;
            border-bottom-right-radius: 16px;
        }

        /* Logo */
        .diamond-icon {
            width: 72px; height: 72px;
            margin: 0 auto 1.25rem;
            display: flex; align-items: center; justify-content: center;
            position: relative;
        }
        .diamond-icon svg {
            width: 100%; height: 100%;
            filter: drop-shadow(0 4px 12px rgba(184, 148, 31, 0.35));
        }

        .login-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 2.2rem; font-weight: 600;
            color: var(--text-bright);
            text-align: center;
            letter-spacing: 0.02em;
            margin-bottom: 0.2rem;
            line-height: 1.1;
        }
        .login-sub {
            font-family: 'Inter', sans-serif;
            font-size: 0.7rem;
            color: var(--text-dim);
            text-align: center;
            letter-spacing: 0.35em;
            text-transform: uppercase;
            margin-bottom: 1.75rem;
            font-weight: 600;
        }

        /* Divider */
        .divider {
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 1.75rem; gap: 12px;
        }
        .divider-line {
            flex: 1; height: 1px;
            background: linear-gradient(90deg, transparent, rgba(184, 148, 31, 0.35), transparent);
        }
        .divider-diamond {
            width: 6px; height: 6px;
            background: var(--gold);
            transform: rotate(45deg);
            box-shadow: 0 0 8px rgba(184, 148, 31, 0.5);
        }

        /* Form */
        .form-label {
            font-size: 0.7rem; font-weight: 700;
            color: var(--text);
            letter-spacing: 0.1em; text-transform: uppercase;
            margin-bottom: 0.5rem;
        }
        .input-group-icon { position: relative; }
        .input-group-icon i.field-icon {
            position: absolute;
            left: 16px; top: 50%; transform: translateY(-50%);
            color: var(--text-dim);
            font-size: 0.9rem;
            pointer-events: none;
            transition: color 0.25s;
            z-index: 5;
        }
        .form-control {
            border: 1px solid var(--border);
            border-radius: 9px;
            padding: 0.8rem 1rem 0.8rem 2.75rem;
            font-size: 0.92rem;
            color: var(--text-bright);
            background: var(--bg-soft);
            transition: all 0.2s ease;
            font-family: 'Inter', sans-serif;
            height: auto;
        }
        .form-control:focus {
            border-color: var(--primary);
            background: var(--card);
            box-shadow: 0 0 0 3px var(--primary-soft);
            outline: none;
            color: var(--text-bright);
        }
        .form-control:focus ~ i.field-icon,
        .input-group-icon:focus-within i.field-icon { color: var(--primary); }
        .form-control::placeholder { color: var(--text-dim); font-size: 0.88rem; }

        .toggle-password {
            position: absolute;
            right: 16px; top: 50%; transform: translateY(-50%);
            color: var(--text-dim);
            font-size: 0.95rem;
            cursor: pointer; z-index: 10;
            transition: color 0.25s;
            background: transparent; border: none; padding: 0; line-height: 1;
        }
        .toggle-password:hover { color: var(--gold); }
        .form-control:focus ~ .toggle-password { color: var(--primary); }

        /* Button */
        .btn-login {
            width: 100%; padding: 0.85rem;
            border: none; border-radius: 9px;
            background: linear-gradient(135deg, var(--gold), var(--gold-strong));
            color: #fff; font-weight: 600;
            font-size: 0.8rem;
            letter-spacing: 0.2em; text-transform: uppercase;
            margin-top: 0.75rem;
            transition: all 0.3s ease;
            position: relative; overflow: hidden;
            box-shadow: 0 4px 14px -3px rgba(184, 148, 31, 0.45);
            cursor: pointer;
        }
        .btn-login::before {
            content: '';
            position: absolute; top: 0; left: -100%;
            width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.25), transparent);
            transition: left 0.6s ease;
        }
        .btn-login:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 22px -5px rgba(184, 148, 31, 0.6);
        }
        .btn-login:hover::before { left: 100%; }
        .btn-login:active { transform: translateY(0); }

        /* Error */
        .alert-danger-modern {
            background: rgba(220, 38, 38, 0.06);
            border: 1px solid rgba(220, 38, 38, 0.2);
            color: #b91c1c;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            font-size: 0.82rem; font-weight: 500;
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 1.25rem;
        }
        .alert-danger-modern i { font-size: 0.95rem; }

        /* Back link */
        .back-link {
            display: block; text-align: center;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.74rem; font-weight: 600;
            letter-spacing: 0.1em; text-transform: uppercase;
            margin-top: 1.5rem;
            transition: color 0.25s;
        }
        .back-link:hover { color: var(--primary); }
        .back-link i {
            margin-right: 8px; font-size: 0.7rem;
            transition: transform 0.25s;
        }
        .back-link:hover i { transform: translateX(-3px); }

        /* Autofill */
        input:-webkit-autofill,
        input:-webkit-autofill:hover,
        input:-webkit-autofill:focus {
            -webkit-box-shadow: 0 0 0 30px var(--bg-soft) inset !important;
            -webkit-text-fill-color: var(--text-bright) !important;
            transition: background-color 5000s ease-in-out 0s;
        }

        /* Responsive */
        @media (max-width: 480px) {
            body { padding: 1rem; }
            .login-card { padding: 2.25rem 1.75rem; border-radius: 14px; }
            .login-title { font-size: 1.9rem; }
            .diamond-icon { width: 60px; height: 60px; }
            .login-card::before, .login-card::after { width: 22px; height: 22px; }
        }
        @media (max-width: 360px) {
            .login-card { padding: 2rem 1.5rem; }
            .login-title { font-size: 1.75rem; }
        }
        @media (prefers-reduced-motion: reduce) {
            * { animation-duration: .01ms !important; transition-duration: .01ms !important; }
        }
    </style>
</head>
<body>

    <div class="login-card">
        <!-- Diamond logo -->
        <div class="diamond-icon">
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

        <h1 class="login-title">R-Jewel&Gold</h1>
        <p class="login-sub">Admin Portal</p>

        <div class="divider">
            <span class="divider-line"></span>
            <span class="divider-diamond"></span>
            <span class="divider-line"></span>
        </div>

        <?php if($error): ?>
            <div class="alert-danger-modern">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-3">
                <label class="form-label">Username</label>
                <div class="input-group-icon">
                    <i class="fas fa-user field-icon"></i>
                    <input type="text" name="username" class="form-control"
                           placeholder="Enter your username" required autofocus
                           value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Password</label>
                <div class="input-group-icon">
                    <i class="fas fa-lock field-icon"></i>
                    <input type="password" name="password" id="passwordField" class="form-control"
                           placeholder="Enter your password" required>
                    <button type="button" class="toggle-password" id="togglePassword" aria-label="Show password">
                        <i class="fas fa-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-login">Sign In</button>
        </form>

        <a href="../user/login.php" class="back-link">
            <i class="fas fa-arrow-left"></i>Back to User Login
        </a>
    </div>

    <script>
        (function () {
            const passwordInput = document.getElementById('passwordField');
            const toggleBtn = document.getElementById('togglePassword');
            const eyeIcon = document.getElementById('eyeIcon');

            if (!passwordInput || !toggleBtn || !eyeIcon) return;

            toggleBtn.addEventListener('click', function () {
                const isHidden = passwordInput.type === 'password';
                passwordInput.type = isHidden ? 'text' : 'password';

                eyeIcon.classList.toggle('fa-eye', !isHidden);
                eyeIcon.classList.toggle('fa-eye-slash', isHidden);

                toggleBtn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
                passwordInput.focus();
            });
        })();
    </script>

</body>
</html>