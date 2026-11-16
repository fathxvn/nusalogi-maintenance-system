import assert from 'node:assert/strict';
import { resolve } from 'node:path';
import test from 'node:test';
import { renderToStaticMarkup } from 'react-dom/server';

import { configure, findElement, loadComponent, stateChanges } from './component-harness.mjs';
import { visitErrorLabel } from '../../resources/js/lib/visit-time.ts';

const { VisitBookingExecutionAction: Action } = await import(loadComponent(resolve('resources/js/components/visit-booking-execution-action.tsx')));
const { default: VisitIndex } = await import(loadComponent(resolve('resources/js/pages/work-orders/visits/index.tsx')));
const { VisitFormErrors } = await import(loadComponent(resolve('resources/js/components/visit-form-errors.tsx')));
const key = '11111111-1111-4111-8111-111111111111';
const booking = {
    id: 4, technician_name: 'Teknisi A', status: 'confirmed', ended_reason: null, cancel_url: null, command_key: null,
    actual_start_at: null, actual_end_at: null, execution_action: 'start',
    execution_url: '/work-orders/8/visits/9/bookings/4/start', execution_command_key: key,
};
const props = { booking, visitVersion: 1, refreshUrl: '/work-orders/8/visits' };
const formElement = tree => findElement(tree, node => node.type === 'form');
const submitButton = tree => findElement(tree, node => node.props.type === 'submit');
const event = { preventDefault() {} };
const complete = { ...booking, status: 'in_progress', execution_action: 'complete', execution_url: '/work-orders/8/visits/9/bookings/4/complete' };

for (const [action, current, version, label] of [
    ['start', booking, 1, 'Mulai bekerja'], ['complete', complete, 2, 'Selesaikan sesi'],
]) {
    test(`${action} confirms before POST and submits observed state only once with identical retries`, () => {
        const form = configure();
        const tree = Action({ ...props, booking: current, visitVersion: version });
        assert.equal(tree.props.open, false);
        assert.deepEqual(form.calls, []);
        assert.equal(submitButton(tree).props.children, label);
        tree.props.onOpenChange(true);
        assert.equal(stateChanges.at(-1), true);
        formElement(tree).props.onSubmit(event);
        formElement(tree).props.onSubmit(event);
        assert.equal(form.calls.length, 1);
        assert.equal(form.calls[0][1], current.execution_url);
        assert.equal(form.calls[0][2].preserveScroll, true);
        assert.equal(form.calls[0][2].errorBag, 'execute-booking-4');
        assert.deepEqual(form.data, { command_key: key, expected_visit_version: version, expected_booking_status: current.status });
        const before = stateChanges.length;
        tree.props.onOpenChange(false);
        assert.equal(stateChanges.length, before);
        form.calls[0][2].onFinish();
        assert.equal(stateChanges.length, before); // A failed visit does not close the dialog.
        const changed = Action({ ...props, booking: { ...current, execution_command_key: 'new-server-key' }, visitVersion: 99 });
        formElement(changed).props.onSubmit(event);
        assert.equal(form.calls.length, 2);
        assert.deepEqual(form.data, { command_key: key, expected_visit_version: version, expected_booking_status: current.status });
        form.calls[1][2].onSuccess();
        assert.equal(stateChanges.at(-1), false);
    });
}

test('processing disables actions and dismiss and suppresses POST', () => {
    const form = configure({ processing: true });
    const tree = Action(props);
    assert.equal(submitButton(tree).props.disabled, true);
    assert.equal(submitButton(tree).props['aria-busy'], true);
    assert.equal(submitButton(tree).props.children, 'Menyimpan…');
    const dialog = findElement(tree, node => node.props.showCloseButton === false);
    assert.ok(dialog);
    assert.equal(findElement(tree, node => node.props.children === 'Kembali').props.disabled, true);
    const trigger = findElement(tree, node => node.props.render);
    assert.equal(trigger.props.render.props.disabled, true);
    const before = stateChanges.length;
    tree.props.onOpenChange(false);
    assert.equal(stateChanges.length, before);
    formElement(tree).props.onSubmit(event);
    assert.deepEqual(form.calls, []);
});

test('missing action URL or command key cannot submit', () => {
    for (const field of ['execution_action', 'execution_url', 'execution_command_key']) {
        const form = configure();
        const tree = Action({ ...props, booking: { ...booking, [field]: null } });
        formElement(tree).props.onSubmit(event);
        assert.deepEqual(form.calls, [], field);
        assert.equal(submitButton(tree).props.disabled, true);
    }
});

test('conflict errors retain dialog and offer translated recovery and completion explains closure requirements', () => {
    for (const code of ['TECHNICIAN_ALREADY_WORKING', 'BOOKING_ACTUAL_TIME_CONFLICT', 'VISIT_VERSION_CONFLICT']) {
        configure({ errors: { booking_id: code } });
        const tree = Action({ ...props, booking: complete });
        const errors = findElement(tree, node => node.type === VisitFormErrors);
        const html = renderToStaticMarkup(VisitFormErrors(errors.props));
        assert.ok(html.includes(visitErrorLabel(code)));
        assert.ok(!html.includes(code));
        assert.ok(html.includes(`href="${props.refreshUrl}"`));
        assert.ok(html.includes('Muat ulang data'));
        const description = findElement(tree, node => typeof node.props.children === 'string' && node.props.children.includes('acceptance'));
        assert.match(description.props.children, /laporan, review, dan acceptance sebelum closure/);
    }
});

const visit = {
    id: 9, visit_no: 1, starts_at: '2026-11-14T02:00:00Z', ends_at: '2026-11-14T04:00:00Z', zone_name: 'Asia/Jayapura',
    status: 'in_progress', reason: null, previous_visit_no: null, successor_visit_no: null, lock_version: 1,
    confirm_url: null, command_key: null, reschedule_url: null, reschedule_command_key: null, active_technician_ids: [], bookings: [booking],
};
const page = {
    workOrder: { id: 8, number: 'WO-008', title: 'Camera', status: 'in_progress', customer: null, site: null },
    members: [], technicians: [], unavailableReason: null,
    links: { index: '/work-orders', refresh: props.refreshUrl, create: null },
    visits: { data: [visit], total: 1, current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null },
};
const pageWith = (current, version = 1) => VisitIndex({ ...page, visits: { ...page.visits, data: [{ ...visit, lock_version: version, bookings: [current] }] } });

test('index renders only server-permitted execution and preserves form identity on stale versions', () => {
    const before = findElement(pageWith(booking), node => node.type === Action);
    assert.ok(before);
    assert.equal(before.props.visitVersion, 1);
    assert.equal(before.props.refreshUrl, props.refreshUrl);
    const changed = findElement(pageWith({ ...booking, execution_command_key: 'changed-key' }, 2), node => node.type === Action);
    assert.equal(before.key, changed.key);
    const nextAction = findElement(pageWith(complete, 2), node => node.type === Action);
    assert.notEqual(before.key, nextAction.key);
    for (const field of ['execution_action', 'execution_url', 'execution_command_key']) {
        assert.equal(findElement(pageWith({ ...booking, [field]: null }), node => node.type === Action), undefined);
    }
});

test('index renders actual times in Visit zone and completed bookings have no action', () => {
    const tree = pageWith({ ...booking, status: 'completed', execution_action: null, execution_url: null, execution_command_key: null,
        actual_start_at: '2026-11-14T02:07:00Z', actual_end_at: '2026-11-14T03:40:00Z' });
    const html = renderToStaticMarkup(tree);
    assert.match(html, /Mulai aktual:.*11[.:]07/);
    assert.match(html, /Selesai aktual:.*12[.:]40/);
    assert.ok(html.includes('Asia/Jayapura'));
    assert.equal(findElement(tree, node => node.type === Action), undefined);
    const pendingHtml = renderToStaticMarkup(pageWith({ ...booking, execution_action: null }));
    assert.ok(!pendingHtml.includes('Mulai aktual:'));
    assert.ok(!pendingHtml.includes('Selesai aktual:'));
});
