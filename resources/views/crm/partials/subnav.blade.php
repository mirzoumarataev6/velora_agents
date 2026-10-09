{{--
    Navigation WITHIN the CRM section. The sidebar gets you into the CRM; this
    says which part of it you are in.

    The three focused CRM work areas: pipeline, customer directory, and tasks.
    A customer profile omits this navigation because its explicit return link
    is the relevant context there; repeating the full tabs in a detail view
    made the header feel like two competing back controls.

    Route::has rather than a hardcoded list: a tab pointing at a route that
    does not exist would take the whole page down with it.
--}}
<div class="tabs">
    @foreach ([
        'board' => ['crm.board', 'crm.board'],
        'customers' => ['crm.customers.index', 'crm.customers.*'],
        'tasks' => ['crm.tasks.index', 'crm.tasks.*'],
    ] as $key => [$routeName, $activePattern])
        @continue (! Route::has($routeName))
        <a href="{{ route($routeName) }}"
            data-crm-nav @if (request()->routeIs($activePattern)) aria-current="page" @endif
            class="tab {{ request()->routeIs($activePattern) ? 'tab-active' : '' }}">
            {{ __('crm.nav.'.$key) }}
        </a>
    @endforeach
</div>
