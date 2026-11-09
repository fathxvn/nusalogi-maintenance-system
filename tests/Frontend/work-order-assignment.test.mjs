import assert from 'node:assert/strict';
import { resolve } from 'node:path';
import test from 'node:test';
import { renderToStaticMarkup } from 'react-dom/server';

import { configure, findElement, loadComponent } from './component-harness.mjs';

const { default: AssignWorkOrder } = await import(loadComponent(resolve('resources/js/pages/work-orders/assign.tsx')));
const { default: WorkOrderIndex } = await import(loadComponent(resolve('resources/js/pages/work-orders/index.tsx')));
const props = {
    workOrder: { id: 1, number: 'WO-001', title: 'Camera maintenance', customer: 'PT Alpha', site: 'Office', status: 'pending' },
    technicians: [{ id: 3, name: 'Teknisi Satu' }, { id: 4, name: 'Teknisi Dua' }],
    links: { index: '/work-orders', update: '/work-orders/1/assignment' },
};
const select = tree => findElement(tree, element => element.props.name === 'technician_ids');
const submit = tree => findElement(tree, element => element.props.type === 'submit');
const emptyPage = { data: [], current_page: 1, last_page: 1, total: 0, from: null, to: null, prev_page_url: null, next_page_url: null };
const workOrder = { id: 1, number: 'WO-001', title: 'Camera maintenance', customer: null, site: null, service_type: 'maintenance', priority: 'medium', status: 'pending', scheduled_at: null, technicians: [] };

test('assignment form displays work order details, available technicians and an authenticated layout', () => {
    const form = configure();
    const tree = AssignWorkOrder(props);
    const html = renderToStaticMarkup(tree);
    for (const text of ['WO-001', 'Camera maintenance', 'PT Alpha', 'Office', 'pending', 'Teknisi Satu', 'Teknisi Dua', 'Tugaskan Teknisi']) {
        assert.ok(html.includes(text), `Missing assignment content: ${text}`);
    }
    assert.deepEqual(form.initial, { technician_ids: [] });
    assert.equal(select(tree).props.multiple, true);
    assert.equal(select(tree).props.required, true);
    assert.equal(select(tree).props.id, 'assignment-technicians');
    assert.ok(html.includes('for="assignment-technicians"'));
    assert.ok(html.includes('id="assignment-technicians-hint"'));
    assert.equal(AssignWorkOrder.layout(tree).props.title, 'Tugaskan Teknisi');
});

test('multi-selection sends selected integer IDs through PATCH to the provided assignment URL', () => {
    const form = configure();
    let tree = AssignWorkOrder(props);
    select(tree).props.onChange({ target: { selectedOptions: [{ value: '3' }, { value: '4' }] } });
    tree = AssignWorkOrder(props);
    assert.deepEqual(select(tree).props.value, ['3', '4']);
    assert.equal(submit(tree).props.disabled, false);
    let prevented = false;
    tree.props.onSubmit({ preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.deepEqual(form.data, { technician_ids: [3, 4] });
    assert.deepEqual(form.calls, [['patch', props.links.update]]);
});

test('empty selection disables submit and clearing a selection updates form state', () => {
    const form = configure({ data: { technician_ids: [3, 4] } });
    let tree = AssignWorkOrder(props);
    select(tree).props.onChange({ target: { selectedOptions: [] } });
    tree = AssignWorkOrder(props);
    assert.deepEqual(form.data.technician_ids, []);
    assert.equal(submit(tree).props.disabled, true);
    tree.props.onSubmit({ preventDefault() {} });
    assert.deepEqual(form.calls, []);
});

test('no eligible technicians gives actionable guidance and prevents assignment even with stale selections', () => {
    const form = configure({ data: { technician_ids: [3] } });
    const tree = AssignWorkOrder({ ...props, technicians: [] });
    const html = renderToStaticMarkup(tree);
    assert.ok(html.includes('Belum ada teknisi aktif yang sudah disetujui.'));
    assert.ok(html.includes('Hubungi Admin'));
    assert.ok(findElement(tree, element => element.props.role === 'status'));
    assert.equal(submit(tree).props.disabled, true);
    assert.equal(select(tree).props.disabled, true);
    tree.props.onSubmit({ preventDefault() {} });
    assert.deepEqual(form.calls, []);
});

test('processing disables submission and selection and prevents another PATCH', () => {
    const form = configure({ data: { technician_ids: [3] }, processing: true });
    const tree = AssignWorkOrder(props);
    assert.equal(submit(tree).props.disabled, true);
    assert.equal(submit(tree).props['aria-busy'], true);
    assert.equal(submit(tree).props.children, 'Menyimpan…');
    assert.equal(select(tree).props.disabled, true);
    tree.props.onSubmit({ preventDefault() {} });
    assert.deepEqual(form.calls, []);
});

test('nested validation errors preserve selections and connect the alert to the multi-select', () => {
    const form = configure({ data: { technician_ids: [3, 4] }, errors: { 'technician_ids.1': 'Teknisi tidak tersedia.' } });
    const tree = AssignWorkOrder(props);
    const html = renderToStaticMarkup(tree);
    assert.deepEqual(form.data.technician_ids, [3, 4]);
    assert.deepEqual(select(tree).props.value, ['3', '4']);
    assert.equal(select(tree).props['aria-invalid'], true);
    assert.equal(select(tree).props['aria-describedby'], 'assignment-technicians-hint assignment-technicians-error');
    assert.ok(html.includes('id="assignment-technicians-error" role="alert"'));
    assert.ok(html.includes('Teknisi tidak tersedia.'));
});

test('aggregate assignment and status-service validation messages remain visible', () => {
    configure({ data: { technician_ids: [3] }, errors: { technician_ids: 'Work Order sudah ditugaskan.', status: 'Perubahan status tidak diperbolehkan.' } });
    const html = renderToStaticMarkup(AssignWorkOrder(props));
    assert.ok(html.includes('Work Order sudah ditugaskan.'));
    assert.ok(html.includes('Perubahan status tidak diperbolehkan.'));
});

test('cancellation is an Inertia link to the supplied listing URL and causes no mutation', () => {
    const form = configure();
    const tree = AssignWorkOrder(props);
    const cancel = findElement(tree, element => element.props.children === 'Batal');
    assert.equal(typeof cancel.type, 'function');
    assert.equal(cancel.props.href, props.links.index);
    assert.deepEqual(form.calls, []);
});

test('nullable relationships render safely and all work order and technician text is escaped', () => {
    configure();
    const nullHtml = renderToStaticMarkup(AssignWorkOrder({ ...props, workOrder: { ...props.workOrder, customer: null, site: null } }));
    assert.equal(nullHtml.split('—').length - 1, 2);
    const dangerous = '<script>alert(1)</script>';
    const unsafe = { ...props, workOrder: { id: 1, number: dangerous, title: dangerous, customer: dangerous, site: dangerous, status: dangerous }, technicians: [{ id: 3, name: dangerous }] };
    const html = renderToStaticMarkup(AssignWorkOrder(unsafe));
    assert.ok(!html.includes(dangerous));
    assert.equal(html.split('&lt;script&gt;alert(1)&lt;/script&gt;').length - 1, 6);
});

test('listing renders only supplied assignment actions as Inertia links without changing pagination', () => {
    const assigned = { ...workOrder, id: 2, number: 'WO-002', status: 'assigned', technicians: ['Teknisi Satu'] };
    const tree = WorkOrderIndex({ workOrders: { ...emptyPage, data: [workOrder, assigned], total: 11, last_page: 2, next_page_url: '/work-orders?page=2' }, links: { create: '/work-orders/create' }, assignmentLinks: { 1: '/work-orders/1/assign' } });
    const action = findElement(tree, element => element.props['aria-label'] === 'Tugaskan teknisi untuk WO-001');
    assert.equal(typeof action.type, 'function');
    assert.equal(action.props.href, '/work-orders/1/assign');
    assert.equal(findElement(tree, element => element.props['aria-label'] === 'Tugaskan teknisi untuk WO-002'), undefined);
    assert.equal(findElement(tree, element => element.props.children === 'Berikutnya').props.href, '/work-orders?page=2');
    assert.equal(findElement(tree, element => element.props.children === 'Tambah Work Order').props.href, '/work-orders/create');
});

test('listing omits assignment actions without authorized URLs and supports prior fixtures', () => {
    const base = { workOrders: { ...emptyPage, data: [workOrder] }, links: { create: null } };
    for (const assignmentLinks of [undefined, {}]) {
        const tree = WorkOrderIndex({ ...base, assignmentLinks });
        assert.equal(findElement(tree, element => element.props.children === 'Tugaskan Teknisi'), undefined);
        assert.equal(findElement(tree, element => element.props.children === 'Aksi'), undefined);
    }
    const empty = WorkOrderIndex({ workOrders: emptyPage, links: { create: null } });
    assert.equal(findElement(empty, element => element.props.className === 'empty-state').props.colSpan, 6);
});
