# Communications platform

FundFlow separates **conversations** from **alerts**, and delivers alerts through a shared template + preference pipeline.

## Surfaces

| Surface | Job |
|---|---|
| Messages (member + admin inbox) | 1:1 admin↔member DMs and support requests |
| Bell + Alerts tab | Durable system alerts and announcements |
| Filament toasts | Immediate action feedback only |
| Delivery log | Audit of channel attempts (`notification_logs`) |

Announcements use the **bell** (database notification), not Direct Messages.

## Admin workspace

Sidebar **Communications** (`/admin/communications`):

- **Inbox** — member DM threads (legacy `/admin/messages` redirects here)
- **Announcements** — compose / schedule / history
- **Templates** — EN/AR Markdown templates + brand email chrome
- **Delivery log** — notification delivery attempts
- **Settings** — communication / Twilio toggles

One page shell owns all tabs. Header actions are tab-scoped (Inbox: support + message all; Announcements: compose).

Bank **SMS import / Bank SMS clearing** remains under Finance and is not member messaging.

## Templates

Tenant table `notification_templates` keyed by `key` + `locale` + `channel_family`:

| Channel family | Used for |
|---|---|
| `email` | Branded email (`mail.branded-notification`) + brand chrome settings |
| `in_app` | Member bell / database notifications + Alerts history |
| `sms_push` | Web push, SMS, and WhatsApp (plain text; Markdown stripped) |

Open **Communications → Templates**, pick an event, then switch **Email / In-app (bell) / Push & SMS** to edit each channel separately (EN/AR). Restore defaults resets all channel families for that event.

## Preferences

Member Settings → Notifications categories are honored by `DeliversToMemberChannels` via `NotificationPreferenceService` for every categorized member notification in the catalog.
