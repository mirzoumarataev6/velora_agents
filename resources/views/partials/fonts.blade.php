@php
    /**
     * Font loading, tuned for first paint.
     *
     * `Vite::fonts()` is correct but preloads EVERY subset the plugin generated —
     * dozens of files (7 unicode ranges × every weight/family pair), almost all
     * of which a given visitor never renders: a Russian reader does not need the
     * Greek or Vietnamese cut. Preloading them competes with CSS for bandwidth
     * on the critical path, which is exactly backwards. With two families now
     * shipping instead of one, that gap only widened.
     *
     * So: link the generated @font-face stylesheet as-is (it already carries
     * font-display: swap and per-face unicode-range, so the browser downloads
     * only the subsets it actually needs), and hand-pick the handful of preloads
     * that matter above the fold — body text and the hero headline, in the
     * script the current locale is written in.
     *
     * Dev/hot mode has no build manifest, so it falls back to Vite::fonts().
     */
    $__manifestPath = public_path('build/fonts-manifest.json');
    $__hot = file_exists(public_path('hot'));
    $__manifest = (! $__hot && file_exists($__manifestPath))
        ? json_decode(file_get_contents($__manifestPath), true)
        : null;

    $__preloads = [];
    $__stylesheet = null;

    if (is_array($__manifest)) {
        $__stylesheet = isset($__manifest['style']['file'])
            ? asset('build/'.$__manifest['style']['file'])
            : null;

        // Probe codepoints: a subset is "the one we need" if its unicode-range
        // covers the script this locale is written in.
        //   Tajik adds the extended Cyrillic block (ғ қ ҳ ҷ ӣ ӯ), which lives in
        //   a different subset file than the basic Cyrillic of Russian.
        $__probes = match (app()->getLocale()) {
            'ru' => [0x0410],
            'tg' => [0x0410, 0x04E3],
            default => [0x0041],
        };

        // Faces that render above the fold on every surface: body copy, and the
        // display face the page title is set in.
        //
        // The alias is whatever the fonts plugin slugged the family to — a
        // single-word family slugs to itself, a multi-word one gets kebabbed —
        // so each lookup is defensive and falls back to preloading nothing
        // (never to preloading the wrong file).
        $__sans = isset($__manifest['style']['familyStyles']['geist'])
            ? 'geist'
            : 'Geist';
        $__serif = isset($__manifest['style']['familyStyles']['source-serif-4'])
            ? 'source-serif-4'
            : null;

        // Sans 400 is body copy and 500 the heaviest UI weight above the fold.
        // Serif 400 is every h1/hero on the product — the single most visible
        // string on the page, and the one whose swap-in is most obvious if it
        // arrives late. Serif 600 and sans 600 ship but render below the fold,
        // so preloading them would spend bandwidth on faces nobody sees first.
        $__wanted = [[$__sans, 400], [$__sans, 500]];
        if ($__serif !== null) {
            $__wanted[] = [$__serif, 400];
        }

        $__covers = function (string $range, int $cp): bool {
            foreach (explode(',', $range) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                $part = ltrim($part, 'Uu+');
                if (str_contains($part, '-')) {
                    [$from, $to] = explode('-', $part, 2);
                    if ($cp >= hexdec($from) && $cp <= hexdec($to)) {
                        return true;
                    }
                } elseif (hexdec($part) === $cp) {
                    return true;
                }
            }

            return false;
        };

        foreach ($__wanted as [$__alias, $__weight]) {
            $__css = $__manifest['style']['familyStyles'][$__alias] ?? '';
            if ($__css === '') {
                continue;
            }

            // Each @font-face block carries one src url and one unicode-range.
            preg_match_all(
                '#@font-face\s*\{(?<body>[^}]*)\}#s',
                $__css,
                $__blocks,
                PREG_SET_ORDER
            );

            foreach ($__blocks as $__block) {
                $__body = $__block['body'];

                // woff2 only — woff is the legacy fallback and must not be preloaded.
                if (! preg_match('#url\("([^"]+\.woff2)"\)#', $__body, $__url)) {
                    continue;
                }
                if (! preg_match('#font-weight:\s*(\d+)#', $__body, $__w) || (int) $__w[1] !== $__weight) {
                    continue;
                }
                if (! preg_match('#unicode-range:\s*([^;]+);#', $__body, $__r)) {
                    continue;
                }

                foreach ($__probes as $__cp) {
                    if ($__covers($__r[1], $__cp)) {
                        $__preloads[$__url[1]] = true;
                        break;
                    }
                }
            }
        }
    }
@endphp

@if ($__stylesheet)
    @foreach (array_keys($__preloads) as $__href)
        <link rel="preload" as="font" type="font/woff2" href="{{ $__href }}" crossorigin="anonymous" />
    @endforeach
    <link rel="stylesheet" href="{{ $__stylesheet }}" />
@else
    {{-- Dev server, or a build that predates the fonts manifest. --}}
    {{ Vite::fonts() }}
@endif

