import fs from "node:fs";
import path from "node:path";
import { CACHE_DIR, USER_AGENT } from "./config.js";
const GEO_CACHE = path.join(CACHE_DIR, "geo.json");
export async function enrichGeocoding(db) {
    const rows = db
        .prepare("SELECT DISTINCT code_geographique, libelle_geographique FROM communes WHERE code_geographique IS NOT NULL AND code_geographique != ''")
        .all();
    const cache = fs.existsSync(GEO_CACHE)
        ? JSON.parse(fs.readFileSync(GEO_CACHE, "utf8"))
        : {};
    const toFetch = rows.filter((r) => !cache[r.code_geographique]);
    console.log(`géocodage : ${rows.length} communes distinctes, ${toFetch.length} à récupérer`);
    const CONCURRENCY = 12;
    let cursor = 0;
    const workers = Array.from({ length: CONCURRENCY }, async () => {
        while (cursor < toFetch.length) {
            const r = toFetch[cursor++];
            try {
                const res = await fetch(`https://geo.api.gouv.fr/communes/${r.code_geographique}?fields=centre,name`, { headers: { "user-agent": USER_AGENT, accept: "application/json" } });
                if (res.ok) {
                    const j = (await res.json());
                    if (j.centre?.coordinates) {
                        cache[r.code_geographique] = {
                            lon: j.centre.coordinates[0],
                            lat: j.centre.coordinates[1],
                            name: j.name,
                        };
                    }
                }
                else {
                    // Inconnu ou outre-mer non couvert : on laisse vide.
                    cache[r.code_geographique] = { lon: 0, lat: 0 };
                }
            }
            catch {
                cache[r.code_geographique] = { lon: 0, lat: 0 };
            }
        }
    });
    await Promise.all(workers);
    fs.writeFileSync(GEO_CACHE, JSON.stringify(cache, null, 0));
    const upd = db.prepare("UPDATE communes SET lon = ?, lat = ? WHERE code_geographique = ?");
    let updated = 0;
    for (const r of rows) {
        const g = cache[r.code_geographique];
        if (g && (g.lon || g.lat)) {
            upd.run(g.lon, g.lat, r.code_geographique);
            updated++;
        }
    }
    console.log(`géocodage : ${updated} communes avec coordonnées`);
}
