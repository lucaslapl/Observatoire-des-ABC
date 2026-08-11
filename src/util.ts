import fs from "node:fs";
import path from "node:path";
import { parse } from "csv-parse/sync";
import { CACHE_DIR, USER_AGENT, REQUEST_DELAY_MS } from "./config.js";

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

export async function downloadToCache(
  url: string,
  label: string,
): Promise<string> {
  const base = path.basename(new URL(url).pathname) || label;
  const dest = path.join(CACHE_DIR, base);
  if (fs.existsSync(dest) && fs.statSync(dest).size > 0) {
    console.log(`[cache] ${label} → ${dest}`);
    return dest;
  }
  console.log(`[download] ${label} …`);
  const res = await fetch(url, { headers: { "user-agent": USER_AGENT } });
  if (!res.ok) throw new Error(`HTTP ${res.status} pour ${url}`);
  const buf = Buffer.from(await res.arrayBuffer());
  fs.writeFileSync(`${dest}.part`, buf);
  fs.renameSync(`${dest}.part`, dest);
  await sleep(REQUEST_DELAY_MS);
  return dest;
}

export function readCsv(file: string, delimiter = ";"): Record<string, string>[] {
  const text = fs.readFileSync(file, "utf8");
  return parse(text, {
    delimiter,
    columns: true,
    skip_empty_lines: true,
    bom: true,
    // Source parfois mal quotée (ex. 'PETR '"Pays de la Jeune Loire'"') — on tolère.
    relax_quotes: true,
    relax_column_count: true,
    skip_records_with_error: true,
  }) as Record<string, string>[];
}

export function slug(key: string): string {
  return key
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/(^-|-$)/g, "");
}

// Clé stable d'identification d'un projet (le registre ABC n'expose pas d'UUID).
export function projetId(nom: string, structure?: string, annee?: number | null): string {
  return slug(`${nom}|${structure ?? ""}|${annee ?? ""}`);
}