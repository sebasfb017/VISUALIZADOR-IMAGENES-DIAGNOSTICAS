<?php
$path = 'c:\xampp\htdocs\buscador\remoto1.php';
$content = file_get_contents($path);

$pattern = "/echo '.*?<!-- Premium Features Addons -->.*?<\/script><\/body><\/html>';/s";
$content = preg_replace($pattern, "echo '</body></html>';", $content);

file_put_contents($path, $content);
echo "Syntax fixed!";
?>
