// Clé localStorage et événement partagés entre ConsentBanner.vue et les liens
// « Cookies » des pieds de page : permettent de retirer son consentement à la
// mesure d'audience aussi simplement qu'il a été donné.
export const CONSENT_STORAGE_KEY = 'abc-consent';
export const CONSENT_REOPEN_EVENT = 'abc:consent:reopen';

export function reopenConsentChoice() {
    if (typeof window === 'undefined') {
        return;
    }

    window.localStorage.removeItem(CONSENT_STORAGE_KEY);
    window.dispatchEvent(new CustomEvent(CONSENT_REOPEN_EVENT));
}
