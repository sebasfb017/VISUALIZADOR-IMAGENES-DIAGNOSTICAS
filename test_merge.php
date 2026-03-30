<?php
$r1 = file_get_contents('c:\xampp\htdocs\buscador\remoto1.php');
$r = file_get_contents('c:\xampp\htdocs\buscador\remoto.php');

$doc_pos1 = strpos($r1, '<!DOCTYPE html>');
$doc_pos = strpos($r, '<!DOCTYPE html>');

if ($doc_pos1 !== false && $doc_pos !== false) {
    $html_r1 = substr($r1, $doc_pos1);
    $php_r = substr($r, 0, $doc_pos);
    
    $new_r = $php_r . $html_r1;
    file_put_contents('c:\xampp\htdocs\buscador\remoto_new.php', $new_r);
    echo "Merge successful. Created remoto_new.php";
} else {
    echo "Failed to find DOCTYPE";
}
?>
