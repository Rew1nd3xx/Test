/* DApp — Service Worker
   Network-first für die App-Hülle (Updates kommen sofort an, wenn online;
   Offline-Fallback aus dem Cache). Network-first mit Cache-Fallback auch
   für externe Ressourcen (Google Fonts, jsPDF).
*/

const CACHE_VERSION = "dapp-v5";
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
