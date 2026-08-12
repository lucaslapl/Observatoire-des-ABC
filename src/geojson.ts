import type { DatabaseSync } from "node:sqlite";
import { statutLabel, statutDepuisVerification } from "./status.js";

export interface PointFeature {
  type: "Feature";
  geometry: { type: "Point"; coordinates: [number, number] };
  properties: Record<string, unknown>;
}

export function buildGeoJson(db: DatabaseSync) {
  const communes = db
    .prepare(
      `SELECT c.*, p.nom, p.structure_porteuse, p.annee_debut, p.annee_fin, p.statut, p.source,
              p.potentiellement_termine, p.potentiellement_en_cours, p.estime_termine,
              v.etat AS verif_etat, v.note AS verif_note, v.lien AS verif_lien
       FROM communes c
       JOIN projets p ON p.id = c.projet_id
       LEFT JOIN verifications v ON v.projet_id = p.id
       WHERE c.lon IS NOT NULL AND c.lat IS NOT NULL AND (c.lon != 0 OR c.lat != 0)`,
    )
    .all() as (Record<string, unknown> & {
    lon: number;
    lat: number;
    nom: string;
    statut: string;
    code_geographique: string;
    libelle_geographique: string;
    projet_id: string;
    libelle_departement: string;
    region: string;
    potentiellement_termine: number;
    potentiellement_en_cours: number;
    estime_termine: number;
    anomalie: number;
    distance_centre_km: number | null;
    verif_etat: string | null;
    verif_note: string | null;
    verif_lien: string | null;
  })[];

  const features: PointFeature[] = communes.map((c) => {
    const statutAffichage = statutDepuisVerification(c.verif_etat) ?? c.statut;
    return {
      type: "Feature",
      geometry: { type: "Point", coordinates: [c.lon, c.lat] },
      properties: {
        projet_id: c.projet_id,
        nom: c.nom,
        structure_porteuse: c.structure_porteuse,
        annee_debut: c.annee_debut,
        annee_fin: c.annee_fin,
        statut: c.statut,
        statut_affichage: statutAffichage,
        categorie: statutLabel(statutAffichage as never),
        potentiellement_termine: c.potentiellement_termine === 1,
        potentiellement_en_cours: c.potentiellement_en_cours === 1,
        estime_termine: c.estime_termine === 1,
        donnees_2022: c.source === "wayback",
        verifie: c.verif_etat !== null && c.verif_etat !== "a_verifier",
        verif_etat: c.verif_etat,
        verif_note: c.verif_note,
        verif_lien: c.verif_lien,
        anomalie: c.anomalie === 1,
        distance_km: c.distance_centre_km,
        commune: c.libelle_geographique,
        code_commune: c.code_geographique,
        departement: c.libelle_departement,
        region: c.region,
        source: c.source,
      },
    };
  });

  return { type: "FeatureCollection", features };
}
