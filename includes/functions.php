<?php
// Llamada genérica a la API REST de Orthanc (método central para toda comunicación)
function callOrthanc($method, $path, $body = null)
{
    global $ORTHANC_URL;

    $url = rtrim($ORTHANC_URL, '/') . $path;
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Timeout de 30 segundos para evitar hangs largos

    $headers = ['Accept: application/json'];

    if ($body !== null) {
        // --- [MODIFICACIÓN] Soporte para strings puros en REST API ---
        if (is_string($body)) {
            $payload = $body;
            $headers[] = 'Content-Type: text/plain';
        } else {
            $payload = json_encode($body);
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $headers[] = 'Content-Length: ' . strlen($payload);
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    // Debug: log request/response
    if (function_exists('debug_log')) {
        $safeResp = is_string($response) ? mb_substr($response, 0, 4000) : json_encode($response);
        debug_log("callOrthanc $method $path HTTP=$httpCode resp=" . $safeResp);
    }

    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception("Error llamando a Orthanc: $err");
    }

    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new Exception("Orthanc devolvió HTTP $httpCode: $response");
    }

    if ($response === '') {
        return null;
    }

    $decoded = json_decode($response, true);
    // --- [MODIFICACIÓN] 3. Control estricto de Errores JSON ---
    // Detecta si la conexión con Orthanc retornó un error de Gateway (ej. 502 Bad Gateway puro HTML)
    if (json_last_error() !== JSON_ERROR_NONE) {
        // En vez de inyectar el bad payload al usuario (XSS / Technical dump), se lanza excepción
        throw new Exception("Servidor PACS devolvió un contenido irreconocible (no JSON). Es probable que Orthanc esté temporalmente detenido.");
    }
    return $decoded;
}

// Construye el rango de fechas DICOM a partir de YYYY-MM-DD
// DICOM: YYYYMMDD o YYYYMMDD-YYYYMMDD
function buildDicomDateRange(?string $dateFrom, ?string $dateTo): ?string
{
    $from = null;
    $to = null;

    if (!empty($dateFrom)) {
        $from = str_replace('-', '', $dateFrom); // 2025-12-04 -> 20251204
    }
    if (!empty($dateTo)) {
        $to = str_replace('-', '', $dateTo);
    }

    if ($from && $to) {
        return $from . '-' . $to;
    } elseif ($from && !$to) {
        return $from . '-';
    } elseif (!$from && $to) {
        return '-' . $to;
    } else {
        return null;
    }
}

// Formatea fecha DICOM (YYYYMMDD) a DD/MM/YYYY
function formatDicomDate(?string $d): string
{
    if ($d === null)
        return '';
    $d = trim($d);
    if (strlen($d) === 8 && ctype_digit($d)) {
        return substr($d, 6, 2) . '/' . substr($d, 4, 2) . '/' . substr($d, 0, 4);
    }
    return $d;
}

// Formatea hora DICOM (HHMMSS...) a HH:MM:SS o HH:MM
function formatDicomTime(?string $t): string
{
    if ($t === null)
        return '';
    $t = trim($t);
    if (strlen($t) >= 6 && ctype_digit(substr($t, 0, 6))) {
        return substr($t, 0, 2) . ':' . substr($t, 2, 2) . ':' . substr($t, 4, 2);
    } elseif (strlen($t) >= 4 && ctype_digit(substr($t, 0, 4))) {
        return substr($t, 0, 2) . ':' . substr($t, 2, 2);
    }
    return $t;
}

// Buscar estudios en Orthanc a partir de un query DICOM (PatientID opcional)
function findStudiesInOrthancByPatientId(?string $patientId, int $maxTries = 1, int $delaySeconds = 0, ?string $dateFrom = null, ?string $dateTo = null): array
{
    $query = [];

    if ($patientId !== null && $patientId !== '') {
        $query['PatientID'] = $patientId;
    }

    $dateRange = buildDicomDateRange($dateFrom, $dateTo);
    if ($dateRange) {
        $query['StudyDate'] = $dateRange;
    }

    $body = [
        'Level' => 'Study',
        'Expand' => true,
        'Query' => $query,
    ];

    for ($i = 0; $i < $maxTries; $i++) {
        $studies = callOrthanc('POST', '/tools/find', $body);
        if (is_array($studies) && count($studies) > 0) {
            return $studies;
        }
        if ($delaySeconds > 0) {
            sleep($delaySeconds);
        }
    }

    return [];
}

// Obtiene los datos completos de un estudio en Orthanc por su DICOM StudyInstanceUID
function getOrthancStudyFullData(string $studyUid): ?array
{
    try {
        // Orthanc requiere su propio ID interno (hash), no el DICOM UID, para rutas como /studies/.
        // Usamos /tools/lookup para encontrar el ID interno de Orthanc a partir del StudyInstanceUID.
        $lookup = callOrthanc('POST', '/tools/lookup', $studyUid);
        if (is_array($lookup) && count($lookup) > 0) {
            foreach ($lookup as $match) {
                if (($match['Type'] ?? '') === 'Study') {
                    $orthancId = $match['ID'];
                    $res = callOrthanc('GET', '/studies/' . urlencode($orthancId));
                    if (is_array($res) && isset($res['MainDicomTags'])) {
                        $res['ID'] = $orthancId; // Aseguramos que el ID de Orthanc esté disponible
                        return $res;
                    }
                }
            }
        }
    } catch (Exception $e) {
        // estudio no existe o el PACS devolvió error de no encontrado
    }
    return null;
}


// Verifica si un estudio existe en Orthanc
function orthancStudyExists(string $studyUid): bool
{
    return getOrthancStudyFullData($studyUid) !== null;
}

// Normaliza contenido simplificado de una respuesta de query a un set de MainDicomTags
function normalizeQueryContent(array $c): array
{
    $tags = [];
    // StudyInstanceUID: buscar en varias claves (puede venir como '0020,000D' o StudyInstanceUID)
    $tags['StudyInstanceUID'] = $c['StudyInstanceUID'] ?? $c['0020,000D'] ?? null;
    // extraer StudyDate en múltiples formatos
    $sd = '';
    if (isset($c['StudyDate']))
        $sd = extractTagValue($c['StudyDate']);
    if ($sd === '' && isset($c['0008,0020']))
        $sd = extractTagValue($c['0008,0020']);
    $tags['StudyDate'] = $sd ?: null;

    // Patient / Nombre: buscar PatientName en varias formas
    $rawName = $c['PatientName'] ?? $c['0010,0010'] ?? $c['Patient'] ?? '';
    if (is_array($rawName)) {
        // intentamos normalizar estructuras complejas
        if (isset($rawName['Alphabetic'])) {
            $rawName = $rawName['Alphabetic'];
        } else {
            // usar extractTagValue para casos Orthanc
            $rawName = extractTagValue($rawName);
        }
    }
    $tags['PatientName'] = $rawName ?? '';
    $tags['PatientID'] = $c['PatientID'] ?? $c['0010,0020'] ?? '';

    // Hora: preferir StudyTime, luego SeriesTime, AcquisitionTime
    $studyTime = $c['MainDicomTags']['StudyTime'] ?? $c['StudyTime'] ?? $c['SeriesTime'] ?? $c['AcquisitionTime'] ?? ($c['0008,0030'] ?? null) ?? '';
    if (!empty($studyTime)) {
        $tags['StudyTime'] = $studyTime;
    }

    // Modalities: ModalitiesInStudy or Modality
    $mod = $c['MainDicomTags']['Modality'] ?? $c['Modality'] ?? '';
    if (!empty($c['MainDicomTags']['ModalitiesInStudy'])) {
        $tags['ModalitiesInStudy'] = $c['MainDicomTags']['ModalitiesInStudy'];
    } elseif (!empty($mod)) {
        $tags['ModalitiesInStudy'] = is_array($mod) ? $mod : [$mod];
        $tags['Modality'] = $mod;
    } else {
        $tags['ModalitiesInStudy'] = [];
    }

    // Descripción: StudyDescription, SeriesDescription, ProtocolName
    $tags['StudyDescription'] = extractTagValue($c['StudyDescription'] ?? ($c['SeriesDescription'] ?? ($c['ProtocolName'] ?? '')));

    return $tags;
}

// Devuelve el primer valor no vacío de una lista de claves dentro de $tags
function pickTag(array $tags, array $keys): string
{
    foreach ($keys as $k) {
        if (array_key_exists($k, $tags) && $tags[$k] !== null) {
            $v = $tags[$k];
            $ex = extractTagValue($v);
            if ($ex !== '' && $ex !== '0' && $ex !== '[]')
                return $ex;
        }
    }
    return '';
}

// Extrae un valor legible de múltiples representaciones DICOM JSON
function extractTagValue($v): string
{
    if ($v === null)
        return '';
    if (is_string($v))
        return trim($v);
    if (is_numeric($v))
        return (string) $v;
    if (is_array($v)) {
        // Estructura tipo Orthanc: ['Value' => [ '20260318' ], 'vr' => 'DA']
        if (isset($v['Value']) && is_array($v['Value']) && count($v['Value']) > 0) {
            $first = $v['Value'][0];
            if (is_array($first) || is_object($first)) {
                // si es complejo intentar convertir a string
                return trim(json_encode($first));
            }
            return trim((string) $first);
        }
        // Estructura tipo nombre: ['Alphabetic' => 'LAST^FIRST']
        if (isset($v['Alphabetic']))
            return trim((string) $v['Alphabetic']);
        // Si es mapa simple con claves, intentar juntar valores
        $flat = [];
        foreach ($v as $k => $val) {
            if (is_array($val) || is_object($val))
                continue;
            $s = trim((string) $val);
            if ($s !== '')
                $flat[] = $s;
        }
        if (!empty($flat))
            return implode(' ', $flat);
        // Si es array indexado, tomar primer elemento convertible
        foreach ($v as $item) {
            if (is_string($item) || is_numeric($item))
                return trim((string) $item);
            if (is_array($item) && isset($item['Value']) && is_array($item['Value']) && count($item['Value']) > 0)
                return trim((string) $item['Value'][0]);
        }
        return '';
    }
    if (is_object($v))
        return trim(json_encode($v));
    return '';
}

function normalizeString($str)
{
    $str = strtolower($str);
    $str = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'n'], $str);
    return $str;
}

// Llamada genérica que devuelve el contenido bruto (para imágenes/previews)
function callOrthancRaw($method, $path, $body = null)
{
    global $ORTHANC_URL;

    $url = rtrim($ORTHANC_URL, '/') . $path;
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $headers = [];
    if ($body !== null) {
        $payload = json_encode($body);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($payload);
    }

    if (!empty($headers))
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception("Error llamando a Orthanc: $err");
    }

    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new Exception("Orthanc devolvió HTTP $httpCode al pedir recurso binario.");
    }

    return $response; // bytes
}

// Logging temporal para depuración (escribe en sys_get_temp_dir()/remoto_debug.log)
function debug_log($msg)
{
    $path = rtrim(sys_get_temp_dir(), "\/") . DIRECTORY_SEPARATOR . 'remoto_debug.log';
    $entry = date('c') . ' - ' . $msg . PHP_EOL;
    @file_put_contents($path, $entry, FILE_APPEND | LOCK_EX);
}

function getInferredModality(string $desc): string
{
    $descLower = normalizeString($desc);
    if (strpos($descLower, 'mr') !== false || strpos($descLower, 'rm') !== false || strpos($descLower, 'magnetic') !== false || strpos($descLower, 'resonance') !== false || strpos($descLower, 'mri') !== false) {
        return 'RM';
    } elseif (strpos($descLower, 'ct') !== false || strpos($descLower, 'tc') !== false || strpos($descLower, 'computed') !== false || strpos($descLower, 'tomography') !== false || strpos($descLower, 'tomograf') !== false || strpos($descLower, 'tac') !== false) {
        return 'CT';
    } elseif (strpos($descLower, 'us') !== false || strpos($descLower, 'ultrasound') !== false || strpos($descLower, 'eco') !== false || strpos($descLower, 'ecografia') !== false) {
        return 'US';
    } elseif (strpos($descLower, 'mg') !== false || strpos($descLower, 'mammography') !== false || strpos($descLower, 'mamografia') !== false || strpos($descLower, 'mamo') !== false) {
        return 'MG';
    } elseif (strpos($descLower, 'xa') !== false || strpos($descLower, 'angiography') !== false || strpos($descLower, 'angio') !== false) {
        return 'XA';
    } elseif (strpos($descLower, 'rayos') !== false || strpos($descLower, 'rx') !== false || strpos($descLower, 'x-ray') !== false || strpos($descLower, 'cr') !== false || strpos($descLower, 'dr') !== false || strpos($descLower, 'dx') !== false || strpos($descLower, 'radiografia') !== false) {
        return 'DX';
    } else {
        return 'DX'; // Default to DX if not inferred
    }
}

// Helper: crear ZIP sin depender de ZipArchive (almacena sin compresión, fallback)
function create_plain_zip(array $filesMap, string $zipPath): bool
{
    $zp = @fopen($zipPath, 'wb');
    if ($zp === false)
        return false;
    $offset = 0;
    $central = '';
    $entries = 0;
    foreach ($filesMap as $filePath => $localName) {
        if (!is_file($filePath))
            continue;
        $data = @file_get_contents($filePath);
        if ($data === false)
            continue;
        $crc = crc32($data);
        $filesize = strlen($data);

        // Local file header
        $localHeader = pack('V', 0x04034b50);
        $localHeader .= pack('v', 20); // version needed to extract
        $localHeader .= pack('v', 0); // general purpose bit flag
        $localHeader .= pack('v', 0); // compression method (0 = store)
        $localHeader .= pack('v', 0); // last mod file time
        $localHeader .= pack('v', 0); // last mod file date
        $localHeader .= pack('V', $crc);
        $localHeader .= pack('V', $filesize);
        $localHeader .= pack('V', $filesize);
        $localHeader .= pack('v', strlen($localName));
        $localHeader .= pack('v', 0); // extra len

        fwrite($zp, $localHeader);
        fwrite($zp, $localName);
        fwrite($zp, $data);

        // Central directory file header
        $centralHeader = pack('V', 0x02014b50);
        $centralHeader .= pack('v', 0); // version made by
        $centralHeader .= pack('v', 20); // version needed
        $centralHeader .= pack('v', 0); // flags
        $centralHeader .= pack('v', 0); // compression
        $centralHeader .= pack('v', 0); // mtime
        $centralHeader .= pack('v', 0); // mdate
        $centralHeader .= pack('V', $crc);
        $centralHeader .= pack('V', $filesize);
        $centralHeader .= pack('V', $filesize);
        $centralHeader .= pack('v', strlen($localName));
        $centralHeader .= pack('v', 0); // extra len
        $centralHeader .= pack('v', 0); // comment len
        $centralHeader .= pack('v', 0); // disk number start
        $centralHeader .= pack('v', 0); // internal attrs
        $centralHeader .= pack('V', 0); // external attrs
        $centralHeader .= pack('V', $offset);
        $centralHeader .= $localName;

        $central .= $centralHeader;

        $offset += strlen($localHeader) + strlen($localName) + $filesize;
        $entries++;
    }

    $centralDirOffset = $offset;
    fwrite($zp, $central);
    $centralSize = strlen($central);

    // End of central directory record
    $eocd = pack('V', 0x06054b50);
    $eocd .= pack('v', 0); // disk number
    $eocd .= pack('v', 0); // disk start
    $eocd .= pack('v', $entries); // entries this disk
    $eocd .= pack('v', $entries); // total entries
    $eocd .= pack('V', $centralSize);
    $eocd .= pack('V', $centralDirOffset);
    $eocd .= pack('v', 0); // comment len
    fwrite($zp, $eocd);
    fclose($zp);
    return true;
}

