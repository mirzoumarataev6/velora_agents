#!/usr/bin/env bash
#
# Vendor the product typeface: Onest 2.001, self-hosted.
#
# WHY NOT THE FONTS PLUGIN. laravel-vite-plugin's bunny() helper fetches from
# fonts.bunny.net, and Bunny (like Google, whose files it mirrors) serves Onest
# in Google's own subsetting, whose cyrillic-ext cut is missing every Tajik
# letter. TG is a first-class locale here, so that cut is disqualifying — and it
# is exactly why an earlier pass recorded "Onest: no Tajik at all" and rejected
# the face. The fault was the distributor, not the typeface: the upstream
# release from the foundry carries all six pairs.
#
# Verified on the files this script downloads, by opening the woff2 and asking
# its cmap (see scripts/verify-font-coverage.mjs):
#   Ғғ U+0492/3 · Ққ U+049A/B · Ҳҳ U+04B2/3
#   Ҷҷ U+04B6/7 · Ӣӣ U+04E2/3 · Ӯӯ U+04EE/F   → all present, 876 glyphs
#
# The upstream woff2 are unsubsetted (~52KB each) and carry scripts this product
# never renders, so each weight is cut down to Latin + Cyrillic here. The
# results are COMMITTED to public/fonts/onest/, so a normal build needs neither
# network nor Python — re-run this only to change weights or bump the version.
#
# They live under public/ rather than resources/ on purpose: served unhashed at
# a stable path, the @font-face blocks in resources/css/app.css and the preload
# links in resources/views/partials/fonts.blade.php can both name the same URL.
# Cache-busting rides on the ?v= version query those two agree on.
#
# Requires: curl, unzip, python3 (a venv with fonttools is made in a temp dir).
#
# Usage: bash scripts/vendor-onest.sh
set -euo pipefail

cd "$(dirname "$0")/.."

VERSION=2.001
DEST=public/fonts/onest
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# 400 body · 500 labels, buttons, nav · 600 headings and KPI figures.
# There is deliberately no 700: the redesign brief caps the product at 600.
declare -A WEIGHTS=( [Regular]=400 [Medium]=500 [SemiBold]=600 )

# Latin + Latin-ext + Cyrillic + Cyrillic-ext, as one file per weight rather
# than Google's seven-way split. Every locale this product ships (en/ru/tg)
# needs Latin AND Cyrillic on the same page, so splitting them would only turn
# one request into two.
UNICODES='U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+2074,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD,U+0100-02AF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF,U+0301,U+0400-045F,U+0490-0491,U+04B0-04B1,U+2116,U+0460-052F,U+1C80-1C88,U+20B4,U+2DE0-2DFF,U+A640-A69F,U+FE2E-FE2F'

echo "→ downloading Onest ${VERSION} from the foundry's GitHub release"
curl -fsSL -o "$TMP/onest.zip" \
    "https://github.com/simpals/onest/releases/download/${VERSION}/onest-${VERSION}.zip"
unzip -q -o "$TMP/onest.zip" -d "$TMP/src"
SRC="$TMP/src/onest-${VERSION}/fonts/webfonts"

echo "→ setting up fonttools"
python3 -m venv "$TMP/venv" >/dev/null
"$TMP/venv/bin/pip" install --quiet 'fonttools[woff]'

mkdir -p "$DEST"
rm -f "$DEST"/*.woff2

echo "→ subsetting to Latin + Cyrillic"
for style in "${!WEIGHTS[@]}"; do
    weight="${WEIGHTS[$style]}"
    out="$DEST/onest-${weight}.woff2"
    "$TMP/venv/bin/pyftsubset" "$SRC/Onest-${style}.woff2" \
        --output-file="$out" \
        --flavor=woff2 \
        --unicodes="$UNICODES" \
        --layout-features='kern,liga,calt,tnum,lnum,onum,pnum,frac,ccmp,locl,mark,mkmk' \
        --no-hinting --desubroutinize --drop-tables+=DSIG
    printf '   %-28s %6s bytes\n' "$(basename "$out")" "$(stat -c%s "$out")"
done

cp "$TMP/src/onest-${VERSION}/OFL.txt" "$DEST/OFL.txt"

# The social-preview wordmark is drawn by scripts/generate-brand-assets.php
# through GD, which cannot read woff2 — so the same face is also exported as a
# TTF for that one job. Unsubsetted, because it never reaches a browser.
mkdir -p resources/brand
cp "$SRC/../ttf/Onest-SemiBold.ttf" resources/brand/Onest-SemiBold.ttf
printf '   %-28s %6s bytes\n' 'Onest-SemiBold.ttf (OG art)' "$(stat -c%s resources/brand/Onest-SemiBold.ttf)"

cat >"$DEST/README.md" <<EOF
# Onest ${VERSION}

The product typeface, self-hosted. SIL Open Font License 1.1 — see \`OFL.txt\`.

Source: https://github.com/simpals/onest/releases/tag/${VERSION} (the foundry's
own release, NOT the Google/Bunny redistribution, whose Cyrillic-ext subset
drops every Tajik letter).

These files are generated — do not edit them. Regenerate with:

    bash scripts/vendor-onest.sh

and confirm coverage with \`npm run build && npm run fonts:verify\`.
EOF

echo "Done → $DEST"
