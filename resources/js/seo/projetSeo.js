/**
 * SEO de la fiche projet (/abc/{slug}) : title, meta description, badge
 * département du header et JSON-LD (schema.org). Module pur — aucune
 * dépendance à Vue ou au DOM — testé par tests/js/projetSeo.test.mjs.
 *
 * Audit SEO du 2026-11-17 (C3/C4) : la fiche n'exposait que le nom brut du
 * projet en title et H1, une description générique (« 1 commune(s) ») et
 * un fil d'Ariane sans le niveau département. Domaine de référence : les
 * pages Commune (« ABC à Romillé (35, Ille-et-Vilaine) », CTR 12,1 %).
 *
 * Faits bruts uniquement (statuts, comptages, sources, référentiels), issus
 * de la payload de LandingService::projet() et de patrimoine_resume :
 * jamais de superlatif, jamais de promesse.
 */

/* Longueur cible d'un title (~65 caractères visibles dans les SERP). */
export const MAX_TITRE = 65;

/* Normalisation de comparaison (insensible casse/accents/séparateurs). */
const cle = (v) =>
    String(v ?? '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();

/* Communes retenues du périmètre (les communes « anomalie » sont écartées,
   comme pour la démographie et le patrimoine). */
const communesRetenues = (projet) => (projet.communes || []).filter((c) => !c.anomalie);

/* « (49, Maine-et-Loire) » ou « (49) » — même motif que les pages Commune. */
const parentheseDept = (projet) => {
    const d = projet.departement || {};
    if (!d.code && !d.label) return '';
    if (d.code && d.label) return ` (${d.code}, ${d.label})`;
    return ` (${d.code || d.label})`;
};

/* « Rhône (69) » : badge du header, absent si le département est inconnu. */
export function badgeDepartement(projet) {
    const d = projet.departement || {};
    if (!d.code && !d.label) return null;
    if (d.label && d.code) return `${d.label} (${d.code})`;
    return d.label || d.code;
}

/**
 * Title de la fiche : nom explicite + département, sinon « ABC {commune
 * principale} [et N communes] » + département, puis porteur ou région en
 * suffixe si la longueur visible reste raisonnable (≤ ~65 caractères) —
 * finalité : « ABC de Mûrs-Erigné (49, Maine-et-Loire) — {porteur} ». Un
 * candidat trop long cède la place au suivant (nom verbeux de porteur →
 * formule courte sur la commune) ; à défaut le nom du projet est gardé.
 */
export function titleProjet(projet) {
    const communes = communesRetenues(projet);
    const principale = communes[0] || null;
    const parenthese = parentheseDept(projet);

    const candidats = [];
    if (principale && cle(projet.nom).includes(cle(principale.libelle))) {
        // Le nom porte déjà la commune : le garder tel quel.
        candidats.push(projet.nom);
    }
    if (principale) {
        if (communes.length === 1) {
            candidats.push(`ABC ${principale.libelle}`);
        } else {
            candidats.push(`ABC ${principale.libelle} et ${communes.length - 1} commune${communes.length > 2 ? 's' : ''}`);
        }
    }
    candidats.push(projet.nom);

    const cible = candidats
        .map((c) => `${c}${parenthese}`)
        .find((c) => c.length <= MAX_TITRE) || `${projet.nom}${parenthese}`;

    for (const suffixe of [projet.structure_porteuse, projet.region_label]) {
        if (suffixe && `${cible} — ${suffixe}`.length <= MAX_TITRE) {
            return `${cible} — ${suffixe}`;
        }
    }

    return cible;
}

/* Phrase de statut, en français courant : « en cours », « terminé »… */
const phraseStatut = (projet) => ({
    en_cours: 'en cours',
    termine: 'terminé',
    a_venir: 'à venir',
    inconnu: 'au statut inconnu',
}[projet.statut] || String(projet.statut_label || '').toLowerCase() || 'au statut inconnu');

/* « 1 commune » / « 12 communes » — jamais « 1 commune(s) ». */
const plurielCommunes = (n) => `${n} commune${n > 1 ? 's' : ''}`;

/* « depuis 2020 » / « 2010–2013 ». */
const phraseAnnees = (projet) => {
    if (!projet.annee_debut) return '';
    if (!projet.annee_fin) return `depuis ${projet.annee_debut}`;
    return `${projet.annee_debut}–${projet.annee_fin}`;
};

/* « 12 ZNIEFF et 3 sites Natura 2000 sur le périmètre » (faits INPN). */
const phrasePatrimoine = (pat) => {
    if (!pat || !pat.present) return '';
    const faits = [];
    if (pat.nb_znieff) faits.push(`${pat.nb_znieff} ZNIEFF`);
    if (pat.nb_natura) faits.push(`${pat.nb_natura} site${pat.nb_natura > 1 ? 's' : ''} Natura 2000`);
    if (faits.length === 0) return '';
    return [faits.join(' et '), ' sur le périmètre'].join('');
};

/**
 * Meta description : formulation « Atlas de la biodiversité communale »,
 * commune principale (et périmètre), département, statut, porteur, nombre
 * de communes au pluriel correct, période, faits saillants INPN et
 * population quand disponibles. Terminée par la revendication de méthode.
 */
export function descriptionProjet(projet, patrimoineResume) {
    const communes = communesRetenues(projet);
    const principale = communes[0] || null;
    const parenthese = parentheseDept(projet);

    let ouverture = 'Atlas de la biodiversité communale';
    if (principale) {
        ouverture += ` à ${principale.libelle}`;
        if (communes.length > 1) {
            ouverture += ` et ${communes.length - 1} autre${communes.length > 2 ? 's' : ''} commune${communes.length > 2 ? 's' : ''}`;
        }
    }
    ouverture += parenthese;

    const morceaux = [];
    if (projet.structure_porteuse) {
        morceaux.push(`ABC ${phraseStatut(projet)} porté par ${projet.structure_porteuse}`);
    } else {
        morceaux.push(`ABC ${phraseStatut(projet)}`);
    }
    if (communes.length > 0) morceaux.push(plurielCommunes(communes.length));
    const annees = phraseAnnees(projet);
    if (annees) morceaux.push(annees);
    const patrimoine = phrasePatrimoine(patrimoineResume);
    if (patrimoine) morceaux.push(patrimoine);
    if (projet.demographie && projet.demographie.population_totale) {
        morceaux.push(`${Number(projet.demographie.population_totale).toLocaleString('fr-FR')} habitants`);
    }

    return `${ouverture} : ${morceaux.join(', ')}. Statuts vérifiés et contributions publiques.`;
}

/**
 * Fil d'Ariane de la fiche (items du composant Breadcrumbs, « Accueil »
 * y est préfixé par le composant) : Région → Département → Projet. Le
 * niveau département est le département majoritaire du périmètre (payload
 * LandingService). Réutilisé tel quel par le BreadcrumbList JSON-LD : le
 * fil affiché et le JSON-LD ne peuvent pas diverger.
 */
export function crumbsProjet(projet) {
    const list = [];
    if (projet.region_slug) {
        list.push({ label: projet.region_label, url: `/region/${projet.region_slug}` });
    }
    const d = projet.departement || {};
    if (d.code) {
        list.push({ label: d.label || d.code, url: `/departement/${d.code}` });
    }
    list.push({ label: projet.nom, url: null });

    return list;
}

/**
 * JSON-LD de la fiche : BreadcrumbList (synchronisé avec le fil d'Ariane
 * affiché) et Project enrichi — aire couverte, localisation avec le code
 * département (ISO 3166-2), porteur comme financeur affilié, période.
 */
export function jsonLdProjet(projet, baseUrl) {
    const base = baseUrl || '';
    const p = projet;
    const d = p.departement || {};
    const communes = communesRetenues(p);
    const principale = communes[0] || null;

    const items = [{ '@type': 'ListItem', position: 1, name: 'Accueil', item: `${base}/` }];
    for (const crumb of crumbsProjet(p)) {
        if (crumb.url) {
            items.push({ '@type': 'ListItem', position: items.length + 1, name: crumb.label, item: `${base}${crumb.url}` });
        }
    }
    items.push({ '@type': 'ListItem', position: items.length + 1, name: p.nom, item: `${base}/abc/${p.slug}` });

    const project = {
        '@context': 'https://schema.org',
        '@type': 'Project',
        name: p.nom,
        url: `${base}/abc/${p.slug}`,
        description: `${p.nom} — Atlas de la Biodiversité Communale (${p.statut_label})`,
        identifier: p.id,
    };

    // Aire couverte : commune principale, ou les premières communes du
    // périmètre (liste tronquée, la fiche liste les autres).
    if (principale) {
        project.areaServed = communes.length === 1
            ? principale.libelle
            : communes.slice(0, 5).map((c) => c.libelle);
    }

    // Localisation : le département du périmètre, code ISO 3166-2 : FR.
    if (d.code) {
        project.location = {
            '@type': 'Place',
            name: d.label || d.code,
            address: {
                '@type': 'PostalAddress',
                addressCountry: 'FR',
                addressRegion: `FR-${String(d.code).toUpperCase()}`,
            },
        };
    }

    // Porteur : organisation affiliée (et financrice quand le Fonds vert
    // ou l'AMI OFB finance la démarche — faits seulement).
    if (p.structure_porteuse) {
        project.funder = { '@type': 'Organization', name: p.structure_porteuse };
    }

    if (p.annee_debut) {
        project.temporalCoverage = p.annee_fin ? `${p.annee_debut}/${p.annee_fin}` : String(p.annee_debut);
        project.startDate = String(p.annee_debut);
    }

    return [
        { '@context': 'https://schema.org', '@type': 'BreadcrumbList', itemListElement: items },
        project,
    ];
}
