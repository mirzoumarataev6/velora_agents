{{-- The Мои данные section's own sub-navigation, in the same shape as the CRM's.

     Held back until there was a second place to go — a tab bar with one tab is
     chrome that says nothing — and rendered from the moment dashboards landed,
     which is what the earlier version of this comment promised. Without it the
     boards were reachable only by typing a URL.

     The tabs are "Таблицы" and "Дашборды" rather than repeating the section's
     own name: the sidebar already says Мои данные, and a tab that echoes its
     section tells a reader nothing about where it leads. --}}
<div class="tabs">
    <a href="{{ route('bi.sources.index') }}"
        class="tab {{ request()->routeIs('bi.sources.*') ? 'tab-active' : '' }}"
        data-i18n="bi.nav.sources">{{ __('bi.nav.sources') }}</a>
    <a href="{{ route('bi.dashboards.index') }}"
        class="tab {{ request()->routeIs('bi.dashboards.*') ? 'tab-active' : '' }}"
        data-i18n="bi.nav.dashboards">{{ __('bi.nav.dashboards') }}</a>
</div>
