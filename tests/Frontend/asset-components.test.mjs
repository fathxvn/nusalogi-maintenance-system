import assert from 'node:assert/strict';
import { resolve } from 'node:path';
import test from 'node:test';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

import { configure, findElement, loadComponent, stateChanges } from './component-harness.mjs';

const { AssetDeleteAction } = await import(loadComponent(resolve('resources/js/components/asset-delete-action.tsx')));
const { DialogContent, DialogDescription, DialogTitle } = await import(loadComponent(resolve('resources/js/components/ui/dialog.tsx')));
const { AssetForm } = await import(loadComponent(resolve('resources/js/components/asset-form.tsx')));
const { default: CreateAsset } = await import(loadComponent(resolve('resources/js/pages/admin/assets/create.tsx')));
const { default: EditAsset } = await import(loadComponent(resolve('resources/js/pages/admin/assets/edit.tsx')));
const { default: AssetIndex } = await import(loadComponent(resolve('resources/js/pages/admin/assets/index.tsx')));
const { default: ShowAsset } = await import(loadComponent(resolve('resources/js/pages/admin/assets/show.tsx')));
const { AppSidebar } = await import(loadComponent(resolve('resources/js/components/app-sidebar.tsx')));
const sites = [
    { id: 3, site_name: 'Office', customer_name: 'PT Alpha' },
    { id: 4, site_name: 'Office', customer_name: 'PT Beta' },
];
const formProps = { sites, action: '/admin/assets', method: 'post', cancelHref: '/admin/assets', submitLabel: 'Simpan Asset' };
const asset = { id: 1, asset_code: 'ASSET-001', category: 'cctv', brand: null, model: null, serial_number: null, installed_at: null, status: 'active', site: { id: 3, site_name: 'Office', customer_name: 'PT Alpha' }, links: { show: '/admin/assets/1', destroy: '/admin/assets/1' } };
const links = { index: '/admin/assets', create: '/admin/assets/create' };
const detailLinks = { index: '/admin/assets', edit: '/admin/assets/1/edit', destroy: '/admin/assets/1' };
const emptyAssets = { data: [], current_page: 1, last_page: 1, total: 0, from: null, to: null, prev_page_url: null, next_page_url: null };

test('asset form identifies same-name sites by customer and posts the selected site with entered fields', () => {
    const form = configure();
    const tree = AssetForm(formProps);
    assert.deepEqual(form.initial, { customer_site_id: '', asset_code: '', category: '', brand: '', model: '', serial_number: '', installed_at: '' });
    const entered = { customer_site_id: '4', asset_code: 'NEW-001', category: 'networking', brand: 'Brand', model: 'Router', serial_number: 'SN-123', installed_at: '2026-11-06' };
    for (const [name, value] of Object.entries(entered)) {
        findElement(tree, element => element.props.name === name).props.onChange({ target: { value } });
    }
    assert.deepEqual(form.data, entered);
    assert.equal(findElement(tree, element => element.props.name === 'customer_id'), undefined);
    let prevented = false;
    tree.props.onSubmit({ preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.deepEqual(form.calls, [['post', '/admin/assets']]);
    const html = renderToStaticMarkup(tree);
    assert.ok(html.includes('PT Alpha — Office'));
    assert.ok(html.includes('PT Beta — Office'));
    assert.equal(findElement(tree, element => element.props.children === 'Batal').props.href, links.index);
});

test('asset form keeps nullable initial values valid and preserves its supplied patch contract', () => {
    const form = configure();
    const tree = AssetForm({ ...formProps, asset, method: 'patch', action: '/admin/assets/1' });
    assert.deepEqual(form.initial, { customer_site_id: '3', asset_code: 'ASSET-001', category: 'cctv', brand: '', model: '', serial_number: '', installed_at: '' });
    tree.props.onSubmit({ preventDefault() {} });
    assert.deepEqual(form.calls, [['patch', '/admin/assets/1']]);
});

test('asset form uses schema length limits and requires only site, code and category', () => {
    configure();
    const tree = AssetForm(formProps);
    for (const [name, required, limit] of [['asset_code', true, 50], ['category', true, 100], ['brand', false, 100], ['model', false, 100], ['serial_number', false, 150]]) {
        const input = findElement(tree, element => element.props.name === name);
        assert.equal(input.props.required, required);
        assert.equal(input.props.maxLength, limit);
    }
    assert.equal(findElement(tree, element => element.props.name === 'customer_site_id').props.required, true);
    const installedAt = findElement(tree, element => element.props.name === 'installed_at');
    assert.equal(installedAt.props.type, 'date');
    assert.equal(installedAt.props.required, false);
});

test('asset validation retains input and associates every error while processing disables save', () => {
    const data = { customer_site_id: '3', asset_code: 'Retained', category: 'cctv', brand: '', model: '', serial_number: '', installed_at: '2026-11-06' };
    const errors = Object.fromEntries(Object.keys(data).map(key => [key, `Invalid ${key}`]));
    configure({ data, errors, processing: true });
    const tree = AssetForm(formProps);
    const html = renderToStaticMarkup(tree);
    for (const [name, value] of Object.entries(data)) {
        const input = findElement(tree, element => element.props.name === name);
        assert.equal(input.props.value, value);
        assert.equal(input.props['aria-invalid'], true);
        assert.equal(input.props['aria-describedby'], `${input.props.id}-error`);
        assert.ok(html.includes(`id="${input.props.id}-error" role="alert"`));
        assert.ok(html.includes(errors[name]));
    }
    const submit = findElement(tree, element => element.props.type === 'submit');
    assert.equal(submit.props.disabled, true);
    assert.equal(submit.props['aria-busy'], true);
    assert.ok(html.includes('Menyimpan…'));
});

test('asset form prevents saving without sites and create page wires supplied URLs and layout', () => {
    const form = configure();
    const empty = AssetForm({ ...formProps, sites: [] });
    assert.equal(findElement(empty, element => element.props.type === 'submit').props.disabled, true);
    assert.ok(renderToStaticMarkup(empty).includes('Tambahkan Customer Site terlebih dahulu'));
    assert.deepEqual(form.calls, []);
    const page = CreateAsset({ sites, links: { index: links.index, store: '/admin/assets' } });
    assert.equal(page.type, AssetForm);
    assert.equal(page.props.sites, sites);
    assert.equal(page.props.action, '/admin/assets');
    assert.equal(page.props.method, 'post');
    assert.equal(page.props.cancelHref, links.index);
    assert.equal(CreateAsset.layout(page).props.title, 'Tambah Asset');
});

test('asset index uses controller-provided create, search, reset and pagination URLs', () => {
    const form = configure();
    const next = '/admin/assets?search=Router&page=2';
    const tree = AssetIndex({ assets: { ...emptyAssets, data: [asset], total: 11, from: 1, to: 10, last_page: 2, next_page_url: next }, filters: { search: 'Router' }, links });
    assert.equal(findElement(tree, element => element.props.href === links.create).props.href, links.create);
    assert.equal(findElement(tree, element => element.props.children === 'Reset filter').props.href, links.index);
    assert.equal(findElement(tree, element => element.props.name === 'search').props.value, 'Router');
    findElement(tree, element => element.props.name === 'search').props.onChange({ target: { value: 'CCTV' } });
    findElement(tree, element => element.type === 'form').props.onSubmit({ preventDefault() {} });
    assert.equal(form.data.search, 'CCTV');
    assert.deepEqual(form.calls, [['get', links.index, { preserveState: true, preserveScroll: true, replace: true }]]);
    assert.equal(findElement(tree, element => element.props.children === 'Berikutnya').props.href, next);
    assert.equal(findElement(tree, element => element.props.children === 'Sebelumnya').props.disabled, true);
});

test('asset index renders empty and unmatched states with search errors and disabled processing', () => {
    configure({ processing: true, errors: { search: 'Search invalid' } });
    const tree = AssetIndex({ assets: emptyAssets, filters: { search: '' }, links });
    const html = renderToStaticMarkup(tree);
    assert.ok(html.includes('Belum ada data asset.'));
    assert.equal(findElement(tree, element => element.props.className === 'empty-state').props.colSpan, 7);
    assert.ok(html.includes('0–0 dari 0'));
    const input = findElement(tree, element => element.props.name === 'search');
    assert.equal(input.props['aria-invalid'], true);
    assert.equal(input.props['aria-describedby'], 'asset-search-error');
    assert.ok(html.includes('id="asset-search-error" role="alert"'));
    const submit = findElement(tree, element => element.props.type === 'submit');
    assert.equal(submit.props.disabled, true);
    assert.equal(submit.props['aria-busy'], true);
    assert.equal(findElement(tree, element => element.props.children === 'Berikutnya').props.disabled, true);
    const noMatch = AssetIndex({ assets: emptyAssets, filters: { search: 'Missing' }, links });
    assert.ok(renderToStaticMarkup(noMatch).includes('Tidak ada asset yang sesuai dengan pencarian.'));
});

test('every asset detail action is an Inertia link to the URL supplied by the controller', () => {
    configure();
    const records = [asset, { ...asset, id: 2, asset_code: 'ASSET-002', links: { show: '/admin/assets/2', destroy: '/admin/assets/2' } }];
    const tree = AssetIndex({ assets: { ...emptyAssets, data: records }, filters: { search: '' }, links });
    for (const record of records) {
        const detail = findElement(tree, element => element.props['aria-label'] === `Detail asset ${record.asset_code}`);
        assert.equal(typeof detail.type, 'function');
        assert.equal(detail.props.href, record.links.show);
        assert.equal(detail.props.children, 'Detail');
    }
    const html = renderToStaticMarkup(tree);
    assert.ok(html.includes('href="/admin/assets/1"'));
    assert.ok(html.includes('href="/admin/assets/2"'));
    assert.ok(!html.includes('<link'));
});

test('asset detail renders all supplied information and installation date with a back link', () => {
    const record = { ...asset, brand: 'Merek Uji', model: 'Model Uji', serial_number: 'SN-123', installed_at: '2026-11-06' };
    const tree = ShowAsset({ asset: record, links: detailLinks });
    const html = renderToStaticMarkup(tree);
    for (const text of ['ASSET-001', 'cctv', 'Merek Uji', 'Model Uji', 'SN-123', 'Aktif', '6 Nov 2026', 'PT Alpha', 'Office']) {
        assert.ok(html.includes(text), `Missing detail: ${text}`);
    }
    assert.equal(findElement(tree, element => element.props.children === 'Kembali ke Asset').props.href, links.index);
    const edit = findElement(tree, element => element.props.children === 'Edit Asset');
    assert.equal(typeof edit.type, 'function');
    assert.equal(edit.props.href, detailLinks.edit);
    assert.equal(ShowAsset.layout(tree).props.title, 'Detail Asset');
});

test('asset detail safely renders nullable values and escapes all free text', () => {
    const nullHtml = renderToStaticMarkup(ShowAsset({ asset: { ...asset, site: { id: null, site_name: null, customer_name: null } }, links: detailLinks }));
    assert.ok(nullHtml.includes('—'));
    assert.ok(!nullHtml.includes('Invalid Date'));
    const dangerous = '<script>alert(1)</script>';
    const record = { ...asset, asset_code: dangerous, category: dangerous, brand: dangerous, model: dangerous, serial_number: dangerous, status: dangerous, site: { id: 3, site_name: dangerous, customer_name: dangerous } };
    const tree = ShowAsset({ asset: record, links: detailLinks });
    const html = renderToStaticMarkup(tree);
    assert.ok(!html.includes(dangerous));
    const details = renderToStaticMarkup(findElement(tree, element => element.type === 'section'));
    assert.equal(details.split('&lt;script&gt;alert(1)&lt;/script&gt;').length - 1, 8);
});

test('edit page prefills all fields, submits PATCH and cancels to the same asset detail', () => {
    const form = configure();
    const record = { ...asset, brand: 'Merek Uji', model: 'Model Uji', serial_number: 'SN-123', installed_at: '2026-11-06' };
    const page = EditAsset({ asset: record, sites, links: { show: '/admin/assets/1', update: '/admin/assets/1' } });
    assert.equal(page.type, AssetForm);
    assert.equal(page.props.asset, record);
    assert.equal(page.props.sites, sites);
    assert.equal(page.props.method, 'patch');
    assert.equal(page.props.action, '/admin/assets/1');
    assert.equal(page.props.cancelHref, '/admin/assets/1');
    assert.equal(EditAsset.layout(page).props.title, 'Edit Asset');
    const tree = AssetForm(page.props);
    assert.deepEqual(form.initial, { customer_site_id: '3', asset_code: 'ASSET-001', category: 'cctv', brand: 'Merek Uji', model: 'Model Uji', serial_number: 'SN-123', installed_at: '2026-11-06' });
    assert.equal(findElement(tree, element => element.props.name === 'installed_at').props.value, '2026-11-06');
    assert.equal(findElement(tree, element => element.props.children === 'Batal').props.href, '/admin/assets/1');
    assert.deepEqual(form.calls, []);
    findElement(tree, element => element.props.name === 'model').props.onChange({ target: { value: 'Model Baru' } });
    findElement(tree, element => element.props.name === 'installed_at').props.onChange({ target: { value: '' } });
    tree.props.onSubmit({ preventDefault() {} });
    assert.deepEqual(form.calls, [['patch', '/admin/assets/1']]);
    assert.equal(form.data.model, 'Model Baru');
    assert.equal(form.data.installed_at, '');
});

test('edit page normalizes null optional values and an empty installation date', () => {
    const form = configure();
    const page = EditAsset({ asset, sites, links: { show: '/admin/assets/1', update: '/admin/assets/1' } });
    const tree = AssetForm(page.props);
    for (const name of ['brand', 'model', 'serial_number', 'installed_at']) {
        assert.equal(form.initial[name], '');
        assert.equal(findElement(tree, element => element.props.name === name).props.value, '');
    }
    assert.equal(form.initial.customer_site_id, '3');
});

test('edit validation preserves input and exposes duplicate-code and locked-site errors during processing', () => {
    const data = { customer_site_id: '4', asset_code: 'DUPLICATE', category: 'cctv', brand: 'Retained Brand', model: '', serial_number: '', installed_at: '' };
    const errors = { asset_code: 'The asset code has already been taken.', customer_site_id: 'Lokasi asset yang sudah terkait Work Order tidak dapat dipindahkan.' };
    configure({ data, errors, processing: true });
    const page = EditAsset({ asset, sites, links: { show: '/admin/assets/1', update: '/admin/assets/1' } });
    const tree = AssetForm(page.props);
    for (const name of Object.keys(errors)) {
        const input = findElement(tree, element => element.props.name === name);
        assert.equal(input.props.value, data[name]);
        assert.equal(input.props['aria-invalid'], true);
        assert.equal(input.props['aria-describedby'], `${input.props.id}-error`);
    }
    const html = renderToStaticMarkup(tree);
    assert.ok(html.includes('value="Retained Brand"'));
    assert.ok(html.includes(errors.asset_code));
    assert.ok(html.includes(errors.customer_site_id));
    assert.ok(html.includes('role="alert"'));
    assert.ok(html.includes('Menyimpan…'));
    const submit = findElement(tree, element => element.props.type === 'submit');
    assert.equal(submit.props.disabled, true);
    assert.equal(submit.props['aria-busy'], true);
});

test('asset display escapes free text, renders nullable fields and marks active Inertia sidebar links', () => {
    configure();
    const dangerous = '<script>alert(1)</script>';
    const unsafe = { ...asset, asset_code: dangerous, category: dangerous, brand: dangerous, model: dangerous, serial_number: dangerous, site: { id: 3, site_name: dangerous, customer_name: dangerous } };
    const html = renderToStaticMarkup(AssetIndex({ assets: { ...emptyAssets, data: [unsafe, { ...asset, id: 2, site: { id: null, site_name: null, customer_name: null } }] }, filters: { search: '' }, links }));
    assert.ok(!html.includes(dangerous));
    assert.ok(html.includes('&lt;script&gt;alert(1)&lt;/script&gt;'));
    assert.ok(html.includes('—'));
    assert.ok(html.includes('Aktif'));
    const formHtml = renderToStaticMarkup(AssetForm({ ...formProps, sites: [{ id: 3, site_name: dangerous, customer_name: dangerous }] }));
    assert.ok(!formHtml.includes(dangerous));
    const sidebar = AppSidebar({ items: [{ id: 'assets', label: 'Asset', href: links.index, active: true, inertia: true }] });
    const link = findElement(sidebar, element => element.props.href === links.index);
    assert.equal(typeof link.type, 'function');
    assert.equal(link.props['aria-current'], 'page');
});

test('list and detail provide the reusable delete dialog with each asset identity and supplied DELETE URL', () => {
    configure();
    const records = [asset, { ...asset, id: 2, asset_code: 'ASSET-002', links: { show: '/admin/assets/2', destroy: '/admin/assets/2' } }];
    const list = AssetIndex({ assets: { ...emptyAssets, data: records }, filters: { search: '' }, links });
    for (const record of records) {
        const action = findElement(list, element => element.type === AssetDeleteAction && element.props.asset.id === record.id);
        assert.equal(action.props.asset, record);
        assert.equal(action.props.action, record.links.destroy);
    }
    const detail = ShowAsset({ asset, links: detailLinks });
    const action = findElement(detail, element => element.type === AssetDeleteAction);
    assert.equal(action.props.asset, asset);
    assert.equal(action.props.action, detailLinks.destroy);
});

test('delete dialog identifies the device safely and opening or cancelling never sends a request', () => {
    stateChanges.length = 0;
    const form = configure({ errors: { asset: 'Previous rejection' } });
    const record = { ...asset, brand: 'Merek', model: 'Model', serial_number: 'SN-123' };
    const tree = AssetDeleteAction({ asset: record, action: detailLinks.destroy });
    assert.equal(tree.props.open, false);
    const trigger = findElement(tree, element => element.props['aria-label'] === 'Hapus asset ASSET-001');
    assert.equal(trigger.props.render.props.type, 'button');
    assert.equal(findElement(tree, element => element.type === DialogTitle).props.children, 'Hapus Asset?');
    const description = findElement(tree, element => element.type === DialogDescription);
    const html = renderToStaticMarkup(createElement('div', null, description.props.children));
    for (const text of ['ASSET-001', 'Merek Model', 'SN-123', 'Work Order', 'pekerjaan selesai']) {
        assert.ok(html.includes(text));
    }
    tree.props.onOpenChange(true);
    assert.deepEqual(form.errors, {});
    const cancel = findElement(tree, element => element.props.children === 'Batal');
    assert.equal(cancel.props.type, 'button');
    cancel.props.onClick();
    assert.deepEqual(stateChanges, [true, false]);
    assert.deepEqual(form.calls, []);
    const dangerous = '<script>alert(1)</script>';
    const unsafeTree = AssetDeleteAction({ asset: { ...record, asset_code: dangerous, brand: dangerous }, action: detailLinks.destroy });
    const unsafeDescription = findElement(unsafeTree, element => element.type === DialogDescription);
    const escaped = renderToStaticMarkup(createElement('div', null, unsafeDescription.props.children));
    assert.ok(!escaped.includes(dangerous));
    assert.ok(escaped.includes('&lt;script&gt;alert(1)&lt;/script&gt;'));
});

test('only confirmation sends DELETE and duplicate submission and closing are blocked until it finishes', () => {
    stateChanges.length = 0;
    const form = configure();
    const tree = AssetDeleteAction({ asset, action: detailLinks.destroy });
    tree.props.onOpenChange(true);
    const confirmation = findElement(tree, element => element.type === 'form');
    let prevented = 0;
    const event = { preventDefault() { prevented++; } };
    confirmation.props.onSubmit(event);
    confirmation.props.onSubmit(event);
    assert.equal(prevented, 2);
    assert.equal(form.calls.length, 1);
    const [method, url, options] = form.calls[0];
    assert.equal(method, 'delete');
    assert.equal(url, detailLinks.destroy);
    assert.equal(options.preserveScroll, true);
    tree.props.onOpenChange(false);
    findElement(tree, element => element.props.children === 'Batal').props.onClick();
    assert.deepEqual(stateChanges, [true]);
    options.onSuccess();
    assert.deepEqual(stateChanges, [true, false]);
    options.onFinish();
    tree.props.onOpenChange(true);
    confirmation.props.onSubmit(event);
    assert.equal(form.calls.length, 2);
});

test('processing disables destructive and cancel actions and protects the dialog from closing', () => {
    stateChanges.length = 0;
    const form = configure({ processing: true });
    const tree = AssetDeleteAction({ asset, action: detailLinks.destroy });
    const submit = findElement(tree, element => element.props.type === 'submit');
    assert.equal(submit.props.children, 'Menghapus…');
    assert.equal(submit.props.disabled, true);
    assert.equal(submit.props['aria-busy'], true);
    const cancel = findElement(tree, element => element.props.children === 'Batal');
    assert.equal(cancel.props.disabled, true);
    assert.equal(findElement(tree, element => element.type === DialogContent).props.showCloseButton, false);
    const trigger = findElement(tree, element => element.props['aria-label'] === 'Hapus asset ASSET-001');
    assert.equal(trigger.props.render.props.disabled, true);
    tree.props.onOpenChange(false);
    cancel.props.onClick();
    findElement(tree, element => element.type === 'form').props.onSubmit({ preventDefault() {} });
    assert.deepEqual(stateChanges, []);
    assert.deepEqual(form.calls, []);
});

test('server rejection remains in the dialog as an alert and permits a later retry or cancellation', () => {
    stateChanges.length = 0;
    const message = 'Asset tidak dapat dihapus karena masih terkait Work Order atau histori pekerjaan.';
    const form = configure({ errors: { asset: message } });
    const tree = AssetDeleteAction({ asset, action: detailLinks.destroy });
    assert.equal(findElement(tree, element => element.props.role === 'alert').props.children, message);
    const confirmation = findElement(tree, element => element.type === 'form');
    confirmation.props.onSubmit({ preventDefault() {} });
    // Inertia calls onFinish after a validation error, without invoking onSuccess.
    form.calls[0][2].onFinish();
    assert.deepEqual(stateChanges, []);
    assert.equal(form.errors.asset, message);
    confirmation.props.onSubmit({ preventDefault() {} });
    assert.equal(form.calls.length, 2);
    form.calls[1][2].onFinish();
    findElement(tree, element => element.props.children === 'Batal').props.onClick();
    assert.deepEqual(stateChanges, [false]);
    assert.deepEqual(form.errors, {});
});
