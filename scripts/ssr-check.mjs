/* Vérification SSR manuelle (dev) : rend côté serveur la fiche demandée
   (comme en production) et affiche le <title> et la meta description.
   Usage: node scripts/ssr-check.mjs http://app:8000/abc/abc-de-murs-erigne */
import http from 'node:http';
import { setTimeout as sleep } from 'node:timers/promises';

const PAGE_URL = process.argv[2] ?? 'http://app:8000/abc/abc-de-murs-erigne';
const SSR_PORT = Number(process.env.SSR_PORT ?? 13714);

function get(url) {
    return new Promise((resolve, reject) => {
        http.get(url, (res) => {
            let data = '';
            res.on('data', (c) => (data += c));
            res.on('end', () => resolve(data));
        }).on('error', reject);
    });
}

function post(url, body) {
    return new Promise((resolve, reject) => {
        const u = new URL(url);
        const req = http.request({ hostname: u.hostname, port: Number(u.port), path: u.pathname, method: 'POST', headers: { 'Content-Type': 'application/json' } }, (res) => {
            let data = '';
            res.on('data', (c) => (data += c));
            res.on('end', () => resolve(data));
        });
        req.on('error', reject);
        req.end(body);
    });
}

const html = await get(PAGE_URL);
const m = html.match(/<div id="app" data-page="([^"]*)"/);
if (!m) {
    console.error('Pas de data-page dans la réponse — SSR local désactivé et page introuvable ?');
    process.exit(1);
}
const page = JSON.parse(m[1].replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&#039;/g, "'"));
console.log('component:', page.component);

// Le serveur SSR doit être lancé à part :
//   SSR_PORT=13714 node bootstrap/ssr/ssr.js &
let render = null;
for (let i = 0; i < 20; i++) {
    try { render = await post(`http://127.0.0.1:${SSR_PORT}/render`, JSON.stringify(page)); break; } catch { await sleep(500); }
}
if (!render) {
    console.error('Serveur SSR indisponible sur', SSR_PORT, '(lancer: SSR_PORT=' + SSR_PORT + ' node bootstrap/ssr/ssr.js &)');
    process.exit(1);
}
const r = JSON.parse(render);
const head = Array.isArray(r.head) ? r.head.join('') : (r.head || '');
const t = head.match(/<title[^>]*>(.*?)<\/title>/);
const d = head.match(/<meta name="description" content="(.*?)"/);
console.log('TITLE:', t ? t[1] : 'ABSENT');
console.log('DESC :', d ? d[1] : 'ABSENT');
