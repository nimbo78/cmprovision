/* Navigation state only; credentials stay in their owning forms. */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory(root);
    else root.StationWizard = factory(root);
}(typeof window !== 'undefined' ? window : globalThis, function (root) {
    'use strict';
    function guardState() {
        var editors = new Map(), dirty = new Set(), busy = false;
        return {
            editor: function (id, open) { if (editors.get(id) === true && !open) dirty.delete(id); editors.set(id, open); },
            change: function (id) { dirty.add(id); }, saved: function (id) { dirty.delete(id); },
            forget: function (id) { dirty.delete(id); editors.delete(id); },
            saving: function (value) { busy = value; },
            canLeave: function () { return !busy && !dirty.size && !Array.from(editors.values()).some(Boolean); }
        };
    }
    function bind(document) {
        if (!document.querySelector('[data-station-wizard]')) return;
        var guard = guardState(), known = new Set(), previousStep = null;
        function scope() { return document.querySelector('[data-station-wizard]'); }
        function id(element) { return element.getAttribute('wire:id') || element.id; }
        function sync() {
            var wizard = scope();
            if (!wizard) return;
            var current = new Set();
            wizard.querySelectorAll('[data-wizard-form]').forEach(function (element) {
                var key = id(element); current.add(key);
                guard.editor(key, element.getAttribute('data-wizard-editor') === 'open');
            });
            known.forEach(function (key) { if (!current.has(key)) guard.forget(key); }); known = current;
            guard.saving(!!wizard.querySelector('[data-wizard-saving]'));
            var step = wizard.getAttribute('data-wizard-step');
            if (previousStep !== null && step !== previousStep) {
                var heading = wizard.querySelector('#station-wizard-heading'); if (heading) heading.focus();
            }
            previousStep = step;
        }
        function changed(event) {
            var form = event.target.closest('[data-wizard-form]');
            if (scope() && form && scope().contains(form)) guard.change(id(form));
        }
        document.addEventListener('input', changed, true); document.addEventListener('change', changed, true);
        document.addEventListener('station-wizard-saved', function (event) {
            var form = event.target.closest('[data-wizard-form]'); if (form) guard.saved(id(form));
        });
        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-wizard-go]'), wizard = scope();
            if (!button || !wizard || !wizard.contains(button)) return;
            sync();
            if (!guard.canLeave()) {
                event.preventDefault(); event.stopImmediatePropagation();
                var message = wizard.querySelector('[data-wizard-guard-message]');
                if (message) { message.classList.remove('hidden'); message.focus(); }
            }
        }, true);
        root.addEventListener('beforeunload', function (event) {
            if (!scope()) return; sync();
            if (!guard.canLeave()) { event.preventDefault(); event.returnValue = ''; }
        });
        new root.MutationObserver(sync).observe(document.body, {subtree: true, childList: true, attributes: true,
            attributeFilter: ['data-wizard-editor', 'data-wizard-saving', 'data-wizard-step']});
        sync();
    }
    if (root.document) {
        if (root.document.readyState === 'loading') root.document.addEventListener('DOMContentLoaded', function () { bind(root.document); }, {once: true});
        else bind(root.document);
    }
    return {guardState: guardState};
}));
