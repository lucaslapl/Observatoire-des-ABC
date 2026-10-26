import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    visible, dStatut, fmtCount, srcChip, delBtn, srcLabel,
    COLOR, PALETTES, applyPalette,
} from '../../resources/js/map/constants.js';

const feature = (props) => ({ properties: props, geometry: { coordinates: [2.35, 48.85] } });

test('visible : aucun filtre actif = tout passe', () => {
    assert.equal(visible(feature({ statut: 'en_cours' }), {}), true);
});

test('visible : filtre statut respecte statut_affichage', () => {
    const f = feature({ statut: 'inconnu', statut_affichage: 'en_cours' });
    assert.equal(visible(f, { statut: 'en_cours' }), true);
    assert.equal(visible(f, { statut: 'inconnu' }), false);
});

test('visible : période exclut les dates inconnues', () => {
    assert.equal(visible(feature({ annee_debut: null }), { yearFrom: '2020' }), false);
    assert.equal(visible(feature({ annee_debut: 2021 }), { yearFrom: '2020', yearTo: '2022' }), true);
    assert.equal(visible(feature({ annee_debut: 2023 }), { yearTo: '2022' }), false);
});

test('visible : recherche insensible à la casse sur plusieurs champs', () => {
    const f = feature({ nom: 'ABC Test', commune: 'Loxeville' });
    assert.equal(visible(f, { q: 'loxe' }), true);
    assert.equal(visible(f, { q: 'absent' }), false);
});

test('dStatut : statut_affichage prime', () => {
    assert.equal(dStatut({ statut: 'a', statut_affichage: 'b' }), 'b');
    assert.equal(dStatut({ statut: 'a' }), 'a');
});

test('fmtCount : pluriel seulement si n > 1', () => {
    assert.equal(fmtCount(1, 'points'), '1 point');
    assert.equal(fmtCount(3, 'points'), '3 points');
});

test('srcChip : badge figé pour wayback et donnees_2022', () => {
    assert.match(srcChip({ source: 'wayback' }), /src-stale/);
    assert.match(srcChip({ source: 'data.gouv', donnees_2022: true }), /src-stale/);
    assert.doesNotMatch(srcChip({ source: 'data.gouv' }), /src-stale/);
});

test('delBtn : seulement pour un admin', () => {
    assert.equal(delBtn({ projet_id: 'x', nom: 'Y' }, false), '');
    assert.match(delBtn({ projet_id: 'x', nom: 'Y' }, true), /data-del-pid="x"/);
    // guillemets neutralises dans l'attribut
    assert.match(delBtn({ projet_id: 'x', nom: 'A"B' }, true), /data-del-nom="A&quot;B"/);
});

test('srcLabel : libelles connus et inconnus', () => {
    assert.equal(srcLabel('data.gouv'), 'Registre OFB');
    assert.equal(srcLabel('nimporte'), 'nimporte');
    assert.equal(srcLabel(null), '—');
});

test('PALETTES : chaque palette couvre les 4 statuts en hex valide', () => {
    for (const [cle, p] of Object.entries(PALETTES)) {
        assert.deepEqual(Object.keys(p.statuts).sort(), ['a_venir', 'en_cours', 'inconnu', 'termine'], `palette ${cle}`);
        for (const hex of Object.values(p.statuts)) assert.match(hex, /^#[0-9a-f]{6}$/i);
    }
});

test('applyPalette : mute COLOR en place, revient au defaut sur cle inconnue', () => {
    const avant = { ...COLOR };
    const referenceCOLOR = COLOR;
    assert.equal(applyPalette('herbier'), 'herbier');
    assert.equal(COLOR.en_cours, PALETTES.herbier.statuts.en_cours);
    assert.equal(applyPalette('nimporte'), 'defaut');
    assert.deepEqual({ ...COLOR }, PALETTES.defaut.statuts);
    // COLOR mute en place : les importeurs gardent la même référence
    assert.equal(COLOR, referenceCOLOR);
    assert.equal(applyPalette('defaut'), 'defaut');
    assert.deepEqual({ ...COLOR }, avant);
});
