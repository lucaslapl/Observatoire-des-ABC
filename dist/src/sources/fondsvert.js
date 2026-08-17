import { SOURCES } from "../config.js";
import { downloadToCache, readCsv, projetId } from "../util.js";
import { upsertProjet } from "../db.js";
import { corrigerCommune } from "../corrections.js";
// Projets ABC financés via le lot "Biodiversité" (P113) du Fonds vert → projets récents (à venir).
export async function collectFondsVert(db) {
    const specs = [
        { url: SOURCES.fondsvert2024, year: 2024, rich: false },
        { url: SOURCES.fondsvert2025, year: 2025, rich: true },
    ];
    let total = 0;
    for (const s of specs) {
        const file = await downloadToCache(s.url, `Fonds vert biodiversité ${s.year}`);
        const rows = readCsv(file, ",");
        const hits = rows.filter((r) => isAbc(r));
        for (const r of hits) {
            const nom = r["nom_du_projet"]?.trim();
            if (!nom)
                continue;
            const benef = (r["raison_sociale_beneficiaire"] || r["nom_fournisseur"] || "").trim();
            const id = projetId(nom, benef || undefined, s.year);
            const statut = "a_venir";
            upsertProjet(db, {
                id,
                nom,
                structure_porteuse: benef || undefined,
                type_de_structure_porteuse: undefined,
                annee_debut: null,
                avancement_raw: `Fonds vert ${s.year}`,
                statut,
                ami_ofb: true,
                source: `fondsvert-p113-${s.year}`,
                url_page: undefined,
                communes: s.rich
                    ? r["code_commune"]
                        ? [corrigerCommune({
                                code_geographique: r["code_commune"],
                                libelle_geographique: r["nom_commune"],
                                libelle_departement: r["nom_departement"] || undefined,
                                region: r["nom_region"] || undefined,
                            })]
                        : []
                    : [],
            });
            total++;
        }
        console.log(`fonds vert biodiversité ${s.year} : ${hits.length} projets ABC`);
    }
    return total;
}
function isAbc(r) {
    const hay = `${r["nom_du_projet"]} ${r["resume_du_projet"]} ${r["demarche"]}`;
    if (!hay)
        return false;
    if (/abc\s*terre/i.test(hay))
        return false; // "ABC Terre" : carbone des sols, à exclure
    return /atlas de la biodiversit[ée] communale/i.test(hay) || /\bABC\b/i.test(hay);
}
