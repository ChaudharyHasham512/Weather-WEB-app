<?php
// ============================================================
//  WeatherWave – api.php
//  A PHP REST API that proxies Open-Meteo (no API key needed).
//
//  Endpoints:
//    GET api.php?city=London
//    GET api.php?lat=51.5&lon=-0.1
//
//  Response: JSON object with normalised weather data
// ============================================================

require_once __DIR__ . '/config.php';

// ─── CORS & Headers ───────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Cache-Control: no-store');

// ─── Only allow GET ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError(405, 'Method not allowed. Use GET.');
}

// ─── Rate Limiting ────────────────────────────────────────────
$clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$clientIp = preg_replace('/[^a-zA-Z0-9._:-]/', '', $clientIp);
checkRateLimit($clientIp);

// ─── Ensure cache directory exists ───────────────────────────
if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}

// ─── Parse input ─────────────────────────────────────────────
$city = isset($_GET['city']) ? trim($_GET['city']) : '';
$lat  = isset($_GET['lat'])  ? (float) $_GET['lat']  : null;
$lon  = isset($_GET['lon'])  ? (float) $_GET['lon']  : null;

if (empty($city) && ($lat === null || $lon === null)) {
    sendError(400, 'Provide either ?city=CityName or ?lat=XX&lon=YY');
}

// ─── Resolve coordinates ─────────────────────────────────────
$locationName    = '';
$locationCountry = '';
$timezone        = 'auto';

if (!empty($city)) {
    // Step 1: Geocode city → lat/lon via Open-Meteo Geocoding API
    $cacheKey     = 'geo_' . md5(strtolower($city));
    $cachedGeo    = getCache($cacheKey);

    if ($cachedGeo) {
        $geo = $cachedGeo;
    } else {
        $geoUrl  = GEOCODING_URL . '?' . http_build_query([
            'name'     => $city,
            'count'    => 1,
            'language' => 'en',
            'format'   => 'json',
        ]);
        $geoJson = fetchUrl($geoUrl);
        $geo     = json_decode($geoJson, true);

        if (!isset($geo['results'][0])) {
            sendError(404, "City \"$city\" not found. Check the spelling and try again.");
        }
        setCache($cacheKey, $geo);
    }

    $place           = $geo['results'][0];
    $lat             = $place['latitude'];
    $lon             = $place['longitude'];
    $locationName    = $place['name'];
    $locationCountry = $place['country_code'] ?? ($place['country'] ?? '');
    $timezone        = $place['timezone'] ?? 'auto';
} else {
    // Reverse geocode coords → city name
    $cacheKey  = 'rgeo_' . md5("{$lat},{$lon}");
    $cachedRGeo = getCache($cacheKey);

    if ($cachedRGeo) {
        $locationName    = $cachedRGeo['name'];
        $locationCountry = $cachedRGeo['country'];
        $timezone        = $cachedRGeo['timezone'];
    } else {
        // Open-Meteo doesn't have reverse geocoding; use small trick:
        // fetch nearby cities from geocoding with blank name isn't possible,
        // so we call the weather API and parse the timezone for a city hint.
        // For display we'll use "Your Location" as fallback.
        $locationName    = 'Your Location';
        $locationCountry = '';
        $timezone        = 'auto';
    }
}

// Validate coordinates range
if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
    sendError(400, 'Invalid coordinates provided.');
}

// ─── Fetch Weather Data ───────────────────────────────────────
$weatherCacheKey = 'wx_' . md5("{$lat}_{$lon}");
$cachedWeather   = getCache($weatherCacheKey);

if ($cachedWeather) {
    $weatherData = $cachedWeather;
} else {
    $weatherUrl = WEATHER_URL . '?' . http_build_query([
        'latitude'        => round($lat, 4),
        'longitude'       => round($lon, 4),
        'current'         => 'temperature_2m,relative_humidity_2m,apparent_temperature,weather_code,wind_speed_10m,visibility,is_day',
        'daily'           => 'temperature_2m_max,temperature_2m_min,sunrise,sunset,weather_code',
        'timezone'        => $timezone,
        'wind_speed_unit' => 'kmh',
        'forecast_days'   => 1,
    ]);

    $weatherJson = fetchUrl($weatherUrl);
    $weatherData = json_decode($weatherJson, true);

    if (isset($weatherData['error']) && $weatherData['error']) {
        sendError(502, 'Weather data fetch failed: ' . ($weatherData['reason'] ?? 'Unknown error'));
    }

    setCache($weatherCacheKey, $weatherData);
}

// ─── Normalise & Build Response ───────────────────────────────
$current  = $weatherData['current']        ?? [];
$daily    = $weatherData['daily']          ?? [];
$units    = $weatherData['current_units']  ?? [];

$wmoCode  = (int) ($current['weather_code'] ?? 0);
$isDay    = (int) ($current['is_day']       ?? 1);

[$condition, $description, $icon] = wmoToCondition($wmoCode, $isDay);

$response = [
    'source'      => 'Open-Meteo (open-meteo.com)',
    'cached'      => (bool) $cachedWeather,
    'location'    => [
        'city'        => $locationName,
        'country'     => strtoupper($locationCountry),
        'lat'         => round($lat, 4),
        'lon'         => round($lon, 4),
        'timezone'    => $weatherData['timezone'] ?? $timezone,
        'utc_offset'  => $weatherData['utc_offset_seconds'] ?? 0,
    ],
    'weather'     => [
        'condition'   => $condition,           // e.g. "Rain"
        'description' => $description,         // e.g. "Moderate rain"
        'icon'        => $icon,                // e.g. "10d"
        'wmo_code'    => $wmoCode,
        'is_day'      => (bool) $isDay,
    ],
    'temperature' => [
        'current'     => round($current['temperature_2m']     ?? 0, 1),
        'feels_like'  => round($current['apparent_temperature'] ?? 0, 1),
        'max'         => round($daily['temperature_2m_max'][0]  ?? 0, 1),
        'min'         => round($daily['temperature_2m_min'][0]  ?? 0, 1),
        'unit'        => 'celsius',
    ],
    'stats'       => [
        'humidity'    => (int)   ($current['relative_humidity_2m'] ?? 0),   // %
        'wind_kmh'    => round(   $current['wind_speed_10m']        ?? 0, 1),
        'visibility_m'=> (int)   ($current['visibility']            ?? 0),
    ],
    'sun'         => [
        'sunrise'     => $daily['sunrise'][0] ?? null,
        'sunset'      => $daily['sunset'][0]  ?? null,
    ],
    'fetched_at'  => date('c'),
];

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Fetch a URL using cURL.
 */
function fetchUrl(string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'WeatherWave/1.0 (+https://github.com/weatherwave)',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) sendError(502, "HTTP request failed: $err");
    if ($code >= 500) sendError(502, "Upstream service returned HTTP $code.");
    if (!$body)       sendError(502, 'Empty response from upstream service.');

    return $body;
}

/**
 * Read from file cache. Returns decoded data or null if expired/missing.
 */
function getCache(string $key): ?array {
    $file = CACHE_DIR . $key . '.json';
    if (!file_exists($file)) return null;

    $mtime = filemtime($file);
    if ((time() - $mtime) > CACHE_DURATION) {
        @unlink($file);
        return null;
    }

    $content = file_get_contents($file);
    return $content ? json_decode($content, true) : null;
}

/**
 * Write data to file cache.
 */
function setCache(string $key, array $data): void {
    $file = CACHE_DIR . $key . '.json';
    file_put_contents($file, json_encode($data), LOCK_EX);
}

/**
 * Simple per-IP rate limiting using cache files.
 */
function checkRateLimit(string $ip): void {
    $file = CACHE_DIR . 'rl_' . md5($ip) . '.json';
    $now  = time();

    $record = ['count' => 0, 'window_start' => $now];
    if (file_exists($file)) {
        $r = json_decode(file_get_contents($file), true);
        if ($r && ($now - $r['window_start']) < RATE_WINDOW) {
            $record = $r;
        }
    }

    $record['count']++;
    file_put_contents($file, json_encode($record), LOCK_EX);

    if ($record['count'] > RATE_LIMIT) {
        sendError(429, 'Rate limit exceeded. Max ' . RATE_LIMIT . ' requests/hour. Please wait.');
    }
}

/**
 * Map WMO weather code → [condition, description, icon-code].
 * Icon codes mirror OpenWeatherMap convention (01d, 10d, etc.)
 * so the existing frontend emoji logic still works.
 */
function wmoToCondition(int $code, int $isDay): array {
    $d = $isDay ? 'd' : 'n';

    $map = [
        0  => ['Clear',        'Clear sky',                    "01$d"],
        1  => ['Clear',        'Mainly clear',                 "01$d"],
        2  => ['Clouds',       'Partly cloudy',                "02$d"],
        3  => ['Clouds',       'Overcast',                     "04$d"],
        45 => ['Mist',         'Foggy',                        "50$d"],
        48 => ['Mist',         'Icy fog',                      "50$d"],
        51 => ['Drizzle',      'Light drizzle',                "09$d"],
        53 => ['Drizzle',      'Moderate drizzle',             "09$d"],
        55 => ['Drizzle',      'Dense drizzle',                "09$d"],
        56 => ['Drizzle',      'Freezing drizzle',             "09$d"],
        57 => ['Drizzle',      'Heavy freezing drizzle',       "09$d"],
        61 => ['Rain',         'Slight rain',                  "10$d"],
        63 => ['Rain',         'Moderate rain',                "10$d"],
        65 => ['Rain',         'Heavy rain',                   "10$d"],
        66 => ['Rain',         'Freezing rain',                "13$d"],
        67 => ['Rain',         'Heavy freezing rain',          "13$d"],
        71 => ['Snow',         'Slight snow',                  "13$d"],
        73 => ['Snow',         'Moderate snowfall',            "13$d"],
        75 => ['Snow',         'Heavy snowfall',               "13$d"],
        77 => ['Snow',         'Snow grains',                  "13$d"],
        80 => ['Rain',         'Light rain showers',           "09$d"],
        81 => ['Rain',         'Moderate rain showers',        "09$d"],
        82 => ['Rain',         'Violent rain showers',         "09$d"],
        85 => ['Snow',         'Snow showers',                 "13$d"],
        86 => ['Snow',         'Heavy snow showers',           "13$d"],
        95 => ['Thunderstorm', 'Thunderstorm',                 "11$d"],
        96 => ['Thunderstorm', 'Thunderstorm with light hail', "11$d"],
        99 => ['Thunderstorm', 'Thunderstorm with heavy hail', "11$d"],
    ];

    return $map[$code] ?? ['Clouds', 'Unknown conditions', "04$d"];
}

/**
 * Send a JSON error response and exit.
 */
function sendError(int $status, string $message): never {
    http_response_code($status);
    echo json_encode([
        'error'   => true,
        'status'  => $status,
        'message' => $message,
    ], JSON_PRETTY_PRINT);
    exit;
}
