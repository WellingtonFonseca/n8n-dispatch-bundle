categoria: ui
regras:
- Plugin modals that hold a table (the plugin settings modal with the alert log, the About block and the Mirror log; the resend modal) are 780px wide (n8ndispatch.css, `.modal-dialog:has(.n8ndispatch-alert-log-block)` / `:has(.n8ndispatch-resend)`): core's 600px shared modal is too narrow. Other uses of the shared modal and other plugins' modals keep their size.
- The name on the SMS and HSM template lists opens a read-only details modal (data-toggle=ajaxmodal into #MauticSharedModal), not the edit page; the edit stays in the row's options menu and in the modal's Edit button.
- Footer order and look as Mautic's forms and the segment details modal: Edit (the one btn-primary) first, Close (btn-tertiary btn-cancel, transparent) last; core draws a second primary by position as outlined.
- Footer buttons are real modal buttons: a `.bottom-form-buttons` block in the body that core copies into `.modal-footer .modal-form-buttons` (1a.content.js) and hides; the copy clicks the original. Core copies it AFTER the onload callback: a script that touches the footer buttons must defer (setTimeout 0, retry up to 10 times 50 ms apart until the button exists). Core locks the buttons and spins the icon on click: unlock them on an error.
- A modal button that navigates (Edit) is a `<button>` whose onclick hides the modal and calls Mautic.loadContent(editUrl): core copies only <button> elements, an <a> would be lost.
- Middle click on an ajaxmodal link: core listens only to 'click', so the browser opened the bare modal content in a new tab; Assets/js/template-modal-middle-click.js catches 'auxclick' (button 1) in the capture phase on the two template details links, cancels the tab and calls Mautic.ajaxifyModal. Ctrl/cmd+click is left to the browser. The Dispatches screen's details link has the same markup and is NOT covered.
- The save button of the plugin settings modal is `.modal .btn-apply.btn-copy` (specs use it).
- The details modal of the Dispatches screen is 1100px wide for the History table.
- Mautic.showConfirmation (CoreBundle/Assets/js/9.modals.js) is the confirmation: core's data-toggle='confirmation' auto-binding only wires <a> elements; call it from a button's onclick when the element must stay a <button>.

Buttons: [ui-botoes.md](ui-botoes.md). Badges: [ui-badge-n8n.md](ui-badge-n8n.md).
