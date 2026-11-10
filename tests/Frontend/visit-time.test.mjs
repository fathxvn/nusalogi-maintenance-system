import assert from 'node:assert/strict';
import test from 'node:test';

import { withVisitOffset, visitDateLabel, visitErrorLabel } from '../../resources/js/lib/visit-time.ts';

test('schedule payload preserves wall time with an explicit Indonesian timezone offset', () => {
    assert.equal(withVisitOffset('2026-11-12T09:00', 'Asia/Jakarta'), '2026-11-12T09:00+07:00');
    assert.equal(withVisitOffset('2026-11-12T09:00', 'Asia/Makassar'), '2026-11-12T09:00+08:00');
    assert.equal(withVisitOffset('2026-11-12T09:00', 'Asia/Jayapura'), '2026-11-12T09:00+09:00');
    assert.equal(withVisitOffset('', 'Asia/Jakarta'), '');
    assert.throws(() => withVisitOffset('2026-11-12T09:00', 'not-a-zone'), RangeError);
    assert.throws(() => withVisitOffset('12 November 2026', 'Asia/Jakarta'), RangeError);
});

test('cross-midnight schedules carry both dates without browser timezone conversion', () => {
    assert.equal(withVisitOffset('2026-11-12T23:30', 'Asia/Jakarta'), '2026-11-12T23:30+07:00');
    assert.equal(withVisitOffset('2026-11-13T01:00', 'Asia/Jakarta'), '2026-11-13T01:00+07:00');
    assert.match(visitDateLabel('2026-11-12T17:30:00Z', 'Asia/Jakarta'), /13 Nov 2026.*00[.:]30/);
});

test('display uses the visit timezone and keeps invalid legacy values readable', () => {
    assert.match(visitDateLabel('2026-11-12T02:00:00Z', 'Asia/Jakarta'), /12 Nov 2026.*09[.:]00/);
    assert.match(visitDateLabel('2026-11-12T02:00:00Z', 'Asia/Makassar'), /12 Nov 2026.*10[.:]00/);
    assert.equal(visitDateLabel('invalid', 'Asia/Jakarta'), 'Waktu tidak valid');
    assert.equal(visitDateLabel('2026-11-12T02:00:00Z', 'invalid'), 'Waktu tidak valid');
});

test('conflict messages explain recovery and retain ordinary validation messages', () => {
    assert.match(visitErrorLabel('BOOKING_TIME_CONFLICT'), /Tidak ada peserta yang dibooking/);
    assert.match(visitErrorLabel('VISIT_VERSION_CONFLICT'), /Muat ulang/);
    assert.equal(visitErrorLabel('Alasan wajib diisi.'), 'Alasan wajib diisi.');
});
