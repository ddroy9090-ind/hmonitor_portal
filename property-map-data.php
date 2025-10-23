<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$sendResponse = static function (array $payload, int $status = 200): void {
    http_response_code($status);

    try {
        echo json_encode($payload, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to encode response payload.',
        ]);
    }

    exit;
};

try {
    $pdo = hh_db();
} catch (Throwable $e) {
    $sendResponse([
        'properties' => [],
        'error' => 'Unable to connect to the database.',
    ], 500);
}

$quoteIdentifier = static function (string $identifier): string {
    return '`' . str_replace('`', '``', $identifier) . '`';
};

$columnExists = static function (PDO $pdo, string $table, string $column) use ($quoteIdentifier): bool {
    try {
        $stmt = $pdo->prepare(sprintf('SHOW COLUMNS FROM %s LIKE :column', $quoteIdentifier($table)));
        $stmt->execute([':column' => $column]);

        return $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
};

$normaliseString = static function ($value): string {
    if (!is_string($value)) {
        return '';
    }

    $trimmed = trim($value);

    return $trimmed === '' ? '' : $trimmed;
};

$extractCoordinates = static function (?string $value): ?array {
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $decoded = urldecode($value);
    $patterns = [
        '/@(-?\d+\.\d+),(-?\d+\.\d+)/',
        '/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/',
        '/q=(-?\d+\.\d+),(-?\d+\.\d+)/',
        '/%40(-?\d+\.\d+)%2C(-?\d+\.\d+)/',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $decoded, $matches) === 1) {
            $latitude = isset($matches[1]) ? (float) $matches[1] : null;
            $longitude = isset($matches[2]) ? (float) $matches[2] : null;

            if ($latitude !== null && $longitude !== null) {
                return [
                    'lat' => $latitude,
                    'lng' => $longitude,
                ];
            }
        }
    }

    return null;
};

$extractMapEmbedUrl = static function (?string $mapValue, string $location, ?array $coordinates): ?string {
    if (is_string($mapValue)) {
        $candidate = trim(html_entity_decode($mapValue, ENT_QUOTES | ENT_HTML5));

        if ($candidate !== '') {
            if (stripos($candidate, '<iframe') !== false) {
                if (preg_match('/src\s*=\s*["\']([^"\']+)["\']/i', $candidate, $matches) === 1) {
                    $candidate = trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5));
                } else {
                    $candidate = '';
                }
            }

            if ($candidate !== '') {
                if (str_starts_with($candidate, 'http://')) {
                    $candidate = 'https://' . substr($candidate, 7);
                }

                if (!str_starts_with($candidate, 'https://') && !str_starts_with($candidate, '//')) {
                    $candidate = '';
                }
            }

            if ($candidate !== '') {
                if (str_starts_with($candidate, '//')) {
                    $candidate = 'https:' . $candidate;
                }

                $parts = @parse_url($candidate);
                if (is_array($parts) && isset($parts['host'])) {
                    $host = strtolower($parts['host']);
                    $isGoogleHost = preg_match('/(^|\.)google\.[a-z.]+$/', $host) === 1;

                    if ($isGoogleHost && (str_contains($candidate, '/maps/embed') || str_contains($candidate, 'output=embed'))) {
                        return $candidate;
                    }
                }
            }
        }
    }

    if ($coordinates !== null) {
        $lat = number_format((float) $coordinates['lat'], 6, '.', '');
        $lng = number_format((float) $coordinates['lng'], 6, '.', '');

        return sprintf('https://www.google.com/maps?q=%s,%s&z=15&output=embed', $lat, $lng);
    }

    if ($location !== '') {
        return 'https://www.google.com/maps?q=' . rawurlencode($location) . '&output=embed';
    }

    return null;
};

$buildDetailsUrl = static function (string $base, int $id): string {
    $base = trim($base);

    if ($base === '') {
        return '#';
    }

    $separator = strpos($base, '?') === false ? '?' : '&';

    return sprintf('%s%sid=%d', $base, $separator, $id);
};

$sources = [
    [
        'table' => 'properties_list',
        'category_key' => 'offplan',
        'category_label' => 'Off-Plan',
        'details_page' => 'houzzhunt/property-details',
    ],
    [
        'table' => 'buy_properties_list',
        'category_key' => 'buy',
        'category_label' => 'Buy',
        'details_page' => 'buy-properties-details.php',
    ],
    [
        'table' => 'rent_properties_list',
        'category_key' => 'rent',
        'category_label' => 'Rent',
        'details_page' => 'rent-properties-details.php',
    ],
];

$properties = [];

foreach ($sources as $source) {
    $table = $source['table'];
    $columns = ['id', 'property_title', 'property_location', 'location_map', 'starting_price', 'bedroom', 'property_type'];

    if ($columnExists($pdo, $table, 'location_highlight')) {
        $columns[] = 'location_highlight';
    }

    if ($columnExists($pdo, $table, 'project_name')) {
        $columns[] = 'project_name';
    }

    $selectColumns = array_map(static fn(string $column): string => $quoteIdentifier($column), $columns);
    $sql = sprintf('SELECT %s FROM %s', implode(', ', $selectColumns), $quoteIdentifier($table));

    if ($columnExists($pdo, $table, 'created_at')) {
        $sql .= ' ORDER BY `created_at` DESC, `id` DESC';
    } else {
        $sql .= ' ORDER BY `id` DESC';
    }

    try {
        $statement = $pdo->query($sql);
    } catch (Throwable $e) {
        continue;
    }

    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        $id = isset($row['id']) ? (int) $row['id'] : 0;
        if ($id <= 0) {
            continue;
        }

        $title = $normaliseString($row['property_title'] ?? '');
        $projectName = $normaliseString($row['project_name'] ?? '');
        $displayName = $projectName !== '' ? $projectName : $title;
        $location = $normaliseString($row['property_location'] ?? '');
        $price = $normaliseString($row['starting_price'] ?? '');
        $bedrooms = $normaliseString($row['bedroom'] ?? '');
        $propertyType = $normaliseString($row['property_type'] ?? '');
        $locationMap = $normaliseString($row['location_map'] ?? '');
        $locationHighlight = $normaliseString($row['location_highlight'] ?? '');
        if ($locationHighlight === '' && $location !== '') {
            $locationHighlight = $location;
        }

        $coordinates = $extractCoordinates($locationMap);
        $mapEmbedUrl = $extractMapEmbedUrl($locationMap, $location, $coordinates);

        if ($location === '' && $coordinates === null) {
            continue;
        }

        $properties[] = [
            'id' => $id,
            'category_key' => $source['category_key'],
            'category_label' => $source['category_label'],
            'title' => $title,
            'display_name' => $displayName,
            'location' => $location,
            'location_highlight' => $locationHighlight,
            'price' => $price,
            'bedrooms' => $bedrooms,
            'property_type' => $propertyType,
            'details_url' => $buildDetailsUrl($source['details_page'], $id),
            'latitude' => $coordinates['lat'] ?? null,
            'longitude' => $coordinates['lng'] ?? null,
            'location_embed_url' => $mapEmbedUrl,
        ];
    }
}

$sendResponse([
    'properties' => $properties,
    'generated_at' => gmdate(DATE_ATOM),
    'google_maps_api_key' => hh_google_maps_api_key(),
    'mapbox_access_token' => hh_mapbox_access_token(),
]);
