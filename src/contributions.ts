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

export const CONTRIBUTION_TYPES = ["statut", "note", "lien", "autre"] as const;

export const contributionSchema = z.object({
  projet_id: z.string().min(1).max(120),
  type: z.enum(CONTRIBUTION_TYPES),
  statut_suggere: z.enum(["termine", "en_cours", "va_debuter"]).optional(),
  note: z.string().max(2000).optional(),
  lien: z.string().max(500).optional(),
  texte: z.string().max(2000).optional(),
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
  if (c.note) p.note = c.note;
  if (c.lien) p.lien = c.lien;
  if (c.texte) p.texte = c.texte;
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

  const avant = getVerification(db, c.projet_id);
  const cible = verificationTarget(c.type, JSON.parse(c.payload_json), avant);
  const apres = { ...cible };

  saveVerification(db, { projet_id: c.projet_id, etat: cible.etat, note: cible.note, lien: cible.lien });
  setContributionStatut(db, id, "validee", admin);
  insertAudit(db, {
    contribution_id: id,
    action: "validee",
    avant: avant ? JSON.stringify(avant) : null,
    apres: JSON.stringify(apres),
    par_admin: admin,
  });
  return { ok: true };
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
  const avant = applied?.avant ? (JSON.parse(applied.avant) as { etat: string; note: string | null; lien: string | null }) : null;
  const etatAvant = getVerification(db, c.projet_id);

  if (avant) {
    saveVerification(db, { projet_id: c.projet_id, etat: avant.etat, note: avant.note ?? null, lien: avant.lien ?? null });
  } else {
    deleteVerification(db, c.projet_id);
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
