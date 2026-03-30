<?php
$path = 'c:\xampp\htdocs\buscador\remoto1.php';
$content = file_get_contents($path);

// Update CSS
$css_pat = '/\.logo-container\s*\{\s*display:\s*block;\s*margin:\s*0\s+0\s+30px\s+0;\s*text-align:\s*left;\s*\}\s*\.logo-container img\s*\{\s*width:\s*260px;\s*height:\s*auto;\s*\}/';
$css_new = <<<EOD
    .page-container {
        display: flex;
        align-items: flex-start;
        max-width: 1400px;
        margin: 0 auto;
        padding: 0 10px;
        gap: 30px;
    }
    .logo-container {
        flex: 0 0 260px;
        margin: 0;
        position: sticky;
        top: 20px;
        text-align: center;
    }
    .logo-container img {
        width: 100%;
        max-width: 260px;
        height: auto;
    }
    @media (max-width: 1100px) {
        .page-container {
            flex-direction: column;
            align-items: center;
        }
        .logo-container {
            position: relative;
            top: 0;
            margin-bottom: 20px;
        }
    }
EOD;

$content = preg_replace($css_pat, $css_new, $content);

// Update HTML
$html_pat = '/\<div class="layout"\>\s*\<div class="logo-container"\>\s*\<img src="\/buscador\/logo\.png" alt="Logo"\>\s*\<\/div\>/';
$html_new = <<<EOD
    <div class="page-container">
        <div class="logo-container">
            <img src="/buscador/logo.png" alt="Logo">
        </div>
        <div class="layout" style="margin: 0; width: 100%;">
EOD;

$content = preg_replace($html_pat, $html_new, $content);

// Add closing div
if (strpos($content, '<!-- page-container -->') === false) {
    $content = preg_replace('/(\s*)<\/body>/', "$1</div>$1</body>", $content);
}

file_put_contents($path, $content);
echo "Layout Flexbox Applied!";
?>
