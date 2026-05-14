<?php
require 'index.php';
// Let's test normalizeQueryContent from index.php directly
$c = [
    'StudyInstanceUID' => ['Value' => ['1.2.276.0.7230010.3.1.2.3012029134.7304.1776283351.2312']],
    'StudyDate' => ['Value' => ['20260415']],
    'StudyDescription' => ['Value' => ['RMN RODILLA IZQ/SOAT/NOQX/TX-INESTABILIDAD-LSION LCA?-MENISCO?']],
    'Modality' => ['Value' => ['RM']],
    'PatientName' => ['Alphabetic' => 'VALENCIA YENNY FERNANDA']
];
$norm = normalizeQueryContent($c);
print_r($norm);
