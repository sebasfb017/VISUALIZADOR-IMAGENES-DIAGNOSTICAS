<?php
$path = 'c:\xampp\htdocs\buscador\remoto1.php';
$content = file_get_contents($path);

// --- 1. CSS Injection ---
$css_insertion = <<<EOD
    /* --- Premium Features CSS --- */
    [data-theme="dark"] {
        --bg-color: #0f172a;
        --card-bg: rgba(30, 41, 59, 1);
        --text-main: #f8fafc;
        --text-muted: #cbd5e1;
        --primary-light: #1e3a8a;
    }
    
    [data-theme="dark"] .bg-shape { opacity: 0.15; }
    [data-theme="dark"] .card { border-color: rgba(255,255,255,0.05); }
    [data-theme="dark"] tbody tr:hover { background: rgba(255,255,255,0.05); }
    [data-theme="dark"] .modality-options { background: rgba(30,41,59,0.95); }
    [data-theme="dark"] .modality-toggle { background: rgba(30,41,59,0.9); border-color: #334155; }
    [data-theme="dark"] .navbar { background-color: rgba(30,41,59,0.8) !important; color: #f8fafc; border: 1px solid rgba(255,255,255,0.1); }
    [data-theme="dark"] .navbar-text { color: #f8fafc !important; }
    [data-theme="dark"] input.form-control, [data-theme="dark"] input[type="text"], [data-theme="dark"] input[type="password"], [data-theme="dark"] input[type="date"] {
        background: rgba(15,23,42,0.9); color: #fff; border-color: #334155;
    }

    #loading-overlay {
        position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
        background: rgba(15, 23, 42, 0.7);
        backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
        z-index: 99999;
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        opacity: 0; pointer-events: none; transition: opacity 0.3s ease;
    }
    #loading-overlay.active { opacity: 1; pointer-events: auto; }
    .spinner-ring {
        width: 60px; height: 60px;
        border: 4px solid rgba(255,255,255,0.2); border-top-color: #3b82f6;
        border-radius: 50%; animation: spin 1s linear infinite; margin-bottom: 20px;
    }
    @keyframes spin { 100% { transform: rotate(360deg); } }
    
    .mod-CT { background: rgba(59, 130, 246, 0.15); color: #2563eb !important; border: 1px solid rgba(59, 130, 246, 0.3); }
    .mod-MR { background: rgba(139, 92, 246, 0.15); color: #7c3aed !important; border: 1px solid rgba(139, 92, 246, 0.3); }
    .mod-CR, .mod-DX { background: rgba(16, 185, 129, 0.15); color: #059669 !important; border: 1px solid rgba(16, 185, 129, 0.3); }
    .mod-US { background: rgba(245, 158, 11, 0.15); color: #d97706 !important; border: 1px solid rgba(245, 158, 11, 0.3); }
    .mod-MG { background: rgba(236, 72, 153, 0.15); color: #db2777 !important; border: 1px solid rgba(236, 72, 153, 0.3); }

    [data-theme="dark"] .mod-CT { color: #93c5fd !important; }
    [data-theme="dark"] .mod-MR { color: #c4b5fd !important; }
    [data-theme="dark"] .mod-CR, [data-theme="dark"] .mod-DX { color: #6ee7b7 !important; }
    [data-theme="dark"] .mod-US { color: #fcd34d !important; }
    [data-theme="dark"] .mod-MG { color: #f9a8d4 !important; }
EOD;

$content = str_replace('</style>', $css_insertion . "\n    </style>", $content);

// --- 2. Remove old static alerts ---
$alert_pat = '/\<\?php if \(\$status === \'error\' \|\| \$status === \'ok\'\):\s*\?\>\s*\<div class="alert.*?\<\/div\>\s*\<\?php endif; \?\>/s';
$content = preg_replace($alert_pat, '', $content, 1);

// --- 3. Replace navbar
$navbar_old = '<a href="?logout=1" class="btn btn-outline-danger btn-sm"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</a>';
$navbar_new = '<div class="d-flex align-items-center"><button type="button" id="theme-toggle" class="btn btn-outline-secondary btn-sm me-2" title="Cambiar tema">🌙</button>' . "\n            " . $navbar_old . '</div>';
$content = str_replace($navbar_old, $navbar_new, $content);

// --- 4. Forms Spinner Event
$content = str_replace('<form method="post">', '<form method="post" onsubmit="showSpinner()">', $content);
$content = str_replace('<form method="get">', '<form method="get" onsubmit="showSpinner()">', $content);

// --- 5. Modality Badges
$badge_old_regex = '/\<\?php if \(\$mods\):\s*\?\>\s*\<span class="modality-badge"\>\s*\<\?php echo htmlspecialchars\(\$mods, ENT_QUOTES\); \?\>\s*\<\/span\>\s*\<\?php else:\s*\?\>\s*\<span class="modality-badge"\>N\/D\<\/span\>\s*\<\?php endif; \?\>/s';
$badge_new = <<<EOD
                                <?php if (\$mods): ?>
                                    <div class="d-flex flex-wrap gap-1">
                                    <?php \$modArray = explode(',', \$mods); ?>
                                    <?php foreach (\$modArray as \$m): \$m = trim(\$m); ?>
                                        <span class="modality-badge mod-<?php echo htmlspecialchars(\$m, ENT_QUOTES); ?>">
                                            <?php echo htmlspecialchars(\$m, ENT_QUOTES); ?>
                                        </span>
                                    <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="modality-badge">N/D</span>
                                <?php endif; ?>
EOD;
$content = preg_replace($badge_old_regex, $badge_new, $content);

// --- 6. Toasts & Script
$html_footer = <<<EOD
    <!-- Premium Features Addons -->
    <div id="loading-overlay">
        <div class="spinner-ring"></div>
        <h3 style="color: #ffffff; font-family: 'Outfit', sans-serif;">Procesando...</h3>
        <p style="color: rgba(255,255,255,0.8); font-family: 'Outfit', sans-serif;">Por favor espera un momento</p>
    </div>

    <div class="toast-container position-fixed top-0 end-0 p-4" style="z-index: 10500;">
        <?php if (isset(\$status) && (\$status === 'error' || \$status === 'ok')): ?>
        <div id="systemToast" class="toast align-items-center text-white bg-<?php echo \$status === 'ok' ? 'success' : 'danger'; ?> border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body" style="font-family: 'Outfit', sans-serif; font-size: 1.05rem;">
                    <?php echo \$status === 'ok' ? '✅' : '⚠️'; ?> <?php echo htmlspecialchars(\$message, ENT_QUOTES); ?>
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Cerrar"></button>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
    // Theme Toggle Logic
    const themeBtn = document.getElementById('theme-toggle');
    const currentTheme = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    if (currentTheme === 'dark') document.documentElement.setAttribute('data-theme', 'dark');

    if (themeBtn) {
        themeBtn.addEventListener('click', () => {
            let theme = document.documentElement.getAttribute('data-theme');
            if (theme === 'dark') {
                document.documentElement.removeAttribute('data-theme');
                localStorage.setItem('theme', 'light');
            } else {
                document.documentElement.setAttribute('data-theme', 'dark');
                localStorage.setItem('theme', 'dark');
            }
        });
    }

    // Spinner Logic
    function showSpinner() {
        document.getElementById('loading-overlay').classList.add('active');
    }

    // Toast Initialization
    document.addEventListener('DOMContentLoaded', () => {
        const toastElList = [].slice.call(document.querySelectorAll('.toast'));
        const toastList = toastElList.map(function(toastEl) {
            return new bootstrap.Toast(toastEl, { delay: 5000 });
        });
        toastList.forEach(toast => toast.show());
    });
    </script>
EOD;

// Insert right before closing body
$content = preg_replace('/(\s*)<\/body>/', "\n" . $html_footer . "$1</body>", $content);

file_put_contents($path, $content);
echo "Premium features successfully added!";
?>
