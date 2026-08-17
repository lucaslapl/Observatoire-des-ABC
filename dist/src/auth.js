import { randomBytes, scryptSync, timingSafeEqual, createHash } from "node:crypto";
export const SESSION_COOKIE = "admin_session";
const SESSION_DAYS = 7;
// --- Hash du mot de passe admin (scrypt, sel aléatoire) ---
export function hashPassword(password) {
    const salt = randomBytes(16).toString("hex");
    const hash = scryptSync(password, salt, 64).toString("hex");
    return `${salt}:${hash}`;
}
export function verifyPassword(password, stored) {
    const [salt, hash] = stored.split(":");
    if (!salt || !hash)
        return false;
    const candidate = scryptSync(password, salt, 64);
    const expected = Buffer.from(hash, "hex");
    return candidate.length === expected.length && timingSafeEqual(candidate, expected);
}
export function hashToken(token) {
    return createHash("sha256").update(token).digest("hex");
}
// --- Sessions en base (survivent aux redémarrages) ---
export function createSession(db, username) {
    const token = randomBytes(32).toString("hex");
    const expiresAt = new Date(Date.now() + SESSION_DAYS * 24 * 3600 * 1000)
        .toISOString()
        .replace("T", " ")
        .slice(0, 19);
    db.prepare("INSERT INTO admin_sessions (token_hash, username, expires_at) VALUES (?,?,?)").run(hashToken(token), username, expiresAt);
    return { token, expiresAt };
}
export function getSession(db, token) {
    if (!token)
        return null;
    const row = db
        .prepare("SELECT username, expires_at FROM admin_sessions WHERE token_hash = ?")
        .get(hashToken(token));
    if (!row)
        return null;
    if (row.expires_at <= new Date().toISOString().replace("T", " ").slice(0, 19)) {
        db.prepare("DELETE FROM admin_sessions WHERE token_hash = ?").run(hashToken(token));
        return null;
    }
    return { username: row.username };
}
export function deleteSession(db, token) {
    if (token)
        db.prepare("DELETE FROM admin_sessions WHERE token_hash = ?").run(hashToken(token));
}
export function clearExpiredSessions(db) {
    const now = new Date().toISOString().replace("T", " ").slice(0, 19);
    db.prepare("DELETE FROM admin_sessions WHERE expires_at <= ?").run(now);
}
// --- Cookies (sans dépendance) ---
export function parseCookies(header) {
    const out = {};
    if (!header)
        return out;
    for (const part of header.split(";")) {
        const i = part.indexOf("=");
        if (i < 0)
            continue;
        out[part.slice(0, i).trim()] = decodeURIComponent(part.slice(i + 1).trim());
    }
    return out;
}
export function serializeCookie(name, value, opts = {}) {
    const parts = [`${name}=${encodeURIComponent(value)}`];
    if (opts.maxAgeSeconds)
        parts.push(`Max-Age=${opts.maxAgeSeconds}`);
    if (opts.secure)
        parts.push("Secure");
    if (opts.httpOnly !== false)
        parts.push("HttpOnly");
    parts.push(`Path=${opts.path ?? "/"}`);
    parts.push("SameSite=Lax");
    return parts.join("; ");
}
// --- IP du client (reverse proxy) ---
export function clientIp(req, trustProxy) {
    if (trustProxy) {
        const fwd = req.headers["x-forwarded-for"];
        if (typeof fwd === "string" && fwd.trim())
            return fwd.split(",")[0].trim();
    }
    return req.socket.remoteAddress ?? "inconnu";
}
