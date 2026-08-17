import { openDb, statistic, ensureDirs } from "./db.js";
import { buildGeoJson } from "./geojson.js";
import { EXPORT_DIR } from "./config.js";
import { backupDb } from "./backup.js";
import { collectAll, printCollectSummary, recomputeStatuses } from "./collect.js";
import { computeAnomalies } from "./anomalies.js";
import { enrichGeocoding } from "./geocode.js";
import fs from "node:fs";
import path from "node:path";
import { statutLabel } from "./status.js";
import { fileURLToPath } from "node:url";
const [cmd, ...rest] = process.argv.slice(2);
function flag(name) {
    const i = rest.indexOf(`--${name}`);
    return i >= 0 ? rest[i + 1] : undefined;
}
async function main() {
    ensureDirs();
    switch (cmd) {
        case "collect": {
            const s = await collectAll();
            printCollectSummary(s);
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
            console.log(`Usage: npm run collect [--geocode] | npm run status | npm run export:csv | npm run export:geojson | npm run verify | npm run serve`);
    }
}
function verifyWorklist(db) {
    const rows = db
        .prepare(`SELECT p.id, p.nom, p.structure_porteuse, p.annee_debut, p.statut, p.source,
              p.potentiellement_termine, p.potentiellement_en_cours,
              (SELECT GROUP_CONCAT(c.libelle_geographique, ', ') FROM communes c
               WHERE c.projet_id = p.id) AS communes,
              (SELECT GROUP_CONCAT(c.libelle_departement, ', ') FROM communes c
               WHERE c.projet_id = p.id AND c.libelle_departement IS NOT NULL) AS departements
       FROM projets p
       WHERE p.potentiellement_termine = 1 OR p.potentiellement_en_cours = 1 OR p.source = 'wayback'
          OR p.annee_debut IS NULL
          ORDER BY p.statut, p.nom`)
        .all();
    const dir = path.join(EXPORT_DIR, "verification-worklist.csv");
    const header = "nom;structure_porteuse;communes;annee_debut;statut_a_verifier;motif;requete_recherche\n";
    const body = rows
        .map((p) => {
        const motifs = [];
        if (p.potentiellement_termine === 1)
            motifs.push("début " + (p.annee_debut ?? "?") + " → potentiellement terminé");
        if (p.potentiellement_en_cours === 1)
            motifs.push("« va débuter » depuis " + (p.annee_debut ?? "?") + " → potentiellement en cours");
        if (p.source === "wayback")
            motifs.push("statut figé à 2022 (archives)");
        if (p.annee_debut === null || p.annee_debut === undefined)
            motifs.push("date début inconnue");
        const place = (p.structure_porteuse ?? p.nom).replace(/ABC\s*/i, "").trim();
        const q = `"atlas de la biodiversité communale" "${place}" ${(p.communes ?? "").split(",")[0].trim()}`.trim();
        return [
            p.nom,
            p.structure_porteuse ?? "",
            p.communes ?? "",
            p.annee_debut ?? "",
            statutLabel(p.statut),
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
function printStats(db) {
    const total = statistic(db, "SELECT COUNT(*) AS n FROM projets");
    const rows = db
        .prepare("SELECT statut, COUNT(*) AS n FROM projets GROUP BY statut ORDER BY n DESC")
        .all();
    const communes = statistic(db, "SELECT COUNT(*) AS n FROM communes");
    const geocoded = statistic(db, "SELECT COUNT(*) AS n FROM communes WHERE lon IS NOT NULL AND lat IS NOT NULL AND (lon!=0 OR lat!=0)");
    console.log(`\n=== ${total} projets / ${communes} lignes communes / ${geocoded} géo ===`);
    for (const r of rows) {
        console.log(`  ${statutLabel(r.statut).padEnd(16)} ${r.n}`);
    }
}
function exportData(db, fmt) {
    const projects = db
        .prepare(`SELECT p.*,
         (SELECT COUNT(*) FROM communes c WHERE c.projet_id = p.id) AS nb_communes,
         (SELECT libelle_departement FROM communes c WHERE c.projet_id = p.id AND c.libelle_departement IS NOT NULL LIMIT 1) AS departement,
         (SELECT region FROM communes c WHERE c.projet_id = p.id AND c.region IS NOT NULL LIMIT 1) AS region
       FROM projets p`)
        .all();
    if (fmt === "csv") {
        const dir = path.join(EXPORT_DIR, "abc-projets.csv");
        const header = "id;nom;structure_porteuse;type_de_structure_porteuse;annee_debut;statut;categorie;note;nb_communes;departement;region;source;ami_ofb;url_page\n";
        const body = projects
            .map((p) => {
            const notes = [];
            if (p.potentiellement_termine === 1) {
                notes.push("Potentiellement terminé (début " + (p.annee_debut ?? "?") + ", durée ABC ~3 ans)");
            }
            if (p.potentiellement_en_cours === 1) {
                notes.push("Potentiellement en cours (début annoncé " +
                    (p.annee_debut ?? "?") +
                    ", encore « va débuter »)");
            }
            if (p.source === "wayback") {
                notes.push("Statut issu des archives 2022, à vérifier");
            }
            if (p.estime_termine === 1) {
                notes.push("Terminé (estimation) : statut officiel inconnu, projet débuté en " +
                    (p.annee_debut ?? "?") +
                    " (> 5 ans)");
            }
            return [
                p.id,
                p.nom,
                p.structure_porteuse ?? "",
                p.type_de_structure_porteuse ?? "",
                p.annee_debut ?? "",
                p.statut,
                statutLabel(p.statut),
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
const isMain = process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1]);
if (isMain) {
    main().catch((e) => {
        console.error(e);
        process.exit(1);
    });
}
