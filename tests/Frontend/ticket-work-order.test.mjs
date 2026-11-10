import assert from 'node:assert/strict';
import { resolve } from 'node:path';
import test from 'node:test';
import { renderToStaticMarkup } from 'react-dom/server';

import { configure, findElement, loadComponent } from './component-harness.mjs';

const { default: CreateWorkOrder } = await import(loadComponent(resolve('resources/js/pages/work-orders/create.tsx')));
const { default: ShowTicket } = await import(loadComponent(resolve('resources/js/pages/tickets/show.tsx')));
const props = {
    sites: [{ id: 3, customer_name: 'PT Testing', site_name: 'Office', assets: [] }],
    serviceTypes: { install: 'Instalasi', maintenance: 'Maintenance' },
    priorities: { medium: 'Sedang' },
    scheduleTimezone: 'UTC',
    links: { index: '/work-orders', store: '/work-orders/m1' },
};
const m1 = {
    command_key: '11111111-1111-4111-8111-111111111111',
    ticket_id: null, ticket_version: null, site_id: null, ticket_number: null,
};

test('manual WO form sends a stable command key and explicit initial scope', () => {
    const form = configure();
    const tree = CreateWorkOrder({ ...props, m1 });

    assert.equal(form.initial.command_key, m1.command_key);
    assert.equal(form.initial.origin_kind, 'manual');
    assert.deepEqual(form.initial.initial_scope, { description: '', acceptance_criteria: '' });
    const reason = findElement(tree, element => element.props.name === 'manual_reason');
    assert.equal(reason.props.required, true);
    tree.props.onSubmit({ preventDefault() {} });
    assert.deepEqual(form.calls, [['post', '/work-orders/m1']]);
    assert.equal(form.data.command_key, m1.command_key);
});

test('ticket WO form uses its ticket version and holds the site selection', () => {
    const form = configure();
    const tree = CreateWorkOrder({
        ...props,
        m1: { ...m1, ticket_id: 9, ticket_version: 2, site_id: 3, ticket_number: 'TKT-9' },
    });

    assert.equal(form.initial.origin_kind, 'ticket');
    assert.equal(form.initial.expected_ticket_version, 2);
    assert.equal(form.initial.customer_site_id, '3');
    assert.equal(findElement(tree, element => element.props.name === 'customer_site_id').props.disabled, true);
    assert.equal(findElement(tree, element => element.props.name === 'manual_reason'), undefined);
});

test('scope validation errors remain visible and a processing form keeps its command key', () => {
    configure({
        processing: true,
        errors: { 'initial_scope.acceptance_criteria': 'Isi kriteria penerimaan.' },
    });
    const tree = CreateWorkOrder({ ...props, m1 });
    const html = renderToStaticMarkup(tree);

    assert.ok(html.includes('Isi kriteria penerimaan.'));
    const form = configure({ processing: true });
    const pendingTree = CreateWorkOrder({ ...props, m1 });
    pendingTree.props.onSubmit({ preventDefault() {} });
    assert.deepEqual(form.calls, []);
    assert.equal(form.data.command_key, m1.command_key);
});

test('ticket forms change identity after a committed version and retain it for a retry', () => {
    const ticket = {
        id: 9, ticket_number: 'TKT-9', contact_label: 'PIC', description: 'Camera offline',
        status: 'new', site_id: null, primary_asset_id: null,
        coverage_disposition: 'unknown', authorization_ref: null, lock_version: 0,
    };
    const page = {
        ticket, sites: [], commandKeys: { triage: 'first-key', approve: 'second-key' },
        links: { index: '/tickets', triage: '/tickets/9/triage', approve: null, createWorkOrder: null },
        workOrders: [],
    };
    const initial = ShowTicket(page);
    const retry = ShowTicket({ ...page, commandKeys: { triage: 'fresh-server-key', approve: 'unused' } });
    const committed = ShowTicket({ ...page, ticket: { ...ticket, status: 'triaged', lock_version: 1 } });

    assert.equal(initial.key, retry.key);
    assert.notEqual(initial.key, committed.key);
});
