import { randomBytes, scryptSync, timingSafeEqual, createHash } from "node:crypto";
import type { DatabaseSync } from "node:sqlite";
import type { IncomingMessage } from "node:http";

export const SESSION_COOKIE = "admin_session";
const SESSION_DAYS = 7;

// --- Hash du mot de passe admin (scrypt, sel aléatoire) ---
export function hashPassword(password: string): string {
  const salt = randomBytes(16).toString("hex");
  const hash = scryptSync(password, salt, 64).toString("hex");
  return `${salt}:${hash}`;
}

export function verifyPassword(password: string, stored: string): boolean {
  const [salt, hash] = stored.split(":");
  if (!salt || !hash) return false;
  const candidate = scryptSync(password, salt, 64);
  const expected = Buffer.from(hash, "hex");
  return candidate.length === expected.length && timingSafeEqual(candidate, expected);
}

export function hashToken(token: string): string {
  return createHash("sha256").update(token).digest("hex");
}

// --- Sessions en base (survivent aux redémarrages) ---
export function createSession(db: DatabaseSync, username: string): { token: string; expiresAt: string } {
  const token = randomBytes(32).toString("hex");
  const expiresAt = new Date(Date.now() + SESSION_DAYS * 24 * 3600 * 1000)
    .toISOString()
    .replace("T", " ")
    .slice(0, 19);
  db.prepare("INSERT INTO admin_sessions (token_hash, username, expires_at) VALUES (?,?,?)").run(
    hashToken(token),
    username,
    expiresAt,
  );
  return { token, expiresAt };
}

export function getSession(db: DatabaseSync, token?: string): { username: string } | null {
  if (!token) return null;
  const row = db
    .prepare("SELECT username, expires_at FROM admin_sessions WHERE token_hash = ?")
    .get(hashToken(token)) as { username: string; expires_at: string } | undefined;
  if (!row) return null;
  if (row.expires_at <= new Date().toISOString().replace("T", " ").slice(0, 19)) {
    db.prepare("DELETE FROM admin_sessions WHERE token_hash = ?").run(hashToken(token));
    return null;
  }
  return { username: row.username };
}

export function deleteSession(db: DatabaseSync, token?: string) {
  if (token) db.prepare("DELETE FROM admin_sessions WHERE token_hash = ?").run(hashToken(token));
}

export function clearExpiredSessions(db: DatabaseSync) {
  const now = new Date().toISOString().replace("T", " ").slice(0, 19);
  db.prepare("DELETE FROM admin_sessions WHERE expires_at <= ?").run(now);
}

// --- Cookies (sans dépendance) ---
export function parseCookies(header?: string): Record<string, string> {
  const out: Record<string, string> = {};
  if (!header) return out;
  for (const part of header.split(";")) {
    const i = part.indexOf("=");
    if (i < 0) continue;
    out[part.slice(0, i).trim()] = decodeURIComponent(part.slice(i + 1).trim());
  }
  return out;
}

export function serializeCookie(
  name: string,
  value: string,
  opts: { maxAgeSeconds?: number; secure?: boolean; httpOnly?: boolean; path?: string } = {},
): string {
  const parts = [`${name}=${encodeURIComponent(value)}`];
  if (opts.maxAgeSeconds) parts.push(`Max-Age=${opts.maxAgeSeconds}`);
  if (opts.secure) parts.push("Secure");
  if (opts.httpOnly !== false) parts.push("HttpOnly");
  parts.push(`Path=${opts.path ?? "/"}`);
  parts.push("SameSite=Lax");
  return parts.join("; ");
}

// --- IP du client (reverse proxy) ---
export function clientIp(req: IncomingMessage, trustProxy: boolean): string {
  if (trustProxy) {
    const fwd = req.headers["x-forwarded-for"];
    if (typeof fwd === "string" && fwd.trim()) return fwd.split(",")[0].trim();
  }
  return req.socket.remoteAddress ?? "inconnu";
}
