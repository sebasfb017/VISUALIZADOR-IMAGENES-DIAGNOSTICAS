<?php
session_start();
$results = $_SESSION['last_search_results'] ?? [];
$out = [];
foreach ($results as $s) {
    $out[] = [
        'local' => $s['_local'] ?? false,
        'UID' => $s['MainDicomTags']['StudyInstanceUID'] ?? null,
        'Date' => $s['MainDicomTags']['StudyDate'] ?? null,
        'Time' => $s['MainDicomTags']['StudyTime'] ?? null,
        'Desc' => $s['MainDicomTags']['StudyDescription'] ?? null
    ];
}
file_put_contents('debug_session.json', json_encode($out, JSON_PRETTY_PRINT));
echo "Done.";
