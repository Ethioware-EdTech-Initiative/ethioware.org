# Chat widget test harness

Drives `assets/js/chatbot.js` in a real browser. The widget's failure modes are
behavioural — a form that freezes mid-submit, a launcher sitting on top of the
send button, a reply whose links aren't clickable — and none of those show up
in a syntax check.

```bash
ci/widget-harness/run.sh            # both suites
ci/widget-harness/run.sh --shots    # also write screenshots to /tmp
```

Needs `php` and a local Chrome (set `CHROME=/path/to/chrome` if it isn't found
automatically). **Not wired into CI** for that reason —
`ci/chatbot-content-test.php` covers what a bare runner can check.

## What's here

| File | Purpose |
|---|---|
| `run.sh` | Boots the server, runs both suites, prints results |
| `router.php` | Mock `/chatbot/api/chat.php` — the real one needs MySQL and a Gemini key |
| `driven-test.html` | Desktop behaviour: a11y wiring, linkified replies, chips, referral, gate form, failure + retry, Escape/focus |
| `mobile-frame.html` | Responsive layout asserted against a genuine 390px viewport |
| `demo.html` | Visual scenes for screenshots (`?scene=chat\|gate\|refer\|greeting`, `&theme=light\|dark`) |
| `phones.html` | Three phone-width frames side by side, for a one-glance visual diff |

## Driving the mock

`router.php` picks a branch from the message text, so a suite can reach every
response shape without a model:

| Message contains | Response |
|---|---|
| `apply` | `refer_apply` + a referral URL |
| `partner` | `request_gate` |
| `unsure` | program chips |
| `messy` | a reply full of markdown the widget must flatten |
| `boom` | HTTP 500 with an HTML body — the network-failure path |
| anything else | a plain reply containing links and an email |

## Why the mobile suite uses an iframe

Headless Chrome clamps `window.innerWidth` to 500 no matter what
`--window-size` says, so `@media (max-width: 480px)` never matches and a
"mobile" screenshot is really desktop layout, cropped. An iframe sized to 390px
gives its document a genuine phone viewport, and media queries inside it
evaluate correctly.
