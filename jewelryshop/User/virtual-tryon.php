<?php
// user/virtual-tryon.php — Camera Capture → Virtual Try-On
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/auth.php';

/* ------------------------------------------------------------------
   1. Theme from cookie
   ------------------------------------------------------------------ */
$theme = 'cream'; $accent = '#5b5fd4';
if (!empty($_COOKIE['rjewel_theme'])) {
    $p = json_decode(urldecode($_COOKIE['rjewel_theme']), true);
    if (is_array($p)) {
        $theme  = $p['theme']  ?? 'cream';
        $accent = $p['accent'] ?? '#5b5fd4';
    }
}
$resolved_theme = in_array($theme, ['cream','light','dark'], true) ? $theme : 'cream';

/* ------------------------------------------------------------------
   2. Database
   ------------------------------------------------------------------ */
$db = null;
try { $database = new Database(); $db = $database->getConnection(); } catch (Throwable $e) { $db = null; }

function safe_query($db, $sql, $params = []) {
    if (!$db) return [];
    try { $s = $db->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { return []; }
}

/* ------------------------------------------------------------------
   3. Fetch products + classify jewelry type
   Order matters: earring → watch → bracelet → necklace → ring.
   OPTIONAL: add a column `tryon_image` (transparent PNG) to `products`
   for the best results. If it is empty, the normal product image is used.
   ------------------------------------------------------------------ */
$products = safe_query($db, "
    SELECT p.*, c.name AS category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.stock > 0
    ORDER BY p.featured DESC, p.created_at DESC
");

foreach ($products as &$p) {
    $n = strtolower(($p['category_name'] ?? '') . ' ' . ($p['name'] ?? ''));
    if     (preg_match('/ear\s*-?rings?|\bstuds?\b|\bhoops?\b|\bdangles?\b/', $n)) $p['jewel_type'] = 'earring';
    elseif (preg_match('/watch|timepiece|chronograph/', $n))                       $p['jewel_type'] = 'watch';
    elseif (preg_match('/bracelet|bangle|cuff/', $n))                              $p['jewel_type'] = 'bracelet';
    elseif (preg_match('/necklace|pendant|choker|chain/', $n))                     $p['jewel_type'] = 'necklace';
    elseif (preg_match('/\brings?\b/', $n))                                        $p['jewel_type'] = 'ring';
    else                                                                           $p['jewel_type'] = 'other';

    $p['tryon_src'] = !empty($p['tryon_image']) ? $p['tryon_image'] : ($p['image'] ?? 'placeholder.jpg');
}
unset($p);

$categories = safe_query($db, "SELECT * FROM categories ORDER BY name ASC");

$page_title = 'Virtual Try-On | R-Jewel&Gold';
?>
<?php include '../includes/header.php'; ?>

<style>
    :root {
        --bg: #f2ede4; --bg-soft: #faf7f2; --card: #ffffff;
        --border: #e5ddd0; --border-light: #ede7db;
        --text: #5a5245; --text-bright: #2b2620; --text-muted: #8a8172; --text-dim: #b3a998;
        --primary: <?php echo htmlspecialchars($accent); ?>;
        --gold: #b8941f; --gold-soft: rgba(184,148,31,0.1); --gold-strong: #8a6e14;
        --success: #0d9668; --danger: #dc2626;
    }
    <?php if ($resolved_theme === 'dark'): ?>
    :root {
        --bg: #151821; --bg-soft: #1c202b; --card: #222735;
        --border: #343b4d; --border-light: #2d3444;
        --text: #d5d9e2; --text-bright: #ffffff; --text-muted: #a0a8ba; --text-dim: #777f91;
    }
    <?php elseif ($resolved_theme === 'light'): ?>
    :root {
        --bg: #f5f7fb; --bg-soft: #ffffff; --card: #ffffff;
        --border: #e2e8f0; --border-light: #edf1f6;
        --text: #475569; --text-bright: #172033; --text-muted: #64748b; --text-dim: #94a3b8;
    }
    <?php endif; ?>

    body, h1, h2, h3, h4, h5, h6,
    .tryon-hero h1, .video-placeholder h5,
    .product-item .name, .active-item-info strong,
    .guide-card h6, .guide-tip .tip-text strong,
    .range-value, .loading-text {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }
    body { background: var(--bg); color: var(--text); }
    h1,h2,h3,h4,h5,h6 { color: var(--text-bright); font-weight: 600; }

    /* Hero */
    .tryon-hero {
        position: relative; background: var(--bg);
        background-image:
            radial-gradient(ellipse at 20% 20%, rgba(184,148,31,0.14), transparent 55%),
            radial-gradient(ellipse at 80% 80%, rgba(91,95,212,0.08), transparent 55%);
        overflow: hidden; padding: 4rem 1.5rem 3.5rem; text-align: center;
        border-bottom: 1px solid var(--border-light);
    }
    .tryon-hero::before {
        content: ''; position: absolute; inset: 0;
        background-image:
            radial-gradient(1.5px 1.5px at 15% 25%, rgba(184,148,31,0.35), transparent),
            radial-gradient(1px 1px at 45% 60%, rgba(138,129,114,0.25), transparent),
            radial-gradient(1.5px 1.5px at 75% 15%, rgba(184,148,31,0.3), transparent),
            radial-gradient(1px 1px at 85% 75%, rgba(138,129,114,0.2), transparent);
        pointer-events: none;
    }
    .tryon-hero > * { position: relative; z-index: 2; }

    .hero-diamond { width: 62px; height: 62px; margin: 0 auto 1rem; }
    .hero-diamond svg { width: 100%; height: 100%; filter: drop-shadow(0 6px 20px rgba(184,148,31,.4)); animation: floatDiamond 6s ease-in-out infinite; }
    @keyframes floatDiamond { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-8px); } }

    .tryon-hero .eyebrow { font-size: .68rem; font-weight: 700; letter-spacing: .4em; text-transform: uppercase; color: var(--gold); margin-bottom: .75rem; }
    .tryon-hero h1 { font-size: clamp(2.1rem, 5vw, 3.2rem); margin-bottom: .75rem; line-height: 1.05; }
    .tryon-hero h1 em { color: var(--gold); font-style: italic; font-weight: 500; }
    .tryon-hero p { color: var(--text-muted); max-width: 620px; margin: 0 auto; font-size: .95rem; }
    .hero-divider { display: flex; align-items: center; justify-content: center; gap: 12px; max-width: 200px; margin: 1.25rem auto 0; }
    .hero-divider .line { flex: 1; height: 1px; background: linear-gradient(90deg, transparent, rgba(184,148,31,.6), transparent); }
    .hero-divider .diamond { width: 6px; height: 6px; background: var(--gold); transform: rotate(45deg); box-shadow: 0 0 10px rgba(184,148,31,.7); }

    /* Buttons */
    .btn-lux {
        display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        padding: .7rem 1.4rem; border-radius: 9px; border: none;
        font-family: 'Inter', sans-serif; font-size: .72rem; font-weight: 600;
        letter-spacing: .2em; text-transform: uppercase;
        text-decoration: none; cursor: pointer; transition: all .3s ease; white-space: nowrap;
    }
    .btn-lux-gold { background: linear-gradient(135deg, var(--gold), var(--gold-strong)); color: #fff; box-shadow: 0 4px 14px -3px rgba(184,148,31,.45); }
    .btn-lux-gold:hover { color:#fff; transform: translateY(-2px); box-shadow: 0 8px 22px -5px rgba(184,148,31,.6); }
    .btn-lux-outline { background: transparent; color: var(--text-bright); border: 1px solid var(--border); }
    .btn-lux-outline:hover { border-color: var(--gold); color: var(--gold); transform: translateY(-2px); }
    .btn-lux-outline-gold { background: transparent; color: var(--gold); border: 1px solid rgba(184,148,31,.4); }
    .btn-lux-outline-gold:hover { background: var(--gold); color: #fff; border-color: var(--gold); }
    .btn-lux-danger { background: transparent; color: var(--danger); border: 1px solid rgba(220,38,38,.35); }
    .btn-lux-danger:hover { background: var(--danger); color: #fff; border-color: var(--danger); }

    /* Layout */
    .tryon-container { padding: 3rem 0 5rem; }
    .tryon-wrapper { background: var(--card); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; }

    /* Camera section */
    .camera-section { padding: 1.5rem; background: var(--bg-soft); border-right: 1px solid var(--border-light); }
    @media (max-width: 991px) { .camera-section { border-right: none; border-bottom: 1px solid var(--border-light); } }

    .video-container {
        position: relative; border-radius: 14px; overflow: hidden;
        background: #0a0a0a; aspect-ratio: 4/3;
        border: 1px solid var(--border);
        display: flex; align-items: center; justify-content: center;
        transition: cursor .2s ease;
    }
    .video-container.tryon-active { cursor: crosshair; }

    /* Step indicator */
    .step-pill {
        position: absolute; top: 12px; left: 12px;
        background: rgba(20,18,15,.78); backdrop-filter: blur(8px);
        color: #fff; padding: 6px 14px; border-radius: 20px;
        font-size: .68rem; font-weight: 700; letter-spacing: .12em;
        text-transform: uppercase; z-index: 10;
        display: flex; align-items: center; gap: 8px;
        border: 1px solid rgba(184,148,31,.4);
    }
    .step-pill i { color: var(--gold); }
    .step-pill .num {
        width: 18px; height: 18px; border-radius: 50%;
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        color: #fff; display: flex; align-items: center; justify-content: center;
        font-size: .6rem; font-weight: 800;
    }

    /* Detection badge */
    .detection-badge {
        position: absolute; top: 12px; right: 12px;
        background: rgba(184,148,31,.92); color: #fff;
        padding: 6px 12px; border-radius: 20px;
        font-size: .62rem; font-weight: 700; letter-spacing: .12em;
        text-transform: uppercase; z-index: 10;
        display: none; align-items: center; gap: 6px;
    }
    .detection-badge.show { display: flex; }

    /* Placeholder */
    .video-placeholder {
        position: absolute; inset: 0; display: flex; flex-direction: column;
        align-items: center; justify-content: center; text-align: center;
        padding: 2rem; color: rgba(255,255,255,.55); pointer-events: none;
    }
    .video-placeholder .icon {
        width: 80px; height: 80px; border-radius: 50%;
        background: rgba(184,148,31,.15); border: 1px solid rgba(184,148,31,.35);
        color: var(--gold); display: flex; align-items: center; justify-content: center;
        font-size: 2rem; margin-bottom: 1rem;
    }
    .video-placeholder h5 { color: rgba(255,255,255,.85); font-size: 1.35rem; margin-bottom: .4rem; }
    .video-placeholder p { font-size: .82rem; max-width: 340px; line-height: 1.55; }
    .video-placeholder.hidden { display: none; }

    /* Video / Photo / Canvas stack
       photo + canvas use "contain" (not "cover") so nothing is cropped
       and click positions map exactly to the picture. */
    #video, #canvas, #capturedPhoto {
        width: 100%; height: 100%; object-fit: contain; display: none;
    }
    #video { object-fit: cover; }
    #video, #capturedPhoto { position: relative; z-index: 1; }
    #canvas { position: absolute; top: 0; left: 0; z-index: 2; }

    /* Camera shutter button */
    .shutter-btn {
        position: absolute;
        bottom: 16px;
        left: 50%;
        transform: translateX(-50%);
        width: 68px; height: 68px;
        border-radius: 50%;
        background: rgba(255,255,255,.15);
        border: 3px solid #fff;
        cursor: pointer;
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 20;
        transition: all .2s ease;
        box-shadow: 0 4px 14px rgba(0,0,0,.35);
    }
    .shutter-btn::after {
        content: '';
        width: 52px; height: 52px;
        border-radius: 50%;
        background: #fff;
        transition: all .2s ease;
    }
    .shutter-btn:hover { transform: translateX(-50%) scale(1.05); }
    .shutter-btn:active::after { transform: scale(.9); }
    .shutter-btn.show { display: flex; }

    /* Corner frame guides */
    .frame-corner {
        position: absolute;
        width: 28px; height: 28px;
        border-color: rgba(184,148,31,.8);
        pointer-events: none;
        z-index: 5;
        display: none;
    }
    .frame-corner.show { display: block; }
    .frame-corner.tl { top: 20px; left: 20px; border-top: 2px solid; border-left: 2px solid; border-radius: 6px 0 0 0; }
    .frame-corner.tr { top: 20px; right: 20px; border-top: 2px solid; border-right: 2px solid; border-radius: 0 6px 0 0; }
    .frame-corner.bl { bottom: 100px; left: 20px; border-bottom: 2px solid; border-left: 2px solid; border-radius: 0 0 0 6px; }
    .frame-corner.br { bottom: 100px; right: 20px; border-bottom: 2px solid; border-right: 2px solid; border-radius: 0 0 6px 0; }

    /* Actions */
    .camera-actions { display: flex; gap: 8px; margin-top: 1rem; flex-wrap: wrap; }
    .camera-actions .btn-lux { flex: 1; min-width: 130px; }

    /* Controls */
    .controls-section { display: flex; flex-direction: column; height: 100%; }
    .controls-tabs { display: flex; background: var(--card); border-bottom: 1px solid var(--border-light); }
    .tab-btn {
        flex: 1; padding: 1rem .5rem; border: none; background: transparent;
        cursor: pointer; font-family: 'Inter', sans-serif;
        font-size: .7rem; font-weight: 700; letter-spacing: .15em; text-transform: uppercase;
        color: var(--text-muted); transition: all .25s ease;
        border-bottom: 2px solid transparent;
        display: flex; align-items: center; justify-content: center; gap: 6px;
    }
    .tab-btn:hover { color: var(--gold); background: var(--gold-soft); }
    .tab-btn.active { color: var(--gold); border-bottom-color: var(--gold); background: var(--gold-soft); }

    .tab-pane { display: none; padding: 1.25rem; max-height: 640px; overflow-y: auto; flex: 1; }
    .tab-pane.active { display: block; }
    .tab-pane::-webkit-scrollbar { width: 5px; }
    .tab-pane::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }

    /* Category filters */
    .category-filters {
        display: flex; gap: 6px; flex-wrap: wrap;
        margin-bottom: 1rem; padding-bottom: 1rem;
        border-bottom: 1px solid var(--border-light);
    }
    .category-filter {
        padding: 6px 12px; border: 1px solid var(--border); background: var(--bg-soft);
        border-radius: 20px; font-family: 'Inter', sans-serif;
        font-size: .68rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
        color: var(--text-muted); cursor: pointer; transition: all .25s ease;
    }
    .category-filter:hover { border-color: var(--gold); color: var(--gold); }
    .category-filter.active {
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        color: #fff; border-color: transparent;
        box-shadow: 0 3px 10px -2px rgba(184,148,31,.5);
    }

    /* Finger / hand picker */
    .picker-block {
        background: var(--bg-soft); border: 1px solid var(--border);
        border-radius: 10px; padding: .75rem .9rem; margin-bottom: 1rem;
    }
    .picker-title {
        font-size: .68rem; font-weight: 700; letter-spacing: .18em; text-transform: uppercase;
        color: var(--text-bright); margin-bottom: .55rem; display: flex; align-items: center; gap: 8px;
    }
    .picker-title i { color: var(--gold); }
    .chip-row { display: flex; flex-wrap: wrap; gap: 6px; }
    .chip-row + .chip-row { margin-top: .5rem; }
    .chip {
        padding: 6px 12px; border: 1px solid var(--border); background: var(--card);
        border-radius: 20px; font-family: 'Inter', sans-serif;
        font-size: .68rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
        color: var(--text-muted); cursor: pointer; transition: all .25s ease;
    }
    .chip:hover { border-color: var(--gold); color: var(--gold); }
    .chip.active { background: linear-gradient(135deg, var(--gold), var(--gold-strong)); color: #fff; border-color: transparent; }

    /* Ring style tiles */
    .style-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 6px; }
    .style-tile {
        border: 1px solid var(--border); background: var(--card); border-radius: 10px;
        padding: 4px 2px 5px; cursor: pointer; text-align: center; transition: all .25s ease;
        font-family: 'Inter', sans-serif; font-size: .5rem; font-weight: 700;
        letter-spacing: .03em; text-transform: uppercase; color: var(--text-muted);
        display: flex; flex-direction: column; align-items: center; gap: 3px; min-width: 0;
    }
    .style-tile canvas { width: 100%; height: auto; border-radius: 7px; display: block; }
    .style-tile:hover { border-color: var(--gold); color: var(--gold); }
    .style-tile.active { border-color: var(--gold); background: var(--gold-soft); color: var(--gold); box-shadow: 0 0 0 2px rgba(184,148,31,.2); }

    /* Product grid */
    .product-grid {
        display: grid; grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
        gap: 10px; margin-bottom: 1rem; max-height: 340px; overflow-y: auto; padding: 4px;
    }
    .product-grid::-webkit-scrollbar { width: 5px; }
    .product-grid::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }

    .product-item {
        background: var(--bg-soft); border: 1px solid var(--border);
        border-radius: 12px; padding: 10px 6px; cursor: pointer;
        transition: all .3s ease; text-align: center;
        position: relative; overflow: hidden;
    }
    .product-item:hover { transform: translateY(-4px); border-color: var(--gold); box-shadow: 0 10px 22px -12px rgba(90,82,69,.3); }
    .product-item.selected {
        border-color: var(--gold); background: var(--gold-soft);
        box-shadow: 0 0 0 2px rgba(184,148,31,.25), 0 8px 20px -12px rgba(184,148,31,.5);
    }
    .product-item.selected::after {
        content: '\f00c'; font-family: 'Font Awesome 6 Free'; font-weight: 900;
        position: absolute; top: 6px; right: 6px;
        width: 18px; height: 18px;
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        color: #fff; border-radius: 50%; font-size: .55rem;
        display: flex; align-items: center; justify-content: center;
    }
    .product-item img {
        width: 60px; height: 60px; object-fit: contain;
        margin-bottom: 6px; border-radius: 8px; background: var(--card);
    }
    .product-item .name {
        font-size: .85rem; font-weight: 600; color: var(--text-bright);
        margin-bottom: 2px; line-height: 1.2;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .product-item .category {
        font-family: 'Inter', sans-serif; font-size: .55rem;
        font-weight: 700; letter-spacing: .15em; text-transform: uppercase;
        color: var(--gold);
    }

    /* Active items */
    .active-items-panel {
        background: var(--bg-soft); border: 1px solid var(--border);
        border-radius: 12px; padding: .9rem; margin-top: .75rem;
    }
    .active-items-panel .panel-head {
        display: flex; align-items: center; gap: 8px;
        margin-bottom: .75rem; padding-bottom: .6rem;
        border-bottom: 1px solid var(--border-light);
        font-size: .68rem; font-weight: 700; letter-spacing: .18em;
        text-transform: uppercase; color: var(--text-bright);
    }
    .active-items-panel .panel-head i { color: var(--gold); }
    #activeItemsList { max-height: 220px; overflow-y: auto; }
    #activeItemsList::-webkit-scrollbar { width: 4px; }
    #activeItemsList::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }

    .active-item {
        display: flex; align-items: center; justify-content: space-between;
        gap: 8px; padding: .6rem .7rem; background: var(--card);
        border: 1px solid var(--border); border-left: 3px solid var(--gold);
        border-radius: 10px; margin-bottom: 6px; transition: all .25s ease;
    }
    .active-item:hover { border-color: var(--gold); }
    .active-item.selected { background: var(--gold-soft); }
    .active-item-info { display: flex; align-items: center; gap: 8px; min-width: 0; flex: 1; }
    .active-item-info img {
        width: 34px; height: 34px; object-fit: contain;
        border-radius: 6px; background: var(--bg-soft);
        border: 1px solid var(--border); flex-shrink: 0;
    }
    .active-item-info .meta { min-width: 0; flex: 1; }
    .active-item-info strong {
        display: block; font-size: .95rem; color: var(--text-bright); font-weight: 600;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .active-item-info small { font-size: .6rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--gold); }
    .item-actions { display: flex; gap: 4px; flex-shrink: 0; }
    .icon-btn {
        width: 30px; height: 30px; border-radius: 8px;
        border: 1px solid var(--border); background: var(--card);
        color: var(--text-muted); cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: .7rem; transition: all .25s ease;
    }
    .icon-btn:hover { border-color: var(--gold); color: var(--gold); background: var(--gold-soft); }
    .icon-btn.danger:hover { border-color: var(--danger); color: var(--danger); background: rgba(220,38,38,.08); }

    .empty-items { text-align: center; padding: 1.5rem 1rem; color: var(--text-muted); font-size: .8rem; }
    .empty-items i { display: block; font-size: 1.5rem; color: var(--gold); opacity: .5; margin-bottom: .4rem; }

    /* Adjust controls */
    .control-group { margin-bottom: 1.5rem; }
    .control-group label {
        display: flex; align-items: center; gap: 8px;
        margin-bottom: .6rem; font-size: .68rem; font-weight: 700;
        letter-spacing: .18em; text-transform: uppercase;
        color: var(--text-bright);
    }
    .control-group label i { color: var(--gold); }

    input[type="range"].lux-slider {
        width: 100%; -webkit-appearance: none; height: 4px;
        border-radius: 4px; background: var(--border); outline: none;
    }
    input[type="range"].lux-slider::-webkit-slider-thumb {
        -webkit-appearance: none; width: 18px; height: 18px;
        border-radius: 50%; background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        cursor: pointer; box-shadow: 0 2px 8px rgba(184,148,31,.5);
    }
    input[type="range"].lux-slider::-moz-range-thumb {
        width: 18px; height: 18px; border-radius: 50%;
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        cursor: pointer; border: none;
    }
    .range-labels { display: flex; justify-content: space-between; font-size: .65rem; color: var(--text-dim); margin-top: .3rem; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; }
    .range-value { text-align: center; font-size: 1.05rem; font-weight: 600; color: var(--gold); margin-top: .35rem; }

    /* Position pad */
    .position-pad { display: grid; grid-template-columns: repeat(3, 44px); grid-template-rows: repeat(3, 44px); gap: 6px; justify-content: center; margin: 0 auto .75rem; }
    .position-btn {
        border: 1px solid var(--border); background: var(--bg-soft);
        border-radius: 10px; cursor: pointer; color: var(--text-muted);
        display: flex; align-items: center; justify-content: center;
        font-size: .85rem; transition: all .25s ease;
    }
    .position-btn:hover { border-color: var(--gold); color: var(--gold); background: var(--gold-soft); }
    .position-btn.reset { border-color: rgba(184,148,31,.5); color: var(--gold); background: var(--gold-soft); }

    .selection-notice {
        background: var(--gold-soft); border: 1px solid rgba(184,148,31,.3);
        border-radius: 10px; padding: .7rem .9rem;
        font-size: .78rem; color: var(--text-muted);
        display: flex; align-items: center; gap: 8px;
    }
    .selection-notice i { color: var(--gold); }

    /* Auto-fit toggle */
    .auto-fit-row {
        display: flex; align-items: center; justify-content: space-between;
        padding: .75rem .9rem; background: var(--bg-soft);
        border: 1px solid var(--border); border-radius: 10px;
        margin-bottom: 1rem;
    }
    .auto-fit-row .label { font-size: .75rem; font-weight: 600; color: var(--text-bright); display: flex; align-items: center; gap: 8px; }
    .auto-fit-row .label i { color: var(--gold); }
    .switch { position: relative; display: inline-block; width: 42px; height: 24px; flex-shrink: 0; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .slider-switch {
        position: absolute; cursor: pointer; inset: 0;
        background: var(--border); border-radius: 24px; transition: .3s;
    }
    .slider-switch:before {
        content: ""; position: absolute; height: 18px; width: 18px;
        left: 3px; bottom: 3px; background: #fff; border-radius: 50%;
        transition: .3s; box-shadow: 0 2px 4px rgba(0,0,0,.15);
    }
    .switch input:checked + .slider-switch { background: linear-gradient(135deg, var(--gold), var(--gold-strong)); }
    .switch input:checked + .slider-switch:before { transform: translateX(18px); }

    /* Guide */
    .guide-card { background: var(--bg-soft); border: 1px solid var(--border); border-radius: 12px; padding: 1.1rem; margin-bottom: 1rem; }
    .guide-card h6 { font-size: 1.15rem; font-weight: 600; margin-bottom: .75rem; display: flex; align-items: center; gap: 8px; color: var(--text-bright); }
    .guide-card h6 i { color: var(--gold); }
    .guide-tip { display: flex; align-items: center; gap: 10px; padding: .6rem .75rem; background: var(--card); border-radius: 8px; margin-bottom: 6px; border-left: 3px solid var(--gold); }
    .guide-tip:last-child { margin-bottom: 0; }
    .guide-tip .tip-icon { width: 32px; height: 32px; border-radius: 50%; background: var(--gold-soft); color: var(--gold); display: flex; align-items: center; justify-content: center; font-size: .85rem; flex-shrink: 0; }
    .guide-tip .tip-text strong { display: block; font-size: 1rem; color: var(--text-bright); font-weight: 600; }
    .guide-tip .tip-text small { font-size: .72rem; color: var(--text-muted); }

    .how-to-list { padding-left: 1.25rem; margin: 0; }
    .how-to-list li { font-size: .85rem; color: var(--text); line-height: 1.8; margin-bottom: .35rem; }
    .how-to-list li::marker { color: var(--gold); font-weight: 700; }

    /* Loading */
    .loading-overlay {
        position: fixed; inset: 0; background: rgba(20,18,15,.75);
        backdrop-filter: blur(6px); z-index: 9999;
        display: none; justify-content: center; align-items: center; flex-direction: column;
    }
    .loading-overlay.active { display: flex; }
    .loading-spinner {
        width: 56px; height: 56px;
        border: 3px solid rgba(184,148,31,.2); border-top-color: var(--gold);
        border-radius: 50%; animation: spin .9s linear infinite; margin-bottom: 1rem;
    }
    .loading-text { color: #fff; font-size: 1.3rem; letter-spacing: .1em; }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* Toast */
    .toast-notification {
        position: fixed; bottom: 24px; right: 24px; z-index: 9999;
        animation: slideInRight .35s ease-out; border-radius: 10px; overflow: hidden;
        box-shadow: 0 12px 30px rgba(0,0,0,.15); background: var(--card);
        border: 1px solid var(--border); min-width: 260px;
        border-left: 3px solid var(--gold);
    }
    .toast-notification .toast-body {
        padding: .9rem 1.1rem; font-size: .85rem; font-weight: 500;
        display: flex; align-items: center; gap: 10px; color: var(--text-bright);
    }
    .toast-notification.success { border-left-color: var(--success); }
    .toast-notification.success .toast-body i { color: var(--success); }
    .toast-notification.error { border-left-color: var(--danger); }
    .toast-notification.error .toast-body i { color: var(--danger); }
    .toast-notification.warning { border-left-color: var(--gold); }
    .toast-notification.warning .toast-body i { color: var(--gold); }
    .toast-notification.info .toast-body i { color: var(--gold); }
    @keyframes slideInRight { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

    /* Review mode banner */
    .mode-banner {
        background: linear-gradient(135deg, rgba(184,148,31,.15), rgba(184,148,31,.05));
        border: 1px solid rgba(184,148,31,.4);
        border-radius: 12px;
        padding: .85rem 1rem;
        margin-bottom: 1rem;
        font-size: .82rem;
        color: var(--text-bright);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .mode-banner i { color: var(--gold); font-size: 1.1rem; }
    .mode-banner strong { font-size: 1.05rem; display: block; }

    /* Capture flash */
    .flash {
        position: absolute; inset: 0;
        background: #fff; opacity: 0;
        pointer-events: none; z-index: 30;
        border-radius: 14px;
    }
    .flash.fire { animation: flash .4s ease-out; }
    @keyframes flash {
        0%   { opacity: 0; }
        20%  { opacity: .95; }
        100% { opacity: 0; }
    }

    @media (max-width: 768px) {
        .tryon-hero { padding: 3rem 1rem 2.5rem; }
        .tryon-container { padding: 2rem 0 3.5rem; }
        .product-grid { grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); gap: 8px; }
        .product-item img { width: 48px; height: 48px; }
        .product-item .name { font-size: .75rem; }
        .tab-btn { font-size: .6rem; padding: .85rem .3rem; }
        .camera-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
        .camera-actions .btn-lux { min-width: 0; padding: .6rem .8rem; font-size: .62rem; }
        .shutter-btn { width: 58px; height: 58px; bottom: 12px; }
        .shutter-btn::after { width: 44px; height: 44px; }
    }
    @media (prefers-reduced-motion: reduce) {
        * { animation-duration: .01ms !important; transition-duration: .01ms !important; }
    }
</style>

<!-- Hero -->
<section class="tryon-hero">
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
    <p class="eyebrow">R-Jewel&Gold · AI Try-On Studio</p>
    <h1>Snap a Photo, <em>See It On</em></h1>
    <p>Take a photo with your camera, then let our AI place any piece on your hand, neck, or ears — so you can see exactly how it looks on you.</p>
    <div class="hero-divider"><span class="line"></span><span class="diamond"></span><span class="line"></span></div>
</section>

<!-- Try-on -->
<div class="tryon-container">
    <div class="container">
        <div class="tryon-wrapper">
            <div class="row g-0">
                <div class="col-lg-8">
                    <div class="camera-section">

                        <!-- Mode banner -->
                        <div class="mode-banner" id="modeBanner">
                            <i class="fas fa-camera" id="modeBannerIcon"></i>
                            <div>
                                <strong id="modeBannerTitle">Step 1 — Take a photo</strong>
                                <span id="modeBannerText">Allow camera access, then tap the shutter to capture your look.</span>
                            </div>
                        </div>

                        <div class="video-container" id="videoContainer">
                            <!-- Step pill -->
                            <div class="step-pill">
                                <span class="num" id="stepNum">1</span>
                                <span id="stepText">Capture</span>
                            </div>

                            <!-- Detection badge (step 2 only) -->
                            <div class="detection-badge" id="detectionBadge">
                                <i class="fas fa-crosshairs"></i> <span id="detectionText">Tracking</span>
                            </div>

                            <!-- Placeholder -->
                            <div class="video-placeholder" id="videoPlaceholder">
                                <div class="icon"><i class="fas fa-camera"></i></div>
                                <h5>Ready when you are</h5>
                                <p>Click "Start Camera" to enable your webcam, or upload a photo from your device.</p>
                            </div>

                            <!-- Camera frame corner guides -->
                            <div class="frame-corner tl" id="cornerTL"></div>
                            <div class="frame-corner tr" id="cornerTR"></div>
                            <div class="frame-corner bl" id="cornerBL"></div>
                            <div class="frame-corner br" id="cornerBR"></div>

                            <!-- Stack -->
                            <video id="video" autoplay playsinline muted></video>
                            <img id="capturedPhoto" alt="Your photo">
                            <canvas id="canvas"></canvas>

                            <!-- Camera shutter -->
                            <button class="shutter-btn" id="shutterBtn" aria-label="Take photo"></button>

                            <!-- Flash -->
                            <div class="flash" id="flash"></div>
                        </div>

                        <div class="camera-actions">
                            <button class="btn-lux btn-lux-gold" id="startCamera">
                                <i class="fas fa-camera"></i> Start Camera
                            </button>
                            <button class="btn-lux btn-lux-outline" id="uploadPhoto">
                                <i class="fas fa-upload"></i> Upload Photo
                            </button>
                            <button class="btn-lux btn-lux-outline" id="retakeBtn" style="display:none;">
                                <i class="fas fa-redo"></i> Retake
                            </button>
                            <button class="btn-lux btn-lux-outline" id="clearAll">
                                <i class="fas fa-trash-alt"></i> Clear All
                            </button>
                            <button class="btn-lux btn-lux-outline-gold" id="saveImage">
                                <i class="fas fa-download"></i> Save Look
                            </button>
                        </div>
                        <input type="file" id="photoUpload" accept="image/*" style="display:none;">
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="controls-section">
                        <div class="controls-tabs">
                            <button class="tab-btn active" data-tab="jewelry"><i class="fas fa-gem"></i> Jewelry</button>
                            <button class="tab-btn" data-tab="adjust"><i class="fas fa-sliders-h"></i> Adjust</button>
                            <button class="tab-btn" data-tab="guide"><i class="fas fa-book-open"></i> Guide</button>
                        </div>

                        <!-- Jewelry tab -->
                        <div class="tab-pane active" id="jewelryTab">
                            <div class="auto-fit-row">
                                <span class="label"><i class="fas fa-magic"></i> Auto-fit to body</span>
                                <label class="switch">
                                    <input type="checkbox" id="autoFitToggle" checked>
                                    <span class="slider-switch"></span>
                                </label>
                            </div>

                            <!-- Choose which finger (rings) / which hand -->
                            <div class="picker-block">
                                <div id="ringFingerBlock">
                                    <div class="picker-title"><i class="fas fa-hand-paper"></i> Wear ring on</div>
                                    <div class="chip-row" id="fingerPicker">
                                        <button type="button" class="chip" data-finger="thumb">Thumb</button>
                                        <button type="button" class="chip" data-finger="index">Index</button>
                                        <button type="button" class="chip" data-finger="middle">Middle</button>
                                        <button type="button" class="chip active" data-finger="ring">Ring</button>
                                        <button type="button" class="chip" data-finger="pinky">Pinky</button>
                                    </div>
                                </div>
                                <div class="chip-row" id="handPicker" style="display:none;"></div>

                                <div id="ringStyleBlock">
                                    <div class="picker-title" style="margin-top:.9rem;"><i class="fas fa-ring"></i> Ring style</div>
                                    <div class="style-grid" id="stylePicker"></div>
                                </div>

                                <div id="braceletStyleBlock" style="display:none;">
                                    <div class="picker-title" style="margin-top:.9rem;"><i class="fas fa-circle-notch"></i> Bracelet style</div>
                                    <div class="style-grid" id="braceletStylePicker"></div>
                                </div>

                                <div id="watchOptionsBlock" style="display:none;">
                                    <div class="picker-title" style="margin-top:.9rem;"><i class="fas fa-clock"></i> Watch style</div>
                                    <div class="style-grid" id="watchStylePicker"></div>

                                    <div class="picker-title" style="margin-top:.9rem;"><i class="fas fa-grip-lines"></i> Strap</div>
                                    <div class="chip-row" id="strapPicker">
                                        <button type="button" class="chip active" data-strap="metal">Metal link</button>
                                        <button type="button" class="chip" data-strap="brown">Brown leather</button>
                                        <button type="button" class="chip" data-strap="black">Black leather</button>
                                        <button type="button" class="chip" data-strap="rubber">Rubber</button>
                                    </div>

                                    <div class="picker-title" style="margin-top:.9rem;"><i class="fas fa-hand-paper"></i> Watch face on</div>
                                    <div class="chip-row" id="facePicker">
                                        <button type="button" class="chip active" data-face="auto">Auto</button>
                                        <button type="button" class="chip" data-face="back">Back of hand</button>
                                        <button type="button" class="chip" data-face="palm">Palm side</button>
                                    </div>
                                </div>

                                <div class="picker-title" style="margin-top:.9rem;"><i class="fas fa-palette"></i> Metal</div>
                                <div class="chip-row" id="metalPicker">
                                    <button type="button" class="chip active" data-metal="auto">Match product</button>
                                    <button type="button" class="chip" data-metal="yellow">Yellow gold</button>
                                    <button type="button" class="chip" data-metal="rose">Rose gold</button>
                                    <button type="button" class="chip" data-metal="white">White gold</button>
                                </div>

                                <div id="gemBlock">
                                    <div class="picker-title" style="margin-top:.9rem;"><i class="fas fa-gem"></i> Stone</div>
                                    <div class="chip-row" id="gemPicker">
                                        <button type="button" class="chip active" data-gem="diamond">Diamond</button>
                                        <button type="button" class="chip" data-gem="ruby">Ruby</button>
                                        <button type="button" class="chip" data-gem="sapphire">Sapphire</button>
                                        <button type="button" class="chip" data-gem="emerald">Emerald</button>
                                    </div>
                                </div>
                            </div>

                            <div class="category-filters" id="categoryFilters">
                                <button class="category-filter active" data-category="all">All</button>
                                <?php foreach ($categories as $cat): ?>
                                    <button class="category-filter" data-category="<?php echo (int)$cat['id']; ?>">
                                        <?php echo htmlspecialchars($cat['name']); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>

                            <?php if (empty($products)): ?>
                                <div class="empty-items"><i class="fas fa-gem"></i> No products available yet.</div>
                            <?php else: ?>
                                <div class="product-grid" id="productGrid">
                                    <?php foreach ($products as $product): ?>
                                    <div class="product-item"
                                         data-id="<?php echo (int)$product['id']; ?>"
                                         data-category="<?php echo (int)$product['category_id']; ?>"
                                         data-image="../uploads/<?php echo htmlspecialchars($product['image'] ?? 'placeholder.jpg'); ?>"
                                         data-tryon="../uploads/<?php echo htmlspecialchars($product['tryon_src']); ?>"
                                         data-name="<?php echo htmlspecialchars($product['name']); ?>"
                                         data-category-name="<?php echo htmlspecialchars($product['category_name'] ?? 'Jewelry'); ?>"
                                         data-jewel-type="<?php echo htmlspecialchars($product['jewel_type']); ?>">
                                        <img src="../uploads/<?php echo htmlspecialchars($product['image'] ?? 'placeholder.jpg'); ?>"
                                             onerror="this.onerror=null;this.src='https://via.placeholder.com/120x120/ede7db/8a8172?text=J'"
                                             alt="<?php echo htmlspecialchars($product['name']); ?>" loading="lazy">
                                        <div class="name"><?php echo htmlspecialchars($product['name']); ?></div>
                                        <div class="category"><?php echo htmlspecialchars($product['category_name'] ?? 'Jewelry'); ?></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div class="active-items-panel">
                                <div class="panel-head"><i class="fas fa-star"></i> Items on You</div>
                                <div id="activeItemsList">
                                    <div class="empty-items">
                                        <i class="fas fa-hand-pointer"></i>
                                        Capture a photo first, then pick a piece.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Adjust -->
                        <div class="tab-pane" id="adjustTab">
                            <div class="control-group">
                                <label><i class="fas fa-expand-arrows-alt"></i> Size</label>
                                <input type="range" id="sizeSlider" class="lux-slider" min="5" max="800" step="1" value="80">
                                <div class="range-labels"><span>Small</span><span>Large</span></div>
                                <div class="range-value" id="sizeValue">80px</div>
                            </div>

                            <div class="control-group">
                                <label><i class="fas fa-sync-alt"></i> Rotation</label>
                                <input type="range" id="rotationSlider" class="lux-slider" min="-180" max="180" step="1" value="0">
                                <div class="range-labels"><span>-180°</span><span>+180°</span></div>
                                <div class="range-value" id="rotationValue">0°</div>
                            </div>

                            <div class="control-group">
                                <label><i class="fas fa-arrows-alt"></i> Position</label>
                                <div class="position-pad">
                                    <div></div>
                                    <button class="position-btn" id="moveUp"><i class="fas fa-arrow-up"></i></button>
                                    <div></div>
                                    <button class="position-btn" id="moveLeft"><i class="fas fa-arrow-left"></i></button>
                                    <button class="position-btn reset" id="resetPosition"><i class="fas fa-undo"></i></button>
                                    <button class="position-btn" id="moveRight"><i class="fas fa-arrow-right"></i></button>
                                    <div></div>
                                    <button class="position-btn" id="moveDown"><i class="fas fa-arrow-down"></i></button>
                                    <div></div>
                                </div>
                            </div>

                            <div class="auto-fit-row">
                                <span class="label"><i class="fas fa-eraser"></i> Remove photo background</span>
                                <label class="switch">
                                    <input type="checkbox" id="cutoutToggle" checked>
                                    <span class="slider-switch"></span>
                                </label>
                            </div>

                            <div class="auto-fit-row">
                                <span class="label"><i class="fas fa-ring"></i> Use jewelry templates</span>
                                <label class="switch">
                                    <input type="checkbox" id="wrapToggle" checked>
                                    <span class="slider-switch"></span>
                                </label>
                            </div>

                            <div class="auto-fit-row">
                                <span class="label"><i class="fas fa-crosshairs"></i> Show detected points</span>
                                <label class="switch">
                                    <input type="checkbox" id="pointsToggle">
                                    <span class="slider-switch"></span>
                                </label>
                            </div>

                            <div class="selection-notice">
                                <i class="fas fa-info-circle"></i>
                                <span>Select an item to fine-tune. Rotation is an offset from the auto-fit angle. For rings, bracelets &amp; watches, Size = finger / wrist width.</span>
                            </div>
                        </div>

                        <!-- Guide -->
                        <div class="tab-pane" id="guideTab">
                            <div class="guide-card">
                                <h6><i class="fas fa-lightbulb"></i> How It Works</h6>
                                <ol class="how-to-list">
                                    <li>Start Camera or Upload Photo</li>
                                    <li>Tap the shutter button to capture</li>
                                    <li>Our AI detects your hands &amp; face</li>
                                    <li>Choose which finger, then pick a ring</li>
                                    <li>Fine-tune with sliders if needed</li>
                                    <li>Save or share your look</li>
                                </ol>
                            </div>

                            <div class="guide-card">
                                <h6><i class="fas fa-camera"></i> Photo Tips</h6>
                                <div class="guide-tip">
                                    <div class="tip-icon"><i class="fas fa-sun"></i></div>
                                    <div class="tip-text"><strong>Good lighting</strong><small>Face a window or lamp</small></div>
                                </div>
                                <div class="guide-tip">
                                    <div class="tip-icon"><i class="fas fa-hand-paper"></i></div>
                                    <div class="tip-text"><strong>Show your hand</strong><small>Back or palm to camera, wrist &amp; forearm visible</small></div>
                                </div>
                                <div class="guide-tip">
                                    <div class="tip-icon"><i class="fas fa-user-circle"></i></div>
                                    <div class="tip-text"><strong>Clear face</strong><small>No sunglasses or hair covering ears</small></div>
                                </div>
                            </div>

                            <div class="guide-card">
                                <h6><i class="fas fa-gem"></i> Placement by Type</h6>
                                <div class="guide-tip">
                                    <div class="tip-icon"><i class="fas fa-ring"></i></div>
                                    <div class="tip-text"><strong>Rings</strong><small>On the finger you choose</small></div>
                                </div>
                                <div class="guide-tip">
                                    <div class="tip-icon"><i class="fas fa-gem"></i></div>
                                    <div class="tip-text"><strong>Necklaces</strong><small>Anchored below your chin</small></div>
                                </div>
                                <div class="guide-tip">
                                    <div class="tip-icon"><i class="fas fa-star"></i></div>
                                    <div class="tip-text"><strong>Earrings</strong><small>Placed on both ear lobes</small></div>
                                </div>
                                <div class="guide-tip">
                                    <div class="tip-icon"><i class="fas fa-circle-notch"></i></div>
                                    <div class="tip-text"><strong>Bracelets</strong><small>Fitted to the edges of your wrist</small></div>
                                </div>
                                <div class="guide-tip">
                                    <div class="tip-icon"><i class="fas fa-clock"></i></div>
                                    <div class="tip-text"><strong>Watches</strong><small>Strap hugs your wrist; face on the back of the hand</small></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner"></div>
    <div class="loading-text">Loading AI…</div>
</div>

<!-- ================================================================
     MediaPipe + App Logic
     ================================================================ -->
<script src="https://cdn.jsdelivr.net/npm/@mediapipe/hands@0.4/hands.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/@mediapipe/face_mesh@0.4/face_mesh.js" crossorigin="anonymous"></script>

<script>
/*PURE-START*/
/* Pure helpers (no DOM): landmark → placement math + background removal. */
var TryOnPure = (function () {
    var FINGERS   = { thumb: [2, 3], index: [5, 6], middle: [9, 10], ring: [13, 14], pinky: [17, 18] };
    var NEIGHBORS = { thumb: null, index: [9], middle: [5, 13], ring: [9, 17], pinky: [13] };

    function toPx(p, W, H) { return { x: p.x * W, y: p.y * H }; }
    function dist(a, b) { var dx = a.x - b.x, dy = a.y - b.y; return Math.sqrt(dx * dx + dy * dy); }
    function deg(a, b) { return Math.atan2(b.y - a.y, b.x - a.x) * 180 / Math.PI; }

    /* Real finger width ≈ 0.82 × spacing to the neighbouring knuckles */
    function fingerWidth(lm, finger, W, H, seg) {
        if (finger === 'thumb') return seg * 0.62;
        var base = toPx(lm[FINGERS[finger][0]], W, H), n = NEIGHBORS[finger], s = 0;
        n.forEach(function (j) { s += dist(base, toPx(lm[j], W, H)); });
        var w = (s / n.length) * 0.82;
        return Math.min(seg * 0.85, Math.max(seg * 0.35, w));
    }

    function sampleAt(img, x, y) {
        var xi = Math.round(x), yi = Math.round(y);
        if (xi < 0 || yi < 0 || xi >= img.width || yi >= img.height) return null;
        var k = (yi * img.width + xi) * 4, d = img.data;
        return [d[k], d[k + 1], d[k + 2]];
    }
    function lumOf(c) { return 0.299 * c[0] + 0.587 * c[1] + 0.114 * c[2]; }
    function isSkin(c) {
        var r = c[0], g = c[1], b = c[2];
        var cb = 128 - 0.168736 * r - 0.331264 * g + 0.5 * b;
        var cr = 128 + 0.5 * r - 0.418688 * g - 0.081312 * b;
        return cr >= 133 && cr <= 180 && cb >= 70 && cb <= 130 && r > g;
    }

    /* Scan across the finger from (cx,cy) along (px,py) both ways until the skin ends
       (non-skin colour, or a sharp dark gap between fingers). */
    function measureFinger(img, cx, cy, px, py, est) {
        if (!img) return null;
        var i, c, sum = [0, 0, 0], n = 0;
        for (i = -2; i <= 2; i++) {
            c = sampleAt(img, cx + px * i, cy + py * i);
            if (c) { sum[0] += c[0]; sum[1] += c[1]; sum[2] += c[2]; n++; }
        }
        if (!n) return null;
        var ref = [sum[0] / n, sum[1] / n, sum[2] / n];
        var maxR = est * 1.7;
        function scan(dir) {
            var j, c2, l, runL = lumOf(ref), badSkin = 0, badLum = 0, last = 0, found = false;
            for (j = 1; j <= maxR; j++) {
                c2 = sampleAt(img, cx + dir * px * j, cy + dir * py * j);
                if (!c2) { found = true; break; }
                l = lumOf(c2);
                badSkin = isSkin(c2) ? 0 : badSkin + 1;
                badLum = (runL - l > 38) ? badLum + 1 : 0;
                if (badSkin >= 3 || badLum >= 2) { found = true; break; }
                if (badSkin === 0 && badLum === 0) {
                    last = j;
                    runL = runL * 0.85 + l * 0.15;
                }
            }
            return { d: last, found: found };
        }
        var L = scan(-1), R = scan(1);
        return { left: L.d, right: R.d, okBoth: L.found && R.found, skin: ref };
    }

    /* Ring: centred on the proximal phalanx, axis along the finger (knuckle → tip).
       With the photo pixels (img) it snaps width, centre AND tilt to the real finger. */
    function ring(lm, finger, W, H, pos, img) {
        var ids = FINGERS[finger] || FINGERS.ring;
        var a = toPx(lm[ids[0]], W, H), b = toPx(lm[ids[1]], W, H);
        var t = (pos == null) ? (finger === 'thumb' ? 0.5 : 0.46) : pos;
        var seg = Math.max(1, dist(a, b));
        var ax = (b.x - a.x) / seg, ay = (b.y - a.y) / seg;
        var cx = a.x + (b.x - a.x) * t, cy = a.y + (b.y - a.y) * t;
        var est = Math.max(12, fingerWidth(lm, finger, W, H, seg));
        var angle = deg(a, b), size = est, skin = null;

        function probe(ox, oy, ang) {
            var rad = ang * Math.PI / 180, px = -Math.sin(rad), py = Math.cos(rad);
            var m = measureFinger(img, ox, oy, px, py, est);
            if (!m) return null;
            var w = m.left + m.right + 1;
            if (!m.okBoth || w < est * 0.55 || w > est * 1.9) return { ok: false, skin: m.skin };
            var sh = (m.right - m.left) / 2;
            return { ok: true, x: ox + px * sh, y: oy + py * sh, w: w, skin: m.skin };
        }

        if (img) {
            var m0 = probe(cx, cy, angle);
            if (m0) skin = m0.skin;
            if (m0 && m0.ok) {
                var d1 = seg * 0.3;
                var mu = probe(m0.x + ax * d1, m0.y + ay * d1, angle);
                var ml = probe(m0.x - ax * d1, m0.y - ay * d1, angle);
                if (mu && mu.ok && ml && ml.ok) {
                    var ang2 = deg({ x: ml.x, y: ml.y }, { x: mu.x, y: mu.y });
                    var diff = ((ang2 - angle + 540) % 360) - 180;
                    if (Math.abs(diff) < 25) {
                        angle += diff * 0.85;
                        var m1 = probe(m0.x, m0.y, angle);
                        if (m1 && m1.ok) m0 = m1;
                    }
                }
                cx = m0.x; cy = m0.y; size = m0.w;
            }
        }
        return { x: cx, y: cy, size: size, baseRotation: angle + 90, anchor: 'center', skin: skin };
    }

    /* ---------- Wrist edge detection (bracelets + watches) ----------
       The forearm is measured straight from the photo: a skin colour is taken from the
       hand itself, then cross-sections are scanned sideways until the skin ends. The
       narrowest one near the wrist bone is where the piece sits; the line through the
       centres of the cross-sections gives the real forearm angle, and the change in
       width gives the taper, so the strap hugs the silhouette on both sides. ---------- */
    function ycc(c) {
        var r = c[0], g = c[1], b = c[2];
        return [0.299 * r + 0.587 * g + 0.114 * b,
                128 - 0.168736 * r - 0.331264 * g + 0.5 * b,
                128 + 0.5 * r - 0.418688 * g - 0.081312 * b];
    }
    function patchAt(img, x, y, rad) {
        var sum = [0, 0, 0], n = 0, i, j, c;
        for (j = -rad; j <= rad; j++) for (i = -rad; i <= rad; i++) {
            c = sampleAt(img, x + i, y + j);
            if (c) { sum[0] += c[0]; sum[1] += c[1]; sum[2] += c[2]; n++; }
        }
        return n ? [sum[0] / n, sum[1] / n, sum[2] / n] : null;
    }
    function median(a) { var s = a.slice().sort(function (p, q) { return p - q; }); return s[Math.floor(s.length / 2)]; }

    /* Reference skin colour, sampled on the palm / back of the hand */
    function handSkin(lm, W, H, img) {
        if (!img) return null;
        var wr = toPx(lm[0], W, H), m9 = toPx(lm[9], W, H), pts = [], cols = [];
        [0.3, 0.5, 0.7].forEach(function (t) { pts.push({ x: wr.x + (m9.x - wr.x) * t, y: wr.y + (m9.y - wr.y) * t }); });
        [5, 13, 17].forEach(function (id) {
            var q = toPx(lm[id], W, H);
            pts.push({ x: wr.x + (q.x - wr.x) * 0.45, y: wr.y + (q.y - wr.y) * 0.45 });
        });
        var rad = Math.max(2, Math.round(dist(wr, m9) * 0.04));
        pts.forEach(function (p) { var c = patchAt(img, p.x, p.y, rad); if (c) cols.push(c); });
        if (cols.length < 3) return null;
        var rgb = [0, 1, 2].map(function (k) { return median(cols.map(function (c) { return c[k]; })); });
        return { rgb: rgb, ycc: ycc(rgb) };
    }
    function likeSkin(c, ref) {
        var a = ycc(c), b = ref.ycc;
        var dc = Math.sqrt((a[1] - b[1]) * (a[1] - b[1]) + (a[2] - b[2]) * (a[2] - b[2]));
        var dy = a[0] - b[0];
        if (dy < -100 || dy > 80) return false;       /* sleeve, strap, table … far lighter / darker */
        return dc < 17 || (dc < 26 && isSkin(c));
    }
    /* Walk both ways from (ox,oy) along (px,py) until the skin stops */
    function scanAcross(img, ox, oy, px, py, ref, maxR) {
        var s0 = patchAt(img, ox, oy, 1);
        if (!s0 || !likeSkin(s0, ref)) return null;
        function side(dir) {
            var j, c, last = 0, miss = 0, border = false;
            for (j = 1; j <= maxR; j++) {
                c = patchAt(img, ox + dir * px * j, oy + dir * py * j, 1);
                if (!c) { border = true; break; }
                if (likeSkin(c, ref)) { last = j; miss = 0; }
                else if (++miss >= 4) break;
            }
            return { d: last, border: border };
        }
        var L = side(-1), R = side(1);
        return { left: L.d, right: R.d, border: L.border || R.border };
    }
    function crossSection(img, ref, ox, oy, px, py, k) {
        var m = scanAcross(img, ox, oy, px, py, ref, k * 0.95);
        if (!m || m.border) return null;
        var w = m.left + m.right + 1;
        if (w < k * 0.5 || w > k * 1.5) return null;
        var sh = (m.right - m.left) / 2;
        return { w: w, cx: ox + px * sh, cy: oy + py * sh,
                 lx: ox - px * m.left, ly: oy - py * m.left, rx: ox + px * m.right, ry: oy + py * m.right };
    }

    /* Wrist pose for a bracelet or a watch.
       Local frame: origin = centre of the wrist cross-section, +x = across the wrist,
       -y = toward the fingers. size = wrist width edge-to-edge; slope = how fast the
       wrist widens toward the elbow; thumbSide = which side (+1/-1) the thumb is on. */
    function wrist(lm, W, H, img, type) {
        var wr = toPx(lm[0], W, H), m9 = toPx(lm[9], W, H);
        var i5 = toPx(lm[5], W, H), p17 = toPx(lm[17], W, H);
        var palm = Math.max(1, dist(wr, m9)), k = Math.max(24, dist(i5, p17));
        var d = { x: (m9.x - wr.x) / palm, y: (m9.y - wr.y) / palm };      /* toward the fingers */
        var u = { x: -d.x, y: -d.y }, p = { x: -d.y, y: d.x };            /* toward the elbow / across */
        var thumbSide = ((i5.x - p17.x) * p.x + (i5.y - p17.y) * p.y) >= 0 ? 1 : -1;
        var s0 = type === 'watch' ? 0.30 : 0.22;       /* preferred distance past the wrist, × palm length */
        var ref = handSkin(lm, W, H, img);

        /* landmark-only estimate (used when the forearm edges can't be read) */
        var pose = {
            x: wr.x + u.x * palm * s0, y: wr.y + u.y * palm * s0,
            size: Math.max(30, k * 0.9), baseRotation: deg(wr, m9) + 90, anchor: 'center',
            skin: ref ? ref.rgb : null, slope: 0, thumbSide: thumbSide, fitted: false, edges: null
        };
        if (!img || !ref) return pose;

        /* pass 1: coarse cross-sections → forearm axis */
        var first = [], i, q, s;
        for (i = 0; i < 9; i++) {
            s = palm * (0.08 + i * 0.09);
            q = crossSection(img, ref, wr.x + u.x * s, wr.y + u.y * s, p.x, p.y, k);
            if (q) { q.s = s; q.lat = (q.cx - wr.x) * p.x + (q.cy - wr.y) * p.y; first.push(q); }
        }
        if (first.length < 3) return pose;

        var n = first.length, sx = 0, sy = 0, sxx = 0, sxy = 0;
        first.forEach(function (e) { sx += e.s; sy += e.lat; sxx += e.s * e.s; sxy += e.s * e.lat; });
        var den = n * sxx - sx * sx;
        var b = den > 1e-6 ? (n * sxy - sx * sy) / den : 0;
        b = Math.max(-0.42, Math.min(0.42, b));
        var a0 = (sy - b * sx) / n;
        var ang = Math.atan(b), ca = Math.cos(ang), sa = Math.sin(ang);
        var u2 = { x: u.x * ca + p.x * sa, y: u.y * ca + p.y * sa };       /* forearm axis (to the elbow) */
        var p2 = { x: p.x * ca - u.x * sa, y: p.y * ca - u.y * sa };       /* across the forearm */

        /* pass 2: fine cross-sections, perpendicular to the real forearm axis */
        var fine = [], j, s2, lat;
        for (j = 0; j < 12; j++) {
            s2 = palm * (0.08 + j * 0.06);
            lat = a0 + b * s2;
            q = crossSection(img, ref, wr.x + u.x * s2 + p.x * lat, wr.y + u.y * s2 + p.y * lat, p2.x, p2.y, k);
            if (q) { q.s = s2; fine.push(q); }
        }
        if (!fine.length) return pose;

        /* the wrist is the narrowest part near the wrist bone */
        var best = null, bestScore = 1e18;
        fine.forEach(function (e, idx) {
            var a = fine[idx - 1] || e, c2 = fine[idx + 1] || e;
            e.ws = (a.w + 2 * e.w + c2.w) / 4;
            var sc = e.ws * (1 + 0.6 * Math.abs(e.s / palm - s0));
            if (sc < bestScore) { bestScore = sc; best = e; }
        });

        /* taper: compare the width a little toward the hand and a little toward the elbow */
        var delta = palm * 0.18;
        function near(sv) {
            var r = null, dd = 1e18;
            fine.forEach(function (e) { var df = Math.abs(e.s - sv); if (df < dd) { dd = df; r = e; } });
            return (r && dd < palm * 0.07) ? r : null;
        }
        var eH = near(best.s - delta), eA = near(best.s + delta), slope = 0;
        if (eH && eA && eA.s > eH.s) slope = (eA.w - eH.w) / (eA.s - eH.s);
        slope = Math.max(-0.3, Math.min(0.3, slope));

        pose.x = best.cx; pose.y = best.cy;
        pose.size = Math.max(24, best.ws);
        pose.baseRotation = Math.atan2(-u2.y, -u2.x) * 180 / Math.PI + 90;
        pose.slope = slope;
        pose.fitted = true;
        pose.edges = [best, eH, eA].filter(function (e) { return !!e; })
                                   .map(function (e) { return [e.lx, e.ly, e.rx, e.ry]; });
        return pose;
    }
    function bracelet(lm, W, H, img) { return wrist(lm, W, H, img, 'bracelet'); }
    function watch(lm, W, H, img)    { return wrist(lm, W, H, img, 'watch'); }

    /* true when the palm faces the camera. trueHand = 'Left' | 'Right' (the person's real hand). */
    function palmFacing(lm, trueHand) {
        var c = (lm[5].x - lm[0].x) * (lm[17].y - lm[0].y) - (lm[5].y - lm[0].y) * (lm[17].x - lm[0].x);
        var rightPalm = c < 0;
        return trueHand === 'Right' ? rightPalm : !rightPalm;
    }

    /* Necklace: below the chin, follows head tilt. */
    function necklace(face, W, H) {
        var chin = toPx(face[152], W, H), top = toPx(face[10], W, H);
        var l = toPx(face[234], W, H), r = toPx(face[454], W, H);
        var fw = dist(l, r), fh = dist(top, chin), roll = deg(l, r);
        var rad = roll * Math.PI / 180;
        var dx = -Math.sin(rad), dy = Math.cos(rad);              /* "down" in the face frame */
        var off = fh * 0.5;
        return {
            x: chin.x + dx * off, y: chin.y + dy * off,
            size: Math.max(60, fw * 1.15),
            baseRotation: roll, anchor: 'center'
        };
    }

    /* Earrings: lobes sit a little below the ear line toward the jaw. Image top is anchored at the lobe. */
    function earrings(face, W, H) {
        var l = toPx(face[234], W, H), r = toPx(face[454], W, H);
        var lj = toPx(face[132], W, H), rj = toPx(face[361], W, H);
        var fw = dist(l, r), roll = deg(l, r), size = Math.max(18, fw * 0.16);
        return [
            { x: l.x + (lj.x - l.x) * 0.4, y: l.y + (lj.y - l.y) * 0.4, size: size, baseRotation: roll, anchor: 'top', side: 'L' },
            { x: r.x + (rj.x - r.x) * 0.4, y: r.y + (rj.y - r.y) * 0.4, size: size, baseRotation: roll, anchor: 'top', side: 'R' }
        ];
    }

    /* Flood-fill from the border, then also remove large ENCLOSED background areas
       (the hole inside a ring photo). */
    function removeBackground(data, w, h, tol) {
        var r = 0, g = 0, b = 0, n = 0, x, y;
        function acc(px, py) { var k = (py * w + px) * 4; r += data[k]; g += data[k + 1]; b += data[k + 2]; n++; }
        for (x = 0; x < w; x++) { acc(x, 0); acc(x, h - 1); }
        for (y = 1; y < h - 1; y++) { acc(0, y); acc(w - 1, y); }
        r /= n; g /= n; b /= n;
        var t2 = (tol || 60) * (tol || 60);
        var seen = new Uint8Array(w * h), stack = new Int32Array(w * h), sp = 0, removed = 0;
        function near(idx) {
            var k = idx * 4, dr = data[k] - r, dg = data[k + 1] - g, db = data[k + 2] - b;
            return dr * dr + dg * dg + db * db < t2;
        }
        function push(idx) { if (!seen[idx] && near(idx)) { seen[idx] = 1; stack[sp++] = idx; removed++; } }
        for (x = 0; x < w; x++) { push(x); push((h - 1) * w + x); }
        for (y = 0; y < h; y++) { push(y * w); push(y * w + w - 1); }
        while (sp > 0) {
            var idx = stack[--sp], cx = idx % w, cy = (idx - cx) / w;
            if (cx > 0) push(idx - 1);
            if (cx < w - 1) push(idx + 1);
            if (cy > 0) push(idx - w);
            if (cy < h - 1) push(idx + w);
        }

        /* enclosed holes */
        var minHole = Math.max(30, w * h * 0.004);
        var tried = new Uint8Array(w * h), buf = new Int32Array(w * h);
        function tpush(j) { if (!seen[j] && !tried[j] && near(j)) { tried[j] = 1; stack[sp++] = j; } }
        for (var s0 = 0; s0 < w * h; s0++) {
            if (seen[s0] || tried[s0] || !near(s0)) continue;
            var cnt = 0; sp = 0; tried[s0] = 1; stack[sp++] = s0;
            while (sp > 0) {
                var i2 = stack[--sp]; buf[cnt++] = i2;
                var x2 = i2 % w, y2 = (i2 - x2) / w;
                if (x2 > 0) tpush(i2 - 1);
                if (x2 < w - 1) tpush(i2 + 1);
                if (y2 > 0) tpush(i2 - w);
                if (y2 < h - 1) tpush(i2 + w);
            }
            if (cnt >= minHole) { for (var k2 = 0; k2 < cnt; k2++) seen[buf[k2]] = 1; removed += cnt; }
        }
        return { mask: seen, removed: removed };
    }

    function alphaBBox(data, w, h, thr) {
        var minX = w, minY = h, maxX = -1, maxY = -1, x, y;
        for (y = 0; y < h; y++) for (x = 0; x < w; x++) {
            if (data[(y * w + x) * 4 + 3] > (thr || 8)) {
                if (x < minX) minX = x; if (x > maxX) maxX = x;
                if (y < minY) minY = y; if (y > maxY) maxY = y;
            }
        }
        return maxX < 0 ? null : { x: minX, y: minY, w: maxX - minX + 1, h: maxY - minY + 1 };
    }

    return { FINGERS: FINGERS, ring: ring, bracelet: bracelet, watch: watch, wrist: wrist, palmFacing: palmFacing, necklace: necklace,
             earrings: earrings, removeBackground: removeBackground, alphaBBox: alphaBBox };
})();
/*PURE-END*/

(function () {
    'use strict';

    var $ = function (id) { return document.getElementById(id); };

    /* =============================================================
       State
       ============================================================= */
    var video         = $('video');
    var capturedPhoto = $('capturedPhoto');
    var canvas        = $('canvas');
    var ctx           = canvas.getContext('2d');
    var placeholder   = $('videoPlaceholder');
    var shutterBtn    = $('shutterBtn');
    var flash         = $('flash');
    var detectionBadge = $('detectionBadge');
    var detectionText  = $('detectionText');

    var stepNum  = $('stepNum');
    var stepText = $('stepText');

    var modeBannerTitle = $('modeBannerTitle');
    var modeBannerText  = $('modeBannerText');
    var modeBannerIcon  = $('modeBannerIcon');

    var retakeBtn = $('retakeBtn');
    var startCameraBtn = $('startCamera');
    var uploadPhotoBtn = $('uploadPhoto');

    var FINGER_LABEL = { thumb: 'Thumb', index: 'Index', middle: 'Middle', ring: 'Ring finger', pinky: 'Pinky' };

    var stream = null;
    var capturedImg = null;     /* HTMLImageElement of the photo */
    var baseData = null;        /* pixels of the photo (finger edge detection) */
    var baseCanvas = null;      /* offscreen copy of the photo (what AI sees, what we redraw) */

    var hands = null, faceMesh = null;
    var lastHandsResult = null, lastFaceResult = null;
    var mediaPipeReady = false, initPromise = null;
    var detecting = false, pendingDetect = false;

    var selectedProduct = null;
    var activeItems = [];
    var selectedItemIndex = -1;
    var currentItemSize = 80;        /* px, canvas space */
    var currentItemRotation = 0;     /* degrees, offset from auto angle */

    var autoFit = true;
    var useCutout = true;
    var showPoints = false;
    var wrapRing = true;
    var ringStyle = 'solitaire';
    var ringMetal = 'auto';
    var ringGem = 'diamond';
    var selectedFinger = 'ring';
    var selectedHand = 0;
    var braceletStyle = 'chain';
    var watchStyle = 'classic';
    var watchStrap = 'metal';
    var watchFace = 'auto';          /* auto | back | palm */

    function styleFor(type) {
        return type === 'bracelet' ? braceletStyle : (type === 'watch' ? watchStyle : ringStyle);
    }

    var mode = 'capture';

    /* =============================================================
       Mode: 'capture' | 'tryon'
       ============================================================= */
    function setMode(newMode) {
        mode = newMode;
        var container = $('videoContainer');
        var corners = ['cornerTL', 'cornerTR', 'cornerBL', 'cornerBR'];

        if (mode === 'capture') {
            stepNum.textContent = '1';
            stepText.textContent = 'Capture';
            shutterBtn.classList.add('show');
            corners.forEach(function (id) { $(id).classList.add('show'); });
            container.classList.remove('tryon-active');
            canvas.style.display = 'none';
            capturedPhoto.style.display = 'none';
            detectionBadge.classList.remove('show');
            retakeBtn.style.display = 'none';

            modeBannerIcon.className = 'fas fa-camera';
            modeBannerTitle.textContent = 'Step 1 — Take a photo';
            modeBannerText.textContent  = 'Frame yourself, then tap the shutter to capture your look.';
        } else {
            stepNum.textContent = '2';
            stepText.textContent = 'Try-On';
            shutterBtn.classList.remove('show');
            corners.forEach(function (id) { $(id).classList.remove('show'); });
            container.classList.add('tryon-active');
            canvas.style.display = 'block';
            retakeBtn.style.display = 'inline-flex';

            modeBannerIcon.className = 'fas fa-gem';
            modeBannerTitle.textContent = 'Step 2 — Try on jewelry';
            modeBannerText.textContent  = 'Choose a finger, then pick a piece — the AI places it. Click the photo to place manually.';
        }
    }

    /* =============================================================
       Toast / Loading helpers
       ============================================================= */
    function showNotification(message, type) {
        type = type || 'info';
        var icon = { success: 'fa-check-circle', error: 'fa-exclamation-circle', warning: 'fa-exclamation-triangle', info: 'fa-info-circle' }[type] || 'fa-info-circle';
        var el = document.createElement('div');
        el.className = 'toast-notification ' + type;
        var body = document.createElement('div');
        body.className = 'toast-body';
        var i = document.createElement('i'); i.className = 'fas ' + icon;
        var s = document.createElement('span'); s.textContent = message;
        body.appendChild(i); body.appendChild(s);
        el.appendChild(body);
        document.body.appendChild(el);
        setTimeout(function () {
            el.style.transition = 'opacity .3s ease'; el.style.opacity = '0';
            setTimeout(function () { el.remove(); }, 300);
        }, 3000);
    }
    function showLoading(text) {
        var el = $('loadingOverlay');
        if (text) el.querySelector('.loading-text').textContent = text;
        el.classList.add('active');
    }
    function hideLoading() { $('loadingOverlay').classList.remove('active'); }
    function setDetection(text, show) {
        if (show) { detectionText.textContent = text; detectionBadge.classList.add('show'); }
        else      { detectionBadge.classList.remove('show'); }
    }

    /* =============================================================
       MediaPipe init (lazy, single shared promise)
       ============================================================= */
    function initMediaPipe() {
        if (mediaPipeReady) return Promise.resolve(true);
        if (initPromise) return initPromise;

        if (typeof Hands === 'undefined' || typeof FaceMesh === 'undefined') {
            showNotification('AI library failed to load. Check your internet connection.', 'error');
            return Promise.resolve(false);
        }

        showLoading('Loading AI models…');
        initPromise = (async function () {
            try {
                hands = new Hands({ locateFile: function (f) { return 'https://cdn.jsdelivr.net/npm/@mediapipe/hands@0.4/' + f; } });
                hands.setOptions({ maxNumHands: 2, modelComplexity: 1, minDetectionConfidence: 0.6, minTrackingConfidence: 0.5 });
                hands.onResults(function (r) { lastHandsResult = r; });

                faceMesh = new FaceMesh({ locateFile: function (f) { return 'https://cdn.jsdelivr.net/npm/@mediapipe/face_mesh@0.4/' + f; } });
                faceMesh.setOptions({ maxNumFaces: 1, refineLandmarks: true, minDetectionConfidence: 0.6, minTrackingConfidence: 0.5 });
                faceMesh.onResults(function (r) { lastFaceResult = r; });

                var warmup = document.createElement('canvas');
                warmup.width = 64; warmup.height = 64;
                await hands.send({ image: warmup });
                await faceMesh.send({ image: warmup });

                mediaPipeReady = true;
                hideLoading();
                showNotification('AI ready — take a photo to start.', 'success');
                return true;
            } catch (err) {
                console.error(err);
                hideLoading();
                initPromise = null;
                showNotification('Could not load AI. Check your internet connection.', 'error');
                return false;
            }
        })();
        return initPromise;
    }

    /* =============================================================
       Camera
       ============================================================= */
    startCameraBtn.addEventListener('click', startCamera);

    async function startCamera() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            showNotification('Camera needs HTTPS (or localhost). You can still upload a photo.', 'error');
            return;
        }
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 960 } },
                audio: false
            });
            video.srcObject = stream;
            video.style.display = 'block';
            capturedPhoto.style.display = 'none';
            canvas.style.display = 'none';
            await video.play();

            placeholder.classList.add('hidden');
            setMode('capture');
            initMediaPipe();
        } catch (err) {
            console.error(err);
            showNotification('Unable to access camera. Please allow permissions or upload a photo.', 'error');
        }
    }

    function stopCamera() {
        if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
        video.srcObject = null;
    }

    /* =============================================================
       Shutter → capture photo
       ============================================================= */
    shutterBtn.addEventListener('click', capturePhoto);

    function capturePhoto() {
        if (!video.videoWidth) {
            showNotification('Camera is not ready yet.', 'warning');
            return;
        }
        flash.classList.add('fire');
        setTimeout(function () { flash.classList.remove('fire'); }, 450);

        var c = document.createElement('canvas');
        c.width  = video.videoWidth;
        c.height = video.videoHeight;
        c.getContext('2d').drawImage(video, 0, 0);
        var dataUrl = c.toDataURL('image/jpeg', 0.95);

        var img = new Image();
        img.onload = function () {
            stopCamera();
            showPhoto(img, dataUrl);
            showNotification('Photo captured! Choose a finger and pick a piece.', 'success');
        };
        img.src = dataUrl;
    }

    /* =============================================================
       Upload photo
       ============================================================= */
    uploadPhotoBtn.addEventListener('click', function () { $('photoUpload').click(); });

    $('photoUpload').addEventListener('change', function (e) {
        var file = e.target.files && e.target.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function (event) {
            var img = new Image();
            img.onload = function () {
                stopCamera();
                showPhoto(img, event.target.result);
                showNotification('Photo loaded! Choose a finger and pick a piece.', 'success');
            };
            img.src = event.target.result;
        };
        reader.readAsDataURL(file);
        this.value = '';
    });

    /* Shared by camera + upload */
    function showPhoto(img, dataUrl) {
        capturedPhoto.src = dataUrl;
        capturedPhoto.style.display = 'block';
        video.style.display = 'none';
        placeholder.classList.add('hidden');

        capturedImg = img;
        var k = Math.min(1, 1280 / Math.max(img.naturalWidth, img.naturalHeight));
        var w = Math.round(img.naturalWidth * k), h = Math.round(img.naturalHeight * k);

        baseCanvas = document.createElement('canvas');
        baseCanvas.width = w; baseCanvas.height = h;
        baseCanvas.getContext('2d').drawImage(img, 0, 0, w, h);

        canvas.width = w; canvas.height = h;
        baseData = null;

        /* Old items belonged to the previous photo */
        activeItems = []; selectedItemIndex = -1;
        lastHandsResult = null; lastFaceResult = null;
        buildHandPicker(0);
        updateActiveItemsList();

        setMode('tryon');
        redrawBase();
        runDetectionOnPhoto();
    }

    /* =============================================================
       AI detection on the static photo
       ============================================================= */
    async function runDetectionOnPhoto() {
        if (detecting) { pendingDetect = true; return; }
        var ok = await initMediaPipe();
        if (!ok || !baseCanvas) return;

        detecting = true;
        setDetection('Analyzing photo…', true);
        try {
            lastHandsResult = null; lastFaceResult = null;
            if (hands.reset) hands.reset();
            if (faceMesh.reset) faceMesh.reset();
            await hands.send({ image: baseCanvas });
            await faceMesh.send({ image: baseCanvas });

            var hl = (lastHandsResult && lastHandsResult.multiHandLandmarks) || [];
            var fl = (lastFaceResult && lastFaceResult.multiFaceLandmarks) || [];

            buildHandPicker(hl.length);

            var parts = [];
            if (hl.length > 0) parts.push(hl.length + ' hand' + (hl.length > 1 ? 's' : ''));
            if (fl.length > 0) parts.push('face');
            setDetection(parts.length ? 'AI sees ' + parts.join(' · ') : 'No hands/face found — click photo to place manually', true);

            if (selectedProduct && autoFit && activeItems.length === 0) autoPlace(selectedProduct);
            else redrawBase();
        } catch (err) {
            console.error(err);
            setDetection('Detection failed — click photo to place manually', true);
        }
        detecting = false;
        if (pendingDetect) { pendingDetect = false; runDetectionOnPhoto(); }
    }

    function getHand(i) {
        var l = lastHandsResult && lastHandsResult.multiHandLandmarks;
        if (!l || !l.length) return null;
        return l[Math.min(i == null ? selectedHand : i, l.length - 1)];
    }
    function getFace() {
        var l = lastFaceResult && lastFaceResult.multiFaceLandmarks;
        return (l && l.length) ? l[0] : null;
    }

    /* =============================================================
       Sprites: load product image, cut the background out,
       sample the ring metal colour, and extract the stone/head
       ============================================================= */
    var spriteCache = {};

    function getSprite(src) {
        var s = spriteCache[src];
        if (s) return s;
        s = spriteCache[src] = { raw: null, cut: null, metal: null, headCut: null, headRaw: null, ready: false, failed: false };
        loadSpriteImage(src, true, s);
        return s;
    }

    function loadSpriteImage(src, cors, s) {
        var img = new Image();
        if (cors) img.crossOrigin = 'anonymous';
        img.onload = function () {
            s.raw = img;
            s.cut = makeCutout(img);
            s.metal   = sampleMetal(s.cut);      /* ring metal colour */
            s.headCut = makeHead(s.cut, true);   /* stone / top of the ring */
            s.headRaw = makeHead(img, false);
            s.ready = true;
            redrawBase();
        };
        img.onerror = function () {
            if (cors) { loadSpriteImage(src, false, s); }
            else { s.failed = true; showNotification('Could not load the jewelry image.', 'error'); }
        };
        img.src = src;
    }

    function makeCutout(img) {
        try {
            var iw = img.naturalWidth, ih = img.naturalHeight;
            if (!iw || !ih) return img;
            var k = Math.min(1, 600 / Math.max(iw, ih));
            var w = Math.max(1, Math.round(iw * k)), h = Math.max(1, Math.round(ih * k));
            var c = document.createElement('canvas'); c.width = w; c.height = h;
            var x = c.getContext('2d'); x.drawImage(img, 0, 0, w, h);
            var d = x.getImageData(0, 0, w, h), data = d.data;   /* throws if the canvas is tainted */

            var hasAlpha = false;
            for (var i = 3; i < data.length; i += 28) { if (data[i] < 250) { hasAlpha = true; break; } }

            if (!hasAlpha) {
                var res = TryOnPure.removeBackground(data, w, h, 60);
                var frac = res.removed / (w * h);
                if (frac > 0.02 && frac < 0.985) {
                    var m = res.mask, e1 = new Uint8Array(w * h), e2 = new Uint8Array(w * h), px, py, idx;
                    var adj = function (arr, id, ax, ay) {
                        return (ax > 0 && arr[id - 1]) || (ax < w - 1 && arr[id + 1]) ||
                               (ay > 0 && arr[id - w]) || (ay < h - 1 && arr[id + w]);
                    };
                    for (py = 0; py < h; py++) for (px = 0; px < w; px++) {
                        idx = py * w + px; if (!m[idx] && adj(m, idx, px, py)) e1[idx] = 1;
                    }
                    for (py = 0; py < h; py++) for (px = 0; px < w; px++) {
                        idx = py * w + px; if (!m[idx] && !e1[idx] && adj(e1, idx, px, py)) e2[idx] = 1;
                    }
                    for (idx = 0; idx < w * h; idx++) {
                        if (m[idx] || e1[idx]) data[idx * 4 + 3] = 0;                 /* kills dark fringe */
                        else if (e2[idx]) data[idx * 4 + 3] = Math.min(data[idx * 4 + 3], 140);
                    }
                }
            }
            x.putImageData(d, 0, 0);

            var bb = TryOnPure.alphaBBox(data, w, h, 8);
            if (!bb || bb.w * bb.h > w * h * 0.98) return c;
            var pad = 2;
            var bx = Math.max(0, bb.x - pad), by = Math.max(0, bb.y - pad);
            var bw = Math.min(w - bx, bb.w + pad * 2), bh = Math.min(h - by, bb.h + pad * 2);
            var o = document.createElement('canvas'); o.width = bw; o.height = bh;
            o.getContext('2d').drawImage(c, bx, by, bw, bh, 0, 0, bw, bh);
            return o;
        } catch (e) {
            return img;     /* tainted canvas etc. → use the raw picture */
        }
    }

    /* Average colour of the ring metal (ignores white gems / dark pixels) */
    function sampleMetal(el) {
        try {
            var c = document.createElement('canvas'); c.width = c.height = 48;
            var x = c.getContext('2d'); x.drawImage(el, 0, 0, 48, 48);
            var d = x.getImageData(0, 0, 48, 48).data, r = 0, g = 0, b = 0, n = 0;
            for (var i = 0; i < d.length; i += 4) {
                if (d[i + 3] < 200) continue;
                var R = d[i], G = d[i + 1], B = d[i + 2], mx = Math.max(R, G, B), mn = Math.min(R, G, B);
                if ((mx > 235 && mn > 200) || mx < 50) continue;
                r += R; g += G; b += B; n++;
            }
            return n < 8 ? null : [r / n, g / n, b / n];
        } catch (e) { return null; }
    }

    /* Top-centre crop of the ring photo = the stone/setting only (no band, no hole) */
    function makeHead(el, trim) {
        try {
            var iw = el.naturalWidth || el.width, ih = el.naturalHeight || el.height;
            if (!iw || !ih) return null;
            var sx = Math.round(iw * 0.22), sw = Math.round(iw * 0.56), sh = Math.round(ih * 0.5);
            var c = document.createElement('canvas'); c.width = sw; c.height = sh;
            var x = c.getContext('2d'); x.drawImage(el, sx, 0, sw, sh, 0, 0, sw, sh);
            if (trim) {
                try {
                    var bb = TryOnPure.alphaBBox(x.getImageData(0, 0, sw, sh).data, sw, sh, 8);
                    if (bb && bb.w > 4 && bb.h > 4) {
                        var o = document.createElement('canvas'); o.width = bb.w; o.height = bb.h;
                        o.getContext('2d').drawImage(c, bb.x, bb.y, bb.w, bb.h, 0, 0, bb.w, bb.h);
                        return o;
                    }
                } catch (e) {}
            }
            return c;
        } catch (e) { return null; }
    }

    /* =============================================================
       Drawing
       ============================================================= */
    function redrawBase() {
        if (!baseCanvas) return;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(baseCanvas, 0, 0, canvas.width, canvas.height);
        activeItems.forEach(drawItem);
        if (showPoints) drawPoints();
    }

    /* =============================================================
       Ring templates — drawn in code and wrapped on the finger.
       Local space: origin = ring centre on the finger,
       +x = across the finger, -y = toward the fingertip.
       item.size = finger width in canvas px.
       ============================================================= */
    var METALS = { yellow: [221, 175, 66], rose: [219, 150, 126], white: [208, 211, 218] };
    var GEMS   = { diamond: [232, 242, 255], ruby: [206, 22, 58], sapphire: [30, 84, 205], emerald: [16, 158, 96] };

    function cl(v) { return Math.max(0, Math.min(255, Math.round(v))); }
    function shade(m, f, a) {
        return 'rgba(' + cl(m[0] * f) + ',' + cl(m[1] * f) + ',' + cl(m[2] * f) + ',' + (a == null ? 1 : a) + ')';
    }
    function metalFor(key, s) {
        if (key && key !== 'auto' && METALS[key]) return METALS[key];
        return (s && s.metal) ? s.metal : METALS.yellow;
    }

    function tone(m, f, skin, k) {
        if (!skin) return shade(m, f);
        return 'rgb(' + cl(m[0] * f * (1 - k) + skin[0] * 0.65 * k) + ',' +
                        cl(m[1] * f * (1 - k) + skin[1] * 0.65 * k) + ',' +
                        cl(m[2] * f * (1 - k) + skin[2] * 0.65 * k) + ')';
    }

    /* tiny pavé / halo stone */
    function drawMini(c, x, y, r, m) {
        c.fillStyle = shade(m, 0.7);
        c.beginPath(); c.arc(x, y, r * 1.22, 0, 7); c.fill();
        var gr = c.createRadialGradient(x - r * 0.3, y - r * 0.3, 0, x, y, r);
        gr.addColorStop(0, '#ffffff'); gr.addColorStop(1, 'rgb(158,176,210)');
        c.fillStyle = gr;
        c.beginPath(); c.arc(x, y, r, 0, 7); c.fill();
    }

    /* faceted round stone with metal seat + 4 prongs */
    function drawGem(c, x, y, r, gemKey, m) {
        var g = GEMS[gemKey] || GEMS.diamond, ry = r * 0.84, i, a, px, py;
        var dia = !GEMS[gemKey] || gemKey === 'diamond';

        c.save();
        c.shadowColor = 'rgba(0,0,0,.35)'; c.shadowBlur = r * 0.5; c.shadowOffsetY = r * 0.2;
        var seat = c.createRadialGradient(x - r * 0.3, y - r * 0.3, r * 0.1, x, y, r * 1.3);
        seat.addColorStop(0, shade(m, 1.25)); seat.addColorStop(1, shade(m, 0.6));
        c.fillStyle = seat;
        c.beginPath(); c.ellipse(x, y, r * 1.18, ry * 1.18, 0, 0, 7); c.fill();
        c.restore();

        var body = c.createRadialGradient(x - r * 0.25, y - ry * 0.3, r * 0.08, x, y, r);
        if (dia) {
            body.addColorStop(0, 'rgb(255,255,255)'); body.addColorStop(0.55, 'rgb(214,226,244)'); body.addColorStop(1, 'rgb(128,148,184)');
        } else {
            body.addColorStop(0, shade(g, 1.65)); body.addColorStop(0.55, shade(g, 1)); body.addColorStop(1, shade(g, 0.45));
        }
        c.fillStyle = body;
        c.beginPath(); c.ellipse(x, y, r, ry, 0, 0, 7); c.fill();

        var tr = 0.52;
        for (i = 0; i < 8; i++) {
            a = i * Math.PI / 4;
            c.beginPath();
            c.moveTo(x + Math.cos(a - Math.PI / 8) * r * tr, y + Math.sin(a - Math.PI / 8) * ry * tr);
            c.lineTo(x + Math.cos(a) * r * 0.98, y + Math.sin(a) * ry * 0.98);
            c.lineTo(x + Math.cos(a + Math.PI / 8) * r * tr, y + Math.sin(a + Math.PI / 8) * ry * tr);
            c.closePath();
            c.fillStyle = (i % 2) ? 'rgba(0,0,0,.16)' : 'rgba(255,255,255,.34)';
            c.fill();
        }
        c.beginPath();
        for (i = 0; i < 8; i++) {
            a = i * Math.PI / 4 + Math.PI / 8;
            px = x + Math.cos(a) * r * tr; py = y + Math.sin(a) * ry * tr;
            if (i) c.lineTo(px, py); else c.moveTo(px, py);
        }
        c.closePath();
        c.fillStyle = dia ? 'rgba(255,255,255,.55)' : 'rgba(255,255,255,.22)'; c.fill();
        c.strokeStyle = 'rgba(255,255,255,.55)'; c.lineWidth = Math.max(0.5, r * 0.05); c.stroke();

        c.beginPath(); c.ellipse(x, y, r, ry, 0, 0, 7);
        c.strokeStyle = dia ? 'rgba(90,105,140,.7)' : shade(g, 0.35, 0.9);
        c.lineWidth = Math.max(0.6, r * 0.07); c.stroke();

        var sx = x - r * 0.28, sy = y - ry * 0.3, sl = r * 0.5;
        c.save();
        c.shadowColor = 'rgba(255,255,255,.9)'; c.shadowBlur = r * 0.5;
        c.strokeStyle = 'rgba(255,255,255,.95)'; c.lineWidth = Math.max(0.6, r * 0.07);
        c.beginPath(); c.moveTo(sx - sl, sy); c.lineTo(sx + sl, sy); c.moveTo(sx, sy - sl); c.lineTo(sx, sy + sl); c.stroke();
        c.fillStyle = '#fff'; c.beginPath(); c.arc(sx, sy, r * 0.1, 0, 7); c.fill();
        c.restore();

        for (i = 0; i < 4; i++) {
            a = Math.PI / 4 + i * Math.PI / 2;
            px = x + Math.cos(a) * r * 1.02; py = y + Math.sin(a) * ry * 1.02;
            var pg = c.createRadialGradient(px - r * 0.05, py - r * 0.05, 0, px, py, r * 0.2);
            pg.addColorStop(0, shade(m, 1.6)); pg.addColorStop(1, shade(m, 0.7));
            c.fillStyle = pg;
            c.beginPath(); c.arc(px, py, r * 0.18, 0, 7); c.fill();
        }
    }

    /* Whole ring, drawn the way it looks when worn: the band wraps the finger and
       disappears at the finger's edges, with a contact shadow on the skin and a
       little skin colour reflected in the metal. */
    function renderRing(c, fw, style, m, gemKey, skin) {
        var rx = fw * 0.54, sag = fw * 0.05;
        var t = Math.max(3, fw * (style === 'pave' ? 0.22 : 0.17));
        var N = 40, i, k, x, a;

        function yc(xx) { return sag * (1 - (xx / rx) * (xx / rx)); }
        function th(xx) { return t * (0.8 + 0.2 * (1 - (xx / rx) * (xx / rx))); }
        function xAt(idx) { return -rx + 2 * rx * idx / N; }
        function bandPath() {
            c.beginPath();
            for (i = 0; i <= N; i++) { x = xAt(i); c.lineTo(x, yc(x) - th(x) / 2); }
            for (i = N; i >= 0; i--) { x = xAt(i); c.lineTo(x, yc(x) + th(x) / 2); }
            c.closePath();
        }
        function edge(sign, inset) {
            c.beginPath();
            for (i = 0; i <= N; i++) { x = xAt(i) * 0.97; c.lineTo(x, yc(x) + sign * (th(x) / 2 - inset)); }
        }

        c.save();
        /* everything is clipped to the finger, so nothing sticks out past its edges */
        c.beginPath(); c.rect(-fw * 0.535, -fw * 4, fw * 1.07, fw * 8); c.clip();

        /* 1. contact shadow on the skin (both sides of the band) */
        c.save();
        c.shadowColor = 'rgba(55,28,16,.65)';
        c.shadowBlur = t * 1.5;
        c.shadowOffsetY = t * 0.3;
        c.strokeStyle = 'rgba(55,28,16,.5)';
        c.lineWidth = t * 1.05; c.lineCap = 'butt';
        c.beginPath();
        for (i = 0; i <= N; i++) { x = xAt(i); c.lineTo(x, yc(x) + t * 0.1); }
        c.stroke();
        c.restore();

        /* 2. metal band, shaded like a cylinder, edges pick up skin colour */
        var g = c.createLinearGradient(-rx, 0, rx, 0);
        g.addColorStop(0,    tone(m, 0.42, skin, 0.32));
        g.addColorStop(0.16, tone(m, 0.90, skin, 0.12));
        g.addColorStop(0.36, tone(m, 1.28, skin, 0.03));
        g.addColorStop(0.58, tone(m, 0.95, skin, 0.08));
        g.addColorStop(0.82, tone(m, 0.66, skin, 0.22));
        g.addColorStop(1,    tone(m, 0.40, skin, 0.34));
        bandPath(); c.fillStyle = g; c.fill();
        var vg = c.createLinearGradient(0, -t / 2, 0, sag + t / 2);
        vg.addColorStop(0, 'rgba(255,255,255,.24)');
        vg.addColorStop(0.5, 'rgba(255,255,255,0)');
        vg.addColorStop(1, 'rgba(0,0,0,.28)');
        bandPath(); c.fillStyle = vg; c.fill();

        /* 3. bevelled edges + specular streak */
        c.lineCap = 'round';
        c.lineWidth = Math.max(0.6, t * 0.13);
        edge(-1, t * 0.08); c.strokeStyle = 'rgba(255,255,255,.55)'; c.stroke();
        edge(1,  t * 0.08); c.strokeStyle = shade(m, 0.38, 0.85);    c.stroke();
        var sp = c.createLinearGradient(-rx, 0, rx, 0);
        sp.addColorStop(0, 'rgba(255,255,255,0)');
        sp.addColorStop(0.28, 'rgba(255,255,255,0)');
        sp.addColorStop(0.36, 'rgba(255,255,255,.6)');
        sp.addColorStop(0.44, 'rgba(255,255,255,0)');
        sp.addColorStop(0.78, 'rgba(255,255,255,0)');
        sp.addColorStop(0.84, 'rgba(255,255,255,.2)');
        sp.addColorStop(0.9, 'rgba(255,255,255,0)');
        bandPath(); c.fillStyle = sp; c.fill();

        /* 4. setting on top of the finger */
        var sy = yc(0);
        if (style === 'pave') {
            var n = 4, r0 = t * 0.26, sc;
            for (k = -n; k <= n; k++) {
                if (Math.abs(k) <= 1) continue;
                x = k * (rx * 0.9 / n);
                sc = 0.5 + 0.5 * Math.cos(Math.PI * x / (2 * rx));
                drawMini(c, x, yc(x), r0 * sc, m);
            }
            drawGem(c, 0, sy - fw * 0.12, fw * 0.17, gemKey, m);
        } else if (style === 'solitaire') {
            drawGem(c, 0, sy - fw * 0.13, fw * 0.19, gemKey, m);
        } else if (style === 'halo') {
            var hr = fw * 0.13, R = hr * 2.05, cy = sy - fw * 0.12;
            var pg = c.createRadialGradient(-R * 0.3, cy - R * 0.3, R * 0.1, 0, cy, R);
            pg.addColorStop(0, shade(m, 1.3)); pg.addColorStop(1, shade(m, 0.6));
            c.save();
            c.shadowColor = 'rgba(0,0,0,.4)'; c.shadowBlur = R * 0.45; c.shadowOffsetY = R * 0.18;
            c.fillStyle = pg;
            c.beginPath(); c.ellipse(0, cy, R, R * 0.84, 0, 0, 7); c.fill();
            c.restore();
            for (k = 0; k < 12; k++) {
                a = k * Math.PI / 6;
                drawMini(c, Math.cos(a) * hr * 1.55, cy + Math.sin(a) * hr * 1.55 * 0.84, hr * 0.27, m);
            }
            drawGem(c, 0, cy, hr, gemKey, m);
        } else if (style === 'three') {
            var off = fw * 0.30, cr = fw * 0.15, sr = fw * 0.105;
            drawGem(c, -off, yc(off) - fw * 0.07, sr, gemKey, m);
            drawGem(c,  off, yc(off) - fw * 0.07, sr, gemKey, m);
            drawGem(c, 0, sy - fw * 0.10, cr, gemKey, m);
        }
        /* style === 'band' → plain band only */

        c.restore();
    }

    /* =============================================================
       Wrist templates — bracelets + watches, drawn in code and fitted to the
       wrist edges found by TryOnPure.wrist().
       Local space: origin = centre of the wrist cross-section,
       +x = across the wrist, -y = toward the fingers.
       item.size = wrist width (edge to edge), item.slope = taper.
       ============================================================= */
    var BR_T    = { chain: 0.078, bangle: 0.12, tennis: 0.105, beaded: 0.15, cuff: 0.30 };
    var WATCH_R = { classic: 0.30, square: 0.285, minimal: 0.27, chrono: 0.31, digital: 0.29 };
    var STRAPS  = { brown: [112, 70, 40], black: [32, 29, 29], rubber: [30, 32, 38] };

    function halfAt(w, slope, y) { return Math.max(w * 0.3, w + slope * y) / 2; }

    /* keep everything inside the wrist silhouette (which tapers with slope) */
    function clipWrist(c, w, slope, ext) {
        var h0 = halfAt(w, slope, -ext), h1 = halfAt(w, slope, ext);
        c.beginPath();
        c.moveTo(-h0, -ext); c.lineTo(h0, -ext); c.lineTo(h1, ext); c.lineTo(-h1, ext);
        c.closePath(); c.clip();
    }

    function rrect(c, x, y, w, h, r) {
        r = Math.max(0, Math.min(r, w / 2, h / 2));
        c.beginPath();
        c.moveTo(x + r, y);
        c.lineTo(x + w - r, y);     c.arcTo(x + w, y, x + w, y + r, r);
        c.lineTo(x + w, y + h - r); c.arcTo(x + w, y + h, x + w - r, y + h, r);
        c.lineTo(x + r, y + h);     c.arcTo(x, y + h, x, y + h - r, r);
        c.lineTo(x, y + r);         c.arcTo(x, y, x + r, y, r);
        c.closePath();
    }

    /* A strip wrapped round the wrist, curved like a cylinder seen from slightly above. */
    function wrapBand(w, slope, y0, t, sag, N) {
        var hw = halfAt(w, slope, y0), b = { hw: hw, t: t, y0: y0, sag: sag, N: N };
        b.xAt = function (idx) { return -hw + 2 * hw * idx / N; };
        b.yc  = function (xx) { var r = xx / hw; return y0 + sag * (1 - r * r); };
        b.th  = function (xx) { var r = xx / hw; return t * (0.86 + 0.14 * (1 - r * r)); };
        b.path = function (c) {
            var i, x;
            c.beginPath();
            for (i = 0; i <= N; i++) { x = b.xAt(i); c.lineTo(x, b.yc(x) - b.th(x) / 2); }
            for (i = N; i >= 0; i--) { x = b.xAt(i); c.lineTo(x, b.yc(x) + b.th(x) / 2); }
            c.closePath();
        };
        b.edge = function (c, sign, inset) {
            var i, x;
            c.beginPath();
            for (i = 0; i <= N; i++) { x = b.xAt(i) * 0.97; c.lineTo(x, b.yc(x) + sign * (b.th(x) / 2 - inset)); }
        };
        return b;
    }
    function lineAt(c, b, f, col, lwid) {
        var i, x;
        c.beginPath();
        for (i = 0; i <= b.N; i++) { x = b.xAt(i) * 0.97; c.lineTo(x, b.yc(x) + f * b.th(x)); }
        c.strokeStyle = col; c.lineWidth = lwid; c.lineCap = 'round'; c.stroke();
    }
    function bandShadow(c, b) {
        var i, x;
        c.save();
        c.shadowColor = 'rgba(55,28,16,.65)'; c.shadowBlur = b.t * 1.4; c.shadowOffsetY = b.t * 0.3;
        c.strokeStyle = 'rgba(55,28,16,.5)'; c.lineWidth = b.t; c.lineCap = 'butt';
        c.beginPath();
        for (i = 0; i <= b.N; i++) { x = b.xAt(i); c.lineTo(x, b.yc(x) + b.t * 0.1); }
        c.stroke();
        c.restore();
    }
    function metalPaint(c, x0, y0, x1, y1, m) {
        var g = c.createLinearGradient(x0, y0, x1, y1);
        g.addColorStop(0, shade(m, 1.45)); g.addColorStop(0.35, shade(m, 1.05));
        g.addColorStop(0.55, shade(m, 0.6)); g.addColorStop(0.78, shade(m, 1.0)); g.addColorStop(1, shade(m, 0.5));
        return g;
    }

    /* polished metal band: shaded like a cylinder, edges pick up a little skin colour */
    function paintMetal(c, b, m, skin) {
        var t = b.t, hw = b.hw, g, vg, sp;
        bandShadow(c, b);
        g = c.createLinearGradient(-hw, 0, hw, 0);
        g.addColorStop(0,    tone(m, 0.42, skin, 0.32));
        g.addColorStop(0.16, tone(m, 0.90, skin, 0.12));
        g.addColorStop(0.36, tone(m, 1.28, skin, 0.03));
        g.addColorStop(0.58, tone(m, 0.95, skin, 0.08));
        g.addColorStop(0.82, tone(m, 0.66, skin, 0.22));
        g.addColorStop(1,    tone(m, 0.40, skin, 0.34));
        b.path(c); c.fillStyle = g; c.fill();
        vg = c.createLinearGradient(0, b.y0 - t / 2, 0, b.y0 + b.sag + t / 2);
        vg.addColorStop(0, 'rgba(255,255,255,.24)'); vg.addColorStop(0.5, 'rgba(255,255,255,0)'); vg.addColorStop(1, 'rgba(0,0,0,.28)');
        b.path(c); c.fillStyle = vg; c.fill();
        c.lineCap = 'round'; c.lineWidth = Math.max(0.6, t * 0.13);
        b.edge(c, -1, t * 0.08); c.strokeStyle = 'rgba(255,255,255,.55)'; c.stroke();
        b.edge(c, 1,  t * 0.08); c.strokeStyle = shade(m, 0.38, 0.85);    c.stroke();
        sp = c.createLinearGradient(-hw, 0, hw, 0);
        sp.addColorStop(0, 'rgba(255,255,255,0)');   sp.addColorStop(0.28, 'rgba(255,255,255,0)');
        sp.addColorStop(0.36, 'rgba(255,255,255,.6)'); sp.addColorStop(0.44, 'rgba(255,255,255,0)');
        sp.addColorStop(0.78, 'rgba(255,255,255,0)'); sp.addColorStop(0.84, 'rgba(255,255,255,.2)'); sp.addColorStop(0.9, 'rgba(255,255,255,0)');
        b.path(c); c.fillStyle = sp; c.fill();
    }

    /* ---------- bracelets ---------- */
    function drawChain(c, b, m, skin) {
        var n = 17, i, ang, x, y, len, ht = b.t * 0.95, lwid = ht * 0.26, g;
        bandShadow(c, b);
        [0, 1].forEach(function (pass) {                 /* pass 0 = edge-on links, pass 1 = flat links on top */
            for (i = 0; i < n; i++) {
                if ((i % 2) !== (pass ? 0 : 1)) continue;
                ang = -1.38 + 2.76 * i / (n - 1);
                x = b.hw * Math.sin(ang); y = b.yc(x);
                len = Math.max(ht * 0.55, b.hw * 2.76 / (n - 1) * Math.cos(ang) * 1.9);
                c.save(); c.translate(x, y);
                g = c.createLinearGradient(0, -ht / 2, 0, ht / 2);
                g.addColorStop(0, tone(m, 1.35, skin, 0.03)); g.addColorStop(0.5, tone(m, 0.95, skin, 0.08)); g.addColorStop(1, tone(m, 0.5, skin, 0.2));
                c.shadowColor = 'rgba(0,0,0,.35)'; c.shadowBlur = ht * 0.25; c.shadowOffsetY = ht * 0.1;
                if (pass) {
                    c.strokeStyle = g; c.lineWidth = lwid;
                    c.beginPath(); c.ellipse(0, 0, Math.max(1, len / 2 - lwid / 2), Math.max(1, ht / 2 - lwid / 2), 0, 0, 7); c.stroke();
                    c.shadowColor = 'rgba(0,0,0,0)';
                    c.strokeStyle = 'rgba(255,255,255,.5)'; c.lineWidth = Math.max(0.5, lwid * 0.25);
                    c.beginPath(); c.ellipse(0, 0, Math.max(1, len / 2 - lwid * 0.75), Math.max(1, ht / 2 - lwid * 0.75), 0, Math.PI * 1.05, Math.PI * 1.8); c.stroke();
                } else {
                    c.fillStyle = g;
                    c.beginPath(); c.ellipse(0, 0, Math.max(lwid * 0.6, len * 0.16), Math.max(1, ht / 2 - lwid * 0.2), 0, 0, 7); c.fill();
                }
                c.restore();
            }
        });
    }

    function drawTennis(c, b, m, skin, gemKey) {
        var n = 15, r = b.t * 0.44, idx = [], i, ang, x, y;
        paintMetal(c, b, m, skin);
        for (i = 0; i < n; i++) idx.push(i);
        idx.sort(function (p, q) { return Math.abs(q - (n - 1) / 2) - Math.abs(p - (n - 1) / 2); });   /* edges first */
        idx.forEach(function (k) {
            ang = -1.32 + 2.64 * k / (n - 1);
            x = b.hw * Math.sin(ang); y = b.yc(x);
            c.save(); c.translate(x, y); c.scale(Math.max(0.28, Math.cos(ang)), 1);
            drawGem(c, 0, 0, r, gemKey, m);
            c.restore();
        });
    }

    function drawBeads(c, b, m, skin, gemKey) {
        var n = 13, r = b.t * 0.37, idx = [], i, ang, x, y, sc, col, g, isMetal;
        var pearl = (gemKey === 'diamond' || !GEMS[gemKey]);
        col = pearl ? [246, 241, 232] : GEMS[gemKey];
        bandShadow(c, b);
        lineAt(c, b, 0, shade(m, 0.5, 0.9), Math.max(1, b.t * 0.06));          /* thread */
        for (i = 0; i < n; i++) idx.push(i);
        idx.sort(function (p, q) { return Math.abs(q - (n - 1) / 2) - Math.abs(p - (n - 1) / 2); });
        idx.forEach(function (k) {
            ang = -1.3 + 2.6 * k / (n - 1);
            x = b.hw * Math.sin(ang); y = b.yc(x); sc = Math.max(0.3, Math.cos(ang));
            isMetal = (k % 4 === 2);
            c.save(); c.translate(x, y); c.scale(sc, 1);
            c.shadowColor = 'rgba(0,0,0,.4)'; c.shadowBlur = r * 0.5; c.shadowOffsetY = r * 0.25;
            g = c.createRadialGradient(-r * 0.3, -r * 0.35, r * 0.05, 0, 0, r);
            if (isMetal) { g.addColorStop(0, shade(m, 1.7)); g.addColorStop(0.55, shade(m, 1)); g.addColorStop(1, shade(m, 0.45)); }
            else if (pearl) { g.addColorStop(0, 'rgb(255,255,255)'); g.addColorStop(0.55, 'rgb(' + col.join(',') + ')'); g.addColorStop(1, shade(col, 0.66)); }
            else { g.addColorStop(0, shade(col, 1.7)); g.addColorStop(0.5, shade(col, 1)); g.addColorStop(1, shade(col, 0.4)); }
            c.fillStyle = g;
            c.beginPath(); c.arc(0, 0, r, 0, 7); c.fill();
            c.shadowColor = 'rgba(0,0,0,0)';
            c.fillStyle = 'rgba(255,255,255,.75)';
            c.beginPath(); c.ellipse(-r * 0.32, -r * 0.38, r * 0.2, r * 0.13, -0.6, 0, 7); c.fill();
            c.restore();
        });
    }

    function drawCuff(c, b, m, skin) {
        var i, x, y, ang;
        paintMetal(c, b, m, skin);
        lineAt(c, b, -0.30, 'rgba(0,0,0,.45)', Math.max(0.6, b.t * 0.025));
        lineAt(c, b, -0.28, 'rgba(255,255,255,.45)', Math.max(0.5, b.t * 0.02));
        lineAt(c, b,  0.30, 'rgba(0,0,0,.45)', Math.max(0.6, b.t * 0.025));
        lineAt(c, b,  0.32, 'rgba(255,255,255,.35)', Math.max(0.5, b.t * 0.02));
        for (i = 0; i < 9; i++) {                                              /* engraved studs */
            ang = -1.1 + 2.2 * i / 8;
            x = b.hw * Math.sin(ang); y = b.yc(x);
            c.save(); c.translate(x, y); c.scale(Math.max(0.3, Math.cos(ang)), 1);
            c.fillStyle = shade(m, 0.45, 0.8); c.beginPath(); c.arc(0, 0, b.t * 0.075, 0, 7); c.fill();
            c.fillStyle = 'rgba(255,255,255,.55)'; c.beginPath(); c.arc(-b.t * 0.02, -b.t * 0.02, b.t * 0.04, 0, 7); c.fill();
            c.restore();
        }
    }

    function renderBracelet(c, w, slope, style, m, gemKey, skin) {
        var t = w * (BR_T[style] || 0.1);
        var b = wrapBand(w, slope, 0, t, w * 0.05, 48);
        c.save();
        clipWrist(c, w, slope, w);
        if (style === 'chain')       drawChain(c, b, m, skin);
        else if (style === 'tennis') drawTennis(c, b, m, skin, gemKey);
        else if (style === 'beaded') drawBeads(c, b, m, skin, gemKey);
        else if (style === 'cuff')   drawCuff(c, b, m, skin);
        else                         paintMetal(c, b, m, skin);                /* bangle */
        c.restore();
    }

    /* ---------- watches ---------- */
    function paintStrap(c, b, key, m, skin) {
        var hw = b.hw, t = b.t, i, x, ang, g, vg, rgb;
        if (key === 'metal' || !STRAPS[key]) {
            paintMetal(c, b, m, skin);
            c.lineCap = 'butt';
            for (i = 1; i < 11; i++) {                                          /* link seams */
                ang = -1.32 + 2.64 * i / 11; x = hw * Math.sin(ang);
                c.strokeStyle = 'rgba(0,0,0,.4)'; c.lineWidth = Math.max(0.6, t * 0.035);
                c.beginPath(); c.moveTo(x, b.yc(x) - b.th(x) * 0.5); c.lineTo(x, b.yc(x) + b.th(x) * 0.5); c.stroke();
                c.strokeStyle = 'rgba(255,255,255,.35)'; c.lineWidth = Math.max(0.5, t * 0.02);
                c.beginPath(); c.moveTo(x + t * 0.04, b.yc(x) - b.th(x) * 0.5); c.lineTo(x + t * 0.04, b.yc(x) + b.th(x) * 0.5); c.stroke();
            }
            lineAt(c, b, 0, 'rgba(0,0,0,.28)', Math.max(0.5, t * 0.025));
            return;
        }
        rgb = STRAPS[key];
        bandShadow(c, b);
        g = c.createLinearGradient(-hw, 0, hw, 0);
        g.addColorStop(0, shade(rgb, 0.35)); g.addColorStop(0.2, shade(rgb, 0.8)); g.addColorStop(0.4, shade(rgb, 1.3));
        g.addColorStop(0.62, shade(rgb, 0.95)); g.addColorStop(0.85, shade(rgb, 0.6)); g.addColorStop(1, shade(rgb, 0.32));
        b.path(c); c.fillStyle = g; c.fill();
        vg = c.createLinearGradient(0, b.y0 - t / 2, 0, b.y0 + b.sag + t / 2);
        vg.addColorStop(0, 'rgba(255,255,255,.12)'); vg.addColorStop(0.5, 'rgba(255,255,255,0)'); vg.addColorStop(1, 'rgba(0,0,0,.3)');
        b.path(c); c.fillStyle = vg; c.fill();
        if (key === 'rubber') {
            c.strokeStyle = 'rgba(0,0,0,.4)'; c.lineWidth = Math.max(0.6, t * 0.03); c.lineCap = 'butt';
            for (i = 1; i < 13; i++) {
                ang = -1.3 + 2.6 * i / 13; x = hw * Math.sin(ang);
                c.beginPath(); c.moveTo(x, b.yc(x) - b.th(x) * 0.42); c.lineTo(x, b.yc(x) + b.th(x) * 0.42); c.stroke();
            }
        } else {
            c.save();
            c.setLineDash([t * 0.12, t * 0.09]);
            c.lineWidth = Math.max(0.6, t * 0.03);
            c.strokeStyle = key === 'brown' ? 'rgba(238,216,172,.78)' : 'rgba(200,200,200,.5)';
            b.edge(c, -1, t * 0.12); c.stroke();
            b.edge(c, 1,  t * 0.12); c.stroke();
            c.restore();
        }
        c.lineCap = 'round'; c.lineWidth = Math.max(0.6, t * 0.05);
        b.edge(c, -1, t * 0.03); c.strokeStyle = 'rgba(255,255,255,.22)'; c.stroke();
        var sp = c.createLinearGradient(-hw, 0, hw, 0);
        sp.addColorStop(0, 'rgba(255,255,255,0)'); sp.addColorStop(0.3, 'rgba(255,255,255,0)');
        sp.addColorStop(0.38, 'rgba(255,255,255,.28)'); sp.addColorStop(0.46, 'rgba(255,255,255,0)'); sp.addColorStop(1, 'rgba(255,255,255,0)');
        b.path(c); c.fillStyle = sp; c.fill();
    }

    /* when the palm faces the camera the watch face is on the other side: show the clasp */
    function drawClasp(c, w, sw, m) {
        var cw = w * 0.30, ch = sw * 0.98;
        c.save();
        c.shadowColor = 'rgba(0,0,0,.45)'; c.shadowBlur = w * 0.02; c.shadowOffsetY = w * 0.012;
        c.fillStyle = metalPaint(c, -cw / 2, -ch / 2, cw / 2, ch / 2, m);
        rrect(c, -cw / 2, -ch / 2, cw, ch, w * 0.02); c.fill();
        c.restore();
        c.strokeStyle = shade(m, 0.4, 0.85); c.lineWidth = Math.max(0.6, w * 0.006);
        rrect(c, -cw / 2, -ch / 2, cw, ch, w * 0.02); c.stroke();
        c.beginPath(); c.moveTo(-cw / 2, 0); c.lineTo(cw / 2, 0); c.stroke();
        [-1, 1].forEach(function (sx) {
            c.fillStyle = shade(m, 0.55, 0.9);
            c.beginPath(); c.arc(sx * cw * 0.3, -ch * 0.27, w * 0.012, 0, 7); c.fill();
            c.beginPath(); c.arc(sx * cw * 0.3,  ch * 0.27, w * 0.012, 0, 7); c.fill();
        });
    }

    function wHand(c, ang, len, wid, tail, fill, stroke) {
        c.save(); c.rotate(ang * Math.PI / 180);
        c.beginPath(); c.moveTo(0, tail); c.lineTo(-wid / 2, 0); c.lineTo(0, -len); c.lineTo(wid / 2, 0); c.closePath();
        c.shadowColor = 'rgba(0,0,0,.4)'; c.shadowBlur = wid * 0.9; c.shadowOffsetY = wid * 0.45;
        c.fillStyle = fill; c.fill();
        c.shadowColor = 'rgba(0,0,0,0)';
        if (stroke) { c.strokeStyle = stroke; c.lineWidth = Math.max(0.5, wid * 0.12); c.stroke(); }
        c.restore();
    }

    function renderWatch(c, w, slope, style, strapKey, m, skin, faceUp, thumbSide) {
        var sw = w * (style === 'digital' ? 0.40 : 0.36);
        var b = wrapBand(w, slope, 0, sw, w * 0.045, 48);
        var cs = thumbSide || 1, i;

        /* strap, wrapped round the wrist and clipped to its silhouette */
        c.save(); clipWrist(c, w, slope, w); paintStrap(c, b, strapKey, m, skin); c.restore();

        if (!faceUp) { drawClasp(c, w, sw, m); return; }

        var R  = w * (WATCH_R[style] || 0.30);
        var dg = style === 'digital', sq = style === 'square';
        var cm = dg ? [52, 55, 62] : m;
        var hx = dg ? R * 1.04 : R, hy = dg ? R * 0.86 : R;

        function shape(k) {
            if (sq)      rrect(c, -hx * k, -hy * k, 2 * hx * k, 2 * hy * k, R * 0.22 * k);
            else if (dg) rrect(c, -hx * k, -hy * k, 2 * hx * k, 2 * hy * k, R * 0.20 * k);
            else { c.beginPath(); c.arc(0, 0, R * k, 0, 7); }
        }
        function dialFill(k, inner, outer) {
            var g = c.createRadialGradient(-R * 0.15, -R * 0.2, R * 0.05, 0, 0, R * 1.05);
            g.addColorStop(0, 'rgb(' + inner.join(',') + ')'); g.addColorStop(1, 'rgb(' + outer.join(',') + ')');
            shape(k); c.fillStyle = g; c.fill();
            shape(k); c.strokeStyle = 'rgba(0,0,0,.4)'; c.lineWidth = Math.max(0.6, R * 0.025); c.stroke();
        }
        function rad(deg, r) { var a = deg * Math.PI / 180; return [Math.sin(a) * r, -Math.cos(a) * r]; }
        function tick(deg, r0, r1, lwid, col) {
            var p0 = rad(deg, r0), p1 = rad(deg, r1);
            c.strokeStyle = col; c.lineWidth = Math.max(0.4, lwid); c.lineCap = 'butt';
            c.beginPath(); c.moveTo(p0[0], p0[1]); c.lineTo(p1[0], p1[1]); c.stroke();
        }
        function cap(r) {
            c.beginPath(); c.arc(0, 0, r, 0, 7); c.fillStyle = metalPaint(c, -r, -r, r, r, cm); c.fill();
        }

        /* lugs that hold the strap */
        var lw = sw * 1.08;
        [-1, 1].forEach(function (sg) {
            var y0 = sg * hy * 0.5, y1 = sg * (hy + w * 0.04);
            c.save();
            c.shadowColor = 'rgba(0,0,0,.4)'; c.shadowBlur = w * 0.02; c.shadowOffsetY = w * 0.01;
            c.fillStyle = metalPaint(c, -lw / 2, 0, lw / 2, 0, cm);
            rrect(c, -lw / 2, Math.min(y0, y1), lw, Math.abs(y1 - y0), w * 0.012); c.fill();
            c.restore();
        });

        /* crown / pushers on the thumb side */
        c.save(); c.scale(cs, 1);
        if (!dg) {
            var chh = R * 0.26, cw0 = R * 0.2;
            c.fillStyle = metalPaint(c, 0, -chh / 2, 0, chh / 2, cm);
            rrect(c, hx * 0.92, -chh / 2, cw0 + hx * 0.08, chh, R * 0.04); c.fill();
            c.strokeStyle = 'rgba(0,0,0,.35)'; c.lineWidth = Math.max(0.5, R * 0.02);
            for (i = 0; i < 4; i++) {
                c.beginPath(); c.moveTo(hx + i * cw0 / 4, -chh / 2 + 1); c.lineTo(hx + i * cw0 / 4, chh / 2 - 1); c.stroke();
            }
            if (style === 'chrono') {
                [-1, 1].forEach(function (sg) {
                    c.fillStyle = metalPaint(c, 0, sg * R * 0.5 - R * 0.07, 0, sg * R * 0.5 + R * 0.07, cm);
                    rrect(c, hx * 0.86, sg * R * 0.5 - R * 0.07, R * 0.2, R * 0.14, R * 0.03); c.fill();
                });
            }
        }
        c.restore();
        if (dg) {
            [-1, 1].forEach(function (sg) { [-1, 1].forEach(function (yy) {
                c.fillStyle = 'rgb(24,25,29)';
                rrect(c, sg > 0 ? hx * 0.94 : -hx * 1.1, yy * hy * 0.36 - R * 0.07, hx * 0.16, R * 0.14, R * 0.03); c.fill();
            }); });
        }

        /* case body + contact shadow on the skin */
        c.save();
        c.shadowColor = 'rgba(40,22,12,.55)'; c.shadowBlur = R * 0.4; c.shadowOffsetY = R * 0.16;
        shape(1); c.fillStyle = metalPaint(c, -hx, -hy, hx, hy, cm); c.fill();
        c.restore();
        shape(1); c.strokeStyle = 'rgba(0,0,0,.35)'; c.lineWidth = Math.max(0.6, R * 0.025); c.stroke();

        /* dial */
        var dialK = 0.8;
        if (style === 'classic') {
            shape(0.93); c.fillStyle = metalPaint(c, hx, hy, -hx, -hy, cm); c.fill();
            dialFill(0.8, [252, 249, 240], [222, 214, 194]);
            for (i = 0; i < 60; i++) tick(i * 6, R * 0.76, R * (i % 5 ? 0.72 : 0.66), R * (i % 5 ? 0.012 : 0.035), 'rgba(30,30,34,' + (i % 5 ? 0.55 : 0.95) + ')');
            wHand(c, 305, R * 0.46, R * 0.11, R * 0.10, 'rgb(28,28,32)', shade(cm, 0.7));
            wHand(c, 60,  R * 0.68, R * 0.08, R * 0.10, 'rgb(28,28,32)', shade(cm, 0.7));
            wHand(c, 206, R * 0.72, R * 0.022, R * 0.20, '#c0392b');
            cap(R * 0.055);
        } else if (style === 'square') {
            shape(0.9); c.fillStyle = metalPaint(c, hx, hy, -hx, -hy, cm); c.fill();
            dialFill(0.8, [58, 76, 112], [18, 26, 46]);
            for (i = 0; i < 12; i++) tick(i * 30, R * 0.70, R * (i % 3 ? 0.62 : 0.55), R * (i % 3 ? 0.03 : 0.05), shade(cm, 1.35, 0.95));
            wHand(c, 305, R * 0.44, R * 0.10, R * 0.08, shade(cm, 1.4), 'rgba(0,0,0,.5)');
            wHand(c, 60,  R * 0.64, R * 0.075, R * 0.08, shade(cm, 1.4), 'rgba(0,0,0,.5)');
            wHand(c, 206, R * 0.68, R * 0.02, R * 0.18, '#e08a2c');
            cap(R * 0.05);
        } else if (style === 'minimal') {
            shape(0.95); c.fillStyle = metalPaint(c, hx, hy, -hx, -hy, cm); c.fill();
            dialFill(0.88, [255, 255, 252], [236, 234, 228]); dialK = 0.88;
            [0, 90, 180, 270].forEach(function (a) {
                var p = rad(a, R * 0.72);
                c.fillStyle = 'rgb(40,40,44)'; c.beginPath(); c.arc(p[0], p[1], R * 0.035, 0, 7); c.fill();
            });
            wHand(c, 305, R * 0.46, R * 0.05, R * 0.08, 'rgb(36,36,40)');
            wHand(c, 60,  R * 0.68, R * 0.035, R * 0.08, 'rgb(36,36,40)');
            c.beginPath(); c.arc(0, 0, R * 0.04, 0, 7); c.fillStyle = 'rgb(36,36,40)'; c.fill();
        } else if (style === 'chrono') {
            shape(0.96); c.fillStyle = 'rgb(26,28,32)'; c.fill();
            for (i = 0; i < 60; i++) tick(i * 6, R * 0.86, R * 0.93, R * (i % 5 ? 0.01 : 0.022), 'rgba(235,235,235,.8)');
            dialFill(0.8, [44, 46, 54], [12, 13, 16]);
            for (i = 0; i < 12; i++) tick(i * 30, R * 0.74, R * 0.66, R * (i % 3 ? 0.03 : 0.045), 'rgba(245,245,245,.95)');
            [[-0.40, 0, 40], [0.40, 0, 160], [0, 0.42, 100]].forEach(function (sd) {
                var sx = R * sd[0], sy = R * sd[1], sr = R * 0.2, a2;
                c.beginPath(); c.arc(sx, sy, sr, 0, 7); c.fillStyle = 'rgb(26,28,34)'; c.fill();
                c.strokeStyle = 'rgba(160,162,170,.7)'; c.lineWidth = Math.max(0.5, R * 0.02); c.stroke();
                for (i = 0; i < 8; i++) {
                    a2 = i * Math.PI / 4;
                    c.beginPath(); c.moveTo(sx + Math.sin(a2) * sr * 0.8, sy - Math.cos(a2) * sr * 0.8);
                    c.lineTo(sx + Math.sin(a2) * sr * 0.95, sy - Math.cos(a2) * sr * 0.95);
                    c.strokeStyle = 'rgba(220,220,225,.7)'; c.lineWidth = Math.max(0.4, R * 0.012); c.stroke();
                }
                a2 = sd[2] * Math.PI / 180;
                c.beginPath(); c.moveTo(sx, sy); c.lineTo(sx + Math.sin(a2) * sr * 0.8, sy - Math.cos(a2) * sr * 0.8);
                c.strokeStyle = 'rgb(235,235,235)'; c.lineWidth = Math.max(0.5, R * 0.018); c.stroke();
            });
            wHand(c, 305, R * 0.44, R * 0.09, R * 0.08, 'rgb(240,240,240)', 'rgba(0,0,0,.45)');
            wHand(c, 60,  R * 0.64, R * 0.07, R * 0.08, 'rgb(240,240,240)', 'rgba(0,0,0,.45)');
            wHand(c, 206, R * 0.70, R * 0.02, R * 0.18, '#e0392b');
            cap(R * 0.05);
        } else {            /* digital */
            dialK = 0.9;
            shape(0.93); c.fillStyle = 'rgb(20,22,26)'; c.fill();
            var lg = c.createLinearGradient(0, -hy, 0, hy);
            lg.addColorStop(0, 'rgb(176,190,166)'); lg.addColorStop(1, 'rgb(140,155,132)');
            rrect(c, -hx * 0.82, -hy * 0.72, hx * 1.64, hy * 1.44, R * 0.1); c.fillStyle = lg; c.fill();
            c.strokeStyle = 'rgba(0,0,0,.5)'; c.lineWidth = Math.max(0.6, R * 0.025); c.stroke();
            c.fillStyle = 'rgba(32,42,32,.92)'; c.textAlign = 'center'; c.textBaseline = 'middle';
            c.font = 'bold ' + Math.max(6, R * 0.62) + 'px "Courier New", monospace';
            c.fillText('10:10', 0, R * 0.1);
            c.font = 'bold ' + Math.max(4, R * 0.17) + 'px "Courier New", monospace';
            c.fillText('MON 10', 0, -hy * 0.5);
        }

        /* glass reflection */
        c.save();
        shape(dialK); c.clip();
        var gg = c.createLinearGradient(-R * 0.8, -R * 0.9, R * 0.5, R * 0.7);
        gg.addColorStop(0, 'rgba(255,255,255,.34)'); gg.addColorStop(0.42, 'rgba(255,255,255,.10)');
        gg.addColorStop(0.43, 'rgba(255,255,255,0)'); gg.addColorStop(1, 'rgba(255,255,255,0)');
        c.fillStyle = gg; c.fillRect(-hx, -hy, 2 * hx, 2 * hy);
        c.restore();
    }

    function drawWrist(item, s) {
        var isWatch = item.jewelType === 'watch';
        var m = (isWatch && (!item.metal || item.metal === 'auto')) ? METALS.yellow : metalFor(item.metal, s);
        if (item.skin) {                                  /* match the metal to the lighting of the photo */
            var lum = 0.299 * item.skin[0] + 0.587 * item.skin[1] + 0.114 * item.skin[2];
            var f = Math.max(0.82, Math.min(1.08, lum / 185));
            m = [m[0] * f, m[1] * f, m[2] * f];
        }
        ctx.save();
        ctx.translate(item.x, item.y);
        ctx.rotate(((item.baseRotation || 0) + (item.rotation || 0)) * Math.PI / 180);
        if (isWatch) {
            var fc = item.face || 'auto';
            var up = (fc === 'back') || (fc === 'auto' && item.facing !== 'palm');
            renderWatch(ctx, item.size, item.slope || 0, item.style || 'classic', item.strap || 'metal', m, item.skin || null, up, item.thumbSide || 1);
        } else {
            renderBracelet(ctx, item.size, item.slope || 0, item.style || 'chain', m, item.gem || 'diamond', item.skin || null);
        }
        ctx.restore();
    }

    function drawRing(item, s) {
        var m = metalFor(item.metal, s);
        /* match the metal's brightness to the lighting of the photo */
        if (item.skin) {
            var lum = 0.299 * item.skin[0] + 0.587 * item.skin[1] + 0.114 * item.skin[2];
            var f = Math.max(0.82, Math.min(1.08, lum / 185));
            m = [m[0] * f, m[1] * f, m[2] * f];
        }
        ctx.save();
        ctx.translate(item.x, item.y);
        ctx.rotate(((item.baseRotation || 0) + (item.rotation || 0)) * Math.PI / 180);
        renderRing(ctx, item.size, item.style || 'solitaire', m, item.gem || 'diamond', item.skin || null);
        ctx.restore();
    }

    /* item.size = displayed width in canvas px (rings: finger width). */
    function drawItem(item) {
        var s = item.sprite;
        if (item.jewelType === 'ring' && wrapRing) { drawRing(item, s); return; }
        if ((item.jewelType === 'bracelet' || item.jewelType === 'watch') && wrapRing) { drawWrist(item, s); return; }
        if (!s || !s.ready) return;

        var el = (useCutout && s.cut) ? s.cut : s.raw;
        var iw = el.naturalWidth || el.width, ih = el.naturalHeight || el.height;
        if (!iw || !ih) return;
        var w = item.size, h = w * ih / iw, turn = 0;
        if (item.jewelType === 'watch') {          /* watch photo (templates off): strap runs along the arm */
            turn = iw > ih * 1.1 ? 90 : 0;
            var kk = (item.size * 1.5) / (turn ? iw : ih);
            w = iw * kk; h = ih * kk;
        }

        ctx.save();
        ctx.translate(item.x, item.y);
        ctx.rotate(((item.baseRotation || 0) + (item.rotation || 0) + turn) * Math.PI / 180);
        ctx.shadowColor = 'rgba(0,0,0,.35)';
        ctx.shadowBlur = Math.max(2, w * 0.05);
        ctx.shadowOffsetY = Math.max(1, w * 0.02);
        ctx.drawImage(el, -w / 2, item.anchor === 'top' ? 0 : -h / 2, w, h);
        ctx.restore();
    }

    function drawPoints() {
        var hl = (lastHandsResult && lastHandsResult.multiHandLandmarks) || [];
        var ids = TryOnPure.FINGERS[selectedFinger] || [];
        hl.forEach(function (lm) {
            lm.forEach(function (p, i) {
                var hot = ids.indexOf(i) !== -1;
                ctx.fillStyle = hot ? '#ffd54a' : 'rgba(255,255,255,.85)';
                ctx.beginPath(); ctx.arc(p.x * canvas.width, p.y * canvas.height, hot ? 6 : 3, 0, 7); ctx.fill();
            });
        });
        /* detected wrist edges (bracelets / watches) */
        activeItems.forEach(function (it) {
            if (!it.edges) return;
            it.edges.forEach(function (e) {
                ctx.strokeStyle = 'rgba(34,197,94,.9)'; ctx.lineWidth = 2;
                ctx.beginPath(); ctx.moveTo(e[0], e[1]); ctx.lineTo(e[2], e[3]); ctx.stroke();
                ctx.fillStyle = '#22c55e';
                ctx.beginPath(); ctx.arc(e[0], e[1], 5, 0, 7); ctx.fill();
                ctx.beginPath(); ctx.arc(e[2], e[3], 5, 0, 7); ctx.fill();
            });
        });
    }

    /* =============================================================
       Auto placement (AI landmark driven)
       ============================================================= */
    function getBaseData() {
        if (!baseCanvas) return null;
        if (!baseData) {
            try { baseData = baseCanvas.getContext('2d').getImageData(0, 0, baseCanvas.width, baseCanvas.height); }
            catch (e) { baseData = null; }
        }
        return baseData;
    }

    /* Bracelet next to a watch on the same hand: sits just toward the hand, using the watch's fit */
    function stackBracelet(pose, handIndex) {
        var wt = null;
        activeItems.forEach(function (it) { if (it.jewelType === 'watch' && it.handIndex === handIndex) wt = it; });
        if (!wt) return pose;
        var th = (wt.baseRotation || 0) * Math.PI / 180, off = wt.size * 0.52, sl = wt.slope || 0;
        pose.x = wt.x + Math.sin(th) * off;
        pose.y = wt.y - Math.cos(th) * off;
        pose.baseRotation = wt.baseRotation;
        pose.size = Math.max(20, wt.size * (1 - sl * 0.52));
        pose.slope = sl;
        return pose;
    }

    function restackBracelets() {
        activeItems.forEach(function (it) { if (it.jewelType === 'bracelet' && it.autoPlaced) reposeItem(it); });
    }

    /* palm or back of the hand towards the camera (decides if a watch face is visible) */
    function facingOf(i) {
        var res = lastHandsResult, lms = res && res.multiHandLandmarks;
        if (!lms || !lms.length) return null;
        var idx = Math.min(i == null ? selectedHand : i, lms.length - 1);
        var hd = res.multiHandedness && res.multiHandedness[idx];
        var lab = hd && (hd.label || hd.displayName);
        if (lab !== 'Left' && lab !== 'Right') return null;
        var trueHand = lab === 'Left' ? 'Right' : 'Left';      /* the photo is not mirrored → swap MediaPipe's label */
        return TryOnPure.palmFacing(lms[idx], trueHand) ? 'palm' : 'back';
    }

    function posesFor(type, finger, handIndex) {
        var W = canvas.width, H = canvas.height;
        if (type === 'ring' || type === 'bracelet' || type === 'watch') {
            var lm = getHand(handIndex);
            if (!lm) return null;
            if (type === 'ring') return [TryOnPure.ring(lm, finger, W, H, null, getBaseData())];
            var wp = TryOnPure.wrist(lm, W, H, getBaseData(), type);
            wp.facing = facingOf(handIndex);
            if (type === 'bracelet') wp = stackBracelet(wp, handIndex);
            return [wp];
        }
        var face = getFace();
        if (!face) return null;
        if (type === 'necklace') return [TryOnPure.necklace(face, W, H)];
        if (type === 'earring')  return TryOnPure.earrings(face, W, H);
        return null;
    }

    function autoPlace(product) {
        if (!product || !baseCanvas) return false;
        var type = product.jewelType;
        var wristy = (type === 'bracelet' || type === 'watch');
        var poses = posesFor(type, selectedFinger, selectedHand);
        if (!poses) {
            var need = (type === 'ring' || wristy) ? 'a hand' : (type === 'other' ? 'this type' : 'a face');
            showNotification('Could not detect ' + need + '. Click on the photo to place manually.', 'warning');
            return false;
        }
        var sprite = getSprite(product.tryon);
        var firstIndex = activeItems.length;
        poses.forEach(function (p, i) {
            activeItems.push({
                id: Date.now() + i, productId: product.id,
                name: product.name + (p.side ? ' (' + p.side + ')' : ''),
                category: product.categoryName,
                label: type === 'ring' ? FINGER_LABEL[selectedFinger] : (wristy ? 'Wrist' : null),
                src: product.tryon, sprite: sprite,
                x: p.x, y: p.y, size: p.size, skin: p.skin || null,
                baseRotation: p.baseRotation, rotation: 0, anchor: p.anchor,
                jewelType: type, autoPlaced: true, style: styleFor(type), metal: ringMetal, gem: ringGem,
                finger: type === 'ring' ? selectedFinger : null,
                handIndex: selectedHand,
                slope: p.slope || 0, thumbSide: p.thumbSide || 1, facing: p.facing || null,
                edges: p.edges || null, fitted: !!p.fitted,
                strap: watchStrap, face: watchFace
            });
        });
        if (type === 'watch') restackBracelets();
        updateActiveItemsList();
        redrawBase();
        selectItem(firstIndex, true);
        if (wristy && !poses[0].fitted) showNotification(product.name + ' placed — wrist edges unclear, adjust Size if needed', 'warning');
        else if (wristy)                showNotification(product.name + ' fitted to your wrist edges', 'success');
        else                            showNotification(product.name + ' placed via AI', 'success');
        return true;
    }

    function reposeItem(item) {
        if (item.jewelType !== 'ring' && item.jewelType !== 'bracelet' && item.jewelType !== 'watch') return false;
        var poses = posesFor(item.jewelType, item.finger, item.handIndex);
        if (!poses) return false;
        var p = poses[0];
        item.x = p.x; item.y = p.y; item.size = p.size; item.baseRotation = p.baseRotation; item.skin = p.skin || null;
        if (item.jewelType !== 'ring') {
            item.slope = p.slope || 0; item.thumbSide = p.thumbSide || 1; item.facing = p.facing || null;
            item.edges = p.edges || null; item.fitted = !!p.fitted;
        }
        return true;
    }

    /* =============================================================
       Finger + hand pickers
       ============================================================= */
    function markFingerChips() {
        document.querySelectorAll('#fingerPicker .chip').forEach(function (c) {
            c.classList.toggle('active', c.getAttribute('data-finger') === selectedFinger);
        });
    }

    document.querySelectorAll('#fingerPicker .chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            selectedFinger = this.getAttribute('data-finger');
            markFingerChips();
            onFingerChange();
        });
    });

    function onFingerChange() {
        if (mode !== 'tryon') return;
        if (!selectedProduct || selectedProduct.jewelType !== 'ring') {
            showNotification('Now pick a ring to wear on the ' + FINGER_LABEL[selectedFinger].toLowerCase() + '.', 'info');
            return;
        }
        if (!autoFit) { showNotification('Turn on Auto-fit to move rings between fingers.', 'info'); return; }

        var existing = null;
        for (var i = activeItems.length - 1; i >= 0; i--) {
            var it = activeItems[i];
            if (it.jewelType === 'ring' && it.autoPlaced && it.productId === selectedProduct.id) { existing = it; break; }
        }
        /* free the target finger */
        activeItems = activeItems.filter(function (it) {
            return !(it !== existing && it.jewelType === 'ring' && it.autoPlaced &&
                     it.finger === selectedFinger && it.handIndex === selectedHand);
        });

        if (existing) {
            existing.finger = selectedFinger; existing.handIndex = selectedHand;
            existing.label = FINGER_LABEL[selectedFinger];
            existing.rotation = 0;
            if (!reposeItem(existing)) showNotification('No hand detected in this photo.', 'warning');
            updateActiveItemsList();
            redrawBase();
            selectItem(activeItems.indexOf(existing), true);
        } else {
            autoPlace(selectedProduct);
        }
    }

    function buildHandPicker(count) {
        var box = $('handPicker');
        box.innerHTML = '';
        if (count < 2) { box.style.display = 'none'; selectedHand = 0; return; }
        box.style.display = 'flex';
        for (var i = 0; i < count; i++) {
            (function (n) {
                var b = document.createElement('button');
                b.type = 'button'; b.className = 'chip' + (n === selectedHand ? ' active' : '');
                b.textContent = 'Hand ' + (n + 1);
                b.addEventListener('click', function () { onHandPick(n); });
                box.appendChild(b);
            })(i);
        }
    }

    function onHandPick(n) {
        selectedHand = n;
        document.querySelectorAll('#handPicker .chip').forEach(function (c, i) { c.classList.toggle('active', i === n); });
        if (mode !== 'tryon') return;
        ['ring', 'watch', 'bracelet'].forEach(function (t) {
            activeItems.forEach(function (it) {
                if (it.autoPlaced && it.jewelType === t) { it.handIndex = n; reposeItem(it); }
            });
        });
        redrawBase();
    }

    /* =============================================================
       Manual placement on canvas (maps clicks through "contain" letterboxing)
       ============================================================= */
    function eventToCanvas(e) {
        var r = canvas.getBoundingClientRect();
        var s = Math.min(r.width / canvas.width, r.height / canvas.height);
        var dw = canvas.width * s, dh = canvas.height * s;
        var ox = r.left + (r.width - dw) / 2, oy = r.top + (r.height - dh) / 2;
        return { x: (e.clientX - ox) / s, y: (e.clientY - oy) / s };
    }

    canvas.addEventListener('click', function (e) {
        if (mode !== 'tryon') return;
        if (!selectedProduct) { showNotification('Pick a jewelry piece first.', 'warning'); return; }
        var p = eventToCanvas(e);
        if (p.x < 0 || p.y < 0 || p.x > canvas.width || p.y > canvas.height) return;

        activeItems.push({
            id: Date.now(), productId: selectedProduct.id,
            name: selectedProduct.name, category: selectedProduct.categoryName, label: null,
            src: selectedProduct.tryon, sprite: getSprite(selectedProduct.tryon),
            x: p.x, y: p.y, size: currentItemSize,
            baseRotation: 0, rotation: currentItemRotation, anchor: 'center',
            jewelType: selectedProduct.jewelType, autoPlaced: false, finger: null, handIndex: 0,
            style: ringStyle, metal: ringMetal, gem: ringGem
        });
        updateActiveItemsList();
        redrawBase();
        selectItem(activeItems.length - 1, true);
        showNotification(selectedProduct.name + ' placed manually', 'success');
    });

    /* =============================================================
       Retake
       ============================================================= */
    retakeBtn.addEventListener('click', function () {
        capturedImg = null; baseCanvas = null; baseData = null;
        activeItems = []; selectedItemIndex = -1;
        lastHandsResult = null; lastFaceResult = null;
        capturedPhoto.style.display = 'none';
        canvas.style.display = 'none';
        buildHandPicker(0);
        setDetection('', false);
        updateActiveItemsList();
        setMode('capture');
        startCamera();
    });

    /* =============================================================
       Active items list
       ============================================================= */
    function updateActiveItemsList() {
        var container = $('activeItemsList');
        if (!container) return;
        if (activeItems.length === 0) {
            container.innerHTML =
                '<div class="empty-items"><i class="fas fa-hand-pointer"></i>' +
                'Capture a photo first, then pick a piece.</div>';
            return;
        }
        container.innerHTML = '';
        activeItems.forEach(function (item, i) {
            var el = document.createElement('div');
            el.className = 'active-item' + (i === selectedItemIndex ? ' selected' : '');
            el.innerHTML =
                '<div class="active-item-info">' +
                    '<img src="' + escapeHtml(item.src) + '" alt="">' +
                    '<div class="meta">' +
                        '<strong>' + escapeHtml(item.name) + '</strong>' +
                        '<small>' + escapeHtml((item.category || 'Jewelry') + (item.label ? ' · ' + item.label : '')) + '</small>' +
                    '</div>' +
                '</div>' +
                '<div class="item-actions">' +
                    '<button class="icon-btn" data-select="' + i + '" title="Adjust"><i class="fas fa-sliders-h"></i></button>' +
                    '<button class="icon-btn danger" data-remove="' + i + '" title="Remove"><i class="fas fa-times"></i></button>' +
                '</div>';
            container.appendChild(el);
        });
        container.querySelectorAll('[data-select]').forEach(function (b) {
            b.addEventListener('click', function () { selectItem(parseInt(this.getAttribute('data-select'), 10)); });
        });
        container.querySelectorAll('[data-remove]').forEach(function (b) {
            b.addEventListener('click', function () { removeItem(parseInt(this.getAttribute('data-remove'), 10)); });
        });
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function selectItem(index, quiet) {
        var item = activeItems[index];
        if (!item) return;
        selectedItemIndex = index;
        $('sizeSlider').value = item.size;
        $('sizeValue').textContent = Math.round(item.size) + 'px';
        $('rotationSlider').value = item.rotation;
        $('rotationValue').textContent = Math.round(item.rotation) + '°';
        currentItemSize = item.size;
        currentItemRotation = item.rotation;
        document.querySelectorAll('#activeItemsList .active-item').forEach(function (el, i) {
            el.classList.toggle('selected', i === index);
        });
        if (!quiet) showNotification('Adjusting: ' + item.name, 'info');
    }

    function removeItem(index) {
        if (index < 0 || index >= activeItems.length) return;
        var name = activeItems[index].name, wasWatch = activeItems[index].jewelType === 'watch';
        activeItems.splice(index, 1);
        if (wasWatch) restackBracelets();
        if (selectedItemIndex === index) selectedItemIndex = -1;
        else if (selectedItemIndex > index) selectedItemIndex--;
        updateActiveItemsList();
        redrawBase();
        showNotification(name + ' removed', 'info');
    }

    /* =============================================================
       Sliders + D-pad
       ============================================================= */
    $('sizeSlider').addEventListener('input', function () {
        currentItemSize = parseFloat(this.value);
        $('sizeValue').textContent = Math.round(currentItemSize) + 'px';
        if (selectedItemIndex >= 0 && activeItems[selectedItemIndex]) {
            activeItems[selectedItemIndex].size = currentItemSize;
            redrawBase();
        }
    });
    $('rotationSlider').addEventListener('input', function () {
        currentItemRotation = parseFloat(this.value);
        $('rotationValue').textContent = Math.round(currentItemRotation) + '°';
        if (selectedItemIndex >= 0 && activeItems[selectedItemIndex]) {
            activeItems[selectedItemIndex].rotation = currentItemRotation;
            redrawBase();
        }
    });
    $('moveUp').addEventListener('click',    function () { moveSel(0, -4); });
    $('moveDown').addEventListener('click',  function () { moveSel(0,  4); });
    $('moveLeft').addEventListener('click',  function () { moveSel(-4, 0); });
    $('moveRight').addEventListener('click', function () { moveSel( 4, 0); });
    $('resetPosition').addEventListener('click', function () {
        var it = activeItems[selectedItemIndex];
        if (!it) { showNotification('Select an item first.', 'warning'); return; }
        if (it.autoPlaced && reposeItem(it)) {
            it.rotation = 0;
            if (it.jewelType === 'watch') restackBracelets();
            selectItem(selectedItemIndex, true);
        }
        else { it.x = canvas.width / 2; it.y = canvas.height / 2; }
        redrawBase();
        showNotification('Position reset', 'info');
    });
    function moveSel(dx, dy) {
        var it = activeItems[selectedItemIndex];
        if (!it) { showNotification('Select an item first.', 'warning'); return; }
        it.x += dx; it.y += dy;
        redrawBase();
    }

    /* =============================================================
       Toggles
       ============================================================= */
    $('autoFitToggle').addEventListener('change', function () {
        autoFit = this.checked;
        showNotification(autoFit ? 'Auto-fit enabled' : 'Auto-fit off — click photo to place manually', 'info');
    });
    $('cutoutToggle').addEventListener('change', function () { useCutout = this.checked; redrawBase(); });
    $('wrapToggle').addEventListener('change', function () { wrapRing = this.checked; redrawBase(); });
    $('pointsToggle').addEventListener('change', function () { showPoints = this.checked; redrawBase(); });

    /* =============================================================
       Clear all items (keeps the photo)
       ============================================================= */
    $('clearAll').addEventListener('click', function () {
        if (activeItems.length === 0) return;
        if (!confirm('Remove all jewelry items?')) return;
        activeItems = [];
        selectedItemIndex = -1;
        updateActiveItemsList();
        redrawBase();
        showNotification('All items cleared', 'info');
    });

    /* =============================================================
       Save look (debug points are never baked into the saved image)
       ============================================================= */
    $('saveImage').addEventListener('click', function () {
        if (mode !== 'tryon' || !baseCanvas) {
            showNotification('Capture a photo first.', 'warning');
            return;
        }
        try {
            var prev = showPoints; showPoints = false; redrawBase();
            var url = canvas.toDataURL('image/png');
            showPoints = prev; redrawBase();
            var link = document.createElement('a');
            link.download = 'r-jewel-tryon-' + Date.now() + '.png';
            link.href = url;
            link.click();
            showNotification('Image saved!', 'success');
        } catch (e) {
            showNotification('Could not save the image.', 'error');
        }
    });

    /* =============================================================
       Product picker
       ============================================================= */
    document.querySelectorAll('.product-item').forEach(function (card) {
        card.addEventListener('click', function () {
            document.querySelectorAll('.product-item').forEach(function (c) { c.classList.remove('selected'); });
            this.classList.add('selected');

            selectedProduct = {
                id: this.getAttribute('data-id'),
                name: this.getAttribute('data-name'),
                image: this.getAttribute('data-image'),
                tryon: this.getAttribute('data-tryon') || this.getAttribute('data-image'),
                categoryName: this.getAttribute('data-category-name'),
                jewelType: this.getAttribute('data-jewel-type')
            };
            getSprite(selectedProduct.tryon);   /* start loading right away */
            updatePickerVisibility();

            if (mode === 'tryon' && autoFit && selectedProduct.jewelType !== 'other') {
                var t = selectedProduct.jewelType;
                /* replace the previous auto-placed piece of this type (for rings: only on the same finger) */
                activeItems = activeItems.filter(function (it) {
                    if (!(it.autoPlaced && it.jewelType === t)) return true;
                    if (t === 'ring') return !(it.finger === selectedFinger && it.handIndex === selectedHand);
                    return false;
                });
                selectedItemIndex = -1;
                updateActiveItemsList();
                redrawBase();
                autoPlace(selectedProduct);
            } else if (mode === 'tryon') {
                showNotification(selectedProduct.name + ' selected. Click on the photo to place it.', 'info');
            } else {
                showNotification(selectedProduct.name + ' selected. Capture a photo first.', 'info');
            }
        });
    });

    /* =============================================================
       Category filter
       ============================================================= */
    document.querySelectorAll('.category-filter').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.category-filter').forEach(function (b) { b.classList.remove('active'); });
            this.classList.add('active');
            var cat = this.getAttribute('data-category');
            document.querySelectorAll('.product-item').forEach(function (card) {
                var show = (cat === 'all') || (card.getAttribute('data-category') === cat);
                card.style.display = show ? '' : 'none';
            });
        });
    });

    /* =============================================================
       Tabs
       ============================================================= */
    document.querySelectorAll('.tab-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.tab-btn').forEach(function (b) { b.classList.remove('active'); });
            this.classList.add('active');
            document.querySelectorAll('.tab-pane').forEach(function (p) { p.classList.remove('active'); });
            var t = $(this.getAttribute('data-tab') + 'Tab');
            if (t) t.classList.add('active');
        });
    });

    /* =============================================================
       Initial mode
       ============================================================= */
    setMode('capture');
    updateActiveItemsList();


    /* =============================================================
       Ring style / metal / stone pickers
       ============================================================= */
    function drawStylePreview(cv, style) {
        var c = cv.getContext('2d'), W = cv.width, H = cv.height;
        c.clearRect(0, 0, W, H);
        var fw = W * 0.5;
        var sg = c.createLinearGradient(W / 2 - fw / 2, 0, W / 2 + fw / 2, 0);
        sg.addColorStop(0, '#c99673'); sg.addColorStop(0.35, '#ecc2a1');
        sg.addColorStop(0.7, '#e3b18f'); sg.addColorStop(1, '#bb8662');
        c.fillStyle = sg;
        c.fillRect(W / 2 - fw / 2, 0, fw, H);
        c.save();
        c.translate(W / 2, H * 0.6);
        renderRing(c, fw, style, metalFor(ringMetal, null), ringGem);
        c.restore();
    }

    function refreshPreviews() {
        document.querySelectorAll('#stylePicker .style-tile').forEach(function (b) {
            drawStylePreview(b.querySelector('canvas'), b.getAttribute('data-style'));
        });
    }

    function markRingPickers() {
        document.querySelectorAll('#stylePicker .style-tile').forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-style') === ringStyle);
        });
        document.querySelectorAll('#metalPicker .chip').forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-metal') === ringMetal);
        });
        document.querySelectorAll('#gemPicker .chip').forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-gem') === ringGem);
        });
    }

    /* apply the current look to the selected piece (or to every piece of that kind if none is selected) */
    function applyLook(kind) {
        var sel = activeItems[selectedItemIndex];
        var ALL = ['ring', 'bracelet', 'watch'];
        var types = (kind === 'all')
            ? ((sel && ALL.indexOf(sel.jewelType) !== -1) ? [sel.jewelType] : ALL)
            : [kind];
        types.forEach(function (t) {
            var targets = (sel && sel.jewelType === t)
                ? [sel]
                : activeItems.filter(function (it) { return it.jewelType === t; });
            targets.forEach(function (it) {
                it.style = styleFor(t); it.metal = ringMetal; it.gem = ringGem;
                if (t === 'watch') { it.strap = watchStrap; it.face = watchFace; }
            });
        });
        redrawBase();
    }
    function applyRingLook() { applyLook('ring'); }

    function buildStylePicker() {
        var box = $('stylePicker');
        if (!box) return;
        box.innerHTML = '';
        [['solitaire', 'Solitaire'], ['halo', 'Halo'], ['three', '3-Stone'], ['pave', 'Pavé'], ['band', 'Band']].forEach(function (d) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'style-tile' + (d[0] === ringStyle ? ' active' : '');
            b.setAttribute('data-style', d[0]);
            var cv = document.createElement('canvas'); cv.width = 96; cv.height = 96;
            var lb = document.createElement('span'); lb.textContent = d[1];
            b.appendChild(cv); b.appendChild(lb);
            b.addEventListener('click', function () {
                ringStyle = d[0]; markRingPickers(); applyRingLook();
            });
            box.appendChild(b);
        });
        refreshPreviews();
    }

    document.querySelectorAll('#metalPicker .chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            ringMetal = this.getAttribute('data-metal');
            markRingPickers(); refreshPreviews(); refreshWristPreviews(); applyLook('all');
        });
    });
    document.querySelectorAll('#gemPicker .chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            ringGem = this.getAttribute('data-gem');
            markRingPickers(); refreshPreviews(); refreshWristPreviews(); applyLook('all');
        });
    });

    buildStylePicker();

    /* =============================================================
       Bracelet / watch pickers
       ============================================================= */
    function watchMetal() { return (ringMetal !== 'auto' && METALS[ringMetal]) ? METALS[ringMetal] : METALS.yellow; }

    function drawWristPreview(cv, kind, style) {
        var c = cv.getContext('2d'), W = cv.width, H = cv.height;
        c.clearRect(0, 0, W, H);
        var fw = W * (kind === 'watch' ? 0.66 : 0.8);
        var sg = c.createLinearGradient(W / 2 - fw / 2, 0, W / 2 + fw / 2, 0);
        sg.addColorStop(0, '#c99673'); sg.addColorStop(0.35, '#ecc2a1');
        sg.addColorStop(0.7, '#e3b18f'); sg.addColorStop(1, '#bb8662');
        c.fillStyle = sg;
        c.fillRect(W / 2 - fw / 2, 0, fw, H);
        c.save();
        c.translate(W / 2, H / 2);
        if (kind === 'watch') renderWatch(c, fw, 0, style, watchStrap, watchMetal(), null, true, 1);
        else                  renderBracelet(c, fw, 0, style, metalFor(ringMetal, null), ringGem, null);
        c.restore();
    }

    function refreshWristPreviews() {
        document.querySelectorAll('#braceletStylePicker .style-tile').forEach(function (b) {
            drawWristPreview(b.querySelector('canvas'), 'bracelet', b.getAttribute('data-style'));
        });
        document.querySelectorAll('#watchStylePicker .style-tile').forEach(function (b) {
            drawWristPreview(b.querySelector('canvas'), 'watch', b.getAttribute('data-style'));
        });
    }

    function markWristPickers() {
        document.querySelectorAll('#braceletStylePicker .style-tile').forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-style') === braceletStyle);
        });
        document.querySelectorAll('#watchStylePicker .style-tile').forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-style') === watchStyle);
        });
        document.querySelectorAll('#strapPicker .chip').forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-strap') === watchStrap);
        });
        document.querySelectorAll('#facePicker .chip').forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-face') === watchFace);
        });
    }

    function buildWristPickers() {
        function fill(boxId, defs, kind, getCur, setCur) {
            var box = $(boxId);
            if (!box) return;
            box.innerHTML = '';
            defs.forEach(function (d) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'style-tile' + (d[0] === getCur() ? ' active' : '');
                b.setAttribute('data-style', d[0]);
                var cv = document.createElement('canvas'); cv.width = 96; cv.height = 96;
                var lb = document.createElement('span'); lb.textContent = d[1];
                b.appendChild(cv); b.appendChild(lb);
                b.addEventListener('click', function () { setCur(d[0]); markWristPickers(); applyLook(kind); });
                box.appendChild(b);
            });
        }
        fill('braceletStylePicker',
            [['chain', 'Chain'], ['bangle', 'Bangle'], ['tennis', 'Tennis'], ['beaded', 'Beaded'], ['cuff', 'Cuff']],
            'bracelet', function () { return braceletStyle; }, function (v) { braceletStyle = v; });
        fill('watchStylePicker',
            [['classic', 'Classic'], ['square', 'Square'], ['minimal', 'Minimal'], ['chrono', 'Chrono'], ['digital', 'Digital']],
            'watch', function () { return watchStyle; }, function (v) { watchStyle = v; });
        refreshWristPreviews();
    }

    document.querySelectorAll('#strapPicker .chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            watchStrap = this.getAttribute('data-strap');
            markWristPickers(); refreshWristPreviews(); applyLook('watch');
        });
    });
    document.querySelectorAll('#facePicker .chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            watchFace = this.getAttribute('data-face');
            markWristPickers(); applyLook('watch');
        });
    });

    /* show only the options that belong to the selected product type */
    function updatePickerVisibility() {
        var t = selectedProduct ? selectedProduct.jewelType : null;
        var wristy = (t === 'bracelet' || t === 'watch');
        function show(id, on) { var el = $(id); if (el) el.style.display = on ? '' : 'none'; }
        show('ringFingerBlock', !wristy);
        show('ringStyleBlock', !wristy);
        show('braceletStyleBlock', t === 'bracelet');
        show('watchOptionsBlock', t === 'watch');
        show('gemBlock', t !== 'watch');
    }

    buildWristPickers();
    updatePickerVisibility();

})();
</script>

<?php include '../includes/footer.php'; ?>
