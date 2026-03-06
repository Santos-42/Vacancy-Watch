<?php
$file = 'd:/Vacancy Watch/backend/scripts/etl_montgomery.php';
$content = file_get_contents($file);

// 1. Add Autoloader
$content = preg_replace('/declare\(strict_types=1\);\n/m', "declare(strict_types=1);\n\nrequire dirname(__DIR__, 2) . '/vendor/autoload.php';\n", $content, 1);

// 2. Refactor main execution loop
$oldMainLoop = <<<PHP
    try {
        \$json = file_get_contents(\$filePath);
        \$data = json_decode(\$json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('JSON decode error: ' . json_last_error_msg());
        }

        \$features = \$data['features'] ?? [];
        if (empty(\$features)) {
            \$report['datasets'][] = ['name' => \$slug, 'records' => 0, 'skipped' => 0, 'file' => basename(\$filePath)];
            error_log("[OK] \$slug: 0 features in " . basename(\$filePath));
            continue;
        }

        // Call the dataset-specific processor
        \$processorFn = \$config['processor'];
        \$pdo->beginTransaction();
        \$result = \$processorFn(\$pdo, \$features, \$config);
        \$pdo->commit();

        \$report['datasets'][] = [
            'name'       => \$slug,
            'records'    => \$result['inserted'],
            'skipped'    => \$result['skipped'],
            'properties' => \$result['properties'],
            'file'       => basename(\$filePath),
        ];

        error_log("[OK] \$slug: {\$result['inserted']} upserted, {\$result['skipped']} skipped, {\$result['properties']} properties");

    } catch (Exception \$e) {
PHP;

$newMainLoop = <<<PHP
    try {
        if (filesize(\$filePath) === 0) {
            \$report['datasets'][] = ['name' => \$slug, 'records' => 0, 'skipped' => 0, 'file' => basename(\$filePath)];
            error_log("[OK] \$slug: Empty file " . basename(\$filePath));
            continue;
        }

        \$features = \JsonMachine\Items::fromFile(\$filePath, [
            'pointer' => '/features',
            'decoder' => new \JsonMachine\JsonDecoder\ExtJsonDecoder(true)
        ]);

        // Call the dataset-specific processor
        \$processorFn = \$config['processor'];
        \$pdo->beginTransaction();
        \$result = \$processorFn(\$pdo, \$features, \$config);
        \$pdo->commit();

        \$report['datasets'][] = [
            'name'       => \$slug,
            'records'    => \$result['inserted'],
            'skipped'    => \$result['skipped'],
            'properties' => \$result['properties'],
            'file'       => basename(\$filePath),
        ];

        error_log("[OK] \$slug: {\$result['inserted']} upserted, {\$result['skipped']} skipped, {\$result['properties']} properties");

        unset(\$features);
        gc_collect_cycles();

    } catch (Exception \$e) {
PHP;

$content = str_replace($oldMainLoop, $newMainLoop, $content);

// 3. String Replacements in processors (substring logic)
$content = str_replace(
    "'status'         => \$attrs['PermitStatus'] ?? 'Pending',",
    "'status'         => substr((string)(\$attrs['PermitStatus'] ?? 'Pending'), 0, 255),",
    $content
);
$content = str_replace(
    "'status'    => \$attrs['NEW__Sus'] ?? 'Open',",
    "'status'    => substr((string)(\$attrs['NEW__Sus'] ?? 'Open'), 0, 255),",
    $content
);
$content = str_replace(
    "'status'          => \$attrs['STRATEGY'] ?? 'Available',",
    "'status'          => substr((string)(\$attrs['STRATEGY'] ?? 'Available'), 0, 255),",
    $content
);

// 4. Change array $features to iterable $features
$content = str_replace(
    'function processCodeViolations(PDO $pdo, array $features, array $config): array',
    'function processCodeViolations(PDO $pdo, iterable $features, array $config): array',
    $content
);
$content = str_replace(
    'function processConstructionPermits(PDO $pdo, array $features, array $config): array',
    'function processConstructionPermits(PDO $pdo, iterable $features, array $config): array',
    $content
);
$content = str_replace(
    'function processVacantProperties(PDO $pdo, array $features, array $config): array',
    'function processVacantProperties(PDO $pdo, iterable $features, array $config): array',
    $content
);
$content = str_replace(
    'function processSurplusProperties(PDO $pdo, array $features, array $config): array',
    'function processSurplusProperties(PDO $pdo, iterable $features, array $config): array',
    $content
);

// 5. Implementing inside-loop batch-flushing for each function.
// Rather than complex regex, we'll insert a chunk and flush mechanism before the final summary.

function injectBatching($content, $functionName, $tableName, $childColumns, $updateColumns) {
    // Basic idea: we find "foreach ($features as $feature) {" and add the flush lambda before it.
    // Inside the loop, replace "$childRows[]" with "$childRows[] ... if count >= BATCH_SIZE flush();"
    
    // BUT since we can't reliably parse PHP with regex easily, let's just replace the body tail.
    
    $pattern = "/function $functionName(.*?)    foreach \(\\\$features as \\\$feature\) \{/s";
    
    // We add variables $inserted, $properties, and a $flush closure
    $replacementHead = <<<PHP
function $functionName\$1    \$inserted = 0;
    \$properties = 0;

    \$flush = function() use (&\$propertyRows, &\$childRows, \$pdo, &\$inserted, &\$properties) {
        if (empty(\$propertyRows)) return;
        \$idMap = upsertProperties(\$pdo, array_values(\$propertyRows));
        \$properties += count(\$idMap);

        \$insertRows = [];
        foreach (\$childRows as \$row) {
            \$propId = \$idMap[\$row['parcel_id']] ?? null;
            if (\$propId === null) continue;
            \$row['property_id'] = \$propId;
            // Build strictly ordered array for insertion
            \$insertArr = [];
            foreach (\$childColumns as \$col) {
                \$insertArr[] = \$row[\$col] ?? null;
            }
            \$insertRows[] = \$row; // Actually, let's just leave it associative and let bulkUpsert extract by column name
        }

        \$inserted += bulkUpsert(
            \$pdo, '$tableName',
            \$childColumns,
            \$insertRows,
            \$updateColumns
        );
        \$propertyRows = [];
        \$childRows = [];
    };

    foreach (\$features as \$feature) {
PHP;
    
    // Prepare column arrays as strings
    $childColsStr = "['" . implode("', '", $childColumns) . "']";
    $updateColsStr = "['" . implode("', '", $updateColumns) . "']";
    
    $replacementHead = str_replace('$childColumns', $childColsStr, $replacementHead);
    $replacementHead = str_replace('$updateColumns', $updateColsStr, $replacementHead);

    $content = preg_replace($pattern, $replacementHead, $content, 1);
    
    // Now replace the end of the loop to call flush() when size reaches BATCH_SIZE
    // We search for "    }\n\n    // Upsert properties" or similar
    // The safest anchor is "$idMap = upsertProperties" inside the function.
    
    $pattern2 = "/    \}\n\n.*?return \['inserted' => \\\$affected, 'skipped' => \\\$skipped, 'properties' => count\(\\\$idMap\)\\];\n\}/s";
    
    $replacementTail = <<<PHP
        if (count(\$propertyRows) >= BATCH_SIZE) {
            \$flush();
        }
    }
    \$flush();

    return ['inserted' => \$inserted, 'skipped' => \$skipped, 'properties' => \$properties];
}
PHP;

    $content = preg_replace($pattern2, $replacementTail, $content, 1);
    
    return $content;
}

$content = injectBatching($content, 'processCodeViolations', 'code_violations', 
    ['property_id', 'source_id', 'case_number', 'date_filed', 'disposition', 'code_reference', 'condition_text'],
    ['date_filed', 'disposition', 'condition_text']
);
$content = injectBatching($content, 'processConstructionPermits', 'construction_permits', 
    ['property_id', 'source_id', 'permit_number', 'permit_type', 'issue_date', 'status', 'description'],
    ['issue_date', 'status', 'description']
);
$content = injectBatching($content, 'processVacantProperties', 'vacant_registrations', 
    ['property_id', 'source_id', 'status'],
    ['status']
);
$content = injectBatching($content, 'processSurplusProperties', 'surplus_properties', 
    ['property_id', 'source_id', 'managing_agency', 'lot_size_sqft', 'status', 'notes'],
    ['managing_agency', 'lot_size_sqft', 'status', 'notes']
);

file_put_contents($file, $content);
echo "Migration complete.\n";
