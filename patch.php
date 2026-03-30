<?php
$path = 'c:\xampp\htdocs\buscador\remoto1.php';
$content = file_get_contents($path);

$new_style = <<<EOD
    <!-- Font and Flatpickr -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/airbnb.css">
    
    <style>
    /* New Styles Injected */
    :root {
        --primary: #2563eb;
        --primary-light: #eff6ff;
        --primary-hover: #1d4ed8;
        --success: #10b981;
        --error: #ef4444;
        --bg-color: #f8fafc;
        --card-bg: rgba(255, 255, 255, 0.75);
        --text-main: #0f172a;
        --text-muted: #64748b;
        --border-radius: 20px;
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    body {
        font-family: 'Outfit', system-ui, -apple-system, sans-serif;
        margin: 0;
        padding: 40px 16px;
        background: var(--bg-color);
        background-image: 
            radial-gradient(at 0% 0%, rgba(37, 99, 235, 0.12) 0px, transparent 50%),
            radial-gradient(at 100% 0%, rgba(16, 185, 129, 0.08) 0px, transparent 50%),
            radial-gradient(at 100% 100%, rgba(37, 99, 235, 0.12) 0px, transparent 50%);
        background-attachment: fixed;
        color: var(--text-main);
        min-height: 100vh;
    }

    .layout {
        max-width: 1080px;
        margin: 0 auto;
        position: relative;
        z-index: 1;
    }

    h1 {
        margin-top: 0;
        font-size: 2.2rem;
        font-weight: 700;
        color: var(--text-main);
        letter-spacing: -0.02em;
        display: flex;
        align-items: center;
        gap: 12px;
        text-shadow: 0 2px 10px rgba(0,0,0,0.02);
    }

    .topbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        font-size: 1rem;
    }

    .card {
        background: var(--card-bg);
        backdrop-filter: blur(24px);
        -webkit-backdrop-filter: blur(24px);
        border-radius: var(--border-radius);
        padding: 28px;
        margin-bottom: 30px;
        box-shadow: 0 10px 40px -10px rgba(0, 0, 0, 0.08), inset 0 1px 0 rgba(255,255,255,0.6);
        border: 1px solid rgba(255, 255, 255, 0.8);
        transition: var(--transition);
    }
    
    .card:hover {
        box-shadow: 0 20px 40px -10px rgba(37, 99, 235, 0.12), inset 0 1px 0 rgba(255,255,255,0.8);
        transform: translateY(-2px);
    }

    .card-header {
        display: flex;
        align-items: center;
        gap: 18px;
        margin-bottom: 24px;
        border-bottom: 1px solid rgba(0,0,0,0.04);
        padding-bottom: 20px;
    }

    .card-header-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 52px;
        height: 52px;
        border-radius: 16px;
        background: linear-gradient(135deg, var(--primary-light), #ffffff);
        color: var(--primary);
        font-size: 26px;
        box-shadow: 0 8px 16px rgba(37, 99, 235, 0.12), inset 0 2px 4px rgba(255,255,255,1);
    }

    h2, h3 {
        margin: 0;
        font-size: 1.3rem;
        font-weight: 600;
        color: var(--text-main);
        letter-spacing: -0.01em;
    }

    .status {
        margin-top: 12px;
        margin-bottom: 20px;
        padding: 14px 18px;
        border-radius: 14px;
        font-size: 1rem;
        display: flex;
        align-items: center;
        gap: 12px;
        font-weight: 500;
        animation: slideIn 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        box-shadow: 0 4px 12px rgba(0,0,0,0.03);
    }
    
    @keyframes slideIn {
        from { opacity: 0; transform: translateY(-15px) scale(0.98); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }

    .status-ok, .alert-success {
        background: rgba(16, 185, 129, 0.1);
        color: #065f46;
        border: 1px solid rgba(16, 185, 129, 0.2);
    }

    .status-error, .alert-danger {
        background: rgba(239, 68, 68, 0.1);
        color: #991b1b;
        border: 1px solid rgba(239, 68, 68, 0.2);
    }

    label, .form-label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: var(--text-main);
        font-size: 0.95rem;
    }

    input[type="text"],
    input[type="date"],
    input[type="password"],
    input[type="search"],
    .form-control {
        width: 100%;
        padding: 14px 18px;
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        background: rgba(255,255,255,0.9);
        font-size: 15px;
        font-family: inherit;
        font-weight: 500;
        transition: var(--transition);
        box-shadow: inset 0 2px 4px rgba(0,0,0,0.01);
    }

    input:focus, .form-control:focus {
        outline: none;
        border-color: var(--primary);
        background: #ffffff;
        box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15), inset 0 2px 4px rgba(0,0,0,0.01);
    }

    .actions {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        margin-top: 20px;
        align-items: center;
    }

    .btn {
        display: inline-flex;
        padding: 12px 24px;
        border: none;
        border-radius: 14px;
        text-decoration: none;
        font-family: inherit;
        font-weight: 600;
        font-size: 15px;
        align-items: center;
        justify-content: center;
        gap: 10px;
        cursor: pointer;
        transition: var(--transition);
    }

    .btn-primary {
        background: linear-gradient(135deg, var(--primary), var(--primary-hover));
        color: #ffffff;
        box-shadow: 0 6px 20px rgba(37, 99, 235, 0.25), inset 0 1px 0 rgba(255,255,255,0.2);
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(37, 99, 235, 0.35), inset 0 1px 0 rgba(255,255,255,0.2);
        color: #ffffff;
    }

    .btn-secondary, .btn-outline-secondary {
        background: #ffffff;
        color: var(--text-main);
        border: 1px solid #e2e8f0;
        box-shadow: 0 4px 10px rgba(0,0,0,0.04);
    }

    .btn-secondary:hover, .btn-outline-secondary:hover {
        background: #f8fafc;
        transform: translateY(-2px);
        box-shadow: 0 6px 15px rgba(0,0,0,0.08);
        border-color: #cbd5e1;
        color: var(--text-main);
    }

    .btn-outline-success {
        color: var(--success);
        border: 1px solid var(--success);
        background: transparent;
        box-shadow: 0 4px 10px rgba(16, 185, 129, 0.05);
    }

    .btn-outline-success:hover {
        background: var(--success);
        color: #ffffff;
        box-shadow: 0 6px 15px rgba(16, 185, 129, 0.25);
        transform: translateY(-2px);
    }
    
    .btn-outline-primary {
        color: var(--primary);
        border: 1px solid var(--primary);
        background: transparent;
    }
    
    .btn-outline-primary:hover {
        background: var(--primary);
        color: #ffffff;
        box-shadow: 0 6px 15px rgba(37, 99, 235, 0.25);
        transform: translateY(-2px);
    }
    
    .btn-sm {
        padding: 8px 16px;
        font-size: 14px;
        border-radius: 12px;
    }

    .hint {
        font-size: 0.95rem;
        color: var(--text-muted);
        margin-top: 8px;
        line-height: 1.5;
        font-weight: 400;
    }

    .table-responsive {
        border-radius: 16px;
        background: rgba(255,255,255,0.5);
        padding: 4px;
        border: 1px solid rgba(226,232,240,0.6);
    }

    table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 8px;
        margin-top: 4px;
        font-size: 14px;
    }

    th, td {
        padding: 16px 20px;
        text-align: left;
    }

    th {
        font-weight: 600;
        color: var(--text-muted);
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        padding-top: 10px;
        padding-bottom: 4px;
    }
    
    tbody tr {
        transition: var(--transition);
        background: #ffffff;
        box-shadow: 0 2px 6px rgba(0,0,0,0.02);
    }

    tbody tr:hover {
        transform: scale(1.005) translateY(-2px);
        box-shadow: 0 8px 20px rgba(37, 99, 235, 0.08);
        z-index: 10;
        position: relative;
    }
    
    tbody td:first-child { border-top-left-radius: 16px; border-bottom-left-radius: 16px; border-left: 2px solid transparent; }
    tbody td:last-child { border-top-right-radius: 16px; border-bottom-right-radius: 16px; }
    
    tbody tr:hover td:first-child { border-left-color: var(--primary); }

    .modality-badge {
        display: inline-flex;
        align-items: center;
        padding: 6px 12px;
        border-radius: 20px;
        background: var(--primary-light);
        color: var(--primary-hover);
        font-size: 13px;
        font-weight: 700;
        letter-spacing: 0.03em;
        box-shadow: inset 0 0 0 1px rgba(37, 99, 235, 0.1);
    }

    .login-error {
        margin-top: 16px;
        padding: 14px 18px;
        border-radius: 14px;
        background: rgba(239, 68, 68, 0.1);
        color: #991b1b;
        font-size: 0.95rem;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .modality-toggle {
        text-align: left;
        cursor: pointer;
        padding: 14px 18px;
        border-radius: 14px;
        background: rgba(255, 255, 255, 0.9);
        border: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-weight: 500;
        transition: var(--transition);
    }

    .modality-toggle:hover {
        background: #ffffff;
        border-color: #cbd5e1;
        box-shadow: 0 4px 12px rgba(0,0,0,0.04);
    }

    .modality-toggle:focus {
        box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15);
        border-color: var(--primary);
    }

    .modality-options {
        background: rgba(255,255,255,0.95);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        border: 1px solid rgba(226, 232, 240, 0.8);
        border-radius: 20px;
        max-height: 320px;
        overflow-y: auto;
        box-shadow: 0 20px 40px -10px rgba(0,0,0,0.15);
        padding: 16px;
        margin-top: 10px;
    }

    @media (max-width: 768px) {
        body { padding: 20px 10px; }
        .card { padding: 20px; margin-bottom: 20px; }
        .table-responsive { background: transparent; border: none; padding: 0; }
        .table-responsive table thead { display: none; }
        .table-responsive tbody tr { display: flex; flex-direction: column; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; margin-bottom: 16px; padding: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); }
        .table-responsive tbody td { display: flex; justify-content: space-between; align-items: center; padding: 10px 4px; border-bottom: 1px solid #f1f5f9; border-radius: 0 !important; }
        .table-responsive tbody td:last-child { border-bottom: none; flex-direction: column; align-items: stretch; gap: 10px; margin-top: 10px;}
        .table-responsive tbody td[data-label]:before { content: attr(data-label); font-weight: 600; color: var(--text-muted); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; }
    }

    .flatpickr-calendar {
        font-family: 'Outfit', sans-serif;
        border-radius: 20px;
        box-shadow: 0 20px 40px -10px rgba(0,0,0,0.15);
        border: 1px solid rgba(226,232,240,0.8);
        padding: 5px;
    }
    
    .bg-shape {
        position: fixed;
        border-radius: 50%;
        filter: blur(80px);
        z-index: 0;
        opacity: 0.5;
        pointer-events: none;
    }
    .shape1 { top: -10%; left: -10%; width: 40vw; height: 40vw; background: rgba(37, 99, 235, 0.15); }
    .shape2 { bottom: -10%; right: -10%; width: 50vw; height: 50vw; background: rgba(16, 185, 129, 0.1); }
    
    .logo-container {
        position: absolute;
        top: 0px; 
        left: -200px;
        z-index: 1000;
        transition: var(--transition);
        filter: drop-shadow(0 10px 20px rgba(0,0,0,0.05));
    }
    
    .logo-container img {
        width: 260px;
        height: auto;
    }
    
    @media (max-width: 1400px) {
        .logo-container {
            position: relative;
            left: 0;
            top: 0;
            margin-bottom: 24px;
            text-align: left;
        }
    }
    </style>
EOD;

$pattern = '/<!-- Flatpickr CSS \(theme\) -->.*?\<\/style>\s*\<\/head>\s*\<body\>\s*\<div[^>]*>.*?\<\/div>\s*\<link rel="stylesheet".*?>\s*\<div class="layout"\>/s';

$replacement = $new_style . "\n</head>\n<body>\n    <div class=\"bg-shape shape1\"></div>\n    <div class=\"bg-shape shape2\"></div>\n    <div class=\"layout\">\n        <div class=\"logo-container\">\n            <img src=\"/buscador/logo.png\" alt=\"Logo\">\n        </div>\n        <link rel=\"stylesheet\" href=\"/buscador/assets/css/remoto1.css\">\n";

if (preg_match($pattern, $content)) {
    $content = preg_replace($pattern, $replacement, $content);
} else {
    echo "Pattern not fully matched. Attempting partial replacements.\n";
    $style_pat = '/\<style\>.*?\<\/style\>/s';
    $content = preg_replace($style_pat, substr($new_style, strpos($new_style, '<style>')), $content);
    
    $body_pat = '/\<body\>/';
    $content = preg_replace($body_pat, "<body>\n    <div class=\"bg-shape shape1\"></div>\n    <div class=\"bg-shape shape2\"></div>", $content, 1);
    
    $logo_pat = '/\<div style="position: fixed; top: 10px; left: 10px; z-index: 1000;"\>\s*\<img src="\/buscador\/logo\.png" alt="Logo" width="300" height="100"\>\s*\<\/div\>/s';
    $content = preg_replace($logo_pat, '<div class="logo-container"><img src="/buscador/logo.png" alt="Logo"></div>', $content);
}

file_put_contents($path, $content);
echo "Patch applied!\n";
?>
