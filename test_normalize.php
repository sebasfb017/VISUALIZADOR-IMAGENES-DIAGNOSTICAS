<?php
$ORTHANC_URL = 'http://192.168.52.155:8042';
$MODALITY_ID = 'CANVAS';

function callOrthanc($method, $path, $body = null) {
    global $ORTHANC_URL;
    $url = rtrim($ORTHANC_URL, '/') . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $headers = ['Accept: application/json'];
    if ($body !== null) {
        $payload = is_string($body) ? $body : json_encode($body);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($payload);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

function extractTagValue($v) {
    if ($v === null) return '';
    if (is_string($v)) return trim($v);
    if (is_numeric($v)) return (string) $v;
    if (is_array($v)) {
        if (isset($v['Value']) && is_array($v['Value']) && count($v['Value']) > 0) {
            $first = $v['Value'][0];
            if (is_array($first) || is_object($first)) return trim(json_encode($first));
            return trim((string) $first);
        }
        if (isset($v['Alphabetic'])) return trim((string) $v['Alphabetic']);
        $flat = [];
        foreach ($v as $k => $val) {
            if (is_array($val) || is_object($val)) continue;
            $s = trim((string) $val);
            if ($s !== '') $flat[] = $s;
        }
        if (!empty($flat)) return implode(' ', $flat);
        foreach ($v as $item) {
            if (is_string($item) || is_numeric($item)) return trim((string) $item);
            if (is_array($item) && isset($item['Value']) && is_array($item['Value']) && count($item['Value']) > 0) return trim((string) $item['Value'][0]);
        }
        return '';
    }
    if (is_object($v)) return trim(json_encode($v));
    return '';
}

function normalizeQueryContent(array $c): array {
    $tags = [];
    $uidRaw = $c['StudyInstanceUID'] ?? $c['0020,000D'] ?? $c['0020,000d'] ?? null;
    $tags['StudyInstanceUID'] = $uidRaw !== null ? extractTagValue($uidRaw) : null;
    
    $sd = '';
    if (isset($c['StudyDate'])) $sd = extractTagValue($c['StudyDate']);
    if ($sd === '' && isset($c['0008,0020'])) $sd = extractTagValue($c['0008,0020']);
    $tags['StudyDate'] = $sd ?: null;

    $mod = $c['MainDicomTags']['Modality'] ?? $c['Modality'] ?? '';
    if (!empty($c['MainDicomTags']['ModalitiesInStudy'])) {
        $tags['ModalitiesInStudy'] = $c['MainDicomTags']['ModalitiesInStudy'];
    } elseif (!empty($mod)) {
        $tags['ModalitiesInStudy'] = is_array($mod) ? $mod : [$mod];
        $tags['Modality'] = $mod;
    } else {
        $tags['ModalitiesInStudy'] = [];
    }
    return $tags;
}

try {
    $patientIdValue = '38794415';
    $query = ['PatientID' => $patientIdValue, 'Modality' => '', 'StudyDate' => '', 'StudyTime' => '', 'StudyDescription' => '', 'PatientName' => ''];
    $body = ['Level' => 'Study', 'Query' => $query];
    $resp = callOrthanc('POST', "/modalities/$MODALITY_ID/query", $body);
    $queryId = $resp['ID'] ?? null;
    
    if (!$queryId) die("No query ID\n");
    sleep(1);

    $answers = callOrthanc('GET', "/queries/$queryId/answers");
    foreach ($answers as $idx) {
        $content = callOrthanc('GET', "/queries/$queryId/answers/$idx/content?simplify");
        $normContent = $content;
        if (isset($content['MainDicomTags'])) {
            $mainTags = $content['MainDicomTags'] ?? [];
            $patientTags = $content['PatientMainDicomTags'] ?? [];
            $normContent = array_merge($mainTags, $patientTags);
        }
        $norm = normalizeQueryContent($normContent);
        echo "Idx $idx => UID: " . ($norm['StudyInstanceUID'] ?: 'NULL') . " | Date: " . ($norm['StudyDate'] ?: 'NULL') . " | Mods: " . json_encode($norm['ModalitiesInStudy'] ?? []) . "\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
