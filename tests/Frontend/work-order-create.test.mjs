import assert from 'node:assert/strict';
import { resolve } from 'node:path';
import test from 'node:test';
import { renderToStaticMarkup } from 'react-dom/server';

import { configure, findElement, loadComponent } from './component-harness.mjs';

const { default: CreateWorkOrder } = await import(loadComponent(resolve('resources/js/pages/work-orders/create.tsx')));
const { default: WorkOrderIndex } = await import(loadComponent(resolve('resources/js/pages/work-orders/index.tsx')));
const props = {
    sites: [
        { id: 3, customer_name: 'PT Alpha', site_name: 'Office', assets: [{ id: 7, asset_code: 'CAM-007', category: 'cctv' }, { id: 8, asset_code: 'NET-008', category: 'networking' }] },
        { id: 4, customer_name: 'PT Beta', site_name: 'Office', assets: [{ id: 9, asset_code: 'CAM-009', category: 'cctv' }] },
    ],
    serviceTypes: { install: 'Instalasi', maintenance: 'Maintenance' },
    priorities: { low: 'Rendah', medium: 'Sedang', high: 'Tinggi' },
    scheduleTimezone: 'UTC', links: { index: '/work-orders', store: '/work-orders' },
};
const initial = { customer_site_id: '', title: '', description: '', service_type: '', category: '', priority: 'medium', scheduled_at: '', asset_ids: [] };
const input = (tree, name) => findElement(tree, element => element.props.name === name);

// Re-render components after state updates using the existing isolated Inertia harness.
test('create form starts with safe defaults, required fields, schema limits and a visible schedule timezone', () => {
    const form = configure();
    const tree = CreateWorkOrder(props);
    assert.deepEqual(form.initial, initial);
    const html = renderToStaticMarkup(tree);
    for (const text of ['PT Alpha — Office', 'PT Beta — Office', 'Jadwal (UTC)', 'Instalasi', 'Maintenance', 'Rendah', 'Sedang', 'Tinggi', 'Kolom bertanda * wajib diisi.', 'Pekerjaan instalasi dapat dibuat tanpa asset.']) {
        assert.ok(html.includes(text), `Missing form text: ${text}`);
    }
    for (const name of ['customer_site_id', 'title', 'description', 'service_type', 'category', 'priority']) {
        assert.equal(input(tree, name).props.required, true);
    }
    for (const [name, limit] of [['title', 150], ['description', 65535], ['category', 50]]) {
        assert.equal(input(tree, name).props.maxLength, limit);
    }
    assert.equal(input(tree, 'scheduled_at').props.type, 'datetime-local');
    assert.equal(input(tree, 'scheduled_at').props.step, 60);
    for (const name of ['wo_number', 'created_by', 'status', 'verified_by', 'completed_at', 'technician_ids']) {
        assert.equal(input(tree, name), undefined);
    }
    assert.equal(CreateWorkOrder.layout(tree).props.title, 'Tambah Work Order');
});

test('entered form values and selected assets are posted to the supplied endpoint', () => {
    const form = configure();
    let tree = CreateWorkOrder(props);
    const entered = { customer_site_id: '3', title: 'New camera', description: 'Install camera', service_type: 'install', category: 'Custom category', priority: 'high', scheduled_at: '2026-11-15T09:30' };
    for (const [name, value] of Object.entries(entered)) {
        input(tree, name).props.onChange({ target: { value } });
    }
    tree = CreateWorkOrder(props);
    const checkbox = findElement(tree, element => element.props.type === 'checkbox' && element.props.value === 7);
    checkbox.props.onChange({ target: { checked: true } });
    tree.props.onSubmit({ preventDefault() {} });
    assert.deepEqual(form.data, { ...entered, asset_ids: [7] });
    assert.deepEqual(form.calls, [['post', props.links.store]]);
});

test('asset choices are scoped to the selected site and changing site clears prior selections', () => {
    const form = configure({ data: { ...initial, customer_site_id: '3', asset_ids: [7, 8] } });
    let tree = CreateWorkOrder(props);
    let html = renderToStaticMarkup(tree);
    assert.ok(html.includes('CAM-007'));
    assert.ok(html.includes('NET-008'));
    assert.ok(!html.includes('CAM-009'));
    input(tree, 'customer_site_id').props.onChange({ target: { value: '4' } });
    assert.deepEqual(form.data.asset_ids, []);
    tree = CreateWorkOrder(props);
    html = renderToStaticMarkup(tree);
    assert.ok(!html.includes('CAM-007'));
    assert.ok(!html.includes('NET-008'));
    assert.ok(html.includes('CAM-009'));
    assert.equal(findElement(tree, element => element.props.type === 'checkbox').props.checked, false);
});

test('selected assets can be unchecked without affecting other selections', () => {
    const form = configure({ data: { ...initial, customer_site_id: '3', asset_ids: [7, 8] } });
    const tree = CreateWorkOrder(props);
    const checkbox = findElement(tree, element => element.props.type === 'checkbox' && element.props.value === 7);
    assert.equal(checkbox.props.checked, true);
    checkbox.props.onChange({ target: { checked: false } });
    assert.deepEqual(form.data.asset_ids, [8]);
});

test('validation preserves values and connects field and nested asset errors to accessible controls', () => {
    const data = { ...initial, customer_site_id: '3', title: 'Retained title', description: 'Retained complaint', asset_ids: [7] };
    const errors = Object.fromEntries(['customer_site_id', 'title', 'description', 'service_type', 'category', 'priority', 'scheduled_at', 'asset_ids.0'].map(name => [name, `Invalid ${name}`]));
    const form = configure({ data, errors });
    const tree = CreateWorkOrder(props);
    const html = renderToStaticMarkup(tree);
    for (const name of ['customer_site_id', 'title', 'description', 'service_type', 'category', 'priority', 'scheduled_at']) {
        const control = input(tree, name);
        assert.equal(control.props.value, data[name]);
        assert.equal(control.props['aria-invalid'], true);
        assert.equal(control.props['aria-describedby'], `${control.props.id}-error`);
        assert.ok(html.includes(`id="${control.props.id}-error" role="alert"`));
        assert.ok(html.includes(errors[name]));
    }
    const group = findElement(tree, element => element.type === 'fieldset');
    assert.equal(group.props['aria-invalid'], true);
    assert.ok(group.props['aria-describedby'].includes('work-order-assets-error'));
    assert.ok(html.includes('Invalid asset_ids.0'));
    assert.ok(html.includes('id="work-order-assets-error" role="alert"'));
    assert.deepEqual(form.data, data);
});

test('aggregate asset errors are visible and processing disables save and prevents another post', () => {
    const form = configure({ data: { ...initial, customer_site_id: '3' }, processing: true, errors: { asset_ids: 'Select assets from this site.' } });
    const tree = CreateWorkOrder(props);
    const save = findElement(tree, element => element.props.type === 'submit');
    assert.equal(save.props.disabled, true);
    assert.equal(save.props['aria-busy'], true);
    assert.equal(save.props.children, 'Menyimpan…');
    assert.equal(findElement(tree, element => element.props.type === 'checkbox').props.disabled, true);
    assert.ok(renderToStaticMarkup(tree).includes('Select assets from this site.'));
    tree.props.onSubmit({ preventDefault() {} });
    assert.deepEqual(form.calls, []);
});

test('no sites disables save with meaningful guidance while no assets still permits saving', () => {
    const form = configure();
    let tree = CreateWorkOrder({ ...props, sites: [] });
    assert.equal(findElement(tree, element => element.props.type === 'submit').props.disabled, true);
    assert.ok(renderToStaticMarkup(tree).includes('Hubungi Admin'));
    tree.props.onSubmit({ preventDefault() {} });
    assert.deepEqual(form.calls, []);
    configure({ data: { ...initial, customer_site_id: '3' } });
    tree = CreateWorkOrder({ ...props, sites: [{ ...props.sites[0], assets: [] }] });
    assert.equal(findElement(tree, element => element.props.type === 'submit').props.disabled, false);
    assert.ok(renderToStaticMarkup(tree).includes('Work Order tetap dapat disimpan.'));
});

test('cancel is an Inertia link to the supplied index URL and sends no mutation', () => {
    const form = configure();
    const tree = CreateWorkOrder(props);
    const cancel = findElement(tree, element => element.props.children === 'Batal');
    assert.equal(typeof cancel.type, 'function');
    assert.equal(cancel.props.href, props.links.index);
    assert.deepEqual(form.calls, []);
});

test('customer site and asset labels render as escaped text', () => {
    configure({ data: { ...initial, customer_site_id: '3' } });
    const dangerous = '<script>alert(1)</script>';
    const tree = CreateWorkOrder({ ...props, sites: [{ id: 3, site_name: dangerous, customer_name: dangerous, assets: [{ id: 7, asset_code: dangerous, category: dangerous }] }] });
    const html = renderToStaticMarkup(tree);
    assert.ok(!html.includes(dangerous));
    assert.equal(html.split('&lt;script&gt;alert(1)&lt;/script&gt;').length - 1, 4);
});

test('listing shows creation only when the backend supplies an authorized creation URL', () => {
    const workOrders = { data: [], current_page: 1, last_page: 1, total: 0, from: null, to: null, prev_page_url: null, next_page_url: null };
    const tree = WorkOrderIndex({ workOrders, links: { create: '/work-orders/create' } });
    const create = findElement(tree, element => element.props.children === 'Tambah Work Order');
    assert.equal(typeof create.type, 'function');
    assert.equal(create.props.href, '/work-orders/create');
    const forbidden = WorkOrderIndex({ workOrders, links: { create: null } });
    assert.equal(findElement(forbidden, element => element.props.children === 'Tambah Work Order'), undefined);
});
