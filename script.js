/* ============================================================
   WeatherWave – script.js  (PHP API backend version)
   Calls our own api.php — no API key needed on the frontend!
   ============================================================ */

'use strict';

// ─── Backend endpoint ─────────────────────────────────────────
// api.php must be served by a PHP server (e.g. XAMPP, Laragon, WAMP)
const API_ENDPOINT = 'api.php';

// ─── State ────────────────────────────────────────────────────
let currentTempC = null;   // always store °C, convert on demand
let currentUnit  = 'C';
let currentData  = null;   // last parsed API response

// ─── DOM References ───────────────────────────────────────────
const cityInput     = document.getElementById('cityInput');
const searchBtn     = document.getElementById('searchBtn');
const geoBtn        = document.getElementById('geoBtn');
const clearBtn      = document.getElementById('clearBtn');
const weatherCard   = document.getElementById('weatherCard');
const errorCard     = document.getElementById('errorCard');
const errorMsg      = document.getElementById('errorMsg');
const loader        = document.getElementById('loader');
const apiNotice     = document.getElementById('apiNotice');
const btnC          = document.getElementById('btnC');
const btnF          = document.getElementById('btnF');

// Weather display elements
const cityName       = document.getElementById('cityName');
const countryName    = document.getElementById('countryName');
const dateTimeEl     = document.getElementById('dateTime');
const weatherIconBig = document.getElementById('weatherIconBig');
const temperature    = document.getElementById('temperature');
const weatherDesc    = document.getElementById('weatherDesc');
const humidity       = document.getElementById('humidity');
const windSpeed      = document.getElementById('windSpeed');
const feelsLike      = document.getElementById('feelsLike');
const visibility     = document.getElementById('visibility');
const tempMax        = document.getElementById('tempMax');
const tempMin        = document.getElementById('tempMin');
const sunrise        = document.getElementById('sunrise');
const sunset         = document.getElementById('sunset');

// ─── Init ─────────────────────────────────────────────────────
(function init() {
  spawnParticles();
  updateDateTime();
  setInterval(updateDateTime, 60_000);

  // Hide API notice — no key needed!
  if (apiNotice) apiNotice.classList.add('hidden');

  // Notify user this version uses a PHP backend
  showToast('🚀 Running via PHP API — no key required!', 3500);
})();

// ─── Particles ────────────────────────────────────────────────
function spawnParticles() {
  const container = document.getElementById('bgParticles');
  if (!container) return;
  for (let i = 0; i < 22; i++) {
    const p    = document.createElement('div');
    const size = Math.random() * 10 + 4;
    p.className = 'particle';
    p.style.cssText = `
      width:${size}px; height:${size}px;
      left:${Math.random() * 100}%;
      background:rgba(255,255,255,${Math.random() * 0.18 + 0.04});
      animation-duration:${Math.random() * 18 + 12}s;
      animation-delay:${Math.random() * -20}s;`;
    container.appendChild(p);
  }
}

// ─── Date / Time ──────────────────────────────────────────────
function updateDateTime() {
  if (!dateTimeEl) return;
  const now  = new Date();
  const opts = { weekday: 'long', month: 'long', day: 'numeric' };
  const time = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  dateTimeEl.innerHTML = `${now.toLocaleDateString(undefined, opts)}<br>${time}`;
}

// ─── Search ───────────────────────────────────────────────────
searchBtn.addEventListener('click', handleSearch);
cityInput.addEventListener('keydown', e => { if (e.key === 'Enter') handleSearch(); });

cityInput.addEventListener('input', () => {
  clearBtn.classList.toggle('visible', cityInput.value.length > 0);
});

clearBtn.addEventListener('click', () => {
  cityInput.value = '';
  clearBtn.classList.remove('visible');
  cityInput.focus();
});

function handleSearch() {
  const city = cityInput.value.trim();
  if (!city) { shakeElement(document.getElementById('searchBox')); return; }
  fetchWeather({ city });
}

// ─── Geolocation ──────────────────────────────────────────────
geoBtn.addEventListener('click', () => {
  if (!navigator.geolocation) {
    showError('Geolocation is not supported by your browser.');
    return;
  }

  showLoader();
  navigator.geolocation.getCurrentPosition(
    pos => fetchWeather({ lat: pos.coords.latitude, lon: pos.coords.longitude }),
    err => {
      hideLoader();
      const msgs = {
        1: 'Location access denied. Please allow permission in your browser.',
        2: 'Unable to determine your position.',
        3: 'Location request timed out.',
      };
      showError(msgs[err.code] || 'Failed to get your location.');
    },
    { timeout: 10_000 }
  );
});

// ─── Core Fetch → api.php ─────────────────────────────────────
async function fetchWeather({ city = null, lat = null, lon = null }) {
  showLoader();

  try {
    // Build query string
    const params = city
      ? new URLSearchParams({ city })
      : new URLSearchParams({ lat, lon });

    const res  = await fetch(`${API_ENDPOINT}?${params}`);
    const data = await res.json();

    // Handle errors returned by our PHP API
    if (!res.ok || data.error) {
      throw new Error(data.message || `Server error (HTTP ${res.status})`);
    }

    renderWeather(data);

  } catch (err) {
    hideLoader();
    // Detect if PHP server isn't running
    if (err instanceof TypeError && err.message.includes('fetch')) {
      showError('⚠️ Cannot reach the PHP server. Make sure XAMPP / Laragon is running and you\'re opening this via http://localhost — not as a file://');
    } else {
      showError(err.message);
    }
  }
}

// ─── Render Weather ───────────────────────────────────────────
function renderWeather(data) {
  currentData  = data;
  currentTempC = data.temperature.current;
  currentUnit  = 'C';

  // Reset unit buttons
  btnC.classList.add('active');
  btnF.classList.remove('active');

  // Location
  cityName.textContent    = data.location.city;
  countryName.textContent = getFlag(data.location.country) + ' ' + data.location.country;

  // Icon & Condition
  const condition = data.weather.condition;   // e.g. "Rain"
  const icon      = data.weather.icon;        // e.g. "10d"
  weatherIconBig.textContent = getWeatherEmoji(condition, icon);
  weatherDesc.textContent    = data.weather.description;

  // Temperature
  temperature.textContent = formatTemp(currentTempC, 'C');

  // Stats
  humidity.textContent   = `${data.stats.humidity}%`;
  windSpeed.textContent  = `${data.stats.wind_kmh} km/h`;
  feelsLike.textContent  = formatTemp(data.temperature.feels_like, 'C');
  const vis = data.stats.visibility_m;
  visibility.textContent = vis ? `${(vis / 1000).toFixed(1)} km` : 'N/A';
  tempMax.textContent    = formatTemp(data.temperature.max, 'C');
  tempMin.textContent    = formatTemp(data.temperature.min, 'C');

  // Sunrise / Sunset (ISO strings from Open-Meteo, e.g. "2024-04-11T06:12")
  sunrise.textContent = formatSunTime(data.sun.sunrise);
  sunset.textContent  = formatSunTime(data.sun.sunset);

  // Dynamic background
  updateBackground(condition, icon);

  // Show card
  hideLoader();
  hideError();
  weatherCard.classList.add('visible');

  setTimeout(() => weatherCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 100);

  // Show cached badge if data came from cache
  if (data.cached) showToast('⚡ Showing cached data (refreshes every 10 min)', 2500);
}

// ─── Unit Toggle ──────────────────────────────────────────────
btnC.addEventListener('click', () => switchUnit('C'));
btnF.addEventListener('click', () => switchUnit('F'));

function switchUnit(unit) {
  if (!currentData || currentUnit === unit) return;
  currentUnit = unit;
  btnC.classList.toggle('active', unit === 'C');
  btnF.classList.toggle('active', unit === 'F');

  temperature.textContent = formatTemp(currentTempC, unit);
  feelsLike.textContent   = formatTemp(currentData.temperature.feels_like, unit);
  tempMax.textContent     = formatTemp(currentData.temperature.max, unit);
  tempMin.textContent     = formatTemp(currentData.temperature.min, unit);
}

function formatTemp(celsius, unit) {
  if (unit === 'F') return `${Math.round(celsius * 9 / 5 + 32)}°F`;
  return `${Math.round(celsius)}°C`;
}

// ─── Sunrise / Sunset Formatter ───────────────────────────────
// Open-Meteo returns ISO strings like "2024-04-11T05:48"
function formatSunTime(isoStr) {
  if (!isoStr) return 'N/A';
  const date = new Date(isoStr);
  if (isNaN(date)) return isoStr.slice(11, 16) || 'N/A'; // fallback: grab HH:MM
  return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

// ─── Dynamic Background ───────────────────────────────────────
function updateBackground(condition, iconCode) {
  const body   = document.body;
  const allWx  = ['clear','clouds','rain','drizzle','thunderstorm','snow',
                   'mist','fog','haze','smoke','dust','night','default'];
  allWx.forEach(c => body.classList.remove(`weather-${c}`));

  const isNight = iconCode?.endsWith('n');
  if (isNight && condition === 'Clear') { body.classList.add('weather-night'); return; }

  const map = {
    Clear: 'clear', Clouds: 'clouds', Rain: 'rain', Drizzle: 'drizzle',
    Thunderstorm: 'thunderstorm', Snow: 'snow', Mist: 'mist',
    Fog: 'fog', Haze: 'haze', Smoke: 'smoke', Dust: 'dust',
  };
  body.classList.add(`weather-${map[condition] || 'default'}`);
}

// ─── Weather Emoji ────────────────────────────────────────────
function getWeatherEmoji(condition, iconCode) {
  const isNight = iconCode?.endsWith('n');
  const map = {
    Clear: isNight ? '🌙' : '☀️', Clouds: '☁️', Rain: '🌧️',
    Drizzle: '🌦️', Thunderstorm: '⛈️', Snow: '❄️',
    Mist: '🌫️', Fog: '🌫️', Haze: '🌁', Smoke: '💨',
    Dust: '🌪️',
  };
  return map[condition] || '🌡️';
}

// ─── Country Flag ─────────────────────────────────────────────
function getFlag(code) {
  if (!code || code.length !== 2) return '';
  return [...code.toUpperCase()].map(c =>
    String.fromCodePoint(0x1F1E6 - 65 + c.charCodeAt(0))
  ).join('');
}

// ─── UI Helpers ───────────────────────────────────────────────
function showError(msg) {
  errorMsg.textContent = msg;
  errorCard.classList.add('visible');
  weatherCard.classList.remove('visible');
  hideLoader();
}
function hideError()  { errorCard.classList.remove('visible'); }
function showLoader() {
  loader.classList.add('visible');
  weatherCard.classList.remove('visible');
  hideError();
}
function hideLoader() { loader.classList.remove('visible'); }

function shakeElement(el) {
  if (!el) return;
  el.style.animation = 'none';
  el.offsetHeight; // reflow
  el.style.animation = 'shake 0.4s ease';
  setTimeout(() => { el.style.animation = ''; }, 450);
}

// ─── Toast Notification ───────────────────────────────────────
function showToast(msg, duration = 3000) {
  let toast = document.getElementById('wwToast');
  if (!toast) {
    toast = document.createElement('div');
    toast.id = 'wwToast';
    toast.style.cssText = `
      position:fixed; bottom:28px; left:50%; transform:translateX(-50%);
      background:rgba(20,40,100,0.92); color:#e8f0ff;
      padding:12px 24px; border-radius:50px;
      font-family:'Outfit',sans-serif; font-size:0.92rem;
      backdrop-filter:blur(16px); border:1px solid rgba(165,200,255,0.3);
      z-index:9999; transition:opacity 0.4s ease;
      box-shadow:0 8px 32px rgba(0,0,0,0.35);
      white-space:nowrap;`;
    document.body.appendChild(toast);
  }
  toast.textContent = msg;
  toast.style.opacity = '1';
  clearTimeout(toast._timer);
  toast._timer = setTimeout(() => { toast.style.opacity = '0'; }, duration);
}

// ─── Inject shake keyframe ─────────────────────────────────────
const shakeStyle = document.createElement('style');
shakeStyle.textContent = `
  @keyframes shake {
    0%,100%{transform:translateX(0)}
    20%{transform:translateX(-8px)}
    40%{transform:translateX(8px)}
    60%{transform:translateX(-5px)}
    80%{transform:translateX(5px)}
  }`;
document.head.appendChild(shakeStyle);
