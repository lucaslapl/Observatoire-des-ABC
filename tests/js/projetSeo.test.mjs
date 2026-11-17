import assert from 'node:assert/strict';
import { test } from 'node:test';

import { badgeDepartement, crumbsProjet, descriptionProjet, jsonLdProjet, MAX_TITRE, titleProjet } from '../../resources/js/seo/projetSeo.js';

/* Cas mono-commune de l'acceptation de l'audit : ABC de Mûrs-Erigné (49). */
const mursErigne = {
    id: 'abc-de-murs-erigne-murs-erigne-2020',
    nom: 'ABC de Mûrs-Erigné',
    slug: 'abc-de-murs-erigne',
    statut: 'en_cours',
    statut_label: 'En cours',
    structure_porteuse: 'Commune de Mûrs-Erigné',
    annee_debut: 2020,
    departement: { code: '49', label: 'Maine-et-Loire' },
    region_label: 'Pays de la Loire',
    region_slug: 'pays-de-la-loire',
    communes: [
        { libelle: 'Mûrs-Erigné', code: '49216', departement_code: '49', departement_label: 'Maine-et-Loire', anomalie: false },
    ],
    demographie: { population_totale: 5934, mono_commune: true },
};

/* Cas multi-communes (SMPMO, Rhône) : nom explicite, périmètre éclaté. */
const smmpmo = {
    id: 'abc-smpmo-2023',
    nom: 'ABC du Syndicat Mixte Plaines Monts d’Or (SMPMO)',
    slug: 'abc-454',
    statut: 'a_venir',
    statut_label: 'Va débuter',
    structure_porteuse: 'Syndicat Mixte Plaines Monts d’Or',
    annee_debut: 2023,
    departement: { code: '69', label: 'Rhône' },
    region_label: 'Auvergne-Rhône-Alpes',
    region_slug: 'auvergne-rhone-alpes',
    communes: [
        { libelle: 'Charbonnières-les-Bains', code: '69047', anomalie: false },
        { libelle: 'Chessy', code: '69056', anomalie: false },
        { libelle: 'Couzon-au-Mont-d’Or', code: '69070', anomalie: false },
        { libelle: 'Limonest', code: '69118', anomalie: false },
    ],
    demographie: { population_totale: 32100 },
};

const patrimoinePresent = { present: true, nb_znieff: 12, nb_natura: 2, nb_aires: 1 };

test('title mono-commune : nom explicite + département, suffixe porteur', () => {
    assert.equal(titleProjet(mursErigne), 'ABC de Mûrs-Erigné (49, Maine-et-Loire) — Commune de Mûrs-Erigné');
});

test('title mono-commune générique : « ABC {commune} (département) »', () => {
    const projet = {
        ...mursErigne,
        nom: 'Atlas de la biodiversité communale',
        structure_porteuse: null,
    };
    assert.equal(titleProjet(projet), 'ABC Mûrs-Erigné (49, Maine-et-Loire) — Pays de la Loire');
});

test('title multi-communes : nom explicite conservé, pluriel correct ailleurs', () => {
    // Le nom SMPMO ne contient AUCUNE commune du périmètre : la formule
    // multi-générique « ABC {commune principale} et N communes » s'applique
    // (aucun suffixe : le nom du syndicat ferait dépasser ~65 caractères).
    assert.equal(
        titleProjet(smmpmo),
        'ABC Charbonnières-les-Bains et 3 communes (69, Rhône)',
    );

    // Nom contenant sa commune principale : conservé tel quel.
    const nomPorteur = {
        ...smmpmo,
        nom: 'ABC de Limonest (Plaines Monts d’Or)',
        communes: [{ libelle: 'Limonest', code: '69118', anomalie: false }],
        region_label: null,
        structure_porteuse: null,
    };
    assert.equal(titleProjet(nomPorteur), 'ABC de Limonest (Plaines Monts d’Or) (69, Rhône)');

    const nomGenerique = {
        ...smmpmo,
        nom: 'Atlas de la biodiversité communale',
        structure_porteuse: null,
        region_label: 'Auvergne-Rhône-Alpes',
    };
    // « — Auvergne-Rhône-Alpes » ferait dépasser les ~65 caractères : pas de
    // suffixe, le titre reste centré commune.
    assert.equal(
        titleProjet(nomGenerique),
        'ABC Charbonnières-les-Bains et 3 communes (69, Rhône)',
    );

    // Deux communes, région courte : « et 1 commune », jamais « et 1 communes ».
    const deux = {
        ...nomGenerique,
        communes: [nomGenerique.communes[1], nomGenerique.communes[0]],
        region_label: 'Corse',
        departement: { code: '2a', label: 'Corse-du-Sud' },
    };
    assert.equal(titleProjet(deux), 'ABC Chessy et 1 commune (2a, Corse-du-Sud) — Corse');
});

test('title borné (~65 caractères visibles) : nom verbeux → formule courte', () => {
    const verbeux = {
        ...mursErigne,
        nom: 'ABC de la Communauté de communes du Val de Mûrs-Erigné et annexes',
        structure_porteuse: 'Communauté de communes Loire-Layon-Aubance (communes du Louet et du Loir)',
        region_label: null,
    };
    const titre = titleProjet(verbeux);
    assert.ok(titre.length <= MAX_TITRE, `titre trop long : ${titre.length} — ${titre}`);
    assert.ok(titre.startsWith('ABC Mûrs-Erigné (49, Maine-et-Loire)'), titre);
    assert.ok(!titre.includes('Loire-Layon'), 'un suffixe trop long ne doit pas être concaténé');
});

test('title sans données de commune : le nom du projet seul', () => {
    assert.equal(titleProjet({ ...mursErigne, communes: [], departement: { code: null, label: null }, region_label: null, structure_porteuse: null }), 'ABC de Mûrs-Erigné');
});

test('description mono-commune : pluriel correct et faits saillants', () => {
    const d = descriptionProjet(mursErigne, patrimoinePresent);
    assert.ok(d.startsWith('Atlas de la biodiversité communale à Mûrs-Erigné (49, Maine-et-Loire) :'));
    assert.ok(d.includes('ABC en cours porté par Commune de Mûrs-Erigné'), d);
    assert.ok(d.includes('1 commune'), d);
    assert.ok(!d.includes('1 commune(s)'), 'jamais de « 1 commune(s) »');
    assert.ok(d.includes('12 ZNIEFF et 2 sites Natura 2000 sur le périmètre'), d);
    assert.ok(d.includes('depuis 2020'), d);
    assert.ok(d.includes(`${(5934).toLocaleString('fr-FR')} habitants`), `séparateur millier French locale attendu : ${d}`);
    assert.ok(d.endsWith('Statuts vérifiés et contributions publiques.'), d);
});

test('description multi-communes : périmètre et pluriel', () => {
    const d = descriptionProjet(smmpmo, { present: false, nb_znieff: 0, nb_natura: 0, nb_aires: 0 });
    assert.ok(d.includes('à Charbonnières-les-Bains et 3 autres communes (69, Rhône)'), d);
    assert.ok(d.includes('4 communes'), d);
    assert.ok(!d.includes('sur le périmètre'), 'aucun fait INPN : pas de clause patrimoine');
});

test('description sans porteur ni patrimoine reste factuelle', () => {
    const d = descriptionProjet({ ...mursErigne, structure_porteuse: null, demographie: null, statut: 'inconnu', statut_label: 'Statut inconnu' }, null);
    assert.ok(d.includes('ABC au statut inconnu'), d);
    assert.ok(!d.includes('porté par'), d);
    assert.ok(!d.includes('habitants'), d);
});

test('badge département : « Maine-et-Loire (49) », null si inconnu', () => {
    assert.equal(badgeDepartement(mursErigne), 'Maine-et-Loire (49)');
    assert.equal(badgeDepartement({ ...mursErigne, departement: { code: null, label: null } }), null);
});

test('JSON-LD Project enrichi : areaServed, location (FR-69), funder, temporalCoverage', () => {
    const blocks = jsonLdProjet(smmpmo, 'https://observatoire-abc.fr');
    const project = blocks.find((b) => b['@type'] === 'Project');
    const breadcrumb = blocks.find((b) => b['@type'] === 'BreadcrumbList');

    assert.deepEqual(project.areaServed, ['Charbonnières-les-Bains', 'Chessy', 'Couzon-au-Mont-d’Or', 'Limonest']);
    assert.equal(project.location['@type'], 'Place');
    assert.equal(project.location.address.addressRegion, 'FR-69');
    assert.equal(project.location.address.addressCountry, 'FR');
    assert.deepEqual(project.funder, { '@type': 'Organization', name: 'Syndicat Mixte Plaines Monts d’Or' });
    assert.equal(project.temporalCoverage, '2023');
    assert.equal(project.startDate, '2023');

    // Fil d'Ariane complet : Accueil → Région → Département → Projet.
    assert.deepEqual(
        breadcrumb.itemListElement.map((i) => i.name),
        ['Accueil', 'Auvergne-Rhône-Alpes', 'Rhône', smmpmo.nom],
    );
    assert.deepEqual(
        breadcrumb.itemListElement.map((i) => i.item),
        [
            'https://observatoire-abc.fr/',
            'https://observatoire-abc.fr/region/auvergne-rhone-alpes',
            'https://observatoire-abc.fr/departement/69',
            'https://observatoire-abc.fr/abc/abc-454',
        ],
    );
});

test('fil d’Ariane : le niveau département apparaît entre la région et le projet', () => {
    assert.deepEqual(crumbsProjet(smmpmo), [
        { label: 'Auvergne-Rhône-Alpes', url: '/region/auvergne-rhone-alpes' },
        { label: 'Rhône', url: '/departement/69' },
        { label: smmpmo.nom, url: null },
    ]);

    // Sans département connu : le fil saute le niveau sans casse.
    const sansDept = crumbsProjet({ ...mursErigne, departement: { code: null, label: null } });
    assert.deepEqual(sansDept.map((c) => c.label), ['Pays de la Loire', mursErigne.nom]);
});

test('JSON-LD mono-commune : areaServed scalaire, fil d’Ariane sans région', () => {
    const blocks = jsonLdProjet({ ...mursErigne, region_slug: null, region_label: null, annee_fin: 2023 }, 'https://observatoire-abc.fr');
    const project = blocks.find((b) => b['@type'] === 'Project');
    const breadcrumb = blocks.find((b) => b['@type'] === 'BreadcrumbList');

    assert.equal(project.areaServed, 'Mûrs-Erigné');
    assert.equal(project.temporalCoverage, '2020/2023');
    // Sans région : Accueil → Département → Projet.
    assert.deepEqual(
        breadcrumb.itemListElement.map((i) => i.name),
        ['Accueil', 'Maine-et-Loire', 'ABC de Mûrs-Erigné'],
    );
    assert.equal(breadcrumb.itemListElement[1].item, 'https://observatoire-abc.fr/departement/49');
});
