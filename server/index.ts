import http from "node:http";
import fs from "node:fs";
import path from "node:path";
import { openDb, saveVerification } from "../src/db.js";
import { buildGeoJson } from "../src/geojson.js";
import { EXPORT_DIR, ROOT, SOURCE_DATES } from "../src/config.js";
import { statutLabel } from "../src/status.js";

const PORT = Number(process.env.PORT || 4000);
const PUBLIC = path.join(ROOT, "server", "public");

function toGeoJson() {
  return buildGeoJson(openDb());
}

const mime: Record<string, string> = {
  ".html": "text/html; charset=utf-8",
  ".js": "application/javascript",
  ".css": "text/css",
  ".png": "image/png",
  ".json": "application/json",
};

const server = http.createServer((req, res) => {
  const url = (req.url ?? "/").split("?")[0];
  // Toujours servir des données fraîches (évite le cache navigateur sur les
  // corrections/anomalies/vérifications mises à jour sans redémarrage).
  res.setHeader("cache-control", "no-store");

  if (url === "/api/abc.geojson") {
    const data = toGeoJson();
    res.writeHead(200, { "content-type": "application/json" });
    res.end(JSON.stringify(data));
    return;
  }

  if (url === "/api/meta") {
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
      }),
    );
    return;
  }

  if (url === "/api/stats") {
    const db = openDb();
    const stats = db
      .prepare("SELECT statut, COUNT(*) AS n FROM projets GROUP BY statut")
      .all() as { statut: string; n: number }[];
    res.writeHead(200, { "content-type": "application/json" });
    res.end(JSON.stringify(stats));
    return;
  }

  if (url === "/api/verifications" && req.method === "POST") {
    (async () => {
      let body = "";
      for await (const chunk of req) body += chunk;
      try {
        const v = JSON.parse(body) as { projet_id: string; etat: string; note?: string; lien?: string };
        if (!v.projet_id || !v.etat) throw new Error("champs manquants");
        const db = openDb();
        saveVerification(db, { projet_id: v.projet_id, etat: v.etat, note: v.note, lien: v.lien });
        res.writeHead(200, { "content-type": "application/json" });
        res.end(JSON.stringify({ ok: true }));
      } catch (e) {
        res.writeHead(400, { "content-type": "application/json" });
        res.end(JSON.stringify({ error: (e as Error).message }));
      }
    })();
    return;
  }

  if (url === "/api/verifications") {
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
    res.writeHead(200, { "content-type": "application/json" });
    res.end(JSON.stringify({ projets, compteurs }));
    return;
  }

  let file = path.join(PUBLIC, url === "/" ? "index.html" : url === "/verify" ? "verify.html" : url);
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
});