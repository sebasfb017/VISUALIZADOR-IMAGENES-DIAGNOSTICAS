<?php
$f1 = file('c:\xampp\htdocs\buscador\remoto.php', FILE_IGNORE_NEW_LINES);
$f2 = file('c:\xampp\htdocs\buscador\remoto1.php', FILE_IGNORE_NEW_LINES);

$diffs = 0;
for ($i=0; $i<min(count($f1), count($f2)); $i++) {
    if(rtrim($f1[$i]) !== rtrim($f2[$i])) {
        echo "Diff at L" . ($i+1) . " | \n";
        $diffs++;
        if ($diffs > 10) { echo "Too many diffs, stopping.\n"; break; }
    }
}
?>
