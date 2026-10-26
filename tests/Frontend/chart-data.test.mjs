import assert from 'node:assert/strict';
import test from 'node:test';
import { distributionPercentages } from '../../resources/js/lib/chart-data.ts';

test('equal thirds display percentages that add up to 100.0%', () => {
    assert.deepEqual(distributionPercentages([1, 1, 1]), [33.4, 33.3, 33.3]);
});
test('unequal counts preserve their actual proportions', () => {
    assert.deepEqual(distributionPercentages([1, 2]), [33.3, 66.7]);
});
test('an empty distribution has no fabricated percentages', () => {
    assert.deepEqual(distributionPercentages([]), []);
    assert.deepEqual(distributionPercentages([0, 0]), [0, 0]);
});
test('one populated status accounts for the full distribution', () => {
    assert.deepEqual(distributionPercentages([0, 7, 0]), [0, 100, 0]);
});
