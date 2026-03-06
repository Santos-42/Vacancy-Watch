<?php
/**
 * =============================================================================
 * Vacancy Watch — Anomaly Correlation Engine (Module 2.3)
 * =============================================================================
 * Cron-job script. Executes heavy SQL JOINs to find anomaly intersections,
 * then writes the result to anomalies_cache.json. The public API endpoint
 * reads this static file only — never hits MySQL directly.
 *
 * Usage:
 *   php backend/scripts/generate_anomalies.php
 *
 * Per agent.md §5.2: This script generates the cache. The API serves it.
 * Per agent.md §5.3: All queries enforce LIMIT 50.
 *
 * References (from documentation.md):
 *   - ArcGIS REST APIs: https://developers.arcgis.com/rest/
 *   - Montgomery Open Data: https://opendata.montgomeryal.gov
 * =============================================================================
 */

declare(strict_types=1);

ini_set('memory_limit', '512M');

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

/** Max anomalies per scenario (agent.md §5.3) */
const ANOMALY_LIMIT = 50;

/**
 * Anomaly detection window (days counted back from the LATEST data in the DB,
 * NOT from today). This makes the engine resilient to government data update
 * delays. COALESCE fallback to CURDATE() handles empty-database bootstrap.
 */
const ANOMALY_WINDOW_DAYS = 365;

/** SQL subquery: dynamic time anchor — latest violation date, or today if DB is empty */
const SQL_TIME_ANCHOR = 'COALESCE((SELECT MAX(date_filed) FROM code_violations), CURDATE())';

// ---------------------------------------------------------------------------
// Environment & Database (reuse from etl_montgomery.php)
// ---------------------------------------------------------------------------

function loadEnv(string $path): array
{
    if (!file_exists($path)) {
        throw new RuntimeException("Missing .env file at: $path");
    }
    $env = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim($value);
    }
    return $env;
}

function createPDO(array $env): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $env['DB_HOST'] ?? 'localhost',
        $env['DB_PORT'] ?? '3306',
        $env['DB_NAME'] ?? 'vacancy_watch'
    );
    return new PDO($dsn, $env['DB_USER'] ?? 'root', $env['DB_PASS'] ?? '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

// ---------------------------------------------------------------------------
// Scenario 1: Zombie Properties
// Properties registered as vacant AND accumulating code violations
// within the last 90 days.
// ---------------------------------------------------------------------------

function findZombieProperties(PDO $pdo): array
{
    $sql = "
        SELECT
            p.parcel_id,
            p.street_address,
            p.latitude,
            p.longitude,
            vr.status           AS vacancy_status,
            COUNT(cv.id)        AS violation_count,
            MAX(cv.date_filed)  AS latest_violation_date,
            (
                SELECT cv2.code_reference
                FROM code_violations cv2
                WHERE cv2.property_id = p.id
                ORDER BY cv2.date_filed DESC
                LIMIT 1
            )                   AS latest_violation_type
        FROM vacant_registrations vr
        INNER JOIN properties p
            ON vr.property_id = p.id
        INNER JOIN code_violations cv
            ON cv.property_id = p.id
            AND cv.date_filed >= DATE_SUB(
                " . SQL_TIME_ANCHOR . ",
                INTERVAL :window DAY
            )
        GROUP BY
            p.id, p.parcel_id, p.street_address, p.latitude, p.longitude,
            vr.status
        ORDER BY
            violation_count DESC
        LIMIT :lim
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':window', ANOMALY_WINDOW_DAYS, PDO::PARAM_INT);
    $stmt->bindValue(':lim', ANOMALY_LIMIT, PDO::PARAM_INT);
    $stmt->execute();

    $results = [];
    while ($row = $stmt->fetch()) {
        $results[] = [
            'anomaly_type'           => 'zombie_property',
            'parcel_id'              => $row['parcel_id'],
            'street_address'         => $row['street_address'],
            'latitude'               => (float) $row['latitude'],
            'longitude'              => (float) $row['longitude'],
            'priority_score'         => min((int) $row['violation_count'], 10),
            'details' => [
                'vacancy_status'         => $row['vacancy_status'],
                'violation_count'        => (int) $row['violation_count'],
                'latest_violation_date'  => $row['latest_violation_date'],
                'latest_violation_type'  => $row['latest_violation_type'],
                'window_days'            => ANOMALY_WINDOW_DAYS,
            ],
        ];
    }

    return $results;
}

// ---------------------------------------------------------------------------
// Scenario 2: Ghost Permits
// Properties with active construction permits that are ALSO vacant OR
// have open code violations within the last 180 days.
// ---------------------------------------------------------------------------

function findGhostPermits(PDO $pdo): array
{
    $sql = "
        SELECT
            p.parcel_id,
            p.street_address,
            p.latitude,
            p.longitude,
            cp.permit_number,
            cp.permit_type,
            cp.issue_date,
            cp.status               AS permit_status,
            CASE WHEN vr.id IS NOT NULL THEN 1 ELSE 0 END AS is_vacant,
            (
                SELECT COUNT(*)
                FROM code_violations cv
                WHERE cv.property_id = p.id
                  AND cv.date_filed >= DATE_SUB(
                    " . SQL_TIME_ANCHOR . ",
                    INTERVAL :window DAY
                  )
            )                       AS open_violations,
            " . SQL_TIME_ANCHOR . " AS _data_anchor
        FROM construction_permits cp
        INNER JOIN properties p
            ON cp.property_id = p.id
        LEFT JOIN vacant_registrations vr
            ON vr.property_id = p.id
        LEFT JOIN code_violations cv
            ON cv.property_id = p.id
            AND cv.date_filed >= DATE_SUB(
                " . SQL_TIME_ANCHOR . ",
                INTERVAL :window2 DAY
            )
        WHERE
            cp.status IN ('ISSUED', 'Pending')
            AND (
                vr.id IS NOT NULL
                OR cv.id IS NOT NULL
            )
        GROUP BY
            p.id, p.parcel_id, p.street_address, p.latitude, p.longitude,
            cp.permit_number, cp.permit_type, cp.issue_date, cp.status,
            vr.id
        ORDER BY
            cp.issue_date ASC
        LIMIT :lim
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':window', ANOMALY_WINDOW_DAYS, PDO::PARAM_INT);
    $stmt->bindValue(':window2', ANOMALY_WINDOW_DAYS, PDO::PARAM_INT);
    $stmt->bindValue(':lim', ANOMALY_LIMIT, PDO::PARAM_INT);
    $stmt->execute();

    $results = [];
    while ($row = $stmt->fetch()) {
        // Priority: older permits + more violations = higher score
        // Anchor age calculation to latest data date, not wall-clock time
        $anchorTs = $row['_data_anchor'] ? strtotime($row['_data_anchor']) : time();
        $ageDays = $row['issue_date']
            ? (int) (($anchorTs - strtotime($row['issue_date'])) / 86400)
            : 0;
        $score = min(10, (int) ($ageDays / 60) + (int) $row['open_violations']);

        $results[] = [
            'anomaly_type'    => 'ghost_permit',
            'parcel_id'       => $row['parcel_id'],
            'street_address'  => $row['street_address'],
            'latitude'        => (float) $row['latitude'],
            'longitude'       => (float) $row['longitude'],
            'priority_score'  => $score,
            'details' => [
                'permit_number'   => $row['permit_number'],
                'permit_type'     => $row['permit_type'],
                'issue_date'      => $row['issue_date'],
                'permit_status'   => $row['permit_status'],
                'is_vacant'       => (bool) $row['is_vacant'],
                'open_violations' => (int) $row['open_violations'],
                'window_days'     => ANOMALY_WINDOW_DAYS,
            ],
        ];
    }

    return $results;
}

// ---------------------------------------------------------------------------
// Scenario 3: Government Blind Spots
// City-owned surplus properties accumulating code violations.
// ---------------------------------------------------------------------------

function findGovernmentBlindSpots(PDO $pdo): array
{
    $sql = "
        SELECT
            p.parcel_id,
            p.street_address,
            p.latitude,
            p.longitude,
            sp.lot_size_sqft,
            sp.status               AS surplus_status,
            COUNT(cv.id)            AS violation_count,
            MAX(cv.date_filed)      AS latest_violation_date
        FROM surplus_properties sp
        INNER JOIN properties p
            ON sp.property_id = p.id
            AND p.ownership_type = 'City-Owned'
        INNER JOIN code_violations cv
            ON cv.property_id = p.id
        GROUP BY
            p.id, p.parcel_id, p.street_address, p.latitude, p.longitude,
            sp.lot_size_sqft, sp.status
        ORDER BY
            violation_count DESC,
            sp.lot_size_sqft DESC
        LIMIT :lim
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':lim', ANOMALY_LIMIT, PDO::PARAM_INT);
    $stmt->execute();

    $results = [];
    while ($row = $stmt->fetch()) {
        // Priority: more violations + larger lot = higher score
        $violScore = min(7, (int) $row['violation_count']);
        $sizeScore = min(3, (int) (($row['lot_size_sqft'] ?? 0) / 5000));
        $score = $violScore + $sizeScore;

        $results[] = [
            'anomaly_type'    => 'government_blind_spot',
            'parcel_id'       => $row['parcel_id'],
            'street_address'  => $row['street_address'],
            'latitude'        => (float) $row['latitude'],
            'longitude'       => (float) $row['longitude'],
            'priority_score'  => min(10, $score),
            'details' => [
                'lot_size_sqft'         => $row['lot_size_sqft'] ? (float) $row['lot_size_sqft'] : null,
                'surplus_status'        => $row['surplus_status'],
                'violation_count'       => (int) $row['violation_count'],
                'latest_violation_date' => $row['latest_violation_date'],
            ],
        ];
    }

    return $results;
}

// ---------------------------------------------------------------------------
// Main execution
// ---------------------------------------------------------------------------

$baseDir = dirname(__DIR__);

// Load .env
$envPath = dirname($baseDir) . DIRECTORY_SEPARATOR . '.env';
if (!file_exists($envPath)) {
    $envPath = dirname($baseDir) . DIRECTORY_SEPARATOR . '.env.example';
}
if (!file_exists($envPath)) {
    fwrite(STDERR, "[FATAL] No .env found.\n");
    exit(1);
}

$env = loadEnv($envPath);

try {
    $pdo = createPDO($env);
    error_log("[OK] Connected to MySQL");
} catch (PDOException $e) {
    fwrite(STDERR, "[FATAL] DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// Run all three anomaly scenarios
$allAnomalies = [];
$summary = [
    'zombie_properties'       => 0,
    'ghost_permits'           => 0,
    'government_blind_spots'  => 0,
    'total'                   => 0,
];
$errors = [];

// Scenario 1: Zombie Properties
try {
    $zombies = findZombieProperties($pdo);
    $allAnomalies = array_merge($allAnomalies, $zombies);
    $summary['zombie_properties'] = count($zombies);
    error_log("[OK] Zombie Properties: " . count($zombies) . " found");
    unset($zombies);
    gc_collect_cycles();
} catch (Exception $e) {
    $errors[] = ['scenario' => 'zombie_properties', 'message' => $e->getMessage()];
    error_log("[ERROR] Zombie Properties: " . $e->getMessage());
}

// Scenario 2: Ghost Permits
try {
    $ghosts = findGhostPermits($pdo);
    $allAnomalies = array_merge($allAnomalies, $ghosts);
    $summary['ghost_permits'] = count($ghosts);
    error_log("[OK] Ghost Permits: " . count($ghosts) . " found");
    unset($ghosts);
    gc_collect_cycles();
} catch (Exception $e) {
    $errors[] = ['scenario' => 'ghost_permits', 'message' => $e->getMessage()];
    error_log("[ERROR] Ghost Permits: " . $e->getMessage());
}

// Scenario 3: Government Blind Spots
try {
    $blindSpots = findGovernmentBlindSpots($pdo);
    $allAnomalies = array_merge($allAnomalies, $blindSpots);
    $summary['government_blind_spots'] = count($blindSpots);
    error_log("[OK] Government Blind Spots: " . count($blindSpots) . " found");
    unset($blindSpots);
    gc_collect_cycles();
} catch (Exception $e) {
    $errors[] = ['scenario' => 'government_blind_spots', 'message' => $e->getMessage()];
    error_log("[ERROR] Government Blind Spots: " . $e->getMessage());
}

$summary['total'] = count($allAnomalies);

// Sort all anomalies by priority_score descending
usort($allAnomalies, fn($a, $b) => $b['priority_score'] <=> $a['priority_score']);

// Write cache file
$cachePayload = [
    'generated_at' => date('c'),
    'anomalies'    => $allAnomalies,
    'summary'      => $summary,
    'errors'       => $errors,
];

$cacheDir  = $baseDir . DIRECTORY_SEPARATOR . 'data';
$cachePath = $cacheDir . DIRECTORY_SEPARATOR . 'anomalies_cache.json';

if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

// Atomic write: write to temp file, then rename to avoid race conditions.
// If get_anomalies.php reads mid-write, it gets the old complete file, never a truncated one.
$tempPath = $cachePath . '.tmp';
file_put_contents($tempPath, json_encode($cachePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
rename($tempPath, $cachePath);

error_log("[OK] Cache written atomically to: $cachePath ({$summary['total']} anomalies)");

// Output summary to stdout
echo json_encode([
    'status'       => 'complete',
    'generated_at' => $cachePayload['generated_at'],
    'summary'      => $summary,
    'cache_path'   => $cachePath,
    'errors'       => $errors,
], JSON_PRETTY_PRINT) . PHP_EOL;
