categoria: ui
regras:
- Buttons use core's component `@MauticCore/Helper/button.html.twig` (variants primary, danger, icon_only, sizes such as xs); the hooks core's JS selects by (class, id, onclick) are passed through `attributes`.
- A button that must show a disabled state, or that core's JS must copy (modal footer), stays a `<button>`, never an <a> (see [ui-modal.md](ui-modal.md)).
- A `data-toggle` is required on a custom menu item: without one core adds `ajax` and navigates (e.g. `data-toggle=n8n-status` on Ativar / Desativar).
- A row action that must act through core's own control CLICKS core's element (the item clicks core's icon), so core sends the request and checks the permission; the script only mirrors the state.
- Options dropdown (Clone/Delete): core's page_actions.html.twig TYPE_BUTTON_DROPDOWN always keeps one button OUTSIDE the dropdown. To get both inside, build it by hand with buttonReset(..., TYPE_DROPDOWN) + buttonAdd() + buttonsRender() like core's list_actions.html.twig. Placed far right with CSS order:1 on the wrapper (n8ndispatch.css), gap via 'ml-xs'.
- Core's `.form-buttons` is position: absolute (FormBundle's field-editor CSS) and pins buttons to a distant corner outside a form: use an own flex row.
- Dangerous or repeatable actions (Reenviar) confirm first and say the contact may receive it twice; a resend is one at a time (no row selection on the Dispatches screen).
- Core hides its toggle icon (`i[class*=-publish-icon]`) on the template lists; the Desativar / Ativar item is the FIRST item of the three-dots menu (customButtons of list_actions, priority 300), only with sms:smses:editown/editother.
