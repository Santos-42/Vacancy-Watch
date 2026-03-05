<?php
/**
 * =============================================================================
 * Vacancy Watch — Montgomery AL API Fetcher (Module 2.1)
 * =============================================================================
 * Pulls raw JSON from Montgomery, Alabama ArcGIS FeatureServer endpoints.
 *
 * Usage:
 *   php backend/scripts/fetch_montgomery.php
 *
 * Output:
 *   - Raw JSON files saved to backend/data/raw/<dataset>_<YYYY-MM-DD>.json
 *   - JSON summary report printed to stdout
 *   - Files older than RETENTION_DAYS auto-deleted
 *
 * References (from documentation.md):
 *   - ArcGIS REST APIs: https://developers.arcgis.com/rest/
 *   - Montgomery Open Data: https://opendata.montgomeryal.gov
 * =============================================================================
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

/** Maximum age (days) for raw JSON files before auto-deletion */
const RETENTION_DAYS = 7;

/** Records per page (ArcGIS pagination) */
const PAGE_SIZE = 2000;

/** Maximum records to fetch per endpoint (safety ceiling) */
const MAX_RECORDS = 50000;

/** cURL timeout in seconds */
const CURL_TIMEOUT = 30;
const CURL_CONNECT_TIMEOUT = 10;

/**
 * Endpoint registry.
 * Each entry: [slug, url, geometryType]
 *   geometryType: 'Point' or 'Polygon'
 *   Polygon endpoints get &returnCentroid=true appended automatically.
 */
const ENDPOINTS = [
    [
        'slug' => 'code_violations',
        'url'  => 'https://gis.montgomeryal.gov/server/rest/services/HostedDatasets/Code_Violations/FeatureServer/0',
        'geometryType' => 'Point',
    ],
    [
        'slug' => 'construction_permits',
        'url'  => 'https://gis.montgomeryal.gov/server/rest/services/HostedDatasets/Construction_Permits/FeatureServer/0',
        'geometryType' => 'Point',
    ],
    [
        'slug' => 'vacant_properties',
        'url'  => 'https://services7.arcgis.com/xNUwUjOJqYE54USz/arcgis/rest/services/Suspected_Rentals/FeatureServer/3',
        'geometryType' => 'Polygon',
    ],
    [
        'slug' => 'surplus_properties',
        'url'  => 'https://services7.arcgis.com/xNUwUjOJqYE54USz/arcgis/rest/services/SURPLUS_CITY_PROPERTIES_polygon/FeatureServer/0',
        'geometryType' => 'Polygon',
    ],
];

// ---------------------------------------------------------------------------
// Directory setup
// ---------------------------------------------------------------------------

$baseDir = dirname(__DIR__);
$rawDir  = $baseDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'raw';

if (!is_dir($rawDir)) {
    mkdir($rawDir, 0755, true);
}

// ---------------------------------------------------------------------------
// HTTP client (cURL)
// ---------------------------------------------------------------------------

/**
 * Fetch a single URL via cURL with production-grade settings.
 *
 * @param  string $url  Fully-qualified URL to fetch
 * @return array        Decoded JSON response
 * @throws RuntimeException on HTTP or decode errors
 */
function fetchUrl(string $url): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => CURL_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'VacancyWatch/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException("cURL error: $error (URL: $url)");
    }

    if ($httpCode !== 200) {
        throw new RuntimeException("HTTP $httpCode from $url");
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('JSON decode error: ' . json_last_error_msg());
    }

    // ArcGIS may return an error object instead of features
    if (isset($data['error'])) {
        $errMsg = $data['error']['message'] ?? 'Unknown ArcGIS error';
        throw new RuntimeException("ArcGIS API error: $errMsg");
    }

    return $data;
}

// ---------------------------------------------------------------------------
// Centroid fallback (for Polygon endpoints that lack returnCentroid support)
// ---------------------------------------------------------------------------

/**
 * Calculate the arithmetic centroid from polygon ring coordinates.
 * Uses only the outer ring (index 0). Excludes closing vertex.
 *
 * @param  array $rings  Polygon rings array from ArcGIS geometry
 * @return array         ['x' => float, 'y' => float]
 */
function calculateCentroid(array $rings): array
{
    $outerRing = $rings[0];
    // Exclude closing vertex (last == first in a closed ring)
    $n = count($outerRing) - 1;
    if ($n < 1) {
        return ['x' => 0.0, 'y' => 0.0];
    }

    $sumX = 0.0;
    $sumY = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $sumX += (float) $outerRing[$i][0];
        $sumY += (float) $outerRing[$i][1];
    }

    return [
        'x' => $sumX / $n,
        'y' => $sumY / $n,
    ];
}

// ---------------------------------------------------------------------------
// Pagination engine
// ---------------------------------------------------------------------------

/**
 * Stream all records from a single ArcGIS FeatureServer endpoint to a file.
 * Writes each page directly to disk to avoid accumulating all features in RAM.
 * RAM usage stays flat regardless of total dataset size.
 *
 * Uses $isFirstPage flag to handle JSON comma separation without trailing commas.
 *
 * @param  string $baseUrl      FeatureServer layer URL
 * @param  string $geometryType 'Point' or 'Polygon'
 * @param  string $filepath     Destination file path
 * @return int                  Total number of records written
 */
function streamAllRecords(string $baseUrl, string $geometryType, string $filepath): int
{
    $offset = 0;
    $queryUrl = $baseUrl . '/query';
    $totalCount = 0;
    $isFirstPage = true;

    // Open file and start JSON structure
    $fh = fopen($filepath, 'w');
    if (!$fh) {
        throw new RuntimeException("Cannot open file for writing: $filepath");
    }
    fwrite($fh, '{"features":[');

    try {
        while ($offset < MAX_RECORDS) {
            // Build query parameters per ArcGIS REST API docs
            $params = [
                'where'             => '1=1',
                'outFields'         => '*',
                'outSR'             => '4326',
                'returnGeometry'    => 'true',
                'resultRecordCount' => (string) PAGE_SIZE,
                'resultOffset'      => (string) $offset,
                'f'                 => 'json',
            ];

            // Polygon endpoints: request centroid for POINT column compatibility
            if ($geometryType === 'Polygon') {
                $params['returnCentroid'] = 'true';
            }

            $url = $queryUrl . '?' . http_build_query($params);
            $data = fetchUrl($url);

            $features = $data['features'] ?? [];
            if (empty($features)) {
                break;
            }

            // For polygon features: ensure centroid is available
            if ($geometryType === 'Polygon') {
                foreach ($features as &$feature) {
                    if (!isset($feature['centroid']) && isset($feature['geometry']['rings'])) {
                        $feature['centroid'] = calculateCentroid($feature['geometry']['rings']);
                    }
                }
                unset($feature);
            }

            // Write each feature to disk immediately (one page at a time in RAM)
            foreach ($features as $feature) {
                if (!$isFirstPage || $feature !== $features[0]) {
                    // Comma before every feature except the very first one
                    fwrite($fh, ',');
                } elseif ($isFirstPage && $feature === $features[0]) {
                    // No comma before the first feature of the first page
                }
                fwrite($fh, json_encode($feature, JSON_UNESCAPED_UNICODE));
            }

            $isFirstPage = false;
            $totalCount += count($features);
            $offset += PAGE_SIZE;

            // Stop if the server signals no more records
            if (!($data['exceededTransferLimit'] ?? false)) {
                break;
            }
        }

        // Close JSON structure
        fwrite($fh, '],"count":' . $totalCount . '}');
    } finally {
        fclose($fh);
    }

    return $totalCount;
}

// ---------------------------------------------------------------------------
// Data retention policy
// ---------------------------------------------------------------------------

/**
 * Delete raw JSON files older than the specified number of days.
 *
 * @param  string $dir         Directory to scan
 * @param  int    $maxAgeDays  Maximum file age in days
 * @return int                 Number of files deleted
 */
function purgeOldFiles(string $dir, int $maxAgeDays = RETENTION_DAYS): int
{
    $deleted = 0;
    $cutoff  = time() - ($maxAgeDays * 86400);

    foreach (glob("$dir/*.json") as $file) {
        if (filemtime($file) < $cutoff) {
            unlink($file);
            $deleted++;
        }
    }

    return $deleted;
}

// ---------------------------------------------------------------------------
// Main execution
// ---------------------------------------------------------------------------

$report = [
    'run_timestamp' => date('c'),
    'datasets'      => [],
    'errors'        => [],
    'purged_files'  => 0,
];

foreach (ENDPOINTS as $endpoint) {
    $slug = $endpoint['slug'];
    $url  = $endpoint['url'];
    $geom = $endpoint['geometryType'];

    try {
        $filename = $slug . '_' . date('Y-m-d') . '.json';
        $filepath = $rawDir . DIRECTORY_SEPARATOR . $filename;
        $count = streamAllRecords($url, $geom, $filepath);

        $report['datasets'][] = [
            'name'     => $slug,
            'records'  => $count,
            'file'     => $filename,
            'geometry' => $geom,
        ];

        error_log("[OK] $slug: {$count} records -> $filename");

    } catch (RuntimeException $e) {
        $report['errors'][] = [
            'dataset' => $slug,
            'message' => $e->getMessage(),
        ];
        error_log("[ERROR] $slug: " . $e->getMessage());
    }
}

// Enforce data retention policy
$report['purged_files'] = purgeOldFiles($rawDir);
if ($report['purged_files'] > 0) {
    error_log("[CLEANUP] Purged {$report['purged_files']} files older than " . RETENTION_DAYS . " days");
}

// Output summary report as JSON
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
