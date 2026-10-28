<?php
// includes/footer.php — Luxury footer
// Reads the same rjewel_theme cookie as the header/index.php for theme consistency

$footer_theme = 'cream';
if (!empty($_COOKIE['rjewel_theme'])) {
    $parsed = json_decode(urldecode($_COOKIE['rjewel_theme']), true);
    if (is_array($parsed) && in_array($parsed['theme'] ?? '', ['cream','light','dark'], true)) {
        $footer_theme = $parsed['theme'];
    }
}
?>
    </main>

    <!-- ============================================================
         LUXURY FOOTER
         ============================================================ -->
    <footer class="lux-footer">
        <div class="footer-glow" aria-hidden="true"></div>

        <div class="container footer-inner">

            <!-- Top: brand + newsletter -->
            <div class="footer-top">
                <div class="brand-block">
                    <div class="brand-logo">
                        <svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
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
                        <div class="brand-text">
                            <span class="brand-name">R-Jewel<em>&amp;Gold</em></span>
                            <span class="brand-tag">Fine Jewelry · Since 2026</span>
                        </div>
                    </div>
                    <p class="brand-desc">
                        Exquisite jewelry for every occasion — crafted with passion, precision, and ethically sourced materials.
                    </p>

                    <div class="footer-socials">
                        <a href="#" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                        <a href="#" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                        <a href="#" aria-label="Pinterest"><i class="fab fa-pinterest-p"></i></a>
                    </div>
                </div>

                <!-- Newsletter -->
                <div class="newsletter-block">
                    <p class="footer-eyebrow">Stay in Touch</p>
                    <h4>Join our <em>collection</em> list</h4>
                    <p class="newsletter-desc">Get early access to new arrivals, private sales, and curated gift ideas.</p>
                    <form class="newsletter-form" id="footerNewsletter" onsubmit="return false;">
                        <div class="input-group-lux">
                            <input type="email" placeholder="Your email address" aria-label="Email" required>
                            <button type="submit" aria-label="Subscribe">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Middle: link columns (Explore + Visit Us) -->
            <div class="footer-grid">
                <div class="footer-col">
                    <h6>Explore</h6>
                    <ul>
                        <li><a href="../user/index.php">Home</a></li>
                        <li><a href="../user/shop.php">Shop</a></li>
                        <li><a href="../user/about.php">About Us</a></li>
                        <li><a href="../user/contact.php">Contact</a></li>
                        <li><a href="../user/virtual-tryon.php">Virtual Try-On</a></li>
                    </ul>
                </div>

                <div class="footer-col contact-col">
                    <h6>Visit Us</h6>
                    <ul class="contact-list">
                        <li>
                            <i class="fas fa-map-marker-alt"></i>
                            <span>Cebu City, Philippines</span>
                        </li>
                        <li>
                            <i class="fas fa-phone"></i>
                            <a href="tel:+639764689323">+63 976 468 9323</a>
                        </li>
                        <li>
                            <i class="fas fa-envelope"></i>
                            <a href="mailto:info@jewelryshop.com">info@jewelryshop.com</a>
                        </li>
                        <li>
                            <i class="fas fa-clock"></i>
                            <span>Mon–Fri · 9AM – 6PM</span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Divider with diamond -->
            <div class="footer-divider" aria-hidden="true">
                <span class="line"></span>
                <span class="diamond"></span>
                <span class="line"></span>
            </div>

            <!-- Bottom bar -->
            <div class="footer-bottom">
                <p class="copyright">&copy; <?php echo date('Y'); ?> <strong>R-Jewel&amp;Gold</strong>. All rights reserved.</p>
                <ul class="legal-links">
                    <li><a href="#">Privacy Policy</a></li>
                    <li><a href="#">Terms of Service</a></li>
                    <li><a href="#">Cookies</a></li>
                </ul>
            </div>

        </div>
    </footer>

    <!-- ============================================================
         FOOTER STYLES (theme-aware)
         ============================================================ -->
    <style>
        :root {
            --bg: #f2ede4; --bg-soft: #faf7f2; --card: #ffffff;
            --border: #e5ddd0; --border-light: #ede7db;
            --text: #5a5245; --text-bright: #2b2620; --text-muted: #8a8172; --text-dim: #b3a998;
            --gold: #b8941f; --gold-soft: rgba(184,148,31,0.1); --gold-strong: #8a6e14;
        }
        <?php if ($footer_theme === 'dark'): ?>
        :root {
            --bg: #151821; --bg-soft: #1c202b; --card: #222735;
            --border: #343b4d; --border-light: #2d3444;
            --text: #d5d9e2; --text-bright: #ffffff; --text-muted: #a0a8ba; --text-dim: #777f91;
        }
        <?php elseif ($footer_theme === 'light'): ?>
        :root {
            --bg: #f5f7fb; --bg-soft: #ffffff; --card: #ffffff;
            --border: #e2e8f0; --border-light: #edf1f6;
            --text: #475569; --text-bright: #172033; --text-muted: #64748b; --text-dim: #94a3b8;
        }
        <?php endif; ?>

        /* Footer shell — deep navy for luxury contrast */
        .lux-footer {
            position: relative;
            margin-top: 5rem;
            background: linear-gradient(180deg, #1e2533 0%, #161b26 100%);
            color: #cbd2df;
            overflow: hidden;
            border-top: 1px solid rgba(184, 148, 31, 0.25);
        }

        .footer-glow {
            position: absolute;
            inset: 0;
            pointer-events: none;
            background-image:
                radial-gradient(ellipse at 15% 0%, rgba(184, 148, 31, 0.14), transparent 55%),
                radial-gradient(ellipse at 85% 100%, rgba(91, 95, 212, 0.08), transparent 55%),
                radial-gradient(1px 1px at 12% 40%, rgba(184, 148, 31, 0.5), transparent),
                radial-gradient(1px 1px at 45% 75%, rgba(184, 148, 31, 0.35), transparent),
                radial-gradient(1.5px 1.5px at 78% 20%, rgba(184, 148, 31, 0.45), transparent),
                radial-gradient(1px 1px at 92% 65%, rgba(184, 148, 31, 0.3), transparent);
        }

        .footer-inner {
            position: relative;
            z-index: 1;
            padding: 4.5rem 1rem 2rem;
            max-width: 1200px;
        }

        /* ---------- TOP: brand + newsletter ---------- */
        .footer-top {
            display: grid;
            grid-template-columns: 1.4fr 1fr;
            gap: 3rem;
            padding-bottom: 3rem;
            border-bottom: 1px solid rgba(184, 148, 31, 0.15);
        }
        @media (max-width: 900px) {
            .footer-top { grid-template-columns: 1fr; gap: 2.25rem; }
        }

        .brand-block { max-width: 520px; }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 1rem;
        }
        .brand-logo svg {
            width: 46px; height: 46px;
            flex-shrink: 0;
            filter: drop-shadow(0 4px 14px rgba(184, 148, 31, 0.35));
        }
        .brand-name {
            display: block;
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.6rem;
            font-weight: 600;
            color: #fff;
            line-height: 1.1;
            letter-spacing: 0.01em;
        }
        .brand-name em {
            color: var(--gold);
            font-style: italic;
            font-weight: 500;
        }
        .brand-tag {
            display: block;
            font-size: 0.68rem;
            font-weight: 600;
            letter-spacing: 0.25em;
            text-transform: uppercase;
            color: var(--gold);
            margin-top: 4px;
            opacity: 0.9;
        }

        .brand-desc {
            color: #94a0b5;
            font-size: 0.9rem;
            line-height: 1.7;
            margin: 0 0 1.35rem;
        }

        .footer-socials {
            display: flex;
            gap: 10px;
        }
        .footer-socials a {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(184, 148, 31, 0.25);
            color: #d5d9e2;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.28s ease;
            font-size: 0.9rem;
        }
        .footer-socials a:hover {
            background: linear-gradient(135deg, var(--gold), var(--gold-strong));
            border-color: transparent;
            color: #fff;
            transform: translateY(-3px);
            box-shadow: 0 10px 22px -10px rgba(184, 148, 31, 0.85);
        }

        /* Newsletter */
        .newsletter-block {
            background: rgba(184, 148, 31, 0.06);
            border: 1px solid rgba(184, 148, 31, 0.22);
            border-radius: 14px;
            padding: 1.5rem 1.5rem 1.6rem;
        }
        .footer-eyebrow {
            font-size: 0.66rem;
            font-weight: 700;
            letter-spacing: 0.35em;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: 0.4rem;
        }
        .newsletter-block h4 {
            font-family: 'Cormorant Garamond', serif;
            color: #fff;
            font-size: 1.55rem;
            margin: 0 0 0.5rem;
            line-height: 1.15;
            font-weight: 600;
        }
        .newsletter-block h4 em {
            color: var(--gold);
            font-style: italic;
            font-weight: 500;
        }
        .newsletter-desc {
            color: #94a0b5;
            font-size: 0.85rem;
            line-height: 1.55;
            margin: 0 0 1rem;
        }
        .input-group-lux {
            display: flex;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid rgba(184, 148, 31, 0.28);
            background: rgba(255, 255, 255, 0.04);
        }
        .input-group-lux input {
            flex: 1;
            border: none;
            background: transparent;
            padding: 0.85rem 1rem;
            color: #fff;
            font-family: 'Inter', sans-serif;
            font-size: 0.88rem;
            outline: none;
        }
        .input-group-lux input::placeholder { color: #6b768a; }
        .input-group-lux button {
            border: none;
            padding: 0 1.1rem;
            background: linear-gradient(135deg, var(--gold), var(--gold-strong));
            color: #fff;
            cursor: pointer;
            transition: filter 0.25s ease;
            font-size: 0.85rem;
        }
        .input-group-lux button:hover { filter: brightness(1.08); }

        /* ---------- MIDDLE: link grid (2 columns now) ---------- */
        .footer-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3rem;
            padding: 3rem 0 2.5rem;
            max-width: 780px;
        }
        @media (max-width: 600px) {
            .footer-grid { grid-template-columns: 1fr; gap: 2rem; max-width: 100%; }
        }

        .footer-col h6 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.15rem;
            font-weight: 600;
            color: #fff;
            margin: 0 0 1rem;
            letter-spacing: 0.02em;
            position: relative;
            padding-bottom: 0.6rem;
        }
        .footer-col h6::after {
            content: '';
            position: absolute;
            left: 0;
            bottom: 0;
            width: 32px;
            height: 2px;
            background: linear-gradient(90deg, var(--gold), var(--gold-strong));
            border-radius: 2px;
            box-shadow: 0 0 8px rgba(184, 148, 31, 0.6);
        }

        .footer-col ul {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .footer-col ul li {
            margin-bottom: 0.55rem;
        }
        .footer-col ul a {
            color: #94a0b5;
            font-size: 0.85rem;
            text-decoration: none;
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .footer-col ul a::before {
            content: '';
            width: 0;
            height: 1px;
            background: var(--gold);
            transition: width 0.25s ease;
            display: inline-block;
        }
        .footer-col ul a:hover {
            color: var(--gold);
            padding-left: 6px;
        }
        .footer-col ul a:hover::before { width: 12px; }

        /* Contact list */
        .contact-list li {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            color: #94a0b5;
            font-size: 0.85rem;
            line-height: 1.5;
            margin-bottom: 0.85rem;
        }
        .contact-list i {
            color: var(--gold);
            width: 16px;
            flex-shrink: 0;
            font-size: 0.9rem;
            margin-top: 3px;
        }
        .contact-list a {
            color: #94a0b5;
            text-decoration: none;
            transition: color 0.25s ease;
        }
        .contact-list a:hover { color: var(--gold); }

        /* ---------- DIVIDER ---------- */
        .footer-divider {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin: 0 auto 1.75rem;
            max-width: 420px;
        }
        .footer-divider .line {
            flex: 1;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(184, 148, 31, 0.55), transparent);
        }
        .footer-divider .diamond {
            width: 6px; height: 6px;
            background: var(--gold);
            transform: rotate(45deg);
            box-shadow: 0 0 10px rgba(184, 148, 31, 0.85);
        }

        /* ---------- BOTTOM ---------- */
        .footer-bottom {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            font-size: 0.78rem;
            color: #7b869a;
        }
        .footer-bottom .copyright { margin: 0; }
        .footer-bottom .copyright strong {
            color: #fff;
            font-weight: 600;
        }

        .legal-links {
            display: flex;
            gap: 1.25rem;
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .legal-links a {
            color: #7b869a;
            text-decoration: none;
            transition: color 0.25s ease;
        }
        .legal-links a:hover { color: var(--gold); }

        @media (max-width: 520px) {
            .footer-inner { padding: 3rem 1rem 1.5rem; }
            .footer-bottom { justify-content: center; text-align: center; }
            .legal-links { justify-content: center; flex-wrap: wrap; }
        }

        @media (prefers-reduced-motion: reduce) {
            .footer-socials a,
            .footer-col ul a,
            .input-group-lux button { transition: none; }
        }
    </style>

    <!-- ============================================================
         SCRIPTS
         ============================================================ -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Cart count (no jQuery dependency)
        function updateCartCount() {
            fetch('../user/get-cart-count.php', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    document.querySelectorAll('.cart-count').forEach(function (el) {
                        el.textContent = data && data.count != null ? data.count : '0';
                    });
                })
                .catch(function () { /* silent */ });
        }
        document.addEventListener('DOMContentLoaded', updateCartCount);

        // Footer newsletter — friendly inline confirmation
        (function () {
            var form = document.getElementById('footerNewsletter');
            if (!form) return;
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var input = form.querySelector('input[type="email"]');
                if (!input || !input.value) return;

                var note = document.createElement('div');
                note.textContent = 'Thank you for subscribing!';
                note.style.cssText =
                    'margin-top:.75rem;font-size:.78rem;color:#b8941f;font-weight:600;letter-spacing:.05em;';
                form.parentNode.appendChild(note);
                input.value = '';
                setTimeout(function () { note.remove(); }, 3500);
            });
        })();
    </script>
</body>
</html>