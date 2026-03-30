<?php
$path = 'c:\xampp\htdocs\buscador\remoto1.php';
$content = file_get_contents($path);

$content = preg_replace("/\.logo-container \{\s*position: absolute;\s*top: 0px; \s*left: -200px;\s*z-index: 1000;\s*transition: var\(--transition\);\s*filter: drop-shadow\(0 10px 20px rgba\(0,0,0,0\.05\)\);\s*\}\s*\.logo-container img \{\s*width: 260px;\s*height: auto;\s*\}\s*@media \(max-width: 1400px\) \{\s*\.logo-container \{\s*position: relative;\s*left: 0;\s*top: 0;\s*margin-bottom: 24px;\s*text-align: left;\s*\}\s*\}/s",
<<<EOD
    .logo-container {
        display: block;
        margin: 0 0 30px 0;
        text-align: left;
    }
    .logo-container img {
        width: 260px;
        height: auto;
    }
EOD, $content);

file_put_contents($path, $content);
echo "Logo fixed";
?>
