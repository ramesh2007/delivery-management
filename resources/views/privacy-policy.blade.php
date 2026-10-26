<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy - Halamama WMS</title>
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

        h4.subsection-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--brand-primary);
            margin: 1.5rem 0 0.65rem 0;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        p {
            color: var(--text-muted);
            margin-bottom: 1rem;
            font-size: 0.975rem;
        }

        /* Lists */
        ul {
            list-style: none;
            padding-left: 0;
            margin: 1rem 0 1.5rem 0;
        }

        ul li {
            position: relative;
            padding-left: 1.85rem;
            margin-bottom: 0.75rem;
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

        ul li strong, p strong {
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
                <a href="{{ route('privacy.policy') }}" class="nav-tab active">Privacy Policy</a>
                <a href="{{ route('terms.conditions') }}" class="nav-tab">Terms &amp; Conditions</a>
            </nav>
        </div>
    </header>

    <!-- Main Container -->
    <main class="page-container">
        <!-- Hero Header -->
        <header class="hero-banner">
            <div class="pill-tag">
                <span class="pill-dot"></span>
                Legal &amp; Privacy Compliance
            </div>
            <h1 class="hero-title">Privacy Policy for Halamama WMS</h1>
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
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                    </svg>
                    <span>Enterprise Data Protection Standards</span>
                </div>
            </div>
        </header>

        <!-- Document Card -->
        <article class="doc-card">
            <p class="lead-paragraph">
                Welcome to <strong>Halamama WMS</strong> (&ldquo;we,&rdquo; &ldquo;our,&rdquo; or &ldquo;us&rdquo;). We are committed to protecting your privacy and ensuring the security of your operational data. This Privacy Policy describes how we collect, use, disclose, and safeguard information when you use our mobile application <strong>Halamama WMS</strong> (the &ldquo;Application&rdquo; or &ldquo;App&rdquo;).
            </p>

            <div class="callout-box">
                Please read this Privacy Policy carefully. By accessing or using the Application, you agree to the collection and use of information in accordance with this policy.
            </div>

            <div class="divider"></div>

            <!-- 1. Information We Collect -->
            <div class="section-header">
                <span class="section-num">1</span>
                <h3 class="section-title">Information We Collect</h3>
            </div>
            <p>We collect information necessary to deliver warehouse inventory management, product scanning, and order fulfillment services:</p>

            <h4 class="subsection-title">A. Information You Provide</h4>
            <ul>
                <li><strong>Account &amp; Identity Data:</strong> When you log into the Application, we may collect your employee ID, username, email address, password/credentials, assigned roles, and warehouse location.</li>
                <li><strong>Operational Data:</strong> Logs of items scanned, quantities entered, order discrepancy notes, delivery verifications, and status updates generated during warehouse tasks.</li>
            </ul>

            <h4 class="subsection-title">B. Device Permissions &amp; Captured Data</h4>
            <ul>
                <li><strong>Camera Access:</strong> We access your device's camera exclusively to scan barcodes and QR codes for item verification, order picking, inventory counts, and to capture images of physical packages or damaged goods.</li>
                <li><strong>Photo Library / Storage Access:</strong> We may request access to your device's photo storage solely to allow you to select, attach, and upload photos documenting order discrepancies, physical package condition, or proof of delivery.</li>
                <li><strong>Device &amp; Network Data:</strong> We collect device identifiers, operating system version, app version, network connectivity status, and basic diagnostics (crash logs and performance metrics) to ensure application stability and security.</li>
            </ul>

            <div class="divider"></div>

            <!-- 2. How We Use Your Information -->
            <div class="section-header">
                <span class="section-num">2</span>
                <h3 class="section-title">How We Use Your Information</h3>
            </div>
            <p>The information collected by Halamama WMS is used strictly for internal business and warehouse operational purposes, including:</p>
            <ul>
                <li>Authenticating user accounts and enforcing role-based access permissions.</li>
                <li>Facilitating real-time barcode scanning, item verification, and inventory tracking.</li>
                <li>Recording order picking, packing, stock audits, and discrepancy resolutions.</li>
                <li>Syncing operational warehouse data with central backend inventory servers.</li>
                <li>Troubleshooting bugs, monitoring app stability, and improving warehouse workflow performance.</li>
                <li>Maintaining an audit trail of warehouse activity for quality control and accountability.</li>
            </ul>

            <div class="divider"></div>

            <!-- 3. Data Sharing and Disclosure -->
            <div class="section-header">
                <span class="section-num">3</span>
                <h3 class="section-title">Data Sharing and Disclosure</h3>
            </div>
            <p>We do not sell, rent, or trade your personal or operational data to third parties. We may disclose information only under the following circumstances:</p>
            <ul>
                <li><strong>With Your Organization:</strong> As an enterprise warehouse management tool, activity logs, scans, and task completion records are shared with your organization's administrative and inventory management teams.</li>
                <li><strong>Service Providers:</strong> We may share data with trusted third-party service providers who assist us in operating our services (e.g., cloud hosting, database management, and error monitoring), provided they adhere to confidentiality and security obligations.</li>
                <li><strong>Legal Compliance:</strong> When required by applicable law, court order, or governmental authority, or to protect the safety, rights, and property of Halamama and its users.</li>
            </ul>

            <div class="divider"></div>

            <!-- 4. Data Storage and Security -->
            <div class="section-header">
                <span class="section-num">4</span>
                <h3 class="section-title">Data Storage and Security</h3>
            </div>
            <p>We implement appropriate administrative, technical, and physical security measures to protect your data against unauthorized access, loss, misuse, or alteration:</p>
            <ul>
                <li>All communications between the Application and our backend servers are encrypted using Secure Sockets Layer (SSL / TLS) protocols.</li>
                <li>Authentication tokens and sensitive session data are stored securely on your device.</li>
                <li>While we strive to use commercially acceptable means to protect your data, no method of transmission over the internet or mobile network is 100% secure.</li>
            </ul>

            <div class="divider"></div>

            <!-- 5. Data Retention -->
            <div class="section-header">
                <span class="section-num">5</span>
                <h3 class="section-title">Data Retention</h3>
            </div>
            <p>We retain collected operational data, inventory logs, and discrepancy reports for as long as necessary to fulfill business objectives, support audit requirements, and comply with legal obligations. Session tokens and local caches are cleared upon logout or app reinstallation.</p>

            <div class="divider"></div>

            <!-- 6. Children's Privacy -->
            <div class="section-header">
                <span class="section-num">6</span>
                <h3 class="section-title">Children's Privacy</h3>
            </div>
            <p>The Application is designed exclusively for enterprise warehouse management and professional operations. It is not directed to anyone under the age of 18 (&ldquo;Children&rdquo;). We do not knowingly collect personal information from children.</p>

            <div class="divider"></div>

            <!-- 7. Changes to This Privacy Policy -->
            <div class="section-header">
                <span class="section-num">7</span>
                <h3 class="section-title">Changes to This Privacy Policy</h3>
            </div>
            <p>We may update this Privacy Policy from time to time. Any changes will be posted within the Application or on our website with an updated &ldquo;Last Updated&rdquo; date. Continued use of the Application after changes are posted constitutes acceptance of the revised policy.</p>

            <div class="divider"></div>

            <!-- 8. Contact Us -->
            <div class="section-header">
                <span class="section-num">8</span>
                <h3 class="section-title">Contact Us</h3>
            </div>
            <p>If you have questions, feedback, or concerns regarding this Privacy Policy, please contact us:</p>

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
