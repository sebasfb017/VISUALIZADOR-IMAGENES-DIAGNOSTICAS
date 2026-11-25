<?php
ob_start();
session_start();

/**
 * ============================================================================
 * SECCIÓN 1: CONFIGURACIÓN BÁSICA DEL SISTEMA
 * ============================================================================
 * Ajusta estos valores según tu entorno.
 * Aquí se definen las credenciales, URLs de conexión a Orthanc (el servidor 
 * DICOM principal) y alias de Modalidades en ClearCanvas para permitir 
 * la consulta remota y transferencia de estudios PACS.
 */

// Orthanc HTTP en el mismo equipo
$ORTHANC_URL = 'http://192.168.52.34:8042';

// Alias de ClearCanvas en "DicomModalities" de Orthanc
$MODALITY_ID = 'CANVAS';

// AET de tu Orthanc (como en orthanc.json y en ClearCanvas)
$ORTHANC_AET = 'ORTHANC';

// Base de OHIF dentro de Orthanc.
// Usando el visor OHIF: /ohif/viewer?StudyInstanceUIDs=
$OHIF_BASE_URL = 'http://181.56.10.196:8042'; // IP Externa / Remota específica para Buscador 1
$OHIF_VIEWER_PATH = '/ohif/viewer?StudyInstanceUIDs=';

// Usuarios para login (cámbialos por algo más seguro)
$USERS = [
    'admin' => '$2y$10$1hQIP5E4AOkgxs4AfLUw9ee/mln2.jyWFy/ngF9RfqWfBhqFLQN5W', // usuario: admin / clave: admin
    'MEDICO' => '$2y$10$nmo/DuvhjBpoxZymJoyBAO1o8d1MCbD0CQoziKssZgU9Dr/8YtZTe',
];

// Usuario invitado pedido: contraseña igual al nombre de usuario ('invitado').
// Se almacena en texto para facilitar la creación aquí; la lógica de login
// aceptará tanto contraseñas hashed (bcrypt) como texto plano.
$USERS['invitado'] = 'invitado';

// Límite de consultas simultáneas por usuario para mejorar concurrencia (aumentado para 15 usuarios)
define('MAX_CONCURRENT_QUERIES', 5);

// Clave secreta para la generación de enlaces efímeros temporales (Compartir a Pacientes)
$SHARE_SECRET = 'vsd_auth_premium_2026';

/**
 * ============================================================================
 * SECCIÓN 2: FUNCIONES AUXILIARES Y DE CONEXIÓN REST
 * ============================================================================
 * Este bloque contiene todas las funciones auxiliares (helpers) utilizadas para 
 * comunicarse con la API REST de Orthanc, realizar consultas C-FIND, parsear 
 * y dar formato a la información DICOM (fechas, horas, modalidades), y procesar 
 * estructuras complejas DICOM JSON hacia un formato plano usado por la interfaz.
 */

// Llamada genérica a la API REST de Orthanc (método central para toda comunicación)
// $timeout: segundos máximos de espera (default 30). Usar valores más bajos para C-FIND remoto.
function callOrthanc($method, $path, $body = null, $timeout = 30)
{
    global $ORTHANC_URL;

    $url = rtrim($ORTHANC_URL, '/') . $path;
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, $timeout)); // Conexión rápida o falla rápido

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

// Llamada binaria a la API REST de Orthanc (Específicamente para descargar Thumbnails)
function callOrthancBinary($method, $path)
{
    global $ORTHANC_URL;
    $url = rtrim($ORTHANC_URL, '/') . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $headers = ['Accept: image/jpeg, image/png'];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($httpCode >= 200 && $httpCode < 300 && $response !== false) {
        return $response;
    }
    return null;
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
        'Query' => (object) $query,
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

// --- [CACHÉ EN MEMORIA] Almacén global para evitar llamadas repetidas a Orthanc
// en el mismo request (problema N+1). Cada UID solo se consulta UNA VEZ por request.
$_ORTHANC_STUDY_CACHE = [];

// Obtiene los datos completos de un estudio en Orthanc por su DICOM StudyInstanceUID
// Con caché en memoria: si ya lo consultamos en este request, se devuelve el resultado
// guardado sin hacer otra petición HTTP a Orthanc.
function getOrthancStudyFullData(string $studyUid): ?array
{
    global $_ORTHANC_STUDY_CACHE;

    // Devolver desde caché si ya se consultó en este mismo request
    if (array_key_exists($studyUid, $_ORTHANC_STUDY_CACHE)) {
        return $_ORTHANC_STUDY_CACHE[$studyUid];
    }

    $result = null;
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
                        $result = $res;
                        break;
                    }
                }
            }
        }
    } catch (Exception $e) {
        // estudio no existe o el PACS devolvió error de no encontrado
    }

    // Guardar en caché (incluso null, para no volver a preguntar por estudios inexistentes)
    $_ORTHANC_STUDY_CACHE[$studyUid] = $result;
    return $result;
}


// Verifica si un estudio existe en Orthanc
function orthancStudyExists(string $studyUid): bool
{
    return getOrthancStudyFullData($studyUid) !== null;
}

// Limpia queries DICOM antiguas en Orthanc para evitar saturar el límite de memoria/almacenamiento (100)
function cleanupStaleOrthancQueries(int $maxKeep = 25): void
{
    try {
        $queries = callOrthanc('GET', '/queries');
        if (is_array($queries) && count($queries) > $maxKeep) {
            $toDelete = array_slice($queries, 0, count($queries) - $maxKeep);
            foreach ($toDelete as $qid) {
                try {
                    callOrthanc('DELETE', '/queries/' . urlencode($qid));
                } catch (Exception $e) {
                    // ignorar fallos individuales
                }
            }
        }
    } catch (Exception $e) {
        // ignorar si no se pueden listar queries
    }
}

// Normaliza contenido simplificado de una respuesta de query a un set de MainDicomTags
function normalizeQueryContent(array $c): array
{
    $tags = [];
    // StudyInstanceUID: buscar en varias claves (puede venir como '0020,000D' o StudyInstanceUID)
    $uidRaw = $c['StudyInstanceUID'] ?? $c['0020,000D'] ?? $c['0020,000d'] ?? null;
    $tags['StudyInstanceUID'] = $uidRaw !== null ? extractTagValue($uidRaw) : null;

    // extraer StudyDate en múltiples formatos y tags equivalentes DICOM
    $sd = '';
    if (isset($c['StudyDate'])) {
        $sd = extractTagValue($c['StudyDate']);
    }
    if ($sd === '' && isset($c['0008,0020'])) {
        $sd = extractTagValue($c['0008,0020']);
    }
    if ($sd === '' && isset($c['SeriesDate'])) {
        $sd = extractTagValue($c['SeriesDate']);
    }
    if ($sd === '' && isset($c['0008,0021'])) {
        $sd = extractTagValue($c['0008,0021']);
    }
    if ($sd === '' && isset($c['AcquisitionDate'])) {
        $sd = extractTagValue($c['AcquisitionDate']);
    }
    if ($sd === '' && isset($c['0008,0022'])) {
        $sd = extractTagValue($c['0008,0022']);
    }
    $tags['StudyDate'] = $sd ?: null;

    // PatientName
    $rawName = $c['PatientName'] ?? $c['0010,0010'] ?? $c['Patient'] ?? '';
    $tags['PatientName'] = extractTagValue($rawName);

    // PatientID
    $tags['PatientID'] = extractTagValue($c['PatientID'] ?? $c['0010,0020'] ?? '');

    // Hora: preferir StudyTime, luego SeriesTime, AcquisitionTime
    $studyTime = $c['MainDicomTags']['StudyTime'] ?? $c['StudyTime'] ?? $c['SeriesTime'] ?? $c['AcquisitionTime'] ?? ($c['0008,0030'] ?? null) ?? '';
    $studyTime = extractTagValue($studyTime);
    if (!empty($studyTime)) {
        $tags['StudyTime'] = $studyTime;
    }

    // Descripción: StudyDescription, SeriesDescription, ProtocolName
    $tags['StudyDescription'] = extractTagValue($c['StudyDescription'] ?? $c['SeriesDescription'] ?? $c['ProtocolName'] ?? '');

    // Modalities: ModalitiesInStudy or Modality
    $mod = $c['MainDicomTags']['Modality'] ?? $c['Modality'] ?? '';
    $modsInStudy = $c['MainDicomTags']['ModalitiesInStudy'] ?? $c['ModalitiesInStudy'] ?? null;
    if (!empty($modsInStudy)) {
        if (is_array($modsInStudy)) {
            $tags['ModalitiesInStudy'] = $modsInStudy;
        } else {
            $tags['ModalitiesInStudy'] = array_values(array_filter(array_map('trim', explode('\\', (string)$modsInStudy))));
        }
    } elseif (!empty($mod)) {
        $tags['ModalitiesInStudy'] = is_array($mod) ? $mod : [$mod];
        $tags['Modality'] = $mod;
    } else {
        $inferred = getInferredModality($tags['StudyDescription']);
        $tags['ModalitiesInStudy'] = $inferred ? [$inferred] : [];
    }

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
function callOrthancRaw($method, $path, $body = null, array $customHeaders = [])
{
    global $ORTHANC_URL;

    $url = rtrim($ORTHANC_URL, '/') . $path;
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $headers = $customHeaders;
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

/**
 * ============================================================================
 * SECCIÓN 3: MANEJO DE ESTADO GLOBAL Y VARIABLES
 * ============================================================================
 * Se inicializan las variables que controlarán el flujo de la interfaz de
 * usuario, incluyendo mensajes de error, lista de estudios encontrados,
 * filtros seleccionados en la URL, e inicialización de banderas críticas 
 * para acciones como "forzar búsqueda remota" o "iniciar recuperación (retrieve)".
 */

$status = null; // 'ok' o 'error' para mensajes de la app
$message = '';
$studies = [];
$patientIdValue = '';
$dateFromValue = '';
$dateToValue = '';
$selectedModalities = $_GET['modalities'] ?? [];
$allModalities = ['RM', 'CT', 'DX', 'US', 'MG', 'XA'];
$modalityMap = [
    'MR' => 'RM',
    'CT' => 'CT',
    'DX' => 'DX',
    'CR' => 'DX',
    'DR' => 'DX',
    'US' => 'US',
    'MG' => 'MG',
    'XA' => 'XA',
];
$debugDetails = ''; // detalle técnico del último error
$forceRemote = false; // inicializar
$doRetrieve = false; // inicializar
// Mensaje de error de login (evita warnings si no se ha intentado iniciar sesión)
$loginError = '';

// --- [MODIFICACIÓN] 1. Control de Session Timeout y Fuerza Bruta ---
define('SESSION_TIMEOUT_SECONDS', 3600); // 60 minutos de inactividad máxima
define('MAX_LOGIN_ATTEMPTS', 3); // Intentos máximos de inicio de sesión
define('LOGIN_LOCKOUT_SECONDS', 300); // 5 minutos de bloqueo

if (isset($_SESSION['user'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT_SECONDS)) {
        // La sesión ha expirado por inactividad
        session_unset();
        session_destroy();
        session_start();
        $loginError = 'Tu sesión médica ha expirado por inactividad prolongada. Por favor, inicia sesión nuevamente.';
    } else {
        // Renovar tiempo de vida de la sesión
        $_SESSION['last_activity'] = time();
    }
}

/**
 * ============================================================================
 * SECCIÓN 4: CONTROL DE ACCESO (LOGIN / LOGOUT)
 * ============================================================================
 * Maneja el inicio y cierre de sesión de los usuarios. 
 * Es importante tener esta barrera de seguridad porque se están consultando 
 * datos médicos sensibles y de pacientes.
 */
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/**
 * LOGIN
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    // --- [MODIFICACIÓN] Control de Fuerza Bruta mediante validación de IP ---
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = [];
    }

    // Limpiar bloqueo tras expirar el tiempo de castigo (5 minutos)
    if (isset($_SESSION['login_attempts'][$ip]) && (time() - $_SESSION['login_attempts'][$ip]['last_time'] > LOGIN_LOCKOUT_SECONDS)) {
        unset($_SESSION['login_attempts'][$ip]);
    }

    if (isset($_SESSION['login_attempts'][$ip]) && $_SESSION['login_attempts'][$ip]['count'] >= MAX_LOGIN_ATTEMPTS) {
        $loginError = 'Exceso de intentos. Tu acceso ha sido bloqueado temporalmente por seguridad. Intenta en 5 minutos.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $loginError = 'Debes ingresar usuario y contraseña.';
        } elseif (!isset($USERS[$username])) {
            $loginError = 'Usuario o contraseña incorrectos.';
            $_SESSION['login_attempts'][$ip]['count'] = ($_SESSION['login_attempts'][$ip]['count'] ?? 0) + 1;
            $_SESSION['login_attempts'][$ip]['last_time'] = time();
        } else {
            $stored = $USERS[$username];
            $ok = false;
            if (is_string($stored) && (strpos($stored, '$2y$') === 0 || strpos($stored, '$2b$') === 0 || strpos($stored, '$2a$') === 0)) {
                $ok = password_verify($password, $stored);
            } else {
                $ok = ($password === $stored);
            }

            if (!$ok) {
                $loginError = 'Usuario o contraseña incorrectos.';
                $_SESSION['login_attempts'][$ip]['count'] = ($_SESSION['login_attempts'][$ip]['count'] ?? 0) + 1;
                $_SESSION['login_attempts'][$ip]['last_time'] = time();
            } else {
                // --- [MODIFICACIÓN] Regenerar Session ID contra ataques de fijación y reset de penalidades ---
                session_regenerate_id(true);
                $_SESSION['user'] = $username;
                $_SESSION['last_activity'] = time(); // Iniciar cronómetro de inactividad
                unset($_SESSION['login_attempts'][$ip]);
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            }
        }
    }
}

/**
 * ============================================================================
 * SECCIÓN 5: LÓGICA DE VISUALIZACIÓN OHIF (ACCIONES 'VIEW')
 * ============================================================================
 * Cuando el usuario hace clic en "Visualizar", este bloque revisa si el
 * estudio ya es local. Si no (por ejemplo si viene de los resultados de
 * ClearCanvas), recrea la consulta (C-FIND) en Canvas y lanza automáticamente 
 * la orden de traerlo al equipo local (C-MOVE/retrieve) para luego mandarlo a 
 * ver nativamente en el visor OHIF de Orthanc.
 */
/**
 * ============================================================================
 * SECCIÓN THUMBNAILS (Previsualización Hover en tiempo real)
 * ============================================================================
 */
/*
if (isset($_SESSION['user']) && isset($_GET['action']) && $_GET['action'] === 'thumbnail') {
    $studyUid = trim($_GET['study_uid'] ?? '');
    if ($studyUid) {
        try {
            $studies = callOrthanc('POST', '/tools/find', [
                'Level' => 'Study',
                'Query' => ['StudyInstanceUID' => $studyUid]
            ]);
            if (!empty($studies)) {
                $studyId = $studies[0];
                $series = callOrthanc('GET', "/studies/$studyId/series");
                if (!empty($series)) {
                    $seriesId = is_array($series[0]) ? ($series[0]['ID'] ?? null) : $series[0];
                    if ($seriesId) {
                        $instances = callOrthanc('GET', "/series/$seriesId/instances");
                        if (!empty($instances)) {
                            $instanceId = is_array($instances[0]) ? ($instances[0]['ID'] ?? null) : $instances[0];
                            if ($instanceId) {
                                $image = callOrthancBinary('GET', "/instances/$instanceId/preview");
                                if ($image) {
                                    header('Content-Type: image/jpeg');
                                    header('Cache-Control: public, max-age=86400');
                                    echo $image;
                                    exit;
                                }
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) { // Silently fall back to empty png
        }
    }
    // Pixel transparente
    header('Content-Type: image/png');
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    exit;
}
*/

// ============================================================================
// ENDPOINTS AJAX: PRECARGA Y PROGRESO DE VISUALIZACIÓN EN SEGUNDO PLANO
// ============================================================================

// 1. Iniciar recuperación del estudio en segundo plano (para la ventana flotante)
if (isset($_SESSION['user']) && isset($_GET['action']) && $_GET['action'] === 'start_view_retrieve') {
    header('Content-Type: application/json; charset=utf-8');
    $studyUid = trim($_GET['study_uid'] ?? '');
    if ($studyUid === '' || !preg_match('/^[0-9.]+$/', $studyUid)) {
        echo json_encode(['status' => 'error', 'message' => 'StudyInstanceUID inválido']);
        exit;
    }

    // Leer datos de sesión necesarios y liberar el lock inmediatamente
    $lastQueryData = null;
    if (isset($_SESSION['last_query']) && is_array($_SESSION['last_query'])) {
        $lastQueryData = $_SESSION['last_query'];
    } elseif (isset($_SESSION['last_query_data']) && is_array($_SESSION['last_query_data'])) {
        $lastQueryData = $_SESSION['last_query_data'];
    }
    $viewJobs = $_SESSION['view_jobs'] ?? [];
    session_write_close();

    $ohifUrl = rtrim($OHIF_BASE_URL, '/') . rtrim($OHIF_VIEWER_PATH, '/') . urlencode($studyUid);

    // Caso A: Ya existe en Orthanc localmente (listo para ver)
    if (orthancStudyExists($studyUid)) {
        echo json_encode([
            'status' => 'ready',
            'progress' => 100,
            'message' => 'Estudio listo en PACS local.',
            'ohifUrl' => $ohifUrl
        ]);
        exit;
    }

    // Caso B: Estudio remoto, disparar C-MOVE desde ClearCanvas
    $queryId = trim($_GET['query_id'] ?? '');
    if ($queryId === '') {
        $queryId = $lastQueryData['id'] ?? '';
    }
    $answerIdx = trim($_GET['answer_idx'] ?? '');

    try {
        $resolvedQueryId = null;
        $resolvedAnswerIdx = null;

        if ($queryId !== '' && $answerIdx !== '') {
            try {
                $testAnswers = callOrthanc('GET', "/queries/" . urlencode($queryId) . "/answers");
                if (is_array($testAnswers) && in_array((string)$answerIdx, array_map('strval', $testAnswers), true)) {
                    $resolvedQueryId = $queryId;
                    $resolvedAnswerIdx = (string)$answerIdx;
                }
            } catch (Exception $e) {
                $resolvedQueryId = null;
            }
        }

        // Si la query expiró o no la tenemos, recrearla
        if (!$resolvedQueryId || $resolvedAnswerIdx === null || $resolvedAnswerIdx === '') {
            $body = (!empty($lastQueryData['body']) && is_array($lastQueryData['body'])) 
                ? $lastQueryData['body'] 
                : ['StudyInstanceUID' => $studyUid];
            $newResp = callOrthanc('POST', "/modalities/$MODALITY_ID/query", [
                'Level' => 'Study',
                'Query' => $body
            ]);
            $resolvedQueryId = $newResp['ID'] ?? null;
            if (!$resolvedQueryId) {
                throw new Exception('No se pudo consultar ClearCanvas.');
            }
            $answers = callOrthanc('GET', "/queries/$resolvedQueryId/answers");
            $resolvedAnswerIdx = null;
            foreach ($answers as $idx) {
                $content = callOrthanc('GET', "/queries/$resolvedQueryId/answers/$idx/content?simplify");
                $norm = normalizeQueryContent(is_array($content) ? $content : []);
                if (($norm['StudyInstanceUID'] ?? '') === $studyUid) {
                    $resolvedAnswerIdx = (string)$idx;
                    break;
                }
            }
            // Si la consulta fue directa por StudyInstanceUID y devolvió resultados
            if ($resolvedAnswerIdx === null && !empty($answers)) {
                $resolvedAnswerIdx = (string)$answers[0];
            }
            if ($resolvedAnswerIdx === null || $resolvedAnswerIdx === '') {
                throw new Exception('No se encontró el estudio en ClearCanvas.');
            }
        }

        // Iniciar C-MOVE en Orthanc
        $retrieveResp = callOrthanc('POST', "/queries/$resolvedQueryId/answers/$resolvedAnswerIdx/retrieve", $ORTHANC_AET);
        $jobId = $retrieveResp['ID'] ?? null;

        session_start();
        if (!isset($_SESSION['view_jobs'])) {
            $_SESSION['view_jobs'] = [];
        }
        $_SESSION['view_jobs'][$studyUid] = [
            'jobId' => $jobId,
            'started' => time(),
            'queryId' => $resolvedQueryId,
            'answerIdx' => $resolvedAnswerIdx
        ];
        session_write_close();

        echo json_encode([
            'status' => 'pending',
            'jobId' => $jobId,
            'progress' => 15,
            'message' => 'Transferencia iniciada en segundo plano...',
            'ohifUrl' => $ohifUrl
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Error al iniciar recuperación: ' . $e->getMessage()
        ]);
        exit;
    }
}

// 2. Consultar progreso en tiempo real de la transferencia del estudio
if (isset($_SESSION['user']) && isset($_GET['action']) && $_GET['action'] === 'check_view_job') {
    session_write_close(); // El polling no debe bloquear la sesión de PHP
    header('Content-Type: application/json; charset=utf-8');
    $studyUid = trim($_GET['study_uid'] ?? '');
    $jobId = trim($_GET['job_id'] ?? '');

    if ($studyUid === '' || !preg_match('/^[0-9.]+$/', $studyUid)) {
        echo json_encode(['status' => 'error', 'message' => 'StudyInstanceUID inválido']);
        exit;
    }

    $ohifUrl = rtrim($OHIF_BASE_URL, '/') . rtrim($OHIF_VIEWER_PATH, '/') . urlencode($studyUid);
    $isLocal = orthancStudyExists($studyUid);

    if ($jobId !== '') {
        try {
            $job = callOrthanc('GET', '/jobs/' . urlencode($jobId));
            $state = $job['State'] ?? '';
            $progress = intval($job['Progress'] ?? 0);

            if ($state === 'Success') {
                echo json_encode([
                    'status' => 'ready',
                    'progress' => 100,
                    'message' => '¡Estudio cargado al 100%! Abriendo visor...',
                    'ohifUrl' => $ohifUrl
                ]);
                exit;
            } elseif ($state === 'Failure') {
                if ($isLocal) {
                    echo json_encode([
                        'status' => 'ready',
                        'progress' => 100,
                        'message' => 'Estudio disponible en PACS local. Abriendo visor...',
                        'ohifUrl' => $ohifUrl
                    ]);
                    exit;
                }
                echo json_encode([
                    'status' => 'error',
                    'progress' => 0,
                    'message' => 'La transferencia falló en ClearCanvas: ' . ($job['ErrorDescription'] ?? 'Error desconocido')
                ]);
                exit;
            } else {
                $calcPct = max(20, min(95, $progress));
                echo json_encode([
                    'status' => 'pending',
                    'progress' => $calcPct,
                    'message' => 'Transfiriendo imágenes DICOM al PACS local (' . $calcPct . '%)...',
                    'ohifUrl' => $ohifUrl
                ]);
                exit;
            }
        } catch (Exception $e) {
            if ($isLocal) {
                echo json_encode([
                    'status' => 'ready',
                    'progress' => 100,
                    'message' => 'Estudio listo en PACS local.',
                    'ohifUrl' => $ohifUrl
                ]);
                exit;
            }
        }
    }

    if ($isLocal) {
        echo json_encode([
            'status' => 'ready',
            'progress' => 100,
            'message' => 'Estudio listo en PACS local.',
            'ohifUrl' => $ohifUrl
        ]);
        exit;
    }

    echo json_encode([
        'status' => 'pending',
        'progress' => 35,
        'message' => 'Esperando recepción de imágenes en Orthanc...',
        'ohifUrl' => $ohifUrl
    ]);
    exit;
}

$isSharedAccess = false;
if (isset($_GET['action']) && $_GET['action'] === 'view_shared') {
    $uid = trim($_GET['study_uid'] ?? '');
    $exp = (int) ($_GET['exp'] ?? 0);
    $sig = trim($_GET['sig'] ?? '');
    $expectedSig = hash_hmac('sha256', $uid . '|' . $exp, $SHARE_SECRET);
    if ($exp > time() && hash_equals($expectedSig, $sig)) {
        $isSharedAccess = true;
    } else {
        die('Error de Acceso: El enlace ha caducado o no es válido.');
    }
}

if ((isset($_SESSION['user']) || $isSharedAccess) && isset($_GET['action']) && ($_GET['action'] === 'view' || $_GET['action'] === 'view_shared')) {
    $studyUid = trim($_GET['study_uid'] ?? '');

    // --- [MODIFICACIÓN] 2. Validación Estricta RegEx de DICOM OID ---
    // Bloquea inyecciones invalidando cualquier UID que tenga caracteres ajenos a formato médico
    if ($studyUid !== '' && !preg_match('/^[0-9.]+$/', $studyUid)) {
        die('Error de Seguridad: StudyInstanceUID proveído contiene caracteres no permitidos.');
    }

    $queryId = trim($_GET['query_id'] ?? '');
    if ($queryId === '') {
        $queryId = isset($_SESSION['last_query']['id']) ? $_SESSION['last_query']['id'] : '';
    }
    $answerIdx = trim($_GET['answer_idx'] ?? '');

    if ($studyUid === '') {
        $status = 'error';
        $message = 'Falta StudyInstanceUID para visualizar.';
    } else {
        try {
            $doRetrieve = false;

            // Construir URL del visor OHIF
            $ohifUrl = rtrim($OHIF_BASE_URL, '/') . rtrim($OHIF_VIEWER_PATH, '/') . urlencode($studyUid);

            if ($queryId !== '') {
                // --- [MEJORA RENDIMIENTO] Estudio REMOTO (viene de ClearCanvas) ---
                // Si el estudio ya llegó a Orthanc (porque el médico ya lo había visto antes),
                // ir directo al visor sin C-MOVE innecesario.
                if (orthancStudyExists($studyUid)) {
                    debug_log("view: study $studyUid already in Orthanc, skipping C-MOVE, opening OHIF directly");
                    header('Location: ' . $ohifUrl);
                    exit;
                }

                $doRetrieve = true;

                // --- [MEJORA RENDIMIENTO] Usar el answerIdx que ya viene en la URL
                // (guardado en la tabla de resultados) para evitar re-query completa a ClearCanvas.
                // Solo recreamos la query si NO tenemos el answerIdx disponible.
                if ($answerIdx !== '') {
                    // Camino rápido: answerIdx ya conocido, usar queryId de la URL directamente
                    // Verificar que la query sigue viva en Orthanc
                    try {
                        $testAnswers = callOrthanc('GET', "/queries/$queryId/answers");
                        $resolvedQueryId = $queryId;
                        $resolvedAnswerIdx = $answerIdx;
                        debug_log("view: using existing queryId=$queryId answerIdx=$answerIdx (fast path)");
                    } catch (Exception $e) {
                        // La query expiró, necesitamos recrearla
                        $resolvedQueryId = null;
                        debug_log("view: query $queryId expired, will recreate. Error: " . $e->getMessage());
                    }
                }

                // Camino lento: recrear la query (query expirada o no tenemos answerIdx)
                if (empty($resolvedQueryId)) {
                    $body = isset($_SESSION['last_query']['body']) ? $_SESSION['last_query']['body'] : null;

                    // Si no hay body en sesión, intentar buscar solo por StudyInstanceUID
                    if (!$body || !is_array($body)) {
                        $body = ['StudyInstanceUID' => $studyUid];
                    }

                    $newResp = callOrthanc('POST', "/modalities/$MODALITY_ID/query", [
                        'Level' => 'Study',
                        'Query' => $body
                    ]);
                    $resolvedQueryId = $newResp['ID'] ?? null;
                    if (!$resolvedQueryId) {
                        throw new Exception('No se pudo recrear la query en ClearCanvas.');
                    }

                    // Encontrar el answerIdx para este studyUid
                    $answers = callOrthanc('GET', "/queries/$resolvedQueryId/answers");
                    $resolvedAnswerIdx = '';
                    foreach ($answers as $idx) {
                        $content = callOrthanc('GET', "/queries/$resolvedQueryId/answers/$idx/content?simplify");
                        $norm = normalizeQueryContent(is_array($content) ? $content : []);
                        $remoteUidNorm = $norm['StudyInstanceUID'] ?? null;
                        if ($remoteUidNorm === $studyUid) {
                            $resolvedAnswerIdx = $idx;
                            break;
                        }
                    }

                    if ($resolvedAnswerIdx === '') {
                        throw new Exception('No se encontró el estudio en ClearCanvas. Verifica la conexión con la modalidad.');
                    }
                    debug_log("view: recreated query resolvedQueryId=$resolvedQueryId answerIdx=$resolvedAnswerIdx");
                }

                $debugDetails = 'Enviando C-MOVE para ' . $studyUid . ' vía ' . $MODALITY_ID . ' → ' . $ORTHANC_AET . '. queryId=' . $resolvedQueryId . ' answerIdx=' . $resolvedAnswerIdx;
            }

            if (!$doRetrieve) {
                // Estudio LOCAL: ir directo al visor
                header('Location: ' . $ohifUrl);
                exit;
            }
            $loading = true;
        } catch (Exception $e) {
            $status = 'error';
            $message = $e->getMessage();
            $debugDetails = $e->getMessage();
        }
    }
}

/**
 * ============================================================================
 * SECCIÓN 6: DISPARO DE RETRIEVE PARA VISUALIZADOR
 * ============================================================================
 * Inicia la petición real al servidor remoto (ClearCanvas) para descargar el
 * estudio hacia el nodo local de Orthanc. Una vez iniciada, redirige al 
 * usuario a una página de espera ("wait.php" o asíncrona) para no bloquear su 
 * navegador mientras se ejecuta el trabajo en el fondo del servidor.
 */
if ($doRetrieve && isset($loading)) {
    try {
        // --- [MEJORA RENDIMIENTO] Disparar C-MOVE y redirigir INMEDIATAMENTE al visor
        // sin bloquear al médico. OHIF mostrará las imágenes a medida que lleguen
        // gracias al mecanismo WADOrs/DICOMweb de Orthanc (carga progresiva).
        $retrieveResp = callOrthanc('POST', "/queries/$resolvedQueryId/answers/$resolvedAnswerIdx/retrieve", $ORTHANC_AET);
        $jobId = $retrieveResp['ID'] ?? null;

        // Guardar info del job en sesión para monitoreo opcional posterior
        if ($jobId) {
            $_SESSION['retrieve_job'] = $jobId;
        }
        $_SESSION['ohif_url'] = $ohifUrl;

        // --- REDIRIGIR INMEDIATAMENTE AL VISOR OHIF ---
        // OHIF carga estudios DICOM de forma progresiva (imagen por imagen),
        // por lo que el médico comienza a ver las primeras imágenes en segundos,
        // mientras el retrieve sigue trayendo el resto en segundo plano.
        // Ya no hay sleep(5) ni wait.php — tiempo de espera del médico = ~0s.
        header('Location: ' . $ohifUrl);
        exit;

    } catch (Exception $e) {
        $status = 'error';
        $message = 'Error al recuperar el estudio: ' . $e->getMessage();
        $debugDetails = $e->getMessage();
        unset($loading);
    }
}

/**
 * ============================================================================
 * SECCIÓN 7: MOTOR DE BÚSQUEDA PRINCIPAL (LISTADO DE ESTUDIOS)
 * ============================================================================
 * Ésta es la función central de la pantalla principal. Su propósito es:
 * 1. Buscar en la base local (Orthanc) los estudios del paciente o las fechas.
 * 2. Solicitar una búsqueda (C-FIND) al servidor remoto (ClearCanvas) con los mismos datos.
 * 3. Combinar, normalizar y mostrar ambos conjuntos de datos, priorizando que 
 *    los remotos se entiendan como "por importar".
 */
if (isset($_SESSION['user']) && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['patient_id'])) {
    $patientIdValue = trim($_GET['patient_id'] ?? '');
    $dateFromValue = trim($_GET['date_from'] ?? '');
    $dateToValue = trim($_GET['date_to'] ?? '');

    $currentQueryHash = md5($patientIdValue . '|' . $dateFromValue . '|' . $dateToValue);

    // Agregar query a la lista activa
    $_SESSION['active_queries'][] = time();

    // Limpiar búsquedas previas solo si los filtros cambiaron (evita recargar todo en la paginación)
    if (!isset($_SESSION['last_search_hash']) || $_SESSION['last_search_hash'] !== $currentQueryHash) {
        unset($_SESSION['last_search_results']);
        $_SESSION['last_search_hash'] = $currentQueryHash;
    }

    // --- [OPTIMIZACIÓN] session_write_close() para concurrencia multi-tab / multi-usuario ---
    // Leer todos los datos de sesión que necesitamos ANTES de cerrar la escritura.
    // Esto libera el lock del archivo de sesión, permitiendo que otras peticiones
    // del mismo usuario (otras pestañas) no queden bloqueadas esperando.
    $cachedSearchResults = $_SESSION['last_search_results'] ?? null;
    $cachedLastQuery = $_SESSION['last_query'] ?? ($_SESSION['last_query_data'] ?? null);
    $sessionUser = $_SESSION['user'] ?? null;
    $sessionActiveQueries = $_SESSION['active_queries'] ?? [];
    session_write_close(); // <-- Libera el lock de sesión

    // --- [MODIFICACIÓN] 2. Sanitizado de Formato de Entrada de Fechas ---
    // Filtra las fechas recibidas por URL para que sean un string seguro de formato 'YYYY-MM-DD'
    if ($dateFromValue !== '') {
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dateFromValue, $matches)) {
            $dateFromValue = $matches[3] . '-' . $matches[2] . '-' . $matches[1];
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFromValue)) {
            $dateFromValue = '';
        }
    }
    if ($dateToValue !== '') {
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dateToValue, $matches)) {
            $dateToValue = $matches[3] . '-' . $matches[2] . '-' . $matches[1];
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateToValue)) {
            $dateToValue = '';
        }
    }

    // Prevención de búsqueda masiva: si la fecha se invalidó y no hay paciente, abortar
    if ($patientIdValue === '' && $dateFromValue === '' && $dateToValue === '') {
        $status = 'error';
        $message = 'Debes escribir un ID de paciente o indicar un rango de fechas válido.';
        $studies = [];
        $display = [];
    } else {
        $dateFrom = $dateFromValue !== '' ? $dateFromValue : null;
        $dateTo = $dateToValue !== '' ? $dateToValue : null;

        // Validar que la fecha desde no sea posterior a la fecha hasta
        if ($dateFrom && $dateTo && $dateFrom > $dateTo) {
            $status = 'error';
            $message = 'La fecha "desde" no puede ser posterior a la fecha "hasta".';
        } else {
            try {
                if ($cachedSearchResults !== null) {
                    // Ya tenemos los resultados exactos para estos filtros, recuperamos de caché
                    $display = $cachedSearchResults;
                    $studies = $display;
                    $localCount = count(array_filter($display, fn($s) => isset($s['_local']) && $s['_local']));
                    $remoteCount = count($display) - $localCount;
                    $status = 'ok';
                    $filterMsg = !empty($selectedModalities) ? ' Filtro aplicado.' : '';
                    $message = 'Listado generado (' . $localCount . ' locales, ' . $remoteCount . ' remotos). Pulsa "Cargar y ver" para traer un estudio desde Canvas cuando lo necesites.' . $filterMsg;
                } else {
                    $dateRange = buildDicomDateRange($dateFrom, $dateTo);

                    $forceRemote = true;

                    // 1) Estudios ya existentes en Orthanc
                    $localStudies = findStudiesInOrthancByPatientId($patientIdValue !== '' ? $patientIdValue : null, 1, 0, $dateFrom, $dateTo);
                    // Deduplicar estudios locales por StudyInstanceUID
                    $uniqueLocal = [];
                    foreach ($localStudies as $st) {
                        $uid = $st['MainDicomTags']['StudyInstanceUID'] ?? null;
                        if ($uid && !isset($uniqueLocal[$uid])) {
                            $uniqueLocal[$uid] = $st;
                        }
                    }
                    $localStudies = array_values($uniqueLocal);
                    $localStudyUids = [];
                    $display = [];

                    foreach ($localStudies as $st) {
                        $uid = $st['MainDicomTags']['StudyInstanceUID'] ?? null;
                        if ($uid) {
                            $localStudyUids[$uid] = true;
                        }
                        $item = $st;
                        $item['_local'] = true;
                        $display[] = $item;
                    }

                    // 2) C-FIND en Canvas (listado) si es posible
                    $query = [];
                    if ($patientIdValue !== '') {
                        $query['PatientID'] = $patientIdValue;
                    }
                    if ($dateRange) {
                        $query['StudyDate'] = $dateRange;
                    }
                    if (!empty($modalityValue)) {
                        $query['Modality'] = $modalityValue;
                    }
                    // IMPORTANTE: Asegurar que StudyDate y ModalitiesInStudy siempre estén presentes en la consulta C-FIND
                    // incluso si no se filtró por fecha, para que ClearCanvas los devuelva de inmediato a nivel de estudio.
                    if (!isset($query['StudyDate'])) {
                        $query['StudyDate'] = '';
                    }
                    if (!isset($query['ModalitiesInStudy'])) {
                        $query['ModalitiesInStudy'] = '';
                    }
                    if (!isset($query['Modality'])) {
                        $query['Modality'] = '';
                    }
                    $query['StudyTime'] = '';
                    $query['StudyDescription'] = '';
                    $query['AccessionNumber'] = '';

                    if ($forceRemote && empty($query)) {
                        $query['PatientID'] = '';
                        $query['PatientName'] = '';
                    }

                    if (!empty($query) && !isset($query['PatientID'])) {
                        $query['PatientID'] = '';
                        $query['PatientName'] = '';
                    }

                    if (!empty($query)) {
                        try {
                            if ($patientIdValue !== '') {
                                $query['PatientName'] = '';
                            }
                            // Para búsquedas solo por fecha, no agregar wildcards extra para evitar limitar resultados

                            // Comprobación de conectividad y existencia de la modalidad en Orthanc
                            try {
                                $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orthanc_modalities.json';
                                if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
                                    $modalitiesList = json_decode(file_get_contents($cacheFile), true);
                                } else {
                                    $modalitiesList = callOrthanc('GET', '/modalities');
                                    if ($modalitiesList)
                                        file_put_contents($cacheFile, json_encode($modalitiesList));
                                }
                                $foundModality = false;
                                if (is_array($modalitiesList)) {
                                    // Caso A: array asociativo indexado por ID
                                    if (isset($modalitiesList[$MODALITY_ID])) {
                                        $foundModality = true;
                                    } else {
                                        // Caso B: lista indexada o estructura con objetos/valores
                                        foreach ($modalitiesList as $k => $v) {
                                            // clave igual a MODALITY_ID
                                            if (strtoupper((string) $k) === strtoupper($MODALITY_ID)) {
                                                $foundModality = true;
                                                break;
                                            }

                                            // valor simple igual a MODALITY_ID
                                            if (is_string($v) && strtoupper($v) === strtoupper($MODALITY_ID)) {
                                                $foundModality = true;
                                                break;
                                            }

                                            // valor array/obj con campo ID o Name
                                            if (is_array($v)) {
                                                if ((isset($v['ID']) && strtoupper((string) $v['ID']) === strtoupper($MODALITY_ID)) || (isset($v['Name']) && strtoupper((string) $v['Name']) === strtoupper($MODALITY_ID))) {
                                                    $foundModality = true;
                                                    break;
                                                }
                                            } elseif (is_object($v)) {
                                                $obj = (array) $v;
                                                if ((isset($obj['ID']) && strtoupper((string) $obj['ID']) === strtoupper($MODALITY_ID)) || (isset($obj['Name']) && strtoupper((string) $obj['Name']) === strtoupper($MODALITY_ID))) {
                                                    $foundModality = true;
                                                    break;
                                                }
                                            }
                                        }
                                    }
                                } elseif (is_string($modalitiesList)) {
                                    // Caso C: respuesta simple como string (ej. "CANVAS" o lista separada)
                                    if (strtoupper($modalitiesList) === strtoupper($MODALITY_ID)) {
                                        $foundModality = true;
                                    } elseif (strpos($modalitiesList, ',') !== false) {
                                        // Si es una lista separada por comas
                                        $mods = explode(',', $modalitiesList);
                                        foreach ($mods as $mod) {
                                            if (strtoupper(trim($mod)) === strtoupper($MODALITY_ID)) {
                                                $foundModality = true;
                                                break;
                                            }
                                        }
                                    }
                                }
                                if (!$foundModality) {
                                    $debugDetails = 'Respuesta /modalities: ' . json_encode($modalitiesList);
                                    throw new Exception('Modalidad "' . $MODALITY_ID . '" no encontrada en Orthanc. Revisa orthanc.json (DicomModalities) y la conexión con ClearCanvas.');
                                }
                            } catch (Exception $e) {
                                // si ya hay debugDetails lo conservamos
                                if (empty($debugDetails))
                                    $debugDetails = $e->getMessage();
                                throw new Exception('No se pudo conectar a Orthanc/Canvas: ' . $e->getMessage());
                            }

                            // Limpieza automática preventiva de consultas antiguas en Orthanc para mantener memoria liberada
                            cleanupStaleOrthancQueries(25);

                            // --- [OPTIMIZACIÓN] Timeout reducido para C-FIND remoto ---
                            // ClearCanvas normalmente responde en < 2s. Con 10s de timeout,
                            // si está caído o lento no bloquea al usuario 30 segundos.
                            $queryResponse = callOrthanc('POST', "/modalities/$MODALITY_ID/query", [
                                'Level' => 'Study',
                                'Query' => $query
                            ], 10);

                            $queryId = $queryResponse['ID'] ?? null;
                            if ($queryId) {
                                $_SESSION['last_query'] = ['id' => $queryId, 'when' => time(), 'body' => $query];
                            }
                            // Si la búsqueda fue por PatientID, intentar obtener PatientName a nivel Patient
                            $globalPatientName = '';
                            if ($patientIdValue !== '') {
                                try {
                                    $patientQuery = ['PatientID' => $patientIdValue, 'PatientName' => '*'];
                                    $patientQueryResp = callOrthanc('POST', "/modalities/$MODALITY_ID/query", [
                                        'Level' => 'Patient',
                                        'Query' => $patientQuery
                                    ], 8);
                                    $patientQueryId = $patientQueryResp['ID'] ?? null;
                                    if ($patientQueryId) {
                                        $patientAnswers = callOrthanc('GET', "/queries/$patientQueryId/answers");
                                        if (!empty($patientAnswers) && is_array($patientAnswers)) {
                                            // tomar el primer resultado
                                            $first = reset($patientAnswers);
                                            $patientContent = callOrthanc('GET', "/queries/$patientQueryId/answers/$first/content?simplify");
                                            if (is_array($patientContent)) {
                                                $patientTags = $patientContent['PatientMainDicomTags'] ?? $patientContent['MainDicomTags'] ?? $patientContent;
                                                $globalPatientName = $patientTags['PatientName'] ?? $patientTags['0010,0010'] ?? '';
                                                if (is_array($globalPatientName)) {
                                                    if (isset($globalPatientName['Alphabetic'])) {
                                                        $globalPatientName = (string) $globalPatientName['Alphabetic'];
                                                    } else {
                                                        $globalPatientName = implode(' ', array_filter(array_map('strval', $globalPatientName)));
                                                    }
                                                } else {
                                                    $globalPatientName = (string) $globalPatientName;
                                                }
                                                if ($globalPatientName !== '')
                                                    $globalPatientName = str_replace('^', ' ', $globalPatientName);
                                                $globalPatientName = preg_replace('/^PatientName\s+String\s+/i', '', $globalPatientName);
                                            }
                                        }
                                        // borrar query
                                        callOrthanc('DELETE', "/queries/$patientQueryId");
                                    }
                                } catch (Exception $e) {
                                    // ignorar
                                }
                            }

                            if (!$queryId) {
                                // No se pudo crear la query, mostrar locales si hay
                                $studies = $display;
                                $localCount = count($display);
                                if (!empty($studies)) {
                                    $status = 'ok';
                                    $message = 'Mostrando estudios locales (' . $localCount . '); no se pudo consultar Canvas.';
                                } else {
                                    $status = 'ok';
                                    $message = 'No se encontraron estudios y no se pudo consultar Canvas.';
                                }
                            } else {
                                $answers = callOrthanc('GET', "/queries/$queryId/answers");

                                if (empty($answers) || !is_array($answers)) {
                                    // No hay respuestas remotas; mantenemos solo los locales
                                    $studies = $display;
                                    $localCount = count($display);
                                    if (!empty($studies)) {
                                        $status = 'ok';
                                        $message = 'No se encontraron estudios nuevos en Canvas. Mostrando los estudios presentes en Orthanc (' . $localCount . ').';
                                    } else {
                                        $status = 'ok';
                                        $message = 'No se encontraron estudios ni en Orthanc ni en Canvas para ese criterio.';
                                    }
                                    // Borrar la query vacía
                                    callOrthanc('DELETE', "/queries/$queryId");
                                } else {
                                    // Construir listado combinando locales y remotos (sin realizar retrieve)
                                    $remoteStudyUids = [];
                                    foreach ($answers as $idx) {
                                        $content = callOrthanc('GET', "/queries/$queryId/answers/$idx/content?simplify");
                                        if (is_array($content)) {
                                            if (isset($content['MainDicomTags'])) {
                                                $mainTags = $content['MainDicomTags'] ?? [];
                                                $patientTags = $content['PatientMainDicomTags'] ?? [];
                                                $norm = array_merge($mainTags, $patientTags);
                                            } else {
                                                $norm = $content;
                                            }
                                        } else {
                                            $norm = [];
                                        }
                                        $norm = normalizeQueryContent($norm);
                                        // Si no tenemos StudyDate en la respuesta simplificada, intentar obtenerlo del contenido completo o de tags equivalentes
                                        if (empty($norm['StudyDate'])) {
                                            try {
                                                $fullAnswer = callOrthanc('GET', "/queries/" . $queryId . "/answers/" . $idx . "/content");
                                                if (is_array($fullAnswer)) {
                                                    $fullTagsForDate = $fullAnswer['MainDicomTags'] ?? $fullAnswer;
                                                    $sd = pickTag($fullTagsForDate, ['StudyDate', '0008,0020', 'SeriesDate', '0008,0021', 'AcquisitionDate', '0008,0022']);
                                                    if ($sd !== '') {
                                                        $norm['StudyDate'] = $sd;
                                                    }
                                                }
                                            } catch (Exception $e) {
                                                debug_log('could not fetch full answer content for date: ' . $e->getMessage());
                                            }
                                        }
                                        $remoteUid = $norm['StudyInstanceUID'] ?? null;
                                        if ($remoteUid && !isset($localStudyUids[$remoteUid]) && !isset($remoteStudyUids[$remoteUid])) {
                                            $remoteStudyUids[$remoteUid] = true;

                                            // Si la modalidad no vino explícita en C-FIND, inferirla instantáneamente de la descripción en memoria
                                            // sin hacer consultas lentas de red C-FIND de series que congelaban la búsqueda.
                                            if (empty($norm['ModalitiesInStudy'])) {
                                                $desc = $norm['StudyDescription'] ?? '';
                                                $inferred = getInferredModality($desc);
                                                $norm['ModalitiesInStudy'] = $inferred ? [$inferred] : ['DX'];
                                            }

                                            $item = ['MainDicomTags' => $norm];
                                            $item['_local'] = false;
                                            $item['_remote'] = ['queryId' => $queryId, 'answerIdx' => $idx];
                                            $display[] = $item;
                                        }
                                    }

                                    // Poblar ModalitiesInStudy si falta para estudios locales
                                    foreach ($display as &$s) {
                                        $tags = $s['MainDicomTags'];
                                        $studyUid = $tags['StudyInstanceUID'] ?? null;
                                        if (empty($tags['ModalitiesInStudy']) && $studyUid) {
                                            $full = getOrthancStudyFullData($studyUid);
                                            if ($full && isset($full['MainDicomTags']['ModalitiesInStudy'])) {
                                                $s['MainDicomTags']['ModalitiesInStudy'] = $full['MainDicomTags']['ModalitiesInStudy'];
                                            }
                                        }
                                    }
                                    unset($s); // Evitar bug de referencia en el último elemento

                                    // Aplicar filtro de modalidades si seleccionado
                                    if (!empty($selectedModalities)) {
                                        $display = array_filter($display, function ($s) use ($selectedModalities, $modalityMap) {
                                            $mods = $s['MainDicomTags']['ModalitiesInStudy'] ?? [];
                                            if (!is_array($mods))
                                                $mods = [];
                                            $mappedMods = array_map(
                                                function ($m) use ($modalityMap) {
                                                    return $modalityMap[trim($m)] ?? trim($m);
                                                }
                                                ,
                                                $mods
                                            );
                                            // Si no hay modalidades mapeadas, inferir de la descripción
                                            if (empty($mappedMods)) {
                                                $desc = $s['MainDicomTags']['StudyDescription'] ?? '';
                                                $inferred = getInferredModality($desc);
                                                $mappedMods = $inferred ? [$inferred] : ['N/D'];
                                            }
                                            // Si incluye 'N/D', incluir siempre
                                            if (in_array('N/D', $mappedMods)) {
                                                return true;
                                            }
                                            return !empty(array_intersect($mappedMods, $selectedModalities));
                                        });
                                    }

                                    // Guardamos el queryId, body y resultados en sesión para uso posterior (retrieve/paginación)
                                    // Reabrir sesión para escritura (fue cerrada antes de las llamadas de red)
                                    session_start();
                                    $_SESSION['last_search_results'] = $display;
                                    $_SESSION['last_query'] = ['id' => $queryId, 'when' => time(), 'body' => $query];
                                    $_SESSION['last_query_data'] = $_SESSION['last_query'];
                                    session_write_close();

                                    $studies = $display;
                                    $localCount = count(array_filter($display, fn($s) => isset($s['_local']) && $s['_local']));
                                    $remoteCount = count($display) - $localCount;
                                    $status = 'ok';
                                    $filterMsg = !empty($selectedModalities) ? ' Filtro aplicado.' : '';
                                    $message = 'Listado generado (' . $localCount . ' locales, ' . $remoteCount . ' remotos). Pulsa "Cargar y ver" para traer un estudio desde Canvas cuando lo necesites.' . $filterMsg;
                                }
                            }
                        } catch (Exception $e) {
                            // Si falla la consulta remota, mostrar solo los locales con advertencia amigable
                            $studies = $display;
                            $localCount = count($display);
                            if (!empty($studies)) {
                                $status = 'warning';
                                $message = 'Mostrando estudios locales (' . $localCount . '). No se pudo consultar Canvas: ' . $e->getMessage();
                            } else {
                                $status = 'warning';
                                $message = 'No se encontraron estudios locales. Error al consultar Canvas: ' . $e->getMessage();
                            }
                        }
                    } else {
                        // No hay criterios de búsqueda, mostrar locales si hay
                        $studies = $display;
                        $localCount = count($display);
                        if (!empty($studies)) {
                            $status = 'ok';
                            $message = 'Mostrando estudios locales (' . $localCount . ').';
                        } else {
                            $status = 'ok';
                            $message = 'No se encontraron estudios para los criterios especificados.';
                        }
                    }
                }
            } catch (Exception $e) {
                $status = 'error';
                $message = $e->getMessage();
                $debugDetails = $e->getMessage();
            }
        } // FIN DE PREVENCION BUSQUEDA MASIVA

        // Remover query de la lista activa (reabrir sesión brevemente)
        session_start();
        if (isset($_SESSION['active_queries'])) {
            array_pop($_SESSION['active_queries']);
        }
        session_write_close();
    }
}
/**
 * ============================================================================
 * SECCIÓN 8: ORDENAMIENTO Y PAGINACIÓN DE ESTUDIOS
 * ============================================================================
 * Toma todo el array resultante (combinado entre locales y remotos almacenado 
 * en la sesión) y realiza el orden (ascendente/descendente según fechas o nombres).
 * Luego divide con array_slice la porción que corresponde a la página actual.
 */
// Paginación: preparar $studies para la página actual usando resultados en sesión si existen
$allowedPer = [10, 25, 50, 100];
$perPage = isset($_GET['per_page']) && in_array((int) $_GET['per_page'], $allowedPer) ? (int) $_GET['per_page'] : 10;
$page = max(1, intval($_GET['page'] ?? 1));
$sort = $_GET['sort'] ?? 'name';

// Lógica de toggle para orden
if (!isset($_SESSION['sort_type']) || $_SESSION['sort_type'] !== $sort) {
    $_SESSION['sort_type'] = $sort;
    $_SESSION['sort_order'] = 'asc'; // Default ascendente
} else {
    $_SESSION['sort_order'] = ($_SESSION['sort_order'] === 'asc') ? 'desc' : 'asc';
}
$order = $_SESSION['sort_order'];

$totalResults = 0;
$totalPages = 1;

if (isset($_SESSION['last_search_results']) && is_array($_SESSION['last_search_results'])) {
    $allResults = $_SESSION['last_search_results'];
    $totalResults = count($allResults);
    $totalPages = max(1, (int) ceil($totalResults / $perPage));

    // Ordenar los resultados primero
    if ($sort === 'date') {
        usort($allResults, function ($a, $b) use ($order) {
            $dateA = $a['MainDicomTags']['StudyDate'] ?? '';
            $dateB = $b['MainDicomTags']['StudyDate'] ?? '';
            if ($dateA === '' && $dateB !== '')
                return 1; // Vacío al final
            if ($dateB === '' && $dateA !== '')
                return -1;
            if ($order === 'asc') {
                return strcmp($dateA, $dateB); // Más antiguo primero
            } else {
                return strcmp($dateB, $dateA); // Más reciente primero
            }
        });
    } elseif ($sort === 'name') {
        usort($allResults, function ($a, $b) use ($order) {
            $nameA = $a['MainDicomTags']['PatientName'] ?? '';
            $nameB = $b['MainDicomTags']['PatientName'] ?? '';
            if ($nameA === '' && $nameB !== '')
                return 1; // Vacío al final
            if ($nameB === '' && $nameA !== '')
                return -1;
            if ($order === 'asc') {
                return strcmp($nameA, $nameB); // A-Z
            } else {
                return strcmp($nameB, $nameA); // Z-A
            }
        });
    }

    // Guardar el orden en sesión para persistir
    $_SESSION['last_search_results'] = $allResults;

    // Luego slice para la página
    $start = ($page - 1) * $perPage;
    $studies = array_slice($allResults, $start, $perPage);
} else {
    $allResults = $studies ?? [];
    $totalResults = count($allResults);
    $totalPages = max(1, (int) ceil(max(1, $totalResults) / $perPage));
    $start = ($page - 1) * $perPage;
    $studies = array_slice($allResults, $start, $perPage);
}

/**
 * ============================================================================
 * SECCIÓN 9: MÓDULO AL VUELO - DESCARGA DE IMÁGENES ZIP (.JPG)
 * ============================================================================
 * Gestor avanzado de creación de un ZIP bajo demanda con las imágenes del 
 * estudio. Recorre las series e instancias del estudio en el PACS, solicita su 
 * render ("preview") a Orthanc, convierde la imagen a JPG a través de PHP GD,
 * los reúne en un archivo ZipArchive temporal, y lo despacha como descarga. 
 * Además si el archivo original no existe en Orthanc, gatilla el modo C-MOVE 
 * desde el servidor remoto primero antes de armar el ZIP final.
 */
// Mostrar mensaje de error de descarga (si existe)
if (isset($_SESSION['download_error'])) {
    $status = 'error';
    $message = $_SESSION['download_error'];
    unset($_SESSION['download_error']);
}
// Nuevo flujo de descarga: inicio + espera (polling) + descarga real
if (isset($_GET['action']) && $_GET['action'] === 'download' && isset($_SESSION['user'])) {
    $studyUid = trim($_GET['study_uid'] ?? '');

    // --- [MODIFICACIÓN] 2. Validación RegEx estricta para endpoint de descarga ---
    if ($studyUid !== '' && !preg_match('/^[0-9.]+$/', $studyUid)) {
        $_SESSION['download_error'] = 'Error de seguridad: StudyInstanceUID contiene formato prohibido.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    if ($studyUid === '') {
        $_SESSION['download_error'] = 'Falta StudyInstanceUID para la descarga.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // Si se llama con download_now=1, generamos el ZIP y lo servimos (estudio debe existir)
    if (isset($_GET['download_now']) && $_GET['download_now'] === '1') {
        if (!orthancStudyExists($studyUid)) {
            // En lugar de fallar, intentar iniciar retrieve automáticamente y redirigir a la página de espera
            $queryId = trim($_GET['query_id'] ?? '');
            if ($queryId === '') {
                $queryId = isset($_SESSION['last_query']['id']) ? $_SESSION['last_query']['id'] : '';
            }
            $answerIdx = trim($_GET['answer_idx'] ?? '');

            // intentar recuperar desde session si no vienen en la URL
            if (($queryId === '' || $answerIdx === '') && isset($_SESSION['last_search_results']) && is_array($_SESSION['last_search_results'])) {
                foreach ($_SESSION['last_search_results'] as $item) {
                    $tags = $item['MainDicomTags'] ?? $item['PatientMainDicomTags'] ?? [];
                    $uid = $tags['StudyInstanceUID'] ?? $tags['0020,000D'] ?? null;
                    if ($uid === $studyUid && isset($item['_remote'])) {
                        $queryId = $queryId ?: ($item['_remote']['queryId'] ?? $queryId);
                        $answerIdx = $answerIdx ?: ($item['_remote']['answerIdx'] ?? $answerIdx);
                        if ($queryId !== '' && $answerIdx !== '')
                            break;
                    }
                }
            }

            // intentar usar last_query si aún faltan
            if (($queryId === '' || $answerIdx === '') && isset($_SESSION['last_query']['id'])) {
                $tryQ = $_SESSION['last_query']['id'];
                try {
                    $answers = callOrthanc('GET', '/queries/' . urlencode($tryQ) . '/answers');
                    if (!empty($answers) && is_array($answers)) {
                        foreach ($answers as $idx) {
                            try {
                                $content = callOrthanc('GET', '/queries/' . urlencode($tryQ) . '/answers/' . urlencode($idx) . '/content');
                            } catch (Exception $e) {
                                continue;
                            }
                            $norm = normalizeQueryContent(is_array($content) ? $content : []);
                            $remoteUidNorm = $norm['StudyInstanceUID'] ?? null;
                            if ($remoteUidNorm === $studyUid) {
                                $queryId = $queryId !== '' ? $queryId : $tryQ;
                                $answerIdx = $answerIdx !== '' ? $answerIdx : (string)$idx;
                                break;
                            }
                        }
                    }
                } catch (Exception $e) {
                    // ignore
                }
            }

            if ($queryId === '' || $answerIdx === '') {
                $_SESSION['download_error'] = 'El estudio no está presente en Orthanc al intentar descargar y no se pudo determinar información para iniciar el retrieve.';
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            }

            try {
                // Llamada con string puro (text/plain) para asegurar compatibilidad con todos los Orthanc
                $retrieveResp = callOrthanc('POST', '/queries/' . urlencode($queryId) . '/answers/' . urlencode($answerIdx) . '/retrieve', $ORTHANC_AET);
                debug_log('download_now triggered retrieve for study=' . $studyUid . ' q=' . $queryId . ' a=' . $answerIdx . ' resp=' . json_encode($retrieveResp));
            } catch (Exception $e) {
                debug_log('download_now retrieve failed for study=' . $studyUid . ' error=' . $e->getMessage());
                $_SESSION['download_error'] = 'No se pudo iniciar retrieve para traer el estudio: ' . $e->getMessage();
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            }

            $jobId = $retrieveResp['ID'] ?? null;
            if (!isset($_SESSION['download_jobs']))
                $_SESSION['download_jobs'] = [];
            $_SESSION['download_jobs'][$studyUid] = ['jobId' => $jobId, 'queryId' => $queryId, 'answerIdx' => $answerIdx, 'started' => time(), 'retries' => 0];

            header('Location: ' . $_SERVER['PHP_SELF'] . '?action=wait_download&study_uid=' . urlencode($studyUid));
            exit;
        }

        try {
            // Ampliar límites de ejecución y memoria por ser una tarea muy pesada
            set_time_limit(0);
            ini_set('memory_limit', '1024M');

            // Crear carpeta temporal
            $tmpBase = sys_get_temp_dir();

            // --- [MODIFICACIÓN] 4. Validación extra de permisos de Escritorio Temporal ---
            // Evita desencadenar la conversión de JPGs desde la memoria si no hay permisos de disco seguros
            if (!is_writable($tmpBase)) {
                throw new Exception('Permiso denegado: El directorio temporal de PHP (' . $tmpBase . ') se encuentra bloqueado contra escritura.');
            }

            $workDir = $tmpBase . DIRECTORY_SEPARATOR . 'remoto_dl_' . uniqid();
            if (!mkdir($workDir) && !is_dir($workDir)) {
                throw new Exception('No se pudo crear carpeta temporal para empaquetar el ZIP.');
            }

            $fileIndex = 1;

            // Convertir DICOM UID a Hash de Orthanc para consultas directas
            $studyData = getOrthancStudyFullData($studyUid);
            if (!$studyData || !isset($studyData['ID'])) {
                throw new Exception('El estudio debe ser importado completamente al PACS local primero.');
            }
            $orthancId = $studyData['ID'];

            // Obtener series del estudio
            $seriesList = callOrthanc('GET', '/studies/' . urlencode($orthancId) . '/series');
            foreach ($seriesList as $ser) {
                // seriesId puede venir como string o como array con 'ID'
                $seriesId = is_string($ser) ? $ser : ($ser['ID'] ?? null);
                if (!$seriesId)
                    continue;

                // obtener instancias
                $instances = callOrthanc('GET', '/series/' . urlencode($seriesId) . '/instances');
                foreach ($instances as $inst) {
                    $instanceId = is_string($inst) ? $inst : ($inst['ID'] ?? null);
                    if (!$instanceId)
                        continue;

                    // pedir preview (imagen JPEG lista para web) desde Orthanc con header directo
                    try {
                        $png = callOrthancRaw('GET', '/instances/' . urlencode($instanceId) . '/preview', null, ['Accept: image/jpeg']);
                    } catch (Exception $e) {
                        // intentar siguiente instancia si falla
                        continue;
                    }

                    $fname = sprintf('%03d.jpg', $fileIndex);
                    $outPath = $workDir . DIRECTORY_SEPARATOR . $fname;

                    // Optimización de alto rendimiento: Si Orthanc ya devolvió un JPEG nativo válido,
                    // escribirlo directamente a disco sin re-comprimir con GD (ahorra ~80% de CPU y RAM)
                    if (substr($png, 0, 3) === "\xFF\xD8\xFF") {
                        if (file_put_contents($outPath, $png) !== false) {
                            $fileIndex++;
                        }
                    } elseif (function_exists('imagecreatefromstring')) {
                        // Fallback con GD si es formato distinto (ej. PNG)
                        $img = @imagecreatefromstring($png);
                        if ($img !== false) {
                            imagejpeg($img, $outPath, 85);
                            imagedestroy($img);
                            $fileIndex++;
                        }
                    } else {
                        if (file_put_contents($outPath, $png) !== false) {
                            $fileIndex++;
                        }
                    }
                }
            }

            if ($fileIndex === 1) {
                // no se generaron imágenes
                throw new Exception('No se pudieron obtener imágenes para el estudio.');
            }

            // Crear ZIP
            $zipName = 'study_' . $studyUid . '.zip';
            $zipPath = $workDir . DIRECTORY_SEPARATOR . $zipName;

            // --- [MODIFICACIÓN] Implementación de Fallback para ZIP sin Extensión Nativa ---
            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
                    throw new Exception('No se pudo crear el archivo ZIP.');
                }

                $dh = opendir($workDir);
                while (($f = readdir($dh)) !== false) {
                    if ($f === '.' || $f === '..')
                        continue;
                    if ($f === $zipName)
                        continue;
                    $zip->addFile($workDir . DIRECTORY_SEPARATOR . $f, $f);
                }
                closedir($dh);
                $zip->close();
            } else {
                // Fallback si no está ZipArchive
                $filesMap = [];
                $dh = opendir($workDir);
                while (($f = readdir($dh)) !== false) {
                    if ($f === '.' || $f === '..')
                        continue;
                    if ($f === $zipName)
                        continue;
                    $filesMap[$workDir . DIRECTORY_SEPARATOR . $f] = $f;
                }
                closedir($dh);
                if (!create_plain_zip($filesMap, $zipPath)) {
                    throw new Exception('No se pudo crear el archivo ZIP usando el método alternativo nativo.');
                }
            }

            // Enviar ZIP al navegador
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . basename($zipName) . '"');
            header('Content-Length: ' . filesize($zipPath));
            readfile($zipPath);

            // limpiar temporal
            foreach (glob($workDir . DIRECTORY_SEPARATOR . '*') as $f) {
                @unlink($f);
            }
            @rmdir($workDir);
            exit;

        } catch (Exception $e) {
            $_SESSION['download_error'] = 'Error preparando descarga: ' . $e->getMessage();
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }

    // Si el estudio ya está en Orthanc, redirigimos para iniciar la descarga inmediata
    if (orthancStudyExists($studyUid)) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?action=download&study_uid=' . urlencode($studyUid) . '&download_now=1');
        exit;
    }

    // Si no existe, intentamos iniciar el retrieve y luego mostrar la página de espera
    $queryId = trim($_GET['query_id'] ?? '');
    if ($queryId === '') {
        $queryId = isset($_SESSION['last_query']['id']) ? $_SESSION['last_query']['id'] : '';
    }
    $answerIdx = trim($_GET['answer_idx'] ?? '');

    // Si no vienen en la URL, intentar recuperarlos desde la sesión (resultados de búsqueda recientes)
    if ($queryId === '' || $answerIdx === '') {
        if (isset($_SESSION['last_search_results']) && is_array($_SESSION['last_search_results'])) {
            foreach ($_SESSION['last_search_results'] as $item) {
                $tags = $item['MainDicomTags'] ?? $item['PatientMainDicomTags'] ?? [];
                $uid = $tags['StudyInstanceUID'] ?? $tags['0020,000D'] ?? null;
                if ($uid === $studyUid) {
                    $queryId = $queryId ?: ($item['_remote']['queryId'] ?? $queryId);
                    $answerIdx = $answerIdx ?: ($item['_remote']['answerIdx'] ?? $answerIdx);
                    if ($queryId !== '' && $answerIdx !== '')
                        break;
                }
            }
        }

        // Si aún no se encontró, intentar usar la última query almacenada en sesión y buscar el answerIdx que coincida
        if (($queryId === '' || $answerIdx === '') && isset($_SESSION['last_query']['id'])) {
            $tryQ = $_SESSION['last_query']['id'];
            try {
                $answers = callOrthanc('GET', '/queries/' . urlencode($tryQ) . '/answers');
                if (!empty($answers) && is_array($answers)) {
                    foreach ($answers as $idx) {
                        try {
                            $content = callOrthanc('GET', '/queries/' . urlencode($tryQ) . '/answers/' . urlencode($idx) . '/content?simplify');
                        } catch (Exception $e) {
                            continue;
                        }
                        $norm = normalizeQueryContent(is_array($content) ? $content : []);
                        $remoteUidNorm = $norm['StudyInstanceUID'] ?? null;
                        if ($remoteUidNorm === $studyUid) {
                            $queryId = $queryId !== '' ? $queryId : $tryQ;
                            $answerIdx = $answerIdx !== '' ? $answerIdx : (string)$idx;
                            break;
                        }
                    }
                }
            } catch (Exception $e) {
                // ignorar errores al recuperar answers
            }
        }

        // Si todavía no encontramos info, intentar crear una query directa por StudyInstanceUID
        if ($queryId === '' || $answerIdx === '') {
            try {
                $fallbackBody = ['StudyInstanceUID' => $studyUid];
                $newQ = callOrthanc('POST', "/modalities/$MODALITY_ID/query", [
                    'Level' => 'Study',
                    'Query' => $fallbackBody
                ]);
                $newQId = $newQ['ID'] ?? null;
                if ($newQId) {
                    $answers = callOrthanc('GET', '/queries/' . urlencode($newQId) . '/answers');
                    if (!empty($answers) && is_array($answers)) {
                        foreach ($answers as $idx) {
                            try {
                                $content = callOrthanc('GET', '/queries/' . urlencode($newQId) . '/answers/' . urlencode($idx) . '/content');
                            } catch (Exception $e) {
                                continue;
                            }
                            $norm = normalizeQueryContent(is_array($content) ? $content : []);
                            $remoteUidNorm = $norm['StudyInstanceUID'] ?? null;
                            if ($remoteUidNorm === $studyUid) {
                                $queryId = $queryId ?: $newQId;
                                $answerIdx = $answerIdx ?: $idx;
                                break;
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                // Ignorar, seguiremos con el flujo de error más abajo si no se encontró
                debug_log('fallback query by StudyInstanceUID failed: ' . $e->getMessage());
            }
        }

        if ($queryId === '' || $answerIdx === '') {
            $_SESSION['download_error'] = 'El estudio no está presente en Orthanc. No se proporcionó información para iniciar el retrieve.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }

    try {
        // Enviar AET como string puro para maximizar compatibilidad con Orthanc REST
        $retrieveResp = callOrthanc('POST', '/queries/' . urlencode($queryId) . '/answers/' . urlencode($answerIdx) . '/retrieve', $ORTHANC_AET);
        // Debug: registrar respuesta de retrieve
        debug_log('retrieve initiated for study=' . $studyUid . ' query=' . $queryId . ' answer=' . $answerIdx . ' resp=' . json_encode($retrieveResp));
    } catch (Exception $e) {
        debug_log('retrieve failed for study=' . $studyUid . ' query=' . $queryId . ' answer=' . $answerIdx . ' error=' . $e->getMessage());
        $_SESSION['download_error'] = 'No se pudo iniciar retrieve para traer el estudio: ' . $e->getMessage();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // Guardar info de job en sesión para polling desde la página de espera
    $jobId = $retrieveResp['ID'] ?? null;
    if (!isset($_SESSION['download_jobs']))
        $_SESSION['download_jobs'] = [];
    $_SESSION['download_jobs'][$studyUid] = ['jobId' => $jobId, 'queryId' => $queryId, 'answerIdx' => $answerIdx, 'started' => time(), 'retries' => 0];

    // Redirigir a la página de espera que hará polling (evita timeouts PHP)
    header('Location: ' . $_SERVER['PHP_SELF'] . '?action=wait_download&study_uid=' . urlencode($studyUid));
    exit;
}

// Página de espera que realiza polling (cliente) para comprobar llegada del estudio
if (isset($_GET['action']) && $_GET['action'] === 'wait_download' && isset($_SESSION['user'])) {
    $studyUid = trim($_GET['study_uid'] ?? '');
    if ($studyUid === '') {
        $_SESSION['download_error'] = 'Falta StudyInstanceUID para la descarga.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    ?>
    <!-- 
 ============================================================================ 
 SECCIÓN 10: INTERFAZ GRÁFICA FRONT-END (HTML Y CSS) 
 ============================================================================ 
 Plantilla de maquetación principal. Emplea Bootstrap 5 para el esquema 
 de respuesta (Responsive UI) y define un motor visual moderno (Dark mode, 
 glassmorphism y transiciones suaves). No utiliza frameworks frontend como React/Vue. 
-->
    <!DOCTYPE html>
    <html lang="es">

    <head>
        <meta charset="utf-8">
        <title>Descargando Estudio...</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="/buscador/assets/css/remoto1.css">
        <!-- 
    ============================================================================ 
    SECCIÓN 11: LÓGICA JAVASCRIPT FRONT-END 
    ============================================================================ 
    Maneja interactividad del DOM: polling sincrónico para monitorear estados 
    de descargas progresivas vía AJAX, generador de enlaces de compartición extendidos, 
    miniaturas (thumbnails) bajo demanda y control de selección múltiple (Batch).
    -->
        <script>
            const currentTheme = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            if (currentTheme === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
        </script>
        <style>
            body {
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                margin: 0;
                background-image: radial-gradient(at 0% 0%, rgba(37, 99, 235, 0.12) 0px, transparent 50%),
                    radial-gradient(at 100% 0%, rgba(16, 185, 129, 0.08) 0px, transparent 50%),
                    radial-gradient(at 100% 100%, rgba(37, 99, 235, 0.12) 0px, transparent 50%);
            }

            .wait-card {
                width: 100%;
                max-width: 500px;
                padding: 40px;
                text-align: center;
                animation: slideUp 0.5s cubic-bezier(0.16, 1, 0.3, 1);
            }

            @keyframes slideUp {
                from {
                    opacity: 0;
                    transform: translateY(20px);
                }

                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            .spinner-ring {
                width: 60px;
                height: 60px;
                margin: 0 auto 20px auto;
                border: 4px solid rgba(37, 99, 235, 0.1);
                border-left-color: var(--primary);
                border-radius: 50%;
                animation: spin 1s linear infinite;
            }

            @keyframes spin {
                100% {
                    transform: rotate(360deg);
                }
            }
        </style>
    </head>

    <body>
        <div class="card wait-card">
            <h3 class="mb-4 fw-bold">Preparando Descarga</h3>
            <div class="spinner-ring" id="wait-spinner"></div>
            <p class="mb-4 text-muted">
                Se ha iniciado la transferencia del estudio desde el servidor principal.<br>
                Este proceso puede tardar unos minutos dependiendo del tamaño del estudio.
            </p>
            <div id="status" class="alert alert-info fw-bold mb-4 shadow-sm border-0"
                style="background: rgba(37, 99, 235, 0.1); color: var(--text-main);">Iniciando conexión...</div>
            <div>
                <button id="cancel" class="btn btn-outline-secondary rounded-pill px-4 fw-medium">Cancelar</button>
            </div>
            <div class="mt-4 small text-muted" style="opacity: 0.8">
                Si detectas errores, vuelve atrás y prueba la opción de <strong>Visualizar</strong> primero.
            </div>
        </div>
        <!-- 
    ============================================================================ 
    SECCIÓN 11: LÓGICA JAVASCRIPT FRONT-END 
    ============================================================================ 
    Maneja interactividad del DOM: polling sincrónico para monitorear estados 
    de descargas progresivas vía AJAX, generador de enlaces de compartición extendidos, 
    miniaturas (thumbnails) bajo demanda y control de selección múltiple (Batch).
    -->
        <script>
            const study = <?php echo json_encode($studyUid); ?>;
            let stopped = false;
            document.getElementById('cancel').addEventListener('click', () => { stopped = true; window.location = '<?php echo $_SERVER['PHP_SELF']; ?>'; });
            async function check() {
                if (stopped) return;
                try {
                    const r = await fetch('<?php echo $_SERVER['PHP_SELF']; ?>?action=check_download&study_uid=' + encodeURIComponent(study) + '&_=' + Date.now());
                    const j = await r.json();
                    document.getElementById('status').innerText = j.message || j.status;
                    if (j.status === 'ready') {
                        window.location = '<?php echo $_SERVER['PHP_SELF']; ?>?action=download&study_uid=' + encodeURIComponent(study) + '&download_now=1';
                        return;
                    }
                    if (j.status === 'failed') {
                        document.getElementById('wait-spinner').style.display = 'none';
                        document.getElementById('status').className = 'alert alert-danger fw-bold mb-4 text-danger';
                        return;
                    }
                } catch (e) {
                    console.error(e);
                }
                setTimeout(check, 3000);
            }
            check();
        </script>
    </body>

    </html>
    <?php
    // registrar inicio de wait page
    debug_log('entered wait_download page for study=' . $studyUid . ' session_job=' . json_encode($_SESSION['download_jobs'][$studyUid] ?? null));
    exit;
}

/**
 * ============================================================================
 * SECCIÓN 10: ENDPOINT AJAX DE MONITOREO DEL "RETRIEVE" (POLLING)
 * ============================================================================
 * Este pequeño servicio es consultado cada par de segundos por las pantallas de 
 * de "Espera/Cargando". Analiza el estado en que se ubica el proceso asíncrono
 * (Job) de transferencia DICOM en Orthanc y comunica si falló temporalmente, 
 * o si ya está listo para mostrar visualmente / descargar imágenes.
 */
// Endpoint ligero para que la página de espera consulte el estado
if (isset($_GET['action']) && $_GET['action'] === 'check_download' && isset($_SESSION['user'])) {
    header('Content-Type: application/json');
    $studyUid = trim($_GET['study_uid'] ?? '');
    if ($studyUid === '') {
        debug_log('check_download called with empty studyUid');
        echo json_encode(['status' => 'failed', 'message' => 'Falta StudyInstanceUID']);
        exit;
    }

    if (orthancStudyExists($studyUid)) {
        debug_log('check_download: study present in Orthanc: ' . $studyUid);
        echo json_encode(['status' => 'ready', 'message' => 'Estudio presente en Orthanc. Preparando descarga...']);
        exit;
    }

    // Si recientemente disparamos un C-MOVE (retrieve) y tenemos una respuesta registrada, inspeccionar el estado actual
    $lastRetrieve = $_SESSION['last_retrieve_resp'] ?? null;
    if (is_array($lastRetrieve)) {
        $gotInstances = false;
        $hadError = false;
        if (!empty($lastRetrieve['Details']) && is_array($lastRetrieve['Details'])) {
            foreach ($lastRetrieve['Details'] as $d) {
                if (!empty($d['ReceivedInstancesIds'])) {
                    $gotInstances = true;
                    break;
                }
                if (isset($d['DimseErrorStatus']) && (int) $d['DimseErrorStatus'] !== 0) {
                    $hadError = true;
                }
            }
        }
        if ($gotInstances) {
            debug_log('check_download: last_retrieve_resp indicates instances received for study=' . $studyUid);
            echo json_encode(['status' => 'ready', 'message' => 'Estudio transferido a Orthanc. Preparando descarga...']);
            exit;
        }
        if ($hadError) {
            debug_log('check_download: last_retrieve_resp indicates DIMSE error for study=' . $studyUid . ' resp=' . json_encode($lastRetrieve));
            echo json_encode(['status' => 'failed', 'message' => 'El retrieve devolvió error DIMSE. Revisa configuración de modalidades/AETs.']);
            exit;
        }
        // Si no hay instancias descargadas aún pero tampoco hay errores, se continúa con el polling periódico notificando al usuario
        debug_log('check_download: last_retrieve_resp present but no instances for study=' . $studyUid . ' resp=' . json_encode($lastRetrieve));
    }

    $jobInfo = $_SESSION['download_jobs'][$studyUid] ?? null;
    debug_log('check_download for study=' . $studyUid . ' jobInfo=' . json_encode($jobInfo));

    // Si no hay jobInfo, intentar recuperar query/answer desde last_search_results y lanzar retrieve automáticamente
    if (!$jobInfo) {
        $foundQ = '';
        $foundA = '';
        if (isset($_SESSION['last_search_results']) && is_array($_SESSION['last_search_results'])) {
            foreach ($_SESSION['last_search_results'] as $item) {
                $tags = $item['MainDicomTags'] ?? $item['PatientMainDicomTags'] ?? [];
                $uid = $tags['StudyInstanceUID'] ?? $tags['0020,000D'] ?? null;
                if ($uid === $studyUid && isset($item['_remote'])) {
                    $foundQ = $item['_remote']['queryId'] ?? '';
                    $foundA = $item['_remote']['answerIdx'] ?? '';
                    break;
                }
            }
        }

        if (($foundQ === '' || $foundA === '') && isset($_SESSION['last_query']['id'])) {
            $tryQ = $_SESSION['last_query']['id'];
            try {
                $answers = callOrthanc('GET', '/queries/' . urlencode($tryQ) . '/answers');
                if (!empty($answers) && is_array($answers)) {
                    foreach ($answers as $idx) {
                        try {
                            $content = callOrthanc('GET', '/queries/' . urlencode($tryQ) . '/answers/' . urlencode($idx) . '/content?simplify');
                        } catch (Exception $e) {
                            continue;
                        }
                        $norm = normalizeQueryContent(is_array($content) ? $content : []);
                        $remoteUidNorm = $norm['StudyInstanceUID'] ?? null;
                        if ($remoteUidNorm === $studyUid) {
                            $foundQ = $foundQ !== '' ? $foundQ : $tryQ;
                            $foundA = $foundA !== '' ? $foundA : (string)$idx;
                            break;
                        }
                    }
                }
            } catch (Exception $e) {
                debug_log('check_download: error scanning last_query answers: ' . $e->getMessage());
            }
        }

        if ($foundQ !== '' && $foundA !== '') {
            try {
                debug_log('check_download: auto-initiating retrieve for study=' . $studyUid . ' q=' . $foundQ . ' a=' . $foundA);
                $resp = callOrthanc('POST', '/queries/' . urlencode($foundQ) . '/answers/' . urlencode($foundA) . '/retrieve', $ORTHANC_AET);
                $newJobId = $resp['ID'] ?? null;
                $_SESSION['download_jobs'][$studyUid] = ['jobId' => $newJobId, 'queryId' => $foundQ, 'answerIdx' => $foundA, 'started' => time(), 'retries' => 0];
                debug_log('check_download: auto retrieve resp=' . json_encode($resp));
                $_SESSION['last_retrieve_resp'] = $resp;
                echo json_encode(['status' => 'pending', 'message' => 'Retrieve iniciado automáticamente. Esperando llegada del estudio...']);
                exit;
            } catch (Exception $e) {
                debug_log('check_download: auto retrieve failed: ' . $e->getMessage());
                echo json_encode(['status' => 'pending', 'message' => 'Error iniciando retrieve automáticamente; revisa logs.']);
                exit;
            }
        }
    }
    if ($jobInfo) {
        $jobId = $jobInfo['jobId'] ?? null;
        $qId = $jobInfo['queryId'] ?? null;
        $aIdx = $jobInfo['answerIdx'] ?? null;
        $retries = $jobInfo['retries'] ?? 0;

        // Si hay jobId, consultar estado del job en Orthanc (/jobs/)
        if ($jobId) {
            try {
                try {
                    $task = callOrthanc('GET', '/jobs/' . urlencode($jobId));
                } catch (Exception $e) {
                    $task = callOrthanc('GET', '/tasks/' . urlencode($jobId));
                }
                $state = $task['State'] ?? $task['Status'] ?? $task['status'] ?? null;
                $progress = intval($task['Progress'] ?? 0);
                if ($state !== null) {
                    $s = strtolower((string) $state);
                    if ($s === 'success') {
                        debug_log('check_download: job succeeded job=' . $jobId);
                        echo json_encode(['status' => 'ready', 'message' => 'Estudio transferido con éxito. Preparando descarga...']);
                        exit;
                    }
                    if (strpos($s, 'failed') !== false || strpos($s, 'error') !== false) {
                        debug_log('check_download: job failed job=' . $jobId . ' error=' . ($task['ErrorDescription'] ?? ''));
                        echo json_encode(['status' => 'failed', 'message' => 'El retrieve falló en ClearCanvas: ' . ($task['ErrorDescription'] ?? $state)]);
                        exit;
                    }
                    debug_log('check_download: job pending job=' . $jobId . ' progress=' . $progress);
                    $pct = max(10, min(95, $progress));
                    echo json_encode(['status' => 'pending', 'progress' => $pct, 'message' => 'Retrieve en progreso (' . $pct . '%)...']);
                    exit;
                }
            } catch (Exception $e) {
                debug_log('check_download: error querying job ' . $jobId . ' error=' . $e->getMessage());
                echo json_encode(['status' => 'pending', 'message' => 'Retrieve iniciado. Esperando llegada del estudio...']);
                exit;
            }

            // Comprobar soporte necesario para generar ZIP/JPG en el servidor
            $downloadSupported = true;
            $downloadSupportMessage = '';
            if (!extension_loaded('gd') || !function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
                $downloadSupported = false;
                $downloadSupportMessage .= 'Extensión GD (imagecreatefromstring/imagejpeg) no disponible.';
            }
            if (!class_exists('ZipArchive')) {
                $downloadSupported = false;
                if ($downloadSupportMessage !== '')
                    $downloadSupportMessage .= ' ';
                $downloadSupportMessage .= 'ZipArchive no disponible.';
            }

        }

        // Si no hay jobId pero sí query info, intentar reintentar el retrieve un número limitado de veces
        if (!$jobId && $qId && $aIdx) {
            $maxRetries = 3;
            if ($retries < $maxRetries) {
                try {
                    debug_log('check_download: re-triggering retrieve for study=' . $studyUid . ' attempt=' . ($retries + 1));
                    $resp = callOrthanc('POST', '/queries/' . urlencode($qId) . '/answers/' . urlencode($aIdx) . '/retrieve', $ORTHANC_AET);
                    $newJobId = $resp['ID'] ?? null;
                    $_SESSION['download_jobs'][$studyUid]['retries'] = $retries + 1;
                    $_SESSION['last_retrieve_resp'] = $resp;
                    if ($newJobId) {
                        $_SESSION['download_jobs'][$studyUid]['jobId'] = $newJobId;
                        debug_log('check_download: retrieve re-trigger returned job=' . $newJobId);
                    } else {
                        debug_log('check_download: retrieve re-trigger returned no job id; resp=' . json_encode($resp));
                    }
                    echo json_encode(['status' => 'pending', 'message' => 'Reintentando retrieve (' . ($_SESSION['download_jobs'][$studyUid]['retries']) . '/' . $maxRetries . ')']);
                    exit;
                } catch (Exception $e) {
                    $_SESSION['download_jobs'][$studyUid]['retries'] = $retries + 1;
                    debug_log('check_download: retrieve re-trigger failed error=' . $e->getMessage());
                    echo json_encode(['status' => 'pending', 'message' => 'Error iniciando retrieve; reintentando... (' . ($_SESSION['download_jobs'][$studyUid]['retries']) . '/' . $maxRetries . ')']);
                    exit;
                }
            } else {
                debug_log('check_download: max retries reached for study=' . $studyUid);
                echo json_encode(['status' => 'pending', 'message' => 'Reintentos de retrieve agotados. Intenta de nuevo más tarde o usa "Visualizar" primero.']);
                exit;
            }
        }
    }

    echo json_encode(['status' => 'pending', 'message' => 'Retrieve iniciado. Esperando llegada del estudio...']);
    exit;
}

// Diagnostics endpoints removed (debug helpers were temporary)

// Endpoint temporal para mostrar el log de depuración y estado de jobs (solo para usuarios autenticados)
if (isset($_GET['action']) && $_GET['action'] === 'show_debug' && isset($_SESSION['user'])) {
    $path = rtrim(sys_get_temp_dir(), "\/") . DIRECTORY_SEPARATOR . 'remoto_debug.log';
    echo '</body></html>';
    exit;
}

// Comprobar soporte necesario para generar ZIP/JPG en el servidor (para el HTML principal)
$hasGD = extension_loaded('gd') && function_exists('imagecreatefromstring') && function_exists('imagejpeg');
$hasZipArchive = class_exists('ZipArchive');
$tmpDir = sys_get_temp_dir();
$tmpWritable = is_dir($tmpDir) && is_writable($tmpDir);
$downloadSupportMessage = '';
if (!$hasGD)
    $downloadSupportMessage .= 'Extensión GD no disponible.';
if (!$hasZipArchive) {
    if ($downloadSupportMessage !== '')
        $downloadSupportMessage .= ' ';
    $downloadSupportMessage .= 'ZipArchive no disponible.';
}
if (!$tmpWritable) {
    if ($downloadSupportMessage !== '')
        $downloadSupportMessage .= ' ';
    $downloadSupportMessage .= 'Directorio temporal no escribible: ' . $tmpDir . '.';
}
// Considerar que la descarga es factible siempre que exista acceso de escritura a memoria/carpeta temporal 
// (se intentará usar sistemas integrados si GD o ZipArchive no están pre-compilados en PHP)
$downloadSupported = $tmpWritable;

if (isset($_GET['ajax'])) {
    ob_start();
}
?>
<!-- 
===============================================================================
SECCIÓN 11: INTERFAZ GRÁFICA FRONT-END (HTML, CSS Y VISTAS)
===============================================================================
A partir de aquí, el archivo PHP implementa toda la interfaz de usuario, 
diseño y experiencia (UX). Usa la directiva "Glassmorphism", soporte para
temas claros/oscuros (Dark Mode) y grillas responsivas.
-->
<!-- 
 ============================================================================ 
 SECCIÓN 10: INTERFAZ GRÁFICA FRONT-END (HTML Y CSS) 
 ============================================================================ 
 Plantilla de maquetación principal. Emplea Bootstrap 5 para el esquema 
 de respuesta (Responsive UI) y define un motor visual moderno (Dark mode, 
 glassmorphism y transiciones suaves). No utiliza frameworks frontend como React/Vue. 
-->
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Visor de estudios (Canvas ➜ Orthanc)</title>
    <link rel="icon" href="favicon.ico">
    <link rel="shortcut icon" href="favicon.ico">
    <link rel="apple-touch-icon" href="favicon.ico">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <!-- Font and Flatpickr -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/airbnb.css">

    <style>
        /* New Styles Injected */
        :root {
            --primary: #2563eb;
            --primary-light: #eff6ff;
            --primary-hover: #1d4ed8;
            --success: #10b981;
            --error: #ef4444;
            --bg-color: #f8fafc;
            --card-bg: rgba(255, 255, 255, 0.75);
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border-radius: 20px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body {
            font-family: 'Outfit', system-ui, -apple-system, sans-serif;
            margin: 0;
            padding: 40px 16px;
            background: var(--bg-color);
            background-image:
                radial-gradient(at 0% 0%, rgba(37, 99, 235, 0.12) 0px, transparent 50%),
                radial-gradient(at 100% 0%, rgba(16, 185, 129, 0.08) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(37, 99, 235, 0.12) 0px, transparent 50%);
            background-attachment: fixed;
            color: var(--text-main);
            min-height: 100vh;
        }

        .layout {
            max-width: 1080px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        h1 {
            margin-top: 0;
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--text-main);
            letter-spacing: -0.02em;
            display: flex;
            align-items: center;
            gap: 12px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            font-size: 1rem;
        }

        .card {
            background: var(--card-bg);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border-radius: var(--border-radius);
            padding: 28px;
            margin-bottom: 30px;
            box-shadow: 0 10px 40px -10px rgba(0, 0, 0, 0.08), inset 0 1px 0 rgba(255, 255, 255, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.8);
            transition: var(--transition);
            position: relative;
        }

        /* Stacking context fix for dropdowns */
        .card:nth-of-type(1) {
            z-index: 40;
        }

        .card:nth-of-type(2) {
            z-index: 30;
        }

        .card:nth-of-type(3) {
            z-index: 20;
        }

        .card:nth-of-type(4) {
            z-index: 10;
        }

        .card:hover {
            box-shadow: 0 20px 40px -10px rgba(37, 99, 235, 0.12), inset 0 1px 0 rgba(255, 255, 255, 0.8);
            transform: translateY(-2px);
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 18px;
            margin-bottom: 24px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.04);
            padding-bottom: 20px;
        }

        .card-header-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 52px;
            height: 52px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--primary-light), #ffffff);
            color: var(--primary);
            font-size: 26px;
            box-shadow: 0 8px 16px rgba(37, 99, 235, 0.12), inset 0 2px 4px rgba(255, 255, 255, 1);
        }

        h2,
        h3 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--text-main);
            letter-spacing: -0.01em;
        }

        .status {
            margin-top: 12px;
            margin-bottom: 20px;
            padding: 14px 18px;
            border-radius: 14px;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            animation: slideIn 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-15px) scale(0.98);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .status-ok,
        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: #065f46;
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .status-error,
        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            color: #991b1b;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        label,
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-main);
            font-size: 0.95rem;
        }

        input[type="text"],
        input[type="date"],
        input[type="password"],
        input[type="search"],
        .form-control {
            width: 100%;
            padding: 14px 18px;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            background: rgba(255, 255, 255, 0.9);
            font-size: 15px;
            font-family: inherit;
            font-weight: 500;
            transition: var(--transition);
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.01);
        }

        input:focus,
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15), inset 0 2px 4px rgba(0, 0, 0, 0.01);
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 20px;
            align-items: center;
        }

        .btn {
            display: inline-flex;
            padding: 12px 24px;
            border: none;
            border-radius: 14px;
            text-decoration: none;
            font-family: inherit;
            font-weight: 600;
            font-size: 15px;
            align-items: center;
            justify-content: center;
            gap: 10px;
            cursor: pointer;
            transition: var(--transition);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-hover));
            color: #ffffff;
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.2);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(37, 99, 235, 0.35), inset 0 1px 0 rgba(255, 255, 255, 0.2);
            color: #ffffff;
        }

        .btn-secondary,
        .btn-outline-secondary {
            background: #ffffff;
            color: var(--text-main);
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.04);
        }

        .btn-secondary:hover,
        .btn-outline-secondary:hover {
            background: #f8fafc;
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.08);
            border-color: #cbd5e1;
            color: var(--text-main);
        }

        .btn-outline-success {
            color: var(--success);
            border: 1px solid var(--success);
            background: transparent;
            box-shadow: 0 4px 10px rgba(16, 185, 129, 0.05);
        }

        .btn-outline-success:hover {
            background: var(--success);
            color: #ffffff;
            box-shadow: 0 6px 15px rgba(16, 185, 129, 0.25);
            transform: translateY(-2px);
        }

        .btn-outline-primary {
            color: var(--primary);
            border: 1px solid var(--primary);
            background: transparent;
        }

        .btn-outline-primary:hover {
            background: var(--primary);
            color: #ffffff;
            box-shadow: 0 6px 15px rgba(37, 99, 235, 0.25);
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 14px;
            border-radius: 12px;
        }

        .hint {
            font-size: 0.95rem;
            color: var(--text-muted);
            margin-top: 8px;
            line-height: 1.5;
            font-weight: 400;
        }

        .table-responsive {
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.5);
            padding: 4px;
            border: 1px solid rgba(226, 232, 240, 0.6);
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
            margin-top: 4px;
            font-size: 14px;
        }

        th,
        td {
            padding: 16px 20px;
            text-align: left;
        }

        th {
            font-weight: 600;
            color: var(--text-muted);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            padding-top: 10px;
            padding-bottom: 4px;
        }

        tbody tr {
            transition: var(--transition);
            background: #ffffff;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.02);
        }

        tbody tr:hover {
            transform: scale(1.005) translateY(-2px);
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.08);
            z-index: 10;
            position: relative;
        }

        tbody td:first-child {
            border-top-left-radius: 16px;
            border-bottom-left-radius: 16px;
            border-left: 2px solid transparent;
        }

        tbody td:last-child {
            border-top-right-radius: 16px;
            border-bottom-right-radius: 16px;
        }

        tbody tr:hover td:first-child {
            border-left-color: var(--primary);
        }

        .modality-badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            border-radius: 20px;
            background: var(--primary-light);
            color: var(--primary-hover);
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.03em;
            box-shadow: inset 0 0 0 1px rgba(37, 99, 235, 0.1);
        }

        .login-error {
            margin-top: 16px;
            padding: 14px 18px;
            border-radius: 14px;
            background: rgba(239, 68, 68, 0.1);
            color: #991b1b;
            font-size: 0.95rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modality-toggle {
            text-align: left;
            cursor: pointer;
            padding: 14px 18px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 500;
            transition: var(--transition);
        }

        .modality-toggle:hover {
            background: #ffffff;
            border-color: #cbd5e1;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04);
        }

        .modality-toggle:focus {
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15);
            border-color: var(--primary);
        }

        .modality-options {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(226, 232, 240, 0.8);
            border-radius: 20px;
            max-height: 320px;
            overflow-y: auto;
            box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.15);
            padding: 16px;
            margin-top: 10px;
        }

        @media (max-width: 768px) {
            body {
                padding: 20px 10px;
            }

            .card {
                padding: 20px;
                margin-bottom: 20px;
            }

            .table-responsive {
                background: transparent;
                border: none;
                padding: 0;
            }

            .table-responsive table thead {
                display: none;
            }

            .table-responsive tbody tr {
                display: flex;
                flex-direction: column;
                background: #ffffff;
                border: 1px solid #e2e8f0;
                border-radius: 16px;
                margin-bottom: 16px;
                padding: 16px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            }

            .table-responsive tbody td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 10px 4px;
                border-bottom: 1px solid #f1f5f9;
                border-radius: 0 !important;
            }

            .table-responsive tbody td:last-child {
                border-bottom: none;
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
                margin-top: 10px;
            }

            .table-responsive tbody td[data-label]:before {
                content: attr(data-label);
                font-weight: 600;
                color: var(--text-muted);
                font-size: 0.85rem;
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }
        }

        .flatpickr-calendar {
            font-family: 'Outfit', sans-serif;
            border-radius: 20px;
            box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.15);
            border: 1px solid rgba(226, 232, 240, 0.8);
            padding: 5px;
        }

        .bg-shape {
            position: fixed;
            border-radius: 50%;
            filter: blur(80px);
            z-index: 0;
            opacity: 0.5;
            pointer-events: none;
        }

        .shape1 {
            top: -10%;
            left: -10%;
            width: 40vw;
            height: 40vw;
            background: rgba(37, 99, 235, 0.15);
        }

        .shape2 {
            bottom: -10%;
            right: -10%;
            width: 50vw;
            height: 50vw;
            background: rgba(16, 185, 129, 0.1);
        }

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

        /* --- Estilos CSS Adicionales para Características Premium (Temas, Modo Oscuro, Layouts) --- */
        [data-theme="dark"] {
            --bg-color: #0f172a;
            --card-bg: rgba(30, 41, 59, 1);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --bs-secondary-color: #94a3b8;
            --primary-light: #1e3a8a;
        }

        [data-theme="dark"] .text-muted {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .bg-shape {
            opacity: 0.15;
        }

        [data-theme="dark"] .card {
            border-color: rgba(255, 255, 255, 0.05);
        }

        [data-theme="dark"] tbody tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        [data-theme="dark"] .modality-options {
            background: rgba(30, 41, 59, 0.95);
        }

        [data-theme="dark"] .modality-toggle {
            background: rgba(30, 41, 59, 0.9);
            border-color: #334155;
        }

        [data-theme="dark"] .navbar {
            background-color: rgba(30, 41, 59, 0.8) !important;
            color: #f8fafc;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        [data-theme="dark"] .navbar-text {
            color: #f8fafc !important;
        }

        [data-theme="dark"] input.form-control,
        [data-theme="dark"] input[type="text"],
        [data-theme="dark"] input[type="password"],
        [data-theme="dark"] input[type="date"] {
            background: rgba(15, 23, 42, 0.9);
            color: #fff;
            border-color: #334155;
        }

        #loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(15, 23, 42, 0.7);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 99999;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }

        #loading-overlay.active {
            opacity: 1;
            pointer-events: auto;
        }

        .spinner-ring {
            width: 60px;
            height: 60px;
            border: 4px solid rgba(255, 255, 255, 0.2);
            border-top-color: #3b82f6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 20px;
        }

        @keyframes spin {
            100% {
                transform: rotate(360deg);
            }
        }

        .mod-CT {
            background: rgba(59, 130, 246, 0.15);
            color: #2563eb !important;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }

        .mod-MR {
            background: rgba(139, 92, 246, 0.15);
            color: #7c3aed !important;
            border: 1px solid rgba(139, 92, 246, 0.3);
        }

        .mod-CR,
        .mod-DX {
            background: rgba(16, 185, 129, 0.15);
            color: #059669 !important;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .mod-US {
            background: rgba(245, 158, 11, 0.15);
            color: #d97706 !important;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .mod-MG {
            background: rgba(236, 72, 153, 0.15);
            color: #db2777 !important;
            border: 1px solid rgba(236, 72, 153, 0.3);
        }

        [data-theme="dark"] .mod-CT {
            color: #93c5fd !important;
        }

        [data-theme="dark"] .mod-MR {
            color: #c4b5fd !important;
        }

        [data-theme="dark"] .mod-CR,
        [data-theme="dark"] .mod-DX {
            color: #6ee7b7 !important;
        }

        [data-theme="dark"] .mod-US {
            color: #fcd34d !important;
        }

        [data-theme="dark"] .mod-MG {
            color: #f9a8d4 !important;
        }

        /* Ajustes visuales de compatibilidad de inputs y layouts para el Modo Oscuro */
        [data-theme="dark"] .card {
            background: var(--card-bg);
            color: var(--text-main);
        }

        [data-theme="dark"] .form-floating>label {
            color: var(--text-muted);
        }

        [data-theme="dark"] .form-floating>.form-control:focus~label,
        [data-theme="dark"] .form-floating>.form-control:not(:placeholder-shown)~label {
            color: var(--primary);
            background: transparent;
        }

        [data-theme="dark"] .btn-outline-secondary {
            background: rgba(30, 41, 59, 0.9);
            border-color: #334155;
            color: var(--text-main);
        }

        [data-theme="dark"] .btn-outline-secondary:hover {
            background: #334155;
            color: #fff;
        }

        [data-theme="dark"] .btn-light {
            background: rgba(30, 41, 59, 0.9);
            border-color: #334155;
            color: var(--text-main);
        }

        [data-theme="dark"] .btn-secondary,
        [data-theme="dark"] .btn-outline-success {
            background: rgba(30, 41, 59, 0.9);
            color: var(--text-main);
        }

        [data-theme="dark"] .btn-outline-success {
            border-color: #10b981;
            color: #10b981;
        }

        [data-theme="dark"] .btn-outline-success:hover {
            background: #10b981;
            color: #fff;
        }

        [data-theme="dark"] .btn-secondary {
            border-color: #64748b;
        }

        [data-theme="dark"] .table-responsive {
            background: var(--card-bg) !important;
            border-color: rgba(255, 255, 255, 0.05);
        }

        [data-theme="dark"] .table {
            --bs-table-bg: transparent;
            --bs-table-color: var(--text-main);
            --bs-table-striped-bg: rgba(255, 255, 255, 0.02);
            color: var(--text-main);
        }

        [data-theme="dark"] .table thead th {
            background: rgba(15, 23, 42, 0.8);
            color: var(--text-muted) !important;
            border-bottom: 2px solid #334155;
        }

        [data-theme="dark"] tbody tr {
            background: transparent !important;
            color: var(--text-main) !important;
        }

        [data-theme="dark"] tbody tr td {
            color: var(--text-main) !important;
            border-bottom-color: rgba(255, 255, 255, 0.05) !important;
        }

        [data-theme="dark"] tbody tr:hover {
            background: rgba(255, 255, 255, 0.05) !important;
        }

        @media (max-width: 768px) {
            [data-theme="dark"] .table-responsive tbody tr {
                background: rgba(30, 41, 59, 0.95);
                border-color: #334155;
            }

            [data-theme="dark"] .table-responsive tbody td {
                border-bottom-color: rgba(255, 255, 255, 0.05);
            }
        }

        /* --- Dark Mode para Componentes Premium Nuevos --- */
        [data-theme="dark"] #offcanvas-modalities {
            background: rgba(30, 41, 59, 0.95) !important;
            border-left-color: rgba(255, 255, 255, 0.1) !important;
        }

        [data-theme="dark"] #offcanvas-modalities .text-dark,
        [data-theme="dark"] #offcanvasModalitiesLabel {
            color: #f1f5f9 !important;
        }

        [data-theme="dark"] #offcanvas-modalities li {
            border-color: rgba(255, 255, 255, 0.1) !important;
        }

        [data-theme="dark"] #offcanvas-modalities .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
        }

        [data-theme="dark"] #batch-download-bar {
            background: rgba(30, 41, 59, 0.95) !important;
            border-top-color: rgba(255, 255, 255, 0.1) !important;
        }

        [data-theme="dark"] #batch-download-bar .text-dark {
            color: #f1f5f9 !important;
        }

        [data-theme="dark"] #batch-download-bar .text-muted {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .skeleton-box {
            background-color: rgba(255, 255, 255, 0.08) !important;
        }

        [data-theme="dark"] .skeleton-box::after {
            background-image: linear-gradient(90deg, rgba(255, 255, 255, 0) 0, rgba(255, 255, 255, 0.1) 20%, rgba(255, 255, 255, 0.2) 60%, rgba(255, 255, 255, 0)) !important;
        }
    </style>
</head>

<body>
    <div class="bg-shape shape1"></div>
    <div class="bg-shape shape2"></div>
    <div class="page-container">
        <div class="logo-container">
            <img src="/buscador/logo.png" alt="Logo">
        </div>
        <div class="layout" style="margin: 0; width: 100%;">
            <link rel="stylesheet" href="/buscador/assets/css/remoto1.css">

            <?php if (!isset($_SESSION['user'])): ?>

                <h1>Acceso al visor de estudios</h1>
                <div class="card">
                    <div class="card-header">
                        <div class="card-header-icon">🔐</div>
                        <div>
                            <h2 style="margin:0;font-size:1.05rem;">Iniciar sesión</h2>
                            <p class="hint">Solo personal autorizado puede acceder al visor de estudios.</p>
                        </div>
                    </div>

                    <form method="post" onsubmit="showSpinner()">
                        <input type="hidden" name="action" value="login">
                        <div class="mb-3">
                            <label class="form-label" for="username">Usuario</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="password">Contraseña</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>

                        <div class="d-flex gap-2 mt-3">
                            <button type="submit" class="btn btn-primary">
                                <span>➡️</span><span>Ingresar</span>
                            </button>
                        </div>

                        <?php if ($loginError !== ''): ?>
                            <div class="alert alert-danger mt-3">
                                <?php echo htmlspecialchars($loginError, ENT_QUOTES); ?>
                            </div>
                            <?php
                        endif; ?>
                    </form>
                </div>

                <?php
            else: ?>

                <nav class="navbar navbar-light bg-light mb-4 p-2 rounded">
                    <span class="navbar-text">Conectado como
                        <strong><?php echo htmlspecialchars($_SESSION['user'], ENT_QUOTES); ?></strong></span>
                    <div class="d-flex align-items-center"><button type="button" id="theme-toggle"
                            class="btn btn-outline-secondary btn-sm me-2" title="Cambiar tema">🌙</button>
                        <a href="?logout=1" class="btn btn-outline-danger btn-sm"><i class="bi bi-box-arrow-right"></i>
                            Cerrar sesión</a>
                    </div>
                </nav>

                <h1 class="mb-4"><span style="font-size:1.5em;">🩻</span> Visor de estudios (Canvas ➜ Orthanc)</h1>
                <div class="container">
                    <div class="row justify-content-center">
                        <div class="col-12">



                            <div class="card">
                                <div class="card-header">
                                    <div class="card-header-icon">🔎</div>
                                    <div>
                                        <h2 style="margin:0;font-size:1.05rem;">Buscar y cargar estudios por CEDULA de
                                            paciente</h2>
                                        <p class="hint">
                                            Primero se buscan estudios en Orthanc. Si falta algo en el rango de fechas
                                            indicado,
                                            se trae automáticamente desde Canvas.
                                        </p>
                                    </div>
                                </div>

                                <form method="get">

                                    <div class="row g-2 align-items-end">
                                        <div class="col-12 col-md-6">
                                            <div class="form-floating mb-2">
                                                <input type="text" class="form-control" id="patient_id" name="patient_id"
                                                    placeholder="ID de paciente"
                                                    value="<?php echo htmlspecialchars($patientIdValue, ENT_QUOTES); ?>">
                                                <label for="patient_id">ID de paciente (PatientID)</label>
                                            </div>
                                        </div>

                                        <div class="col-6 col-md-3">
                                            <div class="form-floating mb-2">
                                                <input type="date" class="form-control" id="date_from" name="date_from"
                                                    value="<?php echo htmlspecialchars($dateFromValue, ENT_QUOTES); ?>">
                                                <label for="date_from">Desde</label>
                                            </div>
                                        </div>

                                        <div class="col-6 col-md-3">
                                            <div class="form-floating mb-2">
                                                <input type="date" class="form-control" id="date_to" name="date_to"
                                                    value="<?php echo htmlspecialchars($dateToValue, ENT_QUOTES); ?>">
                                                <label for="date_to">Hasta</label>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="date-presets mb-2">
                                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                                    id="preset-today">Hoy</button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                                    id="preset-yesterday">Ayer</button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                                    id="preset-7">Últimos 7 días</button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                                    id="preset-30">Últimos 30 días</button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                                    id="preset-clear">Limpiar</button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-12 col-md-6">
                                            <label for="modalities" class="form-label">Modalidades (opcional)</label>
                                            <div class="modality-filter">
                                                <div class="dropdown" data-bs-auto-close="outside">
                                                    <button type="button" id="modality-toggle"
                                                        class="btn btn-outline-secondary dropdown-toggle w-100 text-start"
                                                        data-bs-toggle="dropdown" aria-expanded="false">Seleccionar
                                                        modalidades</button>
                                                    <div id="modality-options" class="dropdown-menu p-3 modality-options"
                                                        aria-labelledby="modality-toggle">
                                                        <div class="modality-search">
                                                            <input type="search" id="modality-filter"
                                                                class="form-control form-control-sm"
                                                                placeholder="Buscar modalidad...">
                                                        </div>
                                                        <div class="modality-controls">
                                                            <button type="button" id="modality-select-all"
                                                                class="btn btn-sm btn-outline-primary">Seleccionar
                                                                todo</button>
                                                            <button type="button" id="modality-clear"
                                                                class="btn btn-sm btn-outline-secondary">Limpiar</button>
                                                            <button type="button" id="modality-view-all"
                                                                class="btn btn-sm btn-outline-info">Ver todos</button>
                                                        </div>
                                                        <?php foreach ($allModalities as $mod): ?>
                                                            <div class="modality-option">
                                                                <?php $safeMod = htmlspecialchars($mod, ENT_QUOTES);
                                                                $isSel = in_array($mod, $selectedModalities); ?>
                                                                <button type="button"
                                                                    class="btn btn-sm btn-light w-100 text-start modality-item<?php echo $isSel ? ' selected' : ''; ?>"
                                                                    data-value="<?php echo $safeMod; ?>"><?php echo $safeMod; ?></button>
                                                                <?php if ($isSel): ?>
                                                                    <input type="hidden" name="modalities[]"
                                                                        value="<?php echo $safeMod; ?>">
                                                                    <?php
                                                                endif; ?>
                                                            </div>
                                                            <?php
                                                        endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                            </div>


                            <div class="actions mt-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-arrow-repeat me-1"></i>
                                    <span>Cargar / actualizar estudios</span>
                                </button>
                                <span class="hint">Solo se cargarán desde Canvas los estudios que aún no existan en Orthanc
                                    para ese rango.</span>
                            </div>
                            </form>
                        </div>

                        <div class="card">
                            <div class="results-inner">
                                <?php if (isset($_GET['ajax'])) {
                                    ob_end_clean();
                                    ob_start();
                                } ?>
                                <div class="card-header d-flex align-items-center">
                                    <div class="card-header-icon">🩻</div>
                                    <div>
                                        <h3 style="margin:0;font-size:1.0rem;">Estudios encontrados en Orthanc para este
                                            paciente</h3>
                                        <p class="hint">Haz clic en “Ver en OHIF” para abrir el estudio en el visor web.</p>
                                    </div>
                                    <div class="ms-auto">
                                        <form method="get" id="perPageForm" class="d-flex align-items-center">
                                            <input type="hidden" name="patient_id"
                                                value="<?php echo htmlspecialchars($patientIdValue, ENT_QUOTES); ?>">
                                            <input type="hidden" name="date_from"
                                                value="<?php echo htmlspecialchars($dateFromValue, ENT_QUOTES); ?>">
                                            <input type="hidden" name="date_to"
                                                value="<?php echo htmlspecialchars($dateToValue, ENT_QUOTES); ?>">
                                            <input type="hidden" name="sort"
                                                value="<?php echo htmlspecialchars($sort, ENT_QUOTES); ?>">
                                            <input type="hidden" name="page" value="1">
                                            <?php if (!empty($forceRemote)): ?>
                                                <input type="hidden" name="force_remote" value="1">
                                                <?php
                                            endif; ?>
                                            <?php foreach ($selectedModalities as $m): ?>
                                                <input type="hidden" name="modalities[]"
                                                    value="<?php echo htmlspecialchars($m, ENT_QUOTES); ?>">
                                                <?php
                                            endforeach; ?>
                                            <div class="btn-group">
                                                <input type="hidden" id="per_page_input" name="per_page"
                                                    value="<?php echo $perPage; ?>">
                                                <button type="button"
                                                    class="btn btn-sm btn-outline-secondary dropdown-toggle per-page-btn"
                                                    data-bs-toggle="dropdown" aria-expanded="false">
                                                    <i class="bi bi-list-ul"></i>
                                                    <span class="visually-hidden">Filas</span>
                                                    <span class="fw-bold ms-1 per-page-label"><?php echo $perPage; ?></span>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <li><button type="button" class="dropdown-item per-page-option"
                                                            data-value="10">10</button></li>
                                                    <li><button type="button" class="dropdown-item per-page-option"
                                                            data-value="25">25</button></li>
                                                    <li><button type="button" class="dropdown-item per-page-option"
                                                            data-value="50">50</button></li>
                                                    <li><button type="button" class="dropdown-item per-page-option"
                                                            data-value="100">100</button></li>
                                                </ul>
                                            </div>
                                        </form>
                                    </div>
                                </div>

                                <?php
                                $modSuffix = '';
                                if (!empty($selectedModalities)) {
                                    foreach ($selectedModalities as $m) {
                                        $modSuffix .= '&modalities[]=' . urlencode($m);
                                    }
                                }
                                ?>
                                <?php if (!empty($studies)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped table-hover">
                                            <thead>
                                                <tr>
                                                    <th style="width:40px;">
                                                        <input type="checkbox" class="form-check-input" id="check-all-batch"
                                                            title="Seleccionar todos">
                                                    </th>
                                                    <th><a href="?sort=date&page=1&per_page=<?php echo $perPage; ?>&patient_id=<?php echo urlencode($patientIdValue); ?>&date_from=<?php echo urlencode($dateFromValue); ?>&date_to=<?php echo urlencode($dateToValue); ?><?php echo $forceRemote ? '&force_remote=1' : ''; ?><?php echo $modSuffix; ?>"
                                                            style="color: inherit; text-decoration: none;">Fecha<?php if ($sort === 'date')
                                                                echo ($_SESSION['sort_order'] === 'asc' ? ' ↑' : ' ↓'); ?></a>
                                                    </th>
                                                    <th>ID Paciente</th>
                                                    <th><a href="?sort=name&page=1&per_page=<?php echo $perPage; ?>&patient_id=<?php echo urlencode($patientIdValue); ?>&date_from=<?php echo urlencode($dateFromValue); ?>&date_to=<?php echo urlencode($dateToValue); ?><?php echo $forceRemote ? '&force_remote=1' : ''; ?><?php echo $modSuffix; ?>"
                                                            style="color: inherit; text-decoration: none;">Nombre<?php if ($sort === 'name')
                                                                echo ($_SESSION['sort_order'] === 'asc' ? ' ↑' : ' ↓'); ?></a>
                                                    </th>
                                                    <th>Hora</th>
                                                    <th>Modalidad(es)</th>
                                                    <th>Descripción</th>
                                                    <th>Acciones</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($studies as $s): ?>
                                                    <?php
                                                    $tags = array_merge($s['MainDicomTags'] ?? [], $s['PatientMainDicomTags'] ?? []);
                                                    $studyUid = $tags['StudyInstanceUID'] ?? null;

                                                    // --- [MEJORA RENDIMIENTO] Una sola llamada a Orthanc por estudio (cacheada).
                                                    // getOrthancStudyFullData usa caché en memoria, así que las 2-3 llamadas
                                                    // anteriores quedan reducidas a UNA SOLA por estudio en toda la página.
                                                    if ($studyUid) {
                                                        $fullData = getOrthancStudyFullData($studyUid);
                                                        if ($fullData && is_array($fullData)) {
                                                            // Enriquecer tags con datos completos de Orthanc (una sola consulta)
                                                            $tags = array_merge($tags, $fullData['MainDicomTags'] ?? [], $fullData['PatientMainDicomTags'] ?? []);
                                                            // Si falta ModalitiesInStudy, tomarlo del fullData directamente
                                                            if (empty($tags['ModalitiesInStudy']) && isset($fullData['MainDicomTags']['ModalitiesInStudy'])) {
                                                                $tags['ModalitiesInStudy'] = $fullData['MainDicomTags']['ModalitiesInStudy'];
                                                            }
                                                        }
                                                    } else {
                                                        $fullData = null;
                                                    }

                                                    $rawDate = pickTag($tags, ['StudyDate', '0008,0020', 'SeriesDate', '0008,0021', 'AcquisitionDate', '0008,0022']);
                                                    if (empty($rawDate) && $fullData) {
                                                        $rawDate = pickTag($fullData['MainDicomTags'] ?? [], ['StudyDate', '0008,0020', 'SeriesDate', '0008,0021', 'AcquisitionDate', '0008,0022']);
                                                    }
                                                    $rawTime = pickTag($tags, ['StudyTime', 'SeriesTime', 'AcquisitionTime', '0008,0030']) ?: ($s['StudyTime'] ?? '');
                                                    $desc = pickTag($tags, ['StudyDescription', 'SeriesDescription', 'ProtocolName']);
                                                    // Obtener PatientID y PatientName por separado (sin fallback cruzado)
                                                    // Si la búsqueda fue por PatientID, usar ese valor como ID mostrado
                                                    $patientId = $patientIdValue !== '' ? $patientIdValue : pickTag($tags, ['PatientID', '0010,0020']);
                                                    // Si tenemos $globalPatientName desde la query Patient, usarlo como nombre
                                                    $patientName = !empty($globalPatientName) ? $globalPatientName : pickTag($tags, ['PatientName', '0010,0010', 'Patient']);
                                                    $patientName = $patientName !== '' ? str_replace('^', ' ', $patientName) : '';
                                                    $patientName = preg_replace('/^PatientName\s+String\s+/i', '', $patientName);
                                                    // Si no hay nombre, buscarlo en fullData (ya cacheado, sin costo extra)
                                                    if (($patientName === '' || strtolower($patientName) === 'sin nombre') && $fullData) {
                                                        $tempName = $fullData['PatientMainDicomTags']['PatientName'] ?? '';
                                                        if ($tempName !== '') {
                                                            if (is_array($tempName)) {
                                                                $tempName = isset($tempName['Alphabetic'])
                                                                    ? (string) $tempName['Alphabetic']
                                                                    : implode(' ', array_filter(array_map('strval', $tempName)));
                                                            }
                                                            $patientName = str_replace('^', ' ', (string) $tempName);
                                                            $patientName = preg_replace('/^PatientName\s+String\s+/i', '', $patientName);
                                                        }
                                                    }
                                                    if ($patientName === '') {
                                                        $patientName = 'Sin nombre';
                                                    }
                                                    $dateText = formatDicomDate($rawDate);
                                                    $timeText = formatDicomTime($rawTime);

                                                    $mods = '';
                                                    if (!empty($tags['ModalitiesInStudy'])) {
                                                        $mods = is_array($tags['ModalitiesInStudy']) ? implode(',', $tags['ModalitiesInStudy']) : (string)$tags['ModalitiesInStudy'];
                                                    } else {
                                                        $modFromTags = $tags['Modality'] ?? '';
                                                        $modFromStudy = $s['Modality'] ?? '';
                                                        if (!empty($modFromTags)) {
                                                            $mods = $modFromTags;
                                                        } elseif (!empty($modFromStudy)) {
                                                            $mods = $modFromStudy;
                                                        } elseif (isset($s['00080060']['Value'][0])) {
                                                            $mods = $s['00080060']['Value'][0];
                                                        }
                                                    }

                                                    // Mapear códigos DICOM a abreviaturas
                                                    $modList = explode(',', $mods);
                                                    $mappedMods = array_map(function ($m) use ($modalityMap) {
                                                        $trimmed = trim($m);
                                                        return $modalityMap[$trimmed] ?? $trimmed;
                                                    }, $modList);
                                                    $mods = implode(',', $mappedMods);

                                                    if (!$mods) {
                                                        // Inferir modalidad desde la descripción (sin llamadas extra a Orthanc)
                                                        $inferred = getInferredModality($desc);
                                                        $mods = $inferred ?: 'N/D';
                                                    }

                                                    $studyUid = $tags['StudyInstanceUID'] ?? null;
                                                    $exp = time() + 86400; // 24 horas
                                                    $sig = hash_hmac('sha256', $studyUid . '|' . $exp, $SHARE_SECRET);
                                                    $shareUrl = "http://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "?action=view_shared&study_uid=" . urlencode($studyUid) . "&exp=$exp&sig=$sig";
                                                    ?>
                                                    <tr class="study-row"
                                                        data-uid="<?php echo htmlspecialchars($studyUid, ENT_QUOTES); ?>">
                                                        <td class="text-center">
                                                            <input type="checkbox" class="form-check-input batch-cb"
                                                                value="<?php echo htmlspecialchars($studyUid, ENT_QUOTES); ?>"
                                                                data-query="<?php echo htmlspecialchars($s['_remote']['queryId'] ?? ($_SESSION['last_query']['id'] ?? ''), ENT_QUOTES); ?>"
                                                                data-answer="<?php echo htmlspecialchars($s['_remote']['answerIdx'] ?? '', ENT_QUOTES); ?>">
                                                        </td>
                                                        <td data-label="Fecha">
                                                            <?php echo htmlspecialchars($dateText ?: 'N/D', ENT_QUOTES); ?>
                                                        </td>
                                                        <td data-label="ID Paciente">
                                                            <?php echo htmlspecialchars($patientId ?: 'N/D', ENT_QUOTES); ?>
                                                        </td>
                                                        <td data-label="Nombre">
                                                            <?php echo htmlspecialchars($patientName ?: 'N/D', ENT_QUOTES); ?>
                                                        </td>
                                                        <td data-label="Hora">
                                                            <?php echo htmlspecialchars($timeText ?: 'N/D', ENT_QUOTES); ?>
                                                        </td>
                                                        <td data-label="Modalidad(es)">
                                                            <?php if ($mods): ?>
                                                                <div class="d-flex flex-wrap gap-1">
                                                                    <?php $modArray = explode(',', $mods); ?>
                                                                    <?php foreach ($modArray as $m):
                                                                        $m = trim($m); ?>
                                                                        <span
                                                                            class="modality-badge mod-<?php echo htmlspecialchars($m, ENT_QUOTES); ?>">
                                                                            <?php echo htmlspecialchars($m, ENT_QUOTES); ?>
                                                                        </span>
                                                                        <?php
                                                                    endforeach; ?>
                                                                </div>
                                                                <?php
                                                            else: ?>
                                                                <span class="modality-badge">N/D</span>
                                                                <?php
                                                            endif; ?>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($desc, ENT_QUOTES); ?></td>
                                                        <td data-label="Acciones">
                                                            <?php if ($studyUid): ?>
                                                                <?php if (!empty($s['_local'])): ?>
                                                                    <a href="?action=view&study_uid=<?php echo urlencode($studyUid); ?>"
                                                                        target="_blank" rel="noopener noreferrer"
                                                                        class="btn btn-secondary btn-sm btn-visualize"
                                                                        data-uid="<?php echo htmlspecialchars($studyUid, ENT_QUOTES); ?>"
                                                                        data-local="1"
                                                                        data-patient="<?php echo htmlspecialchars($patientName ?: 'Paciente', ENT_QUOTES); ?>"
                                                                        data-desc="<?php echo htmlspecialchars($desc ?: 'Estudio DICOM', ENT_QUOTES); ?>"
                                                                        data-bs-toggle="tooltip" title="Visualizar estudio (Disponible localmente)">
                                                                        <i class="bi bi-eye"></i> Visualizar
                                                                    </a>
                                                                    <a href="?action=download&study_uid=<?php echo urlencode($studyUid); ?>&download_now=1"
                                                                        class="btn btn-outline-success btn-sm ms-1 btn-download"
                                                                        title="Descargar imágenes JPG">
                                                                        <i class="bi bi-download"></i> Descargar
                                                                    </a>
                                                                    <button type="button" class="btn btn-outline-info btn-sm ms-1 btn-share"
                                                                        onclick="copyShare(this, '<?php echo $shareUrl; ?>')"
                                                                        data-bs-toggle="tooltip" title="Copiar enlace (24h)"><i
                                                                            class="bi bi-share"></i></button>
                                                                    <?php
                                                                else: ?>
                                                                    <a href="?action=view&study_uid=<?php echo urlencode($studyUid); ?>&query_id=<?php echo urlencode($s['_remote']['queryId'] ?? ($_SESSION['last_query']['id'] ?? '')); ?>&answer_idx=<?php echo urlencode($s['_remote']['answerIdx'] ?? ''); ?>"
                                                                        target="_blank" rel="noopener noreferrer"
                                                                        class="btn btn-secondary btn-sm btn-visualize"
                                                                        data-uid="<?php echo htmlspecialchars($studyUid, ENT_QUOTES); ?>"
                                                                        data-local="0"
                                                                        data-query="<?php echo htmlspecialchars($s['_remote']['queryId'] ?? ($_SESSION['last_query']['id'] ?? ''), ENT_QUOTES); ?>"
                                                                        data-answer="<?php echo htmlspecialchars($s['_remote']['answerIdx'] ?? '', ENT_QUOTES); ?>"
                                                                        data-patient="<?php echo htmlspecialchars($patientName ?: 'Paciente', ENT_QUOTES); ?>"
                                                                        data-desc="<?php echo htmlspecialchars($desc ?: 'Estudio DICOM', ENT_QUOTES); ?>"
                                                                        data-bs-toggle="tooltip"
                                                                        title="Visualizar estudio (precargar desde Canvas)">
                                                                        <i class="bi bi-eye"></i> Visualizar
                                                                    </a>
                                                                    <a href="?action=download&study_uid=<?php echo urlencode($studyUid); ?>&query_id=<?php echo urlencode($s['_remote']['queryId'] ?? ($_SESSION['last_query']['id'] ?? '')); ?>&answer_idx=<?php echo urlencode($s['_remote']['answerIdx'] ?? ''); ?>"
                                                                        class="btn btn-outline-success btn-sm ms-1 btn-download"
                                                                        title="Descargar imágenes JPG">
                                                                        <i class="bi bi-download"></i> Descargar
                                                                    </a>
                                                                    <button type="button" class="btn btn-outline-info btn-sm ms-1 btn-share"
                                                                        onclick="copyShare(this, '<?php echo $shareUrl; ?>')"
                                                                        data-bs-toggle="tooltip" title="Copiar enlace (24h)"><i
                                                                            class="bi bi-share"></i></button>
                                                                    <div class="small text-muted mt-1">Si el estudio no está en Orthanc se
                                                                        iniciará su traslado y se esperará antes de descargar.</div>
                                                                    <?php
                                                                endif; ?>
                                                                <?php
                                                            else: ?>
                                                                <em>Sin UID de estudio</em>
                                                                <?php
                                                            endif; ?>
                                                        </td>
                                                    </tr>
                                                    <?php
                                                endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;">
                                        <div class="hint">
                                            <?php
                                            $showFrom = ($page - 1) * $perPage + 1;
                                            $showTo = min($totalResults, $page * $perPage);
                                            if ($totalResults === 0) {
                                                echo 'Mostrando 0 resultados.';
                                            } else {
                                                echo 'Mostrando ' . $showFrom . ' - ' . $showTo . ' de ' . $totalResults . ' estudios.';
                                            }
                                            ?>
                                        </div>
                                        <div>
                                            <?php if ($page > 1): ?>
                                                <a href="?page=<?php echo $page - 1; ?>&per_page=<?php echo $perPage; ?>&sort=<?php echo $sort; ?>&patient_id=<?php echo urlencode($patientIdValue); ?>&date_from=<?php echo urlencode($dateFromValue); ?>&date_to=<?php echo urlencode($dateToValue); ?><?php echo $forceRemote ? '&force_remote=1' : ''; ?><?php echo $modSuffix; ?>"
                                                    class="btn btn-outline-secondary rounded-pill btn-sm">◀ Anterior</a>
                                                <?php
                                            endif; ?>
                                            <?php if ($page < $totalPages): ?>
                                                <a href="?page=<?php echo $page + 1; ?>&per_page=<?php echo $perPage; ?>&sort=<?php echo $sort; ?>&patient_id=<?php echo urlencode($patientIdValue); ?>&date_from=<?php echo urlencode($dateFromValue); ?>&date_to=<?php echo urlencode($dateToValue); ?><?php echo $forceRemote ? '&force_remote=1' : ''; ?><?php echo $modSuffix; ?>"
                                                    class="btn btn-outline-secondary rounded-pill btn-sm">Siguiente ▶</a>
                                                <?php
                                            endif; ?>
                                        </div>
                                    </div>
                                    <?php
                                else: ?>
                                    <p class="hint">No se encontraron estudios que coincidan con los criterios de búsqueda.</p>
                                    <?php
                                endif; ?>
                            </div> <!-- Cierre correcto de results-inner -->
                            <?php
                            if (isset($_GET['ajax'])) {
                                $html = ob_get_clean();
                                header('Content-Type: application/json');
                                echo json_encode([
                                    'html' => $html,
                                    'status' => $status ?? null,
                                    'message' => $message ?? ''
                                ]);
                                exit;
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php
            endif; // fin rama logueado / no logueado ?>
    </div>



    <!-- 
    ============================================================================ 
    SECCIÓN 11: LÓGICA JAVASCRIPT FRONT-END 
    ============================================================================ 
    Maneja interactividad del DOM: polling sincrónico para monitorear estados 
    de descargas progresivas vía AJAX, generador de enlaces de compartición extendidos, 
    miniaturas (thumbnails) bajo demanda y control de selección múltiple (Batch).
    -->
    <script>
        (function () {
            var base = '<?php echo rtrim($OHIF_BASE_URL, "/"); ?>';
            var path = '<?php echo rtrim($OHIF_VIEWER_PATH, "/"); ?>';
            var ohifUrlBase = base + path;

            function openOhif(studyUid) {
                if (!studyUid) return;
                var url = ohifUrlBase + encodeURIComponent(studyUid);
                window.open(url, '_blank');
            }

        })();
    </script>

    <!-- Modality filter toggle -->

    <!-- 
    ============================================================================ 
    SECCIÓN 11: LÓGICA JAVASCRIPT FRONT-END 
    ============================================================================ 
    Maneja interactividad del DOM: polling sincrónico para monitorear estados 
    de descargas progresivas vía AJAX, generador de enlaces de compartición extendidos, 
    miniaturas (thumbnails) bajo demanda y control de selección múltiple (Batch).
    -->
    <script>
        // Load Flatpickr (CDN) and Spanish locale before using it
    </script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js"></script>
    <!-- inline initialization removed; handled by /buscador/assets/js/remoto1.js -->

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/buscador/assets/js/remoto1.js"></script>

    <!-- Spinner overlay for actions -->
    <div id="action-overlay"
        style="display:none;position:fixed;inset:0;background:rgba(255,255,255,0.7);z-index:2000;align-items:center;justify-content:center;backdrop-filter:blur(4px);">
        <div class="text-center">
            <div class="spinner-border text-primary" role="status" style="width:3rem;height:3rem;"></div>
            <div class="mt-2 text-dark fw-bold">Procesando...</div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- VENTANA FLOTANTE DE CARGA EN SEGUNDO PLANO (VISUALIZACIÓN DE ESTUDIOS)    -->
    <!-- ========================================================================= -->
    <div id="viewer-load-modal"
        style="display:none; position:fixed; inset:0; z-index:99999; background:rgba(15,23,42,0.75); backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px); align-items:center; justify-content:center; padding:16px;">
        <div style="background:var(--card-bg, #ffffff); border-radius:24px; padding:32px 28px; max-width:480px; width:100%; box-shadow:0 25px 60px -15px rgba(0,0,0,0.35); border:1px solid rgba(255,255,255,0.25); text-align:center; position:relative; animation:viewerPopIn 0.3s cubic-bezier(0.16,1,0.3,1);">
            
            <!-- Icono circular animado -->
            <div id="viewer-modal-icon-wrap"
                style="width:72px; height:72px; margin:0 auto 16px; border-radius:50%; background:linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); display:flex; align-items:center; justify-content:center; box-shadow:0 8px 20px rgba(37,99,235,0.15); transition:all 0.3s ease;">
                <i class="bi bi-cloud-arrow-down-fill text-primary" style="font-size:2rem;" id="viewer-modal-icon"></i>
            </div>

            <h4 style="font-weight:700; margin-bottom:6px; color:var(--text-main, #0f172a); font-family:'Outfit',sans-serif;">Preparando Visualización</h4>
            <p style="font-size:0.92rem; color:var(--text-muted, #64748b); margin-bottom:20px; word-break:break-word;" id="viewer-modal-study-info">Cargando imágenes del estudio en segundo plano...</p>

            <!-- Barra de Progreso -->
            <div style="background:rgba(226,232,240,0.85); border-radius:14px; height:20px; overflow:hidden; position:relative; margin-bottom:12px; box-shadow:inset 0 2px 4px rgba(0,0,0,0.06);">
                <div id="viewer-modal-bar"
                    style="width:0%; height:100%; border-radius:14px; background:linear-gradient(90deg, #2563eb, #3b82f6); transition:width 0.4s cubic-bezier(0.4, 0, 0.2, 1), background 0.3s ease; position:relative;">
                </div>
            </div>

            <!-- Porcentaje y Estado Dinámico -->
            <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.92rem; margin-bottom:20px;">
                <span id="viewer-modal-status" style="font-weight:600; color:#2563eb; text-align:left; max-width:78%; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Conectando con ClearCanvas...</span>
                <span id="viewer-modal-pct" style="font-weight:800; font-size:1.15rem; color:var(--text-main, #0f172a); font-family:'Outfit',sans-serif;">0%</span>
            </div>

            <div style="font-size:0.82rem; color:var(--text-muted, #94a3b8); margin-bottom:22px; line-height:1.4;">
                <i class="bi bi-shield-check me-1 text-success"></i> La ventana del visor se abrirá de inmediato apenas las imágenes queden al 100% en el servidor para que no tengas que esperar pantalla negra.
            </div>

            <div class="d-flex gap-2 justify-content-center">
                <button type="button" id="viewer-modal-open-now" class="btn btn-success d-none" style="border-radius:14px; padding:8px 24px; font-weight:600;">
                    <i class="bi bi-box-arrow-up-right me-1"></i> Abrir Visor Ahora
                </button>
                <button type="button" id="viewer-modal-cancel" class="btn btn-outline-secondary" style="border-radius:14px; padding:8px 22px; font-size:0.9rem;">
                    Cancelar
                </button>
            </div>
        </div>
    </div>
    <style>
        @keyframes viewerPopIn {
            0% { opacity:0; transform:scale(0.88); }
            100% { opacity:1; transform:scale(1); }
        }
    </style>

    <!-- Thumbnail Overlay -->
    <!--
    <div id="hover-thumbnail"
        style="display:none; position:fixed; z-index:9999; border-radius:12px; overflow:hidden; box-shadow: 0 15px 35px rgba(0,0,0,0.4); pointer-events:none; width: 220px; height: 220px; background: #111; transition: opacity 0.25s ease; opacity:0;">
        <div class="text-center w-100 h-100 d-flex align-items-center justify-content-center text-white"
            id="hover-thumbnail-loading"
            style="position:absolute; inset:0; font-family:'Outfit',sans-serif; font-size:12px;">Cargando...</div>
        <img id="hover-thumbnail-img" src=""
            style="width:100%; height:100%; object-fit:contain; position:relative; z-index:2;"
            onload="document.getElementById('hover-thumbnail-loading').style.display='none';" />
    </div>
    -->

    <!-- Barra Inferior de Descarga Múltiple -->
    <div id="batch-download-bar" class="fixed-bottom shadow-lg p-3"
        style="background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(12px); border-top: 1px solid rgba(255,255,255,0.3); transform: translateY(100%); transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1); z-index: 1040;">
        <div class="container d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-0 text-dark fw-bold" id="batch-count" style="font-family: 'Outfit', sans-serif;">0
                    estudios seleccionados</h5>
                <small class="text-muted">Se descargarán automáticamente como archivos individuales.</small>
            </div>
            <button id="btn-batch-download" class="btn btn-primary d-inline-flex align-items-center"
                style="border-radius:20px; padding: 10px 24px;">
                <i class="bi bi-cloud-arrow-down-fill me-2 fs-5"></i> Descargar Seleccionados
            </button>
        </div>
    </div>

    <!-- 
    ============================================================================ 
    SECCIÓN 11: LÓGICA JAVASCRIPT FRONT-END 
    ============================================================================ 
    Maneja interactividad del DOM: polling sincrónico para monitorear estados 
    de descargas progresivas vía AJAX, generador de enlaces de compartición extendidos, 
    miniaturas (thumbnails) bajo demanda y control de selección múltiple (Batch).
    -->
    <script>
        var hasGD = <?php echo ($hasGD ? 'true' : 'false'); ?>;
        var hasZipArchive = <?php echo ($hasZipArchive ? 'true' : 'false'); ?>;
        var tmpWritable = <?php echo ($tmpWritable ? 'true' : 'false'); ?>;
        var downloadSupportMessage = <?php echo json_encode($downloadSupportMessage); ?>;
        window.bindTableActions = function () {
            // Compartir link handler (Con Soporte HTTP via execCommand)
            window.copyShare = function (btn, url) {
                var copyExec = function () {
                    var icon = btn.querySelector('i');
                    if (icon) icon.className = 'bi bi-check2-circle';
                    btn.classList.add('btn-success');
                    btn.classList.remove('btn-outline-info');
                    setTimeout(function () {
                        if (icon) icon.className = 'bi bi-share';
                        btn.classList.remove('btn-success');
                        btn.classList.add('btn-outline-info');
                    }, 2000);
                };
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(url).then(copyExec).catch(function (e) { fallbackCopy(url); copyExec(); });
                } else {
                    fallbackCopy(url);
                    copyExec();
                }
                function fallbackCopy(text) {
                    var t = document.createElement("textarea");
                    t.value = text;
                    t.style.top = "0"; t.style.left = "0"; t.style.position = "fixed";
                    document.body.appendChild(t);
                    t.focus(); t.select();
                    try { document.execCommand('copy'); } catch (err) { }
                    document.body.removeChild(t);
                }
            };

            // Hover Thumbnails Logic
            /*
            var thumbDiv = document.getElementById('hover-thumbnail');
            var thumbImg = document.getElementById('hover-thumbnail-img');
            var thumbLoad = document.getElementById('hover-thumbnail-loading');
            var hoverTimeout;

            document.querySelectorAll('tr.study-row').forEach(function (row) {
                row.addEventListener('mouseenter', function (e) {
                    var uid = row.dataset.uid;
                    if (!uid) return;
                    clearTimeout(hoverTimeout);
                    thumbLoad.style.display = 'flex';
                    thumbImg.src = '';
                    hoverTimeout = setTimeout(() => {
                        thumbImg.src = '?action=thumbnail&study_uid=' + encodeURIComponent(uid);
                        thumbDiv.style.opacity = '1';
                        // Position instantly to avoid jumping
                        var x = e.clientX + 20; var y = e.clientY + 20;
                        if (x + 220 > window.innerWidth) x = window.innerWidth - 240;
                        if (y + 220 > window.innerHeight) y = window.innerHeight - 240;
                        thumbDiv.style.left = x + 'px';
                        thumbDiv.style.top = y + 'px';
                        thumbDiv.style.display = 'block';
                    }, 400); // Demora intencional para no saturar al pasar el ratón rápido
                });
                row.addEventListener('mouseleave', function () {
                    clearTimeout(hoverTimeout);
                    thumbDiv.style.opacity = '0';
                    setTimeout(() => { if (thumbDiv.style.opacity === '0') thumbDiv.style.display = 'none'; }, 200);
                });
                row.addEventListener('mousemove', function (e) {
                    if (thumbDiv.style.display === 'block') {
                        var x = e.clientX + 20; var y = e.clientY + 20;
                        if (x + 220 > window.innerWidth) x = window.innerWidth - 240;
                        if (y + 220 > window.innerHeight) y = window.innerHeight - 240;
                        thumbDiv.style.left = x + 'px';
                        thumbDiv.style.top = y + 'px';
                    }
                });
            });
            */

            // Batch selection logic
            var checkAll = document.getElementById('check-all-batch');
            var batchCount = document.getElementById('batch-count');
            var batchBar = document.getElementById('batch-download-bar');
            var btnBatchDl = document.getElementById('btn-batch-download');

            function updateBatchUI() {
                var selected = document.querySelectorAll('.batch-cb:checked').length;
                if (selected > 0) {
                    batchCount.innerText = selected + (selected === 1 ? ' estudio seleccionado' : ' estudios seleccionados');
                    batchBar.style.transform = 'translateY(0)';
                } else {
                    batchBar.style.transform = 'translateY(100%)';
                }
            }

            if (checkAll) {
                checkAll.addEventListener('change', function () {
                    var isChecked = this.checked;
                    document.querySelectorAll('.batch-cb').forEach(function (cb) {
                        cb.checked = isChecked;
                    });
                    updateBatchUI();
                });
            }

            document.querySelectorAll('.batch-cb').forEach(function (cb) {
                cb.addEventListener('change', updateBatchUI);
            });

            if (btnBatchDl) {
                btnBatchDl.replaceWith(btnBatchDl.cloneNode(true));
                btnBatchDl = document.getElementById('btn-batch-download');
                btnBatchDl.addEventListener('click', function () {
                    var selected = document.querySelectorAll('.batch-cb:checked');
                    if (selected.length === 0) return;

                    var overlay = document.getElementById('action-overlay');
                    if (overlay) {
                        overlay.innerHTML = '<div class="text-center"><div class="spinner-border text-primary" role="status" style="width:3rem;height:3rem;"></div><div class="mt-3 fw-bold text-dark fs-5">Descargas Iniciadas</div><div class="mt-2 text-dark">Preparando ' + selected.length + ' estudios simultáneamente.<br>Por favor sigue operando normalmente.</div></div>';
                        // Reducir agresivamente el retraso del bloqueo visual
                        overlay.style.display = 'flex';
                        setTimeout(function () { overlay.style.display = 'none'; }, 2000);
                    }

                    selected.forEach(function (cb, index) {
                        setTimeout(function () {
                            var uid = cb.value;
                            var qId = cb.dataset.query;
                            var aIdx = cb.dataset.answer;
                            var href = '?action=download&study_uid=' + encodeURIComponent(uid);
                            if (qId && aIdx) href += '&query_id=' + encodeURIComponent(qId) + '&answer_idx=' + encodeURIComponent(aIdx);
                            else href += '&download_now=1';

                            var iframe = document.createElement('iframe');
                            iframe.style.display = 'none';
                            document.body.appendChild(iframe);
                            iframe.src = href;
                        }, index * 2500); // 2.5s iteraciones
                    });
                });
            }

            // Descargar button logic single
            document.querySelectorAll('.btn-download').forEach(function (el) {
                // Remove existing listener if any to avoid duplicates
                el.replaceWith(el.cloneNode(true));
            });
            document.querySelectorAll('.btn-download').forEach(function (el) {
                el.addEventListener('click', function (e) {
                    e.preventDefault();
                    var href = el.getAttribute('href') || el.dataset.href || null;
                    if (!tmpWritable) {
                        alert('No es posible preparar la descarga en el servidor. ' + downloadSupportMessage + '\nContacta al administrador para habilitar el directorio temporal.');
                        return;
                    }

                    var overlay = document.getElementById('action-overlay');
                    if (overlay) {
                        overlay.innerHTML = '<div class="text-center"><div class="spinner-border text-primary" role="status" style="width:3rem;height:3rem;"></div><div class="mt-3 fw-bold text-dark fs-5">Preparando archivo ZIP...</div><div class="mt-2 text-dark">La descarga se lanzará en segundo plano.</div></div>';
                        overlay.style.display = 'flex';
                        setTimeout(function () { overlay.style.display = 'none'; }, 2000);
                    }

                    // --- [MODIFICACIÓN] Descarga en Fondo (Iframe Oculto) ---
                    // Al inyectar el URL en un iframe, la página de espera navega y compila en 
                    // segundo plano sin sacar al usuario del Dashboard principal.
                    if (href) {
                        var iframe = document.getElementById('hidden-download-frame');
                        if (!iframe) {
                            iframe = document.createElement('iframe');
                            iframe.id = 'hidden-download-frame';
                            iframe.style.display = 'none';
                            document.body.appendChild(iframe);
                        }
                        iframe.src = href;
                    }
                });
            });

            // Tooltips
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.forEach(function (el) { new bootstrap.Tooltip(el); });

            // =====================================================================
            // MANEJADOR DE VISUALIZACIÓN: VENTANA FLOTANTE Y CARGA AL 100%
            // =====================================================================
            document.querySelectorAll('.btn-visualize').forEach(function (btn) {
                btn.replaceWith(btn.cloneNode(true));
            });

            var activeViewerPoll = null;

            document.querySelectorAll('.btn-visualize').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();

                    var studyUid = this.getAttribute('data-uid') || '';
                    var isLocal = this.getAttribute('data-local') === '1';
                    var queryId = this.getAttribute('data-query') || '';
                    var answerIdx = this.getAttribute('data-answer') || '';
                    var patientName = this.getAttribute('data-patient') || 'Paciente';
                    var studyDesc = this.getAttribute('data-desc') || 'Estudio DICOM';
                    var hrefUrl = this.getAttribute('href') || '';

                    if (!studyUid) {
                        if (hrefUrl) window.open(hrefUrl, '_blank');
                        return;
                    }

                    // Elementos de la ventana flotante
                    var modal = document.getElementById('viewer-load-modal');
                    var modalIcon = document.getElementById('viewer-modal-icon');
                    var modalInfo = document.getElementById('viewer-modal-study-info');
                    var modalBar = document.getElementById('viewer-modal-bar');
                    var modalStatus = document.getElementById('viewer-modal-status');
                    var modalPct = document.getElementById('viewer-modal-pct');
                    var cancelBtn = document.getElementById('viewer-modal-cancel');
                    var openNowBtn = document.getElementById('viewer-modal-open-now');

                    if (!modal) {
                        if (hrefUrl) window.open(hrefUrl, '_blank');
                        return;
                    }

                    // Limpiar intervalos previos si los hubiera
                    if (activeViewerPoll) {
                        clearInterval(activeViewerPoll);
                        activeViewerPoll = null;
                    }

                    // Configurar vista inicial del modal
                    modalInfo.textContent = patientName + ' • ' + studyDesc;
                    modalBar.style.width = '0%';
                    modalBar.style.background = 'linear-gradient(90deg, #2563eb, #3b82f6)';
                    modalPct.textContent = '0%';
                    modalStatus.style.color = '#2563eb';
                    modalStatus.textContent = isLocal ? 'Estudio local disponible. Abriendo...' : 'Conectando con ClearCanvas...';
                    modalIcon.className = 'bi bi-cloud-arrow-down-fill text-primary';
                    if (openNowBtn) openNowBtn.classList.add('d-none');
                    modal.style.display = 'flex';

                    // Handler botón cancelar
                    cancelBtn.onclick = function () {
                        if (activeViewerPoll) {
                            clearInterval(activeViewerPoll);
                            activeViewerPoll = null;
                        }
                        modal.style.display = 'none';
                    };

                    // Función para completar al 100% y abrir el visor OHIF
                    function onCompleteAndOpen(finalUrl) {
                        if (activeViewerPoll) {
                            clearInterval(activeViewerPoll);
                            activeViewerPoll = null;
                        }

                        modalBar.style.width = '100%';
                        modalBar.style.background = 'linear-gradient(90deg, #10b981, #059669)';
                        modalPct.textContent = '100%';
                        modalStatus.style.color = '#10b981';
                        modalStatus.textContent = '¡100% Completado! Todas las imágenes listas en el servidor.';
                        modalIcon.className = 'bi bi-check-circle-fill text-success';

                        // Actualizar la fila en la tabla para marcarla como local
                        btn.setAttribute('data-local', '1');
                        btn.classList.remove('btn-secondary');
                        btn.classList.add('btn-primary');
                        btn.setAttribute('title', 'Visualizar estudio (Disponible localmente)');

                        // Configurar botón "Abrir Visor Ahora" por si el navegador bloquea la ventana emergente
                        if (openNowBtn) {
                            openNowBtn.classList.remove('d-none');
                            openNowBtn.onclick = function () {
                                window.open(finalUrl, '_blank');
                                modal.style.display = 'none';
                            };
                        }

                        // Intentar abrir la pestaña automáticamente
                        setTimeout(function () {
                            var win = window.open(finalUrl, '_blank');
                            // Si se abrió correctamente, cerrar modal suavemente tras 1 segundo
                            if (win && !win.closed) {
                                setTimeout(function () {
                                    modal.style.display = 'none';
                                }, 1200);
                            }
                        }, 500);
                    }

                    // CASO 1: Estudio ya disponible en local
                    if (isLocal) {
                        modalBar.style.width = '60%';
                        modalPct.textContent = '60%';
                        fetch('?action=start_view_retrieve&study_uid=' + encodeURIComponent(studyUid))
                            .then(function (r) { return r.json(); })
                            .then(function (data) {
                                onCompleteAndOpen(data.ohifUrl || hrefUrl);
                            })
                            .catch(function () {
                                onCompleteAndOpen(hrefUrl);
                            });
                        return;
                    }

                    // CASO 2: Estudio remoto -> disparar C-MOVE y monitorear hasta llegar a 100%
                    modalBar.style.width = '15%';
                    modalPct.textContent = '15%';
                    modalStatus.textContent = 'Iniciando transferencia en segundo plano desde ClearCanvas...';

                    var startUrl = '?action=start_view_retrieve&study_uid=' + encodeURIComponent(studyUid) +
                        '&query_id=' + encodeURIComponent(queryId) +
                        '&answer_idx=' + encodeURIComponent(answerIdx);

                    fetch(startUrl)
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (data.status === 'ready') {
                                onCompleteAndOpen(data.ohifUrl || hrefUrl);
                                return;
                            }
                            if (data.status === 'error') {
                                modalStatus.style.color = '#ef4444';
                                modalStatus.textContent = 'Error: ' + (data.message || 'No se pudo iniciar.');
                                modalBar.style.background = '#ef4444';
                                modalIcon.className = 'bi bi-exclamation-triangle-fill text-danger';
                                return;
                            }

                            var jobId = data.jobId || '';
                            var targetOhif = data.ohifUrl || hrefUrl;
                            var currentPct = data.progress || 20;

                            modalBar.style.width = currentPct + '%';
                            modalPct.textContent = currentPct + '%';
                            modalStatus.textContent = data.message || 'Descargando imágenes DICOM...';

                            // Polling periódico cada 1000ms
                            var startTime = Date.now();
                            activeViewerPoll = setInterval(function () {
                                // Límite de seguridad: 3 minutos
                                if (Date.now() - startTime > 180000) {
                                    clearInterval(activeViewerPoll);
                                    activeViewerPoll = null;
                                    modalStatus.textContent = 'Tiempo límite alcanzado. Abriendo visor con imágenes cargadas...';
                                    onCompleteAndOpen(targetOhif);
                                    return;
                                }

                                fetch('?action=check_view_job&study_uid=' + encodeURIComponent(studyUid) + '&job_id=' + encodeURIComponent(jobId))
                                    .then(function (r) { return r.json(); })
                                    .then(function (poll) {
                                        if (poll.status === 'ready' || poll.progress >= 100) {
                                            onCompleteAndOpen(poll.ohifUrl || targetOhif);
                                        } else if (poll.status === 'error') {
                                            clearInterval(activeViewerPoll);
                                            activeViewerPoll = null;
                                            modalStatus.style.color = '#ef4444';
                                            modalStatus.textContent = poll.message || 'Error en la transferencia.';
                                            modalBar.style.background = '#ef4444';
                                            modalIcon.className = 'bi bi-exclamation-triangle-fill text-danger';
                                        } else {
                                            var pct = poll.progress || Math.min(95, currentPct + 8);
                                            currentPct = pct;
                                            modalBar.style.width = pct + '%';
                                            modalPct.textContent = pct + '%';
                                            modalStatus.textContent = poll.message || 'Transfiriendo imágenes DICOM (' + pct + '%)...';
                                        }
                                    })
                                    .catch(function (err) {
                                        console.warn('Polling status warning:', err);
                                    });
                            }, 1000);
                        })
                        .catch(function (err) {
                            console.error('Error al iniciar recuperación:', err);
                            modalStatus.style.color = '#ef4444';
                            modalStatus.textContent = 'Error de conexión con el servidor.';
                            modalBar.style.background = '#ef4444';
                            modalIcon.className = 'bi bi-exclamation-triangle-fill text-danger';
                        });
                });
            });
        };

        document.addEventListener('DOMContentLoaded', function () {
            window.bindTableActions();
        });
    </script>

    <!-- Offcanvas Panel (reemplaza modal-modalities-full) -->
    <div class="offcanvas offcanvas-end shadow-lg" tabindex="-1" id="offcanvas-modalities"
        aria-labelledby="offcanvasModalitiesLabel"
        style="background: rgba(255,255,255,0.95); backdrop-filter: blur(16px); border-left: 1px solid rgba(255,255,255,0.4); width: 350px;">
        <div class="offcanvas-header border-bottom border-light">
            <h5 class="offcanvas-title fw-bold text-dark" id="offcanvasModalitiesLabel"
                style="font-family:'Outfit',sans-serif;"><i class="bi bi-stack me-2 text-primary"></i>Modalidades</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
        </div>
        <div class="offcanvas-body" id="offcanvas-modalities-body" style="font-size:1.05rem;">
            <!-- populated dynamically -->
        </div>
    </div>

    <!-- 
    ============================================================================ 
    SECCIÓN 11: LÓGICA JAVASCRIPT FRONT-END 
    ============================================================================ 
    Maneja interactividad del DOM: polling sincrónico para monitorear estados 
    de descargas progresivas vía AJAX, generador de enlaces de compartición extendidos, 
    miniaturas (thumbnails) bajo demanda y control de selección múltiple (Batch).
    -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var viewAllBtn = document.getElementById('modality-view-all');
            if (viewAllBtn) {
                var newBtn = viewAllBtn.cloneNode(true);
                viewAllBtn.replaceWith(newBtn);
                newBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    var allBtns = Array.from(document.querySelectorAll('#modality-options .modality-item'));
                    var offc = document.getElementById('offcanvas-modalities');
                    if (offc) {
                        var body = document.getElementById('offcanvas-modalities-body');
                        if (body) {
                            body.innerHTML = '';
                            var ul = document.createElement('ul'); ul.className = 'list-unstyled mb-0 d-grid gap-2';
                            allBtns.forEach(function (b) {
                                var li = document.createElement('li');
                                li.className = 'p-2 rounded rounded-3 border align-items-center d-flex justify-content-between';
                                li.style.background = b.classList.contains('selected') ? 'rgba(13, 110, 253, 0.1)' : 'transparent';
                                li.style.borderColor = b.classList.contains('selected') ? 'rgba(13, 110, 253, 0.3)' : 'inherit';

                                var txt = b.getAttribute('data-value');
                                if (b.classList.contains('selected')) {
                                    li.innerHTML = '<span class="fw-bold text-primary">' + txt + '</span><span class="badge bg-primary rounded-pill">Activo</span>';
                                } else {
                                    li.innerHTML = '<span class="text-dark">' + txt + '</span>';
                                }
                                ul.appendChild(li);
                            });
                            body.appendChild(ul);
                        }
                        var bsOff = bootstrap.Offcanvas.getInstance(offc) || new bootstrap.Offcanvas(offc);
                        bsOff.show();
                    }
                });
            }
        });
    </script>

    </div>
    <!-- Premium Features Addons -->
    <div id="loading-overlay">
        <div class="spinner-ring"></div>
        <h3 style="color: #ffffff; font-family: 'Outfit', sans-serif;">Procesando...</h3>
        <p style="color: rgba(255,255,255,0.8); font-family: 'Outfit', sans-serif;">Por favor espera un momento</p>
    </div>

    <div class="toast-container position-fixed top-0 end-0 p-4" style="z-index: 10500;">
        <?php if (isset($status) && in_array($status, ['error', 'ok', 'warning'])): ?>
            <div id="systemToast"
                class="toast align-items-center <?php echo $status === 'warning' ? 'text-dark bg-warning' : ($status === 'ok' ? 'text-white bg-success' : 'text-white bg-danger'); ?> border-0"
                role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body" style="font-family: 'Outfit', sans-serif; font-size: 1.05rem;">
                        <?php echo $status === 'ok' ? '✅' : ($status === 'warning' ? '⚠️' : '❌'); ?>     <?php echo htmlspecialchars($message, ENT_QUOTES); ?>
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"
                        aria-label="Cerrar"></button>
                </div>
            </div>
            <?php
        endif; ?>
    </div>

    <!-- 
    ============================================================================ 
    SECCIÓN 11: LÓGICA JAVASCRIPT FRONT-END 
    ============================================================================ 
    Maneja interactividad del DOM: polling sincrónico para monitorear estados 
    de descargas progresivas vía AJAX, generador de enlaces de compartición extendidos, 
    miniaturas (thumbnails) bajo demanda y control de selección múltiple (Batch).
    -->
    <script>
        /**
         * ====================================================================
         * SECCIÓN 12: MANEJADORES DE INTERACCIÓN CLIENTE (JAVASCRIPT)
         * ====================================================================
         * Códigos Javascript del lado del navegador del usuario para:
         * 1. Manejo dinámico del tema Claro y Oscuro guardando en LocalStorage.
         * 2. Evitar que la página se resfresque completamente mediante uso de fetch() 
         *    para mandar la tabla de datos y botones de forma ininterrumpida (AJAX).
         */

        // Theme Toggle Logic
        const themeBtn = document.getElementById('theme-toggle');
        const currentTheme = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        if (currentTheme === 'dark') document.documentElement.setAttribute('data-theme', 'dark');

        if (themeBtn) {
            themeBtn.addEventListener('click', () => {
                let theme = document.documentElement.getAttribute('data-theme');
                if (theme === 'dark') {
                    document.documentElement.removeAttribute('data-theme');
                    localStorage.setItem('theme', 'light');
                } else {
                    document.documentElement.setAttribute('data-theme', 'dark');
                    localStorage.setItem('theme', 'dark');
                }
            });
        }

        // Spinner Logic
        function showSpinner() {
            document.getElementById('loading-overlay').classList.add('active');
        }

        // Toast Initialization
        document.addEventListener('DOMContentLoaded', () => {
            const toastElList = [].slice.call(document.querySelectorAll('.toast'));
            const toastList = toastElList.map(function (toastEl) {
                return new bootstrap.Toast(toastEl, { delay: 5000 });
            });
            toastList.forEach(toast => toast.show());
        });
        // AJAX Search Logic
        document.addEventListener('DOMContentLoaded', () => {
            const resultsContainer = document.querySelector('.results-inner');
            const searchForm = document.querySelector('form[method="get"]:not(#perPageForm)');
            const perPageForm = document.getElementById('perPageForm');
            const loadingOverlay = document.getElementById('loading-overlay');
            const toastContainer = document.querySelector('.toast-container');

            function showSystemToast(status, message) {
                const toastHtml = `
            <div class="toast align-items-center text-white bg-${status === 'ok' ? 'success' : 'danger'} border-0" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body" style="font-family: 'Outfit', sans-serif; font-size: 1.05rem;">
                        ${status === 'ok' ? '✅' : '⚠️'} ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Cerrar"></button>
                </div>
            </div>`;
                if (toastContainer) {
                    toastContainer.insertAdjacentHTML('beforeend', toastHtml);
                    const newToastEl = toastContainer.lastElementChild;
                    new bootstrap.Toast(newToastEl, { delay: 5000 }).show();
                }
            }

            function setSkeletonLoader() {
                let skeletonRows = '';
                for (let i = 0; i < 7; i++) {
                    skeletonRows += `
                    <tr>
                        <td><div class="skeleton-box" style="width: 20px; height: 20px; border-radius:4px;"></div></td>
                        <td><div class="skeleton-box" style="width: 90px;"></div></td>
                        <td><div class="skeleton-box" style="width: 120px;"></div></td>
                        <td><div class="skeleton-box" style="width: 200px;"></div></td>
                        <td><div class="skeleton-box" style="width: 70px;"></div></td>
                        <td><div class="skeleton-box" style="width: 130px; border-radius:12px;"></div></td>
                        <td><div class="skeleton-box" style="width: 250px;"></div></td>
                        <td><div class="skeleton-box" style="width: 180px;"></div></td>
                    </tr>`;
                }
                resultsContainer.innerHTML = `
                    <style>
                        .skeleton-box {
                            display: inline-block; height: 18px; position: relative; overflow: hidden;
                            background-color: rgba(226, 232, 240, 0.6); border-radius: 6px;
                        }
                        [data-theme="dark"] .skeleton-box { background-color: rgba(255,255,255,0.08); }
                        .skeleton-box::after {
                            position: absolute; top: 0; right: 0; bottom: 0; left: 0; transform: translateX(-100%);
                            background-image: linear-gradient(90deg, rgba(255,255,255,0) 0, rgba(255,255,255,0.5) 20%, rgba(255,255,255,0.8) 60%, rgba(255,255,255,0));
                            animation: shimmer 1.5s infinite; content: '';
                        }
                        [data-theme="dark"] .skeleton-box::after {
                            background-image: linear-gradient(90deg, rgba(255,255,255,0) 0, rgba(255,255,255,0.1) 20%, rgba(255,255,255,0.2) 60%, rgba(255,255,255,0));
                        }
                        @keyframes shimmer { 100% { transform: translateX(100%); } }
                    </style>
                    <div class="table-responsive" style="opacity: 0.8;">
                        <table class="table table-sm table-striped">
                            <thead>
                                <tr>
                                    <th>Cargando...</th><th>Cargando...</th><th>Cargando...</th><th>Cargando...</th>
                                    <th>Cargando...</th><th>Cargando...</th><th>Cargando...</th><th>Cargando...</th>
                                </tr>
                            </thead>
                            <tbody>${skeletonRows}</tbody>
                        </table>
                    </div>
                `;
            }

            function performAjaxSearch(urlStr) {
                setSkeletonLoader(); // UX: Cambia la pantalla oscura por la tabla fantasma elegante
                fetch(urlStr)
                    .then(res => res.json())
                    .then(data => {
                        if (data.html !== undefined) {
                            // Fade in efecto
                            resultsContainer.style.opacity = '0';
                            resultsContainer.innerHTML = data.html;
                            setTimeout(() => { resultsContainer.style.transition = 'opacity 0.4s ease'; resultsContainer.style.opacity = '1'; }, 50);
                        }
                        if (data.status && data.message) {
                            showSystemToast(data.status, data.message);
                        }

                        // Re-bind table actions seamlessly
                        if (typeof window.bindTableActions === 'function') {
                            window.bindTableActions();
                        }

                        // Push state to update URL cleanly
                        const cleanUrl = urlStr.replace(/&ajax=1/g, '').replace(/\?ajax=1&/, '?').replace(/\?ajax=1$/, '');
                        window.history.pushState({}, '', cleanUrl);
                    })
                    .catch(err => {
                        console.error('AJAX Error:', err);
                        if (loadingOverlay) loadingOverlay.classList.remove('active');
                        showSystemToast('error', 'Ocurrió un error de conexión al buscar los estudios.');
                    });
            }

            // Intercept Main Search Form
            if (searchForm) {
                searchForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    const url = new URL(window.location.href);
                    const formData = new FormData(this);
                    url.search = new URLSearchParams(formData).toString() + '&ajax=1';
                    performAjaxSearch(url.toString());
                });
            }

            // Override Per-Page Form submission to prevent native form post
            if (perPageForm) {
                perPageForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    const url = new URL(window.location.href);
                    const formData = new FormData(perPageForm);
                    url.search = new URLSearchParams(formData).toString() + '&ajax=1';
                    performAjaxSearch(url.toString());
                });
            }

            // Handle Per-Page dropdown options with Event Delegation
            document.addEventListener('click', function (e) {
                const btn = e.target.closest('.per-page-option');
                if (btn) {
                    e.preventDefault();
                    const val = btn.getAttribute('data-value');
                    document.getElementById('per_page_input').value = val;
                    const label = document.querySelector('.per-page-label');
                    if (label) label.textContent = val;

                    const form = document.getElementById('perPageForm');
                    if (form) {
                        const url = new URL(window.location.href);
                        const formData = new FormData(form);
                        url.search = new URLSearchParams(formData).toString() + '&ajax=1';
                        performAjaxSearch(url.toString());
                    }
                }
            });

            // Intercept pagination and sorting links inside the results
            if (resultsContainer) {
                resultsContainer.addEventListener('click', function (e) {
                    const link = e.target.closest('a[href*="sort="], a[href*="page="]');
                    if (link) {
                        e.preventDefault();
                        const url = link.href + (link.href.includes('?') ? '&' : '?') + 'ajax=1';
                        performAjaxSearch(url);
                    }
                });
            }
        });

    </script>

</body>

</html>
<?php ob_end_flush(); ?>