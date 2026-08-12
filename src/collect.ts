import { openDb, statistic, ensureDirs } from "./db.js";
import { collectDatagouv } from "./sources/datagouv.js";
import { collectWayback } from "./sources/wayback.js";
import { collectFondsVert } from "./sources/fondsvert.js";
import { enrichGeocoding } from "./geocode.js";
import { computeAnomalies } from "./anomalies.js";
import { statutLabel } from "./status.js";

export function recomputeStatuses(db: import("node:sqlite").DatabaseSync) {
  const YEAR = new Date().getFullYear();
  const ANNEE_MIN = 2000;
  const DUREE_ESTIME_TERMINE = 5;
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

    const pt = s === "en_cours" && anneeOk && p.annee_debut! <= YEAR - 3 ? 1 : 0;
    if (pt) ptCount++;

    const pec =
      s === "a_venir" && p.source !== "wayback" && anneeOk && p.annee_debut! <= YEAR - 2 ? 1 : 0;
    if (pec) pecCount++;

    const officiellementInconnu = s === "inconnu" || (s === "termine" && p.estime_termine === 1);
    const estime = officiellementInconnu && anneeOk && p.annee_debut! <= YEAR - DUREE_ESTIME_TERMINE ? 1 : 0;
    if (estime) {
      s = "termine";
      estimeCount++;
    }

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
  return { changed, ptCount, pecCount, estimeCount, stale };
}

export interface CollectSummary {
  total: number;
  communes: number;
  geocoded: number;
  statuses: { statut: string; n: number }[];
}

export async function collectAll(): Promise<CollectSummary> {
  ensureDirs();
  const db = openDb();
  db.exec("PRAGMA foreign_keys = OFF;");
  db.exec("DELETE FROM snapshots; DELETE FROM communes; DELETE FROM projets;");
  db.exec("PRAGMA foreign_keys = ON;");
  await collectDatagouv(db);
  await collectWayback(db);
  await collectFondsVert(db);
  recomputeStatuses(db);
  await enrichGeocoding(db);
  computeAnomalies(db);
  const total = statistic(db, "SELECT COUNT(*) AS n FROM projets");
  const communes = statistic(db, "SELECT COUNT(*) AS n FROM communes");
  const geocoded = statistic(
    db,
    "SELECT COUNT(*) AS n FROM communes WHERE lon IS NOT NULL AND lat IS NOT NULL AND (lon!=0 OR lat!=0)",
  );
  const statuses = db
    .prepare("SELECT statut, COUNT(*) AS n FROM projets GROUP BY statut ORDER BY n DESC")
    .all() as { statut: string; n: number }[];
  return { total, communes, geocoded, statuses };
}

export function printCollectSummary(s: CollectSummary) {
  console.log(`\n=== ${s.total} projets / ${s.communes} lignes communes / ${s.geocoded} géo ===`);
  for (const r of s.statuses) {
    console.log(`  ${statutLabel(r.statut as never).padEnd(16)} ${r.n}`);
  }
}
