import assert from 'node:assert/strict';
import { resolve } from 'node:path';
import test from 'node:test';
import { renderToStaticMarkup } from 'react-dom/server';

import { configure, findElement, loadComponent, stateChanges } from './component-harness.mjs';
import { visitInputValue, visitLocalTime, withVisitOffset, visitErrorLabel } from '../../resources/js/lib/visit-time.ts';

const { VisitRescheduleAction } = await import(loadComponent(resolve('resources/js/components/visit-reschedule-action.tsx')));
const { default: VisitIndex } = await import(loadComponent(resolve('resources/js/pages/work-orders/visits/index.tsx')));
const { VisitFormErrors } = await import(loadComponent(resolve('resources/js/components/visit-form-errors.tsx')));
const visit = {
    id: 9, visit_no: 1, starts_at: '2026-11-12T02:00:00Z', ends_at: '2026-11-12T04:00:00Z', zone_name: 'Asia/Jakarta',
    status: 'confirmed', reason: null, previous_visit_no: null, successor_visit_no: null, lock_version: 1,
    confirm_url: null, command_key: null, bookings: [], active_technician_ids: [3, 7],
    reschedule_url: '/work-orders/8/visits/9/reschedule', reschedule_command_key: '11111111-1111-4111-8111-111111111111',
};
const props = { visit, technicians: [{ id: 3, name: 'Teknisi A' }, { id: 4, name: 'Teknisi B' }], refreshUrl: '/work-orders/8/visits' };
const named = (tree, name) => findElement(tree, node => node.props.name === name);
const formElement = tree => findElement(tree, node => node.type === 'form');
const event = { preventDefault() {} };

test('reschedule prefills source timezone wall times eligible active participants and observed version', () => {
    const form = configure();
    const tree = VisitRescheduleAction(props);
    assert.equal(named(tree, 'starts_at').props.value, '2026-11-12T09:00');
    assert.equal(named(tree, 'ends_at').props.value, '2026-11-12T11:00');
    assert.equal(form.data.starts_at, '2026-11-12T09:00+07:00');
    assert.equal(named(tree, 'zone_name').props.value, 'Asia/Jakarta');
    assert.deepEqual(form.data.technician_ids, [3]);
    assert.equal(form.data.expected_visit_version, 1);
    assert.equal(form.data.command_key, visit.reschedule_command_key);
    assert.equal(form.data.reason, '');
    assert.equal(named(tree, 'reason').props.required, true);
    assert.equal(named(tree, 'reason').props.maxLength, 10000);
});

test('cross-day edits and zone changes submit one replacement payload with a stable retry key', () => {
    const form = configure();
    const tree = VisitRescheduleAction(props);
    named(tree, 'starts_at').props.onChange({ target: { value: '2026-11-12T23:30' } });
    named(tree, 'ends_at').props.onChange({ target: { value: '2026-11-13T01:00' } });
    named(tree, 'zone_name').props.onChange({ target: { value: 'Asia/Makassar' } });
    named(tree, 'reason').props.onChange({ target: { value: 'Customer requests another slot' } });
    formElement(tree).props.onSubmit(event);
    formElement(tree).props.onSubmit(event);
    assert.equal(form.calls.length, 1);
    assert.equal(form.calls[0][1], visit.reschedule_url);
    assert.equal(form.calls[0][2].errorBag, 'reschedule-visit-9');
    assert.deepEqual(form.data, {
        command_key: visit.reschedule_command_key, expected_visit_version: 1,
        starts_at: '2026-11-12T23:30+08:00', ends_at: '2026-11-13T01:00+08:00', zone_name: 'Asia/Makassar',
        technician_ids: [3], reason: 'Customer requests another slot',
    });
    const before = structuredClone(form.data);
    form.calls[0][2].onFinish();
    formElement(tree).props.onSubmit(event);
    assert.equal(form.calls.length, 2);
    assert.deepEqual(form.data, before);
});

test('empty reason empty selection missing permission and processing prevent submission', () => {
    for (const scenario of ['reason', 'participants', 'permission', 'processing']) {
        const form = configure({ processing: scenario === 'processing' });
        const tree = VisitRescheduleAction({ ...props, visit: scenario === 'permission' ? { ...visit, reschedule_url: null } : visit });
        if (scenario !== 'reason') named(tree, 'reason').props.onChange({ target: { value: 'Required explanation' } });
        if (scenario === 'participants') named(tree, 'technician_ids[]').props.onChange({ target: { checked: false } });
        formElement(tree).props.onSubmit(event);
        assert.deepEqual(form.calls, [], scenario);
        if (scenario === 'processing') {
            assert.equal(named(tree, 'reason').props.disabled, true);
            assert.equal(findElement(tree, node => node.props.type === 'submit').props.disabled, true);
        }
    }
});

test('dialog keeps input on cancellation or failed requests and cannot close while submitting', () => {
    const form = configure();
    const tree = VisitRescheduleAction(props);
    named(tree, 'reason').props.onChange({ target: { value: 'Access unavailable' } });
    const before = stateChanges.length;
    tree.props.onOpenChange(true);
    formElement(tree).props.onSubmit(event);
    tree.props.onOpenChange(false);
    assert.equal(stateChanges.length, before + 1);
    form.calls[0][2].onFinish();
    tree.props.onOpenChange(false);
    assert.equal(stateChanges.at(-1), false);
    assert.equal(form.data.reason, 'Access unavailable');
    assert.equal(form.data.command_key, visit.reschedule_command_key);
    assert.ok(findElement(tree, node => node.props.className?.includes('max-h-[calc(100dvh-2rem)]')));
});

test('server conflicts stay actionable and participant errors are connected to the fieldset', () => {
    configure({ errors: { expected_visit_version: 'VISIT_VERSION_CONFLICT', 'technician_ids.0': 'Pilih anggota eligible.' } });
    const tree = VisitRescheduleAction(props);
    const errors = findElement(tree, node => node.type === VisitFormErrors);
    const html = renderToStaticMarkup(VisitFormErrors(errors.props));
    assert.ok(html.includes('Data kunjungan sudah berubah'));
    assert.ok(html.includes('Muat ulang data'));
    const fieldset = findElement(tree, node => node.type === 'fieldset' && node.props['aria-invalid']);
    assert.match(fieldset.props['aria-describedby'], /reschedule-9-participants-error/);
    assert.equal(findElement(tree, node => node.props.id === 'reschedule-9-participants-error').props.children, 'Pilih anggota eligible.');
    assert.match(visitErrorLabel('VISIT_RESCHEDULE_NO_CHANGES'), /Ubah waktu atau peserta/);
    assert.match(visitErrorLabel('VISIT_RESCHEDULE_NOT_ALLOWED'), /sudah mulai/);
});

test('index renders server-authorized action and successor lineage and preserves observed form identity after a stale response', () => {
    const page = {
        workOrder: { id: 8, number: 'WO-008', title: 'Camera', status: 'assigned', customer: null, site: null },
        members: [], technicians: props.technicians, unavailableReason: null,
        links: { index: '/work-orders', refresh: props.refreshUrl, create: null },
        visits: { data: [visit], total: 1, current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null },
    };
    const before = findElement(VisitIndex(page), node => node.type === VisitRescheduleAction);
    assert.ok(before);
    const changed = findElement(VisitIndex({ ...page, visits: { ...page.visits, data: [{ ...visit, lock_version: 2 }] } }), node => node.type === VisitRescheduleAction);
    assert.equal(before.key, changed.key);
    const readOnly = VisitIndex({ ...page, visits: { ...page.visits, data: [{ ...visit, status: 'superseded', successor_visit_no: 2, reschedule_url: null }] } });
    assert.equal(findElement(readOnly, node => node.type === VisitRescheduleAction), undefined);
    assert.ok(renderToStaticMarkup(readOnly).includes('Diganti oleh kunjungan 2'));
});

test('IANA source values round-trip independently of browser zone and reject ambiguous DST edits', () => {
    assert.equal(visitLocalTime('2026-11-12T17:30:00Z', 'Asia/Jakarta'), '2026-11-13T00:30');
    assert.equal(visitInputValue('2026-11-12T02:00:00Z', 'Asia/Jayapura'), '2026-11-12T11:00+09:00');
    assert.equal(withVisitOffset('2026-11-12T09:00', 'UTC'), '2026-11-12T09:00+00:00');
    assert.equal(withVisitOffset('2026-07-12T09:00', 'America/New_York'), '2026-07-12T09:00-04:00');
    assert.throws(() => withVisitOffset('2026-03-08T02:30', 'America/New_York'), RangeError);
    assert.throws(() => withVisitOffset('2026-11-01T01:30', 'America/New_York'), RangeError);
    const form = configure();
    const tree = VisitRescheduleAction({ ...props, visit: { ...visit, zone_name: 'America/New_York' } });
    named(tree, 'starts_at').props.onChange({ target: { value: '2026-03-08T02:30' } });
    named(tree, 'reason').props.onChange({ target: { value: 'Move appointment' } });
    assert.match(form.errors.starts_at, /ambigu/);
    assert.equal(form.data.starts_at, '2026-03-08T02:30');
    formElement(tree).props.onSubmit(event);
    assert.deepEqual(form.calls, []);
});

test('a participant revoked during a failed request can be removed explicitly without discarding the schedule', () => {
    const form = configure();
    const tree = VisitRescheduleAction(props);
    named(tree, 'reason').props.onChange({ target: { value: 'Keep these edits' } });
    const updated = VisitRescheduleAction({ ...props, technicians: [{ id: 4, name: 'Teknisi B' }] });
    const remove = findElement(updated, node => node.props.children === 'Hapus peserta yang tidak lagi eligible');
    assert.ok(remove);
    remove.props.onClick();
    assert.deepEqual(form.data.technician_ids, []);
    assert.equal(form.data.reason, 'Keep these edits');
    assert.equal(form.data.starts_at, '2026-11-12T09:00+07:00');
    assert.equal(form.data.expected_visit_version, 1);
});
