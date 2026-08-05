# Communications → Templates UX

Design and implementation notes for `/admin/communications?sideTab=templates`.

## Problems with the original layout

1. **Long side list first** — member + admin templates before the editor (stacked on mobile).
2. **Three independent dimensions** at once: template × channel (email / in-app / push) × language (EN / AR).
3. **EN + AR always side-by-side** — cramped and tall on phone.
4. **Actions mixed with chrome** — Save / Restore / EN / AR in one row with little hierarchy.
5. **Brand settings inside each email template** — product-wide chrome living in one event’s edit flow.
6. **Raw text preview at the bottom** — not sticky and not channel-shaped.

## Goals

- **Intuitive on mobile** — pick from a list, then edit; never list+editor stacked.
- **Clear hierarchy** — what (event) → how (channel) → copy (language) → check (preview).
- **Harder to save the wrong thing** — sticky Save, single language by default, dirty warnings.
- **Brand chrome separated** from per-event templates.
- **Variables as actions**, not a wall of text.
- **Preview matches the medium** (email card, bell card, SMS bubble).

## Interaction model

### Mobile (`max-width: 1023px`)

| Step | UI |
|------|-----|
| **Browse** | Search + audience filter + grouped list. Tap a row → editor. |
| **Edit** | Sticky header: back + template name + Save. Channel → language → fields → collapsible preview. |

Livewire flag `templatesEditorFocus` drives CSS pane visibility. Desktop always shows list + editor.

### Desktop (`lg+`)

Master–detail: fixed-width list, editor, sticky/channel-shaped preview column.

### Editor vertical order

1. Sticky header (name, audience chip, unsaved indicator, Save, overflow Restore)
2. Channel segmented control + short helper
3. Language EN|AR (optional Compare both on larger screens)
4. Subject + body for active language
5. Variable chips (append placeholder tokens)
6. Preview (shape by channel; collapsible on mobile)

### Brand layout

From name / primary color / footers live under **Email brand** on the templates list pane (collapsed by default), with a link to Settings → Communication for channel toggles. Saved independently via `saveBrandSettings()` — not on each template save when editing email.

### Unsaved changes

Editing EN/AR fields marks `templateDirty`. Switching template or channel while dirty shows a warning and **blocks** the switch until Save or Discard.

## Files

| Piece | Path |
|-------|------|
| Doc | `docs/communications-templates-ux.md` |
| Page | `app/Filament/Tenant/Pages/CommunicationsWorkspacePage.php` |
| View partial | `resources/views/filament/tenant/pages/partials/communications-templates-workspace.blade.php` |
| Styles | `resources/css/filament/tenant/tenant-portal-components.css` |
| Platform overview | `docs/communications-platform.md` |

## Acceptance checklist

- [ ] Mobile starts on template list; editor after selection; back returns to list
- [ ] Desktop shows list + editor together
- [ ] Single language by default; compare toggle dual-pane when off mobile
- [ ] Channel switch reloads that channel’s EN/AR content
- [ ] Variable chips append `{{token}}` to the active body
- [ ] Brand saved separately from templates
- [ ] Preview skin changes for email / in-app / sms_push
- [ ] Dirty switch is blocked with a clear notification
- [ ] Arabic UI strings present for new chrome
