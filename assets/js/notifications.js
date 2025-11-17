const body = document.body || document.querySelector('body');
const dataSet = body ? body.dataset : {};
const serviceWorkerPath = (dataSet && dataSet.serviceWorker) ? dataSet.serviceWorker : '/service-worker.js';
const userId = (dataSet && dataSet.userId) ? dataSet.userId : '';
const subscriptionEndpoint = (dataSet && dataSet.pushEndpoint) ? dataSet.pushEndpoint : '';
const vapidKey = (dataSet && dataSet.vapidKey) ? dataSet.vapidKey : '';
const csrfMeta = document.querySelector('meta[name="csrf-token"]');
const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

const toastContainerId = 'toast-container';

function ensureToastContainer() {
  let container = document.getElementById(toastContainerId);
  if (!container) {
    container = document.createElement('div');
    container.id = toastContainerId;
    document.body.appendChild(container);
  }
  return container;
}

export function showToast(message, type = 'info') {
  const container = ensureToastContainer();
  const toast = document.createElement('div');
  toast.className = 'toast-message';
  toast.dataset.variant = type;
  const content = document.createElement('div');
  content.className = 'toast-message__content';

  const bodyEl = document.createElement('div');
  bodyEl.className = 'toast-message__body';
  bodyEl.textContent = message;

  const dismissBtn = document.createElement('button');
  dismissBtn.type = 'button';
  dismissBtn.className = 'toast-dismiss';
  dismissBtn.setAttribute('aria-label', 'Dismiss');
  dismissBtn.textContent = '×';

  content.appendChild(bodyEl);
  content.appendChild(dismissBtn);
  toast.appendChild(content);
  container.appendChild(toast);
  requestAnimationFrame(() => {
    toast.dataset.state = 'visible';
  });
  const close = () => {
    toast.dataset.state = 'hidden';
    setTimeout(() => toast.remove(), 250);
  };
  dismissBtn.addEventListener('click', close);
  setTimeout(close, 6000);
  return toast;
}

window.showToast = showToast;

function flushSessionToasts() {
  const pending = Array.isArray(window.__SESSION_TOASTS) ? window.__SESSION_TOASTS : [];
  pending.forEach((toast) => {
    const message = typeof toast.message === 'string' ? toast.message : '';
    const type = typeof toast.type === 'string' ? toast.type : 'info';
    if (message) {
      showToast(message, type);
    }
  });
  delete window.__SESSION_TOASTS;
}

function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = window.atob(base64);
  const outputArray = new Uint8Array(rawData.length);
  for (let i = 0; i < rawData.length; i += 1) {
    outputArray[i] = rawData.charCodeAt(i);
  }
  return outputArray;
}

async function sendSubscription(subscription) {
  if (!subscriptionEndpoint || !userId) return;
  try {
    await fetch(subscriptionEndpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken,
      },
      credentials: 'same-origin',
      body: JSON.stringify({ subscription, user_id: userId }),
    });
  } catch (err) {
    console.warn('Failed to register subscription', err);
  }
}

async function ensureSubscription(force = false) {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
  if (!userId || !subscriptionEndpoint || !vapidKey) return;
  if (Notification.permission === 'denied') {
    return;
  }
  if (Notification.permission === 'default') {
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') {
      return;
    }
  }
  const registration = await navigator.serviceWorker.ready;
  let subscription = await registration.pushManager.getSubscription();
  if (!subscription || force) {
    if (subscription && force) {
      try { await subscription.unsubscribe(); } catch (err) { /* noop */ }
    }
    subscription = await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(vapidKey),
    });
  }
  if (subscription) {
    const payload = typeof subscription.toJSON === 'function' ? subscription.toJSON() : JSON.parse(JSON.stringify(subscription));
    await sendSubscription(payload);
  }
}

async function registerServiceWorker() {
  if (!('serviceWorker' in navigator)) {
    return null;
  }
  try {
    const registration = await navigator.serviceWorker.register(serviceWorkerPath);
    return registration;
  } catch (err) {
    console.warn('Service worker registration failed', err);
    return null;
  }
}

function handleServiceWorkerMessages() {
  if (!('serviceWorker' in navigator)) return;
  navigator.serviceWorker.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'pushsubscriptionchange') {
      ensureSubscription(true);
    }
  });
}

async function bootstrap() {
  flushSessionToasts();
  await registerServiceWorker();
  handleServiceWorkerMessages();
  if (userId) {
    ensureSubscription();
  }
}

document.addEventListener('DOMContentLoaded', bootstrap);
