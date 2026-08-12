import fs from "node:fs";
import path from "node:path";
import { openDb } from "./db.js";
import { DATA_DIR, DB_PATH } from "./config.js";

export function backupDb(keep = 14): { path: string; kept: number } {
  const backups = path.join(DATA_DIR, "backups");
  fs.mkdirSync(backups, { recursive: true });
  const stamp = new Date().toISOString().slice(0, 19).replace(/[-:]/g, "").replace("T", "-");
  const dest = path.join(backups, `abc-${stamp}.db`);

  // Checkpoint WAL pour copier un fichier cohérent.
  const db = openDb();
  db.exec("PRAGMA wal_checkpoint(TRUNCATE);");
  db.close();
  fs.copyFileSync(DB_PATH, dest);

  // Rotation : on garde les `keep` plus récents.
  const existing = fs
    .readdirSync(backups)
    .filter((f) => f.startsWith("abc-") && f.endsWith(".db"))
    .sort();
  while (existing.length > keep) {
    const old = existing.shift()!;
    fs.unlinkSync(path.join(backups, old));
  }
  return { path: dest, kept: existing.length };
}