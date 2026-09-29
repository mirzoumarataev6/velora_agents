# Eskhata re-skin — screenshots

**This folder is empty of images, and that is a gap, not a decision.**

The re-skin is applied and every surface renders (all eight return 200 and were
checked in the emitted HTML), but no screenshot could be taken on the machine
this was built on. Run `bash scripts/screenshots.sh` anywhere with a working
Chrome or Chromium and the eight PNGs below appear here.

## Why it could not be done here

The only browser on the box is the **Firefox snap**, and its headless mode fails
on every invocation:

```
[GFX1-]: RenderCompositorSWGL failed mapping default framebuffer, no dt
```

That is the software compositor failing to allocate a framebuffer inside the
snap sandbox — no screenshot is written and the process exits 1. It was retried
with `MOZ_ACCELERATED=0`, `MOZ_WEBRENDER=0`, `LIBGL_ALWAYS_SOFTWARE=1`,
`MESA_LOADER_DRIVER_OVERRIDE=llvmpipe`, `MOZ_LAYERS_ALLOW_SOFTWARE_GL=1`, with
and without `--window-size`, and with the output path inside `$HOME` (the snap
cannot write outside it — a separate failure that produced
`Could not find profile folder` whenever `--profile` pointed at a temp dir).
All eight combinations produce the same error.

The usual fallback, Playwright's own Chromium, could not be installed either:
the npm registry is unreachable from this machine (`npm install` hangs until it
is killed, while direct HTTPS to other hosts works fine). `Xvfb`, `wkhtmltoimage`,
`cutycapt` and any Chromium are all absent.

## What the script produces

| file | surface |
|---|---|
| `landing.png` | `/ru` |
| `login.png` | `/ru/login` |
| `dashboard.png` | `/dashboard` |
| `agents.png` | `/agents` |
| `agent-detail.png` | first seeded agent |
| `inbox.png` | `/conversations` |
| `leads.png` | `/leads` |
| `settings.png` | `/settings` |

It migrates and seeds a **throwaway SQLite database in a temp dir** — the local
MySQL `agent` database is never touched — serves the app on port 8899, logs in
as the seeded demo owner, and captures each page at 1440px wide.

One wrinkle it handles: a headless browser started fresh has no session, and
there is no portable way to hand one a cookie jar. So each authenticated page is
fetched with curl and staged under `public/__shots/`, then rendered from there;
every asset URL the app emits is absolute, so the page loads exactly as served.
The staging directory is removed on exit.

## Comparing against the reference

The source screenshots are in `docs/reference/eskhata/`. The palette sampled
from them, the three greys that were deliberately darkened for contrast, and the
measured radii are all documented at the top of `tailwind.config.js`.
