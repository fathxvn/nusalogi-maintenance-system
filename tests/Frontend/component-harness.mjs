import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import React from 'react';
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
        setError: (key, value) => { form.errors[key] = value; },
        clearErrors: (...keys) => {
            if (keys.length === 0) form.errors = {};
            else for (const key of keys) delete form.errors[key];
        },
    };
    return form;
}
export function useForm(initial) { form.initial = initial; form.data ??= initial; return form; }
export function Link({ children, preserveScroll, ...props }) { return React.createElement('a', props, children); }
`);
const reactMock = moduleUrl(`export * from ${JSON.stringify(reactUrl)};
export const stateChanges = [];
export function useState(initial) { return [initial, value => stateChanges.push(value)]; }
export function useRef(initial) { return { current: initial }; }
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

function findElement(tree, predicate) {
    if (!React.isValidElement(tree)) return undefined;
    if (predicate(tree)) return tree;
    for (const child of React.Children.toArray(tree.props.children)) {
        const found = findElement(child, predicate);
        if (found) return found;
    }
}

export { configure, stateChanges, loadComponent, findElement };
