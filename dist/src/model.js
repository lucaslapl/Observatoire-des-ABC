import { z } from "zod";
// --- Statut brut tel que publié par OFB / data.gouv ---
export const AVANCEMENT = [
    "En cours de réalisation",
    "Fini",
    "En phase de lancement",
    "Non commencé",
    "Inconnu",
];
// --- Statut agrégé (les 3 catégories du besoin) ---
export const STATUT_AGREGEG = ["a_venir", "en_cours", "termine", "inconnu"];
const communeSchema = z.object({
    code_geographique: z.string(),
    libelle_geographique: z.string(),
    epci: z.string().optional(),
    libelle_epci: z.string().optional(),
    departement: z.string().optional(),
    libelle_departement: z.string().optional(),
    region: z.string().optional(),
    ept: z.string().optional(),
    libelle_petr: z.string().optional(),
    code_pnr: z.string().optional(),
    libelle_pnr: z.string().optional(),
});
export const projetSchema = z.object({
    nom: z.string(),
    structure_porteuse: z.string().optional(),
    type_de_structure_porteuse: z.string().optional(),
    annee_debut: z.coerce.number().optional().nullable(),
    avancement: z.string().optional(),
    ami_ofb: z.coerce.boolean().optional().nullable(),
    communes: z.array(communeSchema).default([]),
    source: z.string(),
    identifiant_technique: z.string().optional(),
    url_page: z.string().optional(),
});
