{{--
    Navigation WITHIN the CRM section. The sidebar gets you into the CRM; this
    says which part of it you are in.

    Two tabs, not three: a customer used to have its own directory here,
    competing with the deal list for the same attention even though it held
    the same people, one level of indirection removed. A customer is reached
    FROM a deal now (open one, their profile is right there in the panel) or
    from search — see crm.customers.show, kept alive as a deep-link target
    but no longer a peer screen with its own tab.

    Route::has rather than a hardcoded list: a tab pointing at a route that
    does not exist would take the whole page down with it.
--}}
<div class="tabs">
    @foreach ([
        'board' => ['crm.board', 'crm.board'],
        'tasks' => ['crm.tasks.index', 'crm.tasks.*'],
    ] as $key => [$routeName, $activePattern])
        @continue (! Route::has($routeName))
        <a href="{{ route($routeName) }}"
            @if (request()->routeIs($activePattern)) aria-current="page" @endif
            class="tab {{ request()->routeIs($activePattern) ? 'tab-active' : '' }}">
            {{ __('crm.nav.'.$key) }}
        </a>
    @endforeach
</div>
