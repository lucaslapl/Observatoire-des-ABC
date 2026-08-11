import type { DatabaseSync } from "node:sqlite";
import { SOURCES } from "../config.js";
import { downloadToCache, readCsv, projetId } from "../util.js";
import { recordSnapshot, upsertProjet } from "../db.js";
import { avancementToStatut } from "../status.js";
import { corrigerCommune } from "../corrections.js";

const SNAPSHOT_DATE = "2022-12-06"; // date de l'instantané Wayback

// Export archivé du site abc.naturefrance.fr (point dans le temps, 1 ligne = commune).
export async function collectWayback(db: DatabaseSync) {
  const file = await downloadToCache(SOURCES.wayback, "historique ABC (Wayback 2022)");
  const rows = readCsv(file, ",");

  const grouped = new Map<string, typeof rows>();
  for (const r of rows) {
    const key = projetId(r["nom"], r["structure_porteuse"], toInt(r["annee_debut"]));
    if (!grouped.has(key)) grouped.set(key, []);
    grouped.get(key)!.push(r);
  }

  let projects = 0;
  const exists = db.prepare("SELECT 1 FROM projets WHERE id = ?");
  for (const [key, rs] of grouped) {
    const first = rs[0];
    // Le registre data.gouv (plus récent) fait foi : on n'écrase jamais ses statuts.
    // L'instantané 2022 sert uniquement d'historique (snapshot) et d'apport
    // pour les projets disparus du registre.
    if (!exists.get(key)) {
      const ami = parseAmi(first["ami_ofb"]);
      upsertProjet(db, {
        id: key,
        nom: first["nom"],
        structure_porteuse: first["structure_porteuse"] || undefined,
        type_de_structure_porteuse: first["type_de_structure_porteuse"] || undefined,
        annee_debut: toInt(first["annee_debut"]),
        avancement_raw: first["avancement"],
        statut: avancementToStatut(first["avancement"]),
        ami_ofb: ami,
        source: "wayback",
        url_page: first["ressource_documentaire"] || undefined,
        communes: rs
          .map((r) =>
            corrigerCommune({
              code_geographique: r["code_commune"],
              libelle_geographique: r["commune"],
            }),
          )
          .filter((c) => c.code_geographique),
      });
      projects++;
    }
    if (first["avancement"]) {
      recordSnapshot(db, SNAPSHOT_DATE, key, first["avancement"], "wayback");
    }
  }
  console.log(
    `wayback : ${projects} projets uniquement issus des archives (instantané ${SNAPSHOT_DATE}) + snapshots historiques`,
  );
}

function toInt(v?: string): number | null {
  if (!v) return null;
  const n = Number.parseInt(v, 10);
  return Number.isNaN(n) ? null : n;
}

function parseAmi(v?: string): boolean | null {
  if (!v) return null;
  return v.trim().toLowerCase() === "vrai" || v.trim() === "1";
}