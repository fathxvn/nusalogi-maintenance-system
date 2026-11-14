import assert from 'node:assert/strict';
import { resolve } from 'node:path';
import test from 'node:test';
import { renderToStaticMarkup } from 'react-dom/server';
import { configure, findElement, loadComponent } from './component-harness.mjs';
import { reportError, reportTime } from '../../resources/js/lib/report.ts';

const components = {};
for (const [name, path] of [
    ['Command', 'components/report-command-form.tsx'], ['Draft', 'components/report-draft-form.tsx'],
    ['Review', 'components/report-review-form.tsx'], ['Material', 'components/report-material-form.tsx'],
    ['Evidence', 'components/report-evidence-form.tsx'], ['Errors', 'components/report-form-errors.tsx'],
    ['Index', 'pages/work-orders/reports/index.tsx'], ['Show', 'pages/work-orders/reports/show.tsx'],
]) {
    const exports = await import(loadComponent(resolve('resources/js', path)));
    components[name] = Object.values(exports)[0];
}
const { Command, Draft, Review, Material, Evidence, Errors, Index, Show } = components;
const key = '11111111-1111-4111-8111-111111111111';
const action = { url: '/work-orders/1/reports/2/submit', command_key: key, upload_key: key };
const event = { preventDefault() {} };
const control = (tree, id) => findElement(tree, node => node.props.id === id && ['input', 'select', 'textarea'].includes(node.type));
const formIn = tree => findElement(tree, node => node.type === 'form');
const buttonIn = tree => findElement(tree, node => node.props.type === 'submit');
const report = { id: 2, revision_no: 1, previous_revision_id: null, review_status: 'draft', diagnosis: 'Kabel', action_taken: 'Pasang', result: 'Normal', recommendation: null,
    checklist_snapshot: null, asset_results: null, usage_snapshot: null, review_notes: null, lock_version: 0, author: 'Teknisi A', reviewer: null,
    submitted_at: null, reviewed_at: null, is_head: true, legacy: false, scope: { id: 1, scope_no: 1, description: 'Pulihkan CCTV', acceptance_criteria: 'Live feed' }, photos: [] };
const workOrder = { id: 1, number: 'WO-1', title: 'CCTV', status: 'in_progress', customer: 'Customer', site: 'Site', lock_version: 3 };
const refreshUrl = '/work-orders/1/reports/2';

for (const [name, Component, props] of [
    ['command', Command, { action, reportVersion: 0, workOrderVersion: 3, label: 'Ajukan', refreshUrl }],
    ['draft', Draft, { action, report, assets: [], refreshUrl }],
    ['material', Material, { action, reportVersion: 0, movements: [], refreshUrl }],
]) {
    test(`${name} sends one request and retains command identity and observed versions for retries`, () => {
        const form = configure();
        const tree = Component(props);
        formIn(tree).props.onSubmit(event);
        formIn(tree).props.onSubmit(event);
        assert.equal(form.calls.length, 1);
        assert.equal(form.data.command_key, key);
        assert.equal(form.data.expected_report_version, 0);
        assert.equal(form.calls[0][1], action.url);
        assert.equal(form.calls[0][2].preserveScroll, true);
        form.calls[0][2].onFinish();
        formIn(tree).props.onSubmit(event);
        assert.equal(form.calls.length, 2);
        assert.equal(form.data.command_key, key);
        assert.equal(form.data.expected_report_version, 0);
    });
    test(`${name} processing suppresses duplicate actions and exposes loading state`, () => {
        const form = configure({ processing: true });
        const tree = Component(props);
        assert.equal(buttonIn(tree).props.disabled, true);
        assert.equal(buttonIn(tree).props['aria-busy'], true);
        formIn(tree).props.onSubmit(event);
        assert.deepEqual(form.calls, []);
    });
}

test('draft checklist starts empty and asset results are attributed only after explicit input', () => {
    const form = configure();
    const tree = Draft({ action, report, assets: [{ id: 7, asset_code: 'CCTV-7' }], refreshUrl });
    assert.deepEqual(form.data.checklist_snapshot, []);
    assert.deepEqual(form.data.asset_results, []);
    const result = control(tree, 'asset-result-7');
    result.props.onChange({ target: { value: 'Uji berhasil' } });
    assert.deepEqual(form.data.asset_results, [{ asset_id: 7, result: 'Uji berhasil' }]);
    result.props.onChange({ target: { value: '' } });
    assert.deepEqual(form.data.asset_results, []);
    formIn(tree).props.onSubmit(event);
    assert.equal(form.calls[0][0], 'patch');
    assert.equal(form.data.usage_snapshot, undefined);
    assert.equal(form.data.created_by, undefined);
});

test('review requires an explicit decision and distinguishes document correction from technical rework', () => {
    const form = configure();
    const props = { action, reportVersion: 4, workOrderVersion: 5, refreshUrl };
    const first = Review(props);
    assert.equal(buttonIn(first).props.disabled, true);
    formIn(first).props.onSubmit(event);
    assert.deepEqual(form.calls, []);
    control(first, 'review-decision').props.onChange({ target: { value: 'needs_revision' } });
    const changed = Review(props);
    const category = control(changed, 'review-category');
    assert.equal(category.props.required, true);
    const html = renderToStaticMarkup(category);
    assert.ok(html.includes('document_fix'));
    assert.ok(html.includes('technical_rework'));
    assert.equal(control(changed, 'review-reason').props.required, true);
    formIn(changed).props.onSubmit(event);
    assert.equal(form.calls.length, 1);
    assert.equal(form.data.expected_report_version, 4);
    assert.equal(form.data.expected_work_order_version, 5);
});

test('material adjustment selects parent attributes and preserves decimal strings', () => {
    const form = configure();
    const props = { action, reportVersion: 2, movements: [{ id: 8, movement_kind: 'consume', item_description: 'Kabel', unit_code: 'meter', quantity: '1.125', supply_source: 'company', disposition: 'covered' }], refreshUrl };
    const first = Material(props);
    control(first, 'material-kind').props.onChange({ target: { value: 'return' } });
    const changed = Material(props);
    control(changed, 'material-parent').props.onChange({ target: { value: '8' } });
    assert.equal(form.data.adjusts_usage_id, 8);
    assert.equal(form.data.unit_code, 'meter');
    assert.equal(form.data.item_description, 'Kabel');
    control(changed, 'material-quantity').props.onChange({ target: { value: '0.125' } });
    assert.equal(form.data.quantity, '0.125');
    assert.equal(control(changed, 'material-reason').props.required, true);
});

test('evidence waits for a file digest and submits multipart data with stable upload and attachment keys', () => {
    const form = configure();
    const props = { action, reportVersion: 2, refreshUrl };
    const empty = Evidence(props);
    assert.equal(buttonIn(empty).props.disabled, true);
    formIn(empty).props.onSubmit(event);
    assert.deepEqual(form.calls, []);
    form.setData('file', { name: 'after.jpg' });
    form.setData('sha256', 'a'.repeat(64));
    const ready = Evidence(props);
    formIn(ready).props.onSubmit(event);
    formIn(ready).props.onSubmit(event);
    assert.equal(form.calls.length, 1);
    assert.equal(form.calls[0][2].forceFormData, true);
    assert.equal(form.data.upload_key, key);
    assert.equal(form.data.command_key, key);
    assert.equal(form.data.expected_report_version, 2);
});

test('nested conflict errors are translated and recovery keeps the relevant report URL', () => {
    configure();
    const html = renderToStaticMarkup(Errors({ errors: { expected_report_version: 'REPORT_VERSION_CONFLICT', checklist_snapshot: { '0.label': 'Lengkapi pemeriksaan' } }, refreshUrl }));
    assert.ok(html.includes(reportError('REPORT_VERSION_CONFLICT')));
    assert.ok(html.includes('Lengkapi pemeriksaan'));
    assert.ok(!html.includes('REPORT_VERSION_CONFLICT'));
    assert.ok(html.includes(`href="${refreshUrl}"`));
    assert.match(reportTime('2026-11-14T17:30:00Z'), /15 Nov 2026.*00[.:]30.*WIB/);
});

const actions = { update: null, submit: null, review: null, revise: null, evidence: null, material: null };
test('detail renders only server permitted actions and escapes user supplied evidence', () => {
    configure();
    const props = { workOrder, report: { ...report, diagnosis: '<script>alert(1)</script>' }, revisions: [], materials: [], assets: [], actions, links: { index: '/work-orders/1/reports', refresh: refreshUrl } };
    const tree = Show(props);
    const html = renderToStaticMarkup(tree);
    assert.ok(html.includes('&lt;script&gt;'));
    assert.ok(!html.includes('<script>'));
    assert.ok(html.includes('Belum ada evidence terlampir'));
    assert.equal(findElement(tree, node => node.type === Draft), undefined);
    const permitted = Show({ ...props, actions: { ...actions, update: action, review: action } });
    assert.ok(findElement(permitted, node => node.type === Draft));
    assert.ok(findElement(permitted, node => node.type === Review));
});

test('index distinguishes empty scopes and legacy reports from verified scope revisions', () => {
    configure();
    const props = { workOrder, scopes: [], legacyReports: [], unavailableReason: 'Baseline belum lengkap', links: { index: '/work-orders', refresh: '/work-orders/1/reports', visits: '/work-orders/1/visits' } };
    const html = renderToStaticMarkup(Index(props));
    assert.ok(html.includes('Belum ada scope M1'));
    assert.ok(html.includes('Baseline belum lengkap'));
    const legacy = renderToStaticMarkup(Index({ ...props, legacyReports: [{ id: 42, status: 'approved', url: '/legacy/42' }] }));
    assert.ok(legacy.includes('Laporan historis'));
    assert.ok(legacy.includes('href="/legacy/42"'));
    assert.ok(!legacy.includes('Buat draft laporan'));
});
