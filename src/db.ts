import { DatabaseSync } from "node:sqlite";
import fs from "node:fs";
import { DB_PATH, DATA_DIR, CACHE_DIR, EXPORT_DIR } from "./config.js";

export function ensureDirs() {
  for (const dir of [DATA_DIR, CACHE_DIR, EXPORT_DIR]) {
    fs.mkdirSync(dir, { recursive: true });
  }
}

export function openDb(): DatabaseSync {
  ensureDirs();
  const db = new DatabaseSync(DB_PATH);
  db.exec("PRAGMA journal_mode = WAL;");
  migrate(db);
  return db;
}

function migrate(db: DatabaseSync) {
  db.exec(`
    CREATE TABLE IF NOT EXISTS projets (
      id TEXT PRIMARY KEY,
      nom TEXT NOT NULL,
      structure_porteuse TEXT,
      type_de_structure_porteuse TEXT,
      annee_debut INTEGER,
      avancement_raw TEXT,
      statut TEXT NOT NULL,
      potentiellement_termine INTEGER DEFAULT 0,
      potentiellement_en_cours INTEGER DEFAULT 0,
      estime_termine INTEGER DEFAULT 0,
      statut_maj_at TEXT,
      ami_ofb INTEGER,
      source TEXT,
      url_page TEXT,
      created_at TEXT DEFAULT (datetime('now')),
      updated_at TEXT DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS communes (
      projet_id TEXT NOT NULL,
      code_geographique TEXT NOT NULL,
      libelle_geographique TEXT,
      epci TEXT,
      libelle_epci TEXT,
      departement TEXT,
      libelle_departement TEXT,
      region TEXT,
      libelle_pnr TEXT,
      lon REAL,
      lat REAL,
      anomalie INTEGER DEFAULT 0,
      distance_centre_km REAL,
      PRIMARY KEY (projet_id, code_geographique),
      FOREIGN KEY (projet_id) REFERENCES projets(id)
    );

    CREATE TABLE IF NOT EXISTS snapshots (
      snapshot_date TEXT NOT NULL,
      projet_id TEXT NOT NULL,
      avancement TEXT,
      source TEXT,
      PRIMARY KEY (snapshot_date, projet_id),
      FOREIGN KEY (projet_id) REFERENCES projets(id)
    );

    CREATE TABLE IF NOT EXISTS enrichissements (
      projet_id TEXT PRIMARY KEY,
      description TEXT,
      documents_json TEXT,
      FOREIGN KEY (projet_id) REFERENCES projets(id)
    );

    CREATE TABLE IF NOT EXISTS verifications (
      projet_id TEXT PRIMARY KEY,
      etat TEXT NOT NULL DEFAULT 'a_verifier',
      note TEXT,
      lien TEXT,
      verifie_le TEXT,
      FOREIGN KEY (projet_id) REFERENCES projets(id)
    );

    CREATE TABLE IF NOT EXISTS admin_sessions (
      token_hash TEXT PRIMARY KEY,
      username TEXT NOT NULL,
      created_at TEXT NOT NULL DEFAULT (datetime('now')),
      expires_at TEXT NOT NULL
    );

    CREATE TABLE IF NOT EXISTS contributions (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      projet_id TEXT NOT NULL,
      type TEXT NOT NULL CHECK (type IN ('statut','note','lien','autre')),
      payload_json TEXT NOT NULL,
      commentaire TEXT,
      ip TEXT,
      user_agent TEXT,
      created_at TEXT NOT NULL DEFAULT (datetime('now')),
      statut TEXT NOT NULL DEFAULT 'en_attente' CHECK (statut IN ('en_attente','validee','refusee','retiree')),
      traite_par TEXT,
      traite_le TEXT,
      note_admin TEXT,
      FOREIGN KEY (projet_id) REFERENCES projets(id)
    );

    CREATE TABLE IF NOT EXISTS audit_log (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      contribution_id INTEGER NOT NULL REFERENCES contributions(id),
      action TEXT NOT NULL CHECK (action IN ('validee','refusee','retiree')),
      avant TEXT,
      apres TEXT,
      par_admin TEXT,
      le TEXT NOT NULL DEFAULT (datetime('now'))
    );

    CREATE INDEX IF NOT EXISTS idx_communes_dept ON communes(libelle_departement);
    CREATE INDEX IF NOT EXISTS idx_communes_region ON communes(region);
    CREATE INDEX IF NOT EXISTS idx_contributions_projet ON contributions(projet_id);
    CREATE INDEX IF NOT EXISTS idx_contributions_statut ON contributions(statut);
  `);

  // Migration : ajout des flags pour les bases déjà créées
  const cols = db.prepare("PRAGMA table_info(projets)").all() as { name: string }[];
  if (!cols.some((c) => c.name === "potentiellement_termine")) {
    db.exec("ALTER TABLE projets ADD COLUMN potentiellement_termine INTEGER DEFAULT 0");
  }
  if (!cols.some((c) => c.name === "potentiellement_en_cours")) {
    db.exec("ALTER TABLE projets ADD COLUMN potentiellement_en_cours INTEGER DEFAULT 0");
  }
  if (!cols.some((c) => c.name === "estime_termine")) {
    db.exec("ALTER TABLE projets ADD COLUMN estime_termine INTEGER DEFAULT 0");
  }

  const ccols = db.prepare("PRAGMA table_info(communes)").all() as { name: string }[];
  if (!ccols.some((c) => c.name === "anomalie")) {
    db.exec("ALTER TABLE communes ADD COLUMN anomalie INTEGER DEFAULT 0");
    db.exec("ALTER TABLE communes ADD COLUMN distance_centre_km REAL");
  }
}

export function upsertProjet(
  db: DatabaseSync,
  p: {
    id: string;
    nom: string;
    structure_porteuse?: string;
    type_de_structure_porteuse?: string;
    annee_debut?: number | null;
    avancement_raw?: string;
    statut: string;
    ami_ofb?: boolean | null;
    source: string;
    url_page?: string;
    communes: {
      code_geographique: string;
      libelle_geographique?: string;
      epci?: string;
      libelle_epci?: string;
      departement?: string;
      libelle_departement?: string;
      region?: string;
      libelle_pnr?: string;
    }[];
  },
) {
  db.prepare(
    `INSERT INTO projets (id, nom, structure_porteuse, type_de_structure_porteuse,
       annee_debut, avancement_raw, statut, ami_ofb, source, url_page)
     VALUES (?,?,?,?,?,?,?,?,?,?)
     ON CONFLICT(id) DO UPDATE SET
       nom=excluded.nom,
       structure_porteuse=COALESCE(excluded.structure_porteuse, projets.structure_porteuse),
       type_de_structure_porteuse=COALESCE(excluded.type_de_structure_porteuse, projets.type_de_structure_porteuse),
       annee_debut=COALESCE(excluded.annee_debut, projets.annee_debut),
       avancement_raw=excluded.avancement_raw,
       statut=excluded.statut,
       ami_ofb=excluded.ami_ofb,
       url_page=COALESCE(excluded.url_page, projets.url_page),
       updated_at=datetime('now')`,
  ).run(
    p.id,
    p.nom,
    p.structure_porteuse ?? null,
    p.type_de_structure_porteuse ?? null,
    p.annee_debut ?? null,
    p.avancement_raw ?? null,
    p.statut,
    p.ami_ofb === undefined || p.ami_ofb === null ? null : p.ami_ofb ? 1 : 0,
    p.source,
    p.url_page ?? null,
  );

  const delCommunes = db.prepare("DELETE FROM communes WHERE projet_id = ?");
  delCommunes.run(p.id);
  const insCommune = db.prepare(
    `INSERT OR IGNORE INTO communes (projet_id, code_geographique, libelle_geographique,
       epci, libelle_epci, departement, libelle_departement, region, libelle_pnr)
     VALUES (?,?,?,?,?,?,?,?,?)`,
  );
  for (const c of p.communes) {
    insCommune.run(
      p.id,
      c.code_geographique,
      c.libelle_geographique ?? null,
      c.epci ?? null,
      c.libelle_epci ?? null,
      c.departement ?? null,
      c.libelle_departement ?? null,
      c.region ?? null,
      c.libelle_pnr ?? null,
    );
  }
}

export function recordSnapshot(
  db: DatabaseSync,
  snapshotDate: string,
  projetId: string,
  avancement: string,
  source: string,
) {
  db.prepare(
    `INSERT INTO snapshots (snapshot_date, projet_id, avancement, source)
     VALUES (?,?,?,?)
     ON CONFLICT(snapshot_date, projet_id) DO UPDATE SET avancement=excluded.avancement`,
  ).run(snapshotDate, projetId, avancement, source);
}

export function saveVerification(
  db: DatabaseSync,
  v: { projet_id: string; etat: string; note?: string | null; lien?: string | null },
) {
  db.prepare(
    `INSERT INTO verifications (projet_id, etat, note, lien, verifie_le)
     VALUES (?,?,?,?, datetime('now'))
     ON CONFLICT(projet_id) DO UPDATE SET
       etat=excluded.etat,
       note=excluded.note,
       lien=excluded.lien,
       verifie_le=excluded.verifie_le`,
  ).run(v.projet_id, v.etat, v.note ?? null, v.lien ?? null);
}

export interface ContributionRow {
  id: number;
  projet_id: string;
  type: string;
  payload_json: string;
  commentaire: string | null;
  ip: string | null;
  user_agent: string | null;
  created_at: string;
  statut: string;
  traite_par: string | null;
  traite_le: string | null;
  note_admin: string | null;
}

export function insertContribution(
  db: DatabaseSync,
  c: {
    projet_id: string;
    type: string;
    payload_json: string;
    commentaire?: string;
    ip?: string;
    user_agent?: string;
  },
): number {
  const r = db
    .prepare(
      `INSERT INTO contributions (projet_id, type, payload_json, commentaire, ip, user_agent)
       VALUES (?,?,?,?,?,?)`,
    )
    .run(
      c.projet_id,
      c.type,
      c.payload_json,
      c.commentaire ?? null,
      c.ip ?? null,
      c.user_agent ?? null,
    );
  return Number(r.lastInsertRowid);
}

export function getContribution(db: DatabaseSync, id: number): ContributionRow | undefined {
  return db
    .prepare("SELECT * FROM contributions WHERE id = ?")
    .get(id) as ContributionRow | undefined;
}

export function setContributionStatut(
  db: DatabaseSync,
  id: number,
  statut: string,
  traitePar: string,
  noteAdmin?: string,
) {
  db.prepare(
    `UPDATE contributions SET statut = ?, traite_par = ?, traite_le = datetime('now'), note_admin = COALESCE(?, note_admin)
     WHERE id = ?`,
  ).run(statut, traitePar, noteAdmin ?? null, id);
}

export function insertAudit(
  db: DatabaseSync,
  a: {
    contribution_id: number;
    action: string;
    avant?: string | null;
    apres?: string | null;
    par_admin: string;
  },
) {
  db.prepare(
    `INSERT INTO audit_log (contribution_id, action, avant, apres, par_admin)
     VALUES (?,?,?,?,?)`,
  ).run(a.contribution_id, a.action, a.avant ?? null, a.apres ?? null, a.par_admin);
}

export function getVerification(
  db: DatabaseSync,
  projetId: string,
): { etat: string; note: string | null; lien: string | null } | undefined {
  return db
    .prepare("SELECT etat, note, lien FROM verifications WHERE projet_id = ?")
    .get(projetId) as { etat: string; note: string | null; lien: string | null } | undefined;
}

export function deleteVerification(db: DatabaseSync, projetId: string) {
  db.prepare("DELETE FROM verifications WHERE projet_id = ?").run(projetId);
}

export function getLastAppliedAudit(db: DatabaseSync, contributionId: number): {
  avant: string | null;
} | undefined {
  return db
    .prepare(
      "SELECT avant FROM audit_log WHERE contribution_id = ? AND action = 'validee' ORDER BY id DESC LIMIT 1",
    )
    .get(contributionId) as { avant: string | null } | undefined;
}

export function statistic(db: DatabaseSync, sql: string): number {
  return (db.prepare(sql).get() as { n: number }).n;
}