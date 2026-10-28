<?php
// admin/edit-product.php
require_once '../config/database.php';
require_once '../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

if(!$auth->isLoggedIn() || !$auth->isAdmin()) {
    header("Location: login.php");
    exit();
}

$id = $_GET['id'] ?? 0;

// Get product details
$query = "SELECT * FROM products WHERE id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->execute();
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$product) {
    header("Location: products.php");
    exit();
}

// Get categories
$cat_query = "SELECT * FROM categories ORDER BY name";
$cat_stmt = $db->prepare($cat_query);
$cat_stmt->execute();
$categories = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);

// ---------- Read persisted appearance from cookie (written by dashboard Settings) ----------
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
$resolved_theme = in_array($theme, ['cream','light','dark'], true) ? $theme : 'cream';

// Resolve sidebar palette in PHP for initial paint
$sidebar_palette = [
    'dark'     => ['#1a2030','#252d3d','#2a3142'],
    'midnight' => ['#101b35','#1b2a4a','#293958'],
    'charcoal' => ['#292929','#383838','#454545']
][$sidebar_style] ?? ['#1a2030','#252d3d','#2a3142'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Edit Product | R-Jewel&Gold Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400;1,700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <!-- ============================================================
         BASE VARIABLES (cream / default) — same as dashboard
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
            --gold-light: #d4af37;
            --gold-bright: #f0d060;
            --gold-ref: #c9a227;
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
            --sidebar-bg: <?php echo $sidebar_palette[0]; ?>;
            --sidebar-hover: <?php echo $sidebar_palette[1]; ?>;
            --sidebar-active: <?php echo htmlspecialchars($accent); ?>26;
            --sidebar-text: #8b92a5;
            --sidebar-text-bright: #ffffff;
            --sidebar-border: <?php echo $sidebar_palette[2]; ?>;
        }

        /* Theme override */
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
         PAGE STYLES (all reference the variables above)
         ============================================================ -->
    <style>
        * { margin:0; padding:0; box-sizing:border-box; -webkit-tap-highlight-color: transparent; }
        html { scroll-behavior: smooth; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            overflow-x: hidden;
            min-height: 100vh;
            font-size: 15px;
            line-height: 1.5;
            transition: background-color .3s ease, color .3s ease;
        }

        /* ========= SIDEBAR OVERLAY ========= */
        .sidebar-overlay {
            display:none; position:fixed; inset:0;
            background: rgba(30,37,51,0.4); backdrop-filter: blur(3px);
            z-index:998; opacity:0; transition: opacity .3s ease;
        }
        .sidebar-overlay.active { display:block; opacity:1; }

        /* ========= TOP NAVBAR ========= */
        .top-nav {
            position: fixed; top:0; left:340px; right:0; height:80px;
            background: var(--nav-bg);
            border-bottom: 1px solid var(--nav-border);
            z-index: 997;
            display:flex; align-items:center; justify-content:space-between;
            padding: 0 30px;
            transition: left .3s cubic-bezier(.4,0,.2,1);
            box-shadow: 0 2px 12px rgba(0,0,0,.15);
        }
        .nav-left { display:flex; align-items:center; gap:16px; flex:1; min-width:0; }

        .nav-menu-btn {
            display:none; width:44px; height:44px; border-radius:12px;
            background: rgba(255,255,255,.06); border:1px solid var(--nav-border);
            color: var(--nav-text); font-size:1.1rem;
            align-items:center; justify-content:center;
            cursor:pointer; transition: all .2s ease; flex-shrink:0;
        }
        .nav-menu-btn:hover { background: rgba(255,255,255,.1); color: var(--nav-text-bright); }

        .nav-search { position:relative; max-width:420px; width:100%; min-width:0; }
        .nav-search i {
            position:absolute; left:16px; top:50%; transform:translateY(-50%);
            color:#6b7280; font-size:.95rem; pointer-events:none;
        }
        .nav-search input {
            width:100%; background: rgba(255,255,255,.06);
            border:1px solid var(--nav-border); border-radius:12px;
            padding: 11px 16px 11px 44px; color: var(--nav-text-bright);
            font-size:.9rem; font-family:'Inter', sans-serif;
            transition: all .2s ease;
        }
        .nav-search input::placeholder { color:#6b7280; }
        .nav-search input:focus {
            outline:none; border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(91,95,212,.2);
            background: rgba(255,255,255,.08);
        }

        .nav-right { display:flex; align-items:center; gap:10px; flex-shrink:0; }

        .nav-icon-btn {
            position:relative; width:46px; height:46px; border-radius:12px;
            background: transparent; border:1px solid transparent;
            color:#8b92a5; font-size:1.1rem;
            display:flex; align-items:center; justify-content:center;
            cursor:pointer; transition: all .2s ease; text-decoration:none;
        }
        .nav-icon-btn:hover {
            background: var(--nav-hover); border-color: var(--nav-border);
            color: var(--nav-text-bright);
        }

        .nav-divider { width:1px; height:32px; background: var(--nav-border); margin:0 8px; }

        /* ========= USER DROPDOWN ========= */
        .nav-user {
            display:flex; align-items:center; gap:12px;
            padding: 6px 12px 6px 6px; border-radius:12px;
            cursor:pointer; transition: background .2s ease;
            position:relative; border:1px solid transparent;
        }
        .nav-user:hover { background: var(--nav-hover); border-color: var(--nav-border); }

        .nav-user-avatar {
            width:40px; height:40px;
            background: linear-gradient(135deg, var(--gold), #8a6e14);
            border-radius:11px; display:flex; align-items:center;
            justify-content:center; color:#fff; font-weight:600;
            font-size:.9rem; flex-shrink:0;
        }
        .nav-user-info { text-align:left; line-height:1.25; }
        .nav-user-info strong {
            display:block; color: var(--nav-text-bright);
            font-size:.86rem; font-weight:600;
        }
        .nav-user-info small { color:#6b7280; font-size:.7rem; }
        .nav-user-chevron {
            color:#6b7280; font-size:.75rem; transition: transform .2s ease;
        }
        .nav-user.open .nav-user-chevron { transform: rotate(180deg); }

        .nav-dropdown {
            position:absolute; top:calc(100% + 10px); right:0;
            min-width:220px; background:#252d3d;
            border:1px solid var(--nav-border); border-radius:12px;
            padding:8px; box-shadow: 0 15px 35px -10px rgba(0,0,0,.5);
            opacity:0; visibility:hidden; transform: translateY(-6px);
            transition: all .2s ease; z-index:1000;
        }
        .nav-user.open .nav-dropdown {
            opacity:1; visibility:visible; transform: translateY(0);
        }
        .nav-dropdown a {
            display:flex; align-items:center; gap:12px;
            padding: 10px 14px; border-radius:8px;
            color:#8b92a5; text-decoration:none; font-size:.86rem;
            font-weight:500; transition: all .15s ease;
        }
        .nav-dropdown a:hover { background: rgba(255,255,255,.06); color: var(--nav-text-bright); }
        .nav-dropdown a.danger { color:#f87171; }
        .nav-dropdown a.danger:hover { background: rgba(220,38,38,.12); }
        .nav-dropdown a i { width:20px; text-align:center; font-size:.9rem; }
        .nav-dropdown-divider { height:1px; background: var(--nav-border); margin:6px 8px; }

        /* ============================================================
           SIDEBAR — R-Jewel&Gold diamond logo + Admin Panel subtitle
           ============================================================ */
        .sidebar {
            background: var(--sidebar-bg);
            border-right:1px solid var(--sidebar-border);
            min-height:100vh; position:fixed; top:0; left:0;
            z-index:999; width:340px;
            transition: transform .3s cubic-bezier(.4,0,.2,1), background-color .3s ease;
            display:flex; flex-direction:column;
            box-shadow: 4px 0 25px rgba(0,0,0,.2);
        }

        .sidebar .brand {
            padding: 18px 24px;
            border-bottom: 1px solid var(--sidebar-border);
            display: flex;
            align-items: center;
            gap: 14px;
            flex-shrink: 0;
            height: 96px;
            background: linear-gradient(180deg, #1f2632 0%, #1a2030 100%);
        }

        .brand-icon {
            width: 38px;
            height: 54px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .brand-icon svg {
            width: 100%;
            height: 100%;
            filter: drop-shadow(0 0 6px rgba(201, 162, 39, 0.35));
        }

        .brand-text {
            min-width: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .brand-text h4 {
            font-family: 'Playfair Display', 'Georgia', 'Times New Roman', serif;
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
            line-height: 1.05;
            letter-spacing: -0.01em;
            color: #ffffff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .brand-text h4 .amp {
            font-family: 'Playfair Display', 'Georgia', serif;
            font-style: italic;
            font-weight: 400;
            color: #c9a227;
            -webkit-text-fill-color: #c9a227;
            margin: 0 1px;
            letter-spacing: 0;
        }
        .brand-text p {
            font-family: 'Inter', sans-serif;
            font-size: 0.62rem;
            color: #8b92a5;
            margin: 5px 0 0;
            letter-spacing: 0.24em;
            text-transform: uppercase;
            font-weight: 500;
            white-space: nowrap;
        }

        .sidebar-nav {
            padding: 18px 14px; flex:1; overflow-y:auto; overscroll-behavior:contain;
        }
        .nav-item { margin-bottom:4px; }
        .nav-link {
            color: var(--sidebar-text); padding: 14px 18px;
            border-radius:12px; transition: all .2s ease;
            display:flex; align-items:center; gap:14px;
            font-size:.94rem; font-weight:500; text-decoration:none; white-space:nowrap;
        }
        .nav-link:hover { background: var(--sidebar-hover); color: var(--sidebar-text-bright); }
        .nav-link.active {
            background: var(--sidebar-active); color: var(--sidebar-text-bright);
            font-weight:600; box-shadow: inset 4px 0 0 var(--primary);
        }
        .nav-link i { width:24px; font-size:1.1rem; text-align:center; flex-shrink:0; }

        /* ========= MAIN CONTENT ========= */
        .main-content {
            margin-left:340px; padding: 104px 30px 30px;
            min-height:100vh; transition: margin-left .3s ease;
        }

        .page-header {
            background: var(--card); border-radius:16px;
            padding: 20px 26px; margin-bottom:24px;
            border:1px solid var(--border);
            display:flex; justify-content:space-between;
            align-items:center; box-shadow: 0 1px 3px rgba(90,82,69,.05);
            gap:16px; flex-wrap:wrap;
        }
        .page-header h2 {
            font-size:1.4rem; font-weight:700; margin:0;
            color: var(--text-bright); letter-spacing:-.02em;
        }
        .page-header p { margin:4px 0 0; color: var(--text-dim); font-size:.82rem; }
        .page-header-actions { display:flex; align-items:center; gap:10px; flex-shrink:0; }

        /* ========= CARDS ========= */
        .card-modern {
            background: var(--card); border-radius:16px; padding:30px;
            border:1px solid var(--border);
            box-shadow: 0 1px 3px rgba(90,82,69,.05);
        }

        /* ========= FORM ========= */
        .form-label {
            font-size:.78rem; font-weight:600;
            color: var(--text); margin-bottom:8px;
            letter-spacing:.04em; text-transform:uppercase;
        }
        .form-control, .form-select {
            width:100%;
            background: var(--bg-soft);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 12px 16px;
            color: var(--text);
            font-size: .9rem;
            font-family: 'Inter', sans-serif;
            transition: all .2s ease;
        }
        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-soft);
            background: var(--card);
            color: var(--text);
        }
        .form-control::placeholder { color: var(--text-dim); }
        .form-control[type=file] { padding: 10px 14px; }
        .form-text, small.text-muted {
            color: var(--text-dim) !important;
            font-size: .76rem;
        }
        .form-check-input {
            background-color: var(--bg-soft);
            border-color: var(--border);
            cursor: pointer;
            width: 1.1rem;
            height: 1.1rem;
            margin-top: .15rem;
        }
        .form-check-input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        .form-check-input:focus {
            box-shadow: 0 0 0 3px var(--primary-soft);
            border-color: var(--primary);
        }
        .form-check-label {
            color: var(--text); font-size: .88rem;
            cursor: pointer; user-select: none;
        }

        /* ========= BUTTONS ========= */
        .btn-modern {
            background: var(--primary); border:none; color:#fff;
            border-radius:10px; font-size:.86rem; font-weight:600;
            padding: 12px 26px; transition: all .2s ease;
            text-decoration:none; display:inline-flex;
            align-items:center; gap:8px; white-space:nowrap; cursor:pointer;
        }
        .btn-modern:hover {
            background: color-mix(in srgb, var(--primary) 85%, black);
            color:#fff; transform: translateY(-1px);
            box-shadow: 0 6px 18px -3px var(--primary-soft);
        }
        .btn-outline-modern {
            background: transparent; border:1px solid var(--border);
            color: var(--text-muted); border-radius:10px;
            font-size:.86rem; font-weight:500; padding: 12px 26px;
            transition: all .2s ease; text-decoration:none;
            display:inline-flex; align-items:center; gap:8px;
            white-space:nowrap; cursor:pointer;
        }
        .btn-outline-modern:hover {
            background: var(--bg-soft); border-color: var(--border-light);
            color: var(--text-bright);
        }

        /* ========= IMAGE PREVIEW ========= */
        .image-preview-box {
            background: var(--bg-soft);
            border: 1px dashed var(--border);
            border-radius: 14px;
            padding: 16px;
            display: inline-block;
            transition: all .2s ease;
        }
        .image-preview-box img {
            width: 140px; height: 140px;
            object-fit: cover;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: var(--card);
            display: block;
        }
        .image-preview-empty {
            width: 140px; height: 140px;
            display:flex; align-items:center; justify-content:center;
            color:var(--text-dim);
            border-radius: 10px;
            border: 1px solid var(--border);
            background: var(--card);
        }

        /* ========= SCROLLBAR ========= */
        ::-webkit-scrollbar { width:7px; height:7px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--border-light); border-radius:4px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--text-dim); }

        /* ========= RESPONSIVE ========= */
        @media (max-width: 1200px) {
            .main-content { padding: 100px 24px 24px; }
            .brand-text h4 { font-size: 1.35rem; }
            .brand-icon { width: 34px; height: 48px; }
        }

        @media (max-width: 1024px) {
            .sidebar { width: 300px; }
            .top-nav { left: 300px; }
            .main-content { margin-left: 300px; padding: 96px 22px 22px; }
            .nav-search { max-width: 300px; }
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
        }

        @media (max-width: 768px) {
            body { font-size: 14px; }
            .top-nav { height: 70px; padding: 0 16px; }
            .nav-search { display: none; }
            .nav-user-info, .nav-user-chevron { display: none; }
            .nav-user { padding: 5px; }
            .nav-divider { display: none; }
            .main-content { padding: 88px 18px 18px; }
            .page-header { flex-direction: column; align-items: flex-start; padding: 18px; }
            .page-header h2 { font-size: 1.2rem; }
            .card-modern { padding: 22px; border-radius: 14px; }
            .image-preview-box img,
            .image-preview-empty { width: 110px; height: 110px; }
        }

        @media (max-width: 576px) {
            .top-nav { height: 66px; }
            .main-content { padding: 82px 14px 14px; }
            .page-header { border-radius: 14px; padding: 16px; }
            .page-header h2 { font-size: 1.1rem; }
            .card-modern { padding: 18px; border-radius: 13px; }
            .sidebar { width: 290px; }

            .brand-text h4 { font-size: 1.15rem; }
            .brand-text p { font-size: 0.55rem; letter-spacing: 0.18em; }
            .brand-icon { width: 28px; height: 42px; }
            .sidebar .brand { padding: 16px 20px; height: 88px; gap: 12px; }
        }

        @media (max-width: 380px) {
            .top-nav { height: 64px; padding: 0 12px; }
            .nav-menu-btn { width: 40px; height: 40px; font-size: 1rem; }
            .nav-icon-btn { width: 40px; height: 40px; font-size: 1rem; }
            .nav-user-avatar { width: 36px; height: 36px; font-size: .82rem; }
            .main-content { padding: 78px 12px 12px; }
            .page-header h2 { font-size: 1.02rem; }
            .card-modern { padding: 16px; }
            .brand-text h4 { font-size: 1rem; }
            .brand-text p { font-size: 0.5rem; letter-spacing: 0.15em; }
            .brand-icon { width: 24px; height: 38px; }
        }

        @media (prefers-reduced-motion: reduce) {
            * { animation-duration: .01ms !important; transition-duration: .01ms !important; }
        }
    </style>
</head>
<body>

    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Top Navbar -->
    <header class="top-nav">
        <div class="nav-left">
            <button class="nav-menu-btn" id="menuToggle" aria-label="Toggle sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <div class="nav-search">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search products, orders, customers...">
            </div>
        </div>
        <div class="nav-right">
            <a href="index.php" class="nav-icon-btn" title="Dashboard" aria-label="Dashboard">
                <i class="fas fa-chart-line"></i>
            </a>
            <div class="nav-divider"></div>
            <div class="nav-user" id="navUser">
                <div class="nav-user-avatar">
                    <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'A', 0, 1)); ?>
                </div>
                <div class="nav-user-info">
                    <strong><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin User'); ?></strong>
                    <small>Administrator</small>
                </div>
                <i class="fas fa-chevron-down nav-user-chevron"></i>
                <div class="nav-dropdown">
                    <a href="index.php"><i class="fas fa-chart-line"></i> Dashboard</a>
                    <a href="products.php"><i class="fas fa-gem"></i> Products</a>
                    <div class="nav-dropdown-divider"></div>
                    <a href="logout.php" class="danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
        </div>
    </header>

    <!-- Sidebar — R-Jewel&Gold diamond logo -->
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <div class="brand-icon">
                <svg viewBox="0 0 40 56" xmlns="http://www.w3.org/2000/svg" fill="none">
                    <path d="M4 18 L20 4 L36 18 L20 52 Z"
                          stroke="#c9a227" stroke-width="1.6"
                          stroke-linejoin="round" stroke-linecap="round" fill="none"/>
                    <line x1="4" y1="18" x2="36" y2="18"
                          stroke="#c9a227" stroke-width="1.2" stroke-linecap="round"/>
                    <line x1="4" y1="18" x2="14" y2="30"
                          stroke="#c9a227" stroke-width="0.9" opacity="0.65"/>
                    <line x1="36" y1="18" x2="26" y2="30"
                          stroke="#c9a227" stroke-width="0.9" opacity="0.65"/>
                </svg>
            </div>
            <div class="brand-text">
                <h4>R-Jewel<span class="amp">&amp;Gold</span></h4>
                <p>Admin Panel</p>
            </div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-item"><a href="index.php" class="nav-link"><i class="fas fa-chart-line"></i><span>Dashboard</span></a></div>
            <div class="nav-item"><a href="products.php" class="nav-link active"><i class="fas fa-gem"></i><span>Products</span></a></div>
            <div class="nav-item"><a href="categories.php" class="nav-link"><i class="fas fa-tags"></i><span>Categories</span></a></div>
            <div class="nav-item"><a href="orders.php" class="nav-link"><i class="fas fa-shopping-cart"></i><span>Orders</span></a></div>
            <div class="nav-item"><a href="users.php" class="nav-link"><i class="fas fa-users"></i><span>Customers</span></a></div>
            <div class="nav-item"><a href="logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></div>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <div class="page-header">
            <div>
                <h2>Edit Product</h2>
                <p>Update details for <strong><?php echo htmlspecialchars($product['name']); ?></strong></p>
            </div>
            <div class="page-header-actions">
                <a href="products.php" class="btn-outline-modern">
                    <i class="fas fa-arrow-left"></i> Back to Products
                </a>
            </div>
        </div>

        <div class="card-modern">
            <form action="update-product.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="id" value="<?php echo $product['id']; ?>">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Product Name *</label>
                        <input type="text" name="name" class="form-control"
                               value="<?php echo htmlspecialchars($product['name']); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Category *</label>
                        <select name="category_id" class="form-select" required>
                            <option value="">Select Category</option>
                            <?php foreach($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>" <?php echo $category['id'] == $product['category_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($category['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="mt-3">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="4"><?php echo htmlspecialchars($product['description']); ?></textarea>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label class="form-label">Price *</label>
                        <input type="number" step="0.01" name="price" class="form-control"
                               value="<?php echo $product['price']; ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Stock *</label>
                        <input type="number" name="stock" class="form-control"
                               value="<?php echo $product['stock']; ?>" required>
                    </div>
                </div>

                <div class="mt-4">
                    <label class="form-label">Current Image</label>
                    <?php if($product['image']): ?>
                        <div class="image-preview-box">
                            <img src="../uploads/<?php echo htmlspecialchars($product['image']); ?>" alt="Product image">
                            <input type="hidden" name="current_image" value="<?php echo htmlspecialchars($product['image']); ?>">
                        </div>
                    <?php else: ?>
                        <div class="image-preview-box">
                            <div class="image-preview-empty">
                                <i class="fas fa-image" style="font-size:2.2rem;"></i>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="mt-3">
                        <label class="form-label">Change Image</label>
                        <input type="file" name="image" class="form-control" accept="image/*">
                        <small class="text-muted">Leave empty to keep current image</small>
                    </div>
                </div>

                <div class="mt-4 form-check">
                    <input type="checkbox" name="featured" class="form-check-input" value="1"
                           id="featured" <?php echo $product['featured'] ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="featured">Featured Product</label>
                </div>

                <div class="d-flex gap-3 mt-4 flex-wrap">
                    <button type="submit" class="btn-modern">
                        <i class="fas fa-check"></i> Update Product
                    </button>
                    <a href="products.php" class="btn-outline-modern">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar toggle
        (function () {
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');

            function openSidebar() {
                sidebar.classList.add('active');
                overlay.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
            function closeSidebar() {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }

            menuToggle && menuToggle.addEventListener('click', function () {
                sidebar.classList.contains('active') ? closeSidebar() : openSidebar();
            });
            overlay && overlay.addEventListener('click', closeSidebar);

            document.querySelectorAll('.sidebar .nav-link').forEach(function (link) {
                link.addEventListener('click', function () {
                    if (window.innerWidth <= 992) closeSidebar();
                });
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && sidebar.classList.contains('active')) closeSidebar();
            });

            let resizeTimer;
            window.addEventListener('resize', function () {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function () {
                    if (window.innerWidth > 992) closeSidebar();
                }, 150);
            });
        })();

        // User dropdown
        (function () {
            const navUser = document.getElementById('navUser');
            if (!navUser) return;
            navUser.addEventListener('click', function (e) {
                e.stopPropagation();
                navUser.classList.toggle('open');
            });
            document.addEventListener('click', function () {
                navUser.classList.remove('open');
            });
        })();
    </script>
</body>
</html>