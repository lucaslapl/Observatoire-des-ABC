import { openDb, statistic, ensureDirs } from "./db.js";
import { collectDatagouv } from "./sources/datagouv.js";
import { collectWayback } from "./sources/wayback.js";
import { collectFondsVert } from "./sources/fondsvert.js";
import { enrichGeocoding } from "./geocode.js";
import { computeAnomalies } from "./anomalies.js";
import { buildGeoJson } from "./geojson.js";
import { EXPORT_DIR } from "./config.js";
import { backupDb } from "./backup.js";
import fs from "node:fs";
import path from "node:path";
import { statutLabel, estPotentiellementTermine } from "./status.js";

const [cmd, ...rest] = process.argv.slice(2);

function flag(name: string): string | undefined {
  const i = rest.indexOf(`--${name}`);
  return i >= 0 ? rest[i + 1] : undefined;
}

async function main() {
  ensureDirs();

  switch (cmd) {
    case "collect": {
      const db = openDb();
      // Les vérifications sont liées aux projets par des slugs stables : on les
      // conserve entre deux reconstructions. On désactive donc temporairement
      // les clés étrangères le temps de purger projets/communes/snapshots.
      db.exec("PRAGMA foreign_keys = OFF;");
      db.exec("DELETE FROM snapshots; DELETE FROM communes; DELETE FROM projets;");
      db.exec("PRAGMA foreign_keys = ON;");
      await collectDatagouv(db);
      await collectWayback(db);
      await collectFondsVert(db);
      recomputeStatuses(db);
      await enrichGeocoding(db);
      computeAnomalies(db);
      printStats(db);
      break;
    }

    case "status": {
      const db = openDb();
      recomputeStatuses(db);
      computeAnomalies(db);
      printStats(db);
      break;
    }

    case "geocode": {
      const db = openDb();
      await enrichGeocoding(db);
      computeAnomalies(db);
      break;
    }

    case "export": {
      const db = openDb();
      const fmt = flag("fmt") ?? "csv";
      exportData(db, fmt);
      break;
    }

    case "verify": {
      const db = openDb();
      verifyWorklist(db);
      break;
    }

    case "backup": {
      const r = backupDb();
      console.log(`Sauvegarde : ${r.path}`);
      break;
    }

    default:
      console.log(
        `Usage: npm run collect [--geocode] | npm run status | npm run export:csv | npm run export:geojson | npm run verify | npm run serve`,
      );
  }
}

function verifyWorklist(db: import("node:sqlite").DatabaseSync) {
  const rows = db
    .prepare(
      `SELECT p.id, p.nom, p.structure_porteuse, p.annee_debut, p.statut, p.source,
              p.potentiellement_termine, p.potentiellement_en_cours,
              (SELECT GROUP_CONCAT(c.libelle_geographique, ', ') FROM communes c
               WHERE c.projet_id = p.id) AS communes,
              (SELECT GROUP_CONCAT(c.libelle_departement, ', ') FROM communes c
               WHERE c.projet_id = p.id AND c.libelle_departement IS NOT NULL) AS departements
       FROM projets p
       WHERE p.potentiellement_termine = 1 OR p.potentiellement_en_cours = 1 OR p.source = 'wayback'
       ORDER BY p.statut, p.nom`,
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
    communes: string | null;
    departements: string | null;
  }[];

  const dir = path.join(EXPORT_DIR, "verification-worklist.csv");
  const header = "nom;structure_porteuse;communes;annee_debut;statut_a_verifier;motif;requete_recherche\n";
  const body = rows
    .map((p) => {
      const motifs: string[] = [];
      if (p.potentiellement_termine === 1)
        motifs.push("début " + (p.annee_debut ?? "?") + " → potentiellement terminé");
      if (p.potentiellement_en_cours === 1)
        motifs.push("« va débuter » depuis " + (p.annee_debut ?? "?") + " → potentiellement en cours");
      if (p.source === "wayback") motifs.push("statut figé à 2022 (archives)");
      const place = (p.structure_porteuse ?? p.nom).replace(/ABC\s*/i, "").trim();
      const q =
        `"atlas de la biodiversité communale" "${place}" ${(p.communes ?? "").split(",")[0].trim()}`.trim();
      return [
        p.nom,
        p.structure_porteuse ?? "",
        p.communes ?? "",
        p.annee_debut ?? "",
        statutLabel(p.statut as never),
        motifs.join(" + "),
        q,
      ]
        .join(";")
        .replace(/\n/g, " ");
    })
    .join("\n");
  fs.writeFileSync(dir, header + body + "\n", "utf8");
  console.log(`Worklist de vérification : ${dir} (${rows.length} projets à vérifier)`);
}

function recomputeStatuses(db: import("node:sqlite").DatabaseSync) {
  const YEAR = new Date().getFullYear();
  const ANNEE_MIN = 2000; // année de début plausibles (les ABC existent depuis ~2010)
  const DUREE_ESTIME_TERMINE = 5; // statut inconnu depuis > 5 ans → probablement terminé
  const all = db
    .prepare(
      "SELECT id, statut, source, annee_debut, potentiellement_termine, potentiellement_en_cours, estime_termine FROM projets",
    )
    .all() as {
    id: string;
    statut: string;
    source: string;
    annee_debut: number | null;
    potentiellement_termine: number;
    potentiellement_en_cours: number;
    estime_termine: number;
  }[];

  const snapAvancements: Record<string, string[]> = {};
  const snaps = db
    .prepare("SELECT projet_id, avancement FROM snapshots")
    .all() as { projet_id: string; avancement: string }[];
  for (const s of snaps) {
    (snapAvancements[s.projet_id] ??= []).push(s.avancement);
  }

  const upd = db.prepare(
    "UPDATE projets SET statut = ?, potentiellement_termine = ?, potentiellement_en_cours = ?, estime_termine = ? WHERE id = ?",
  );
  let changed = 0;
  let ptCount = 0;
  let pecCount = 0;
  let estimeCount = 0;
  let stale = 0;
  for (const p of all) {
    const hist = snapAvancements[p.id] ?? [];
    let s = p.statut;
    if (hist.includes("Fini")) s = "termine";
    else if (p.source === "fondsvert-p113-2025") s = "a_venir";

    const anneeOk = p.annee_debut !== null && p.annee_debut >= ANNEE_MIN;

    // ABC « en cours » commencé il y a ≥ 3 ans : vraisemblablement terminé,
    // la donnée n'étant pas mise à jour en temps réel.
    const pt = s === "en_cours" && anneeOk && p.annee_debut! <= YEAR - 3 ? 1 : 0;
    if (pt) ptCount++;

    // ABC « va débuter » dont le début annoncé remonte à ≥ 2 ans : il a
    // très probablement démarré entre-temps (cohérence temporelle).
    const pec =
      s === "a_venir" && p.source !== "wayback" && anneeOk && p.annee_debut! <= YEAR - 2 ? 1 : 0;
    if (pec) pecCount++;

    // Statut officiel inconnu avec un début > 5 ans : le projet est presque
    // certainement terminé (les ABC durent ~3 ans). On le classe Terminé,
    // tout en gardant la trace que c'est une estimation.
    const officiellementInconnu = s === "inconnu" || (s === "termine" && p.estime_termine === 1);
    const estime = officiellementInconnu && anneeOk && p.annee_debut! <= YEAR - DUREE_ESTIME_TERMINE ? 1 : 0;
    if (estime) {
      s = "termine";
      estimeCount++;
    }

    // Projets connus uniquement via l'instantané 2022 (site disparu) :
    // statut potentiellement obsolète.
    if (p.source === "wayback") stale++;

    if (
      s !== p.statut ||
      pt !== p.potentiellement_termine ||
      pec !== p.potentiellement_en_cours ||
      estime !== p.estime_termine
    ) {
      upd.run(s, pt, pec, estime, p.id);
      changed++;
    }
  }
  console.log(
    `statuts recalculés (${changed} ajustés) — ${ptCount} « en cours » potentiellement terminés, ` +
      `${pecCount} « va débuter » potentiellement en cours, ` +
      `${estimeCount} inconnus > ${DUREE_ESTIME_TERMINE} ans reclassés Terminé (estimation), ` +
      `${stale} issus uniquement des archives 2022`,
  );
}

function printStats(db: import("node:sqlite").DatabaseSync) {
  const total = statistic(db, "SELECT COUNT(*) AS n FROM projets");
  const rows = db
    .prepare("SELECT statut, COUNT(*) AS n FROM projets GROUP BY statut ORDER BY n DESC")
    .all() as { statut: string; n: number }[];
  const communes = statistic(db, "SELECT COUNT(*) AS n FROM communes");
  const geocoded = statistic(
    db,
    "SELECT COUNT(*) AS n FROM communes WHERE lon IS NOT NULL AND lat IS NOT NULL AND (lon!=0 OR lat!=0)",
  );
  console.log(`\n=== ${total} projets / ${communes} lignes communes / ${geocoded} géo ===`);
  for (const r of rows) {
    console.log(`  ${statutLabel(r.statut as never).padEnd(16)} ${r.n}`);
  }
}

function exportData(db: import("node:sqlite").DatabaseSync, fmt: string) {
  const projects = db
    .prepare(
      `SELECT p.*,
         (SELECT COUNT(*) FROM communes c WHERE c.projet_id = p.id) AS nb_communes,
         (SELECT libelle_departement FROM communes c WHERE c.projet_id = p.id AND c.libelle_departement IS NOT NULL LIMIT 1) AS departement,
         (SELECT region FROM communes c WHERE c.projet_id = p.id AND c.region IS NOT NULL LIMIT 1) AS region
       FROM projets p`,
    )
    .all() as (Record<string, unknown> & {
    id: string;
    nom: string;
    statut: string;
    structure_porteuse: string | null;
    annee_debut: number | null;
    nb_communes: number;
  })[];

  if (fmt === "csv") {
    const dir = path.join(EXPORT_DIR, "abc-projets.csv");
    const header =
      "id;nom;structure_porteuse;type_de_structure_porteuse;annee_debut;statut;categorie;note;nb_communes;departement;region;source;ami_ofb;url_page\n";
    const body = projects
      .map((p) => {
        const notes: string[] = [];
        if (p.potentiellement_termine === 1) {
          notes.push(
            "Potentiellement terminé (début " + (p.annee_debut ?? "?") + ", durée ABC ~3 ans)",
          );
        }
        if (p.potentiellement_en_cours === 1) {
          notes.push(
            "Potentiellement en cours (début annoncé " +
              (p.annee_debut ?? "?") +
              ", encore « va débuter »)",
          );
        }
        if (p.source === "wayback") {
          notes.push("Statut issu des archives 2022, à vérifier");
        }
        if (p.estime_termine === 1) {
          notes.push(
            "Terminé (estimation) : statut officiel inconnu, projet débuté en " +
              (p.annee_debut ?? "?") +
              " (> 5 ans)",
          );
        }
        return [
          p.id,
          p.nom,
          p.structure_porteuse ?? "",
          p.type_de_structure_porteuse ?? "",
          p.annee_debut ?? "",
          p.statut,
          statutLabel(p.statut as never),
          notes.join(" ; "),
          p.nb_communes,
          p.departement ?? "",
          p.region ?? "",
          p.source ?? "",
          p.ami_ofb ? "Oui" : "Non",
          p.url_page ?? "",
        ]
          .join(";")
          .replace(/\n/g, " ");
      })
      .join("\n");
    fs.writeFileSync(dir, header + body + "\n", "utf8");
    console.log(`CSV écrit : ${dir} (${projects.length} projets)`);
    return;
  }

  if (fmt === "geojson") {
    const out = buildGeoJson(db);
    const dir = path.join(EXPORT_DIR, "abc.geojson");
    fs.writeFileSync(dir, JSON.stringify(out), "utf8");
    console.log(`GeoJSON écrit : ${dir} (${out.features.length} points)`);
    return;
  }

  throw new Error(`Format inconnu : ${fmt}`);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});