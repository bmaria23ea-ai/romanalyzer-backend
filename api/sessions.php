<?php
/**
 * ROMAnalyzer — API REST
 * Servidor: Apache HTTP Server
 * Base de datos: Turso (SQLite en la nube via HTTP API)
 */

// ── CORS ────────────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── TURSO CONFIG ────────────────────────────────────────────────────
define('TURSO_URL',   'https://romanalyzer-bmaria23ea-ai.aws-us-east-1.turso.io');
define('TURSO_TOKEN', 'eyJhbGciOiJFZERTQSIsInR5cCI6IkpXVCJ9.eyJhIjoicnciLCJpYXQiOjE3NzU5MzUyOTYsImlkIjoiMDE5ZDdkZmMtNDIwMS03ZDVkLWJkNDQtZDg0MzA2ODFjZTM2IiwicmlkIjoiM2RjNWNhZDEtMzA3Mi00OTIzLTgyNDEtOTRiMWRmMDlhYzUyIn0.GxQFAhBXkhYOwn4Cq5EXg1e5gxOi1AAUPXHDXrw0Nbelnrf6x8EA5B8UxAXaaKlbAVCY41-xAV7YHN3uqOeSAA');

// ── FUNCIÓN: ejecutar SQL en Turso ──────────────────────────────────
function turso($statements) {
    $requests = array_map(fn($s) => [
        'type' => 'execute',
        'stmt' => isset($s['args'])
            ? ['sql' => $s['sql'], 'named_args' => array_map(
                fn($k, $v) => ['name' => $k, 'value' => ['type' => 'text', 'value' => (string)$v]],
                array_keys($s['args']), $s['args']
              )]
            : ['sql' => $s['sql']]
    ], $statements);
    $requests[] = ['type' => 'close'];

    $ch = curl_init(TURSO_URL . '/v2/pipeline');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . TURSO_TOKEN,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode(['requests' => $requests]),
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) throw new Exception('Turso cURL error: ' . $err);
    $data = json_decode($res, true);
    if (!$data) throw new Exception('Turso respuesta invalida');
    return $data['results'] ?? [];
}

// ── CREAR TABLA si no existe ────────────────────────────────────────
turso([[
    'sql' => "CREATE TABLE IF NOT EXISTS sessions (
        id TEXT PRIMARY KEY,
        data TEXT NOT NULL,
        created_at TEXT DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
    )"
]]);

// ── ROUTER ──────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$parts  = array_values(array_filter(explode('/', $uri)));
$id     = $parts[2] ?? null;

try {

    // GET /api/sessions.php → todas las sesiones
    if ($method === 'GET' && !$id && !isset($_GET['maxid'])) {
        $res  = turso([['sql' => "SELECT data FROM sessions ORDER BY created_at DESC"]]);
        $rows = $res[0]['response']['result']['rows'] ?? [];
        $out  = array_map(fn($r) => $r[0]['value'], $rows);
        echo '[' . implode(',', $out) . ']';

    // GET /api/sessions.php?maxid=1 → id maximo
    } elseif ($method === 'GET' && isset($_GET['maxid'])) {
        $res  = turso([['sql' => "SELECT id FROM sessions"]]);
        $rows = $res[0]['response']['result']['rows'] ?? [];
        $ids  = array_map(fn($r) => intval($r[0]['value']), $rows);
        echo json_encode(['maxId' => empty($ids) ? 0 : max($ids)]);

    // POST /api/sessions.php → crear sesion
    } elseif ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body || !isset($body['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Body invalido']);
            exit;
        }
        turso([[
            'sql'  => "INSERT INTO sessions (id, data) VALUES (:id, :data)",
            'args' => [':id' => $body['id'], ':data' => json_encode($body)]
        ]]);
        http_response_code(201);
        echo json_encode(['ok' => true, 'id' => $body['id']]);

    // PATCH /api/sessions.php/:id → actualizar paciente y notas
    } elseif ($method === 'PATCH' && $id) {
        $body = json_decode(file_get_contents('php://input'), true);
        $res  = turso([[
            'sql'  => "SELECT data FROM sessions WHERE id = :id",
            'args' => [':id' => $id]
        ]]);
        $rows = $res[0]['response']['result']['rows'] ?? [];
        if (empty($rows)) {
            http_response_code(404);
            echo json_encode(['error' => "Sesion #$id no encontrada"]);
            exit;
        }
        $ses = json_decode($rows[0][0]['value'], true);
        if (array_key_exists('paciente', $body)) $ses['paciente'] = $body['paciente'];
        if (array_key_exists('notas',    $body)) $ses['notas']    = $body['notas'];
        turso([[
            'sql'  => "UPDATE sessions SET data = :data WHERE id = :id",
            'args' => [':data' => json_encode($ses), ':id' => $id]
        ]]);
        echo json_encode(['ok' => true]);

    // DELETE /api/sessions.php/:id → eliminar sesion
    } elseif ($method === 'DELETE' && $id) {
        turso([[
            'sql'  => "DELETE FROM sessions WHERE id = :id",
            'args' => [':id' => $id]
        ]]);
        echo json_encode(['ok' => true, 'deleted' => $id]);

    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Metodo no permitido']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
