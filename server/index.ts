import http from "node:http";
import { timingSafeEqual } from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import type { IncomingMessage, ServerResponse } from "node:http";
import { openDb, saveVerification, insertContribution } from "../src/db.js";
import { buildGeoJson } from "../src/geojson.js";
import { EXPORT_DIR, ROOT, SOURCE_DATES } from "../src/config.js";
import { statutLabel } from "../src/status.js";
import {
  createSession,
  deleteSession,
  getSession,
  serializeCookie,
  parseCookies,
  clientIp,
  verifyPassword,
  SESSION_COOKIE,
} from "../src/auth.js";
import { RateLimiter } from "../src/ratelimit.js";
import {
  contributionSchema,
  contributionToJson,
  applyContribution,
  rejectContribution,
  revertContribution,
} from "../src/contributions.js";

const PORT = Number(process.env.PORT || 4000);
const PUBLIC = path.join(ROOT, "server", "public");

const ADMIN_USERNAME = process.env.ADMIN_USERNAME || "admin";
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || "";
const TRUST_PROXY = process.env.TRUST_PROXY === "1";
const COOKIE_SECURE = process.env.COOKIE_SECURE === "1";

// ADMIN_PASSWORD peut être fourni soit en clair (dans .env), soit déjà hashé au
// format scrypt `sel:hash` (produit par hashPassword). Détection : un ":" présent.
function checkAdminPassword(submitted: string): boolean {
  if (ADMIN_PASSWORD.includes(":")) return verifyPassword(submitted, ADMIN_PASSWORD);
  const a = Buffer.from(submitted);
  const b = Buffer.from(ADMIN_PASSWORD);
  return a.length === b.length && timingSafeEqual(a, b);
}

const loginLimiter = new RateLimiter(15 * 60 * 1000, 5);
const contributionLimiter = new RateLimiter(60 * 60 * 1000, 10);

function readBody(req: IncomingMessage, limit = 64 * 1024): Promise<string> {
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

function sendJson(res: ServerResponse, status: number, data: unknown) {
  res.writeHead(status, { "content-type": "application/json" });
  res.end(JSON.stringify(data));
}

function toGeoJson() {
  return buildGeoJson(openDb());
}

function adminFrom(req: IncomingMessage): string | null {
  const token = parseCookies(req.headers.cookie)[SESSION_COOKIE];
  const session = getSession(openDb(), token);
  return session ? session.username : null;
}

function requireAdmin(
  req: IncomingMessage,
  res: ServerResponse,
): { ok: true; username: string } | { ok: false } {
  const username = adminFrom(req);
  if (!username) {
    sendJson(res, 401, { error: "Authentification requise" });
    return { ok: false };
  }
  return { ok: true, username };
}

const mime: Record<string, string> = {
  ".html": "text/html; charset=utf-8",
  ".js": "application/javascript",
  ".css": "text/css",
  ".png": "image/png",
  ".json": "application/json",
};

function routeContributionAdmin(urlPath: string, req: IncomingMessage, res: ServerResponse) {
  const m = urlPath.match(/^\/api\/admin\/contributions\/(\d+)\/(valider|refuser|retirer)$/);
  if (!m) return false;
  const id = Number(m[1]);
  const action = m[2];
  (async () => {
    const auth = requireAdmin(req, res);
    if (!auth.ok) return;
    let noteAdmin: string | undefined;
    if (action === "refuser") {
      try {
        const body = await readBody(req);
        noteAdmin = (JSON.parse(body) as { note_admin?: string }).note_admin;
      } catch {
        /* note optionnelle */
      }
    }
    const db = openDb();
    let result: { ok: boolean; error?: string };
    if (action === "valider") result = applyContribution(db, id, auth.username);
    else if (action === "refuser") result = rejectContribution(db, id, auth.username, noteAdmin);
    else result = revertContribution(db, id, auth.username);
    if (!result.ok) return sendJson(res, 400, { error: result.error });
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
      .all() as { statut: string; n: number }[];
    const countProjets = db
      .prepare("SELECT COUNT(*) AS n FROM projets")
      .get() as { n: number };
    const countPt = db
      .prepare("SELECT COUNT(*) AS n FROM projets WHERE potentiellement_termine = 1")
      .get() as { n: number };
    const countPec = db
      .prepare("SELECT COUNT(*) AS n FROM projets WHERE potentiellement_en_cours = 1")
      .get() as { n: number };
    const countStale = db
      .prepare("SELECT COUNT(*) AS n FROM projets WHERE source = 'wayback'")
      .get() as { n: number };
    const countEstimes = db
      .prepare("SELECT COUNT(*) AS n FROM projets WHERE estime_termine = 1")
      .get() as { n: number };
    const countVerifies = db
      .prepare("SELECT COUNT(*) AS n FROM verifications WHERE etat != 'a_verifier'")
      .get() as { n: number };
    const countAnomalies = db
      .prepare("SELECT COUNT(*) AS n FROM communes WHERE anomalie = 1")
      .get() as { n: number };
    const countContributionsEnAttente = db
      .prepare("SELECT COUNT(*) AS n FROM contributions WHERE statut = 'en_attente'")
      .get() as { n: number };
    res.writeHead(200, { "content-type": "application/json" });
    res.end(
      JSON.stringify({
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
      }),
    );
    return;
  }

  if (urlPath === "/api/stats") {
    const db = openDb();
    const stats = db
      .prepare("SELECT statut, COUNT(*) AS n FROM projets GROUP BY statut")
      .all() as { statut: string; n: number }[];
    res.writeHead(200, { "content-type": "application/json" });
    res.end(JSON.stringify(stats));
    return;
  }

  // --- Administration ---
  if (urlPath === "/api/admin/login" && req.method === "POST") {
    const ip = clientIp(req, TRUST_PROXY);
    if (!loginLimiter.allow(`login:${ip}`)) {
      return sendJson(res, 429, { error: "Trop de tentatives, réessayez plus tard" });
    }
    (async () => {
      try {
        const body = JSON.parse(await readBody(req)) as { username?: string; password?: string };
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
      } catch {
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

  if (urlPath === "/api/admin/contributions") {
    const auth = requireAdmin(req, res);
    if (!auth.ok) return;
    const db = openDb();
    const rows = db
      .prepare(
        `SELECT c.*, p.nom AS projet_nom, p.structure_porteuse,
                v.etat AS verif_etat, v.note AS verif_note, v.lien AS verif_lien
         FROM contributions c
         LEFT JOIN projets p ON p.id = c.projet_id
         LEFT JOIN verifications v ON v.projet_id = c.projet_id
         ORDER BY CASE c.statut WHEN 'en_attente' THEN 0 WHEN 'validee' THEN 1 ELSE 2 END, c.created_at DESC`,
      )
      .all() as Record<string, unknown>[];
    sendJson(res, 200, { contributions: rows });
    return;
  }

  if (routeContributionAdmin(urlPath, req, res)) return;

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
        if (!projet) return sendJson(res, 404, { error: "Projet introuvable" });
        const id = insertContribution(db, {
          projet_id: parsed.data.projet_id,
          type: parsed.data.type,
          payload_json: contributionToJson(parsed.data),
          commentaire: parsed.data.commentaire,
          ip,
          user_agent: (req.headers["user-agent"] as string | undefined)?.slice(0, 200),
        });
        sendJson(res, 201, { ok: true, id });
      } catch {
        sendJson(res, 400, { error: "Requête invalide" });
      }
    })();
    return;
  }

  if (urlPath === "/api/contributions") {
    const db = openDb();
    const rows = db
      .prepare(
        `SELECT projet_id, type, payload_json, commentaire, created_at, statut
         FROM contributions
         ORDER BY created_at DESC`,
      )
      .all() as {
      projet_id: string;
      type: string;
      payload_json: string;
      commentaire: string | null;
      created_at: string;
      statut: string;
    }[];
    sendJson(res, 200, { contributions: rows });
    return;
  }

  if (urlPath === "/api/verifications" && req.method === "POST") {
    const auth = requireAdmin(req, res);
    if (!auth.ok) return;
    (async () => {
      try {
        const v = JSON.parse(await readBody(req)) as {
          projet_id: string;
          etat: string;
          note?: string;
          lien?: string;
        };
        if (!v.projet_id || !v.etat) throw new Error("champs manquants");
        const db = openDb();
        saveVerification(db, { projet_id: v.projet_id, etat: v.etat, note: v.note, lien: v.lien });
        sendJson(res, 200, { ok: true });
      } catch (e) {
        sendJson(res, 400, { error: (e as Error).message });
      }
    })();
    return;
  }

  if (urlPath === "/api/verifications") {
    const db = openDb();
    const rows = db
      .prepare(
        `SELECT p.id, p.nom, p.structure_porteuse, p.annee_debut, p.statut, p.source,
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
            OR EXISTS (SELECT 1 FROM communes c3 WHERE c3.projet_id = p.id AND c3.anomalie = 1)
         ORDER BY (v.etat IS NULL OR v.etat = 'a_verifier') DESC, p.nom`,
      )
      .all() as {
      id: string;
      nom: string;
      structure_porteuse: string | null;
      annee_debut: number | null;
      statut: string;
      source: string;
      potentiellement_termine: number;
      potentiellement_en_cours: number;
      etat: string | null;
      note: string | null;
      lien: string | null;
      verifie_le: string | null;
      communes: string | null;
      departements: string | null;
      communes_anormales: string | null;
    }[];

    const projets = rows.map((p) => {
      const motifs: string[] = [];
      if (p.potentiellement_termine === 1)
        motifs.push("potentiellement terminé");
      if (p.potentiellement_en_cours === 1)
        motifs.push("potentiellement en cours");
      if (p.source === "wayback") motifs.push("archives 2022");
      if (p.communes_anormales) motifs.push("anomalie");
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

    const compteurs: Record<string, number> = {};
    for (const p of projets) compteurs[p.etat] = (compteurs[p.etat] ?? 0) + 1;
    sendJson(res, 200, { projets, compteurs });
    return;
  }

  let file = path.join(
    PUBLIC,
    urlPath === "/" ? "index.html" : urlPath === "/verify" ? "verify.html" : urlPath === "/admin" ? "admin.html" : urlPath,
  );
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
  if (!ADMIN_PASSWORD) {
    console.warn("⚠ ADMIN_PASSWORD non défini : le panneau admin est inaccessible.");
  }
});