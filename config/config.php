<?php
/**
 * ============================================================================
 * ARCHIVO DE CONFIGURACIÓN PRINCIPAL (config.php)
 * ============================================================================
 * Ajusta estos valores según tu entorno.
 */

// Entorno de red por defecto
$ENVIRONMENT = 'remote'; // Valores permitidos: 'local' (privada), 'remote' (pública)

// Orthanc HTTP en el mismo equipo (o servidorPACS)
$ORTHANC_URL = 'http://192.168.52.155:8042';

// Configuración de la IP dinámica dependiendo del entorno
if ($ENVIRONMENT === 'local') {
    $OHIF_BASE_URL = 'http://192.168.52.155:8042'; // IP Privada local
} else {
    $OHIF_BASE_URL = 'http://181.56.10.196:8042';  // IP Pública / Remota
}

// Alias de ClearCanvas en "DicomModalities" de Orthanc
$MODALITY_ID = 'CANVAS';

// AET de tu Orthanc (como en orthanc.json y en ClearCanvas)
$ORTHANC_AET = 'ORTHANC';

// Ruta de OHIF
$OHIF_VIEWER_PATH = '/ohif/viewer?StudyInstanceUIDs=';

// Usuarios para login (cámbialos por algo más seguro)
$USERS = [
    'admin' => '$2y$10$1hQIP5E4AOkgxs4AfLUw9ee/mln2.jyWFy/ngF9RfqWfBhqFLQN5W', // usuario: admin / clave: admin
    'MEDICO' => '$2y$10$nmo/DuvhjBpoxZymJoyBAO1o8d1MCbD0CQoziKssZgU9Dr/8YtZTe',
];

// Usuario invitado pedido: contraseña igual al nombre de usuario ('invitado').
$USERS['invitado'] = 'invitado';

// Límite de consultas simultáneas por usuario para mejorar concurrencia
define('MAX_CONCURRENT_QUERIES', 5);

// Control de Session Timeout y Fuerza Bruta
define('SESSION_TIMEOUT_SECONDS', 3600); // 60 minutos de inactividad máxima
define('MAX_LOGIN_ATTEMPTS', 3); // Intentos máximos de inicio de sesión
define('LOGIN_LOCKOUT_SECONDS', 300); // 5 minutos de bloqueo

?>
