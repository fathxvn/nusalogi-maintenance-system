import assert from 'node:assert/strict';
import { resolve } from 'node:path';
import test from 'node:test';
import { renderToStaticMarkup } from 'react-dom/server';

import { configure, findElement, loadComponent } from './component-harness.mjs';

const { default: VisitIndex } = await import(loadComponent(resolve('resources/js/pages/work-orders/visits/index.tsx')));
const { VisitDraftForm } = await import(loadComponent(resolve('resources/js/pages/work-orders/visits/create.tsx')));
const { VisitConfirmForm } = await import(loadComponent(resolve('resources/js/components/visit-confirm-form.tsx')));
const { VisitBookingCancelAction } = await import(loadComponent(resolve('resources/js/components/visit-booking-cancel-action.tsx')));
const { default: WorkOrderIndex } = await import(loadComponent(resolve('resources/js/pages/work-orders/index.tsx')));

const key = '11111111-1111-4111-8111-111111111111';
const workOrder = { id: 8, number: 'WO-008', title: 'Periksa kamera', status: 'assigned', customer: 'PT Contoh', site: 'Bekasi' };
const visit = {
    id: 9, visit_no: 1, starts_at: '2026-11-12T02:00:00Z', ends_at: '2026-11-12T04:00:00Z', zone_name: 'Asia/Jakarta',
    status: 'draft', reason: null, previous_visit_no: null, lock_version: 0, confirm_url: '/work-orders/8/visits/9/confirm', command_key: key, bookings: [],
};
const technician = { id: 3, name: 'Teknisi A' };
const pagination = { data: [], current_page: 1, last_page: 1, total: 0, from: null, to: null, prev_page_url: null, next_page_url: null };
const links = { index: '/work-orders', refresh: '/work-orders/8/visits', create: null };
const readProps = {
    workOrder, members: [technician], technicians: [], unavailableReason: null, links,
    visits: { ...pagination, total: 1, data: [{ ...visit, confirm_url: null, command_key: null }] },
};
const draftProps = { workOrder, commandKey: key, links: { index: links.refresh, store: '/work-orders/8/visits' } };
const element = (tree, name) => findElement(tree, candidate => candidate.props.name === name);

test('read-only visit page renders independent WO status and visit timezone without write actions', () => {
    const tree = VisitIndex(readProps);
    const html = renderToStaticMarkup(tree);
    for (const text of ['WO-008', 'Periksa kamera', 'Teknisi A', 'Ditugaskan', 'Draft', 'Asia/Jakarta']) assert.ok(html.includes(text), text);
    assert.match(html, /09[.:]00/);
    assert.match(html, /11[.:]00/);
    assert.equal(findElement(tree, candidate => candidate.type === VisitConfirmForm), undefined);
    assert.equal(findElement(tree, candidate => candidate.type === VisitBookingCancelAction), undefined);
    assert.equal(findElement(tree, candidate => candidate.props.href?.includes('/visits/create')), undefined);
});

test('empty visit page offers only the server-supplied creation URL', () => {
    const tree = VisitIndex({ ...readProps, visits: pagination, links: { ...links, create: '/work-orders/8/visits/create' } });
    assert.ok(renderToStaticMarkup(tree).includes('Belum ada kunjungan'));
    assert.equal(findElement(tree, candidate => candidate.props.href === '/work-orders/8/visits/create').props.href, '/work-orders/8/visits/create');
});

test('stale errors remain visible after the affected action disappears or the dialog unmounts', () => {
    const tree = VisitIndex({ ...readProps, errors: { 'confirm-visit-9': { expected_visit_version: 'VISIT_VERSION_CONFLICT' } } });
    const html = renderToStaticMarkup(tree);
    assert.ok(html.includes('Data kunjungan sudah berubah'));
    assert.ok(html.includes('Muat ulang data'));
    assert.ok(!html.includes('VISIT_VERSION_CONFLICT'));
});

test('visit names notes and cancellation reasons are escaped', () => {
    const danger = '<script>alert(1)</script>';
    const html = renderToStaticMarkup(VisitIndex({
        ...readProps, workOrder: { ...workOrder, title: danger },
        visits: { ...pagination, total: 1, data: [{ ...visit, confirm_url: null, reason: danger, bookings: [
            { id: 4, technician_name: danger, status: 'cancelled', ended_reason: danger, cancel_url: null, command_key: null },
        ] }] },
    }));
    assert.ok(!html.includes(danger));
    assert.ok(html.includes('&lt;script&gt;'));
});

test('draft form preserves wall times and request identity across retry and zone changes', () => {
    const form = configure();
    const tree = VisitDraftForm(draftProps);
    element(tree, 'starts_at').props.onChange({ target: { value: '2026-11-12T23:30' } });
    element(tree, 'ends_at').props.onChange({ target: { value: '2026-11-13T01:00' } });
    element(tree, 'zone_name').props.onChange({ target: { value: 'Asia/Makassar' } });
    assert.equal(form.data.starts_at, '2026-11-12T23:30+08:00');
    assert.equal(form.data.ends_at, '2026-11-13T01:00+08:00');
    assert.equal(form.data.zone_name, 'Asia/Makassar');
    assert.equal(form.data.command_key, key);
    assert.equal(form.data.technician_ids, undefined);
    tree.props.onSubmit({ preventDefault() {} });
    tree.props.onSubmit({ preventDefault() {} });
    assert.equal(form.calls.length, 1);
    assert.equal(form.calls[0][1], draftProps.links.store);
    form.calls[0][2].onFinish();
    tree.props.onSubmit({ preventDefault() {} });
    assert.equal(form.calls.length, 2);
    assert.equal(form.data.command_key, key);
});

test('processing draft suppresses another request and disables submit', () => {
    const form = configure({ processing: true });
    const tree = VisitDraftForm(draftProps);
    tree.props.onSubmit({ preventDefault() {} });
    assert.deepEqual(form.calls, []);
    assert.equal(findElement(tree, candidate => candidate.props.type === 'submit').props.disabled, true);
});

test('confirmation submits selected membership IDs with the observed visit version only once', () => {
    const form = configure();
    const tree = VisitConfirmForm({ visit, technicians: [technician], refreshUrl: links.refresh });
    element(tree, 'technician_ids[]').props.onChange({ target: { checked: true } });
    assert.deepEqual(form.data.technician_ids, [3]);
    assert.equal(form.data.expected_visit_version, 0);
    const confirm = findElement(tree, candidate => candidate.type === 'form');
    confirm.props.onSubmit({ preventDefault() {} });
    confirm.props.onSubmit({ preventDefault() {} });
    assert.equal(form.calls.length, 1);
    assert.equal(form.calls[0][1], visit.confirm_url);
    assert.equal(form.calls[0][2].errorBag, 'confirm-visit-9');
    assert.equal(form.data.command_key, key);
});

test('confirmation with no eligible participants does not submit', () => {
    const form = configure();
    const tree = VisitConfirmForm({ visit, technicians: [], refreshUrl: links.refresh });
    findElement(tree, candidate => candidate.type === 'form').props.onSubmit({ preventDefault() {} });
    assert.deepEqual(form.calls, []);
    assert.ok(renderToStaticMarkup(tree).includes('Belum ada anggota WO yang eligible'));
});

test('changed visit version creates a fresh confirmation form identity', () => {
    const props = { ...readProps, technicians: [technician], visits: { ...pagination, total: 1, data: [visit] } };
    const before = findElement(VisitIndex(props), candidate => candidate.type === VisitConfirmForm);
    const after = findElement(VisitIndex({ ...props, visits: { ...props.visits, data: [{ ...visit, lock_version: 1 }] } }), candidate => candidate.type === VisitConfirmForm);
    assert.notEqual(before.key, after.key);
});

test('cancellation requires a reason and sends the observed booking state with a stable key', () => {
    const booking = { id: 4, technician_name: 'Teknisi A', status: 'confirmed', ended_reason: null, cancel_url: '/work-orders/8/visits/9/bookings/4/cancel', command_key: key };
    const form = configure();
    const tree = VisitBookingCancelAction({ booking, visitVersion: 2, refreshUrl: links.refresh });
    const cancel = findElement(tree, candidate => candidate.type === 'form');
    cancel.props.onSubmit({ preventDefault() {} });
    assert.deepEqual(form.calls, []);
    assert.equal(element(tree, 'reason').props.required, true);
    element(tree, 'reason').props.onChange({ target: { value: 'Site belum siap' } });
    cancel.props.onSubmit({ preventDefault() {} });
    cancel.props.onSubmit({ preventDefault() {} });
    assert.equal(form.calls.length, 1);
    assert.equal(form.calls[0][1], booking.cancel_url);
    assert.deepEqual(form.data, { command_key: key, expected_visit_version: 2, expected_booking_status: 'confirmed', reason: 'Site belum siap' });
});

test('WO listing retains assignment while adding the supplied visit link', () => {
    const tree = WorkOrderIndex({ workOrders: { ...pagination, total: 1, data: [{ ...workOrder, service_type: 'maintenance', priority: 'medium', technicians: [], scheduled_at: null }] },
        links: { create: null }, assignmentLinks: { 8: '/work-orders/8/assign' }, visitLinks: { 8: links.refresh } });
    assert.ok(findElement(tree, candidate => candidate.props.href === links.refresh));
    assert.ok(findElement(tree, candidate => candidate.props.href === '/work-orders/8/assign'));
});
