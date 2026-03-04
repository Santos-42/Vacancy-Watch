<?php
/**
 * =============================================================================
 * Vacancy Watch — Anomaly API Endpoint (Module 2.3)
 * =============================================================================
 * Serves the pre-computed anomalies_cache.json as a JSON API response.
 * This endpoint NEVER queries MySQL directly (agent.md §5.2).
 *
 * Supports optional filtering via query parameters:
 *   ?type=zombie_property          Filter by anomaly type
 *   ?min_score=5                   Filter by minimum priority score
 *   ?limit=20                      Override result count (max 50)
 *
 * CORS headers are set for cross-origin frontend access (agent.md §4.3).
 * =============================================================================
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// CORS Headers (agent.md §4.3 — frontend and backend on different ports)
// ---------------------------------------------------------------------------

header('Access-Control-Allow-Origin: *');
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
// Locate and read cache file
// ---------------------------------------------------------------------------

$cacheFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'anomalies_cache.json';

if (!file_exists($cacheFile)) {
    http_response_code(503);
    echo json_encode([
        'error'   => 'Cache not generated yet. Run: php backend/scripts/generate_anomalies.php',
        'status'  => 'unavailable',
    ]);
    exit;
}

$raw = file_get_contents($cacheFile);
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
$limit = isset($_GET['limit']) ? min((int) $_GET['limit'], 50) : 50;
$anomalies = array_slice($anomalies, 0, $limit);

// ---------------------------------------------------------------------------
// Response
// ---------------------------------------------------------------------------

$response = [
    'generated_at' => $cache['generated_at'] ?? null,
    'anomalies'    => $anomalies,
    'count'        => count($anomalies),
    'summary'      => $cache['summary'] ?? [],
];

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
