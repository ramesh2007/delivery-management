<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms and Conditions - Halamama WMS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand-primary: #3d7977;
            --brand-dark: #23504e;
            --brand-accent: #85AFAE;
            --brand-soft: #f2f7f7;
            --brand-border: #d4e3e2;
            --text-main: #1e293b;
            --text-muted: #475569;
            --text-subtle: #64748b;
            --bg-page: #f8fafb;
            --bg-card: #ffffff;
            --radius-lg: 16px;
            --radius-md: 10px;
            --radius-sm: 6px;
            --shadow-subtle: 0 4px 20px -2px rgba(35, 80, 78, 0.06), 0 2px 6px -1px rgba(0, 0, 0, 0.04);
            --shadow-card: 0 10px 30px -5px rgba(35, 80, 78, 0.07), 0 1px 3px rgba(0, 0, 0, 0.04);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--bg-page);
            color: var(--text-main);
            line-height: 1.75;
            -webkit-font-smoothing: antialiased;
        }

        /* Top Navigation Bar */
        .navbar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-bottom: 1px solid var(--brand-border);
            transition: all 0.2s ease;
        }

        .nav-inner {
            max-width: 1080px;
            margin: 0 auto;
            padding: 0.85rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .brand-link {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            text-decoration: none;
        }

        .brand-logo {
            height: 38px;
            width: auto;
            display: block;
            object-fit: contain;
        }

        .badge-wms {
            background: linear-gradient(135deg, var(--brand-primary), var(--brand-accent));
            color: #ffffff;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.06em;
            padding: 0.2rem 0.55rem;
            border-radius: var(--radius-sm);
            text-transform: uppercase;
            box-shadow: 0 2px 6px rgba(61, 121, 119, 0.25);
        }

        .nav-tabs {
            display: flex;
            align-items: center;
            background: #eef5f5;
            padding: 0.25rem;
            border-radius: 10px;
            border: 1px solid var(--brand-border);
            gap: 0.25rem;
        }

        .nav-tab {
            text-decoration: none;
            padding: 0.45rem 1rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-muted);
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .nav-tab:hover {
            color: var(--brand-primary);
        }

        .nav-tab.active {
            background: #ffffff;
            color: var(--brand-dark);
            box-shadow: 0 2px 6px rgba(35, 80, 78, 0.1);
        }

        /* Container */
        .page-container {
            max-width: 1080px;
            margin: 2.5rem auto 4rem auto;
            padding: 0 1.5rem;
        }

        /* Hero Banner */
        .hero-banner {
            background: linear-gradient(135deg, #ffffff 0%, #f0f7f7 100%);
            border: 1px solid var(--brand-border);
            border-radius: var(--radius-lg);
            padding: 2.75rem 2.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-subtle);
            position: relative;
            overflow: hidden;
        }

        .hero-banner::after {
            content: "";
            position: absolute;
            top: -60px;
            right: -60px;
            width: 220px;
            height: 220px;
            background: radial-gradient(circle, rgba(133, 175, 174, 0.25) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .pill-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background: rgba(133, 175, 174, 0.18);
            color: var(--brand-dark);
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            padding: 0.3rem 0.8rem;
            border-radius: 9999px;
            margin-bottom: 1rem;
            border: 1px solid rgba(133, 175, 174, 0.35);
        }

        .pill-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background-color: var(--brand-primary);
        }

        .hero-title {
            font-size: 2.25rem;
            font-weight: 800;
            line-height: 1.25;
            letter-spacing: -0.03em;
            color: var(--brand-dark);
            margin-bottom: 1rem;
        }

        .hero-meta {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 1.5rem;
            color: var(--text-subtle);
            font-size: 0.875rem;
            font-weight: 500;
        }

        .hero-meta-item {
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .hero-meta-item svg {
            color: var(--brand-accent);
        }

        /* Document Card */
        .doc-card {
            background: var(--bg-card);
            border: 1px solid #e5eceb;
            border-radius: var(--radius-lg);
            padding: 3rem 3rem;
            box-shadow: var(--shadow-card);
        }

        .lead-paragraph {
            font-size: 1.05rem;
            color: var(--text-main);
            margin-bottom: 1.5rem;
            line-height: 1.85;
        }

        /* Callout Box */
        .callout-box {
            background: linear-gradient(135deg, var(--brand-soft) 0%, #edf5f5 100%);
            border-left: 4px solid var(--brand-accent);
            border-radius: 0 var(--radius-md) var(--radius-md) 0;
            padding: 1.25rem 1.5rem;
            margin: 1.75rem 0 2.25rem 0;
            color: var(--brand-dark);
            font-size: 0.96rem;
            line-height: 1.7;
            box-shadow: 0 2px 8px rgba(35, 80, 78, 0.04);
        }

        .callout-box strong {
            color: var(--brand-dark);
        }

        /* Section Dividers */
        .divider {
            height: 1px;
            background: linear-gradient(to right, #e2e8f0, #eef5f5, #e2e8f0);
            margin: 2.75rem 0;
        }

        /* Headings */
        .section-header {
            display: flex;
            align-items: baseline;
            gap: 0.75rem;
            margin: 2rem 0 1rem 0;
        }

        .section-num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(133, 175, 174, 0.2);
            color: var(--brand-dark);
            font-weight: 800;
            font-size: 0.85rem;
            width: 28px;
            height: 28px;
            border-radius: 8px;
            flex-shrink: 0;
        }

        h3.section-title {
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--brand-dark);
            letter-spacing: -0.02em;
        }

        p {
            color: var(--text-muted);
            margin-bottom: 1rem;
            font-size: 0.975rem;
        }

        ol {
            padding-left: 0;
            list-style: none;
            counter-reset: term-counter;
            margin: 1rem 0 1.5rem 0;
        }

        ol > li {
            counter-increment: term-counter;
            position: relative;
            padding-left: 2rem;
            margin-bottom: 1rem;
            color: var(--text-muted);
            font-size: 0.95rem;
            line-height: 1.75;
        }

        ol > li::before {
            content: counter(term-counter) ".";
            position: absolute;
            left: 0.25rem;
            top: 0;
            font-weight: 700;
            color: var(--brand-primary);
        }

        ul {
            list-style: none;
            padding-left: 0;
            margin: 0.75rem 0 1rem 0;
        }

        ul li {
            position: relative;
            padding-left: 1.85rem;
            margin-bottom: 0.65rem;
            color: var(--text-muted);
            font-size: 0.95rem;
            line-height: 1.7;
        }

        ul li::before {
            content: "";
            position: absolute;
            left: 0.35rem;
            top: 0.65rem;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background-color: var(--brand-accent);
            box-shadow: 0 0 0 3px rgba(133, 175, 174, 0.25);
        }

        ul li strong, ol li strong, p strong {
            color: var(--text-main);
            font-weight: 600;
        }

        /* Contact Details Card */
        .contact-card {
            background: linear-gradient(135deg, #ffffff 0%, var(--brand-soft) 100%);
            border: 1px solid var(--brand-border);
            border-radius: var(--radius-md);
            padding: 1.75rem;
            margin-top: 1.25rem;
            box-shadow: 0 2px 10px rgba(35, 80, 78, 0.04);
        }

        .contact-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.25rem;
            margin-top: 0.5rem;
        }

        .contact-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .contact-label {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-subtle);
            font-weight: 700;
        }

        .contact-value {
            font-size: 1rem;
            font-weight: 600;
            color: var(--brand-dark);
            text-decoration: none;
        }

        .contact-value a {
            color: var(--brand-primary);
            text-decoration: none;
            transition: color 0.15s ease;
        }

        .contact-value a:hover {
            color: var(--brand-dark);
            text-decoration: underline;
        }

        /* Footer */
        .footer {
            border-top: 1px solid var(--brand-border);
            background: #ffffff;
            padding: 2.5rem 1.5rem;
            text-align: center;
            color: var(--text-subtle);
            font-size: 0.875rem;
        }

        .footer-brand {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }

        .footer-logo {
            height: 24px;
            width: auto;
            opacity: 0.85;
        }

        .footer-links {
            margin-top: 0.5rem;
        }

        .footer-links a {
            color: var(--brand-primary);
            text-decoration: none;
            margin: 0 0.6rem;
            font-weight: 600;
            transition: color 0.15s ease;
        }

        .footer-links a:hover {
            color: var(--brand-dark);
            text-decoration: underline;
        }

        /* Responsive Breakpoints */
        @media (max-width: 768px) {
            .nav-inner {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
            }
            .nav-tabs {
                width: 100%;
                justify-content: stretch;
            }
            .nav-tab {
                flex: 1;
                text-align: center;
            }
            .hero-banner {
                padding: 1.75rem 1.5rem;
            }
            .hero-title {
                font-size: 1.75rem;
            }
            .doc-card {
                padding: 2rem 1.5rem;
            }
        }
    </style>
</head>
<body>

    <!-- Top Sticky Header -->
    <header class="navbar">
        <div class="nav-inner">
            <a href="/" class="brand-link" title="Halamama Home">
                <img src="{{ asset('halamama_green.svg') }}" alt="Halamama Logo" class="brand-logo" onerror="this.onerror=null; this.src='/halamama_green.svg';">
                <span class="badge-wms">WMS</span>
            </a>
            <nav class="nav-tabs" aria-label="Legal document tabs">
                <a href="/privacy-policy" class="nav-tab">Privacy Policy</a>
                <a href="/terms-conditions" class="nav-tab active">Terms &amp; Conditions</a>
            </nav>
        </div>
    </header>

    <!-- Main Container -->
    <main class="page-container">
        <!-- Hero Header -->
        <header class="hero-banner">
            <div class="pill-tag">
                <span class="pill-dot"></span>
                Terms of Service &amp; Agreement
            </div>
            <h1 class="hero-title">Terms and Conditions for Halamama WMS</h1>
            <div class="hero-meta">
                <div class="hero-meta-item">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                    <span><strong>Last Updated:</strong> October 25, 2026</span>
                </div>
                <div class="hero-meta-item">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                        <polyline points="10 9 9 9 8 9"></polyline>
                    </svg>
                    <span>Enterprise Terms of Service</span>
                </div>
            </div>
        </header>

        <!-- Document Card -->
        <article class="doc-card">
            <p class="lead-paragraph">
                Please read these Terms and Conditions (&ldquo;Terms,&rdquo; &ldquo;Terms of Service&rdquo;) carefully before using the <strong>Halamama WMS</strong> mobile application (the &ldquo;Application&rdquo; or &ldquo;Service&rdquo;) operated by <strong>Halamama</strong> (&ldquo;Company,&rdquo; &ldquo;we,&rdquo; &ldquo;us,&rdquo; or &ldquo;our&rdquo;).
            </p>

            <div class="callout-box">
                By downloading, accessing, or using the Application, you agree to be bound by these Terms. If you disagree with any part of the Terms, you may not access or use the Application.
            </div>

            <div class="divider"></div>

            <!-- 1. Authorized Use and License -->
            <div class="section-header">
                <span class="section-num">1</span>
                <h3 class="section-title">Authorized Use and License</h3>
            </div>
            <ol>
                <li><strong>Enterprise Purpose:</strong> Halamama WMS is an enterprise tool intended for authorized employees, contractors, and warehouse staff to conduct inventory management, barcode scanning, item verification, and order processing.</li>
                <li><strong>Grant of License:</strong> We grant you a revocable, non-exclusive, non-transferable, limited license to download, install, and use the Application strictly for its intended business operations and in accordance with these Terms.</li>
                <li>
                    <strong>Restrictions:</strong> You agree not to:
                    <ul>
                        <li>Decompile, reverse engineer, disassemble, or attempt to derive the source code of the Application.</li>
                        <li>Modify, adapt, translate, or create derivative works from the Application.</li>
                        <li>Use the Application for any unauthorized, fraudulent, or illegal activity.</li>
                        <li>Interfere with or disrupt the integrity or performance of the servers, network, or data connected to the Application.</li>
                    </ul>
                </li>
            </ol>

            <div class="divider"></div>

            <!-- 2. User Accounts and Security -->
            <div class="section-header">
                <span class="section-num">2</span>
                <h3 class="section-title">User Accounts and Security</h3>
            </div>
            <ol>
                <li><strong>Credentials:</strong> You are responsible for safeguarding your login credentials (username, password, or authentication token) and for all activities that occur under your account.</li>
                <li><strong>Unauthorized Access:</strong> You must immediately notify your system administrator or Halamama support if you suspect any unauthorized access to your account or any other breach of security.</li>
                <li><strong>Role-Based Permissions:</strong> Access to specific warehouse features (e.g., inventory audits, stock adjustments, order packing) is governed by assigned user roles. You agree not to circumvent access restrictions.</li>
            </ol>

            <div class="divider"></div>

            <!-- 3. Equipment and Permissions -->
            <div class="section-header">
                <span class="section-num">3</span>
                <h3 class="section-title">Equipment and Permissions</h3>
            </div>
            <ol>
                <li><strong>Hardware &amp; Connectivity:</strong> You are responsible for providing a compatible mobile device and maintaining adequate internet connectivity (Wi-Fi or cellular) necessary to operate the Application.</li>
                <li><strong>Camera &amp; Storage Permissions:</strong> The Application requires access to your device camera (for barcode and QR scanning) and local storage (for discrepancy photo uploads). Refusing these permissions may prevent core warehouse workflows from functioning.</li>
            </ol>

            <div class="divider"></div>

            <!-- 4. Intellectual Property -->
            <div class="section-header">
                <span class="section-num">4</span>
                <h3 class="section-title">Intellectual Property</h3>
            </div>
            <p>
                The Application, including its original content, features, interfaces, designs, software, documentation, and logos, is the exclusive property of Halamama and its licensors, protected by copyright, trademark, and intellectual property laws.
            </p>

            <div class="divider"></div>

            <!-- 5. Termination -->
            <div class="section-header">
                <span class="section-num">5</span>
                <h3 class="section-title">Termination</h3>
            </div>
            <p>
                We may terminate or suspend your access to the Application immediately, without prior notice or liability, for any reason, including without limitation:
            </p>
            <ul>
                <li>Breach of these Terms.</li>
                <li>Termination of your employment or contractual relationship with the authorized operating organization.</li>
                <li>Decommissioning or sunsetting of the Application.</li>
            </ul>
            <p>
                Upon termination, your right to use the Application will cease immediately, and you must uninstall and delete all copies of the Application from your devices.
            </p>

            <div class="divider"></div>

            <!-- 6. Disclaimer of Warranties -->
            <div class="section-header">
                <span class="section-num">6</span>
                <h3 class="section-title">Disclaimer of Warranties</h3>
            </div>
            <p>
                The Application is provided on an <strong>&ldquo;AS IS&rdquo;</strong> and <strong>&ldquo;AS AVAILABLE&rdquo;</strong> basis without warranties of any kind, whether express or implied, including, but not limited to, implied warranties of merchantability, fitness for a particular purpose, non-infringement, or uninterrupted scanning operation. We do not warrant that:
            </p>
            <ul>
                <li>The Application will be uninterrupted, error-free, or fully compatible with every hardware device.</li>
                <li>Scan results or inventory quantities will always be instantly synchronized during intermittent network outages.</li>
            </ul>

            <div class="divider"></div>

            <!-- 7. Limitation of Liability -->
            <div class="section-header">
                <span class="section-num">7</span>
                <h3 class="section-title">Limitation of Liability</h3>
            </div>
            <p>
                To the fullest extent permitted by applicable law, in no event shall Halamama, its affiliates, directors, employees, or agents be liable for any indirect, incidental, special, consequential, or punitive damages (including loss of profits, data, inventory discrepancies, or business interruption) arising out of or in connection with your use or inability to use the Application.
            </p>

            <div class="divider"></div>

            <!-- 8. Governing Law -->
            <div class="section-header">
                <span class="section-num">8</span>
                <h3 class="section-title">Governing Law</h3>
            </div>
            <p>
                These Terms shall be governed and construed in accordance with the laws of [United Arab Emirates / Kingdom of Saudi Arabia / Your Jurisdiction], without regard to its conflict of law provisions.
            </p>

            <div class="divider"></div>

            <!-- 9. Changes to Terms -->
            <div class="section-header">
                <span class="section-num">9</span>
                <h3 class="section-title">Changes to Terms</h3>
            </div>
            <p>
                We reserve the right to modify or replace these Terms at any time at our sole discretion. We will indicate the date of the latest update at the top of this document. Continued use of the Application following the posting of changes constitutes acceptance of those changes.
            </p>

            <div class="divider"></div>

            <!-- 10. Contact Us -->
            <div class="section-header">
                <span class="section-num">10</span>
                <h3 class="section-title">Contact Us</h3>
            </div>
            <p>If you have any questions about these Terms, please contact:</p>

            <div class="contact-card">
                <div class="contact-grid">
                    <div class="contact-item">
                        <span class="contact-label">Entity</span>
                        <span class="contact-value">Halamama</span>
                    </div>
                    <div class="contact-item">
                        <span class="contact-label">Email Support</span>
                        <span class="contact-value"><a href="mailto:support@halamama.com">support@halamama.com</a></span>
                    </div>
                    <div class="contact-item">
                        <span class="contact-label">Official Website</span>
                        <span class="contact-value"><a href="https://halamama.com" target="_blank" rel="noopener noreferrer">https://halamama.com</a></span>
                    </div>
                </div>
            </div>
        </article>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-brand">
            <img src="{{ asset('halamama_green.svg') }}" alt="Halamama Logo" class="footer-logo" onerror="this.onerror=null; this.src='/halamama_green.svg';">
        </div>
        <p>&copy; {{ date('Y') }} Halamama WMS. All rights reserved.</p>
        <div class="footer-links">
            <a href="/privacy-policy">Privacy Policy</a> &bull; 
            <a href="/terms-conditions">Terms and Conditions</a>
        </div>
    </footer>

</body>
</html>
