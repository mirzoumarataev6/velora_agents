{{--
    Legacy stub. The /home route redirects to /agents, so this view is not
    reachable in normal use — it is kept only as a fallback logout form. Updated
    to the compiled bundle and the green palette so it can't reintroduce the old
    brand colour if something ever renders it.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{{ __('common.brand') }}</title>
    @include('partials.favicons')
    @vite(['resources/css/app.css'])
</head>
<body class="bg-paper text-ink antialiased dark:bg-night dark:text-night-ink">
    <form method="POST" action="{{ route('logout') }}" class="p-6">
        @csrf
        <button type="submit"
            class="btn btn-primary">
            {{ __('agents.nav.logout') }}
        </button>
    </form>
</body>
</html>
