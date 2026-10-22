import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    buildPopup, buildContribForm, buildMultiRow, buildMultiHeader, shortenUrl, noteHtml,
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

test('buildContribForm : select statut avec option vide (optionnel avec une date)', () => {
    const html = buildContribForm('abc-42');
    assert.match(html, /data-s-statut[^>]*>/);
    assert.match(html, /<option value="">Choisir un statut…<\/option>/);
    assert.match(html, /value="en_cours">En cours/);
});

test('shortenUrl : domaine + debut de chemin, jamais l\'URL entiere', () => {
    const long = 'https://www.picardie-nature.org/etude-de-la-faune-sauvage/les-atlas-de-la-biodiversite/article/la-communaute-de-communes-du-val';
    const s = shortenUrl(long);
    assert.ok(s.startsWith('picardie-nature.org/'));
    assert.ok(s.length <= 48);
    assert.ok(s.endsWith('…'));
    assert.equal(shortenUrl('https://exemple.fr'), 'exemple.fr');
    const pasUrl = 'pas une url tres tres tres tres tres tres tres tres tres longue';
    const t = shortenUrl(pasUrl);
    assert.ok(t.length <= 48 && t.endsWith('…'));
});

test('noteHtml : texte echappe, URL en lien raccourci avec URL complete en title', () => {
    const url = 'https://exemple.org/article/la-communaute-de-communes-du-val';
    const note = `Dates confirmées : 2026 — Source : ${url}.`;
    const html = noteHtml(note);
    assert.match(html, new RegExp(`<a href="${url}"[^>]*title="${url}">exemple.org/[^<"…]*…</a>\\.`));
    const escaped = noteHtml('Note <b>html</b> & "quotes"');
    assert.match(escaped, /&lt;b&gt;html&lt;\/b&gt; &amp; &quot;quotes&quot;/);
});

test('buildPopup : note de verification avec URL longue rendue cliquable et raccourcie', () => {
    const url = 'https://www.picardie-nature.org/etude-de-la-faune-sauvage/les-atlas-de-la-biodiversite/article/la-communaute-de-communes-du-val';
    const html = buildPopup({
        ...base,
        verif_etat: 'confirme_date',
        verif_note: `Dates confirmées : 2026 — Source : ${url}`,
    }, false);
    assert.match(html, /✓ Vérifié : Date confirmée/);
    assert.match(html, new RegExp(`title="${url.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}"`));
    assert.ok(!html.includes(`>${url}<`), 'l\'URL entière ne doit pas être affichée en texte');
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
