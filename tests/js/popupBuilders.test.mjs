import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    buildPopup, buildContribForm, buildMultiRow, buildMultiHeader,
} from '../../resources/js/map/popupBuilders.js';

const base = {
    projet_id: 'abc-x',
    nom: 'ABC Test',
    commune: 'Loxeville',
    departement: '54',
    statut: 'en_cours',
    statut_affichage: null,
    categorie: 'En cours',
    structure_porteuse: 'Mairie',
    source: 'data.gouv',
    verifie: false,
    potentiellement_termine: false,
    potentiellement_en_cours: false,
    donnees_2022: false,
    estime_termine: false,
    anomalie: false,
};

test('buildPopup : contenu principal et formulaire de contribution', () => {
    const html = buildPopup(base, false);
    assert.match(html, /<b>ABC Test<\/b>/);
    assert.match(html, /Loxeville \(54\)/);
    assert.match(html, /Structure : Mairie/);
    assert.match(html, /data-sugg data-pid="abc-x"/);
    assert.doesNotMatch(html, /data-del-pid/);
});

test('buildPopup : bouton suppression uniquement pour un admin', () => {
    assert.match(buildPopup(base, true), /data-del-pid="abc-x"/);
    assert.doesNotMatch(buildPopup(base, false), /data-del-pid/);
});

test('buildPopup : verdict verifie avec note et lien', () => {
    const html = buildPopup({
        ...base,
        verif_etat: 'confirme_en_cours',
        verif_note: 'Vu sur le site',
        verif_lien: 'https://ex.fr',
    }, false);
    assert.match(html, /✓ Vérifié : En cours/);
    assert.match(html, /Vu sur le site/);
    assert.match(html, /href="https:\/\/ex.fr"/);
});

test('buildPopup : alertes pour projets potentiellement termines / archives', () => {
    const html = buildPopup({ ...base, potentiellement_termine: true, annee_debut: 2020 }, false);
    assert.match(html, /Potentiellement terminé/);
    const html2022 = buildPopup({ ...base, donnees_2022: true }, false);
    assert.match(html2022, /archives 2022/);
});

test('buildContribForm : pid transmis au formulaire', () => {
    assert.match(buildContribForm('abc-42'), /data-pid="abc-42"/);
});

test('buildMultiRow : badge statut, verdict et source figee', () => {
    const html = buildMultiRow({ ...base, verif_etat: 'introuvable' }, false);
    assert.match(html, /multi-chip/);
    assert.match(html, /En cours/);
    assert.match(html, /Vérifié : introuvable/);
    assert.doesNotMatch(html, /multi-srcnote/);

    const stale = buildMultiRow({ ...base, source: 'wayback' }, false);
    assert.match(stale, /multi-srcnote/);
});

test('buildMultiHeader : compteur, communes et note admin', () => {
    assert.match(buildMultiHeader(3, 2, false), /3<\/span> ABC sur ce territoire/);
    assert.match(buildMultiHeader(3, 2, false), /2 communes/);
    assert.match(buildMultiHeader(3, 2, true), /multi-adminnote/);
    assert.doesNotMatch(buildMultiHeader(3, 0, false), /communes/);
});
