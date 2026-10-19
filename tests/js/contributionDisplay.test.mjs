import { test } from 'node:test';
import assert from 'node:assert/strict';

import { parsePayload, payloadLines } from '../../resources/js/utils/contributionDisplay.js';

test('parsePayload : objet passe tel quel', () => {
    const p = { note: 'x' };
    assert.equal(parsePayload(p), p);
});

test('parsePayload : chaine JSON decodee, invalide -> objet vide', () => {
    assert.deepEqual(parsePayload('{"statut_suggere":"termine"}'), { statut_suggere: 'termine' });
    assert.deepEqual(parsePayload('pas du json'), {});
    assert.deepEqual(parsePayload(null), {});
});

test('payloadLines : statut, dates, source, note, lien, texte', () => {
    const lines = payloadLines({
        statut_suggere: 'termine',
        annee_debut_suggeree: 2021,
        annee_fin_suggeree: 2023,
        source: 'Mairie',
        note: 'n',
        lien: 'https://ex.fr',
        texte: 't',
    });
    assert.deepEqual(lines, [
        { label: 'Statut suggéré', value: 'Terminé' },
        { label: 'Dates suggérées', value: '2021–2023' },
        { label: 'Source', value: 'Mairie' },
        { label: 'Note', value: 'n' },
        { label: 'Lien', value: 'https://ex.fr', link: true },
        { label: 'Texte', value: 't' },
    ]);
});

test('payloadLines : dates sans annee de fin', () => {
    assert.deepEqual(payloadLines({ annee_debut_suggeree: 2022 }), [
        { label: 'Dates suggérées', value: '2022' },
    ]);
});

test('payloadLines : payload vide -> aucune ligne', () => {
    assert.deepEqual(payloadLines({}), []);
    assert.deepEqual(payloadLines(null), []);
});
