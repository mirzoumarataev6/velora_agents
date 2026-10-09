<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8" />
    <title>@yield('title', __('common.brand'))</title>
</head>
<body>
    <main data-app-main data-crm-navigation-failed="{{ __('crm.navigation_failed') }}"
        class="mx-auto w-full max-w-[100rem] flex-1 px-4 py-6 sm:px-6 lg:px-10 lg:py-8">
        @yield('content')
    </main>
    @stack('scripts')
</body>
</html>
