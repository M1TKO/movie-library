'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function runtime(writeText) {
    const handlers = new Map();
    const status = {textContent: '', hidden: true};
    const path = {textContent: 'D:\\Synthetic\\Example.mkv'};
    const selections = [];
    const document = {
        addEventListener(name, handler) { handlers.set(name, handler); },
        getElementById() { return status; },
        createRange() { return {selectNodeContents(node) { selections.push(node); }}; }
    };
    vm.runInNewContext(fs.readFileSync('public/app.js', 'utf8'), {
        document,
        navigator: {clipboard: {writeText}},
        window: {getSelection() { return {removeAllRanges() {}, addRange(range) { selections.push(range); }}; }}
    });
    return {handlers, status, path, selections, click: {target: {closest() { return {closest() { return {querySelector(selector) { return selector === 'code' ? path : status; }}; }}; }}}};
}

test('Clipboard success reports completion only after writing the displayed path', async () => {
    let copied;
    const ui = runtime(async value => { copied = value; });
    await ui.handlers.get('click')(ui.click);
    assert.equal(copied, ui.path.textContent);
    assert.equal(ui.status.textContent, 'Path copied.');
    assert.equal(ui.selections.length, 0);
});

test('Denied clipboard selects the displayed path and explains manual copying', async () => {
    const ui = runtime(async () => { throw new Error('Permission denied'); });
    await ui.handlers.get('click')(ui.click);
    assert.equal(ui.selections[0], ui.path);
    assert.equal(ui.selections.length, 2);
    assert.match(ui.status.textContent, /Clipboard unavailable.*selected.*Copy command/);
});

test('Polling reports loading, connection failure, and recovery', () => {
    const ui = runtime(async () => {});
    ui.handlers.get('htmx:beforeRequest')();
    assert.equal(ui.status.hidden, false);
    assert.match(ui.status.textContent, /Updating/);
    ui.handlers.get('htmx:timeout')();
    assert.match(ui.status.textContent, /retrying automatically.*Reload/);
    ui.handlers.get('htmx:afterRequest')({detail: {successful: false}});
    assert.equal(ui.status.hidden, false);
    assert.match(ui.status.textContent, /could not be refreshed/);
    ui.handlers.get('htmx:afterRequest')({detail: {successful: true}});
    assert.equal(ui.status.hidden, true);
    assert.equal(ui.status.textContent, '');
});
