const CACHE='piscina-static-v1';
const ROOT=new URL('./',self.location.href);
const ASSETS=['assets/app.css','assets/app.js','assets/inter-latin.woff2','assets/placeholder.svg','icons/icon.svg','icons/icon-192.png','icons/icon-512.png','icons/maskable-512.png','offline.html'].map(p=>new URL(p,ROOT).href);
self.addEventListener('install',e=>e.waitUntil(caches.open(CACHE).then(c=>c.addAll(ASSETS))));
self.addEventListener('message',e=>{if(e.data?.type==='ACTIVATE')self.skipWaiting();});
self.addEventListener('activate',e=>e.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k.startsWith('piscina-static-')&&k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim())));
self.addEventListener('fetch',e=>{
 if(e.request.method!=='GET')return;
 const u=new URL(e.request.url);if(u.origin!==ROOT.origin)return;
 // Lista explícita: nenhum HTML dinâmico, API, sessão, administração ou resultado entra no cache.
 if(ASSETS.includes(u.href)){e.respondWith(caches.match(e.request).then(c=>c||fetch(e.request)));return;}
 if(e.request.mode==='navigate'){e.respondWith(fetch(e.request).catch(()=>caches.match(new URL('offline.html',ROOT).href)));}
});
