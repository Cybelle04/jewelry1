<?php
// user/index.php — Luxury Home Page
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/auth.php';

$db = null;
try {
    $database = new Database();
    $db = $database->getConnection();
} catch (Throwable $e) {
    $db = null;
}

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

$featured_products = safe_query($db, "
    SELECT p.*, c.name AS category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.featured = 1
    ORDER BY p.created_at DESC
    LIMIT 8
");

$new_arrivals = safe_query($db, "
    SELECT p.*, c.name AS category_name,
           (SELECT ROUND(AVG(rating), 1) FROM reviews WHERE product_id = p.id AND status = 'approved') AS avg_rating,
           (SELECT COUNT(*)              FROM reviews WHERE product_id = p.id AND status = 'approved') AS review_count
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    ORDER BY p.created_at DESC
    LIMIT 4
");

$bestsellers = safe_query($db, "
    SELECT p.*, c.name AS category_name,
           (SELECT COUNT(*) FROM order_items WHERE product_id = p.id) AS sales_count,
           (SELECT ROUND(AVG(rating), 1) FROM reviews WHERE product_id = p.id AND status = 'approved') AS avg_rating,
           (SELECT COUNT(*)              FROM reviews WHERE product_id = p.id AND status = 'approved') AS review_count
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.stock > 0
    ORDER BY sales_count DESC
    LIMIT 4
");

$categories = safe_query($db, "
    SELECT c.*,
           (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
    FROM categories c
    ORDER BY c.name ASC
    LIMIT 6
");

$testimonials = safe_query($db, "
    SELECT r.rating, r.title, r.comment, r.created_at, r.is_verified,
           u.full_name,
           p.name        AS product_name,
           p.image       AS product_image,
           p.description AS product_description,
           p.id          AS product_id
    FROM reviews r
    JOIN users u ON u.id = r.user_id
    JOIN products p ON p.id = r.product_id
    WHERE r.status = 'approved' AND r.rating = 5
    ORDER BY r.updated_at DESC
    LIMIT 3
");

if (empty($testimonials)) {
    $testimonials = [
        ['full_name' => 'Sarah Johnson', 'rating' => 5, 'title' => 'Absolutely stunning!', 'comment' => 'The quality is exceptional and the customer service was outstanding. My new favorite jewelry store!', 'product_name' => 'Fine Jewelry', 'product_image' => '', 'product_description' => '', 'product_id' => 0, 'is_verified' => 0, 'created_at' => date('Y-m-d H:i:s')],
        ['full_name' => 'Michael Chen',  'rating' => 5, 'title' => 'Perfect engagement ring', 'comment' => 'Bought an engagement ring here and it was perfect. The craftsmanship is incredible. Highly recommended!', 'product_name' => 'Engagement Rings', 'product_image' => '', 'product_description' => '', 'product_id' => 0, 'is_verified' => 0, 'created_at' => date('Y-m-d H:i:s')],
        ['full_name' => 'Emma Williams', 'rating' => 5, 'title' => 'Beautiful collection', 'comment' => 'Beautiful collection and fast shipping. The packaging was elegant and made it feel extra special.', 'product_name' => 'Fine Jewelry', 'product_image' => '', 'product_description' => '', 'product_id' => 0, 'is_verified' => 0, 'created_at' => date('Y-m-d H:i:s')],
    ];
}

$page_title = 'Home | R-Jewel&Gold';
?>
<?php include '../includes/header.php'; ?>

<style>
    body, h1, h2, h3, h4, h5, h6,
    .hero h1, .section-head h2, .banner-inner h2,
    .testimonial-title, .testimonial-product-name,
    .reviews-empty h4, .product-price {
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

    .hero {
        position: relative;
        min-height: 620px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--bg);
        background-image:
            radial-gradient(ellipse at 20% 20%, rgba(184, 148, 31, 0.14), transparent 55%),
            radial-gradient(ellipse at 80% 80%, rgba(91, 95, 212, 0.08), transparent 55%);
        overflow: hidden;
        padding: 6rem 1.5rem 5rem;
    }
    .hero::before {
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
    .hero-content {
        position: relative;
        z-index: 2;
        text-align: center;
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
    .hero h1 {
        font-size: clamp(2.5rem, 6vw, 4.2rem);
        font-weight: 600;
        line-height: 1.05;
        color: var(--text-bright);
        margin-bottom: 1.25rem;
    }
    .hero h1 em {
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
    .hero p.lead-text {
        font-size: 1.02rem;
        color: var(--text-muted);
        max-width: 560px;
        margin: 0 auto 2.25rem;
        line-height: 1.7;
    }
    .hero-actions {
        display: flex;
        gap: 12px;
        justify-content: center;
        flex-wrap: wrap;
    }

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

    .features-section {
        padding: 5rem 0;
        background: var(--card);
        border-top: 1px solid var(--border-light);
        border-bottom: 1px solid var(--border-light);
    }
    .feature-card {
        text-align: center;
        padding: 2rem 1rem;
        transition: transform 0.3s ease;
    }
    .feature-card:hover { transform: translateY(-6px); }
    .feature-icon {
        width: 64px;
        height: 64px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1rem;
        font-size: 1.4rem;
        color: var(--gold);
        background: var(--gold-soft);
        border: 1px solid rgba(184, 148, 31, 0.25);
    }
    .feature-card h5 {
        font-size: 1.3rem;
        margin-bottom: 0.35rem;
    }
    .feature-card p {
        font-size: 0.82rem;
        color: var(--text-muted);
        margin: 0;
    }

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

    .category-section {
        padding: 5rem 0;
        background: var(--section-alt);
    }
    .category-card {
        position: relative;
        border-radius: 14px;
        overflow: hidden;
        cursor: pointer;
        transition: all 0.35s ease;
        border: 1px solid var(--border);
        background: var(--card);
        width: 100%;
        max-width: 200px;
        margin: 0 auto;
    }
    .category-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 20px 40px -18px rgba(90, 82, 69, 0.25);
        border-color: var(--gold);
    }
    .category-card img {
        width: 100%;
        height: 200px;
        object-fit: cover;
        object-position: center;
        transition: transform 0.6s ease;
        display: block;
    }
    .category-card:hover img { transform: scale(1.08); }
    .category-placeholder {
        width: 100%;
        height: 200px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, rgba(184, 148, 31, 0.12), rgba(91, 95, 212, 0.08));
        color: var(--gold);
        font-size: 2.5rem;
        transition: transform 0.6s ease;
    }
    .category-card:hover .category-placeholder { transform: scale(1.08); }

    .category-overlay {
        position: absolute;
        inset: 0;
        background: linear-gradient(transparent 35%, rgba(30, 25, 18, 0.85));
        display: flex;
        flex-direction: column;
        justify-content: flex-end;
        padding: 1.25rem 1rem 1rem;
        color: #fff;
        text-align: center;
    }
    .category-overlay h5 {
        font-size: 1.2rem;
        color: #fff;
        margin-bottom: 0.1rem;
    }
    .category-overlay small {
        font-size: 0.62rem;
        letter-spacing: 0.22em;
        text-transform: uppercase;
        color: rgba(255, 255, 255, 0.72);
    }

    .product-section { padding: 5rem 0; }
    .product-section.alt { background: var(--section-alt); }

    .product-card {
        border: 1px solid var(--border);
        border-radius: 14px;
        overflow: hidden;
        background: var(--card);
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
    .product-img-wrap {
        position: relative;
        overflow: hidden;
        background: var(--bg-soft);
    }
    .product-card img {
        width: 100%;
        height: 240px;
        object-fit: cover;
        transition: transform 0.6s ease;
        display: block;
    }
    .product-card:hover img { transform: scale(1.06); }

    .badge-lux {
        position: absolute;
        top: 12px;
        right: 12px;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.62rem;
        font-weight: 700;
        letter-spacing: 0.15em;
        text-transform: uppercase;
        color: #fff;
        z-index: 2;
    }
    .badge-low { background: #b91c1c; }
    .badge-new { background: var(--success); }

    .product-body {
        padding: 1.25rem 1.25rem 1.35rem;
        display: flex;
        flex-direction: column;
        flex-grow: 1;
    }
    .product-category {
        font-size: 0.65rem;
        font-weight: 700;
        letter-spacing: 0.2em;
        text-transform: uppercase;
        color: var(--gold);
        margin-bottom: 0.35rem;
    }
    .product-card h5 {
        font-size: 1.25rem;
        margin-bottom: 0.25rem;
        line-height: 1.25;
    }

    .product-desc {
        font-size: 0.8rem;
        color: var(--text-muted);
        line-height: 1.55;
        margin: 0.35rem 0 0.55rem;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .product-rating {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 0.75rem;
        color: var(--text-muted);
        margin-bottom: 0.65rem;
    }
    .product-rating .stars {
        color: var(--gold);
        font-size: 0.78rem;
        letter-spacing: 1px;
    }
    .product-rating .avg {
        font-weight: 600;
        color: var(--text-bright);
    }
    .product-rating .count {
        color: var(--text-dim);
        font-size: 0.72rem;
    }
    .product-rating .no-rating {
        color: var(--text-dim);
        font-size: 0.72rem;
        font-style: italic;
    }

    .product-price {
        font-size: 1.35rem;
        font-weight: 600;
        color: var(--text-bright);
    }
    .product-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: auto;
        padding-top: 0.75rem;
        gap: 8px;
    }
    .btn-product {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 0.55rem 1rem;
        border-radius: 7px;
        font-size: 0.7rem;
        font-weight: 600;
        letter-spacing: 0.15em;
        text-transform: uppercase;
        border: none;
        cursor: pointer;
        transition: all 0.25s ease;
        text-decoration: none;
    }
    .btn-product-gold {
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        color: #fff;
        box-shadow: 0 3px 10px -2px rgba(184, 148, 31, 0.4);
    }
    .btn-product-gold:hover {
        color: #fff;
        transform: translateY(-1px);
        box-shadow: 0 6px 16px -3px rgba(184, 148, 31, 0.55);
    }
    .btn-product-outline {
        background: transparent;
        color: var(--text-bright);
        border: 1px solid var(--border);
    }
    .btn-product-outline:hover {
        color: var(--gold);
        border-color: var(--gold);
    }

    .banner-section { padding: 5rem 0; background: var(--section-alt); }
    .banner-inner {
        background: linear-gradient(135deg, #1e2533, #2a3142);
        border-radius: 18px;
        padding: 4rem 2rem;
        text-align: center;
        color: #fff;
        position: relative;
        overflow: hidden;
        border: 1px solid var(--border);
    }
    .banner-inner::before {
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
    .banner-inner > * { position: relative; z-index: 1; }
    .banner-inner .eyebrow {
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.4em;
        text-transform: uppercase;
        color: var(--gold);
        margin-bottom: 0.75rem;
    }
    .banner-inner h2 {
        font-size: clamp(1.9rem, 4vw, 2.8rem);
        color: #fff;
        margin-bottom: 0.75rem;
    }
    .banner-inner p {
        color: rgba(255, 255, 255, 0.7);
        max-width: 520px;
        margin: 0 auto 1.75rem;
    }

    .testimonial-section {
        padding: 5rem 0;
        background: var(--card);
        border-top: 1px solid var(--border-light);
        border-bottom: 1px solid var(--border-light);
    }
    .testimonial-card {
        background: var(--bg-soft);
        border: 1px solid var(--border);
        border-radius: 14px;
        padding: 1.5rem;
        height: 100%;
        transition: all 0.3s ease;
        display: flex;
        flex-direction: column;
    }
    .testimonial-card:hover {
        transform: translateY(-6px);
        border-color: var(--gold);
        box-shadow: 0 18px 36px -18px rgba(90, 82, 69, 0.22);
    }

    .testimonial-product-block {
        display: block;
        text-decoration: none;
        color: inherit;
        margin-bottom: 1rem;
        border-radius: 12px;
        overflow: hidden;
        border: 1px solid var(--border-light);
        background: var(--card);
        transition: all 0.25s ease;
    }
    .testimonial-product-block:hover {
        border-color: var(--gold);
        transform: translateY(-2px);
        box-shadow: 0 10px 22px -14px rgba(184, 148, 31, 0.5);
        color: inherit;
    }
    .testimonial-product-image-wrap {
        position: relative;
        width: 100%;
        aspect-ratio: 16 / 10;
        overflow: hidden;
        background: var(--bg-soft);
    }
    .testimonial-product-image {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
        transition: transform 0.5s ease;
    }
    .testimonial-product-block:hover .testimonial-product-image {
        transform: scale(1.05);
    }
    .testimonial-product-placeholder {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, rgba(184,148,31,0.15), rgba(91,95,212,0.1));
        color: var(--gold);
        font-size: 2rem;
    }
    .testimonial-product-info {
        padding: 0.85rem 1rem;
        background: var(--card);
        border-top: 1px solid var(--border-light);
    }
    .testimonial-product-name-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 0.35rem;
    }
    .testimonial-product-name {
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--text-bright);
        line-height: 1.2;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        flex: 1;
        min-width: 0;
    }
    .testimonial-product-block:hover .testimonial-product-name {
        color: var(--gold);
    }
    .testimonial-product-arrow {
        color: var(--text-dim);
        font-size: 0.72rem;
        flex-shrink: 0;
        transition: transform 0.25s ease, color 0.25s ease;
    }
    .testimonial-product-block:hover .testimonial-product-arrow {
        color: var(--gold);
        transform: translateX(3px);
    }
    .testimonial-product-description {
        font-size: 0.78rem;
        color: var(--text-muted);
        line-height: 1.55;
        margin: 0;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .testimonial-stars {
        color: var(--gold);
        font-size: 0.9rem;
        letter-spacing: 2px;
        margin-bottom: 0.6rem;
    }
    .testimonial-title {
        font-size: 1.15rem;
        font-weight: 600;
        color: var(--text-bright);
        margin-bottom: 0.4rem;
        line-height: 1.3;
    }
    .testimonial-text {
        font-size: 0.9rem;
        color: var(--text);
        line-height: 1.7;
        margin-bottom: 1.25rem;
        font-style: italic;
    }

    .testimonial-footer {
        display: flex;
        align-items: center;
        gap: 12px;
        padding-top: 1rem;
        border-top: 1px solid var(--border-light);
        margin-top: auto;
    }
    .testimonial-avatar {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-size: 1.2rem;
        font-weight: 600;
        flex-shrink: 0;
        box-shadow: 0 4px 12px -4px rgba(184, 148, 31, 0.6);
    }
    .testimonial-name {
        font-size: 1.05rem;
        color: var(--text-bright);
        font-weight: 600;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .verified-pill {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 8px;
        border-radius: 20px;
        background: rgba(13, 150, 104, 0.12);
        color: var(--success);
        font-family: 'Inter', sans-serif;
        font-size: 0.6rem;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        font-style: normal;
    }
    .testimonial-date {
        font-size: 0.72rem;
        color: var(--text-dim);
        margin-top: 2px;
    }
    .reviews-empty {
        text-align: center;
        padding: 3rem 1rem;
        background: var(--bg-soft);
        border: 1px dashed var(--border);
        border-radius: 12px;
        max-width: 520px;
        margin: 0 auto;
    }
    .reviews-empty i {
        font-size: 2rem;
        color: var(--gold);
        margin-bottom: 0.75rem;
        display: block;
    }
    .reviews-empty h4 {
        font-size: 1.4rem;
        margin-bottom: 0.35rem;
    }
    .reviews-empty p {
        color: var(--text-muted);
        font-size: 0.9rem;
        margin: 0 0 1.25rem;
    }

    .newsletter-section { padding: 5rem 0; background: var(--section-alt); }
    .newsletter-inner {
        max-width: 620px;
        margin: 0 auto;
        text-align: center;
    }
    .newsletter-form {
        max-width: 520px;
        margin: 1.75rem auto 0;
    }
    .newsletter-form .input-group {
        border-radius: 9px;
        overflow: hidden;
        box-shadow: 0 10px 30px -12px rgba(90, 82, 69, 0.2);
        border: 1px solid var(--border);
        display: flex;
    }
    .newsletter-form input {
        flex: 1;
        border: none;
        padding: 1rem 1.25rem;
        font-size: 0.9rem;
        background: var(--card);
        color: var(--text-bright);
        font-family: 'Inter', sans-serif;
    }
    .newsletter-form input:focus {
        outline: none;
        box-shadow: none;
        background: var(--card);
        color: var(--text-bright);
    }
    .newsletter-form input::placeholder { color: var(--text-dim); }
    .newsletter-form button {
        border: none;
        padding: 0 1.75rem;
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        color: #fff;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.2em;
        text-transform: uppercase;
        transition: all 0.3s ease;
        cursor: pointer;
    }
    .newsletter-form button:hover { filter: brightness(1.08); }

    .toast-notification {
        position: fixed;
        bottom: 24px;
        right: 24px;
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
        to   { transform: translateX(0);    opacity: 1; }
    }

    @media (max-width: 768px) {
        .hero { min-height: 520px; padding: 4rem 1.25rem; }
        .hero-actions { flex-direction: column; align-items: stretch; }
        .btn-lux { justify-content: center; }
        .features-section,
        .category-section,
        .product-section,
        .banner-section,
        .testimonial-section,
        .newsletter-section { padding: 3.5rem 0; }
        .banner-inner { padding: 3rem 1.5rem; }
    }
    @media (max-width: 480px) {
        .hero h1 { font-size: 2.1rem; }
        .product-card img { height: 200px; }
        .category-card img,
        .category-placeholder { height: 180px; }
        .category-card { max-width: 170px; }
    }
    @media (prefers-reduced-motion: reduce) {
        * { animation-duration: .01ms !important; transition-duration: .01ms !important; }
    }
</style>

<section class="hero">
    <div class="hero-content">
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

        <p class="hero-eyebrow">R-Jewel&Gold · Fine Jewelry</p>
        <h1>Elegance in <em>Every</em> Piece</h1>

        <div class="hero-divider">
            <span class="line"></span>
            <span class="diamond"></span>
            <span class="line"></span>
        </div>

        <p class="lead-text">
            Discover our exquisite collection of fine jewelry crafted with precision and passion — timeless pieces for life's most cherished moments.
        </p>

        <div class="hero-actions">
            <a href="shop.php" class="btn-lux btn-lux-gold">
                Shop Collection <i class="fas fa-arrow-right"></i>
            </a>
            <a href="#featured" class="btn-lux btn-lux-outline">
                <i class="fas fa-gem"></i> Featured Pieces
            </a>
        </div>
    </div>
</section>

<section class="features-section">
    <div class="container">
        <div class="row g-4">
            <div class="col-md-3 col-6">
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-truck"></i></div>
                    <h5>Free Shipping</h5>
                    <p>On orders over ₱100</p>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-gem"></i></div>
                    <h5>Premium Quality</h5>
                    <p>100% authentic materials</p>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-undo"></i></div>
                    <h5>Easy Returns</h5>
                    <p>30-day return policy</p>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-lock"></i></div>
                    <h5>Secure Payment</h5>
                    <p>100% secure transactions</p>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="category-section">
    <div class="container">
        <div class="section-head">
            <p class="eyebrow">Explore</p>
            <h2>Shop by Category</h2>
            <p>Find the perfect piece for every occasion</p>
            <div class="small-divider"><span class="line"></span><span class="diamond"></span><span class="line"></span></div>
        </div>

        <?php if (!empty($categories)): ?>
        <div class="row g-4 justify-content-center">
            <?php foreach($categories as $category): ?>
            <?php
                $catImgFile = trim((string)($category['image'] ?? ''));
                $catImgDisk = __DIR__ . '/../uploads/categories/' . $catImgFile;
                $catImgUrl  = ($catImgFile !== '' && file_exists($catImgDisk))
                    ? '../uploads/categories/' . htmlspecialchars($catImgFile, ENT_QUOTES, 'UTF-8')
                    : '';
            ?>
            <div class="col-6 col-md-4 col-lg-2 d-flex justify-content-center">
                <div class="category-card" onclick="location.href='shop.php?category=<?php echo (int)$category['id']; ?>'">
                    <?php if ($catImgUrl !== ''): ?>
                        <img src="<?php echo $catImgUrl; ?>"
                             alt="<?php echo htmlspecialchars($category['name']); ?>"
                             loading="lazy">
                    <?php else: ?>
                        <div class="category-placeholder">
                            <i class="fas fa-tag"></i>
                        </div>
                    <?php endif; ?>
                    <div class="category-overlay">
                        <h5><?php echo htmlspecialchars($category['name']); ?></h5>
                        <small><?php echo (int)($category['product_count'] ?? 0); ?> Products</small>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="text-center" style="color:var(--text-muted);">No categories yet.</p>
        <?php endif; ?>

        <div class="text-center mt-5">
            <a href="shop.php" class="btn-lux btn-lux-outline">
                View All Categories <i class="fas fa-arrow-right"></i>
            </a>
        </div>
    </div>
</section>

<section class="product-section" id="featured">
    <div class="container">
        <div class="section-head">
            <p class="eyebrow">Curated Selection</p>
            <h2>Featured Products</h2>
            <p>Our most popular and hand-picked pieces</p>
            <div class="small-divider"><span class="line"></span><span class="diamond"></span><span class="line"></span></div>
        </div>

        <?php if (!empty($featured_products)): ?>
        <div class="row g-4">
            <?php foreach($featured_products as $product): ?>
            <div class="col-md-6 col-lg-3">
                <div class="product-card">
                    <div class="product-img-wrap">
                        <button type="button"
                                class="wish-heart"
                                data-product-id="<?php echo (int)$product['id']; ?>"
                                aria-label="Save to wishlist"
                                onclick="toggleWishlist(<?php echo (int)$product['id']; ?>, this)">
                            <i class="fas fa-heart"></i>
                        </button>
                        <?php if(($product['stock'] ?? 0) <= 5): ?>
                            <span class="badge-lux badge-low">Low Stock</span>
                        <?php endif; ?>
                        <img src="../uploads/<?php echo htmlspecialchars($product['image'] ?? 'placeholder.jpg'); ?>"
                             onerror="this.onerror=null;this.src='https://via.placeholder.com/400x300/ede7db/8a8172?text=Jewelry'"
                             alt="<?php echo htmlspecialchars($product['name']); ?>"
                             loading="lazy">
                    </div>
                    <div class="product-body">
                        <p class="product-category"><?php echo htmlspecialchars($product['category_name'] ?? 'Jewelry'); ?></p>
                        <h5><?php echo htmlspecialchars($product['name']); ?></h5>
                        <div class="product-footer">
                            <span class="product-price">₱<?php echo number_format((float)$product['price'], 2); ?></span>
                            <a href="product-details.php?id=<?php echo (int)$product['id']; ?>" class="btn-product btn-product-gold">
                                View
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="text-center" style="color:var(--text-muted);">No featured products yet.</p>
        <?php endif; ?>
    </div>
</section>

<section class="banner-section">
    <div class="container">
        <div class="banner-inner">
            <p class="eyebrow">Limited Time Offer</p>
            <h2>Get 20% Off This Weekend</h2>
            <p>Enjoy special savings on all jewelry pieces — crafted for elegance, priced for the moment.</p>
            <a href="shop.php" class="btn-lux btn-lux-gold">
                Shop Now <i class="fas fa-arrow-right"></i>
            </a>
        </div>
    </div>
</section>

<section class="product-section alt">
    <div class="container">
        <div class="section-head">
            <p class="eyebrow">Customer Favorites</p>
            <h2>Best Sellers</h2>
            <p>Pieces our customers love most</p>
            <div class="small-divider"><span class="line"></span><span class="diamond"></span><span class="line"></span></div>
        </div>

        <?php if (!empty($bestsellers)): ?>
        <div class="row g-4">
            <?php foreach($bestsellers as $product): ?>
            <?php
                $avg   = isset($product['avg_rating'])   ? (float)$product['avg_rating']   : 0;
                $count = isset($product['review_count']) ? (int)$product['review_count'] : 0;
            ?>
            <div class="col-md-6 col-lg-3">
                <div class="product-card">
                    <div class="product-img-wrap">
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
                    </div>
                    <div class="product-body">
                        <p class="product-category"><?php echo htmlspecialchars($product['category_name'] ?? 'Jewelry'); ?></p>
                        <h5><?php echo htmlspecialchars($product['name']); ?></h5>

                        <?php if (!empty($product['description'])): ?>
                            <p class="product-desc"><?php echo htmlspecialchars($product['description']); ?></p>
                        <?php endif; ?>

                        <div class="product-rating">
                            <?php if ($count > 0): ?>
                                <span class="stars">
                                    <?php
                                    for ($i = 1; $i <= 5; $i++) {
                                        if ($i <= floor($avg))       echo '<i class="fas fa-star"></i>';
                                        elseif ($i - 0.5 <= $avg)    echo '<i class="fas fa-star-half-alt"></i>';
                                        else                         echo '<i class="far fa-star"></i>';
                                    }
                                    ?>
                                </span>
                                <span class="avg"><?php echo number_format($avg, 1); ?></span>
                                <span class="count">(<?php echo $count; ?>)</span>
                            <?php else: ?>
                                <span class="no-rating">No reviews yet</span>
                            <?php endif; ?>
                        </div>

                        <div class="product-footer">
                            <span class="product-price">₱<?php echo number_format((float)$product['price'], 2); ?></span>
                            <button onclick="addToCart(<?php echo (int)$product['id']; ?>)" class="btn-product btn-product-gold">
                                <i class="fas fa-shopping-cart"></i> Add
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="text-center" style="color:var(--text-muted);">No best sellers yet.</p>
        <?php endif; ?>
    </div>
</section>

<section class="product-section">
    <div class="container">
        <div class="section-head">
            <p class="eyebrow">Fresh From the Studio</p>
            <h2>New Arrivals</h2>
            <p>The latest additions to our collection</p>
            <div class="small-divider"><span class="line"></span><span class="diamond"></span><span class="line"></span></div>
        </div>

        <?php if (!empty($new_arrivals)): ?>
        <div class="row g-4">
            <?php foreach($new_arrivals as $product): ?>
            <?php
                $avg   = isset($product['avg_rating'])   ? (float)$product['avg_rating']   : 0;
                $count = isset($product['review_count']) ? (int)$product['review_count'] : 0;
            ?>
            <div class="col-md-6 col-lg-3">
                <div class="product-card">
                    <div class="product-img-wrap">
                        <button type="button"
                                class="wish-heart"
                                data-product-id="<?php echo (int)$product['id']; ?>"
                                aria-label="Save to wishlist"
                                onclick="toggleWishlist(<?php echo (int)$product['id']; ?>, this)">
                            <i class="fas fa-heart"></i>
                        </button>
                        <span class="badge-lux badge-new">New</span>
                        <img src="../uploads/<?php echo htmlspecialchars($product['image'] ?? 'placeholder.jpg'); ?>"
                             onerror="this.onerror=null;this.src='https://via.placeholder.com/400x300/ede7db/8a8172?text=Jewelry'"
                             alt="<?php echo htmlspecialchars($product['name']); ?>"
                             loading="lazy">
                    </div>
                    <div class="product-body">
                        <p class="product-category"><?php echo htmlspecialchars($product['category_name'] ?? 'Jewelry'); ?></p>
                        <h5><?php echo htmlspecialchars($product['name']); ?></h5>

                        <?php if (!empty($product['description'])): ?>
                            <p class="product-desc"><?php echo htmlspecialchars($product['description']); ?></p>
                        <?php endif; ?>

                        <div class="product-rating">
                            <?php if ($count > 0): ?>
                                <span class="stars">
                                    <?php
                                    for ($i = 1; $i <= 5; $i++) {
                                        if ($i <= floor($avg))       echo '<i class="fas fa-star"></i>';
                                        elseif ($i - 0.5 <= $avg)    echo '<i class="fas fa-star-half-alt"></i>';
                                        else                         echo '<i class="far fa-star"></i>';
                                    }
                                    ?>
                                </span>
                                <span class="avg"><?php echo number_format($avg, 1); ?></span>
                                <span class="count">(<?php echo $count; ?>)</span>
                            <?php else: ?>
                                <span class="no-rating">No reviews yet</span>
                            <?php endif; ?>
                        </div>

                        <div class="product-footer">
                            <span class="product-price">₱<?php echo number_format((float)$product['price'], 2); ?></span>
                            <a href="product-details.php?id=<?php echo (int)$product['id']; ?>" class="btn-product btn-product-outline">
                                Shop
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="text-center" style="color:var(--text-muted);">No new arrivals yet.</p>
        <?php endif; ?>
    </div>
</section>

<section class="testimonial-section">
    <div class="container">
        <div class="section-head">
            <p class="eyebrow">Kind Words</p>
            <h2>What Our Clients Say</h2>
            <p>Top-rated 5-star reviews from real customers</p>
            <div class="small-divider"><span class="line"></span><span class="diamond"></span><span class="line"></span></div>
        </div>

        <?php if (!empty($testimonials)): ?>
        <div class="row g-4 justify-content-center">
            <?php foreach($testimonials as $t): ?>
            <?php
                $name      = $t['full_name'] ?? 'Anonymous';
                $rating    = max(1, min(5, (int)($t['rating'] ?? 5)));
                $title     = trim((string)($t['title'] ?? ''));
                $comment   = trim((string)($t['comment'] ?? ''));
                $verified  = !empty($t['is_verified']);
                $prodName  = $t['product_name'] ?? '';
                $prodImg   = trim((string)($t['product_image'] ?? ''));
                $prodDesc  = trim((string)($t['product_description'] ?? ''));
                $prodId    = (int)($t['product_id'] ?? 0);
                $dateStr   = !empty($t['created_at']) ? date('M d, Y', strtotime($t['created_at'])) : '';

                $prodImgDisk = __DIR__ . '/../uploads/' . $prodImg;
                $prodImgUrl  = ($prodImg !== '' && file_exists($prodImgDisk))
                    ? '../uploads/' . htmlspecialchars($prodImg, ENT_QUOTES, 'UTF-8')
                    : '';
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="testimonial-card">
                    <?php if ($prodId > 0 && $prodName !== ''): ?>
                        <a href="product-details.php?id=<?php echo $prodId; ?>#reviews"
                           class="testimonial-product-block"
                           title="View <?php echo htmlspecialchars($prodName); ?>">
                            <div class="testimonial-product-image-wrap">
                                <?php if ($prodImgUrl !== ''): ?>
                                    <img src="<?php echo $prodImgUrl; ?>"
                                         alt="<?php echo htmlspecialchars($prodName); ?>"
                                         class="testimonial-product-image"
                                         loading="lazy"
                                         onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                                    <div class="testimonial-product-placeholder" style="display:none;">
                                        <i class="fas fa-gem"></i>
                                    </div>
                                <?php else: ?>
                                    <div class="testimonial-product-placeholder">
                                        <i class="fas fa-gem"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="testimonial-product-info">
                                <div class="testimonial-product-name-bar">
                                    <span class="testimonial-product-name">
                                        <?php echo htmlspecialchars($prodName); ?>
                                    </span>
                                    <i class="fas fa-chevron-right testimonial-product-arrow"></i>
                                </div>
                                <?php if ($prodDesc !== ''): ?>
                                    <p class="testimonial-product-description">
                                        <?php echo htmlspecialchars($prodDesc); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endif; ?>

                    <div class="testimonial-stars">
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                    </div>

                    <?php if ($title !== ''): ?>
                        <div class="testimonial-title"><?php echo htmlspecialchars($title); ?></div>
                    <?php endif; ?>

                    <p class="testimonial-text">
                        <?php
                        $short = $comment;
                        if (function_exists('mb_strlen') && mb_strlen($short) > 200) {
                            $short = mb_substr($short, 0, 200) . '…';
                        } elseif (strlen($short) > 200) {
                            $short = substr($short, 0, 200) . '…';
                        }
                        echo htmlspecialchars($short);
                        ?>
                    </p>

                    <div class="testimonial-footer">
                        <div class="testimonial-avatar">
                            <?php echo strtoupper(substr($name, 0, 1)); ?>
                        </div>
                        <div>
                            <p class="testimonial-name">
                                <?php echo htmlspecialchars($name); ?>
                                <?php if ($verified): ?>
                                    <span class="verified-pill"><i class="fas fa-check-circle"></i> Verified</span>
                                <?php endif; ?>
                            </p>
                            <?php if ($dateStr): ?>
                                <div class="testimonial-date"><?php echo htmlspecialchars($dateStr); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="reviews-empty">
            <i class="fas fa-comment-dots"></i>
            <h4>No 5-star reviews yet</h4>
            <p>Be the first to leave a 5-star review on one of our pieces.</p>
            <a href="shop.php" class="btn-lux btn-lux-gold">
                <i class="fas fa-shopping-bag"></i> Shop Now
            </a>
        </div>
        <?php endif; ?>
    </div>
</section>

<section class="newsletter-section">
    <div class="container">
        <div class="newsletter-inner">
            <div class="section-head" style="margin-bottom:0;">
                <p class="eyebrow">Stay in Touch</p>
                <h2>Subscribe to Our Newsletter</h2>
                <p>Get exclusive offers and updates on new collections</p>
                <div class="small-divider"><span class="line"></span><span class="diamond"></span><span class="line"></span></div>
            </div>

            <form class="newsletter-form" id="newsletterForm">
                <div class="input-group">
                    <input type="email" class="form-control" placeholder="Enter your email address" required>
                    <button type="submit">
                        Subscribe <i class="fas fa-paper-plane ms-1"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>
</section>

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
        body.appendChild(i);
        body.appendChild(span);
        el.appendChild(body);
        document.body.appendChild(el);
        setTimeout(function () {
            el.style.transition = 'opacity .3s ease';
            el.style.opacity = '0';
            setTimeout(function () { el.remove(); }, 300);
        }, 3000);
    }

    window.addToCart = function (productId) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'add-to-cart.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            if (xhr.status === 200) {
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success) {
                        showNotification('Product added to cart!', 'success');
                        if (typeof window.updateCartCount === 'function') window.updateCartCount();
                    } else {
                        showNotification(res.message || 'Please login to add items to cart', 'error');
                    }
                } catch (e) {
                    showNotification('Please login to add items to cart', 'error');
                }
            } else {
                showNotification('Network error. Please try again.', 'error');
            }
        };
        xhr.send('product_id=' + encodeURIComponent(productId) + '&quantity=1');
    };

    var form = document.getElementById('newsletterForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var input = form.querySelector('input[type="email"]');
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'subscribe.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) return;
                if (xhr.status === 200) {
                    showNotification('Thank you for subscribing!', 'success');
                    form.reset();
                } else {
                    showNotification('Subscription failed. Try again.', 'error');
                }
            };
            xhr.send('email=' + encodeURIComponent(input.value));
        });
    }

    document.querySelectorAll('a[href^="#"]').forEach(function (a) {
        a.addEventListener('click', function (e) {
            var href = a.getAttribute('href');
            if (href === '#' || href.length < 2) return;
            var target = document.querySelector(href);
            if (target) {
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth' });
            }
        });
    });

    if (typeof window.refreshWishlist === 'function') {
        window.refreshWishlist();
    }
})();
</script>

<?php include '../includes/footer.php'; ?>