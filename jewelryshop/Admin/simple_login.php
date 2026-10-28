<?php
// admin/simple_login.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If already logged in, go to dashboard
if(isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] == 'admin') {
    header("Location: simple_dashboard.php");
    exit();
}

$error = '';

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Direct query
    $query = "SELECT * FROM users WHERE username = :username AND role = 'admin'";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':username', $username);
    $stmt->execute();
    
    if($stmt->rowCount() > 0) {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if(password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];
            
            header("Location: simple_dashboard.php");
            exit();
        } else {
            $error = "Invalid password! Please try again.";
        }
    } else {
        $error = "Admin user not found! Username: " . htmlspecialchars($username);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Admin Login | R-Jewel&Gold</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
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
            --primary: #5b5fd4;
            --primary-soft: rgba(91, 95, 212, 0.08);
            --gold: #b8941f;
            --gold-soft: rgba(184, 148, 31, 0.1);
            --success: #0d9668;
            --danger: #dc2626;
            --nav-bg: #1e2533;
            --nav-border: #2a3142;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html { scroll-behavior: smooth; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            font-size: 15px;
            line-height: 1.5;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            position: relative;
            overflow-x: hidden;
        }

        /* Soft decorative background accents */
        body::before,
        body::after {
            content: '';
            position: fixed;
            border-radius: 50%;
            filter: blur(90px);
            opacity: .35;
            z-index: 0;
            pointer-events: none;
        }
        body::before {
            width: 420px; height: 420px;
            background: radial-gradient(circle, rgba(91,95,212,.35), transparent 70%);
            top: -140px; left: -140px;
        }
        body::after {
            width: 460px; height: 460px;
            background: radial-gradient(circle, rgba(184,148,31,.3), transparent 70%);
            bottom: -160px; right: -160px;
        }

        .login-wrapper {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 430px;
            animation: fadeInUp .5s ease-out;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ========= BRAND HEADER ========= */
        .brand-header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 14px;
            margin-bottom: 22px;
        }
        .brand-icon {
            width: 52px; height: 52px;
            background: linear-gradient(135deg, var(--gold), #8a6e14);
            border-radius: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.35rem;
            box-shadow: 0 8px 22px -6px rgba(184, 148, 31, .55);
            flex-shrink: 0;
        }
        .brand-text h1 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text-bright);
            letter-spacing: -.02em;
            margin: 0;
            line-height: 1.15;
        }
        .brand-text p {
            font-size: .72rem;
            color: var(--gold);
            letter-spacing: .18em;
            text-transform: uppercase;
            font-weight: 600;
            margin: 3px 0 0;
        }

        /* ========= LOGIN CARD ========= */
        .login-container {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 32px 30px;
            box-shadow: 0 20px 50px -20px rgba(90, 82, 69, .25),
                        0 1px 3px rgba(90, 82, 69, .05);
        }

        .login-heading {
            text-align: center;
            margin-bottom: 26px;
        }
        .login-heading h2 {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--text-bright);
            margin: 0 0 4px;
            letter-spacing: -.01em;
        }
        .login-heading .subtitle {
            font-size: .78rem;
            color: var(--text-dim);
            margin: 0;
        }

        /* ========= FORM ========= */
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            font-size: .72rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 7px;
            letter-spacing: .05em;
            text-transform: uppercase;
        }
        .input-wrap {
            position: relative;
        }
        .input-wrap i.field-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-dim);
            font-size: .85rem;
            pointer-events: none;
            transition: color .2s ease;
        }
        .input-wrap input {
            width: 100%;
            background: var(--bg-soft);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 12px 14px 12px 40px;
            color: var(--text-bright);
            font-size: .88rem;
            font-family: 'Inter', sans-serif;
            font-weight: 500;
            transition: all .2s ease;
        }
        .input-wrap input::placeholder { color: var(--text-dim); font-weight: 400; }
        .input-wrap input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-soft);
            background: var(--card);
        }
        .input-wrap input:focus ~ i.field-icon { color: var(--primary); }

        /* Password reveal */
        .toggle-pass {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: var(--text-dim);
            font-size: .85rem;
            padding: 6px 8px;
            border-radius: 7px;
            cursor: pointer;
            transition: all .15s ease;
        }
        .toggle-pass:hover { color: var(--primary); background: var(--primary-soft); }

        /* ========= BUTTON ========= */
        .btn-login {
            width: 100%;
            padding: 12px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: .88rem;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            letter-spacing: .02em;
            cursor: pointer;
            transition: all .2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 6px;
        }
        .btn-login:hover {
            background: #4f46e5;
            transform: translateY(-1px);
            box-shadow: 0 10px 24px -6px rgba(91, 95, 212, .5);
        }
        .btn-login:active { transform: translateY(0); }

        /* ========= ERROR / INFO ========= */
        .error {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            background: rgba(220, 38, 38, .08);
            color: #b91c1c;
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 18px;
            border: 1px solid rgba(220, 38, 38, .18);
            font-size: .8rem;
            font-weight: 500;
            animation: fadeInUp .3s ease-out;
        }
        .error i { font-size: .9rem; margin-top: 2px; flex-shrink: 0; }

        .info {
            background: var(--bg-soft);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 14px 16px;
            margin-top: 22px;
            font-size: .75rem;
            color: var(--text-muted);
            line-height: 1.6;
        }
        .info .info-title {
            display: flex;
            align-items: center;
            gap: 7px;
            color: var(--text-bright);
            font-weight: 600;
            font-size: .75rem;
            margin-bottom: 8px;
            letter-spacing: .03em;
            text-transform: uppercase;
        }
        .info .info-title i { color: var(--gold); font-size: .8rem; }
        .info .cred-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 4px 0;
        }
        .info .cred-row span:first-child { color: var(--text-dim); }
        .info .cred-row code {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 2px 8px;
            font-family: 'SFMono-Regular', Consolas, monospace;
            font-size: .72rem;
            color: var(--primary);
            font-weight: 600;
        }
        .info .divider {
            height: 1px;
            background: var(--border);
            margin: 12px 0;
        }
        .info a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: color .15s ease;
        }
        .info a:hover { color: #4f46e5; }

        /* ========= FOOTER ========= */
        .page-footer {
            text-align: center;
            margin-top: 20px;
            font-size: .7rem;
            color: var(--text-dim);
        }
        .page-footer i { color: var(--gold); }

        /* ========= RESPONSIVE ========= */
        @media (max-width: 480px) {
            body { padding: 20px 14px; }
            .login-container { padding: 26px 22px; border-radius: 14px; }
            .brand-icon { width: 46px; height: 46px; font-size: 1.2rem; border-radius: 11px; }
            .brand-text h1 { font-size: 1.15rem; }
            .brand-text p { font-size: .66rem; }
            .login-heading h2 { font-size: 1.05rem; }
        }

        @media (prefers-reduced-motion: reduce) {
            * { animation-duration: .01ms !important; transition-duration: .01ms !important; }
        }
    </style>
</head>
<body>
    <div class="login-wrapper">

        <!-- Brand header -->
        <div class="brand-header">
            <div class="brand-icon"><i class="fas fa-gem"></i></div>
            <div class="brand-text">
                <h1>R-Jewel&Gold</h1>
                <p>Admin Panel</p>
            </div>
        </div>

        <!-- Login card -->
        <div class="login-container">
            <div class="login-heading">
                <h2>Admin Login</h2>
                <p class="subtitle">Sign in to access your dashboard</p>
            </div>

            <?php if($error): ?>
                <div class="error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-wrap">
                        <input type="text" id="username" name="username" required autofocus
                               placeholder="Enter your username" value="admin">
                        <i class="fas fa-user field-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <input type="password" id="password" name="password" required
                               placeholder="Enter your password" value="admin123">
                        <i class="fas fa-lock field-icon"></i>
                        <button type="button" class="toggle-pass" id="togglePass" aria-label="Toggle password visibility">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i> Login
                </button>
            </form>

            <div class="info">
                <div class="info-title"><i class="fas fa-key"></i> Default Credentials</div>
                <div class="cred-row">
                    <span>Username</span>
                    <code>admin</code>
                </div>
                <div class="cred-row">
                    <span>Password</span>
                    <code>admin123</code>
                </div>
                <div class="divider"></div>
                <a href="../user/login.php"><i class="fas fa-arrow-right"></i> Go to User Login</a>
            </div>
        </div>

        <div class="page-footer">
            &copy; <?php echo date('Y'); ?> <i class="fas fa-gem"></i> R-Jewel&Gold &mdash; Secure Admin Access
        </div>
    </div>

    <script>
        // Password visibility toggle
        (function () {
            const toggle = document.getElementById('togglePass');
            const pass = document.getElementById('password');
            if (!toggle || !pass) return;
            toggle.addEventListener('click', function () {
                const isPass = pass.type === 'password';
                pass.type = isPass ? 'text' : 'password';
                this.innerHTML = isPass
                    ? '<i class="fas fa-eye-slash"></i>'
                    : '<i class="fas fa-eye"></i>';
                pass.focus();
            });
        })();
    </script>
</body>
</html>