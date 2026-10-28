<?php
// admin/simple_dashboard.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if logged in as admin
if(!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: simple_login.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Get statistics
$stats = [];

// Total products
$query = "SELECT COUNT(*) as total FROM products";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['products'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Total orders
$query = "SELECT COUNT(*) as total FROM orders";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['orders'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Total users
$query = "SELECT COUNT(*) as total FROM users WHERE role = 'user'";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['users'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Admin Dashboard | R-Jewel&Gold</title>
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
            --warning: #d97706;
            --danger: #dc2626;
            --info: #2563eb;
            --nav-bg: #1e2533;
            --nav-text: #8b92a5;
            --nav-text-bright: #ffffff;
            --nav-border: #2a3142;
            --nav-hover: #252d3d;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html { scroll-behavior: smooth; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            overflow-x: hidden;
            min-height: 100vh;
            font-size: 15px;
            line-height: 1.5;
        }

        /* ========= TOP HEADER ========= */
        .header {
            background: var(--nav-bg);
            border-bottom: 1px solid var(--nav-border);
            padding: 0 24px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 12px rgba(0, 0, 0, .15);
        }
        .header-content {
            max-width: 1300px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            min-height: 72px;
            flex-wrap: wrap;
            padding: 12px 0;
        }
        .header-brand {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
        }
        .header-brand-icon {
            width: 44px; height: 44px;
            background: linear-gradient(135deg, var(--gold), #8a6e14);
            border-radius: 11px;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 1.15rem;
            box-shadow: 0 4px 12px -3px rgba(184, 148, 31, .4);
            flex-shrink: 0;
        }
        .header-brand-text h1 {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--nav-text-bright);
            margin: 0;
            letter-spacing: -.01em;
            line-height: 1.2;
        }
        .header-brand-text p {
            font-size: .75rem;
            color: var(--nav-text);
            margin: 3px 0 0;
            font-weight: 500;
        }
        .header-brand-text p strong {
            color: #a5b4fc;
            font-weight: 600;
        }

        .logout-btn {
            background: rgba(220, 38, 38, .12);
            color: #f87171;
            border: 1px solid rgba(220, 38, 38, .25);
            padding: 9px 18px;
            text-decoration: none;
            border-radius: 9px;
            font-size: .8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: all .2s ease;
            white-space: nowrap;
        }
        .logout-btn:hover {
            background: var(--danger);
            color: #fff;
            border-color: var(--danger);
            transform: translateY(-1px);
            box-shadow: 0 6px 18px -4px rgba(220, 38, 38, .45);
        }

        /* ========= CONTAINER ========= */
        .container {
            max-width: 1300px;
            margin: 0 auto;
            padding: 26px 24px 40px;
        }

        /* ========= PAGE TITLE ========= */
        .page-title {
            margin-bottom: 20px;
        }
        .page-title h2 {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--text-bright);
            margin: 0;
            letter-spacing: -.02em;
        }
        .page-title p {
            margin: 4px 0 0;
            color: var(--text-dim);
            font-size: .82rem;
        }

        /* ========= WELCOME CARD ========= */
        .welcome-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 26px 28px;
            margin-bottom: 22px;
            box-shadow: 0 1px 3px rgba(90, 82, 69, .05);
            position: relative;
            overflow: hidden;
        }
        .welcome-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 4px; height: 100%;
            background: linear-gradient(180deg, var(--gold), var(--primary));
            opacity: .85;
        }
        .welcome-card h2 {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--text-bright);
            margin: 0 0 6px;
            letter-spacing: -.01em;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .welcome-card h2 i {
            color: var(--gold);
            font-size: 1.05rem;
        }
        .welcome-card p {
            margin: 0;
            color: var(--text-muted);
            font-size: .85rem;
            line-height: 1.55;
        }

        /* ========= STATS GRID ========= */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 26px;
        }
        .stat-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 20px;
            transition: all .25s ease;
            box-shadow: 0 1px 3px rgba(90, 82, 69, .05);
            position: relative;
            overflow: hidden;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 3px; height: 100%;
            background: var(--primary);
            opacity: .7;
        }
        .stat-card.gold::before    { background: var(--gold); }
        .stat-card.success::before { background: var(--success); }
        .stat-card.info::before    { background: var(--info); }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 24px -8px rgba(90, 82, 69, .15);
            border-color: var(--border-light);
        }

        .stat-card .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 14px;
            gap: 8px;
        }
        .stat-card .stat-icon {
            width: 44px; height: 44px;
            border-radius: 11px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem;
            background: var(--primary-soft);
            color: var(--primary);
            flex-shrink: 0;
        }
        .stat-card.gold    .stat-icon { background: var(--gold-soft); color: var(--gold); }
        .stat-card.success .stat-icon { background: rgba(13, 150, 104, .08); color: var(--success); }
        .stat-card.info    .stat-icon { background: rgba(37, 99, 235, .08); color: var(--info); }

        .stat-card .stat-label {
            font-size: .68rem;
            font-weight: 600;
            color: var(--text-dim);
            letter-spacing: .08em;
            text-transform: uppercase;
            margin-bottom: 6px;
        }
        .stat-card .number {
            font-size: 1.9rem;
            font-weight: 700;
            color: var(--text-bright);
            letter-spacing: -.02em;
            line-height: 1.1;
        }
        .stat-card .stat-sub {
            font-size: .72rem;
            color: var(--text-muted);
            margin-top: 4px;
            font-weight: 500;
        }

        /* ========= SECTION HEADING ========= */
        .section-heading {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 4px 0 16px;
        }
        .section-heading h3 {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-bright);
            margin: 0;
            letter-spacing: -.01em;
        }
        .section-heading::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        /* ========= MENU GRID ========= */
        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 16px;
            margin-bottom: 26px;
        }
        .menu-card {
            background: var(--card);
            border: 1px solid var(--border);
            padding: 24px 20px;
            text-align: center;
            border-radius: 14px;
            text-decoration: none;
            color: var(--text);
            transition: all .25s ease;
            box-shadow: 0 1px 3px rgba(90, 82, 69, .05);
            position: relative;
            overflow: hidden;
        }
        .menu-card::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: var(--primary);
            opacity: 0;
            transition: opacity .25s ease;
        }
        .menu-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 14px 30px -10px rgba(90, 82, 69, .2);
            border-color: var(--border-light);
        }
        .menu-card:hover::after { opacity: 1; }

        .menu-card .menu-icon {
            width: 56px; height: 56px;
            margin: 0 auto 14px;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem;
            background: var(--primary-soft);
            color: var(--primary);
            transition: all .25s ease;
        }
        .menu-card:hover .menu-icon {
            background: var(--primary);
            color: #fff;
            transform: scale(1.05);
        }
        .menu-card.gold    .menu-icon { background: var(--gold-soft); color: var(--gold); }
        .menu-card.gold:hover .menu-icon { background: var(--gold); color: #fff; }
        .menu-card.success .menu-icon { background: rgba(13,150,104,.08); color: var(--success); }
        .menu-card.success:hover .menu-icon { background: var(--success); color: #fff; }
        .menu-card.info    .menu-icon { background: rgba(37,99,235,.08); color: var(--info); }
        .menu-card.info:hover .menu-icon { background: var(--info); color: #fff; }

        .menu-card h4 {
            font-size: .95rem;
            font-weight: 700;
            color: var(--text-bright);
            margin: 0 0 5px;
            letter-spacing: -.01em;
        }
        .menu-card p {
            font-size: .76rem;
            color: var(--text-dim);
            margin: 0;
            line-height: 1.45;
        }
        .menu-card .menu-arrow {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: .72rem;
            font-weight: 600;
            color: var(--primary);
            margin-top: 12px;
            opacity: 0;
            transform: translateY(4px);
            transition: all .25s ease;
        }
        .menu-card:hover .menu-arrow {
            opacity: 1;
            transform: translateY(0);
        }

        /* ========= SESSION INFO CARD ========= */
        .session-card {
            background: var(--bg-soft);
            border: 1px dashed var(--border);
            border-radius: 14px;
            padding: 20px 22px;
            margin-top: 10px;
        }
        .session-card .session-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: .78rem;
            font-weight: 700;
            color: var(--text-bright);
            letter-spacing: .05em;
            text-transform: uppercase;
            margin-bottom: 14px;
        }
        .session-card .session-title i { color: var(--gold); font-size: .85rem; }

        .session-rows {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
        }
        .session-row {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 11px 14px;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .session-row .label {
            font-size: .66rem;
            color: var(--text-dim);
            text-transform: uppercase;
            letter-spacing: .06em;
            font-weight: 600;
        }
        .session-row .value {
            font-size: .85rem;
            color: var(--text-bright);
            font-weight: 600;
            font-family: 'SFMono-Regular', Consolas, monospace;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .session-row .value.role {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--primary);
        }
        .session-row .value.role::before {
            content: '';
            width: 7px; height: 7px;
            border-radius: 50%;
            background: var(--success);
            box-shadow: 0 0 0 3px rgba(13,150,104,.15);
        }

        /* ========= FOOTER ========= */
        .page-footer {
            text-align: center;
            margin-top: 30px;
            font-size: .72rem;
            color: var(--text-dim);
        }
        .page-footer i { color: var(--gold); }

        /* ========= RESPONSIVE ========= */
        @media (max-width: 992px) {
            .container { padding: 22px 20px 32px; }
            .header { padding: 0 18px; }
        }

        @media (max-width: 768px) {
            body { font-size: 14px; }
            .header-content { min-height: 62px; }
            .header-brand-icon { width: 40px; height: 40px; font-size: 1rem; }
            .header-brand-text h1 { font-size: 1rem; }
            .header-brand-text p { font-size: .68rem; }
            .logout-btn { padding: 8px 14px; font-size: .75rem; }
            .container { padding: 20px 16px 28px; }
            .page-title h2 { font-size: 1.2rem; }
            .welcome-card { padding: 20px; border-radius: 12px; }
            .welcome-card h2 { font-size: 1.02rem; }
            .welcome-card p { font-size: .8rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
            .stat-card { padding: 16px; border-radius: 12px; }
            .stat-card .stat-icon { width: 40px; height: 40px; font-size: 1rem; }
            .stat-card .number { font-size: 1.55rem; }
            .stat-card .stat-label { font-size: .62rem; }
            .menu-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
            .menu-card { padding: 20px 14px; border-radius: 12px; }
            .menu-card .menu-icon { width: 48px; height: 48px; font-size: 1.2rem; }
            .menu-card h4 { font-size: .88rem; }
            .menu-card p { font-size: .72rem; }
            .menu-card .menu-arrow { opacity: 1; transform: none; }
        }

        @media (max-width: 480px) {
            .header-content { gap: 10px; }
            .header-brand-text p { display: none; }
            .stats-grid { grid-template-columns: 1fr; }
            .menu-grid { grid-template-columns: 1fr; }
            .stat-card .number { font-size: 1.4rem; }
            .session-rows { grid-template-columns: 1fr; }
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .stat-card, .menu-card, .welcome-card, .session-card {
            animation: fadeInUp .4s ease-out;
        }

        @media (prefers-reduced-motion: reduce) {
            * { animation-duration: .01ms !important; transition-duration: .01ms !important; }
        }
    </style>
</head>
<body>

    <!-- ========= HEADER ========= -->
    <header class="header">
        <div class="header-content">
            <div class="header-brand">
                <div class="header-brand-icon"><i class="fas fa-gem"></i></div>
                <div class="header-brand-text">
                    <h1>Admin Dashboard</h1>
                    <p>Welcome back, <strong><?php echo htmlspecialchars($_SESSION['full_name']); ?></strong></p>
                </div>
            </div>
            <a href="logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </header>

    <div class="container">

        <!-- Welcome -->
        <div class="page-title">
            <h2>Overview</h2>
            <p>Here's what's happening with your store today.</p>
        </div>

        <div class="welcome-card">
            <h2><i class="fas fa-crown"></i> Welcome to the Admin Panel</h2>
            <p>You have successfully logged in as administrator. From here you can manage products, orders, and users.</p>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card gold">
                <div class="stat-header">
                    <span class="stat-label">Total Products</span>
                    <div class="stat-icon"><i class="fas fa-gem"></i></div>
                </div>
                <div class="number"><?php echo number_format((int)$stats['products']); ?></div>
                <div class="stat-sub">Items in catalog</div>
            </div>

            <div class="stat-card success">
                <div class="stat-header">
                    <span class="stat-label">Total Orders</span>
                    <div class="stat-icon"><i class="fas fa-shopping-cart"></i></div>
                </div>
                <div class="number"><?php echo number_format((int)$stats['orders']); ?></div>
                <div class="stat-sub">All-time orders</div>
            </div>

            <div class="stat-card info">
                <div class="stat-header">
                    <span class="stat-label">Total Users</span>
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                </div>
                <div class="number"><?php echo number_format((int)$stats['users']); ?></div>
                <div class="stat-sub">Registered customers</div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="section-heading"><h3>Quick Actions</h3></div>

        <div class="menu-grid">
            <a href="products.php" class="menu-card gold">
                <div class="menu-icon"><i class="fas fa-box"></i></div>
                <h4>Products</h4>
                <p>Manage your product catalog</p>
                <span class="menu-arrow">Manage <i class="fas fa-arrow-right"></i></span>
            </a>

            <a href="orders.php" class="menu-card success">
                <div class="menu-icon"><i class="fas fa-shopping-cart"></i></div>
                <h4>Orders</h4>
                <p>View and process orders</p>
                <span class="menu-arrow">Manage <i class="fas fa-arrow-right"></i></span>
            </a>

            <a href="users.php" class="menu-card info">
                <div class="menu-icon"><i class="fas fa-users"></i></div>
                <h4>Users</h4>
                <p>Manage customers</p>
                <span class="menu-arrow">Manage <i class="fas fa-arrow-right"></i></span>
            </a>

            <a href="categories.php" class="menu-card">
                <div class="menu-icon"><i class="fas fa-tags"></i></div>
                <h4>Categories</h4>
                <p>Organize product categories</p>
                <span class="menu-arrow">Manage <i class="fas fa-arrow-right"></i></span>
            </a>
        </div>

        <!-- Session Info -->
        <div class="session-card">
            <div class="session-title"><i class="fas fa-shield-alt"></i> Session Information</div>
            <div class="session-rows">
                <div class="session-row">
                    <span class="label">User ID</span>
                    <span class="value">#<?php echo htmlspecialchars($_SESSION['user_id']); ?></span>
                </div>
                <div class="session-row">
                    <span class="label">Username</span>
                    <span class="value"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                </div>
                <div class="session-row">
                    <span class="label">Role</span>
                    <span class="value role"><?php echo htmlspecialchars(ucfirst($_SESSION['role'])); ?></span>
                </div>
            </div>
        </div>

        <div class="page-footer">
            &copy; <?php echo date('Y'); ?> <i class="fas fa-gem"></i> R-Jewel&Gold &mdash; Admin Panel
        </div>
    </div>

</body>
</html>