import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { transformSync } from 'rolldown/utils';

// Exercise component contracts with isolated Inertia state; browser behavior remains separate QA.
const moduleUrl = source => `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`;
const reactUrl = import.meta.resolve('react');
const inertiaMock = moduleUrl(`import React from ${JSON.stringify(reactUrl)};
let form;
export function configure(overrides = {}) {
    const calls = [];
    form = { data: null, errors: {}, processing: false, ...overrides, calls,
        post: (...args) => calls.push(['post', ...args]),
        patch: (...args) => calls.push(['patch', ...args]),
        delete: (...args) => calls.push(['delete', ...args]),
        get: (...args) => calls.push(['get', ...args]),
        setData: (key, value) => { form.data[key] = value; },
        clearErrors: () => { form.errors = {}; },
    };
    return form;
}
export function useForm(initial) { form.initial = initial; form.data ??= initial; return form; }
export function Link({ children, preserveScroll, ...props }) { return React.createElement('a', props, children); }
`);
const reactMock = moduleUrl(`export * from ${JSON.stringify(reactUrl)};
export const stateChanges = [];
export function useState(initial) { return [initial, value => stateChanges.push(value)]; }
`);
const layoutMock = moduleUrl('export default function Layout({children}) { return children; }');
const cache = new Map();
function loadComponent(path) {
    if (cache.has(path)) return cache.get(path);
    const transformed = transformSync(path, readFileSync(path, 'utf8'), { jsx: { runtime: 'automatic' } });
    assert.deepEqual(transformed.errors, []);
    const code = transformed.code.replace(/\bfrom\s+(['"])([^'"]+)\1/g, (_, quote, name) => {
        let target;
        if (name === '@inertiajs/react') target = inertiaMock;
        else if (name === 'react') target = reactMock;
        else if (name === '@/layouts/authenticated-layout') target = layoutMock;
        else if (name.startsWith('@/')) target = loadComponent(resolve('resources/js', name.slice(2)) + (name.startsWith('@/lib/') ? '.ts' : '.tsx'));
        else target = import.meta.resolve(name);
        return `from ${JSON.stringify(target)}`;
    });
    const url = moduleUrl(code);
    cache.set(path, url);
    return url;
}
const { configure } = await import(inertiaMock);
const { stateChanges } = await import(reactMock);
const { CustomerForm } = await import(loadComponent(resolve('resources/js/components/customer-form.tsx')));
const { CustomerSiteForm } = await import(loadComponent(resolve('resources/js/components/customer-site-form.tsx')));
const { default: CreateCustomerSite } = await import(loadComponent(resolve('resources/js/pages/admin/customers/sites/create.tsx')));
const { default: EditCustomerSite } = await import(loadComponent(resolve('resources/js/pages/admin/customers/sites/edit.tsx')));
const { CustomerDeleteAction } = await import(loadComponent(resolve('resources/js/components/customer-delete-action.tsx')));
const { default: CreateCustomer } = await import(loadComponent(resolve('resources/js/pages/admin/customers/create.tsx')));
const { default: EditCustomer } = await import(loadComponent(resolve('resources/js/pages/admin/customers/edit.tsx')));
const { default: ShowCustomer } = await import(loadComponent(resolve('resources/js/pages/admin/customers/show.tsx')));
const { default: CustomerIndex } = await import(loadComponent(resolve('resources/js/pages/admin/customers/index.tsx')));

function findElement(tree, predicate) {
    if (!React.isValidElement(tree)) return undefined;
    if (predicate(tree)) return tree;
    for (const child of React.Children.toArray(tree.props.children)) {
        const found = findElement(child, predicate);
        if (found) return found;
    }
}
const customer = { id: 7, name: 'PT Testing', pic_name: null, phone: '+62 812', email: 'office@example.test', billing_address: 'Bekasi' };
const formProps = { action: '/admin/customers', method: 'post', cancelHref: '/admin/customers', submitLabel: 'Simpan Customer' };

test('create form starts empty and submits entered values through POST', () => {
    const form = configure();
    const tree = CustomerForm(formProps);
    assert.deepEqual(form.initial, { name: '', pic_name: '', phone: '', email: '', billing_address: '' });
    findElement(tree, element => element.props.name === 'name').props.onChange({ target: { value: 'New Customer' } });
    let prevented = false;
    tree.props.onSubmit({ preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.deepEqual(form.calls, [['post', '/admin/customers']]);
    assert.equal(form.data.name, 'New Customer');
    const html = renderToStaticMarkup(tree);
    assert.ok(html.includes('name="name"'));
    assert.equal(findElement(tree, element => element.props.name === 'name').props.required, true);
    assert.equal(findElement(tree, element => element.props.name === 'email').props.required, false);
    assert.equal(findElement(tree, element => element.props.children === 'Batal').props.href, '/admin/customers');
});

test('edit form prefills nullable fields and sends PATCH to the supplied URL', () => {
    const form = configure();
    const tree = CustomerForm({ ...formProps, customer, action: '/admin/customers/7', method: 'patch' });
    assert.deepEqual(form.initial, { name: 'PT Testing', pic_name: '', phone: '+62 812', email: 'office@example.test', billing_address: 'Bekasi' });
    findElement(tree, element => element.props.name === 'billing_address').props.onChange({ target: { value: 'Jakarta' } });
    tree.props.onSubmit({ preventDefault() {} });
    assert.deepEqual(form.calls, [['patch', '/admin/customers/7']]);
    assert.equal(form.data.billing_address, 'Jakarta');
});

test('validation feedback retains input and processing disables submit', () => {
    configure({ data: { name: 'Retained Name', pic_name: '', phone: '', email: 'bad', billing_address: '' }, errors: { email: 'Invalid email' }, processing: true });
    const tree = CustomerForm(formProps);
    const email = findElement(tree, element => element.props.name === 'email');
    assert.equal(email.props.value, 'bad');
    assert.equal(email.props['aria-invalid'], true);
    assert.equal(email.props['aria-describedby'], 'customer-email-error');
    const submit = findElement(tree, element => element.props.type === 'submit');
    assert.equal(submit.props.disabled, true);
    assert.equal(submit.props['aria-busy'], true);
    const html = renderToStaticMarkup(tree);
    assert.ok(html.includes('value="Retained Name"'));
    assert.ok(html.includes('id="customer-email-error" role="alert"'));
    assert.ok(html.includes('Invalid email'));
});

test('create and edit pages pass the correct methods and named-route URLs to the shared form', () => {
    const create = CreateCustomer({ links: { index: '/admin/customers', store: '/admin/customers' } });
    const edit = EditCustomer({ customer, links: { index: '/admin/customers', update: '/admin/customers/7' } });
    assert.equal(create.type, CustomerForm);
    assert.equal(create.props.method, 'post');
    assert.equal(create.props.action, '/admin/customers');
    assert.equal(edit.type, CustomerForm);
    assert.equal(edit.props.method, 'patch');
    assert.equal(edit.props.action, '/admin/customers/7');
    assert.equal(edit.props.customer, customer);
});

test('opening or cancelling delete sends no request and confirmation sends DELETE', () => {
    const form = configure();
    stateChanges.length = 0;
    const tree = CustomerDeleteAction({ customer, action: '/admin/customers/7' });
    tree.props.onOpenChange(true);
    findElement(tree, element => element.props.children === 'Batal').props.onClick();
    assert.deepEqual(form.calls, []);
    assert.deepEqual(stateChanges, [true, false]);
    findElement(tree, element => element.type === 'form').props.onSubmit({ preventDefault() {} });
    assert.equal(form.calls[0][0], 'delete');
    assert.equal(form.calls[0][1], '/admin/customers/7');
    assert.equal(form.calls[0][2].preserveScroll, true);
    form.calls[0][2].onSuccess();
    assert.equal(stateChanges.at(-1), false);
});

test('delete failure is visible and processing prevents closing or repeat confirmation', () => {
    configure({ errors: { customer: 'Customer memiliki site terkait.' }, processing: true });
    stateChanges.length = 0;
    const tree = CustomerDeleteAction({ customer, action: '/admin/customers/7' });
    const error = findElement(tree, element => element.props.role === 'alert');
    assert.equal(error.props.children, 'Customer memiliki site terkait.');
    tree.props.onOpenChange(false);
    assert.deepEqual(stateChanges, []);
    const submit = findElement(tree, element => element.props.type === 'submit');
    assert.equal(submit.props.disabled, true);
    assert.equal(submit.props['aria-busy'], true);
});

test('detail renders customer and site information as escaped text', () => {
    configure();
    const tree = ShowCustomer({
        customer: { ...customer, name: '<script>alert(1)</script>', created_at: '2026-11-04', sites_count: 1, sites: [{ id: 1, site_name: 'Office', address: 'Bekasi', pic_name: null, pic_phone: null, links: { edit: '/admin/customers/7/sites/1/edit' } }] },
        links: { index: '/admin/customers', edit: '/admin/customers/7/edit', destroy: '/admin/customers/7', createSite: '/admin/customers/7/sites/create' },
    });
    const html = renderToStaticMarkup(tree);
    assert.ok(html.includes('&lt;script&gt;alert(1)&lt;/script&gt;'));
    assert.ok(!html.includes('<script>alert(1)</script>'));
    assert.ok(html.includes('Office'));
    assert.ok(html.includes('office@example.test'));
    assert.ok(html.includes('Bekasi'));
    assert.ok(html.includes('href="/admin/customers/7/edit"'));
});

const site = { id: 3, site_name: 'Kantor Bekasi', address: 'Jl. Testing 1\nBekasi', pic_name: 'José Wijaya', pic_phone: '+62 812' };
const siteFormProps = { customer, action: '/admin/customers/7/sites', method: 'post', cancelHref: '/admin/customers/7', submitLabel: 'Simpan Site' };

test('site create form displays customer identity and posts entered fields without customer_id', () => {
    const form = configure();
    const tree = CustomerSiteForm(siteFormProps);
    assert.deepEqual(form.initial, { site_name: '', address: '', pic_name: '', pic_phone: '' });
    for (const [name, value] of Object.entries({ site_name: site.site_name, address: site.address, pic_name: site.pic_name, pic_phone: site.pic_phone })) {
        findElement(tree, element => element.props.name === name).props.onChange({ target: { value } });
        assert.equal(form.data[name], value);
    }
    let prevented = false;
    tree.props.onSubmit({ preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.deepEqual(form.calls, [['post', '/admin/customers/7/sites']]);
    assert.equal(findElement(tree, element => element.props.name === 'customer_id'), undefined);
    assert.ok(renderToStaticMarkup(tree).includes('PT Testing'));
    assert.equal(findElement(tree, element => element.props.children === 'Batal').props.href, '/admin/customers/7');
});

test('site form applies required fields and database length limits', () => {
    configure();
    const tree = CustomerSiteForm(siteFormProps);
    for (const [name, required, maxLength] of [['site_name', true, 150], ['address', true, 65535], ['pic_name', false, 150], ['pic_phone', false, 30]]) {
        const input = findElement(tree, element => element.props.name === name);
        assert.equal(input.props.required, required);
        assert.equal(input.props.maxLength, maxLength);
        assert.ok(input.props.className.includes('field-control'));
    }
    assert.equal(findElement(tree, element => element.props.name === 'pic_phone').props.type, 'tel');
});

test('site edit form prefills all fields and sends PATCH with the edited address', () => {
    const form = configure();
    const tree = CustomerSiteForm({ ...siteFormProps, site, action: '/admin/customers/7/sites/3', method: 'patch' });
    assert.deepEqual(form.initial, { site_name: site.site_name, address: site.address, pic_name: site.pic_name, pic_phone: site.pic_phone });
    findElement(tree, element => element.props.name === 'address').props.onChange({ target: { value: 'Alamat Baru' } });
    tree.props.onSubmit({ preventDefault() {} });
    assert.equal(form.data.address, 'Alamat Baru');
    assert.deepEqual(form.calls, [['patch', '/admin/customers/7/sites/3']]);

    const nullableForm = configure();
    CustomerSiteForm({ ...siteFormProps, site: { ...site, pic_name: null, pic_phone: null } });
    assert.equal(nullableForm.initial.pic_name, '');
    assert.equal(nullableForm.initial.pic_phone, '');
});

test('site validation feedback retains values, associates errors and disables submit during processing', () => {
    const data = { site_name: 'Retained Site', address: 'Alamat tetap', pic_name: 'Retained PIC', pic_phone: 'bad phone' };
    const errors = { site_name: 'Nama wajib diisi.', address: 'Alamat site maksimal 65.535 byte.', pic_name: 'PIC terlalu panjang.', pic_phone: 'Telepon terlalu panjang.' };
    configure({ data, errors, processing: true });
    const tree = CustomerSiteForm(siteFormProps);
    const html = renderToStaticMarkup(tree);
    for (const [name, value] of Object.entries(data)) {
        const field = findElement(tree, element => element.props.name === name);
        assert.equal(field.props.value, value);
        assert.equal(field.props['aria-invalid'], true);
        assert.equal(field.props['aria-describedby'], `${field.props.id}-error`);
        assert.ok(html.includes(`id="${field.props.id}-error" role="alert"`));
        assert.ok(html.includes(errors[name]));
    }
    const submit = findElement(tree, element => element.props.type === 'submit');
    assert.equal(submit.props.disabled, true);
    assert.equal(submit.props['aria-busy'], true);
    assert.ok(html.includes('Menyimpan…'));
});

test('site create and edit pages use customer-specific URLs and authenticated layouts', () => {
    const create = CreateCustomerSite({ customer, links: { show: '/admin/customers/7', store: '/admin/customers/7/sites' } });
    const edit = EditCustomerSite({ customer, site, links: { show: '/admin/customers/7', update: '/admin/customers/7/sites/3' } });
    assert.equal(create.type, CustomerSiteForm);
    assert.equal(create.props.customer, customer);
    assert.equal(create.props.method, 'post');
    assert.equal(create.props.action, '/admin/customers/7/sites');
    assert.equal(create.props.cancelHref, '/admin/customers/7');
    assert.equal(edit.type, CustomerSiteForm);
    assert.equal(edit.props.customer, customer);
    assert.equal(edit.props.site, site);
    assert.equal(edit.props.method, 'patch');
    assert.equal(edit.props.action, '/admin/customers/7/sites/3');
    assert.equal(edit.props.cancelHref, '/admin/customers/7');
    assert.equal(CreateCustomerSite.layout(create).props.title, 'Tambah Site');
    assert.equal(EditCustomerSite.layout(edit).props.title, 'Edit Site');
});

test('detail offers add site when empty and an edit action for every site with escaped content', () => {
    configure();
    const links = { index: '/admin/customers', edit: '/admin/customers/7/edit', destroy: '/admin/customers/7', createSite: '/admin/customers/7/sites/create' };
    const empty = ShowCustomer({ customer: { ...customer, created_at: null, sites_count: 0, sites: [] }, links });
    assert.equal(findElement(empty, element => element.props.children === 'Tambah Site').props.href, links.createSite);
    assert.ok(renderToStaticMarkup(empty).includes('colSpan="5"'));
    assert.ok(renderToStaticMarkup(empty).includes('Belum ada site untuk customer ini.'));

    const dangerous = '<script>alert(1)</script>';
    const sites = [
        { ...site, site_name: dangerous, address: dangerous, pic_name: dangerous, pic_phone: dangerous, links: { edit: '/admin/customers/7/sites/3/edit' } },
        { ...site, id: 4, pic_name: null, pic_phone: null, links: { edit: '/admin/customers/7/sites/4/edit' } },
    ];
    const tree = ShowCustomer({ customer: { ...customer, created_at: null, sites_count: 2, sites }, links });
    const html = renderToStaticMarkup(tree);
    for (const record of sites) {
        assert.equal(findElement(tree, element => element.props['aria-label'] === `Edit site ${record.site_name}`).props.href, record.links.edit);
    }
    assert.ok(!html.includes(dangerous));
    assert.ok(html.includes('&lt;script&gt;alert(1)&lt;/script&gt;'));
    assert.ok(html.includes('—'));

    configure();
    const form = CustomerSiteForm({ ...siteFormProps, customer: { ...customer, name: dangerous }, site: sites[0] });
    const formHtml = renderToStaticMarkup(form);
    assert.ok(!formHtml.includes(dangerous));
    assert.ok(formHtml.includes('&lt;script&gt;alert(1)&lt;/script&gt;'));
});

test('index retains search and pagination while providing actions for each customer', () => {
    configure();
    const row = { ...customer, sites_count: 2, links: { show: '/admin/customers/7', edit: '/admin/customers/7/edit', destroy: '/admin/customers/7' } };
    const tree = CustomerIndex({
        customers: { data: [row], current_page: 1, last_page: 2, total: 11, from: 1, to: 10, prev_page_url: null, next_page_url: '/admin/customers?search=Testing&page=2' },
        filters: { search: 'Testing' }, links: { index: '/admin/customers', create: '/admin/customers/create' },
    });
    assert.equal(findElement(tree, element => element.props.children === 'Detail').props.href, '/admin/customers/7');
    assert.equal(findElement(tree, element => element.props.children === 'Edit').props.href, '/admin/customers/7/edit');
    assert.equal(findElement(tree, element => element.type === CustomerDeleteAction).props.action, '/admin/customers/7');
    assert.equal(findElement(tree, element => element.type === 'input').props.value, 'Testing');
    assert.ok(findElement(tree, element => element.props.href === '/admin/customers/create'));
    assert.ok(findElement(tree, element => element.props.href === '/admin/customers?search=Testing&page=2'));
});
