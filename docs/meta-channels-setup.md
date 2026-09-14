# WhatsApp & Instagram — manual setup checklist

Everything in this file needs a browser and a human. The code side is done and
tested; these are the steps Meta only exposes through its dashboard, plus the
things no API will tell you.

Run the diagnostics first — they answer most questions without any clicking:

```bash
php artisan whatsapp:diagnose  --agent=<id|slug> --app-id=… --app-secret=…
php artisan instagram:diagnose --agent=<id|slug> --app-id=… --app-secret=…
```

Both print Meta's raw responses. Neither paraphrases an error into a guess.

---

## 0. Before anything else — the App Secret is now required

Both channels refuse every incoming webhook unless an **App Secret** is stored,
because that is what Meta's `X-Hub-Signature-256` is verified against. This was
previously not collected at all and both webhooks accepted anything.

- [ ] Meta App Dashboard → **Settings → Basic** → **App Secret** → *Show*
- [ ] Paste it into the Channels tab when connecting WhatsApp / Instagram
- [ ] Any channel connected before this change must be **reconnected** — the
      diagnostics flag it as `app_secret missing — every incoming webhook is REJECTED`

---

## 1. WhatsApp Cloud API

### Phone number
- [ ] Business Manager → WhatsApp Accounts → your WABA → **Phone numbers**
- [ ] Number added and **verified** (SMS/voice code — Meta will not let an API do this)
- [ ] Display name approved (`verified_name` shows in `whatsapp:diagnose` section 3)
- [ ] Quality rating is not `RED` — a flagged number silently stops delivering

### Access token
- [ ] Prefer a **System User token** (Business Settings → System Users → Generate token)
      with `whatsapp_business_messaging` + `whatsapp_business_management`
- [ ] Set it to **never expire**. A 24-hour test token is the single most common
      cause of "it worked yesterday" — `whatsapp:diagnose` section 2 prints the
      real expiry straight from `/debug_token`

### Webhook
- [ ] App Dashboard → WhatsApp → **Configuration** → Callback URL =
      the "Callback URL (now)" value printed by `whatsapp:diagnose`
- [ ] Verify token = the one shown in the Channels tab
- [ ] Subscribe the **`messages`** field (not just `message_template_status_update`)
- [ ] WABA subscribed to the app: diagnose section 4 → `subscribed_apps` non-empty
- [ ] `php artisan whatsapp:diagnose --fix --app-id=… --app-secret=…` repairs the
      callback URL without touching the dashboard

### Business verification
- [ ] Business verified, or you are inside the unverified-tier limits
      (250 unique customers / 24h). Beyond it, sends start failing with code 131056.

---

## 2. Instagram Messaging

### Account
- [ ] Instagram account is **Business** or **Creator** (personal accounts cannot
      receive the messaging webhook at all)
- [ ] If using the Facebook Page flow: account linked to a Page, and the Page
      linked to the app
- [ ] Instagram app → **Settings → Privacy → Messages** →
      *Allow access to messages* is **ON**. This is off by default and there is
      no API to read it; with it off, the webhook never fires and everything
      else looks perfect.

### Token
Two flows exist and they are not interchangeable:

| Flow | Token prefix | Host |
|---|---|---|
| Instagram API with Instagram Login | `IGAA…` | `graph.instagram.com` |
| Facebook Page (legacy Messenger) | `EAA…` | `graph.facebook.com` |

The driver and the diagnostics both pick the host from the prefix. A token used
against the wrong host fails with "Cannot parse access token" even when it is
perfectly valid.

- [ ] Token has `instagram_business_manage_messages` (or `instagram_manage_messages`)
- [ ] `instagram:diagnose` section 2 authenticates it

### Webhook
- [ ] App Dashboard → Webhooks → object **`instagram`** → Callback URL =
      the value printed by `instagram:diagnose`
- [ ] Subscribe the **`messages`** field. Subscribing only `comments` gives you a
      green webhook that never delivers a DM — diagnose section 4 warns on this.

### ⚠️ App Mode — the one that wastes the most time
- [ ] App Dashboard → top bar → **App Mode**

In **Development** mode, Instagram DMs work *only* for accounts added as
**testers** (App Roles → Roles → add Instagram testers, then accept the invite
from the tester's Instagram account). Everyone else gets total silence: no
error, no webhook, nothing in any log.

Meta exposes no API for this setting, so no command here can check it. If every
diagnostic is green and real customers still get no reply, this is almost
always why.

- [ ] Switch to **Live** for real customers, which requires App Review for
      `instagram_business_manage_messages`

---

## 3. What the platforms genuinely cannot do

Not bugs, and not worth chasing. The app degrades honestly for each rather than
pretending.

| Feature | Telegram | WhatsApp | Instagram |
|---|---|---|---|
| 👍/👎 reply rating | ✅ | ❌ | ❌ |
| Humanized multi-message replies | ✅ | ✅ | ✅ |
| Typing indicator | ✅ | ❌ | ✅ |
| Voice notes transcribed | ✅ | ❌ | ❌ |
| Photos understood | ✅ | ✅ | ✅ |
| Lead / order capture | ✅ | ✅ | ✅ |
| Price haggling flow | ✅ | ✅ * | ✅ * |
| Human takeover | ✅ | ✅ * | ✅ * |
| Free-form reply any time | ✅ | ❌ 24h | ❌ 24h |

**\* Subject to the 24-hour window.** Both Meta platforms refuse a free-form
message more than 24 hours after the customer's last one. This matters most for
the haggling flow and human takeover, where the owner may reply the next
morning: the app now refuses loudly (the operator sees "wait until they write
again") instead of recording a reply that was never sent.

Raising it needs one of:
- WhatsApp: a **pre-approved message template** (not implemented)
- Instagram: the **HUMAN_AGENT** tag, extending to 7 days (needs App Review)

**No rating buttons on Meta.** WhatsApp and Instagram can both draw buttons, but
neither can edit a sent message to retire them after a vote, and both return a
tap as a *new inbound message* that cannot be attributed to a specific reply.
`supportsMessageFeedback()` returns false for both and the reply goes out plain.

**Unreadable attachments.** A voice note, video, PDF or location now gets a
polite "I can only read text and photos" reply on both platforms. It used to get
silence, which from the customer's side is indistinguishable from a dead bot.
