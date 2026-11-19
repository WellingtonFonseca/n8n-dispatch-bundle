// A jQuery-like page for the tab scripts: a tab list and a tab content that already hold the "Habilitar/Autenticar" tab.
// With `noTabList` the content has no tab list before its form. With `hasError` a field of the content has the error class of a failed save. `log` is what the script did, in order, so a test can tell prepend from append and what was removed first.
'use strict';

function page(paneExists, noTabList, hasError) {
    const log  = [];
    const make = (name, html) => {
        const el = {length: 1, name, html};
        el.append    = () => el;
        el.attr      = (key, value) => (undefined === value ? 'Tab label' : el);
        el.text      = () => el;
        el.find      = (selector) => ({remove: () => log.push('removed ' + selector)});
        el.prependTo = (target) => { log.push(name + ' prepended to ' + target.name + ': ' + html); return el; };
        el.appendTo  = (target) => { log.push(name + ' appended to ' + target.name + ': ' + html); return el; };
        return el;
    };
    const tabs    = Object.assign(make('tabs', ''), {children: () => ({removeClass: (c) => log.push('tabs: remove ' + c)})});
    const content = Object.assign(make('content', ''), {
        children: () => ({removeClass: (c) => log.push('panes: remove ' + c)}),
        find    : (selector) => ({length: ('.has-error' === selector && hasError) ? 1 : 0}),
        closest : () => ({prev: () => (noTabList ? {length: 0} : tabs)}),
    });
    const block = Object.assign(make('block', ''), {closest: () => content});
    const $     = (arg) => {
        if ('string' !== typeof arg) { return block; }
        if ('#' === arg[0]) { return paneExists ? make('existing', '') : {length: 0}; }
        return make(arg.startsWith('<li') ? 'li' : (arg.startsWith('<div') ? 'pane' : 'a'), arg);
    };

    return {$, block, log};
}

// a jQuery whose every element is empty (no tab content, no tab list): every way up the page ends in nothing
const empty = () => { const node = {length: 0, closest: () => node, prev: () => node}; return node; };

module.exports = {page, empty};
