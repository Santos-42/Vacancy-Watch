<?php
/**
 * =============================================================================
 * Vacancy Watch — Montgomery AL ETL Pipeline (Module 2.2)
 * =============================================================================
 * Reads raw JSON from Module 2.1 (backend/data/raw/), cleans data,
 * normalizes addresses, and bulk-upserts into MySQL.
 *
 * Usage:
 *   php backend/scripts/etl_montgomery.php          # Live data from raw/
 *   php backend/scripts/etl_montgomery.php --mock    # Test with mock_data/
 *
 * References (from documentation.md):
 *   - ArcGIS REST APIs: https://developers.arcgis.com/rest/
 *   - Montgomery Open Data: https://opendata.montgomeryal.gov
 * =============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

const BATCH_SIZE = 500;

/**
 * Dataset registry: slug → processing config.
 * 'coordPath' determines where to read lat/lng from the feature.
 */
const DATASETS = [
    'code_violations' => [
        'parcelField'  => 'ParcelNo',
        'addressField' => 'Address1',
        'coordPath'    => 'geometry',  // Point: geometry.x / geometry.y
        'processor'    => 'processCodeViolations',
    ],
    'construction_permits' => [
        'parcelField'  => 'ParcelNo',
        'addressField' => 'PhysicalAddress',
        'coordPath'    => 'geometry',
        'processor'    => 'processConstructionPermits',
    ],
    'vacant_properties' => [
        'parcelField'  => 'Parcel_ID',
        'addressField' => 'Address',
        'coordPath'    => 'centroid',  // Polygon: centroid.x / centroid.y
        'processor'    => 'processVacantProperties',
    ],
    'surplus_properties' => [
        'parcelField'  => 'PARCEL_NUM',
        'addressField' => null,  // Assembled from STREET_NUM + STREET_NAM
        'coordPath'    => 'centroid',
        'processor'    => 'processSurplusProperties',
    ],
];

// ---------------------------------------------------------------------------
// Environment loader (.env)
// ---------------------------------------------------------------------------

/**
 * Parse a .env file into an associative array.
 */
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

// ---------------------------------------------------------------------------
// Database connection (PDO)
// ---------------------------------------------------------------------------

function createPDO(array $env): PDO
{
    // Prioritaskan Environment Variables dari server Render. Jika tidak ada, fallback ke array (lokal).
    $host = getenv('DB_HOST') ?: ($env['DB_HOST'] ?? 'localhost');
    $port = getenv('DB_PORT') ?: ($env['DB_PORT'] ?? '3306');
    $db   = getenv('DB_NAME') ?: ($env['DB_NAME'] ?? 'vacancy_watch');
    $user = getenv('DB_USER') ?: ($env['DB_USER'] ?? 'root');
    $pass = getenv('DB_PASS') ?: ($env['DB_PASS'] ?? '');

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $db);

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

// ---------------------------------------------------------------------------
// Transform functions
// ---------------------------------------------------------------------------

/**
 * Normalize address strings for consistent cross-table matching.
 * Returns NULL for empty/whitespace-only input.
 */
function normalizeAddress(?string $raw): ?string
{
    if ($raw === null) return null;

    $addr = trim($raw);
    if ($addr === '') return null;

    // Uppercase for consistency
    $addr = strtoupper($addr);

    // Collapse multiple whitespace to single space
    $addr = preg_replace('/\s+/', ' ', $addr);

    // Remove trailing city/state/zip patterns (e.g. " MONTGOMERY AL 36109")
    $addr = preg_replace('/\s+MONTGOMERY\s+AL\s+\d{5}(-\d{4})?$/i', '', $addr);

    return trim($addr) ?: null;
}

/**
 * Universal date parser: handles both ISO strings and epoch milliseconds.
 * ArcGIS REST APIs may return dates as epoch ms (13-digit integers).
 *
 * @param  mixed $value  Date string, epoch ms integer, or null
 * @return string|null   'Y-m-d' format or null
 */
function parseDate($value): ?string
{
    if ($value === null || $value === '') return null;

    // Epoch milliseconds (13-digit number)
    if (is_numeric($value) && strlen((string) abs((int) $value)) >= 10) {
        $seconds = (int) ($value / 1000);
        return date('Y-m-d', $seconds);
    }

    // ISO string (YYYY-MM-DD or YYYY-MM-DDT...)
    if (is_string($value)) {
        $ts = strtotime($value);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }
    }

    return null;
}

/**
 * Extract centroid coordinates from a polygon feature.
 * Falls back to arithmetic mean of outer ring if centroid property is absent.
 */
function extractCentroid(array $feature): ?array
{
    // Primary: use API-provided centroid
    if (isset($feature['centroid']['x'], $feature['centroid']['y'])) {
        return [
            'x' => (float) $feature['centroid']['x'],
            'y' => (float) $feature['centroid']['y'],
        ];
    }

    // Fallback: calculate from outer ring
    if (isset($feature['geometry']['rings'][0])) {
        $ring = $feature['geometry']['rings'][0];
        $n = count($ring) - 1; // Exclude closing vertex
        if ($n < 1) return null;

        $sumX = $sumY = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sumX += (float) $ring[$i][0];
            $sumY += (float) $ring[$i][1];
        }
        return ['x' => $sumX / $n, 'y' => $sumY / $n];
    }

    return null;
}

/**
 * Extract coordinates from a feature based on geometry type.
 *
 * @return array|null ['lat' => float, 'lng' => float]
 */
function extractCoordinates(array $feature, string $coordPath): ?array
{
    if ($coordPath === 'geometry') {
        // Point geometry
        $x = $feature['geometry']['x'] ?? null;
        $y = $feature['geometry']['y'] ?? null;
        if ($x === null || $y === null) return null;
        return ['lat' => (float) $y, 'lng' => (float) $x];
    }

    // Polygon → centroid
    $centroid = extractCentroid($feature);
    if ($centroid === null) return null;
    return ['lat' => $centroid['y'], 'lng' => $centroid['x']];
}

/**
 * Strip whitespace from parcel IDs for consistency.
 */
function normalizeParcelId(?string $raw): ?string
{
    if ($raw === null) return null;
    $clean = preg_replace('/\s+/', '', trim($raw));
    return $clean !== '' ? $clean : null;
}

// ---------------------------------------------------------------------------
// Bulk Upsert Engine
// ---------------------------------------------------------------------------

/**
 * Bulk upsert rows into a MySQL table using INSERT ... ON DUPLICATE KEY UPDATE.
 * Processes in batches of BATCH_SIZE to avoid N+1 queries.
 *
 * @param  PDO    $pdo        Database connection
 * @param  string $table      Target table name
 * @param  array  $columns    Column names for INSERT
 * @param  array  $rows       Array of associative arrays (column => value)
 * @param  array  $updateCols Columns to update on duplicate
 * @return int                Number of affected rows
 */
function bulkUpsert(PDO $pdo, string $table, array $columns, array $rows, array $updateCols): int
{
    if (empty($rows)) return 0;

    $affected = 0;
    $colCount = count($columns);
    $colList  = implode(', ', $columns);

    foreach (array_chunk($rows, BATCH_SIZE) as $batch) {
        $placeholders = [];
        $values = [];

        foreach ($batch as $row) {
            $placeholders[] = '(' . implode(', ', array_fill(0, $colCount, '?')) . ')';
            foreach ($columns as $col) {
                $values[] = $row[$col] ?? null;
            }
        }

        $updateClause = implode(', ', array_map(
            fn(string $c) => "$c = VALUES($c)",
            $updateCols
        ));

        $sql = "INSERT INTO $table ($colList) VALUES "
             . implode(', ', $placeholders)
             . " ON DUPLICATE KEY UPDATE $updateClause";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        $affected += $stmt->rowCount();
    }

    return $affected;
}

// ---------------------------------------------------------------------------
// Properties master upsert (shared across all datasets)
// ---------------------------------------------------------------------------

/**
 * Upsert properties and return a map of parcel_id → property id.
 *
 * @return array<string, int> Parcel ID → properties.id
 */
function upsertProperties(PDO $pdo, array $propertyRows): array
{
    if (empty($propertyRows)) return [];

    // Bulk upsert — ownership_type only updates if currently 'Unknown'
    $columns = ['parcel_id', 'street_address', 'city', 'zip_code', 'ownership_type', 'latitude', 'longitude'];
    $updateCols = ['street_address', 'latitude', 'longitude'];

    bulkUpsert($pdo, 'properties', $columns, $propertyRows, $updateCols);

    // Fetch IDs for all parcel_ids we just upserted
    $parcelIds = array_unique(array_column($propertyRows, 'parcel_id'));
    $placeholders = implode(', ', array_fill(0, count($parcelIds), '?'));
    $stmt = $pdo->prepare("SELECT id, parcel_id FROM properties WHERE parcel_id IN ($placeholders)");
    $stmt->execute($parcelIds);

    $map = [];
    while ($row = $stmt->fetch()) {
        $map[$row['parcel_id']] = (int) $row['id'];
    }
    return $map;
}

// ---------------------------------------------------------------------------
// Dataset processors (Transform + Load)
// ---------------------------------------------------------------------------

function processCodeViolations(PDO $pdo, iterable $features, array $config): array
{
    $propertyRows = [];
    $childRows = [];
    $skipped = 0;

    $inserted = 0;
    $properties = 0;

    $flush = function() use (&$propertyRows, &$childRows, $pdo, &$inserted, &$properties) {
        if (empty($propertyRows)) return;
        $idMap = upsertProperties($pdo, array_values($propertyRows));
        $properties += count($idMap);

        $insertRows = [];
        foreach ($childRows as $row) {
            $propId = $idMap[$row['parcel_id']] ?? null;
            if ($propId === null) continue;
            $row['property_id'] = $propId;
            // Build strictly ordered array for insertion
            $insertArr = [];
            foreach (['property_id', 'source_id', 'case_number', 'date_filed', 'disposition', 'code_reference', 'condition_text'] as $col) {
                $insertArr[] = $row[$col] ?? null;
            }
            $insertRows[] = $row; // Actually, let's just leave it associative and let bulkUpsert extract by column name
        }

        $inserted += bulkUpsert(
            $pdo, 'code_violations',
            ['property_id', 'source_id', 'case_number', 'date_filed', 'disposition', 'code_reference', 'condition_text'],
            $insertRows,
            ['date_filed', 'disposition', 'condition_text']
        );
        $propertyRows = [];
        $childRows = [];
    };

    foreach ($features as $feature) {
        $attrs = $feature['attributes'] ?? [];

        $parcelId = normalizeParcelId($attrs[$config['parcelField']] ?? null);
        $coords   = extractCoordinates($feature, $config['coordPath']);

        // Skip: must have parcel ID AND coordinates
        if ($parcelId === null || $coords === null) {
            $skipped++;
            continue;
        }

        $address = normalizeAddress($attrs[$config['addressField']] ?? null);

        $propertyRows[$parcelId] = [
            'parcel_id'      => $parcelId,
            'street_address' => $address,
            'city'           => 'Montgomery',
            'zip_code'       => (string) ($attrs['Zip'] ?? ''),
            'ownership_type' => 'Unknown',
            'latitude'       => $coords['lat'],
            'longitude'      => $coords['lng'],
        ];

        $sourceId = $attrs['OffenceNum'] ?? null;
        if ($sourceId === null) {
            $skipped++;
            continue;
        }

        $childRows[] = [
            'parcel_id'      => $parcelId,
            'source_id'      => $sourceId,
            'case_number'    => $sourceId,
            'date_filed'     => parseDate($attrs['CaseDate'] ?? null),
            'disposition'    => $attrs['CaseStatus'] ?? null,
            'code_reference' => $attrs['CaseType'] ?? null,
            'condition_text' => $attrs['ComplaintRem'] ?? null,
        ];
    }

    // Upsert properties
    $idMap = upsertProperties($pdo, array_values($propertyRows));

    // Upsert child table
    $insertRows = [];
    foreach ($childRows as $row) {
        $propId = $idMap[$row['parcel_id']] ?? null;
        if ($propId === null) continue;

        $insertRows[] = [
            'property_id'    => $propId,
            'source_id'      => $row['source_id'],
            'case_number'    => $row['case_number'],
            'date_filed'     => $row['date_filed'],
            'disposition'    => $row['disposition'],
            'code_reference' => $row['code_reference'],
            'condition_text' => $row['condition_text'],
        ];
    }

    $affected = bulkUpsert(
        $pdo, 'code_violations',
        ['property_id', 'source_id', 'case_number', 'date_filed', 'disposition', 'code_reference', 'condition_text'],
        $insertRows,
        ['date_filed', 'disposition', 'condition_text']
    );

    return ['inserted' => $affected, 'skipped' => $skipped, 'properties' => count($idMap)];
}

function processConstructionPermits(PDO $pdo, iterable $features, array $config): array
{
    $propertyRows = [];
    $childRows = [];
    $skipped = 0;

    $inserted = 0;
    $properties = 0;

    $flush = function() use (&$propertyRows, &$childRows, $pdo, &$inserted, &$properties) {
        if (empty($propertyRows)) return;
        $idMap = upsertProperties($pdo, array_values($propertyRows));
        $properties += count($idMap);

        $insertRows = [];
        foreach ($childRows as $row) {
            $propId = $idMap[$row['parcel_id']] ?? null;
            if ($propId === null) continue;
            $row['property_id'] = $propId;
            // Build strictly ordered array for insertion
            $insertArr = [];
            foreach (['property_id', 'source_id', 'permit_number', 'permit_type', 'issue_date', 'status', 'description'] as $col) {
                $insertArr[] = $row[$col] ?? null;
            }
            $insertRows[] = $row; // Actually, let's just leave it associative and let bulkUpsert extract by column name
        }

        $inserted += bulkUpsert(
            $pdo, 'construction_permits',
            ['property_id', 'source_id', 'permit_number', 'permit_type', 'issue_date', 'status', 'description'],
            $insertRows,
            ['issue_date', 'status', 'description']
        );
        $propertyRows = [];
        $childRows = [];
    };

    foreach ($features as $feature) {
        $attrs = $feature['attributes'] ?? [];

        $parcelId = normalizeParcelId($attrs[$config['parcelField']] ?? null);
        $coords   = extractCoordinates($feature, $config['coordPath']);

        if ($parcelId === null || $coords === null) {
            $skipped++;
            continue;
        }

        $address = normalizeAddress($attrs[$config['addressField']] ?? null);

        $propertyRows[$parcelId] = [
            'parcel_id'      => $parcelId,
            'street_address' => $address,
            'city'           => 'Montgomery',
            'zip_code'       => '',
            'ownership_type' => 'Unknown',
            'latitude'       => $coords['lat'],
            'longitude'      => $coords['lng'],
        ];

        $sourceId = $attrs['PermitNo'] ?? null;
        if ($sourceId === null) {
            $skipped++;
            continue;
        }

        $childRows[] = [
            'parcel_id'      => $parcelId,
            'source_id'      => $sourceId,
            'permit_number'  => $sourceId,
            'permit_type'    => $attrs['UseType'] ?? null,
            'issue_date'     => parseDate($attrs['IssuedDate'] ?? null),
            'status'         => substr((string)($attrs['PermitStatus'] ?? 'Pending'), 0, 255),
            'description'    => $attrs['JobDescription'] ?? null,
        ];
    }

    $idMap = upsertProperties($pdo, array_values($propertyRows));

    $insertRows = [];
    foreach ($childRows as $row) {
        $propId = $idMap[$row['parcel_id']] ?? null;
        if ($propId === null) continue;

        $insertRows[] = [
            'property_id'    => $propId,
            'source_id'      => $row['source_id'],
            'permit_number'  => $row['permit_number'],
            'permit_type'    => $row['permit_type'],
            'issue_date'     => $row['issue_date'],
            'status'         => $row['status'],
            'description'    => $row['description'],
        ];
    }

    $affected = bulkUpsert(
        $pdo, 'construction_permits',
        ['property_id', 'source_id', 'permit_number', 'permit_type', 'issue_date', 'status', 'description'],
        $insertRows,
        ['issue_date', 'status', 'description']
    );

    return ['inserted' => $affected, 'skipped' => $skipped, 'properties' => count($idMap)];
}

function processVacantProperties(PDO $pdo, iterable $features, array $config): array
{
    $propertyRows = [];
    $childRows = [];
    $skipped = 0;

    $inserted = 0;
    $properties = 0;

    $flush = function() use (&$propertyRows, &$childRows, $pdo, &$inserted, &$properties) {
        if (empty($propertyRows)) return;
        $idMap = upsertProperties($pdo, array_values($propertyRows));
        $properties += count($idMap);

        $insertRows = [];
        foreach ($childRows as $row) {
            $propId = $idMap[$row['parcel_id']] ?? null;
            if ($propId === null) continue;
            $row['property_id'] = $propId;
            // Build strictly ordered array for insertion
            $insertArr = [];
            foreach (['property_id', 'source_id', 'status'] as $col) {
                $insertArr[] = $row[$col] ?? null;
            }
            $insertRows[] = $row; // Actually, let's just leave it associative and let bulkUpsert extract by column name
        }

        $inserted += bulkUpsert(
            $pdo, 'vacant_registrations',
            ['property_id', 'source_id', 'status'],
            $insertRows,
            ['status']
        );
        $propertyRows = [];
        $childRows = [];
    };

    foreach ($features as $feature) {
        $attrs = $feature['attributes'] ?? [];

        $parcelId = normalizeParcelId($attrs[$config['parcelField']] ?? null);
        $coords   = extractCoordinates($feature, $config['coordPath']);

        if ($parcelId === null || $coords === null) {
            $skipped++;
            continue;
        }

        $address = normalizeAddress($attrs[$config['addressField']] ?? null);

        $propertyRows[$parcelId] = [
            'parcel_id'      => $parcelId,
            'street_address' => $address,
            'city'           => 'Montgomery',
            'zip_code'       => '',
            'ownership_type' => 'Unknown',
            'latitude'       => $coords['lat'],
            'longitude'      => $coords['lng'],
        ];

        $sourceId = 'VAC_' . ($attrs['OBJECTID'] ?? '');
        if ($sourceId === 'VAC_') {
            $skipped++;
            continue;
        }

        $childRows[] = [
            'parcel_id' => $parcelId,
            'source_id' => $sourceId,
            'status'    => substr((string)($attrs['NEW__Sus'] ?? 'Open'), 0, 255),
        ];
    }

    $idMap = upsertProperties($pdo, array_values($propertyRows));

    $insertRows = [];
    foreach ($childRows as $row) {
        $propId = $idMap[$row['parcel_id']] ?? null;
        if ($propId === null) continue;

        $insertRows[] = [
            'property_id' => $propId,
            'source_id'   => $row['source_id'],
            'status'      => $row['status'],
        ];
    }

    $affected = bulkUpsert(
        $pdo, 'vacant_registrations',
        ['property_id', 'source_id', 'status'],
        $insertRows,
        ['status']
    );

    return ['inserted' => $affected, 'skipped' => $skipped, 'properties' => count($idMap)];
}

function processSurplusProperties(PDO $pdo, iterable $features, array $config): array
{
    $propertyRows = [];
    $childRows = [];
    $skipped = 0;

    $inserted = 0;
    $properties = 0;

    $flush = function() use (&$propertyRows, &$childRows, $pdo, &$inserted, &$properties) {
        if (empty($propertyRows)) return;
        $idMap = upsertProperties($pdo, array_values($propertyRows));
        $properties += count($idMap);

        $insertRows = [];
        foreach ($childRows as $row) {
            $propId = $idMap[$row['parcel_id']] ?? null;
            if ($propId === null) continue;
            $row['property_id'] = $propId;
            // Build strictly ordered array for insertion
            $insertArr = [];
            foreach (['property_id', 'source_id', 'managing_agency', 'lot_size_sqft', 'status', 'notes'] as $col) {
                $insertArr[] = $row[$col] ?? null;
            }
            $insertRows[] = $row; // Actually, let's just leave it associative and let bulkUpsert extract by column name
        }

        $inserted += bulkUpsert(
            $pdo, 'surplus_properties',
            ['property_id', 'source_id', 'managing_agency', 'lot_size_sqft', 'status', 'notes'],
            $insertRows,
            ['managing_agency', 'lot_size_sqft', 'status', 'notes']
        );
        $propertyRows = [];
        $childRows = [];
    };

    foreach ($features as $feature) {
        $attrs = $feature['attributes'] ?? [];

        $parcelId = normalizeParcelId($attrs[$config['parcelField']] ?? null);
        $coords   = extractCoordinates($feature, $config['coordPath']);

        if ($parcelId === null || $coords === null) {
            $skipped++;
            continue;
        }

        // Assemble address from STREET_NUM + STREET_NAM
        $streetNum  = trim($attrs['STREET_NUM'] ?? '');
        $streetName = trim($attrs['STREET_NAM'] ?? '');
        $rawAddress = trim("$streetNum $streetName");
        $address    = normalizeAddress($rawAddress !== '' ? $rawAddress : null);

        $propertyRows[$parcelId] = [
            'parcel_id'      => $parcelId,
            'street_address' => $address,
            'city'           => 'Montgomery',
            'zip_code'       => '',
            'ownership_type' => 'City-Owned',  // Surplus = government-owned
            'latitude'       => $coords['lat'],
            'longitude'      => $coords['lng'],
        ];

        $sourceId = 'SUR_' . ($attrs['FID'] ?? '');
        if ($sourceId === 'SUR_') {
            $skipped++;
            continue;
        }

        $childRows[] = [
            'parcel_id'       => $parcelId,
            'source_id'       => $sourceId,
            'managing_agency' => $attrs['LOCATION'] ?? null,
            'lot_size_sqft'   => $attrs['SQ_FT'] ?? null,
            'status'          => substr((string)($attrs['STRATEGY'] ?? 'Available'), 0, 255),
            'notes'           => $attrs['NOTES'] ?? null,
        ];
    }

    $idMap = upsertProperties($pdo, array_values($propertyRows));

    $insertRows = [];
    foreach ($childRows as $row) {
        $propId = $idMap[$row['parcel_id']] ?? null;
        if ($propId === null) continue;

        $insertRows[] = [
            'property_id'    => $propId,
            'source_id'      => $row['source_id'],
            'managing_agency' => $row['managing_agency'],
            'lot_size_sqft'  => $row['lot_size_sqft'],
            'status'         => $row['status'],
            'notes'          => $row['notes'],
        ];
    }

    $affected = bulkUpsert(
        $pdo, 'surplus_properties',
        ['property_id', 'source_id', 'managing_agency', 'lot_size_sqft', 'status', 'notes'],
        $insertRows,
        ['managing_agency', 'lot_size_sqft', 'status', 'notes']
    );

    return ['inserted' => $affected, 'skipped' => $skipped, 'properties' => count($idMap)];
}

// ---------------------------------------------------------------------------
// File discovery
// ---------------------------------------------------------------------------

/**
 * Find the most recent raw JSON file for a given dataset slug.
 */
function findLatestFile(string $dir, string $slug): ?string
{
    $pattern = "$dir/{$slug}_*.json";
    $files = glob($pattern);

    if (empty($files)) return null;

    // Sort descending by filename (date is embedded)
    rsort($files);
    return $files[0];
}

// ---------------------------------------------------------------------------
// Main execution
// ---------------------------------------------------------------------------

$useMock = in_array('--mock', $argv ?? [], true);
$baseDir = dirname(__DIR__);

// Determine data source directory
$dataDir = $useMock
    ? $baseDir . DIRECTORY_SEPARATOR . 'mock_data'
    : $baseDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'raw';

// Load .env
$envPath = dirname($baseDir) . DIRECTORY_SEPARATOR . '.env';
if (!file_exists($envPath)) {
    // Try .env.example as fallback for first-time setup
    $examplePath = dirname($baseDir) . DIRECTORY_SEPARATOR . '.env.example';
    if (file_exists($examplePath)) {
        error_log("[WARN] No .env file found, falling back to .env.example");
        $envPath = $examplePath;
    } else {
        fwrite(STDERR, "[FATAL] No .env or .env.example found. Copy .env.example to .env and configure.\n");
        exit(1);
    }
}

$env = loadEnv($envPath);

// Connect to database
try {
    $pdo = createPDO($env);
    error_log("[OK] Connected to MySQL at {$env['DB_HOST']}:{$env['DB_PORT']}");
} catch (PDOException $e) {
    fwrite(STDERR, "[FATAL] Database connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// Process each dataset
$report = [
    'run_timestamp' => date('c'),
    'mode'          => $useMock ? 'mock' : 'live',
    'datasets'      => [],
    'errors'        => [],
];

foreach (DATASETS as $slug => $config) {
    // Find data file
    if ($useMock) {
        $filePath = $dataDir . DIRECTORY_SEPARATOR . "{$slug}_sample.json";
    } else {
        $filePath = findLatestFile($dataDir, $slug);
    }

    if ($filePath === null || !file_exists($filePath)) {
        $report['errors'][] = ['dataset' => $slug, 'message' => "No data file found"];
        error_log("[SKIP] $slug: No data file found in $dataDir");
        continue;
    }

    try {
        if (filesize($filePath) === 0) {
            $report['datasets'][] = ['name' => $slug, 'records' => 0, 'skipped' => 0, 'file' => basename($filePath)];
            error_log("[OK] $slug: Empty file " . basename($filePath));
            continue;
        }

        $features = \JsonMachine\Items::fromFile($filePath, [
            'pointer' => '/features',
            'decoder' => new \JsonMachine\JsonDecoder\ExtJsonDecoder(true)
        ]);

        // Call the dataset-specific processor
        $processorFn = $config['processor'];
        $pdo->beginTransaction();
        $result = $processorFn($pdo, $features, $config);
        $pdo->commit();

        $report['datasets'][] = [
            'name'       => $slug,
            'records'    => $result['inserted'],
            'skipped'    => $result['skipped'],
            'properties' => $result['properties'],
            'file'       => basename($filePath),
        ];

        error_log("[OK] $slug: {$result['inserted']} upserted, {$result['skipped']} skipped, {$result['properties']} properties");

        unset($features);
        gc_collect_cycles();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $report['errors'][] = ['dataset' => $slug, 'message' => $e->getMessage()];
        error_log("[ERROR] $slug: " . $e->getMessage());
    }
}

// Output summary
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
