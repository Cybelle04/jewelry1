<?php
// user/about.php — Luxury About Us Page
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/auth.php';

/* ------------------------------------------------------------------
   1. Appearance from cookie (same as index.php)
   ------------------------------------------------------------------ */
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

/* ------------------------------------------------------------------
   2. Database connection
   ------------------------------------------------------------------ */
$db = null;
try {
    $database = new Database();
    $db = $database->getConnection();
} catch (Throwable $e) {
    $db = null;
}

/* ------------------------------------------------------------------
   3. Safe query helper
   ------------------------------------------------------------------ */
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

/* ------------------------------------------------------------------
   4. Fetch stats
   ------------------------------------------------------------------ */
$stats = ['products' => 0, 'customers' => 0, 'orders' => 0];
$avg_rating = 4.8;

if ($db) {
    try {
        $row = safe_query($db, "SELECT COUNT(*) AS total FROM products WHERE stock > 0");
        $stats['products'] = (int)($row[0]['total'] ?? 0);

        $row = safe_query($db, "SELECT COUNT(*) AS total FROM users WHERE role = 'user'");
        $stats['customers'] = (int)($row[0]['total'] ?? 0);

        $row = safe_query($db, "SELECT COUNT(*) AS total FROM orders WHERE status != 'cancelled'");
        $stats['orders'] = (int)($row[0]['total'] ?? 0);

        $row = safe_query($db, "SELECT AVG(rating) AS avg FROM reviews");
        if (!empty($row[0]['avg'])) {
            $avg_rating = round((float)$row[0]['avg'], 1);
        }
    } catch (Throwable $e) {
        // keep defaults
    }
}

$page_title = 'About Us | R-Jewel&Gold';
?>
<?php include '../includes/header.php'; ?>

<style>
    /* ============================================================
       THEME VARIABLES (identical to index.php)
       ============================================================ */
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

    /* ============================================================
       BASE
       ============================================================ */
    body, h1, h2, h3, h4, h5, h6,
    .about-hero h1, .section-head h2, .story-content h2,
    .value-card h4, .milestone-title, .milestone-year,
    .stat-number, .cta-inner h2 {
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

    /* ============================================================
       HERO (matches index.php hero language)
       ============================================================ */
    .about-hero {
        position: relative;
        min-height: 480px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--bg);
        background-image:
            radial-gradient(ellipse at 20% 20%, rgba(184, 148, 31, 0.14), transparent 55%),
            radial-gradient(ellipse at 80% 80%, rgba(91, 95, 212, 0.08), transparent 55%);
        overflow: hidden;
        padding: 6rem 1.5rem 4rem;
        text-align: center;
    }
    .about-hero::before {
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
    .about-hero-content {
        position: relative;
        z-index: 2;
        max-width: 800px;
    }
    .hero-diamond {
        width: 80px;
        height: 80px;
        margin: 0 auto 1.75rem;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .hero-diamond svg {
        width: 100%;
        height: 100%;
        filter: drop-shadow(0 6px 20px rgba(184, 148, 31, 0.4));
        animation: floatDiamond 6s ease-in-out infinite;
    }
    @keyframes floatDiamond {
        0%, 100% { transform: translateY(0); }
        50%      { transform: translateY(-10px); }
    }
    .hero-eyebrow {
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.4em;
        text-transform: uppercase;
        color: var(--gold);
        margin-bottom: 1rem;
    }
    .about-hero h1 {
        font-size: clamp(2.5rem, 6vw, 4.2rem);
        font-weight: 600;
        line-height: 1.05;
        color: var(--text-bright);
        margin-bottom: 1.25rem;
    }
    .about-hero h1 em {
        color: var(--gold);
        font-style: italic;
        font-weight: 500;
    }
    .hero-divider {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        max-width: 220px;
        margin: 1.5rem auto;
    }
    .hero-divider .line {
        flex: 1;
        height: 1px;
        background: linear-gradient(90deg, transparent, rgba(184, 148, 31, 0.6), transparent);
    }
    .hero-divider .diamond {
        width: 6px;
        height: 6px;
        background: var(--gold);
        transform: rotate(45deg);
        box-shadow: 0 0 10px rgba(184, 148, 31, 0.7);
    }
    .about-hero p.lead-text {
        font-size: 1.02rem;
        color: var(--text-muted);
        max-width: 600px;
        margin: 0 auto;
        line-height: 1.7;
    }

    /* Breadcrumb */
    .lux-breadcrumb {
        display: flex;
        justify-content: center;
        gap: 8px;
        font-size: 0.7rem;
        letter-spacing: 0.2em;
        text-transform: uppercase;
        color: var(--text-dim);
        margin-bottom: 1.5rem;
        font-weight: 600;
    }
    .lux-breadcrumb a {
        color: var(--text-muted);
        text-decoration: none;
        transition: color 0.25s ease;
    }
    .lux-breadcrumb a:hover { color: var(--gold); }
    .lux-breadcrumb .sep { color: var(--text-dim); }
    .lux-breadcrumb .current { color: var(--gold); }

    /* ============================================================
       BUTTONS (matches index.php)
       ============================================================ */
    .btn-lux {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 0.9rem 2rem;
        border-radius: 9px;
        border: none;
        font-family: 'Inter', sans-serif;
        font-size: 0.78rem;
        font-weight: 600;
        letter-spacing: 0.2em;
        text-transform: uppercase;
        text-decoration: none;
        cursor: pointer;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }
    .btn-lux-gold {
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        color: #fff;
        box-shadow: 0 4px 14px -3px rgba(184, 148, 31, 0.45);
    }
    .btn-lux-gold::before {
        content: '';
        position: absolute;
        top: 0; left: -100%;
        width: 100%; height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.25), transparent);
        transition: left 0.6s ease;
    }
    .btn-lux-gold:hover {
        color: #fff;
        transform: translateY(-2px);
        box-shadow: 0 8px 22px -5px rgba(184, 148, 31, 0.6);
    }
    .btn-lux-gold:hover::before { left: 100%; }

    .btn-lux-outline {
        background: transparent;
        color: var(--text-bright);
        border: 1px solid var(--border);
    }
    .btn-lux-outline:hover {
        border-color: var(--gold);
        color: var(--gold);
        transform: translateY(-2px);
    }

    /* ============================================================
       SECTION HEADINGS (matches index.php)
       ============================================================ */
    .section-head {
        text-align: center;
        margin-bottom: 3.5rem;
    }
    .section-head .eyebrow {
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.4em;
        text-transform: uppercase;
        color: var(--gold);
        margin-bottom: 0.75rem;
    }
    .section-head h2 {
        font-size: clamp(1.9rem, 4vw, 2.6rem);
        margin-bottom: 0.75rem;
    }
    .section-head p {
        color: var(--text-muted);
        max-width: 520px;
        margin: 0 auto;
        font-size: 0.95rem;
    }
    .small-divider {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        max-width: 140px;
        margin: 1rem auto 0;
    }
    .small-divider .line {
        flex: 1;
        height: 1px;
        background: linear-gradient(90deg, transparent, rgba(184, 148, 31, 0.5), transparent);
    }
    .small-divider .diamond {
        width: 5px;
        height: 5px;
        background: var(--gold);
        transform: rotate(45deg);
    }

    /* ============================================================
       STATS
       ============================================================ */
    .stats-section {
        padding: 4rem 0;
        background: var(--card);
        border-top: 1px solid var(--border-light);
        border-bottom: 1px solid var(--border-light);
    }
    .stat-item {
        text-align: center;
        padding: 1.5rem 1rem;
        transition: transform 0.3s ease;
    }
    .stat-item:hover { transform: translateY(-4px); }
    .stat-number {
        font-size: 2.8rem;
        font-weight: 600;
        color: var(--gold);
        margin-bottom: 0.35rem;
        line-height: 1;
    }
    .stat-label {
        font-size: 0.7rem;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.25em;
        font-weight: 600;
    }

    /* ============================================================
       STORY
       ============================================================ */
    .story-section {
        padding: 5rem 0;
        background: var(--section-alt);
    }
    .story-image {
        border-radius: 14px;
        overflow: hidden;
        box-shadow: 0 20px 40px -18px rgba(90, 82, 69, 0.25);
        border: 1px solid var(--border);
        position: relative;
    }
    .story-image::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(transparent 55%, rgba(30, 25, 18, 0.35));
        pointer-events: none;
    }
    .story-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
        transition: transform 0.6s ease;
        min-height: 380px;
    }
    .story-image:hover img { transform: scale(1.05); }
    .story-content h2 {
        font-size: clamp(1.9rem, 4vw, 2.6rem);
        margin-bottom: 1.5rem;
    }
    .story-content p {
        color: var(--text-muted);
        line-height: 1.85;
        margin-bottom: 1.25rem;
        font-size: 0.95rem;
    }

    /* ============================================================
       VALUES
       ============================================================ */
    .values-section {
        padding: 5rem 0;
        background: var(--card);
    }
    .value-card {
        text-align: center;
        padding: 2.5rem 1.5rem;
        border-radius: 14px;
        background: var(--bg-soft);
        border: 1px solid var(--border);
        transition: all 0.35s ease;
        height: 100%;
    }
    .value-card:hover {
        transform: translateY(-8px);
        border-color: var(--gold);
        box-shadow: 0 20px 40px -18px rgba(90, 82, 69, 0.25);
    }
    .value-icon {
        width: 64px;
        height: 64px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1.25rem;
        font-size: 1.4rem;
        color: var(--gold);
        background: var(--gold-soft);
        border: 1px solid rgba(184, 148, 31, 0.25);
        transition: transform 0.3s ease;
    }
    .value-card:hover .value-icon { transform: scale(1.1) rotate(-4deg); }
    .value-card h4 {
        font-size: 1.3rem;
        margin-bottom: 0.6rem;
    }
    .value-card p {
        color: var(--text-muted);
        line-height: 1.65;
        font-size: 0.88rem;
        margin: 0;
    }

    /* ============================================================
       MILESTONES
       ============================================================ */
    .milestones-section {
        padding: 5rem 0;
        background: var(--section-alt);
    }
    .milestone-item {
        text-align: center;
        padding: 2rem 1rem;
        border-radius: 14px;
        background: var(--card);
        border: 1px solid var(--border);
        transition: all 0.35s ease;
        height: 100%;
        position: relative;
    }
    .milestone-item:hover {
        transform: translateY(-8px);
        border-color: var(--gold);
        box-shadow: 0 20px 40px -18px rgba(90, 82, 69, 0.25);
    }
    .milestone-year {
        font-size: 2.2rem;
        font-weight: 600;
        color: var(--gold);
        margin-bottom: 0.5rem;
        line-height: 1;
    }
    .milestone-title {
        font-size: 1.3rem;
        font-weight: 600;
        color: var(--text-bright);
        margin-bottom: 0.5rem;
    }
    .milestone-desc {
        color: var(--text-muted);
        font-size: 0.85rem;
        line-height: 1.6;
        margin: 0;
    }

    /* ============================================================
       CTA
       ============================================================ */
    .cta-section {
        padding: 5rem 0;
        background: var(--section-alt);
    }
    .cta-inner {
        background: linear-gradient(135deg, #1e2533, #2a3142);
        border-radius: 18px;
        padding: 4rem 2rem;
        text-align: center;
        color: #fff;
        position: relative;
        overflow: hidden;
        border: 1px solid var(--border);
    }
    .cta-inner::before {
        content: '';
        position: absolute;
        inset: 0;
        background-image:
            radial-gradient(1.5px 1.5px at 12% 30%, rgba(184, 148, 31, 0.55), transparent),
            radial-gradient(1px 1px at 45% 65%, rgba(184, 148, 31, 0.35), transparent),
            radial-gradient(1.5px 1.5px at 78% 22%, rgba(184, 148, 31, 0.5), transparent),
            radial-gradient(1px 1px at 88% 80%, rgba(184, 148, 31, 0.3), transparent);
        pointer-events: none;
    }
    .cta-inner > * { position: relative; z-index: 1; }
    .cta-inner .eyebrow {
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.4em;
        text-transform: uppercase;
        color: var(--gold);
        margin-bottom: 0.75rem;
    }
    .cta-inner h2 {
        font-size: clamp(1.9rem, 4vw, 2.8rem);
        color: #fff;
        margin-bottom: 0.75rem;
    }
    .cta-inner p {
        color: rgba(255, 255, 255, 0.7);
        max-width: 520px;
        margin: 0 auto 1.75rem;
    }

    /* ============================================================
       RESPONSIVE
       ============================================================ */
    @media (max-width: 768px) {
        .about-hero { min-height: 400px; padding: 4rem 1.25rem 3rem; }
        .stats-section, .story-section, .values-section,
        .milestones-section, .cta-section { padding: 3.5rem 0; }
        .cta-inner { padding: 3rem 1.5rem; }
        .stat-number { font-size: 2.2rem; }
    }
    @media (max-width: 480px) {
        .about-hero h1 { font-size: 2.1rem; }
        .stat-number { font-size: 1.9rem; }
    }
    @media (prefers-reduced-motion: reduce) {
        * { animation-duration: .01ms !important; transition-duration: .01ms !important; }
    }
</style>

<!-- ============================================================
     HERO
     ============================================================ -->
<section class="about-hero">
    <div class="about-hero-content">
        <div class="hero-diamond">
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

        <nav class="lux-breadcrumb" aria-label="breadcrumb">
            <a href="index.php">Home</a>
            <span class="sep">·</span>
            <span class="current">About Us</span>
        </nav>

        <p class="hero-eyebrow">R-Jewel&Gold · Our Story</p>
        <h1>Crafting <em>Timeless</em> Elegance</h1>

        <div class="hero-divider">
            <span class="line"></span>
            <span class="diamond"></span>
            <span class="line"></span>
        </div>

        <p class="lead-text">
            Since 2020, we've been creating jewelry that tells your unique story —
            pieces crafted with precision, passion, and the finest materials.
        </p>
    </div>
</section>

<!-- ============================================================
     STATS
     ============================================================ -->
<section class="stats-section">
    <div class="container">
        <div class="row g-4">
            <div class="col-md-3 col-6">
                <div class="stat-item">
                    <div class="stat-number"><?php echo date('Y') - 2020; ?>+</div>
                    <div class="stat-label">Years of Excellence</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-item">
                    <div class="stat-number"><?php echo number_format($stats['products']); ?>+</div>
                    <div class="stat-label">Unique Pieces</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-item">
                    <div class="stat-number"><?php echo number_format($stats['customers']); ?>+</div>
                    <div class="stat-label">Happy Customers</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-item">
                    <div class="stat-number"><?php echo htmlspecialchars($avg_rating); ?>★</div>
                    <div class="stat-label">Customer Rating</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============================================================
     OUR STORY
     ============================================================ -->
<section class="story-section">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <div class="story-image">
                    <img src="https://images.unsplash.com/photo-1535632066927-ab7c9ab60908?w=800&h=600&fit=crop"
                         alt="Our Workshop"
                         loading="lazy"
                         onerror="this.onerror=null;this.src='https://via.placeholder.com/800x600/ede7db/8a8172?text=Our+Workshop'">
                </div>
            </div>
            <div class="col-lg-6">
                <div class="story-content">
                    <p class="eyebrow" style="font-size:0.68rem;font-weight:700;letter-spacing:0.4em;text-transform:uppercase;color:var(--gold);margin-bottom:0.75rem;">Our Journey</p>
                    <h2>A Passion for Fine Craftsmanship</h2>
                    <p>R-Jewel&Gold was born from a passion for creating beautiful, meaningful pieces that celebrate life's most precious moments. What started as a small workshop has grown into a beloved brand known for exceptional craftsmanship and timeless design.</p>
                    <p>Every piece we create tells a story — of love, of achievement, of memories waiting to be made. We believe that jewelry should be more than just an accessory; it should be an heirloom, a treasure passed down through generations.</p>
                    <p>Today, we're proud to serve thousands of customers worldwide, each finding their perfect piece in our collection. But our mission remains the same: to create jewelry that makes you feel special, every single day.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============================================================
     OUR VALUES
     ============================================================ -->
<section class="values-section">
    <div class="container">
        <div class="section-head">
            <p class="eyebrow">What Drives Us</p>
            <h2>Our Values</h2>
            <p>The principles that guide everything we do</p>
            <div class="small-divider"><span class="line"></span><span class="diamond"></span><span class="line"></span></div>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="value-card">
                    <div class="value-icon"><i class="fas fa-gem"></i></div>
                    <h4>Quality Craftsmanship</h4>
                    <p>Each piece is meticulously crafted by skilled artisans using only the finest materials and gemstones.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="value-card">
                    <div class="value-icon"><i class="fas fa-hand-sparkles"></i></div>
                    <h4>Ethical Sourcing</h4>
                    <p>We are committed to responsible sourcing, ensuring all our materials are ethically obtained.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="value-card">
                    <div class="value-icon"><i class="fas fa-heart"></i></div>
                    <h4>Customer First</h4>
                    <p>Your satisfaction is our priority. We're dedicated to providing exceptional service and support.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="value-card">
                    <div class="value-icon"><i class="fas fa-leaf"></i></div>
                    <h4>Sustainable Practices</h4>
                    <p>We're committed to minimizing our environmental impact through sustainable business practices.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="value-card">
                    <div class="value-icon"><i class="fas fa-lightbulb"></i></div>
                    <h4>Innovation</h4>
                    <p>We continuously explore new designs and techniques to bring you fresh, contemporary collections.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="value-card">
                    <div class="value-icon"><i class="fas fa-globe"></i></div>
                    <h4>Community</h4>
                    <p>We believe in giving back and supporting the communities that make our work possible.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============================================================
     MILESTONES
     ============================================================ -->
<section class="milestones-section">
    <div class="container">
        <div class="section-head">
            <p class="eyebrow">Milestones</p>
            <h2>Our Journey</h2>
            <p>Key moments that shaped our story</p>
            <div class="small-divider"><span class="line"></span><span class="diamond"></span><span class="line"></span></div>
        </div>

        <div class="row g-4">
            <div class="col-md-3 col-sm-6">
                <div class="milestone-item">
                    <div class="milestone-year">2020</div>
                    <div class="milestone-title">The Beginning</div>
                    <p class="milestone-desc">Founded with a vision to create timeless jewelry</p>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="milestone-item">
                    <div class="milestone-year">2021</div>
                    <div class="milestone-title">First Collection</div>
                    <p class="milestone-desc">Launched our debut collection to critical acclaim</p>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="milestone-item">
                    <div class="milestone-year">2022</div>
                    <div class="milestone-title">Global Recognition</div>
                    <p class="milestone-desc">Expanded to serve customers worldwide</p>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="milestone-item">
                    <div class="milestone-year">2023</div>
                    <div class="milestone-title">Sustainable Future</div>
                    <p class="milestone-desc">Committed to ethical and sustainable practices</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============================================================
     CTA
     ============================================================ -->
<section class="cta-section">
    <div class="container">
        <div class="cta-inner">
            <p class="eyebrow">Ready to Begin?</p>
            <h2>Find Your Perfect Piece</h2>
            <p>Explore our collection and discover jewelry that speaks to your heart — crafted for elegance, made for you.</p>
            <a href="shop.php" class="btn-lux btn-lux-gold">
                Shop Collection <i class="fas fa-arrow-right"></i>
            </a>
        </div>
    </div>
</section>

<?php include '../includes/footer.php'; ?>