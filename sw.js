// Service Worker — кешування для офлайн
const CACHE_NAME = 'unique-curls-v1';

// Файли для кешування
const PRECACHE = [
  '/',
  '/manifest.json'
];

// Встановлення
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(PRECACHE))
      .then(() => self.skipWaiting())
  );
});

// Активація — очищуємо старий кеш
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => 
      Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

// Стратегія: спочатку мережа, потім кеш
self.addEventListener('fetch', event => {
  // Тільки GET запити
  if (event.request.method !== 'GET') return;
  
  // API запити — завжди з мережі
  if (event.request.url.includes('/api/')) return;
  
  event.respondWith(
    fetch(event.request)
      .then(response => {
        // Кешуємо успішні відповіді
        if (response.ok) {
          const clone = response.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
        }
        return response;
      })
      .catch(() => {
        // Офлайн — повертаємо з кешу
        return caches.match(event.request);
      })
  );
});