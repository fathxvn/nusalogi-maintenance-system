import assert from 'node:assert/strict';
import test from 'node:test';
import { workOrderLabels, statusClass } from '../../resources/js/lib/presentation.ts';
test('all M1 lifecycle statuses have Indonesian labels and intentional styles with safe unknown fallback', () => {
    for (const [status, label] of Object.entries({ pending: 'Menunggu', assigned: 'Ditugaskan', in_progress: 'Dikerjakan', on_hold: 'Ditahan', pending_review: 'Menunggu review', rework: 'Perlu perbaikan', awaiting_acceptance: 'Menunggu penerimaan', closed: 'Ditutup', cancelled: 'Dibatalkan' })) {
        assert.equal(workOrderLabels[status], label);
        assert.ok(statusClass(status).includes('dark:') || statusClass(status) === 'bg-muted text-muted-foreground');
    }
    assert.equal(workOrderLabels['historical_unknown'] ?? 'historical_unknown', 'historical_unknown');
    assert.equal(statusClass('<script>'), 'bg-muted text-muted-foreground');
});

import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { resolve } from 'node:path';
import { configure, loadComponent } from './component-harness.mjs';
const { default: Dashboard } = await import(loadComponent(resolve('resources/js/pages/dashboard.tsx')));
test('dashboard rows and distribution render new status labels and escape unknown history', () => {
    configure();
    const statuses = ['on_hold', 'pending_review', 'rework', 'awaiting_acceptance', 'closed', 'cancelled', '<script>'];
    const html = renderToStaticMarkup(React.createElement(Dashboard, {
        metrics: { active: 4, today: 0, customers: 0, pendingInvoices: 0 }, trend: [],
        statuses: statuses.map(status => ({ status, total: 1 })),
        recentWorkOrders: statuses.map((status, id) => ({ id, number: `WO-${id}`, title: 'Uji', date: null, customer: null, site: null, status, priority: 'medium', technicians: [] })),
    }));
    for (const status of statuses.slice(0, -1)) assert.ok(html.includes(workOrderLabels[status]));
    assert.ok(html.includes('&lt;script&gt;'));
    assert.ok(!html.includes('<script>'));
    assert.ok(html.includes('Seluruh pekerjaan yang belum ditutup atau dibatalkan'));
});
