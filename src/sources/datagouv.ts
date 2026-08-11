import type { DatabaseSync } from "node:sqlite";
import { SOURCES } from "../config.js";
import { downloadToCache, readCsv, projetId } from "../util.js";
import { upsertProjet, recordSnapshot } from "../db.js";
import { avancementToStatut } from "../status.js";
import { regionLabel } from "../regions.js";
import { corrigerCommune } from "../corrections.js";

const SNAPSHOT_DATE = new Date().toISOString().slice(0, 10);

// Registre principal OFB : 1 ligne = 1 couple (projet x commune).
export async function collectDatagouv(db: DatabaseSync) {
  const file = await downloadToCache(SOURCES.datagouv, "registre ABC (data.gouv)");
  const rows = readCsv(file, ";");

  const grouped = new Map<string, typeof rows>();
  for (const r of rows) {
    const key = projetId(r["nom"], r["structure_porteuse"], toInt(r["annee_debut"]));
    if (!grouped.has(key)) grouped.set(key, []);
    grouped.get(key)!.push(r);
  }

  let projects = 0;
  for (const [key, rs] of grouped) {
    const first = rs[0];
    const annee = toInt(first["annee_debut"]);
    upsertProjet(db, {
      id: key,
      nom: first["nom"],
      structure_porteuse: first["structure_porteuse"] || undefined,
      type_de_structure_porteuse: first["type_de_structure_porteuse"] || undefined,
      annee_debut: annee,
      avancement_raw: first["avancement"],
      statut: avancementToStatut(first["avancement"]),
      source: "data.gouv",
      communes: rs
        .map((r) => {
          const c = corrigerCommune({
            code_geographique: r["code_geographique"],
            libelle_geographique: r["libelle_geographique"],
            epci: r["epci"] || undefined,
            libelle_epci: r["libelle_epci"] || undefined,
            departement: r["departement"] || undefined,
            libelle_departement: r["libelle_departement"] || undefined,
            region: r["region"],
            libelle_pnr: r["libelle_pnr"] || undefined,
          });
          return { ...c, region: regionLabel(c.region) };
        })
        .filter((c) => c.code_geographique),
    });
    if (first["avancement"]) {
      recordSnapshot(db, SNAPSHOT_DATE, key, first["avancement"], "data.gouv");
    }
    projects++;
  }
  console.log(`data.gouv : ${projects} projets / ${rows.length} communes`);
}

function toInt(v?: string): number | null {
  if (!v) return null;
  const n = Number.parseInt(v, 10);
  return Number.isNaN(n) ? null : n;
}