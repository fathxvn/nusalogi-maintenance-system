import assert from 'node:assert/strict';
import { resolve } from 'node:path';
import test from 'node:test';
import { renderToStaticMarkup } from 'react-dom/server';

import { findElement, loadComponent } from './component-harness.mjs';

const { default: WorkOrderIndex } = await import(loadComponent(resolve('resources/js/pages/work-orders/index.tsx')));
const { AppSidebar } = await import(loadComponent(resolve('resources/js/components/app-sidebar.tsx')));
const emptyPage = { data: [], current_page: 1, last_page: 1, total: 0, from: null, to: null, prev_page_url: null, next_page_url: null };
const workOrder = {
    id: 1, number: 'WO-001', title: 'Repair camera', customer: 'PT Alpha', site: 'Office',
    service_type: 'maintenance', priority: 'medium', status: 'assigned', scheduled_at: null,
    technicians: ['Teknisi Satu', 'Teknisi Dua'],
};

test('work order listing renders table headings, row data, technicians and its authenticated layout', () => {
    const tree = WorkOrderIndex({ workOrders: { ...emptyPage, data: [workOrder], total: 1 } });
    const html = renderToStaticMarkup(tree);
    for (const text of ['Nomor WO', 'Pekerjaan', 'Customer / Site', 'Teknisi', 'Prioritas', 'Status',
        'WO-001', 'Repair camera', 'PT Alpha', 'Office', 'maintenance', 'medium', 'assigned', 'Teknisi Satu, Teknisi Dua', 'Total 1 pekerjaan']) {
        assert.ok(html.includes(text), `Missing listing content: ${text}`);
    }
    assert.ok(findElement(tree, element => element.type === 'thead'));
    assert.ok(findElement(tree, element => element.type === 'tbody'));
    assert.equal(WorkOrderIndex.layout(tree).props.title, 'Work Order Management');
});

test('empty listing spans all columns and has no pagination links', () => {
    const tree = WorkOrderIndex({ workOrders: emptyPage });
    const html = renderToStaticMarkup(tree);
    assert.ok(html.includes('Belum ada Work Order.'));
    assert.ok(html.includes('Total 0 pekerjaan'));
    assert.ok(html.includes('Halaman 1 dari 1'));
    assert.equal(findElement(tree, element => element.props.className === 'empty-state').props.colSpan, 6);
    const pagination = findElement(tree, element => element.props['aria-label'] === 'Pagination Work Order');
    assert.equal(findElement(pagination, element => Boolean(element.props.href)), undefined);
});

test('listing handles nullable relationships, missing schedule, no technicians and unexpected status values', () => {
    const record = { ...workOrder, customer: null, site: null, technicians: [], priority: 'legacy_priority', status: 'legacy_status' };
    const html = renderToStaticMarkup(WorkOrderIndex({ workOrders: { ...emptyPage, data: [record] } }));
    assert.equal(html.split('—').length - 1, 2);
    for (const text of ['Belum ditugaskan', 'legacy_priority', 'legacy_status']) {
        assert.ok(html.includes(text));
    }
    assert.ok(!html.includes('Invalid Date'));
    assert.ok(!html.includes('undefined'));
});

test('pagination uses supplied URLs as Inertia links on first middle and last pages', () => {
    const previous = '/work-orders?page=1';
    const next = '/work-orders?page=3';
    const tree = WorkOrderIndex({ workOrders: { ...emptyPage, current_page: 2, last_page: 3, prev_page_url: previous, next_page_url: next } });
    for (const [label, url] of [['Sebelumnya', previous], ['Berikutnya', next]]) {
        const link = findElement(tree, element => element.props.children === label);
        assert.equal(typeof link.type, 'function');
        assert.equal(link.props.href, url);
    }
    const first = WorkOrderIndex({ workOrders: { ...emptyPage, last_page: 3, next_page_url: '/work-orders?page=2' } });
    assert.equal(findElement(first, element => element.props.children === 'Sebelumnya'), undefined);
    const last = WorkOrderIndex({ workOrders: { ...emptyPage, current_page: 3, last_page: 3, prev_page_url: '/work-orders?page=2' } });
    assert.equal(findElement(last, element => element.props.children === 'Berikutnya'), undefined);
});

test('all free text is escaped and long work order titles are retained', () => {
    const dangerous = '<script>alert(1)</script>';
    const record = { ...workOrder, number: dangerous, title: dangerous, customer: dangerous, site: dangerous,
        service_type: dangerous, priority: dangerous, status: dangerous, technicians: [dangerous] };
    const html = renderToStaticMarkup(WorkOrderIndex({ workOrders: { ...emptyPage, data: [record] } }));
    assert.ok(!html.includes(dangerous));
    assert.equal(html.split('&lt;script&gt;alert(1)&lt;/script&gt;').length - 1, 8);
    const longTitle = 'Maintenance '.repeat(200);
    const longHtml = renderToStaticMarkup(WorkOrderIndex({ workOrders: { ...emptyPage, data: [{ ...workOrder, title: longTitle }] } }));
    assert.ok(longHtml.includes(longTitle));
});

test('sidebar renders active work order navigation as an Inertia link and calls onNavigate', () => {
    let navigations = 0;
    const tree = AppSidebar({ items: [{ id: 'work-orders', label: 'Work Order', href: '/work-orders', active: true, inertia: true }], onNavigate: () => navigations++ });
    const link = findElement(tree, element => element.props.href === '/work-orders');
    assert.equal(typeof link.type, 'function');
    assert.equal(link.props['aria-current'], 'page');
    link.props.onClick();
    assert.equal(navigations, 1);
    assert.ok(renderToStaticMarkup(tree).includes('href="/work-orders"'));
});

test('sidebar renders forbidden work order navigation without a clickable destination', () => {
    const tree = AppSidebar({ items: [{ id: 'work-orders', label: 'Work Order', href: null, active: false, inertia: false }] });
    assert.equal(findElement(tree, element => Boolean(element.props.href)), undefined);
    assert.ok(findElement(tree, element => element.props['aria-disabled'] === 'true'));
});
