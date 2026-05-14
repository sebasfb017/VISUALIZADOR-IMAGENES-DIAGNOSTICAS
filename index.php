<?php
ob_start();
session_start();

/**
 * CONFIGURACIÓN BÁSICA
 * Ajusta estos valores según tu entorno
 */

// Orthanc HTTP en el mismo equipo
$ORTHANC_URL = 'http://192.168.52.155:8042';

// Alias de ClearCanvas en "DicomModalities" de Orthanc
$MODALITY_ID = 'CANVAS';

// AET de tu Orthanc (como en orthanc.json y en ClearCanvas)
$ORTHANC_AET = 'ORTHANC';

// Base de OHIF dentro de Orthanc.
// Usando el visor OHIF: /ohif/viewer?StudyInstanceUIDs=
$OHIF_BASE_URL    = $ORTHANC_URL;
$OHIF_VIEWER_PATH = '/ohif/viewer?StudyInstanceUIDs=';

// Usuarios para login (cámbialos por algo más seguro)
$USERS = [
    'admin' => '$2y$10$1hQIP5E4AOkgxs4AfLUw9ee/mln2.jyWFy/ngF9RfqWfBhqFLQN5W',   // usuario: admin / clave: admin
    'MEDICO' => '$2y$10$nmo/DuvhjBpoxZymJoyBAO1o8d1MCbD0CQoziKssZgU9Dr/8YtZTe',
];

// Límite de consultas simultáneas por usuario para mejorar concurrencia (aumentado para 15 usuarios)
define('MAX_CONCURRENT_QUERIES', 5);

/**
 * FUNCIONES AUXILIARES
 */

// Llamada genérica a la API REST de Orthanc
function callOrthanc($method, $path, $body = null) {
    global $ORTHANC_URL;

    $url = rtrim($ORTHANC_URL, '/') . $path;
    $ch  = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Timeout de 30 segundos para evitar hangs largos

    $headers = ['Accept: application/json'];

    if ($body !== null) {
        $payload = json_encode($body);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($payload);
    }

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
        throw new Exception("Orthanc devolvió HTTP $httpCode: $response");
    }

    if ($response === '') {
        return null;
    }

    return json_decode($response, true);
}

// Construye el rango de fechas DICOM a partir de YYYY-MM-DD
// DICOM: YYYYMMDD o YYYYMMDD-YYYYMMDD
function buildDicomDateRange(?string $dateFrom, ?string $dateTo): ?string {
    $from = null;
    $to   = null;

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
function formatDicomDate(?string $d): string {
    if ($d === null) return '';
    $d = trim($d);
    if (strlen($d) === 8 && ctype_digit($d)) {
        return substr($d, 6, 2) . '/' . substr($d, 4, 2) . '/' . substr($d, 0, 4);
    }
    return $d;
}

// Formatea hora DICOM (HHMMSS...) a HH:MM:SS o HH:MM
function formatDicomTime(?string $t): string {
    if ($t === null) return '';
    $t = trim($t);
    if (strlen($t) >= 6 && ctype_digit(substr($t, 0, 6))) {
        return substr($t, 0, 2) . ':' . substr($t, 2, 2) . ':' . substr($t, 4, 2);
    } elseif (strlen($t) >= 4 && ctype_digit(substr($t, 0, 4))) {
        return substr($t, 0, 2) . ':' . substr($t, 2, 2);
    }
    return $t;
}

// Buscar estudios en Orthanc a partir de un query DICOM (PatientID opcional)
function findStudiesInOrthancByPatientId(?string $patientId, int $maxTries = 1, int $delaySeconds = 0, ?string $dateFrom = null, ?string $dateTo = null): array {
    $query = [];

    if ($patientId !== null && $patientId !== '') {
        $query['PatientID'] = $patientId;
    }

    $dateRange = buildDicomDateRange($dateFrom, $dateTo);
    if ($dateRange) {
        $query['StudyDate'] = $dateRange;
    }

    $body = [
        'Level'  => 'Study',
        'Expand' => true,
        'Query'  => $query,
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

// Obtiene los datos completos de un estudio en Orthanc por StudyInstanceUID
function getOrthancStudyFullData(string $studyUid): ?array {
    try {
        $res = callOrthanc('GET', '/studies/' . urlencode($studyUid));
        if (is_array($res) && isset($res['MainDicomTags'])) {
            return $res;
        }
    } catch (Exception $e) {
        // estudio no existe aún
    }
    return null;
}


// Verifica si un estudio existe en Orthanc
function orthancStudyExists(string $studyUid): bool {
    return getOrthancStudyFullData($studyUid) !== null;
}

// Función auxiliar robusta para extraer el valor real de una etiqueta DICOM,
// ya venga como cadena, array con clave 'Value', o estructura compleja.
function extractTagValue($v) {
    if ($v === null) return '';
    if (is_string($v)) return trim($v);
    if (is_numeric($v)) return (string) $v;
    if (is_array($v)) {
        if (isset($v['Value']) && is_array($v['Value']) && count($v['Value']) > 0) {
            $first = $v['Value'][0];
            if (is_array($first) || is_object($first)) {
                return trim(json_encode($first));
            }
            return trim((string) $first);
        }
        if (isset($v['Alphabetic'])) {
            return trim((string) $v['Alphabetic']);
        }
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
    if (is_object($v)) {
        return trim(json_encode($v));
    }
    return '';
}

// Normaliza contenido simplificado de una respuesta de query a un set de MainDicomTags
function normalizeQueryContent(array $c): array {
    $tags = [];
    // StudyInstanceUID: buscar en varias claves (puede venir como '0020,000D' o StudyInstanceUID)
    $uidRaw = $c['StudyInstanceUID'] ?? $c['0020,000D'] ?? $c['0020,000d'] ?? null;
    $tags['StudyInstanceUID'] = $uidRaw !== null ? extractTagValue($uidRaw) : null;

    // extraer StudyDate en múltiples formatos
    $sd = '';
    if (isset($c['StudyDate'])) {
        $sd = extractTagValue($c['StudyDate']);
    }
    if ($sd === '' && isset($c['0008,0020'])) {
        $sd = extractTagValue($c['0008,0020']);
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
    $tags['StudyDescription'] = extractTagValue($c['StudyDescription'] ?? $c['SeriesDescription'] ?? $c['ProtocolName'] ?? '');

    return $tags;
}

// Devuelve el primer valor no vacío de una lista de claves dentro de $tags
function pickTag(array $tags, array $keys): string {
    foreach ($keys as $k) {
        if (isset($tags[$k]) && $tags[$k] !== null && $tags[$k] !== '') {
            $v = $tags[$k];
            if (is_array($v)) {
                if (isset($v['Alphabetic'])) return (string) $v['Alphabetic'];
                return implode(' ', array_filter(array_map('strval', $v)));
            }
            $result = (string) $v;
            // Limpiar valores vacíos o nulos de DICOM
            $result = trim($result);
            if ($result !== '' && $result !== '0' && $result !== '[]') {
                return $result;
            }
        }
    }
    return '';
}

function normalizeString($str) {
    $str = strtolower($str);
    $str = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'n'], $str);
    return $str;
}

function getInferredModality(string $desc): string {
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

/**
 * ESTADO GENERAL
 */

$status         = null;  // 'ok' o 'error' para mensajes de la app
$message        = '';
$studies        = [];
$patientIdValue = '';
$dateFromValue  = '';
$dateToValue    = '';
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
$debugDetails   = ''; // detalle técnico del último error
$forceRemote    = false; // inicializar
$doRetrieve     = false; // inicializar

/**
 * LOGOUT
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
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $loginError = 'Debes ingresar usuario y contraseña.';
    } elseif (!isset($USERS[$username]) || !password_verify($password, $USERS[$username])) {
        $loginError = 'Usuario o contraseña incorrectos.';
    } else {
        $_SESSION['user'] = $username;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

if (isset($_SESSION['user']) && isset($_GET['action']) && $_GET['action'] === 'view') {
    $studyUid = trim($_GET['study_uid'] ?? '');
    $queryId  = trim($_GET['query_id'] ?? '') ?: (isset($_SESSION['last_query']['id']) ? $_SESSION['last_query']['id'] : null);
    $answerIdx = trim($_GET['answer_idx'] ?? '');

    if ($studyUid === '') {
        $status = 'error';
        $message = 'Falta StudyInstanceUID para visualizar.';
    } else {
        try {
            $doRetrieve = false;
            if ($queryId !== '') {
                $doRetrieve = true;
                // Estudio remoto: recrear la query siempre para evitar expiración
                $body = isset($_SESSION['last_query']['body']) ? $_SESSION['last_query']['body'] : null;
                if (!$body || !is_array($body)) {
                    throw new Exception('No hay información de búsqueda guardada para recrear la query.');
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
                    throw new Exception('No se encontró el estudio en la query recreada.');
                }

                $debugDetails = 'Recreando query y enviando C-MOVE para el estudio ' . $studyUid . ' a la modalidad ' . $MODALITY_ID . ' con TargetAet ' . $ORTHANC_AET . '. nuevo queryId=' . $resolvedQueryId . ' answerIdx=' . $resolvedAnswerIdx;
            }

            // Abrir OHIF
            $ohifUrl = rtrim($OHIF_BASE_URL, '/') . rtrim($OHIF_VIEWER_PATH, '/') . urlencode($studyUid);
            $waitTime = 0; // Se esperará el retrieve antes de mostrar la página
            $loading = true;
        } catch (Exception $e) {
            $status  = 'error';
            $message = $e->getMessage();
            $debugDetails = $e->getMessage();
        }
    }
}

if ($doRetrieve && isset($loading)) {
    try {
        // Hacer retrieve y redirigir a página de espera en lugar de bloquear
        $retrieveResp = callOrthanc('POST', "/queries/$resolvedQueryId/answers/$resolvedAnswerIdx/retrieve", [
            'TargetAet' => $ORTHANC_AET
        ]);
        if (isset($retrieveResp['ID'])) {
            $jobId = $retrieveResp['ID'];
            // Guardar jobId en sesión para polling
            $_SESSION['retrieve_job'] = $jobId;
            $_SESSION['ohif_url'] = $ohifUrl;
            // Redirigir a página de espera
            header('Location: wait.php?job=' . $jobId);
            exit;
        } else {
            // Si no hay ID de job, esperar 5 segundos como antes y luego redirigir
            sleep(5);
            header('Location: ' . $ohifUrl);
            exit;
        }
    } catch (Exception $e) {
        $status = 'error';
        $message = 'Error al recuperar el estudio: ' . $e->getMessage();
        $debugDetails = $e->getMessage();
        unset($loading);
    }
}

/**
 * BÚSQUEDA / LISTADO DE ESTUDIOS (solo listamos, no realizamos C-MOVE)
 */
if (isset($_SESSION['user']) && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['patient_id'])) {
    // Agregar query a la lista activa
    $_SESSION['active_queries'][] = time();

    // Limpiar búsquedas previas en sesión
    unset($_SESSION['last_query'], $_SESSION['last_search_results']);

    $patientIdValue = trim($_GET['patient_id'] ?? '');
    $dateFromValue  = trim($_GET['date_from'] ?? '');
    $dateToValue    = trim($_GET['date_to'] ?? '');

    // Requerimos al menos un criterio: PatientID o un rango de fechas
    // Removido para permitir búsqueda de todos los estudios
    // if ($patientIdValue === '' && $dateFromValue === '' && $dateToValue === '') {
    //     $status  = 'error';
    //     $message = 'Debes escribir un ID de paciente o indicar un rango de fechas.';
    // } else {
        $dateFrom = $dateFromValue !== '' ? $dateFromValue : null;
        $dateTo   = $dateToValue   !== '' ? $dateToValue   : null;

        // Validar que la fecha desde no sea posterior a la fecha hasta
        if ($dateFrom && $dateTo && $dateFrom > $dateTo) {
            $status = 'error';
            $message = 'La fecha "desde" no puede ser posterior a la fecha "hasta".';
        } else {
            try {
                $dateRange = buildDicomDateRange($dateFrom, $dateTo);

                $forceRemote = true;

            // 1) Estudios ya existentes en Orthanc
            $localStudies   = findStudiesInOrthancByPatientId($patientIdValue !== '' ? $patientIdValue : null, 1, 0, $dateFrom, $dateTo);
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
            // Always include Modality and StudyTime in the query to get them in the C-FIND response
            if (!isset($query['Modality'])) {
                $query['Modality'] = '';
            }
            $query['StudyTime'] = '';
            $query['StudyDescription'] = '';

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
                        $modalitiesList = callOrthanc('GET', '/modalities');
                        $foundModality = false;
                        if (is_array($modalitiesList)) {
                            // Caso A: array asociativo indexado por ID
                            if (isset($modalitiesList[$MODALITY_ID])) {
                                $foundModality = true;
                            } else {
                                // Caso B: lista indexada o estructura con objetos/valores
                                foreach ($modalitiesList as $k => $v) {
                                    // clave igual a MODALITY_ID
                                    if (strtoupper((string)$k) === strtoupper($MODALITY_ID)) { $foundModality = true; break; }

                                    // valor simple igual a MODALITY_ID
                                    if (is_string($v) && strtoupper($v) === strtoupper($MODALITY_ID)) { $foundModality = true; break; }

                                    // valor array/obj con campo ID o Name
                                    if (is_array($v)) {
                                        if ((isset($v['ID']) && strtoupper((string)$v['ID']) === strtoupper($MODALITY_ID)) || (isset($v['Name']) && strtoupper((string)$v['Name']) === strtoupper($MODALITY_ID))) { $foundModality = true; break; }
                                    } elseif (is_object($v)) {
                                        $obj = (array)$v;
                                        if ((isset($obj['ID']) && strtoupper((string)$obj['ID']) === strtoupper($MODALITY_ID)) || (isset($obj['Name']) && strtoupper((string)$obj['Name']) === strtoupper($MODALITY_ID))) { $foundModality = true; break; }
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
                        if (empty($debugDetails)) $debugDetails = $e->getMessage();
                        throw new Exception('No se pudo conectar a Orthanc/Canvas: ' . $e->getMessage());
                    }

                    $queryResponse = callOrthanc('POST', "/modalities/$MODALITY_ID/query", [
                        'Level' => 'Study',
                        'Query' => $query
                    ]);

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
                            ]);
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
                                        if ($globalPatientName !== '') $globalPatientName = str_replace('^', ' ', $globalPatientName);
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
                                $remoteUid = $norm['StudyInstanceUID'] ?? null;
                                if ($remoteUid && !isset($localStudyUids[$remoteUid]) && !isset($remoteStudyUids[$remoteUid])) {
                                    $remoteStudyUids[$remoteUid] = true;
                                    if (empty($norm['ModalitiesInStudy'])) {
                                        // try to get from series
                                        $seriesQueryBody = [
                                            'Level' => 'Series',
                                            'Query' => ['0020,000D' => $remoteUid]
                                        ];
                                        $seriesQuery = callOrthanc('POST', "/modalities/$MODALITY_ID/query", $seriesQueryBody);
                                        if ($seriesQuery && isset($seriesQuery['ID'])) {
                                            $seriesAnswers = callOrthanc('GET', "/queries/" . $seriesQuery['ID'] . "/answers");
                                            $modalities = [];
                                            foreach ($seriesAnswers as $sidx) {
                                                $scontent = callOrthanc('GET', "/queries/" . $seriesQuery['ID'] . "/answers/" . $sidx . "/content?simplify");
                                                $smod = $scontent['Modality'] ?? '';
                                                if ($smod) $modalities[] = $smod;
                                            }
                                            $modalities = array_unique($modalities);
                                            if (!empty($modalities)) {
                                                $norm['ModalitiesInStudy'] = $modalities;
                                            }
                                            // try to get StudyTime from first series if missing
                                            if (empty($norm['StudyTime']) && !empty($seriesAnswers)) {
                                                $firstSeries = reset($seriesAnswers);
                                                $scontent = callOrthanc('GET', "/queries/" . $seriesQuery['ID'] . "/answers/" . $firstSeries . "/content?simplify");
                                                $norm['StudyTime'] = $scontent['AcquisitionTime'] ?? $scontent['SeriesTime'] ?? '';
                                            }
                                            callOrthanc('DELETE', "/queries/" . $seriesQuery['ID']);
                                        }
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
                                $display = array_filter($display, function($s) use ($selectedModalities, $modalityMap) {
                                    $mods = $s['MainDicomTags']['ModalitiesInStudy'] ?? [];
                                    if (!is_array($mods)) $mods = [];
                                    $mappedMods = array_map(function($m) use ($modalityMap) { return $modalityMap[trim($m)] ?? trim($m); }, $mods);
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
                            $_SESSION['last_search_results'] = $display;

                            $studies = $display;
                            $localCount = count(array_filter($display, fn($s) => isset($s['_local']) && $s['_local']));
                            $remoteCount = count($display) - $localCount;
                            $status = 'ok';
                            $filterMsg = !empty($selectedModalities) ? ' Filtro aplicado.' : '';
                            $message = 'Listado generado (' . $localCount . ' locales, ' . $remoteCount . ' remotos). Pulsa "Cargar y ver" para traer un estudio desde Canvas cuando lo necesites.' . $filterMsg;
                        }
                    }
                } catch (Exception $e) {
                    // Si falla la consulta remota, mostrar solo los locales
                    $studies = $display;
                    $localCount = count($display);
                    if (!empty($studies)) {
                        $status = 'ok';
                        $message = 'Mostrando estudios locales (' . $localCount . '); error al consultar Canvas: ' . $e->getMessage();
                    } else {
                        $status = 'ok';
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
        } catch (Exception $e) {
            $status  = 'error';
            $message = $e->getMessage();
            $debugDetails = $e->getMessage();
        }
        // Remover query de la lista activa
        if (isset($_SESSION['active_queries'])) {
            array_pop($_SESSION['active_queries']);
        }
    }
}
// Paginación: preparar $studies para la página actual usando resultados en sesión si existen
$perPage = 10;
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
        usort($allResults, function($a, $b) use ($order) {
            $dateA = $a['MainDicomTags']['StudyDate'] ?? '';
            $dateB = $b['MainDicomTags']['StudyDate'] ?? '';
            if ($dateA === '' && $dateB !== '') return 1; // Vacío al final
            if ($dateB === '' && $dateA !== '') return -1;
            if ($order === 'asc') {
                return strcmp($dateA, $dateB); // Más antiguo primero
            } else {
                return strcmp($dateB, $dateA); // Más reciente primero
            }
        });
    } elseif ($sort === 'name') {
        usort($allResults, function($a, $b) use ($order) {
            $nameA = $a['MainDicomTags']['PatientName'] ?? '';
            $nameB = $b['MainDicomTags']['PatientName'] ?? '';
            if ($nameA === '' && $nameB !== '') return 1; // Vacío al final
            if ($nameB === '' && $nameA !== '') return -1;
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

?>
?>
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
    <style>
    :root {
        --primary: #1976d2;
        --primary-light: #e3f2fd;
        --success: #2e7d32;
        --error: #c62828;
        --border-radius: 10px;
    }

    body {
        font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        margin: 0;
        padding: 40px 16px;
        background: radial-gradient(circle at top, #e3f2fd 0, #fafafa 55%, #f5f5f5 100%);
    }

    .layout {
        max-width: 960px;
        margin: 0 auto;
    }

    h1 {
        margin-top: 0;
        font-size: 1.6rem;
        color: #0d47a1;
    }

    .topbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
        font-size: 0.9rem;
        color: #374151;
    }

    .topbar a {
        color: #c62828;
        text-decoration: none;
        font-weight: 500;
    }

    .card {
        background: #ffffff;
        border-radius: var(--border-radius);
        padding: 20px 24px;
        margin-bottom: 24px;
        box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
        border: 1px solid #e0e0e0;
    }

    .card-header {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 12px;
    }

    .card-header-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 999px;
        background: var(--primary-light);
        color: var(--primary);
        font-size: 18px;
    }

    .status {
        margin-top: 12px;
        margin-bottom: 16px;
        padding: 10px 12px;
        border-radius: 6px;
        font-size: 0.9rem;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .status-ok {
        background: #e8f5e9;
        color: var(--success);
        border: 1px solid rgba(46, 125, 50, 0.4);
    }

    .status-error {
        background: #ffebee;
        color: var(--error);
        border: 1px solid rgba(198, 40, 40, 0.4);
    }

    .status-icon {
        font-size: 18px;
    }

    label {
        display: block;
        margin-bottom: 6px;
        font-weight: 600;
        color: #374151;
    }

    input[type="text"],
    input[type="date"],
    input[type="password"] {
        width: 100%;
        padding: 10px 12px;
        margin-bottom: 12px;
        border-radius: 6px;
        border: 1px solid #d1d5db;
        box-sizing: border-box;
        font-size: 14px;
    }

    input[type="text"]:focus,
    input[type="date"]:focus,
    input[type="password"]:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 1px rgba(25, 118, 210, 0.25);
    }

    .actions {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-top: 8px;
        align-items: center;
    }

    .btn {
        display: inline-block;
        padding: 10px 14px;
        border: 1px solid #ccc;
        border-radius: 8px;
        text-decoration: none;
        font-family: system-ui, sans-serif;
        background: #f5f5f5;
        color: inherit;
        cursor: pointer;
        font-size: 14px;
        font-weight: 500;
        align-items: center;
        gap: 6px;
        transition: background 0.15s ease, box-shadow 0.15s ease, transform 0.05s ease;
    }

    .btn:focus {
        outline: 2px solid #6aa0ff;
        outline-offset: 2px;
    }

    .btn-primary {
        background: var(--primary);
        color: #fff;
    }

    .btn-primary:hover {
        background: #145ea8;
        box-shadow: 0 4px 10px rgba(25, 118, 210, 0.3);
        transform: translateY(-1px);
    }

    .btn-secondary {
        background: #f5f5f5;
        color: #000;
        text-decoration: none;
    }

    .btn-secondary:hover {
        background: #e0e0e0;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        transform: translateY(-1px);
    }

    .btn-secondary:focus {
        outline: 2px solid #6aa0ff;
        outline-offset: 2px;
    }

    .hint {
        font-size: 0.85rem;
        color: #6b7280;
        margin-top: 4px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 12px;
        font-size: 14px;
    }

    th, td {
        padding: 8px 10px;
        text-align: left;
        border-bottom: 1px solid #e5e7eb;
    }

    th {
        background: #f3f4f6;
        font-weight: 600;
        color: #374151;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }

    tbody tr:nth-child(even) {
        background: #f9fafb;
    }

    tbody tr:hover {
        background: #eef2ff;
    }

    .modality-badge {
        display: inline-flex;
        align-items: center;
        padding: 2px 8px;
        border-radius: 999px;
        background: #e0f2fe;
        color: #075985;
        font-size: 12px;
        font-weight: 500;
    }

    .login-error {
        margin-top: 8px;
        padding: 8px 10px;
        border-radius: 6px;
        background: #ffebee;
        color: #c62828;
        font-size: 0.85rem;
    }

    @media (max-width: 640px) {
        body {
            padding: 24px 12px;
        }
        .card {
            padding: 16px 16px;
        }
        table {
            font-size: 13px;
        }
        th, td {
            padding: 6px 8px;
        }
    }

    .modality-filter {
        position: relative;
        margin-bottom: 12px;
    }

    .modality-toggle {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        background: #fff;
        text-align: left;
        cursor: pointer;
        font-size: 14px;
    }

    .modality-toggle:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 1px rgba(25, 118, 210, 0.25);
    }

    .modality-options {
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: #fff;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        max-height: 200px;
        overflow-y: auto;
        z-index: 1001;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    }

    .modality-option {
        display: block;
        padding: 8px 12px;
        cursor: pointer;
    }

    .modality-option:hover {
        background: #f3f4f6;
    }

    .modality-option input {
        margin-right: 8px;
    }
    </style>
</head>
<body>
    <div style="position: fixed; top: 10px; left: 10px; z-index: 1000;">
        <img src="/buscador/logo.png" alt="Logo" width="400" height="150">
    </div>
<div class="layout">
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

            <form method="post">
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
                <?php endif; ?>
            </form>
        </div>

    <?php else: ?>

        <nav class="navbar navbar-light bg-light mb-4 p-2 rounded">
            <span class="navbar-text">Conectado como <strong><?php echo htmlspecialchars($_SESSION['user'], ENT_QUOTES); ?></strong></span>
            <a href="?logout=1" class="btn btn-outline-danger btn-sm">Cerrar sesión</a>
        </nav>

        <h1 class="mb-4"><span style="font-size:1.5em;">🩻</span> Visor de estudios (Canvas ➜ Orthanc)</h1>

        <?php if ($status === 'error' || $status === 'ok'): ?>
            <div class="alert <?php echo $status === 'ok' ? 'alert-success' : 'alert-danger'; ?> mb-4">
                <span><?php echo $status === 'ok' ? '✅' : '⚠️'; ?></span>
                <span><?php echo htmlspecialchars($message, ENT_QUOTES); ?></span>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <div class="card-header-icon">🔎</div>
                <div>
                    <h2 style="margin:0;font-size:1.05rem;">Buscar y cargar estudios por CEDULA de paciente</h2>
                    <p class="hint">
                        Primero se buscan estudios en Orthanc. Si falta algo en el rango de fechas indicado,
                        se trae automáticamente desde Canvas.
                    </p>
                </div>
            </div>

            <form method="get">

                <label for="patient_id">ID de paciente (PatientID)</label>
                  <input type="text"
                      id="patient_id"
                      name="patient_id"
                      placeholder="Ejemplo: 123456789"
                      value="<?php echo htmlspecialchars($patientIdValue, ENT_QUOTES); ?>">

                <label>Rango de fechas (opcional)</label>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <div style="flex:1 1 140px;margin-bottom:4px;">
                        <span class="hint">Desde:</span>
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFromValue, ENT_QUOTES); ?>">
                    </div>
                    <div style="flex:1 1 140px;margin-bottom:4px;">
                        <span class="hint">Hasta:</span>
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateToValue, ENT_QUOTES); ?>">
                    </div>
                </div>



                <label for="modalities">Modalidades (opcional)</label>
                <div class="modality-filter">
                    <button type="button" id="modality-toggle" class="modality-toggle">Seleccionar modalidades</button>
                    <div id="modality-options" class="modality-options">
                        <?php foreach ($allModalities as $mod): ?>
                            <label class="modality-option">
                                <input type="checkbox" name="modalities[]" value="<?php echo htmlspecialchars($mod, ENT_QUOTES); ?>" <?php if (in_array($mod, $selectedModalities)) echo 'checked'; ?>> <?php echo htmlspecialchars($mod, ENT_QUOTES); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="actions">
                    <button type="submit" class="btn btn-primary">
                        <span>🔄</span>
                        <span>Cargar / actualizar estudios</span>
                    </button>
                    <span class="hint">Solo se cargarán desde Canvas los estudios que aún no existan en Orthanc para ese rango.</span>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-header-icon">🩻</div>
                <div>
                    <h3 style="margin:0;font-size:1.0rem;">Estudios encontrados en Orthanc para este paciente</h3>
                    <p class="hint">Haz clic en “Ver en OHIF” para abrir el estudio en el visor web.</p>
                </div>
            </div>

            <?php if (!empty($studies)): ?>

            <table class="table table-striped table-hover">
                    <thead>
                    <tr>
                        <th><a href="?sort=date&page=1&patient_id=<?php echo urlencode($patientIdValue); ?>&date_from=<?php echo urlencode($dateFromValue); ?>&date_to=<?php echo urlencode($dateToValue); ?><?php echo $forceRemote ? '&force_remote=1' : ''; ?>" style="color: inherit; text-decoration: none;">Fecha<?php if ($sort === 'date') echo ($_SESSION['sort_order'] === 'asc' ? ' ↑' : ' ↓'); ?></a></th>
                        <th>ID Paciente</th>
                        <th><a href="?sort=name&page=1&patient_id=<?php echo urlencode($patientIdValue); ?>&date_from=<?php echo urlencode($dateFromValue); ?>&date_to=<?php echo urlencode($dateToValue); ?><?php echo $forceRemote ? '&force_remote=1' : ''; ?>" style="color: inherit; text-decoration: none;">Nombre<?php if ($sort === 'name') echo ($_SESSION['sort_order'] === 'asc' ? ' ↑' : ' ↓'); ?></a></th>
                        <th>Hora</th>
                        <th>Modalidad(es)</th>
                        <th>Descripción</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($studies as $s): ?>
                        <?php
                        $tags     = array_merge($s['MainDicomTags'] ?? [], $s['PatientMainDicomTags'] ?? []);
                        $studyUid = $tags['StudyInstanceUID'] ?? null;

                        // Obtener datos completos del estudio desde Orthanc para asegurar PatientID y PatientName
                        if ($studyUid) {
                            $fullTags = getOrthancStudyFullData($studyUid);
                            if ($fullTags && is_array($fullTags)) {
                                // Enriquecer tags con datos completos de Orthanc
                                $tags = array_merge($tags, $fullTags['MainDicomTags'] ?? [], $fullTags['PatientMainDicomTags'] ?? $fullTags);
                            }
                        }

                        $rawDate  = pickTag($tags, ['StudyDate', '0008,0020']);
                        $rawTime  = pickTag($tags, ['StudyTime', 'SeriesTime', 'AcquisitionTime', '0008,0030']) ?: ($s['StudyTime'] ?? '');
                        $desc     = pickTag($tags, ['StudyDescription', 'SeriesDescription', 'ProtocolName']);
                        // Obtener PatientID y PatientName por separado (sin fallback cruzado)
                        // Si la búsqueda fue por PatientID, usar ese valor como ID mostrado
                        $patientId = $patientIdValue !== '' ? $patientIdValue : pickTag($tags, ['PatientID', '0010,0020']);
                        // Si tenemos $globalPatientName desde la query Patient, usarlo como nombre
                        $patientName = !empty($globalPatientName) ? $globalPatientName : pickTag($tags, ['PatientName', '0010,0010', 'Patient']);
                        $patientName = $patientName !== '' ? str_replace('^', ' ', $patientName) : '';
                        $patientName = preg_replace('/^PatientName\s+String\s+/i', '', $patientName);
                        // Si no obtuvimos nombre en la respuesta inicial, intentar obtenerlo desde los tags completos del estudio en Orthanc
                        if (($patientName === '' || strtolower($patientName) === 'sin nombre') && $studyUid) {
                            $full = getOrthancStudyFullData($studyUid);
                            if (!empty($full) && isset($full['PatientMainDicomTags']['PatientName']) && $full['PatientMainDicomTags']['PatientName'] !== '') {
                                $tempName = $full['PatientMainDicomTags']['PatientName'];
                                if (is_array($tempName)) {
                                    if (isset($tempName['Alphabetic'])) {
                                        $tempName = (string) $tempName['Alphabetic'];
                                    } else {
                                        $tempName = implode(' ', array_filter(array_map('strval', $tempName)));
                                    }
                                } else {
                                    $tempName = (string) $tempName;
                                }
                                $patientName = str_replace('^', ' ', $tempName);
                                $patientName = preg_replace('/^PatientName\s+String\s+/i', '', $patientName);
                            }
                        }
                        if (empty($tags['ModalitiesInStudy']) && !empty($s['_local']) && $studyUid) {
                            $full = getOrthancStudyFullData($studyUid);
                            if ($full && isset($full['MainDicomTags']['ModalitiesInStudy'])) {
                                $tags['ModalitiesInStudy'] = $full['MainDicomTags']['ModalitiesInStudy'];
                            }
                        }
                        if ($patientName === '') {
                            $patientName = 'Sin nombre';
                        }
                        $dateText = formatDicomDate($rawDate);
                        $timeText = formatDicomTime($rawTime);

                        $mods = '';
                        if (!empty($tags['ModalitiesInStudy']) && is_array($tags['ModalitiesInStudy'])) {
                            $mods = implode(',', $tags['ModalitiesInStudy']);
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
                        $mappedMods = array_map(function($m) use ($modalityMap) {
                            $trimmed = trim($m);
                            return $modalityMap[$trimmed] ?? $trimmed;
                        }, $modList);
                        $mods = implode(',', $mappedMods);

                        if (!$mods && $studyUid) {
                            // fetch modalities from Orthanc
                            try {
                                $series = callOrthanc('GET', '/studies/' . urlencode($studyUid) . '/series');
                                if (is_array($series)) {
                                    $modalities = [];
                                    foreach ($series as $ser) {
                                        if (isset($ser['MainDicomTags']['Modality'])) {
                                            $modalities[] = $ser['MainDicomTags']['Modality'];
                                        }
                                    }
                                    $modalities = array_unique($modalities);
                                    if (!empty($modalities)) {
                                        $mods = implode(',', $modalities);
                                        $modList = explode(',', $mods);
                                        $mappedMods = array_map(function($m) use ($modalityMap) {
                                            $trimmed = trim($m);
                                            return $modalityMap[$trimmed] ?? $trimmed;
                                        }, $modList);
                                        $mods = implode(',', $mappedMods);
                                    }
                                }
                            } catch (Exception $e) {
                                // ignore if study not found
                            }
                        }

                        if (!$mods) {
                            // Inferir modalidad desde la descripción
                            $inferred = getInferredModality($desc);
                            $mods = $inferred ?: 'N/D';
                        }

                        $studyUid = $tags['StudyInstanceUID'] ?? null;
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($dateText ?: 'N/D', ENT_QUOTES); ?></td>
                                <td><?php echo htmlspecialchars($patientId ?: 'N/D', ENT_QUOTES); ?></td>
                                <td><?php echo htmlspecialchars($patientName ?: 'N/D', ENT_QUOTES); ?></td>
                                <td><?php echo htmlspecialchars($timeText ?: 'N/D', ENT_QUOTES); ?></td>
                            <td>
                                <?php if ($mods): ?>
                                    <span class="modality-badge">
                                        <?php echo htmlspecialchars($mods, ENT_QUOTES); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="modality-badge">N/D</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($desc, ENT_QUOTES); ?></td>
                            <td>
                                <?php if ($studyUid): ?>
                                    <?php if (!empty($s['_local'])): ?>
                                        <a href="?action=view&study_uid=<?php echo urlencode($studyUid); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary">
                                            Visualizar con OHIF
                                        </a>
                                    <?php else: ?>
                                        <a href="?action=view&study_uid=<?php echo urlencode($studyUid); ?>&query_id=<?php echo urlencode($s['_remote']['queryId'] ?? ($_SESSION['last_query']['id'] ?? '')); ?>&answer_idx=<?php echo urlencode($s['_remote']['answerIdx'] ?? ''); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary">
                                            Visualizar con OHIF
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <em>Sin UID de estudio</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
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
                            <a href="?page=<?php echo $page - 1; ?>&sort=<?php echo $sort; ?>&patient_id=<?php echo urlencode($patientIdValue); ?>&date_from=<?php echo urlencode($dateFromValue); ?>&date_to=<?php echo urlencode($dateToValue); ?><?php echo $forceRemote ? '&force_remote=1' : ''; ?>" class="btn">◀ Anterior</a>
                        <?php endif; ?>
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&sort=<?php echo $sort; ?>&patient_id=<?php echo urlencode($patientIdValue); ?>&date_from=<?php echo urlencode($dateFromValue); ?>&date_to=<?php echo urlencode($dateToValue); ?><?php echo $forceRemote ? '&force_remote=1' : ''; ?>" class="btn">Siguiente ▶</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <p class="hint">No se encontraron estudios que coincidan con los criterios de búsqueda.</p>
            <?php endif; ?>
            </div>

    <?php endif; // fin rama logueado / no logueado ?>
</div>



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

    // Limpiar fechas cuando se ingresa PatientID
    document.getElementById('patient_id').addEventListener('input', function() {
        if (this.value.trim() !== '') {
            document.getElementById('date_from').value = '';
            document.getElementById('date_to').value = '';
        }
    });

    // No longer needed since all are forms now
})();
</script>

<!-- Modality filter toggle -->

<script>
document.addEventListener('DOMContentLoaded', function() {
    var toggle = document.getElementById('modality-toggle');
    var options = document.getElementById('modality-options');
    if (toggle && options) {
        toggle.addEventListener('click', function() {
            options.style.display = options.style.display === 'block' ? 'none' : 'block';
        });
        // Update toggle text
        function updateToggleText() {
            var checked = document.querySelectorAll('input[name="modalities[]"]:checked');
            var texts = Array.from(checked).map(c => c.value);
            toggle.textContent = texts.length === 0 ? 'Seleccionar modalidades' : texts.join(', ');
        }
        updateToggleText();
        // When a modality is changed, update text and hide the options (close dropdown)
        document.querySelectorAll('input[name="modalities[]"]').forEach(function(cb) {
            cb.addEventListener('change', function(e) {
                updateToggleText();
                // Hide options after selection to mimic dropdown behavior
                options.style.display = 'none';
            });
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!toggle.contains(e.target) && !options.contains(e.target)) {
                options.style.display = 'none';
            }
        });

        // Close dropdown on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') options.style.display = 'none';
        });
    }
});
</script>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php ob_end_flush(); ?>

