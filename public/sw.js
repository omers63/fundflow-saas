const CACHE_VERSION = 'fundflow-v2';
const STATIC_CACHE = `static-${CACHE_VERSION}`;
const DYNAMIC_CACHE = `dynamic-${CACHE_VERSION}`;

const STATIC_ASSETS = [
    '/offline',
    '/manifest.json',
    '/icons/icon-192x192.png',
    '/icons/icon-512x512.png',
    '/icons/apple-touch-icon.png',
    '/favicon.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE).then((cache) => cache.addAll(STATIC_ASSETS))
    );
    self.skipWaiting();
});

self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys
                    .filter((key) => key !== STATIC_CACHE && key !== DYNAMIC_CACHE)
                    .map((key) => caches.delete(key))
            )
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    if (request.method !== 'GET') return;

    if (url.pathname.startsWith('/livewire')) return;

    if (
        url.pathname.match(/\.(css|js|woff2?|ttf|png|jpg|svg|ico)$/) ||
        url.pathname.startsWith('/build/') ||
        url.pathname.startsWith('/icons/')
    ) {
        event.respondWith(
            caches.match(request).then(
                (cached) =>
                    cached ||
                    fetch(request).then((response) => {
                        const clone = response.clone();
                        caches.open(STATIC_CACHE).then((cache) => cache.put(request, clone));
                        return response;
                    })
            )
        );
        return;
    }

    if (request.headers.get('accept')?.includes('text/html')) {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    const clone = response.clone();
                    caches.open(DYNAMIC_CACHE).then((cache) => cache.put(request, clone));
                    return response;
                })
                .catch(() => caches.match(request).then((cached) => cached || caches.match('/offline')))
        );
        return;
    }

    event.respondWith(
        caches.match(request).then((cached) => cached || fetch(request))
    );
});

self.addEventListener('push', (event) => {
    let data = { title: 'FundFlow', body: '' };

    if (event.data) {
        try {
            data = event.data.json();
        } catch {
            data = { ...data, body: event.data.text() };
        }
    }

    const title = data.title || 'FundFlow';
    const options = {
        body: data.body || '',
        icon: data.icon || '/icons/icon-192x192.png',
        badge: data.badge || '/icons/icon-192x192.png',
        tag: data.tag,
        data: data.data || { url: data.url },
        actions: data.actions || [],
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const payload = event.notification.data || {};
    const url = payload.url || payload.action_url;

    if (!url) {
        return;
    }

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            const target = new URL(url, self.location.origin).href;

            for (const client of clientList) {
                if (client.url.startsWith(target) && 'focus' in client) {
                    return client.focus();
                }
            }

            for (const client of clientList) {
                if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
                    return client.focus();
                }
            }

            if (clients.openWindow) {
                return clients.openWindow(target);
            }
        })
    );
});
