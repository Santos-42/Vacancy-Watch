<?php
/**
 * =============================================================================
 * Vacancy Watch — Anomaly API Endpoint (Module 2.3 + 2.4)
 * =============================================================================
 * Serves the pre-computed anomalies_cache.json as a JSON API response.
 * This endpoint NEVER queries MySQL directly (agent.md §5.2).
 *
 * Supports optional filtering via query parameters:
 *   ?type=zombie_property          Filter by anomaly type
 *   ?min_score=5                   Filter by minimum priority score
 *   ?limit=20                      Override result count (max 50)
 *   ?mode=light                    Strip payload to coordinates + address +
 *                                  anomaly_type + parcel_id only (Module 2.4)
 *
 * CORS origin is loaded from .env (CORS_ORIGIN) — no wildcard (agent.md §4.3).
 * Cache staleness is validated against a 48-hour TTL window.
 * =============================================================================
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Environment: CORS origin
// ---------------------------------------------------------------------------
// Production: read from server config (Apache SetEnv, Nginx fastcgi_param, Docker ENV).
//   Zero disk I/O per request.
// Development fallback: parse .env file only if getenv() returns nothing.
// ---------------------------------------------------------------------------

$allowedOrigin = getenv('CORS_ORIGIN') ?: null;

if (!$allowedOrigin) {
    // Dev fallback: parse .env (acceptable for single-user local dev)
    $envPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
    $env = [];
    if (file_exists($envPath)) {
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (strpos($line, '=') === false) continue;
            [$key, $value] = explode('=', $line, 2);
            $env[trim($key)] = trim($value);
        }
    }
    $allowedOrigin = $env['CORS_ORIGIN'] ?? 'http://localhost:5500';
}

header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Only GET allowed
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// ---------------------------------------------------------------------------
// Locate and read cache file (WITH AUTO-HEAL)
// ---------------------------------------------------------------------------

$cacheFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'anomalies_cache.json';
$needsHeal = false;

/** Maximum cache age in seconds (48 hours) */
const CACHE_TTL_SECONDS = 48 * 60 * 60;

if (!file_exists($cacheFile)) {
    $needsHeal = true;
} else {
    $cacheAge = time() - filemtime($cacheFile);
    if ($cacheAge > CACHE_TTL_SECONDS) {
        $needsHeal = true;
    }
}

// AUTO-HEAL SYSTEM: If cache is missing or stale, rebuild it this exact second.
if ($needsHeal) {
    $generateScript = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'generate_anomalies.php';
    
    // Execute generator script synchronously, force memory limit
    exec("php -d memory_limit=512M " . escapeshellarg($generateScript) . " > /dev/null 2>&1");
    
    // Final validation: Ensure the cache file was actually created after execution
    if (!file_exists($cacheFile)) {
        http_response_code(500); // 500 because this is an internal server failure preventing auto-heal
        echo json_encode([
            'error' => 'Auto-heal failed. Cannot generate cache.',
            'status' => 'fatal_error'
        ]);
        exit;
    }
}

// ---------------------------------------------------------------------------
// Load Cache
// ---------------------------------------------------------------------------

$raw   = file_get_contents($cacheFile);
$cache = json_decode($raw, true);

if ($cache === null) {
    http_response_code(500);
    echo json_encode(['error' => 'Corrupt cache file']);
    exit;
}

// ---------------------------------------------------------------------------
// Apply optional filters
// ---------------------------------------------------------------------------

$anomalies = $cache['anomalies'] ?? [];

// Filter by anomaly type
$typeFilter = $_GET['type'] ?? null;
if ($typeFilter !== null) {
    $anomalies = array_values(array_filter(
        $anomalies,
        fn($a) => ($a['anomaly_type'] ?? '') === $typeFilter
    ));
}

// Filter by minimum priority score
$minScore = isset($_GET['min_score']) ? (int) $_GET['min_score'] : null;
if ($minScore !== null) {
    $anomalies = array_values(array_filter(
        $anomalies,
        fn($a) => ($a['priority_score'] ?? 0) >= $minScore
    ));
}

// Apply limit (max 50, per agent.md §5.3)
$limit     = isset($_GET['limit']) ? min((int) $_GET['limit'], 50) : 50;
$anomalies = array_slice($anomalies, 0, $limit);

// ---------------------------------------------------------------------------
// Mode: light — strip to coordinates + address + anomaly_type + parcel_id
// (Module 2.4: lightweight payload for frontend map consumption)
// ---------------------------------------------------------------------------

$mode = $_GET['mode'] ?? 'full';

if ($mode === 'light') {
    $anomalies = array_map(fn(array $a): array => [
        'parcel_id'      => $a['parcel_id']      ?? null,
        'latitude'       => $a['latitude']        ?? null,
        'longitude'      => $a['longitude']       ?? null,
        'street_address' => $a['street_address']  ?? null,
        'anomaly_type'   => $a['anomaly_type']    ?? null,
        'priority_score' => $a['priority_score']  ?? 0,
    ], $anomalies);
}

// ---------------------------------------------------------------------------
// Response
// ---------------------------------------------------------------------------

$response = [
    'generated_at' => $cache['generated_at'] ?? null,
    'count'        => count($anomalies),
    'anomalies'    => $anomalies,
];

// Include summary only in full mode
if ($mode !== 'light') {
    $response['summary'] = $cache['summary'] ?? [];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
