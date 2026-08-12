import { z } from "zod";
import type { DatabaseSync } from "node:sqlite";
import {
  getContribution,
  getLastAppliedAudit,
  getVerification,
  insertAudit,
  saveVerification,
  setContributionStatut,
  deleteVerification,
} from "./db.js";

export const CONTRIBUTION_TYPES = ["statut", "note", "lien", "autre", "date_debut"] as const;

export const contributionSchema = z.object({
  projet_id: z.string().min(1).max(120),
  type: z.enum(CONTRIBUTION_TYPES),
  statut_suggere: z.enum(["termine", "en_cours", "va_debuter"]).optional(),
  annee_debut_suggeree: z.number().int().min(1990).max(2040).optional(),
  annee_fin_suggeree: z.number().int().min(1990).max(2040).optional(),
  note: z.string().max(2000).optional(),
  lien: z.string().max(500).optional(),
  texte: z.string().max(2000).optional(),
  source: z.string().max(500).optional(),
  commentaire: z.string().max(1000).optional(),
});
export type ContributionInput = z.infer<typeof contributionSchema>;

// Statut suggéré (catégorie carte) → verdict officiel de la table verifications.
export function verificationEtatPourStatut(s: string): string | null {
  if (s === "termine") return "confirme_termine";
  if (s === "en_cours") return "confirme_en_cours";
  if (s === "va_debuter") return "toujours_a_venir";
  return null;
}

function payloadFromInput(c: ContributionInput): Record<string, string> {
  const p: Record<string, string> = {};
  if (c.statut_suggere) p.statut_suggere = c.statut_suggere;
  if (c.annee_debut_suggeree) p.annee_debut_suggeree = String(c.annee_debut_suggeree);
  if (c.annee_fin_suggeree) p.annee_fin_suggeree = String(c.annee_fin_suggeree);
  if (c.note) p.note = c.note;
  if (c.lien) p.lien = c.lien;
  if (c.texte) p.texte = c.texte;
  if (c.source) p.source = c.source;
  return p;
}

// Construit les nouvelles valeurs de `verifications` à partir de la contribution,
// en conservant ce qui existait déjà et n'est pas touché.
function verificationTarget(
  type: string,
  payload: Record<string, string>,
  avant: { etat: string; note: string | null; lien: string | null } | undefined,
): { etat: string; note: string | null; lien: string | null } {
  let etat = avant?.etat ?? "a_verifier";
  let note = avant?.note ?? null;
  let lien = avant?.lien ?? null;
  if (type === "statut" && payload.statut_suggere) {
    etat = verificationEtatPourStatut(payload.statut_suggere) ?? etat;
  }
  if (type === "date_debut") {
    etat = "confirme_date";
    const debut = payload.annee_debut_suggeree;
    const fin = payload.annee_fin_suggeree;
    if (debut) {
      const ligne = fin ? `${debut}–${fin}` : debut;
      note = note ? `${note}\n— Dates confirmées : ${ligne}` : `Dates confirmées : ${ligne}`;
    }
    if (payload.source) note = note ? `${note} — Source : ${payload.source}` : `Source : ${payload.source}`;
  }
  if (type === "note" && payload.note) note = payload.note;
  if (type === "lien" && payload.lien) lien = payload.lien;
  if (type === "autre" && payload.texte) {
    note = note ? `${note}\n— ${payload.texte}` : payload.texte;
  }
  return { etat, note, lien };
}

export function applyContribution(
  db: DatabaseSync,
  id: number,
  admin: string,
): { ok: boolean; error?: string } {
  const c = getContribution(db, id);
  if (!c) return { ok: false, error: "Contribution introuvable" };
  if (c.statut !== "en_attente") return { ok: false, error: "Contribution déjà traitée" };

  const avant = projectStateOf(db, c.projet_id);
  const cible = verificationTarget(c.type, JSON.parse(c.payload_json), avant);

  saveVerification(db, { projet_id: c.projet_id, etat: cible.etat, note: cible.note, lien: cible.lien });

  if (c.type === "date_debut") {
    const payload = JSON.parse(c.payload_json) as Record<string, string>;
    const debut = Number(payload.annee_debut_suggeree);
    const fin = payload.annee_fin_suggeree ? Number(payload.annee_fin_suggeree) : null;
    if (Number.isInteger(debut) || fin !== null) {
      db.prepare(
        "UPDATE projets SET annee_debut = ?, annee_fin = ?, updated_at = datetime('now') WHERE id = ?",
      ).run(Number.isInteger(debut) ? debut : null, fin, c.projet_id);
    }
  }

  const apres = projectStateOf(db, c.projet_id);
  setContributionStatut(db, id, "validee", admin);
  insertAudit(db, {
    contribution_id: id,
    action: "validee",
    avant: JSON.stringify(avant),
    apres: JSON.stringify(apres),
    par_admin: admin,
  });
  return { ok: true };
}

// Instantané du fragment de projet pertinent pour une contribution (table
// `verifications` + date de début), afin de pouvoir appliquer et rejouer/annuler.
function projectStateOf(
  db: DatabaseSync,
  projetId: string,
): { etat: string; note: string | null; lien: string | null; annee_debut: number | null; annee_fin: number | null } {
  const v = getVerification(db, projetId);
  const p = db
    .prepare("SELECT annee_debut, annee_fin FROM projets WHERE id = ?")
    .get(projetId) as { annee_debut: number | null; annee_fin: number | null } | undefined;
  return {
    etat: v?.etat ?? "a_verifier",
    note: v?.note ?? null,
    lien: v?.lien ?? null,
    annee_debut: p?.annee_debut ?? null,
    annee_fin: p?.annee_fin ?? null,
  };
}

function applyState(db: DatabaseSync, projetId: string, s: { etat: string; note: string | null; lien: string | null }) {
  saveVerification(db, { projet_id: projetId, etat: s.etat, note: s.note, lien: s.lien });
}

export function rejectContribution(
  db: DatabaseSync,
  id: number,
  admin: string,
  noteAdmin?: string,
): { ok: boolean; error?: string } {
  const c = getContribution(db, id);
  if (!c) return { ok: false, error: "Contribution introuvable" };
  if (c.statut !== "en_attente") return { ok: false, error: "Contribution déjà traitée" };

  setContributionStatut(db, id, "refusee", admin, noteAdmin);
  insertAudit(db, { contribution_id: id, action: "refusee", avant: null, apres: null, par_admin: admin });
  return { ok: true };
}

// Rollback : restaure l'état `verifications` antérieur à la validation.
export function revertContribution(
  db: DatabaseSync,
  id: number,
  admin: string,
): { ok: boolean; error?: string } {
  const c = getContribution(db, id);
  if (!c) return { ok: false, error: "Contribution introuvable" };
  if (c.statut !== "validee") return { ok: false, error: "Seule une contribution validée est réversible" };

  const applied = getLastAppliedAudit(db, id);
  const avant = applied?.avant
    ? (JSON.parse(applied.avant) as {
        etat: string;
        note: string | null;
        lien: string | null;
        annee_debut?: number | null;
        annee_fin?: number | null;
      } | null)
    : null;
  const etatAvant = getVerification(db, c.projet_id);

  if (avant) {
    applyState(db, c.projet_id, avant);
  } else {
    deleteVerification(db, c.projet_id);
  }

  if (avant && ("annee_debut" in avant || "annee_fin" in avant)) {
    db.prepare(
      "UPDATE projets SET annee_debut = ?, annee_fin = ?, updated_at = datetime('now') WHERE id = ?",
    ).run(avant.annee_debut ?? null, avant.annee_fin ?? null, c.projet_id);
  }

  setContributionStatut(db, id, "retiree", admin);
  insertAudit(db, {
    contribution_id: id,
    action: "retiree",
    avant: etatAvant ? JSON.stringify(etatAvant) : null,
    apres: avant ? JSON.stringify(avant) : null,
    par_admin: admin,
  });
  return { ok: true };
}

export function contributionToJson(c: ContributionInput): string {
  return JSON.stringify(payloadFromInput(c));
}
