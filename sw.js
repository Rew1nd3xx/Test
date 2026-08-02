/* DApp — Service Worker
   Network-first für die App-Hülle (Updates kommen sofort an, wenn online;
   Offline-Fallback aus dem Cache). Network-first mit Cache-Fallback auch
   für externe Ressourcen (Google Fonts, jsPDF).
*/

const CACHE_VERSION = "dapp-v6";
const SHELL_CACHE = `${CACHE_VERSION}-shell`;
const RUNTIME_CACHE = `${CACHE_VERSION}-runtime`;

const SHELL_FILES = [
  "./",
  "./index.html",
  "./manifest.json",
  "./icons/icon-192.png",
  "./icons/icon-512.png",
  "./icons/icon-maskable-512.png"
];

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(SHELL_CACHE)
      .then((cache) => cache.addAll(SHELL_FILES))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((key) => key !== SHELL_CACHE && key !== RUNTIME_CACHE)
          .map((key) => caches.delete(key))
      )
    ).then(() => self.clients.claim())
  );
});

self.addEventListener("push", (event) => {
  event.waitUntil(handlePushWake());
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const targetUrl = (event.notification.data && event.notification.data.url) || "./";
  event.waitUntil(
    self.clients.matchAll({ type: "window", includeUncontrolled: true }).then((list) => {
      for (const client of list) {
        if ("focus" in client) return client.focus();
      }
      if (self.clients.openWindow) return self.clients.openWindow(targetUrl);
    })
  );
});

async function handlePushWake() {
  try {
    const cache = await caches.open("push-config");
    const res = await cache.match("/config");
    if (!res) return; // Noch nicht eingerichtet — nichts zu tun
    const cfg = await res.json();
    if (!cfg.serverUrl || !cfg.deviceId) return;
    const fetchUrl = cfg.serverUrl.replace(/push-subscribe\.php.*$/, "push-fetch.php") + "?device=" + encodeURIComponent(cfg.deviceId);
    const pendingRes = await fetch(fetchUrl);
    if (!pendingRes.ok) return;
    const pending = await pendingRes.json();
    const notifications = pending.notifications || [];
    for (const n of notifications) {
      await self.registration.showNotification(n.title || "DApp", {
        body: n.body || "",
        icon: "./icons/icon-192.png",
        badge: "./icons/icon-192.png",
        data: { url: n.url || "./" }
      });
    }
  } catch (e) {
    // Aufwecken ohne Inhalt (z. B. Server gerade nicht erreichbar) — einfach nichts anzeigen,
    // statt einen kryptischen Fehler zu riskieren.
  }
}

self.addEventListener("fetch", (event) => {
  const req = event.request;
  if (req.method !== "GET") return;

  const url = new URL(req.url);
  const isSameOrigin = url.origin === self.location.origin;

  // App-Hülle: network-first, damit neue Versionen sofort ankommen,
  // sobald online — mit Cache als Fallback für den Offline-Fall.
  if (isSameOrigin) {
    event.respondWith(
      fetch(req, { cache: "no-store" })
        .then((res) => {
          const copy = res.clone();
          caches.open(SHELL_CACHE).then((cache) => cache.put(req, copy));
          return res;
        })
        .catch(() =>
          caches.match(req).then((cached) => {
            if (cached) return cached;
            if (req.mode === "navigate") return caches.match("./index.html");
            return new Response("", { status: 504, statusText: "Offline" });
          })
        )
    );
    return;
  }

  // Nur bekannte statische Ressourcen-CDNs cachen (Schriften, jsPDF).
  // Alle anderen fremden Adressen (eigene Sync-Server, Wetter-/Ferien-APIs, …)
  // NICHT abfangen — die sollen sich wie ein normaler fetch() verhalten,
  // damit echte Fehler (CORS, Zertifikat, Server down) nicht hinter einem
  // verschluckten Cache-Fallback verschwinden.
  const STATIC_ASSET_HOSTS = ["fonts.googleapis.com", "fonts.gstatic.com", "cdnjs.cloudflare.com"];
  if (!STATIC_ASSET_HOSTS.includes(url.hostname)) return;

  event.respondWith(
    fetch(req)
      .then((res) => {
        const copy = res.clone();
        caches.open(RUNTIME_CACHE).then((cache) => cache.put(req, copy));
        return res;
      })
      .catch(() => caches.match(req).then((cached) => cached || new Response("", { status: 504, statusText: "Offline" })))
  );
});
