import assert from 'node:assert/strict';
import { test } from 'node:test';

import { pageLink, pageWindow } from '../../resources/js/utils/pagination.js';

test('pageLink remplace le numéro de page d’un paginateur Inertia (format toArray)', () => {
    const paginator = {
        current_page: 2,
        last_page: 5,
        links: [
            { url: null, label: '«', active: false },
            { url: 'https://example.test/admin/contributions?statut=validee&page=1', label: '1', active: false },
            { url: 'https://example.test/admin/contributions?statut=validee&page=2', label: '2', active: true },
        ],
    };

    assert.equal(pageLink(paginator, 3), '/admin/contributions?statut=validee&page=3');
    assert.equal(pageLink(paginator, 1), '/admin/contributions?statut=validee&page=1');
});

test('pageLink gère un nom de paramètre personnalisé (vpage/fpage)', () => {
    const paginator = {
        links: [{ url: 'https://example.test/admin/journaux?vpage=1&fpage=3', label: '1', active: true }],
    };

    assert.equal(pageLink(paginator, 7, 'vpage'), '/admin/journaux?vpage=7&fpage=3');
});

test('pageLink gère le format links objet (first/last)', () => {
    const paginator = { links: { first: 'https://example.test/admin/comptes?q=a&page=1' } };
    assert.equal(pageLink(paginator, 4), '/admin/comptes?q=a&page=4');
});

test('pageLink renvoie # sans gabarit d’URL', () => {
    assert.equal(pageLink({}, 2), '#');
    assert.equal(pageLink(null, 2), '#');
});

test('pageWindow : listes courtes affichées en entier', () => {
    assert.deepEqual(pageWindow(1, 1), [1]);
    assert.deepEqual(pageWindow(3, 7), [1, 2, 3, 4, 5, 6, 7]);
});

test('pageWindow : listes longes resserrées autour de la page courante', () => {
    assert.deepEqual(pageWindow(1, 20), [1, 2, '…', 20]);
    assert.deepEqual(pageWindow(10, 20), [1, '…', 9, 10, 11, '…', 20]);
    assert.deepEqual(pageWindow(20, 20), [1, '…', 19, 20]);
});

test('pageWindow : entrées invalides', () => {
    assert.deepEqual(pageWindow(0, 0), []);
    assert.deepEqual(pageWindow(undefined, 5), []);
});
