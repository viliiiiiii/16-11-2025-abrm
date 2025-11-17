const DEFAULT_ICON = '/assets/logo.png';

self.addEventListener('install', (event) => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('push', (event) => {
  let data = {};
  if (event.data) {
    try {
      data = event.data.json();
    } catch (err) {
      try {
        data = JSON.parse(event.data.text());
      } catch (e) {
        data = {};
      }
    }
  }

  const title = data.title || 'Notification';
  const options = {
    body: data.body || '',
    icon: data.icon || DEFAULT_ICON,
    data: {
      url: data.url || '/',
    },
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || '/';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientsList) => {
      for (const client of clientsList) {
        if (client.url === url) {
          return client.focus();
        }
      }
      return clients.openWindow(url);
    })
  );
});

self.addEventListener('pushsubscriptionchange', (event) => {
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientsList) => {
      clientsList.forEach((client) => {
        client.postMessage({ type: 'pushsubscriptionchange' });
      });
    })
  );
});
