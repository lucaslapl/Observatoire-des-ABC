// Limiteur simple en mémoire (fenêtre glissante). Les compteurs sont perdus
// au redémarrage : acceptable pour un panneau d'administration personnel.
export class RateLimiter {
    windowMs;
    max;
    hits = new Map();
    constructor(windowMs, max) {
        this.windowMs = windowMs;
        this.max = max;
    }
    allow(key) {
        const now = Date.now();
        const arr = (this.hits.get(key) ?? []).filter((t) => now - t < this.windowMs);
        if (arr.length >= this.max) {
            this.hits.set(key, arr);
            return false;
        }
        arr.push(now);
        this.hits.set(key, arr);
        return true;
    }
}
