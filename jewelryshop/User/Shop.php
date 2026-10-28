<?php
// user/shop.php — Luxury Shop Page
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/auth.php';

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
$resolved_theme = in_array($theme, ['cream', 'light', 'dark'], true) ? $theme : 'cream';

$db = null;
try {
    $database = new Database();
    $db = $database->getConnection();
} catch (Throwable $e) {
    $db = null;
}

$auth = $db ? new Auth($db) : null;

function safe_query($db, $sql, $params = []) {
    if (!$db) return [];
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

if (isset($_GET['live'])) {
    header('Content-Type: application/json; charset=utf-8');

    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') {
        echo json_encode(['success' => true, 'items' => [], 'total' => 0]);
        exit;
    }

    $cat = isset($_GET['category']) ? (int)$_GET['category'] : 0;

    $words = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY);
    if (!$words) $words = [$q];

    $whereParts = [];
    $params = [':startsWith' => $q . '%'];
    $i = 0;
    foreach ($words as $w) {
        $i++;
        $k1 = ':w' . $i . '_a';
        $k2 = ':w' . $i . '_b';
        $k3 = ':w' . $i . '_c';
        $whereParts[] = "(p.name LIKE $k1 OR p.description LIKE $k2 OR c.name LIKE $k3)";
        $params[$k1] = '%' . $w . '%';
        $params[$k2] = '%' . $w . '%';
        $params[$k3] = '%' . $w . '%';
    }
    $matchSql = implode(' AND ', $whereParts);

    // Hide products with 0 available
    $where = ["(p.stock - COALESCE(p.released_stock,0)) > 0", "($matchSql)"];
    if ($cat > 0) {
        $where[] = "p.category_id = :cat";
        $params[':cat'] = $cat;
    }
    $where_sql = 'WHERE ' . implode(' AND ', $where);

    $sql = "
        SELECT p.id, p.name, p.price, p.image, p.created_at, p.featured,
               p.stock,
               COALESCE(p.released_stock, 0) AS released_stock,
               c.name AS category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        $where_sql
        ORDER BY
            CASE WHEN p.name LIKE :startsWith THEN 0 ELSE 1 END,
            p.featured DESC,
            p.created_at DESC
        LIMIT 24
    ";

    $rows = safe_query($db, $sql, $params);

    $items = [];
    foreach ($rows as $r) {
        $stock    = (int)$r['stock'];
        $released = (int)$r['released_stock'];
        if ($released > $stock) $released = $stock;
        $available = max(0, $stock - $released);

        $items[] = [
            'id'         => (int)$r['id'],
            'name'       => (string)$r['name'],
            'price'      => number_format((float)$r['price'], 2),
            'image'      => (string)($r['image'] ?? ''),
            'category'   => (string)($r['category_name'] ?? 'Jewelry'),
            'stock'      => $stock,
            'released'   => $released,
            'available'  => $available,
            'created_at' => (string)($r['created_at'] ?? ''),
            'featured'   => (int)($r['featured'] ?? 0),
        ];
    }

    echo json_encode([
        'success' => true,
        'items'   => $items,
        'total'   => count($items),
        'query'   => $q,
    ]);
    exit;
}

$categories = safe_query($db, "
    SELECT c.*,
           (SELECT COUNT(*) FROM products
              WHERE category_id = c.id
                AND (stock - COALESCE(released_stock,0)) > 0) AS product_count
    FROM categories c
    ORDER BY c.name ASC
");

$price_rows = safe_query($db,
    "SELECT MIN(price) AS min_price, MAX(price) AS max_price
       FROM products
      WHERE (stock - COALESCE(released_stock,0)) > 0");
$price_range = [
    'min_price' => (float)($price_rows[0]['min_price'] ?? 0),
    'max_price' => (float)($price_rows[0]['max_price'] ?? 1000),
];
if ($price_range['max_price'] <= 0) $price_range['max_price'] = 1000;

$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$search          = isset($_GET['search'])   ? trim((string)$_GET['search']) : '';
$min_price       = isset($_GET['min_price']) ? (float)$_GET['min_price'] : $price_range['min_price'];
$max_price       = isset($_GET['max_price']) ? (float)$_GET['max_price'] : $price_range['max_price'];
$sort            = $_GET['sort'] ?? 'newest';
$page            = max(1, (int)($_GET['page'] ?? 1));
$limit           = 12;
$offset          = ($page - 1) * $limit;

$allowed_sorts = ['newest','price_low','price_high','name_asc','name_desc','popular','rating'];
if (!in_array($sort, $allowed_sorts, true)) $sort = 'newest';

// Only show products with available stock
$where  = ["(p.stock - COALESCE(p.released_stock,0)) > 0"];
$params = [];

if ($category_filter > 0) {
    $where[] = "p.category_id = :category";
    $params[':category'] = $category_filter;
}
if ($search !== '') {
    $where[] = "(p.name LIKE :search OR p.description LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}
if ($min_price > $price_range['min_price']) {
    $where[] = "p.price >= :min_price";
    $params[':min_price'] = $min_price;
}
if ($max_price < $price_range['max_price']) {
    $where[] = "p.price <= :max_price";
    $params[':max_price'] = $max_price;
}

$where_sql = "WHERE " . implode(" AND ", $where);

switch ($sort) {
    case 'price_low':  $order_sql = "ORDER BY p.price ASC"; break;
    case 'price_high': $order_sql = "ORDER BY p.price DESC"; break;
    case 'name_asc':   $order_sql = "ORDER BY p.name ASC"; break;
    case 'name_desc':  $order_sql = "ORDER BY p.name DESC"; break;
    case 'popular':    $order_sql = "ORDER BY p.id DESC"; break;
    case 'rating':     $order_sql = "ORDER BY p.featured DESC, p.id DESC"; break;
    default:           $order_sql = "ORDER BY p.created_at DESC"; break;
}

$count_rows = safe_query($db,
    "SELECT COUNT(*) AS total FROM products p $where_sql",
    $params);
$total_products = (int)($count_rows[0]['total'] ?? 0);
$total_pages    = max(1, (int)ceil($total_products / $limit));
if ($page > $total_pages) $page = $total_pages;

$products = [];
if ($db && $total_products > 0) {
    try {
        $sql = "
            SELECT p.*, c.name AS category_name,
                   COALESCE(p.released_stock, 0) AS released_stock
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            $where_sql
            $order_sql
            LIMIT :offset, :limit
        ";
        $stmt = $db->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $products = [];
    }
}

function build_query(array $overrides = []) {
    $base = [
        'category'  => $_GET['category']  ?? null,
        'search'    => $_GET['search']    ?? null,
        'min_price' => $_GET['min_price'] ?? null,
        'max_price' => $_GET['max_price'] ?? null,
        'sort'      => $_GET['sort']      ?? null,
        'page'      => $_GET['page']      ?? null,
    ];
    $merged = array_merge($base, $overrides);
    $merged = array_filter($merged, function ($v) { return $v !== null && $v !== ''; });
    return http_build_query($merged);
}

$active_filters = 0;
if ($category_filter > 0) $active_filters++;
if ($search !== '')       $active_filters++;
if ($min_price > $price_range['min_price']) $active_filters++;
if ($max_price < $price_range['max_price']) $active_filters++;

$page_title = 'Shop Collection | R-Jewel&Gold';
?>
<?php include '../includes/header.php'; ?>

<style>
    :root {
        --bg: #f2ede4;
        --bg-soft: #faf7f2;
        --card: #ffffff;
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
        --section-alt: #faf7f2;
    }
    <?php if ($resolved_theme === 'dark'): ?>
    :root {
        --bg: #151821;
        --bg-soft: #1c202b;
        --card: #222735;
        --border: #343b4d;
        --border-light: #2d3444;
        --text: #d5d9e2;
        --text-bright: #ffffff;
        --text-muted: #a0a8ba;
        --text-dim: #777f91;
        --section-alt: #1c202b;
    }
    <?php elseif ($resolved_theme === 'light'): ?>
    :root {
        --bg: #f5f7fb;
        --bg-soft: #ffffff;
        --card: #ffffff;
        --border: #e2e8f0;
        --border-light: #edf1f6;
        --text: #475569;
        --text-bright: #172033;
        --text-muted: #64748b;
        --text-dim: #94a3b8;
        --section-alt: #f5f7fb;
    }
    <?php endif; ?>

    body, h1, h2, h3, h4, h5, h6,
    .shop-hero h1, .filter-sidebar-head h5,
    .product-title, .product-price,
    .empty-state h3, .price-range-visual strong {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }
    body {
        background: var(--bg);
        color: var(--text);
        transition: background-color .3s ease, color .3s ease;
    }
    h1, h2, h3, h4, h5, h6 {
        color: var(--text-bright);
        font-weight: 600;
    }

    .shop-hero {
        position: relative;
        background: var(--bg);
        background-image:
            radial-gradient(ellipse at 20% 20%, rgba(184, 148, 31, 0.14), transparent 55%),
            radial-gradient(ellipse at 80% 80%, rgba(91, 95, 212, 0.08), transparent 55%);
        overflow: hidden;
        padding: 4rem 1.5rem 3.5rem;
        border-bottom: 1px solid var(--border-light);
        text-align: center;
    }
    .shop-hero::before {
        content: '';
        position: absolute;
        inset: 0;
        background-image:
            radial-gradient(1.5px 1.5px at 15% 25%, rgba(184, 148, 31, 0.35), transparent),
            radial-gradient(1px 1px at 45% 60%, rgba(138, 129, 114, 0.25), transparent),
            radial-gradient(1.5px 1.5px at 75% 15%, rgba(184, 148, 31, 0.3), transparent),
            radial-gradient(1px 1px at 85% 75%, rgba(138, 129, 114, 0.2), transparent);
        pointer-events: none;
    }
    .shop-hero > * { position: relative; z-index: 2; }

    .hero-diamond {
        width: 62px; height: 62px;
        margin: 0 auto 1rem;
        display: flex; align-items: center; justify-content: center;
    }
    .hero-diamond svg {
        width: 100%; height: 100%;
        filter: drop-shadow(0 6px 20px rgba(184, 148, 31, 0.4));
        animation: floatDiamond 6s ease-in-out infinite;
    }
    @keyframes floatDiamond {
        0%, 100% { transform: translateY(0); }
        50%      { transform: translateY(-8px); }
    }
    .lux-breadcrumb {
        display: flex;
        justify-content: center;
        gap: 8px;
        font-size: 0.7rem;
        letter-spacing: 0.2em;
        text-transform: uppercase;
        color: var(--text-dim);
        margin-bottom: 1rem;
        font-weight: 600;
    }
    .lux-breadcrumb a { color: var(--text-muted); text-decoration: none; transition: color .25s; }
    .lux-breadcrumb a:hover { color: var(--gold); }
    .lux-breadcrumb .sep { color: var(--text-dim); }
    .lux-breadcrumb .current { color: var(--gold); }

    .shop-hero .eyebrow {
        font-size: 0.68rem; font-weight: 700; letter-spacing: 0.4em;
        text-transform: uppercase; color: var(--gold); margin-bottom: 0.75rem;
    }
    .shop-hero h1 {
        font-size: clamp(1.9rem, 4.5vw, 2.75rem);
        margin-bottom: 0.75rem;
        line-height: 1.15;
        font-weight: 700;
        letter-spacing: -0.02em;
    }
    .shop-hero h1 em { color: var(--gold); font-style: normal; font-weight: 700; }
    .shop-hero p {
        color: var(--text-muted);
        max-width: 560px;
        margin: 0 auto;
        font-size: 0.95rem;
    }
    .hero-divider {
        display: flex; align-items: center; justify-content: center; gap: 12px;
        max-width: 200px; margin: 1.25rem auto 0;
    }
    .hero-divider .line {
        flex: 1; height: 1px;
        background: linear-gradient(90deg, transparent, rgba(184, 148, 31, 0.6), transparent);
    }
    .hero-divider .diamond {
        width: 6px; height: 6px; background: var(--gold);
        transform: rotate(45deg);
        box-shadow: 0 0 10px rgba(184, 148, 31, 0.7);
    }

    .btn-lux {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 0.7rem 1.4rem;
        border-radius: 9px;
        border: none;
        font-family: 'Inter', sans-serif;
        font-size: 0.72rem;
        font-weight: 600;
        letter-spacing: 0.2em;
        text-transform: uppercase;
        text-decoration: none;
        cursor: pointer;
        transition: all 0.3s ease;
        white-space: nowrap;
    }
    .btn-lux-gold {
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        color: #fff;
        box-shadow: 0 4px 14px -3px rgba(184, 148, 31, 0.45);
    }
    .btn-lux-gold:hover { color:#fff; transform: translateY(-2px); box-shadow: 0 8px 22px -5px rgba(184,148,31,.6); }
    .btn-lux-outline {
        background: transparent;
        color: var(--text-bright);
        border: 1px solid var(--border);
    }
    .btn-lux-outline:hover { border-color: var(--gold); color: var(--gold); transform: translateY(-2px); }

    .shop-container { padding: 3.5rem 0 5rem; }

    .filter-sidebar {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 14px;
        padding: 1.5rem;
        position: sticky;
        top: 20px;
        transition: border-color 0.3s ease;
    }
    .filter-sidebar:hover { border-color: rgba(184, 148, 31, 0.35); }

    .filter-sidebar-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 1.25rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid var(--border-light);
    }
    .filter-sidebar-head h5 {
        font-size: 1.15rem;
        font-weight: 700;
        margin: 0;
        letter-spacing: -0.01em;
    }
    .filter-count-badge {
        font-size: 0.65rem;
        font-weight: 700;
        letter-spacing: 0.15em;
        text-transform: uppercase;
        padding: 3px 10px;
        border-radius: 20px;
        background: var(--gold-soft);
        color: var(--gold-strong);
        border: 1px solid rgba(184, 148, 31, 0.3);
    }

    .filter-section {
        margin-bottom: 1.5rem;
        padding-bottom: 1.25rem;
        border-bottom: 1px solid var(--border-light);
    }
    .filter-section:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }

    .filter-title {
        display: flex;
        justify-content: space-between;
        align-items: center;
        cursor: pointer;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.15em;
        text-transform: uppercase;
        color: var(--text-bright);
        user-select: none;
        padding: 0.2rem 0;
    }
    .filter-title i {
        color: var(--gold);
        transition: transform 0.3s ease;
        font-size: 0.75rem;
    }
    .filter-title.collapsed i { transform: rotate(-90deg); }
    .filter-content { margin-top: 0.85rem; }
    .filter-content.hidden { display: none; }

    .category-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .category-list li { margin-bottom: 0.25rem; }
    .category-list a {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.55rem 0.75rem;
        border-radius: 8px;
        color: var(--text-muted);
        text-decoration: none;
        font-size: 0.85rem;
        transition: all 0.25s ease;
        border: 1px solid transparent;
    }
    .category-list a:hover {
        background: var(--gold-soft);
        color: var(--gold);
        border-color: rgba(184, 148, 31, 0.2);
        transform: translateX(3px);
    }
    .category-list a.active {
        background: var(--gold-soft);
        color: var(--gold);
        border-color: rgba(184, 148, 31, 0.4);
        font-weight: 600;
    }
    .category-count {
        font-size: 0.68rem;
        font-weight: 700;
        padding: 2px 8px;
        border-radius: 20px;
        background: var(--bg-soft);
        color: var(--text-muted);
        border: 1px solid var(--border);
    }
    .category-list a.active .category-count,
    .category-list a:hover .category-count {
        background: var(--card);
        color: var(--gold-strong);
        border-color: rgba(184, 148, 31, 0.3);
    }

    .price-range-visual {
        display: flex;
        justify-content: space-between;
        font-size: 0.78rem;
        color: var(--text-muted);
        margin-bottom: 0.5rem;
    }
    .price-range-visual strong {
        font-size: 0.9rem;
        color: var(--gold);
        font-weight: 700;
    }
    input[type="range"].lux-slider {
        width: 100%;
        -webkit-appearance: none;
        height: 4px;
        border-radius: 4px;
        background: var(--border);
        outline: none;
        margin: 1rem 0;
    }
    input[type="range"].lux-slider::-webkit-slider-thumb {
        -webkit-appearance: none;
        width: 18px; height: 18px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        cursor: pointer;
        box-shadow: 0 2px 8px rgba(184, 148, 31, 0.5);
        transition: transform 0.2s ease;
    }
    input[type="range"].lux-slider::-webkit-slider-thumb:hover { transform: scale(1.15); }
    input[type="range"].lux-slider::-moz-range-thumb {
        width: 18px; height: 18px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        cursor: pointer;
        border: none;
        box-shadow: 0 2px 8px rgba(184, 148, 31, 0.5);
    }

    .price-inputs {
        display: grid;
        grid-template-columns: 1fr auto 1fr;
        gap: 8px;
        align-items: center;
        margin-bottom: 0.75rem;
    }
    .price-inputs input {
        width: 100%;
        padding: 0.6rem;
        border: 1px solid var(--border);
        border-radius: 8px;
        background: var(--bg-soft);
        color: var(--text-bright);
        text-align: center;
        font-family: 'Inter', sans-serif;
        font-size: 0.82rem;
    }
    .price-inputs input:focus {
        outline: none;
        border-color: var(--gold);
        box-shadow: 0 0 0 3px rgba(184, 148, 31, 0.12);
    }
    .price-inputs .sep { color: var(--text-dim); }

    .shop-toolbar {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 1.5rem;
        flex-wrap: wrap;
    }
    .search-wrap {
        position: relative;
        flex: 1 1 320px;
        min-width: 0;
    }
    .search-group {
        display: flex;
        align-items: center;
        border: 1px solid var(--border);
        border-radius: 12px;
        background: var(--card);
        transition: border-color .2s ease, box-shadow .2s ease;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(90, 82, 69, 0.04);
    }
    .search-wrap.is-active .search-group {
        border-color: var(--gold);
        box-shadow: 0 0 0 4px rgba(184, 148, 31, 0.12);
    }
    .search-group .search-icon {
        padding: 0 4px 0 14px;
        color: var(--text-dim);
        font-size: 0.9rem;
        pointer-events: none;
    }
    .search-group input {
        flex: 1;
        border: none;
        background: transparent;
        padding: 0.85rem 0.9rem;
        color: var(--text-bright);
        font-family: 'Inter', sans-serif;
        font-size: 0.9rem;
        min-width: 0;
    }
    .search-group input:focus { outline: none; }
    .search-group input::placeholder { color: var(--text-dim); }
    .search-group .clear-btn {
        display: none;
        border: none;
        background: transparent;
        color: var(--text-dim);
        padding: 0 10px;
        cursor: pointer;
        font-size: 0.9rem;
        transition: color .2s ease;
    }
    .search-group .clear-btn:hover { color: var(--danger); }
    .search-wrap.has-text .clear-btn { display: inline-flex; align-items: center; }
    .search-group .submit-btn {
        border: none;
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        color: #fff;
        padding: 0.85rem 1.2rem;
        cursor: pointer;
        transition: filter 0.2s ease, transform 0.15s ease;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        font-family: 'Inter', sans-serif;
        font-weight: 700;
        font-size: 0.72rem;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        white-space: nowrap;
    }
    .search-group .submit-btn:hover { filter: brightness(1.08); }
    .search-group .submit-btn:active { transform: translateY(1px); }

    .shop-toolbar .results-count {
        font-size: 0.88rem;
        color: var(--text-muted);
        white-space: nowrap;
        display: inline-flex;
        align-items: center;
    }
    .shop-toolbar .results-count strong {
        color: var(--gold);
        font-weight: 700;
        margin: 0 3px;
    }
    .sort-select {
        padding: 0.7rem 2.4rem 0.7rem 1rem;
        border: 1px solid var(--border);
        border-radius: 10px;
        background: var(--card);
        color: var(--text-bright);
        font-family: 'Inter', sans-serif;
        font-size: 0.82rem;
        cursor: pointer;
        appearance: none;
        background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23b8941f' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
        background-repeat: no-repeat;
        background-position: right 0.85rem center;
        background-size: 14px;
        transition: border-color 0.25s ease;
        white-space: nowrap;
    }
    .sort-select:focus { outline: none; border-color: var(--gold); }

    @media (max-width: 768px) {
        .shop-toolbar { flex-direction: column; align-items: stretch; }
        .search-wrap { flex: 1 1 auto; }
        .shop-toolbar .results-count,
        .sort-select { width: 100%; }
    }
    @media (max-width: 480px) {
        .search-group .submit-btn span { display: none; }
        .search-group .submit-btn { padding: 0.85rem 1rem; }
    }

    .grid-shell { position: relative; min-height: 100px; }
    .grid-shell.is-fetching { opacity: 0.6; transition: opacity .15s ease; }

    .product-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 14px;
        overflow: hidden;
        transition: all 0.35s ease;
        height: 100%;
        display: flex;
        flex-direction: column;
    }
    .product-card:hover {
        transform: translateY(-8px);
        border-color: var(--gold);
        box-shadow: 0 20px 40px -18px rgba(90, 82, 69, 0.25);
    }
    .product-image-wrap {
        position: relative;
        overflow: hidden;
        background: var(--bg-soft);
        aspect-ratio: 4 / 3;
    }
    .product-image-wrap img {
        width: 100%; height: 100%;
        object-fit: cover;
        display: block;
        transition: transform 0.6s ease;
    }
    .product-card:hover .product-image-wrap img { transform: scale(1.08); }

    .product-badges {
        position: absolute;
        top: 12px;
        left: 12px;
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
        z-index: 2;
    }
    .badge-lux {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.6rem;
        font-weight: 700;
        letter-spacing: 0.15em;
        text-transform: uppercase;
        color: #fff;
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }
    .badge-new { background: linear-gradient(135deg, var(--success), #0a7d56); }
    .badge-out { background: linear-gradient(135deg, #6b7280, #4b5563); }

    .product-actions {
        position: absolute;
        left: 0; right: 0; bottom: 0;
        padding: 12px;
        display: flex;
        gap: 8px;
        justify-content: center;
        background: linear-gradient(transparent, rgba(20, 18, 15, 0.75));
        opacity: 0;
        transform: translateY(10px);
        transition: all 0.35s ease;
        z-index: 2;
    }
    .product-card:hover .product-actions {
        opacity: 1;
        transform: translateY(0);
    }
    .action-btn {
        padding: 0.5rem 1rem;
        border-radius: 8px;
        border: none;
        font-family: 'Inter', sans-serif;
        font-size: 0.62rem;
        font-weight: 700;
        letter-spacing: 0.15em;
        text-transform: uppercase;
        cursor: pointer;
        transition: all 0.25s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .action-btn-gold {
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        color: #fff;
        box-shadow: 0 3px 10px -2px rgba(184, 148, 31, 0.5);
    }
    .action-btn-gold:hover { filter: brightness(1.08); }
    .action-btn-light {
        background: rgba(255, 255, 255, 0.92);
        color: #2b2620;
        border: 1px solid rgba(255, 255, 255, 0.3);
    }
    .action-btn-light:hover { background: #fff; }

    .product-info {
        padding: 1.1rem 1.15rem 1.25rem;
        flex: 1;
        display: flex;
        flex-direction: column;
    }
    .product-category {
        font-size: 0.62rem;
        font-weight: 700;
        letter-spacing: 0.2em;
        text-transform: uppercase;
        color: var(--gold);
        margin-bottom: 0.35rem;
    }
    .product-title {
        font-size: 1.05rem;
        font-weight: 600;
        margin: 0 0 0.6rem;
        line-height: 1.35;
        letter-spacing: -0.01em;
    }
    .product-title a {
        color: var(--text-bright);
        text-decoration: none;
        transition: color 0.25s ease;
    }
    .product-title a:hover { color: var(--gold); }
    .product-title mark {
        background: rgba(184, 148, 31, 0.25);
        color: var(--gold-strong);
        padding: 0 3px;
        border-radius: 3px;
        font-weight: 700;
    }

    .product-footer {
        margin-top: auto;
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 8px;
        padding-top: 0.5rem;
    }
    .product-price {
        font-size: 1.15rem;
        font-weight: 700;
        color: var(--gold);
        line-height: 1.2;
        letter-spacing: 0;
    }

    .stock-note {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.03em;
        background: rgba(13, 150, 104, 0.1);
        color: #0a7a54;
        border: 1px solid rgba(13, 150, 104, 0.25);
        white-space: nowrap;
    }
    .stock-note i { font-size: 0.62rem; opacity: 0.85; }

    .empty-state {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 14px;
        text-align: center;
        padding: 4rem 2rem;
    }
    .empty-state .icon-wrap {
        width: 100px; height: 100px;
        border-radius: 50%;
        background: var(--gold-soft);
        border: 1px solid rgba(184, 148, 31, 0.25);
        color: var(--gold);
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1.25rem;
        font-size: 2rem;
    }
    .empty-state h3 {
        font-size: 1.5rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
        letter-spacing: -0.01em;
    }
    .empty-state h3 em { color: var(--gold); font-style: normal; font-weight: 700; }
    .empty-state p { color: var(--text-muted); margin-bottom: 1.5rem; font-size: 0.95rem; }

    .lux-pagination {
        display: flex;
        justify-content: center;
        gap: 6px;
        margin-top: 3rem;
        flex-wrap: wrap;
    }
    .lux-pagination a,
    .lux-pagination span {
        min-width: 40px;
        height: 40px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0 0.75rem;
        border-radius: 10px;
        border: 1px solid var(--border);
        background: var(--card);
        color: var(--text-bright);
        text-decoration: none;
        font-family: 'Inter', sans-serif;
        font-size: 0.85rem;
        font-weight: 600;
        transition: all 0.25s ease;
    }
    .lux-pagination a:hover {
        border-color: var(--gold);
        color: var(--gold);
        transform: translateY(-2px);
    }
    .lux-pagination .current {
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        border-color: transparent;
        color: #fff;
        box-shadow: 0 4px 14px -3px rgba(184, 148, 31, 0.5);
    }
    .lux-pagination .dots {
        border: none;
        background: transparent;
        color: var(--text-dim);
    }

    .toast-notification {
        position: fixed;
        bottom: 24px; right: 24px;
        z-index: 9999;
        animation: slideInRight 0.35s ease-out;
        border-radius: 10px;
        overflow: hidden;
        box-shadow: 0 12px 30px rgba(0,0,0,0.15);
        background: var(--card);
        border: 1px solid var(--border);
        min-width: 260px;
    }
    .toast-notification .toast-body {
        padding: 0.9rem 1.1rem;
        font-size: 0.85rem;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .toast-notification.success .toast-body { color: var(--success); }
    .toast-notification.error   .toast-body { color: var(--danger); }
    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to   { transform: translateX(0); opacity: 1; }
    }

    @media (max-width: 991px) {
        .filter-sidebar { position: static; margin-bottom: 1.5rem; }
    }
    @media (max-width: 768px) {
        .shop-hero { padding: 3rem 1rem 2.5rem; }
        .shop-container { padding: 2.5rem 0 3.5rem; }
    }
    @media (max-width: 480px) {
        .product-title { font-size: 0.98rem; }
        .product-price { font-size: 1rem; }
        .lux-pagination a, .lux-pagination span { min-width: 34px; height: 34px; font-size: 0.78rem; }
    }
    @media (prefers-reduced-motion: reduce) {
        * { animation-duration: .01ms !important; transition-duration: .01ms !important; }
    }
</style>

<section class="shop-hero">
    <div class="hero-diamond">
        <svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M50 5 L90 35 L50 95 L10 35 Z" stroke="#b8941f" stroke-width="1.8" fill="none" opacity="0.95"/>
            <path d="M50 5 L90 35 L50 35 Z" fill="#b8941f" opacity="0.18"/>
            <path d="M50 5 L10 35 L50 35 Z" fill="#b8941f" opacity="0.1"/>
            <path d="M10 35 L50 95 L50 35 Z" fill="#b8941f" opacity="0.06"/>
            <path d="M90 35 L50 95 L50 35 Z" fill="#b8941f" opacity="0.12"/>
            <path d="M10 35 L90 35" stroke="#b8941f" stroke-width="1.2" opacity="0.75"/>
            <circle cx="50" cy="5" r="2.2" fill="#b8941f"/>
            <circle cx="90" cy="35" r="1.6" fill="#b8941f" opacity="0.85"/>
            <circle cx="10" cy="35" r="1.6" fill="#b8941f" opacity="0.85"/>
            <circle cx="50" cy="95" r="2.2" fill="#b8941f"/>
        </svg>
    </div>
    <nav class="lux-breadcrumb">
        <a href="index.php">Home</a>
        <span class="sep">·</span>
        <span class="current">Shop</span>
    </nav>
    <p class="eyebrow">R-Jewel&Gold · Collection</p>
    <h1>Our <em>Collection</em></h1>
    <p>Discover timeless elegance with our exquisite jewelry collection</p>
    <div class="hero-divider">
        <span class="line"></span>
        <span class="diamond"></span>
        <span class="line"></span>
    </div>
</section>

<div class="shop-container">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-3">
                <aside class="filter-sidebar">
                    <div class="filter-sidebar-head">
                        <h5>Filters</h5>
                        <?php if ($active_filters > 0): ?>
                            <span class="filter-count-badge"><?php echo $active_filters; ?> active</span>
                        <?php endif; ?>
                    </div>

                    <div class="filter-section">
                        <div class="filter-title" data-filter-toggle="categoryFilter">
                            <span>Categories</span>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="filter-content" id="categoryFilter">
                            <ul class="category-list">
                                <?php
                                $total_count = 0;
                                foreach ($categories as $c) $total_count += (int)$c['product_count'];
                                ?>
                                <li>
                                    <a href="shop.php<?php echo $search ? '?search=' . urlencode($search) : ''; ?>"
                                       class="<?php echo $category_filter === 0 ? 'active' : ''; ?>">
                                        All Categories
                                        <span class="category-count"><?php echo $total_count; ?></span>
                                    </a>
                                </li>
                                <?php foreach ($categories as $cat): ?>
                                <li>
                                    <?php
                                    $qs = ['category' => (int)$cat['id']];
                                    if ($search !== '') $qs['search'] = $search;
                                    ?>
                                    <a href="shop.php?<?php echo http_build_query($qs); ?>"
                                       class="<?php echo $category_filter === (int)$cat['id'] ? 'active' : ''; ?>">
                                        <?php echo htmlspecialchars($cat['name']); ?>
                                        <span class="category-count"><?php echo (int)$cat['product_count']; ?></span>
                                    </a>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>

                    <div class="filter-section">
                        <div class="filter-title" data-filter-toggle="priceFilter">
                            <span>Price Range</span>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="filter-content" id="priceFilter">
                            <div class="price-range-visual">
                                <span>₱<strong id="priceMinLabel"><?php echo number_format($min_price, 0); ?></strong></span>
                                <span>₱<strong id="priceMaxLabel"><?php echo number_format($max_price, 0); ?></strong></span>
                            </div>

                            <input type="range"
                                   class="lux-slider"
                                   id="priceSlider"
                                   min="<?php echo (int)$price_range['min_price']; ?>"
                                   max="<?php echo (int)$price_range['max_price']; ?>"
                                   step="10"
                                   value="<?php echo (int)$max_price; ?>">

                            <div class="price-inputs">
                                <input type="number" id="minPriceInput" placeholder="Min"
                                       value="<?php echo (int)$min_price; ?>">
                                <span class="sep">–</span>
                                <input type="number" id="maxPriceInput" placeholder="Max"
                                       value="<?php echo (int)$max_price; ?>">
                            </div>

                            <button type="button" class="btn-lux btn-lux-gold w-100" id="applyPriceFilter">
                                <i class="fas fa-check"></i> Apply Price
                            </button>
                        </div>
                    </div>

                    <?php if ($active_filters > 0): ?>
                        <a href="shop.php" class="btn-lux btn-lux-outline w-100 mt-3">
                            <i class="fas fa-times"></i> Clear All
                        </a>
                    <?php endif; ?>
                </aside>
            </div>

            <div class="col-lg-9">
                <div class="shop-toolbar">
                    <div class="search-wrap <?php echo $search !== '' ? 'has-text' : ''; ?>" id="searchWrap">
                        <div class="search-group">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text"
                                   id="searchInput"
                                   placeholder="Type to search — the products below filter instantly"
                                   value="<?php echo htmlspecialchars($search); ?>"
                                   autocomplete="off"
                                   spellcheck="false"
                                   aria-label="Search products">
                            <button type="button" class="clear-btn" id="searchClear" aria-label="Clear search">
                                <i class="fas fa-times"></i>
                            </button>
                            <button type="button" class="submit-btn" id="searchSubmitBtn" aria-label="Search">
                                <i class="fas fa-search"></i> <span>Search</span>
                            </button>
                        </div>
                    </div>

                    <div class="results-count">
                        <i class="fas fa-gem" style="color:var(--gold);"></i>
                        <strong id="resultCount"><?php echo $total_products; ?></strong>
                        <span id="resultWord"><?php echo $total_products === 1 ? 'piece' : 'pieces'; ?></span>
                    </div>

                    <select class="sort-select" id="sortSelect">
                        <option value="newest"     <?php echo $sort === 'newest'     ? 'selected' : ''; ?>>Newest First</option>
                        <option value="price_low"  <?php echo $sort === 'price_low'  ? 'selected' : ''; ?>>Price · Low to High</option>
                        <option value="price_high" <?php echo $sort === 'price_high' ? 'selected' : ''; ?>>Price · High to Low</option>
                        <option value="name_asc"   <?php echo $sort === 'name_asc'   ? 'selected' : ''; ?>>Name · A to Z</option>
                        <option value="name_desc"  <?php echo $sort === 'name_desc'  ? 'selected' : ''; ?>>Name · Z to A</option>
                        <option value="popular"    <?php echo $sort === 'popular'    ? 'selected' : ''; ?>>Most Popular</option>
                        <option value="rating"     <?php echo $sort === 'rating'     ? 'selected' : ''; ?>>Top Rated</option>
                    </select>
                </div>

                <div class="grid-shell" id="gridShell">
                    <?php if (empty($products)): ?>
                        <div class="empty-state">
                            <div class="icon-wrap"><i class="fas fa-search"></i></div>
                            <h3>No <em>pieces</em> found</h3>
                            <p>Try adjusting your filters or search terms.</p>
                            <a href="shop.php" class="btn-lux btn-lux-gold">
                                <i class="fas fa-redo"></i> Reset Filters
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="row g-4 products-grid" id="productsGrid">
                            <?php foreach ($products as $product):
                                $is_new    = !empty($product['created_at']) && (strtotime($product['created_at']) > strtotime('-30 days'));

                                // Stock model:
                                //   stock          = total monthly stock
                                //   released_stock = already sold / released (DISPLAYED in the badge)
                                //   available      = stock - released_stock (controls Add button + Sold Out badge)
                                $total_stock    = (int)($product['stock'] ?? 0);
                                $released_stock = (int)($product['released_stock'] ?? 0);
                                if ($released_stock > $total_stock) $released_stock = $total_stock;

                                $available_stock = max(0, $total_stock - $released_stock);
                                $is_out = $available_stock <= 0;
                            ?>
                            <div class="col-md-6 col-lg-4 product-item">
                                <div class="product-card">
                                    <div class="product-image-wrap">
                                        <button type="button"
                                                class="wish-heart"
                                                data-product-id="<?php echo (int)$product['id']; ?>"
                                                aria-label="Save to wishlist"
                                                onclick="toggleWishlist(<?php echo (int)$product['id']; ?>, this)">
                                            <i class="fas fa-heart"></i>
                                        </button>

                                        <img src="../uploads/<?php echo htmlspecialchars($product['image'] ?? 'placeholder.jpg'); ?>"
                                             onerror="this.onerror=null;this.src='https://via.placeholder.com/400x300/ede7db/8a8172?text=Jewelry'"
                                             alt="<?php echo htmlspecialchars($product['name']); ?>"
                                             loading="lazy">

                                        <div class="product-badges">
                                            <?php if ($is_new): ?>
                                                <span class="badge-lux badge-new">New</span>
                                            <?php endif; ?>
                                            <?php if ($is_out): ?>
                                                <span class="badge-lux badge-out">Sold Out</span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="product-actions">
                                            <button type="button"
                                                    class="action-btn action-btn-light"
                                                    onclick="quickView(<?php echo (int)$product['id']; ?>)"
                                                    title="Quick view">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                            <?php if (!$is_out): ?>
                                                <button type="button"
                                                        class="action-btn action-btn-gold"
                                                        onclick="addToCart(<?php echo (int)$product['id']; ?>)"
                                                        title="Add to cart">
                                                    <i class="fas fa-shopping-bag"></i> Add
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="product-info">
                                        <div class="product-category">
                                            <?php echo htmlspecialchars($product['category_name'] ?? 'Jewelry'); ?>
                                        </div>

                                        <h5 class="product-title">
                                            <a href="product-details.php?id=<?php echo (int)$product['id']; ?>">
                                                <?php echo htmlspecialchars($product['name']); ?>
                                            </a>
                                        </h5>

                                        <div class="product-footer">
                                            <span class="product-price">₱<?php echo number_format((float)$product['price'], 2); ?></span>
                                            <span class="stock-note">
                                                <i class="fas fa-check-circle"></i> Stock <?php echo $released_stock; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($total_pages > 1 && $search === ''): ?>
                            <nav class="lux-pagination" aria-label="Pagination" id="paginationNav">
                                <?php if ($page > 1): ?>
                                    <a href="shop.php?<?php echo build_query(['page' => $page - 1]); ?>" aria-label="Previous">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                <?php endif; ?>

                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page   = min($total_pages, $page + 2);
                                ?>

                                <?php if ($start_page > 1): ?>
                                    <a href="shop.php?<?php echo build_query(['page' => 1]); ?>">1</a>
                                    <?php if ($start_page > 2): ?>
                                        <span class="dots">…</span>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                    <?php if ($i === $page): ?>
                                        <span class="current"><?php echo $i; ?></span>
                                    <?php else: ?>
                                        <a href="shop.php?<?php echo build_query(['page' => $i]); ?>"><?php echo $i; ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>

                                <?php if ($end_page < $total_pages): ?>
                                    <?php if ($end_page < $total_pages - 1): ?>
                                        <span class="dots">…</span>
                                    <?php endif; ?>
                                    <a href="shop.php?<?php echo build_query(['page' => $total_pages]); ?>"><?php echo $total_pages; ?></a>
                                <?php endif; ?>

                                <?php if ($page < $total_pages): ?>
                                    <a href="shop.php?<?php echo build_query(['page' => $page + 1]); ?>" aria-label="Next">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                <?php endif; ?>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    function showNotification(message, type) {
        type = type || 'success';
        var icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        var el = document.createElement('div');
        el.className = 'toast-notification ' + type;
        var body = document.createElement('div');
        body.className = 'toast-body';
        var i = document.createElement('i');
        i.className = 'fas ' + icon;
        var span = document.createElement('span');
        span.textContent = message;
        body.appendChild(i); body.appendChild(span);
        el.appendChild(body);
        document.body.appendChild(el);
        setTimeout(function () {
            el.style.transition = 'opacity .3s ease';
            el.style.opacity = '0';
            setTimeout(function () { el.remove(); }, 300);
        }, 3000);
    }

    document.querySelectorAll('[data-filter-toggle]').forEach(function (title) {
        title.addEventListener('click', function () {
            var id = this.getAttribute('data-filter-toggle');
            var content = document.getElementById(id);
            if (!content) return;
            content.classList.toggle('hidden');
            this.classList.toggle('collapsed');
        });
    });

    var sortSelect = document.getElementById('sortSelect');
    if (sortSelect) {
        sortSelect.addEventListener('change', function () {
            var url = new URL(window.location.href);
            url.searchParams.set('sort', this.value);
            url.searchParams.set('page', 1);
            window.location.href = url.toString();
        });
    }

    var slider   = document.getElementById('priceSlider');
    var minInput = document.getElementById('minPriceInput');
    var maxInput = document.getElementById('maxPriceInput');
    var minLabel = document.getElementById('priceMinLabel');
    var maxLabel = document.getElementById('priceMaxLabel');
    var applyBtn = document.getElementById('applyPriceFilter');

    if (slider && maxInput && maxLabel) {
        slider.addEventListener('input', function () {
            maxInput.value = this.value;
            maxLabel.textContent = Number(this.value).toLocaleString();
        });
    }
    if (minInput && minLabel) {
        minInput.addEventListener('input', function () {
            minLabel.textContent = Number(this.value || 0).toLocaleString();
        });
    }
    if (maxInput && maxLabel) {
        maxInput.addEventListener('input', function () {
            maxLabel.textContent = Number(this.value || 0).toLocaleString();
            if (slider) slider.value = this.value;
        });
    }
    if (applyBtn) {
        applyBtn.addEventListener('click', function () {
            var url = new URL(window.location.href);
            var min = parseFloat(minInput.value);
            var max = parseFloat(maxInput.value);
            if (!isNaN(min)) url.searchParams.set('min_price', min);
            if (!isNaN(max)) url.searchParams.set('max_price', max);
            url.searchParams.set('page', 1);
            window.location.href = url.toString();
        });
    }

    (function liveSearchGrid() {
        var input    = document.getElementById('searchInput');
        var wrap     = document.getElementById('searchWrap');
        var shell    = document.getElementById('gridShell');
        var clear    = document.getElementById('searchClear');
        var submitBtn= document.getElementById('searchSubmitBtn');
        var countEl  = document.getElementById('resultCount');
        var wordEl   = document.getElementById('resultWord');

        if (!input || !wrap || !shell) return;

        var urlParams = new URLSearchParams(window.location.search);
        var categoryId = urlParams.get('category') || '';

        var initialHTML  = shell.innerHTML;
        var initialCount = countEl ? countEl.textContent : '';

        var DEBOUNCE_MS = 180;
        var debounceTimer = null;
        var currentReq = null;

        function esc(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function highlight(text, q) {
            var safeText = esc(text);
            if (!q) return safeText;
            var safeQ = esc(q).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            try {
                var re = new RegExp('(' + safeQ + ')', 'ig');
                return safeText.replace(re, '<mark>$1</mark>');
            } catch (e) {
                return safeText;
            }
        }

        function updateClearBtn() {
            if (input.value.trim().length > 0) wrap.classList.add('has-text');
            else wrap.classList.remove('has-text');
        }

        function setFetching(on) {
            if (on) shell.classList.add('is-fetching');
            else    shell.classList.remove('is-fetching');
        }

        function updateCount(n) {
            if (countEl) countEl.textContent = n;
            if (wordEl)  wordEl.textContent  = (Number(n) === 1 ? 'piece' : 'pieces');
        }

        function cardHTML(it, q) {
            var imgSrc = it.image
                ? ('../uploads/' + esc(it.image))
                : 'https://via.placeholder.com/400x300/ede7db/8a8172?text=Jewelry';

            var isNew = false;
            if (it.created_at) {
                try {
                    var d = new Date(it.created_at.replace(' ', 'T'));
                    var diff = (Date.now() - d.getTime()) / 1000;
                    isNew = diff < (30 * 24 * 3600);
                } catch (e) { isNew = false; }
            }

            // Badge label = released (sold count).
            // Sold-Out badge + Add button = driven by available.
            var released  = Math.max(0, Number(it.released)  | 0);
            var available = Math.max(0, Number(it.available) | 0);
            var isOut = available <= 0;

            return '' +
            '<div class="col-md-6 col-lg-4 product-item">' +
                '<div class="product-card">' +
                    '<div class="product-image-wrap">' +
                        '<button type="button" class="wish-heart" ' +
                                'data-product-id="' + it.id + '" aria-label="Save to wishlist" ' +
                                'onclick="toggleWishlist(' + it.id + ', this)">' +
                            '<i class="fas fa-heart"></i>' +
                        '</button>' +
                        '<img src="' + imgSrc + '" ' +
                             'onerror="this.onerror=null;this.src=\'https://via.placeholder.com/400x300/ede7db/8a8172?text=Jewelry\'" ' +
                             'alt="' + esc(it.name) + '" loading="lazy">' +
                        '<div class="product-badges">' +
                            (isNew ? '<span class="badge-lux badge-new">New</span>' : '') +
                            (isOut ? '<span class="badge-lux badge-out">Sold Out</span>' : '') +
                        '</div>' +
                        '<div class="product-actions">' +
                            '<button type="button" class="action-btn action-btn-light" ' +
                                    'onclick="quickView(' + it.id + ')" title="Quick view">' +
                                '<i class="fas fa-eye"></i> View' +
                            '</button>' +
                            (!isOut ?
                                '<button type="button" class="action-btn action-btn-gold" ' +
                                        'onclick="addToCart(' + it.id + ')" title="Add to cart">' +
                                    '<i class="fas fa-shopping-bag"></i> Add' +
                                '</button>'
                            : '') +
                        '</div>' +
                    '</div>' +
                    '<div class="product-info">' +
                        '<div class="product-category">' + esc(it.category) + '</div>' +
                        '<h5 class="product-title">' +
                            '<a href="product-details.php?id=' + it.id + '">' +
                                highlight(it.name, q) +
                            '</a>' +
                        '</h5>' +
                        '<div class="product-footer">' +
                            '<span class="product-price">₱' + esc(it.price) + '</span>' +
                            '<span class="stock-note">' +
                                '<i class="fas fa-check-circle"></i> Stock ' + released +
                            '</span>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>';
        }

        function renderResults(items, q) {
            if (!items.length) {
                shell.innerHTML =
                    '<div class="empty-state">' +
                        '<div class="icon-wrap"><i class="fas fa-search"></i></div>' +
                        '<h3>No <em>pieces</em> found</h3>' +
                        '<p>No products match "<strong>' + esc(q) + '</strong>".</p>' +
                        '<button type="button" class="btn-lux btn-lux-gold" id="resetFromEmpty">' +
                            '<i class="fas fa-redo"></i> Reset Search' +
                        '</button>' +
                    '</div>';
                var r = document.getElementById('resetFromEmpty');
                if (r) r.addEventListener('click', function () {
                    input.value = '';
                    updateClearBtn();
                    renderInitial();
                    input.focus();
                });
                updateCount(0);
                return;
            }

            var html = '<div class="row g-4 products-grid" id="productsGrid">';
            items.forEach(function (it) { html += cardHTML(it, q); });
            html += '</div>';

            shell.innerHTML = html;
            updateCount(items.length);

            if (typeof window.refreshWishlist === 'function') window.refreshWishlist();
        }

        function renderInitial() {
            shell.innerHTML = initialHTML;
            updateCount(initialCount);
            if (typeof window.refreshWishlist === 'function') window.refreshWishlist();
        }

        function fetchLive(q) {
            if (currentReq) { try { currentReq.abort(); } catch (e) {} }
            currentReq = ('AbortController' in window) ? new AbortController() : null;

            setFetching(true);

            var url = 'shop.php?live=1&q=' + encodeURIComponent(q);
            if (categoryId) url += '&category=' + encodeURIComponent(categoryId);

            var opts = { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
            if (currentReq) opts.signal = currentReq.signal;

            fetch(url, opts)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    setFetching(false);
                    if (!data || !data.success) { renderResults([], q); return; }
                    renderResults(data.items || [], q);
                })
                .catch(function (err) {
                    if (err && err.name === 'AbortError') return;
                    setFetching(false);
                });
        }

        function performFullSearch() {
            var q = input.value.trim();

            var url = new URL(window.location.href);
            if (q !== '') url.searchParams.set('search', q);
            else          url.searchParams.delete('search');
            url.searchParams.set('page', 1);
            history.replaceState({ search: q }, '', url.toString());

            if (q === '') { renderInitial(); return; }
            fetchLive(q);
        }

        input.addEventListener('input', function () {
            updateClearBtn();
            var q = input.value.trim();
            clearTimeout(debounceTimer);

            if (q.length < 1) { renderInitial(); return; }

            debounceTimer = setTimeout(function () { fetchLive(q); }, DEBOUNCE_MS);
        });

        if (submitBtn) {
            submitBtn.addEventListener('click', function (e) {
                e.preventDefault();
                performFullSearch();
            });
        }

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                performFullSearch();
            }
        });

        if (clear) {
            clear.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                input.value = '';
                updateClearBtn();
                renderInitial();

                var url = new URL(window.location.href);
                url.searchParams.delete('search');
                history.replaceState({}, '', url.toString());

                input.focus();
            });
        }

        updateClearBtn();
    })();

    window.addToCart = function (productId) {
        var body = new URLSearchParams();
        body.append('product_id', productId);
        body.append('quantity', 1);

        fetch('add-to-cart.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function (r) { return r.text(); })
        .then(function (txt) {
            var res = null;
            try { res = JSON.parse(txt); } catch (e) {}
            if (res && res.success) {
                showNotification('Added to cart!', 'success');
                if (typeof window.updateCartCount === 'function') window.updateCartCount();
            } else {
                showNotification((res && res.message) || 'Please login to add items', 'error');
            }
        })
        .catch(function () {
            showNotification('Network error. Please try again.', 'error');
        });
    };

    window.quickView = function (productId) {
        window.location.href = 'product-details.php?id=' + productId;
    };

    if (typeof window.refreshWishlist === 'function') {
        window.refreshWishlist();
    }
})();
</script>

<?php include '../includes/footer.php'; ?>