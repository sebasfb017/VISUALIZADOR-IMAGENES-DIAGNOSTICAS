<?php
header('Content-Type: text/plain; charset=utf-8');
$out = [];
$out[] = 'PHP SAPI: ' . php_sapi_name();
$out[] = 'PHP version: ' . PHP_VERSION;
$tmp = sys_get_temp_dir();
$out[] = 'sys_get_temp_dir(): ' . $tmp;
$out[] = 'is_dir(tmp): ' . (is_dir($tmp) ? 'YES' : 'NO');
$out[] = 'is_writable(tmp): ' . (is_writable($tmp) ? 'YES' : 'NO');
// try to write a small temp file
$testFile = $tmp . DIRECTORY_SEPARATOR . 'remoto_check_' . uniqid() . '.tmp';
$written = @file_put_contents($testFile, "test\n");
$out[] = 'write test file result: ' . ($written === false ? 'FAILED' : 'OK');
if ($written !== false) {
    $out[] = 'test file size: ' . filesize($testFile);
    @unlink($testFile);
}
$out[] = 'GD extension: ' . (extension_loaded('gd') ? 'YES' : 'NO');
$out[] = 'GD imagecreatefromstring exists: ' . (function_exists('imagecreatefromstring') ? 'YES' : 'NO');
$out[] = 'ZipArchive class: ' . (class_exists('ZipArchive') ? 'YES' : 'NO');
$out[] = 'open_basedir: ' . (ini_get('open_basedir') ?: '(none)');
$out[] = 'upload_tmp_dir: ' . (ini_get('upload_tmp_dir') ?: '(php default)');
$out[] = "\nAdditional info:\n";
$out[] = 'Loaded configuration file: ' . (php_ini_loaded_file() ?: '(none)');
$out[] = 'Headers sent? ' . (headers_sent() ? 'YES' : 'NO');
// try to create a tiny zip using ZipArchive if available
if (class_exists('ZipArchive')) {
    $zipPath = $tmp . DIRECTORY_SEPARATOR . 'remoto_check_zip_' . uniqid() . '.zip';
    $z = new ZipArchive();
    if ($z->open($zipPath, ZipArchive::CREATE) === true) {
        $z->addFromString('hello.txt', 'hello');
        $z->close();
        $out[] = 'ZipArchive create test: OK (' . $zipPath . ') size=' . filesize($zipPath);
        @unlink($zipPath);
    } else {
        $out[] = 'ZipArchive create test: FAILED';
    }
} else {
    $out[] = 'ZipArchive not available, skipping zip test.';
}

echo implode("\n", $out);
