import assert from 'node:assert/strict';
import { resolve } from 'node:path';
import test from 'node:test';
import { renderToStaticMarkup } from 'react-dom/server';
import { configure, findElement, loadComponent } from './component-harness.mjs';
const { BastScopeForm: Scope } = await import(loadComponent(resolve('resources/js/components/bast-scope-form.tsx')));
const { BastEvidenceForm: Evidence } = await import(loadComponent(resolve('resources/js/components/bast-evidence-form.tsx')));
const { BastReasonForm: Cancel } = await import(loadComponent(resolve('resources/js/components/bast-reason-form.tsx')));
const { ReportCorrectionForm: Correction } = await import(loadComponent(resolve('resources/js/components/report-correction-form.tsx')));
const { default: Show } = await import(loadComponent(resolve('resources/js/pages/work-orders/acceptance/show.tsx')));
const { default: Index } = await import(loadComponent(resolve('resources/js/pages/work-orders/acceptance/index.tsx')));
const key = '11111111-1111-4111-8111-111111111111';
const action = { url: '/bast/1/issue', command_key: key, upload_key: key };
const event = { preventDefault() {} };
const formIn = tree => findElement(tree, node => node.type === 'form');
const submitIn = tree => findElement(tree, node => node.props.type === 'submit');
const control = (tree, id) => findElement(tree, node => node.props.id === id && ['input', 'select', 'textarea'].includes(node.type));
const item = { report_id: 2, scope_id: 3, work_order_id: 4, work_order_version: 5, work_order_number: 'WO-4', description: 'Pulihkan CCTV', acceptance_criteria: 'Live feed', revision_no: 1, site_id: 7, authors: [{ id: 8, name: 'Teknisi' }] };
const version = { id: 1, number: 'BAST-1', version_no: 1, status: 'draft', lock_version: 0, context: 'ticket:1', customer: 'Customer', reason: null, signer_name: null, signer_capacity: null, authority_ref: null, issued_at: null, decided_at: null, recorded_at: null, recorder: null, issued_url: null, decision_url: null };
const refreshUrl = '/bast/1';

test('scope selection binds WO versions, replaces older revisions of the same scope and retries identically', () => {
    const form = configure();
    const newer = { ...item, report_id: 9, revision_no: 2 };
    const tree = Scope({ action, initial: [item], candidates: [newer], version: 0, method: 'patch', label: 'Simpan', refreshUrl });
    const checkbox = findElement(tree, node => node.props.type === 'checkbox' && node.props.value === 9);
    checkbox.props.onChange({ target: { checked: true } });
    assert.deepEqual(form.data.report_ids, [9]);
    assert.deepEqual(form.data.work_order_versions, { 4: 5 });
    formIn(tree).props.onSubmit(event); formIn(tree).props.onSubmit(event);
    assert.equal(form.calls.length, 1);
    assert.equal(form.calls[0][0], 'patch');
    assert.equal(form.calls[0][2].preserveState, 'errors');
    assert.equal(form.data.command_key, key);
    assert.equal(form.data.expected_version, 0);
});

test('empty draft scope form does not invent acceptance or select reports automatically', () => {
    const form = configure();
    const tree = Scope({ action, candidates: [], label: 'Buat draft', refreshUrl });
    assert.deepEqual(form.data.report_ids, []);
    assert.equal(form.data.decision, undefined);
    assert.ok(renderToStaticMarkup(tree).includes('Draft kosong dapat dilengkapi kemudian'));
});

test('external decision requires explicit choice, file digest and all-scope authority attestation', () => {
    const form = configure();
    const props = { action, version: { ...version, status: 'pending', lock_version: 1 }, items: [item], decision: true, refreshUrl };
    const empty = Evidence(props);
    assert.equal(form.data.decision, '');
    assert.equal(form.data.scope_confirmed, false);
    assert.equal(submitIn(empty).props.disabled, true);
    formIn(empty).props.onSubmit(event);
    assert.deepEqual(form.calls, []);
    form.setData('file', { name: 'signed.pdf' }); form.setData('sha256', 'a'.repeat(64));
    form.setData('decision', 'accepted'); form.setData('scope_confirmed', true);
    const ready = Evidence(props);
    formIn(ready).props.onSubmit(event); formIn(ready).props.onSubmit(event);
    assert.equal(form.calls.length, 1);
    assert.equal(form.calls[0][2].forceFormData, true);
    assert.equal(form.calls[0][2].preserveState, 'errors');
    assert.equal(form.data.expected_version, 1);
    assert.deepEqual(form.data.authorized_site_ids, [7]);
    assert.equal(form.data.recorded_at, undefined);
    assert.equal(form.data.recorded_by, undefined);
});

test('technical rejection requires defect scope and an explicit eligible revision author', () => {
    const form = configure();
    const props = { action, version, items: [item], decision: true, refreshUrl };
    Evidence(props);
    Object.assign(form.data, { file: { name: 'reject.pdf' }, sha256: 'a'.repeat(64), scope_confirmed: true, decision: 'rejected', category: 'technical_rework' });
    const tree = Evidence(props);
    formIn(tree).props.onSubmit(event);
    assert.deepEqual(form.calls, []);
    assert.ok(form.errors.rework_scope_authors);
    const defect = findElement(tree, node => node.props.type === 'checkbox' && node.props.required !== true);
    defect.props.onChange({ target: { checked: true } });
    assert.deepEqual(form.data.rework_scope_authors, { 3: 0 });
    const chosen = Evidence(props);
    control(chosen, 'bast-author-3').props.onChange({ target: { value: '8' } });
    assert.deepEqual(form.data.rework_scope_authors, { 3: 8 });
    control(chosen, 'bast-decided-at').props.onChange({ target: { value: '2026-11-15T00:30' } });
    assert.equal(form.data.decided_at, '2026-11-15T00:30+07:00');
    formIn(chosen).props.onSubmit(event);
    assert.equal(form.calls.length, 1);
});

for (const [name, Component, props] of [
    ['scope', Scope, { action, candidates: [item], label: 'Simpan', refreshUrl }],
    ['evidence', Evidence, { action, version, items: [item], refreshUrl }],
    ['cancel', Cancel, { action, version: 1, refreshUrl }],
    ['correction', Correction, { action, reportVersion: 2, workOrderVersion: 3, authors: [{ id: 8, name: 'Teknisi' }], refreshUrl }],
]) test(`${name} processing blocks writes and exposes loading`, () => {
    const form = configure({ processing: true }); const tree = Component(props);
    assert.equal(submitIn(tree).props.disabled, true); assert.equal(submitIn(tree).props['aria-busy'], true);
    formIn(tree).props.onSubmit(event); assert.deepEqual(form.calls, []);
});

test('accepted detail exposes frozen evidence and hides mutable actions while escaping text', () => {
    configure();
    const props = { version: { ...version, status: 'accepted', signer_name: '<script>bad()</script>', signer_capacity: 'PIC', authority_ref: 'Surat', decided_at: '2026-11-14T17:30:00Z', recorded_at: '2026-11-14T17:40:00Z', recorder: 'Koordinator', issued_url: '/bast/1/files/issued', decision_url: '/bast/1/files/decision' }, items: [item], candidates: [], versions: [], actions: { update: null, issue: null, decide: null, cancel: null, revise: null }, links: { index: '/index', refresh: refreshUrl } };
    const tree = Show(props); const html = renderToStaticMarkup(tree);
    assert.ok(html.includes('Diterima customer')); assert.ok(html.includes('&lt;script&gt;')); assert.ok(!html.includes('<script>'));
    assert.ok(html.includes('href="/bast/1/files/decision"')); assert.ok(html.includes('00.30') || html.includes('00:30'));
    assert.equal(findElement(tree, node => node.type === Evidence), undefined);
});

test('acceptance list empty state keeps unrelated package details absent', () => {
    configure();
    const html = renderToStaticMarkup(Index({ workOrder: { id: 4, number: 'WO-4', title: 'CCTV' }, candidates: [], create: null, versions: { data: [], current_page: 1, last_page: 1 }, links: { reports: '/reports', refresh: '/acceptance' } }));
    assert.ok(html.includes('Belum ada dokumen BAST yang dapat diakses')); assert.ok(!html.includes('Buat draft BAST'));
});
