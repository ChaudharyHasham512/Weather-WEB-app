<?php
// ============================================================
//  WeatherWave – config.php
//  Uses Open-Meteo (free, no API key required)
// ============================================================

// Open-Meteo endpoints (100% free, no key needed)
define('GEOCODING_URL', 'https://geocoding-api.open-meteo.com/v1/search');
define('WEATHER_URL',   'https://api.open-meteo.com/v1/forecast');

// File-based cache
define('CACHE_DIR',      __DIR__ . '/cache/');
define('CACHE_DURATION', 600);   // seconds (10 min)

// Rate limiting (stored in cache dir)
define('RATE_LIMIT',  60);    // max requests per IP
define('RATE_WINDOW', 3600);  // per hour
