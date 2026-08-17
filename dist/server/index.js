import http from "node:http";
import { timingSafeEqual } from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import { openDb, saveVerification, insertContribution } from "../src/db.js";
import { backupDb } from "../src/backup.js";
import { collectAll } from "../src/collect.js";
import { buildGeoJson } from "../src/geojson.js";
import { ROOT, SOURCE_DATES } from "../src/config.js";
import { createSession, deleteSession, getSession, serializeCookie, parseCookies, clientIp, verifyPassword, SESSION_COOKIE, } from "../src/auth.js";
import { RateLimiter } from "../src/ratelimit.js";
import { contributionSchema, contributionToJson, applyContribution, rejectContribution, revertContribution, } from "../src/contributions.js";
const PORT = Number(process.env.PORT || 4000);
const PUBLIC = path.join(ROOT, "server", "public");
const ADMIN_USERNAME = process.env.ADMIN_USERNAME || "admin";
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || "";
const TRUST_PROXY = process.env.TRUST_PROXY === "1";
const COOKIE_SECURE = process.env.COOKIE_SECURE === "1";
// ADMIN_PASSWORD peut être fourni soit en clair (dans .env), soit déjà hashé au
// format scrypt `sel:hash` (produit par hashPassword). Détection : un ":" présent.
function checkAdminPassword(submitted) {
    if (ADMIN_PASSWORD.includes(":"))
        return verifyPassword(submitted, ADMIN_PASSWORD);
    const a = Buffer.from(submitted);
    const b = Buffer.from(ADMIN_PASSWORD);
    return a.length === b.length && timingSafeEqual(a, b);
}
const loginLimiter = new RateLimiter(15 * 60 * 1000, 5);
const contributionLimiter = new RateLimiter(60 * 60 * 1000, 10);
function readBody(req, limit = 64 * 1024) {
    return new Promise((resolve, reject) => {
        let body = "";
        req.on("data", (chunk) => {
            body += chunk;
            if (body.length > limit) {
                reject(new Error("Corps de requête trop volumineux"));
                req.destroy();
            }
        });
        req.on("end", () => resolve(body));
        req.on("error", reject);
    });
}
function sendJson(res, status, data) {
    res.writeHead(status, { "content-type": "application/json" });
    res.end(JSON.stringify(data));
}
function toGeoJson() {
    return buildGeoJson(openDb());
}
function adminFrom(req) {
    const token = parseCookies(req.headers.cookie)[SESSION_COOKIE];
    const session = getSession(openDb(), token);
    return session ? session.username : null;
}
function requireAdmin(req, res) {
    const username = adminFrom(req);
    if (!username) {
        sendJson(res, 401, { error: "Authentification requise" });
        return { ok: false };
    }
    return { ok: true, username };
}
const mime = {
    ".html": "text/html; charset=utf-8",
    ".js": "application/javascript",
    ".css": "text/css",
    ".png": "image/png",
    ".json": "application/json",
};
function routeContributionAdmin(urlPath, req, res) {
    const m = urlPath.match(/^\/api\/admin\/contributions\/(\d+)\/(valider|refuser|retirer)$/);
    if (!m)
        return false;
    const id = Number(m[1]);
    const action = m[2];
    (async () => {
        const auth = requireAdmin(req, res);
        if (!auth.ok)
            return;
        let noteAdmin;
        if (action === "refuser") {
            try {
                const body = await readBody(req);
                noteAdmin = JSON.parse(body).note_admin;
            }
            catch {
                /* note optionnelle */
            }
        }
        const db = openDb();
        let result;
        if (action === "valider")
            result = applyContribution(db, id, auth.username);
        else if (action === "refuser")
            result = rejectContribution(db, id, auth.username, noteAdmin);
        else
            result = revertContribution(db, id, auth.username);
        if (!result.ok)
            return sendJson(res, 400, { error: result.error });
        sendJson(res, 200, { ok: true });
    })();
    return true;
}
const server = http.createServer((req, res) => {
    const urlPath = (req.url ?? "/").split("?")[0];
    // Toujours servir des données fraîches (évite le cache navigateur sur les
    // corrections/anomalies/vérifications mises à jour sans redémarrage).
    res.setHeader("cache-control", "no-store");
    if (urlPath === "/api/abc.geojson") {
        const data = toGeoJson();
        res.writeHead(200, { "content-type": "application/json" });
        res.end(JSON.stringify(data));
        return;
    }
    if (urlPath === "/api/meta") {
        const db = openDb();
        const stats = db
            .prepare("SELECT statut, COUNT(*) AS n FROM projets GROUP BY statut")
            .all();
        const countProjets = db
            .prepare("SELECT COUNT(*) AS n FROM projets")
            .get();
        const countPt = db
            .prepare("SELECT COUNT(*) AS n FROM projets WHERE potentiellement_termine = 1")
            .get();
        const countPec = db
            .prepare("SELECT COUNT(*) AS n FROM projets WHERE potentiellement_en_cours = 1")
            .get();
        const countStale = db
            .prepare("SELECT COUNT(*) AS n FROM projets WHERE source = 'wayback'")
            .get();
        const countEstimes = db
            .prepare("SELECT COUNT(*) AS n FROM projets WHERE estime_termine = 1")
            .get();
        const countVerifies = db
            .prepare("SELECT COUNT(*) AS n FROM verifications WHERE etat != 'a_verifier'")
            .get();
        const countAnomalies = db
            .prepare("SELECT COUNT(*) AS n FROM communes WHERE anomalie = 1")
            .get();
        const countContributionsEnAttente = db
            .prepare("SELECT COUNT(*) AS n FROM contributions WHERE statut = 'en_attente'")
            .get();
        res.writeHead(200, { "content-type": "application/json" });
        res.end(JSON.stringify({
            sources: SOURCE_DATES,
            stats,
            countProjets: countProjets.n,
            countPotentiellementTermines: countPt.n,
            countPotentiellementEnCours: countPec.n,
            countDonnees2022: countStale.n,
            countEstimes: countEstimes.n,
            countVerifies: countVerifies.n,
            countAnomalies: countAnomalies.n,
            countContributionsEnAttente: countContributionsEnAttente.n,
        }));
        return;
    }
    if (urlPath === "/api/stats") {
        const db = openDb();
        const stats = db
            .prepare("SELECT statut, COUNT(*) AS n FROM projets GROUP BY statut")
            .all();
        res.writeHead(200, { "content-type": "application/json" });
        res.end(JSON.stringify(stats));
        return;
    }
    // --- Diagnostic (aucun secret : uniquement l'état de la config) ---
    if (urlPath === "/api/diag") {
        const adminState = !ADMIN_PASSWORD
            ? "absent"
            : ADMIN_PASSWORD.includes(":")
                ? "hash"
                : "plain";
        return sendJson(res, 200, {
            adminState,
            adminUsername: ADMIN_USERNAME,
            envFilePresent: fs.existsSync(path.join(ROOT, ".env")),
            root: ROOT,
            cwd: process.cwd(),
            port: PORT,
            nodePath: process.execPath,
            nodeVersion: process.version,
        });
    }
    // --- Administration ---
    if (urlPath === "/api/admin/login" && req.method === "POST") {
        const ip = clientIp(req, TRUST_PROXY);
        if (!loginLimiter.allow(`login:${ip}`)) {
            return sendJson(res, 429, { error: "Trop de tentatives, réessayez plus tard" });
        }
        (async () => {
            try {
                const body = JSON.parse(await readBody(req));
                if (!ADMIN_PASSWORD) {
                    return sendJson(res, 500, { error: "ADMIN_PASSWORD non défini sur le serveur" });
                }
                if (body.username !== ADMIN_USERNAME || !checkAdminPassword(body.password ?? "")) {
                    return sendJson(res, 401, { error: "Identifiants invalides" });
                }
                const db = openDb();
                const { token } = createSession(db, ADMIN_USERNAME);
                res.writeHead(200, {
                    "content-type": "application/json",
                    "set-cookie": serializeCookie(SESSION_COOKIE, token, {
                        maxAgeSeconds: 7 * 24 * 3600,
                        secure: COOKIE_SECURE,
                        path: "/",
                    }),
                });
                res.end(JSON.stringify({ ok: true, username: ADMIN_USERNAME }));
            }
            catch {
                sendJson(res, 400, { error: "Requête invalide" });
            }
        })();
        return;
    }
    if (urlPath === "/api/admin/logout" && req.method === "POST") {
        const token = parseCookies(req.headers.cookie)[SESSION_COOKIE];
        deleteSession(openDb(), token);
        res.writeHead(200, {
            "content-type": "application/json",
            "set-cookie": serializeCookie(SESSION_COOKIE, "", { maxAgeSeconds: 0, secure: COOKIE_SECURE }),
        });
        res.end(JSON.stringify({ ok: true }));
        return;
    }
    if (urlPath === "/api/admin/me") {
        const token = parseCookies(req.headers.cookie)[SESSION_COOKIE];
        const session = getSession(openDb(), token);
        sendJson(res, 200, session ? { admin: true, username: session.username } : { admin: false });
        return;
    }
    if (urlPath === "/api/admin/backup" && req.method === "POST") {
        const token = parseCookies(req.headers.cookie)[SESSION_COOKIE];
        if (!getSession(openDb(), token))
            return sendJson(res, 401, { error: "Non autorisé" });
        try {
            const r = backupDb();
            sendJson(res, 200, { ok: true, path: r.path, kept: r.kept });
        }
        catch (e) {
            sendJson(res, 500, { error: String(e) });
        }
        return;
    }
    if (urlPath === "/api/admin/collect" && req.method === "POST") {
        const auth = requireAdmin(req, res);
        if (!auth.ok)
            return;
        (async () => {
            try {
                const summary = await collectAll();
                sendJson(res, 200, { ok: true, summary });
            }
            catch (e) {
                sendJson(res, 500, { error: String(e) });
            }
        })();
        return;
    }
    if (urlPath === "/api/admin/contributions") {
        const auth = requireAdmin(req, res);
        if (!auth.ok)
            return;
        const db = openDb();
        const rows = db
            .prepare(`SELECT c.*, p.nom AS projet_nom, p.structure_porteuse,
                v.etat AS verif_etat, v.note AS verif_note, v.lien AS verif_lien
         FROM contributions c
         LEFT JOIN projets p ON p.id = c.projet_id
         LEFT JOIN verifications v ON v.projet_id = c.projet_id
         ORDER BY CASE c.statut WHEN 'en_attente' THEN 0 WHEN 'validee' THEN 1 ELSE 2 END, c.created_at DESC`)
            .all();
        sendJson(res, 200, { contributions: rows });
        return;
    }
    if (routeContributionAdmin(urlPath, req, res))
        return;
    // --- Contributions publiques ---
    if (urlPath === "/api/contributions" && req.method === "POST") {
        const ip = clientIp(req, TRUST_PROXY);
        if (!contributionLimiter.allow(`contribution:${ip}`)) {
            return sendJson(res, 429, { error: "Trop de contributions, réessayez plus tard" });
        }
        (async () => {
            try {
                const parsed = contributionSchema.safeParse(JSON.parse(await readBody(req)));
                if (!parsed.success) {
                    return sendJson(res, 400, { error: parsed.error.issues.map((i) => i.message).join("; ") });
                }
                const db = openDb();
                const projet = db.prepare("SELECT id FROM projets WHERE id = ?").get(parsed.data.projet_id);
                if (!projet)
                    return sendJson(res, 404, { error: "Projet introuvable" });
                const id = insertContribution(db, {
                    projet_id: parsed.data.projet_id,
                    type: parsed.data.type,
                    payload_json: contributionToJson(parsed.data),
                    commentaire: parsed.data.commentaire,
                    ip,
                    user_agent: req.headers["user-agent"]?.slice(0, 200),
                });
                sendJson(res, 201, { ok: true, id });
            }
            catch {
                sendJson(res, 400, { error: "Requête invalide" });
            }
        })();
        return;
    }
    if (urlPath === "/api/contributions") {
        const db = openDb();
        const rows = db
            .prepare(`SELECT projet_id, type, payload_json, commentaire, created_at, statut
         FROM contributions
         ORDER BY created_at DESC`)
            .all();
        sendJson(res, 200, { contributions: rows });
        return;
    }
    if (urlPath === "/api/verifications" && req.method === "POST") {
        const auth = requireAdmin(req, res);
        if (!auth.ok)
            return;
        (async () => {
            try {
                const v = JSON.parse(await readBody(req));
                if (!v.projet_id || !v.etat)
                    throw new Error("champs manquants");
                const db = openDb();
                saveVerification(db, { projet_id: v.projet_id, etat: v.etat, note: v.note, lien: v.lien });
                if (v.etat === "confirme_date" && (v.annee_debut !== undefined || v.annee_fin !== undefined)) {
                    db.prepare("UPDATE projets SET annee_debut = ?, annee_fin = ?, updated_at = datetime('now') WHERE id = ?").run(v.annee_debut ?? null, v.annee_fin ?? null, v.projet_id);
                }
                sendJson(res, 200, { ok: true });
            }
            catch (e) {
                sendJson(res, 400, { error: e.message });
            }
        })();
        return;
    }
    if (urlPath === "/api/verifications") {
        const db = openDb();
        const rows = db
            .prepare(`SELECT p.id, p.nom, p.structure_porteuse, p.annee_debut, p.statut, p.source,
                p.potentiellement_termine, p.potentiellement_en_cours,
                v.etat, v.note, v.lien, v.verifie_le,
                (SELECT GROUP_CONCAT(c.libelle_geographique, ', ') FROM communes c
                 WHERE c.projet_id = p.id) AS communes,
                (SELECT GROUP_CONCAT(c.libelle_departement, ', ') FROM communes c
                 WHERE c.projet_id = p.id AND c.libelle_departement IS NOT NULL) AS departements,
                (SELECT GROUP_CONCAT(c2.libelle_geographique, ', ') FROM communes c2
                 WHERE c2.projet_id = p.id AND c2.anomalie = 1) AS communes_anormales
         FROM projets p LEFT JOIN verifications v ON v.projet_id = p.id
         WHERE p.potentiellement_termine = 1 OR p.potentiellement_en_cours = 1 OR p.source = 'wayback'
            OR p.annee_debut IS NULL
            OR EXISTS (SELECT 1 FROM communes c3 WHERE c3.projet_id = p.id AND c3.anomalie = 1)
         ORDER BY (v.etat IS NULL OR v.etat = 'a_verifier') DESC, p.nom`)
            .all();
        const projets = rows.map((p) => {
            const motifs = [];
            if (p.potentiellement_termine === 1)
                motifs.push("potentiellement terminé");
            if (p.potentiellement_en_cours === 1)
                motifs.push("potentiellement en cours");
            if (p.source === "wayback")
                motifs.push("archives 2022");
            if (p.communes_anormales)
                motifs.push("anomalie");
            if (p.annee_debut === null || p.annee_debut === undefined)
                motifs.push("date inconnue");
            const place = (p.structure_porteuse ?? p.nom).replace(/ABC\s*/i, "").trim();
            const cible = p.communes_anormales ?? (p.communes ?? "").split(",")[0].trim();
            const requete = `"atlas de la biodiversité communale" "${place}" ${cible}`.trim();
            return {
                id: p.id,
                nom: p.nom,
                structure_porteuse: p.structure_porteuse,
                annee_debut: p.annee_debut,
                statut: p.statut,
                source: p.source,
                motifs,
                communes: p.communes,
                departements: p.departements,
                requete,
                etat: p.etat ?? "a_verifier",
                note: p.note,
                lien: p.lien,
                verifie_le: p.verifie_le,
            };
        });
        const compteurs = {};
        for (const p of projets)
            compteurs[p.etat] = (compteurs[p.etat] ?? 0) + 1;
        sendJson(res, 200, { projets, compteurs });
        return;
    }
    let file = path.join(PUBLIC, urlPath === "/" ? "index.html" : urlPath === "/login" ? "login.html" : urlPath === "/verify" ? "verify.html" : urlPath === "/admin" ? "admin.html" : urlPath);
    if (!fs.existsSync(file)) {
        res.writeHead(404, { "content-type": "text/plain" });
        res.end("Not found");
        return;
    }
    const ext = path.extname(file);
    res.writeHead(200, { "content-type": mime[ext] ?? "application/octet-stream" });
    fs.createReadStream(file).pipe(res);
});
server.listen(PORT, () => {
    console.log(`Observatoire des ABC — http://localhost:${PORT}`);
    console.log(`Admin: user="${ADMIN_USERNAME}", mdp=${ADMIN_PASSWORD ? (ADMIN_PASSWORD.includes(":") ? "hash" : "clair") : "ABSENT"}`);
    if (!ADMIN_PASSWORD) {
        console.warn("⚠ ADMIN_PASSWORD non défini : le panneau admin est inaccessible.");
    }
});
// --- Sauvegarde quotidienne automatique (sans CRON) ---
function msUntilNextDaily(hourUtc) {
    const now = new Date();
    const next = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate(), hourUtc, 0, 0, 0));
    if (next.getTime() <= now.getTime())
        next.setUTCDate(next.getUTCDate() + 1);
    return next.getTime() - now.getTime();
}
const BACKUP_DAILY = process.env.BACKUP_DAILY !== "0";
const BACKUP_HOUR_UTC = Number(process.env.BACKUP_HOUR_UTC ?? 4);
function runBackup(tag) {
    try {
        const r = backupDb();
        console.log(`[backup:${tag}] OK ${r.path} (${r.kept} gardées)`);
    }
    catch (e) {
        console.error(`[backup:${tag}] ECHEC`, e);
    }
}
if (BACKUP_DAILY) {
    // Sauvegarde immédiate au démarrage (permet de vérifier que ça fonctionne).
    setTimeout(() => runBackup("init"), 5000);
    const schedule = () => {
        const t = msUntilNextDaily(BACKUP_HOUR_UTC);
        console.log(`Prochaine sauvegarde auto dans ${Math.round(t / 3600000)} h (${BACKUP_HOUR_UTC}:00 UTC)`);
        setTimeout(() => {
            runBackup("auto");
            schedule();
        }, t);
    };
    schedule();
}
else {
    console.log("Sauvegarde quotidienne désactivée (BACKUP_DAILY=0)");
}
