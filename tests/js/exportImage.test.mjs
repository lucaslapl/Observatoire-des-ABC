import { test } from 'node:test';
import assert from 'node:assert/strict';

import { A4_LANDSCAPE_RATIO, fitRatio } from '../../resources/js/map/exportImage.js';

test('fitRatio : carte large → la hauteur est agrandie au ratio A4 paysage', () => {
    const r = fitRatio(1400, 700, A4_LANDSCAPE_RATIO);
    assert.equal(r.width, 1400);
    assert.equal(r.height, 990);
    assert.ok(Math.abs(r.width / r.height - A4_LANDSCAPE_RATIO) < 0.002);
});

test('fitRatio : carte haute → la largeur est agrandie au ratio A4 paysage', () => {
    const r = fitRatio(800, 1000, A4_LANDSCAPE_RATIO);
    assert.equal(r.width, 1414);
    assert.equal(r.height, 1000);
});

test('fitRatio : déjà au ratio → dimensions inchangées', () => {
    const r = fitRatio(1414, 1000, A4_LANDSCAPE_RATIO);
    assert.equal(r.width, 1414);
    assert.equal(r.height, 1000);
});

test('fitRatio : agrandit uniquement, jamais de perte de zone visible', () => {
    const wide = fitRatio(1400, 700, A4_LANDSCAPE_RATIO);
    assert.ok(wide.width >= 1400 && wide.height >= 700);
    const tall = fitRatio(800, 1000, A4_LANDSCAPE_RATIO);
    assert.ok(tall.width >= 800 && tall.height >= 1000);
});
