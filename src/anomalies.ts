import type { DatabaseSync } from "node:sqlite";

// Une commune membre d'un ABC située bien plus loin que ses voisines du même
// projet est très probablement une erreur (commune homonyme mal rattachée,
// mauvais code INSEE…). On l'écarte des connexions visuelles et on la signale.
export const DISTANCE_ANOMALIE_KM = 100;

function haversineKm(lat1: number, lon1: number, lat2: number, lon2: number): number {
  const R = 6371;
  const t = (x: number) => (x * Math.PI) / 180;
  const a =
    Math.sin(t(lat2 - lat1) / 2) ** 2 +
    Math.cos(t(lat1)) * Math.cos(t(lat2)) * Math.sin(t(lon2 - lon1) / 2) ** 2;
  return 2 * R * Math.asin(Math.sqrt(a));
}

function median(vals: number[]): number {
  const s = [...vals].sort((a, b) => a - b);
  const m = Math.floor(s.length / 2);
  return s.length % 2 ? s[m] : (s[m - 1] + s[m]) / 2;
}

export function computeAnomalies(db: DatabaseSync) {
  const rows = db
    .prepare(
      "SELECT projet_id, code_geographique, lon, lat FROM communes WHERE lon IS NOT NULL AND lat IS NOT NULL",
    )
    .all() as { projet_id: string; code_geographique: string; lon: number; lat: number }[];

  const groupes = new Map<string, typeof rows>();
  for (const r of rows) {
    if (!groupes.has(r.projet_id)) groupes.set(r.projet_id, []);
    groupes.get(r.projet_id)!.push(r);
  }

  const upd = db.prepare(
    "UPDATE communes SET anomalie = ?, distance_centre_km = ? WHERE projet_id = ? AND code_geographique = ?",
  );
  let anomalies = 0;

  for (const [pid, cs] of groupes) {
    if (cs.length < 2) continue;

    // Centroïde médian (robuste aux outliers) : les coordonnées erronées ne
    // doivent pas tirer le centre vers elles et faire passer le groupe pour faux.
    let clat = median(cs.map((c) => c.lat));
    let clon = median(cs.map((c) => c.lon));

    let flagged = new Set<string>();
    for (let pass = 0; pass < 2; pass++) {
      const outliers = cs.filter(
        (c) => !flagged.has(c.code_geographique) && haversineKm(c.lat, c.lon, clat, clon) > DISTANCE_ANOMALIE_KM,
      );
      if (outliers.length === 0) break;
      for (const o of outliers) flagged.add(o.code_geographique);
      const ok = cs.filter((c) => !flagged.has(c.code_geographique));
      if (ok.length > 0) {
        clat = ok.reduce((s, c) => s + c.lat, 0) / ok.length;
        clon = ok.reduce((s, c) => s + c.lon, 0) / ok.length;
      }
    }

    const ok = cs.filter((c) => !flagged.has(c.code_geographique));
    if (ok.length > 0) {
      clat = ok.reduce((s, c) => s + c.lat, 0) / ok.length;
      clon = ok.reduce((s, c) => s + c.lon, 0) / ok.length;
    }

    for (const c of cs) {
      const d = haversineKm(c.lat, c.lon, clat, clon);
      const isAnomalie = flagged.has(c.code_geographique) ? 1 : 0;
      upd.run(isAnomalie, Math.round(d * 10) / 10, pid, c.code_geographique);
      if (isAnomalie) anomalies++;
    }
  }
  console.log(`anomalies détectées : ${anomalies} commune(s) à > ${DISTANCE_ANOMALIE_KM} km de leur groupe`);
}
