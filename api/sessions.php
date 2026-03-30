<?php
/**
 * ROMAnalyzer — API REST
 * Servidor: Apache HTTP Server (via mod_php)
 * Base de datos: SQLite 3 (via PDO)
 *
 * Rutas:
 *   GET    /api/sessions          → listar todas las sesiones
 *   GET    /api/sessions/_maxid   → id máximo (para auto-incremento)
 *   POST   /api/sessions          → crear sesión
 *   PATCH  /api/sessions/:id      → actualizar paciente + notas
 *   DELETE /api/sessions/:id      → eliminar sesión
 */

// ── CORS (necesario: GitHub Pages → Render son orígenes distintos) ──
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

// Respuesta a pre-flight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── CONEXIÓN SQLite ──────────────────────────────────────────────────
// /data es el disco persistente montado en Render
// En desarrollo local puedes usar __DIR__.'/../romanalyzer.db'
$dbPath = '/tmp/romanalyzer.db';
try {
    $db = new PDO('sqlite:'.$dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL");   // mejor rendimiento en concurrencia
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed: '.$e->getMessage()]);
    exit;
}

// ── CREAR TABLA si no existe ─────────────────────────────────────────
$db->exec("
    CREATE TABLE IF NOT EXISTS sessions (
        id         TEXT        PRIMARY KEY,
        data       TEXT        NOT NULL,
        created_at TEXT        DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
    )
");

// ── ROUTER ──────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Ejemplo: /api/sessions  o  /api/sessions/42  o  /api/sessions/_maxid
$parts  = array_values(array_filter(explode('/', $uri)));
$id     = $parts[2] ?? null;   // null | '_maxid' | '1' | '2' …

try {
    // ── GET /api/sessions → todas las sesiones ordenadas por fecha desc ──
    if ($method === 'GET' && !$id) {
        $stmt = $db->query("SELECT data FROM sessions ORDER BY created_at DESC");
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);     // array de strings JSON
        // Devolver array JSON sin re-serializar cada objeto
        echo '[' . implode(',', ($rows ?: [])) . ']';

    // ── GET /api/sessions/_maxid → id numérico más alto ─────────────
    } elseif ($method === 'GET' && $id === '_maxid') {
        $stmt = $db->query("SELECT id FROM sessions");
        $ids  = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $max  = empty($ids) ? 0 : max(array_map('intval', $ids));
        echo json_encode(['maxId' => $max]);

    // ── POST /api/sessions → insertar nueva sesión ──────────────────
    } elseif ($method === 'POST' && !$id) {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body || !isset($body['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Body inválido — se requiere campo id']);
            exit;
        }
        $stmt = $db->prepare("INSERT INTO sessions (id, data) VALUES (:id, :data)");
        $stmt->execute([':id' => $body['id'], ':data' => json_encode($body)]);
        http_response_code(201);
        echo json_encode(['ok' => true, 'id' => $body['id']]);

    // ── PATCH /api/sessions/:id → actualizar paciente y notas ───────
    } elseif ($method === 'PATCH' && $id) {
        $body = json_decode(file_get_contents('php://input'), true);

        // Leer sesión actual
        $stmt = $db->prepare("SELECT data FROM sessions WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $raw = $stmt->fetchColumn();
        if ($raw === false) {
            http_response_code(404);
            echo json_encode(['error' => "Sesión #$id no encontrada"]);
            exit;
        }

        // Mezclar cambios y guardar
        $ses = json_decode($raw, true);
        if (array_key_exists('paciente', $body)) $ses['paciente'] = $body['paciente'];
        if (array_key_exists('notas',    $body)) $ses['notas']    = $body['notas'];

        $stmt2 = $db->prepare("UPDATE sessions SET data = :data WHERE id = :id");
        $stmt2->execute([':data' => json_encode($ses), ':id' => $id]);
        echo json_encode(['ok' => true]);

    // ── DELETE /api/sessions/:id → eliminar sesión ──────────────────
    } elseif ($method === 'DELETE' && $id) {
        $stmt = $db->prepare("DELETE FROM sessions WHERE id = :id");
        $stmt->execute([':id' => $id]);
        echo json_encode(['ok' => true, 'deleted' => $id]);

    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Método no permitido']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
