import assert from 'node:assert/strict';
import { resolve } from 'node:path';
import test from 'node:test';
import { renderToStaticMarkup } from 'react-dom/server';
import { configure, findElement, loadComponent } from './component-harness.mjs';
const { WorkOrderLifecycleForm: WorkOrderForm } = await import(loadComponent(resolve('resources/js/components/work-order-lifecycle-form.tsx')));
const { TicketLifecycleForm: TicketForm } = await import(loadComponent(resolve('resources/js/components/ticket-lifecycle-form.tsx')));
const { default: Page } = await import(loadComponent(resolve('resources/js/pages/work-orders/lifecycle.tsx')));
const action = { url: '/command', command_key: '11111111-1111-4111-8111-111111111111' };
const event = { preventDefault() {} };
const formIn = tree => findElement(tree, node => node.type === 'form');
const buttonIn = tree => findElement(tree, node => node.props.type === 'submit');
for (const [label, Component, props, versionKey] of [
    ['WO', WorkOrderForm, { kind: 'hold', context: 'Case A' }, 'expected_work_order_version'],
    ['ticket', TicketForm, { kind: 'resolve', canonicalTickets: [] }, 'expected_ticket_version'],
]) {
    test(`${label} lifecycle preserves observed version and key, prevents duplicates, and exposes processing`, () => {
        const form = configure();
        const options = { action, version: 3, refreshUrl: '/refresh', ...props };
        const empty = Component(options);
        formIn(empty).props.onSubmit(event);
        assert.equal(form.calls.length, 0);
        form.setData(label === 'WO' ? 'reason' : 'note', 'Keputusan berdasarkan bukti');
        const ready = Component(options);
        formIn(ready).props.onSubmit(event);
        formIn(ready).props.onSubmit(event);
        assert.equal(form.calls.length, 1);
        assert.equal(form.data[versionKey], 3);
        assert.equal(form.data.command_key, action.command_key);
        assert.equal(form.calls[0][2].preserveState, 'errors');
        form.calls[0][2].onFinish();
        formIn(ready).props.onSubmit(event);
        assert.equal(form.calls.length, 2);
        const busy = configure({ processing: true });
        const tree = Component(options);
        assert.equal(buttonIn(tree).props.disabled, true);
        assert.equal(buttonIn(tree).props['aria-busy'], true);
        formIn(tree).props.onSubmit(event);
        assert.equal(busy.calls.length, 0);
    });
}
test('closure action remains disabled when required acceptance is incomplete', () => {
    const form = configure();
    const tree = WorkOrderForm({ action, kind: 'close', version: 4, context: 'Case A', refreshUrl: '/refresh', disabled: true });
    assert.equal(buttonIn(tree).props.disabled, true);
    formIn(tree).props.onSubmit(event);
    assert.equal(form.calls.length, 0);
});
test('duplicate ticket uses explicit canonical selection and never preselects a target', () => {
    const form = configure();
    const tree = TicketForm({ action, kind: 'duplicate', version: 2, canonicalTickets: [{ id: 8, ticket_number: 'TKT-8' }], refreshUrl: '/refresh' });
    const select = findElement(tree, node => node.type === 'select');
    assert.equal(select.props.required, true);
    assert.equal(form.data.duplicate_of_id, '');
    select.props.onChange({ target: { value: '8' } });
    assert.equal(form.data.duplicate_of_id, '8');
});
test('lifecycle page exposes readable scope readiness and empty historical state', () => {
    configure();
    const html = renderToStaticMarkup(Page({
        workOrder: { id: 1, number: 'WO-1', title: 'CCTV', status: 'closed', lock_version: 4, closed_at: '2026-11-15T02:00:00Z', context: 'A' },
        scopes: [], history: [], closureEvidence: [{ scope_item_id: 1 }], actions: {},
        links: { reports: '/reports', visits: '/visits', acceptance: '/acceptance', refresh: '/refresh' }, unavailableReason: 'Perlu verifikasi',
    }));
    assert.ok(html.includes('Belum ada scope M1'));
    assert.ok(html.includes('Belum ada perubahan status'));
    assert.ok(html.includes('1 scope wajib'));
    assert.ok(html.includes('Perlu verifikasi'));
});
