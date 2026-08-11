import { fileURLToPath } from "node:url";
import path from "node:path";

const __dirname = path.dirname(fileURLToPath(import.meta.url));

export const ROOT = path.resolve(__dirname, "..");
export const DATA_DIR = path.join(ROOT, "data");
export const CACHE_DIR = path.join(DATA_DIR, "cache");
export const DB_PATH = path.join(DATA_DIR, "abc.db");
export const EXPORT_DIR = path.join(DATA_DIR, "exports");

export const SOURCES = {
  // Registre principal (vivant) — CSV OFB sur data.gouv
  datagouv:
    "https://static.data.gouv.fr/resources/atlas-de-la-biodiversite-communale-abc/20260709-081207/atlas-biodiversite.csv",
  // Historique (point dans le temps) — export CSV archivé sur Wayback Machine
  wayback:
    "https://web.archive.org/web/20221206082701/https://abc.naturefrance.fr/abcexport?page&_format=csv",
  // Lauréats Fonds vert — lot "Biodiversité" (P113) qui finance les ABC (data.gouv)
  fondsvert2024:
    "https://static.data.gouv.fr/resources/fonds-vert-liste-des-projets-subventionnes/20250731-100849/fonds-vert-p113-2024-export.csv",
  fondsvert2025:
    "https://static.data.gouv.fr/resources/fonds-vert-liste-des-projets-subventionnes/20260728-085038/fonds-vert-p113-2025-export.csv.csv",
};

export const USER_AGENT =
  "observatoire-des-abc/0.1 (recherche ouverte sur les Atlas de la Biodiversité Communale)";

export const REQUEST_DELAY_MS = 500;

// Fraîcheur des sources (date de publication des fichiers utilisés)
export const SOURCE_DATES: Record<string, string> = {
  "data.gouv": "2026-07-09", // registre OFB (mise à jour data.gouv)
  wayback: "2022-12-06", // instantané archivé du site abc.naturefrance.fr
  "fondsvert-p113-2024": "2025-07-31",
  "fondsvert-p113-2025": "2026-06-22",
};