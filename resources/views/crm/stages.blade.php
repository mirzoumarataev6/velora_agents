@extends('layouts.dashboard')

@section('title', __('crm.stages.title'))

@section('content')
<div class="crm-page" x-data="{ editing: null, adding: false, deleting: null }">
    <div class="page-header">
        <div>
            <a href="{{ route('crm.board') }}" class="page-eyebrow hover:underline">{{ __('crm.customers.back') }}</a>
            <h1 class="page-title">{{ __('crm.stages.heading') }}</h1>
            <p class="page-sub">{{ __('crm.stages.subheading') }}</p>
        </div>
    </div>

    @if (session('status'))
        <p class="card mt-4 px-4 py-3 text-sm text-success">{{ session('status') }}</p>
    @endif
    @error('order')
        <p class="card mt-4 px-4 py-3 text-sm text-danger">{{ $message }}</p>
    @enderror

    @php
        // The reorder endpoint takes the intermediates' ids in the desired
        // order; an up/down click posts that same list with two neighbours
        // swapped, rather than anything position-arithmetic in the view.
        $intermediateIds = $stages->filter(fn ($s) => $s->role === null)->pluck('id')->values();
        $swapped = function (int $a, int $b) use ($intermediateIds) {
            $ids = $intermediateIds->values()->all();
            [$ids[$a], $ids[$b]] = [$ids[$b], $ids[$a]];

            return $ids;
        };
    @endphp

    <div class="card mt-5 divide-y divide-hairline dark:divide-white/10">
        @foreach ($stages as $stage)
            <div class="flex flex-wrap items-center gap-3 p-4">
                <span class="h-3 w-3 shrink-0 rounded-full {{ \App\Models\CrmStage::dotClass($stage->color) }}"></span>

                <div class="min-w-0 flex-1">
                    <p class="flex items-center gap-2 font-medium">
                        <span>{{ $stage->displayName() }}</span>
                        @if ($stage->role)
                            <span class="badge badge-neutral text-xs">{{ __('crm.stages.roles.'.$stage->role) }}</span>
                        @endif
                    </p>
                    <p class="text-xs text-muted dark:text-night-muted">
                        {{ trans_choice('crm.stages.deal_count', $stage->deals_count, ['count' => $stage->deals_count]) }}
                    </p>
                </div>

                {{-- Reordering is for intermediates only — initial and won
                     always bracket the pipeline, see CrmStageController::reorder(). --}}
                @can('analytics.manage')
                @if ($stage->role === null)
                    @php $position = $intermediateIds->search($stage->id); @endphp
                    <div class="flex shrink-0 gap-1">
                        <form method="POST" action="{{ route('crm.stages.reorder') }}">
                            @csrf
                            @foreach ($swapped($position, max(0, $position - 1)) as $id)
                                <input type="hidden" name="order[]" value="{{ $id }}" />
                            @endforeach
                            <button type="submit" class="btn btn-ghost btn-sm"
                                aria-label="{{ __('crm.stages.move_up') }}" {{ $position === 0 ? 'disabled' : '' }}>↑</button>
                        </form>
                        <form method="POST" action="{{ route('crm.stages.reorder') }}">
                            @csrf
                            @foreach ($swapped($position, min($intermediateIds->count() - 1, $position + 1)) as $id)
                                <input type="hidden" name="order[]" value="{{ $id }}" />
                            @endforeach
                            <button type="submit" class="btn btn-ghost btn-sm"
                                aria-label="{{ __('crm.stages.move_down') }}" {{ $position === $intermediateIds->count() - 1 ? 'disabled' : '' }}>↓</button>
                        </form>
                    </div>
                @endif

                <button type="button" class="btn btn-ghost btn-sm shrink-0" @click="editing = editing === {{ $stage->id }} ? null : {{ $stage->id }}">
                    {{ __('crm.stages.edit') }}
                </button>

                @if ($stage->role === null)
                    <button type="button" class="btn btn-ghost btn-sm shrink-0 text-danger" @click="deleting = deleting === {{ $stage->id }} ? null : {{ $stage->id }}">
                        {{ __('crm.stages.delete') }}
                    </button>
                @endif
                @endcan
            </div>

            @can('analytics.manage')
            <div x-show="editing === {{ $stage->id }}" x-cloak class="border-t border-hairline bg-sunk/40 p-4 dark:border-white/10 dark:bg-night-raised/40">
                <form method="POST" action="{{ route('crm.stages.update', $stage) }}" class="space-y-3">
                    @csrf
                    @method('PATCH')
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="stage-name-{{ $stage->id }}">{{ __('crm.stages.name') }}</label>
                        <input id="stage-name-{{ $stage->id }}" name="name" value="{{ $stage->is_system ? __('crm.stages.'.$stage->name) : $stage->name }}"
                            required maxlength="80" class="field w-full max-w-sm px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <p class="mb-1.5 text-sm font-medium">{{ __('crm.stages.color') }}</p>
                        <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="{{ __('crm.stages.color') }}">
                            @foreach (\App\Models\CrmStage::COLORS as $color)
                                <label class="flex cursor-pointer items-center gap-2 border border-hairline px-3 py-2 text-sm transition-colors has-[:checked]:border-brand has-[:checked]:bg-brand/5 has-[:checked]:text-brand hover:bg-sunk dark:border-white/10 dark:hover:bg-night-raised">
                                    <input class="sr-only" type="radio" name="color" value="{{ $color }}" @checked($color === $stage->color) />
                                    <span aria-hidden="true" class="h-3.5 w-3.5 shrink-0 rounded-full {{ \App\Models\CrmStage::dotClass($color) }}"></span>
                                    <span>{{ __('crm.workspaces.colors.'.$color) }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">{{ __('crm.stages.save') }}</button>
                        <button type="button" class="btn btn-secondary btn-sm" @click="editing = null">{{ __('crm.stages.cancel') }}</button>
                    </div>
                </form>
            </div>

            @if ($stage->role === null)
                <div x-show="deleting === {{ $stage->id }}" x-cloak class="border-t border-hairline bg-sunk/40 p-4 dark:border-white/10 dark:bg-night-raised/40">
                    <form method="POST" action="{{ route('crm.stages.destroy', $stage) }}" class="space-y-3"
                        @if ($stage->deals_count === 0) onsubmit="return confirm(@js(__('crm.stages.delete_confirm_empty')))" @endif>
                        @csrf
                        @method('DELETE')
                        @if ($stage->deals_count > 0)
                            <p class="text-sm text-muted dark:text-night-muted">
                                {{ trans_choice('crm.stages.delete_warning', $stage->deals_count, ['count' => $stage->deals_count]) }}
                            </p>
                            <div>
                                <label class="mb-1 block text-sm font-medium" for="reassign-to-{{ $stage->id }}">{{ __('crm.stages.reassign_to') }}</label>
                                <select id="reassign-to-{{ $stage->id }}" name="reassign_to" required class="field w-full max-w-sm px-3 py-2 text-sm">
                                    @foreach ($stages->whereNotIn('id', [$stage->id]) as $other)
                                        <option value="{{ $other->id }}">{{ $other->displayName() }}</option>
                                    @endforeach
                                </select>
                                @error('reassign_to')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                            </div>
                        @endif
                        <div class="flex gap-2">
                            <button type="submit" class="btn btn-danger-soft btn-sm">{{ __('crm.stages.delete_confirm') }}</button>
                            <button type="button" class="btn btn-secondary btn-sm" @click="deleting = null">{{ __('crm.stages.cancel') }}</button>
                        </div>
                    </form>
                </div>
            @endif
            @endcan
        @endforeach
    </div>

    @can('analytics.manage')
    <div class="card mt-4 card-pad">
        <button type="button" x-show="!adding" @click="adding = true" class="btn btn-secondary btn-sm">{{ __('crm.stages.add') }}</button>
        <form x-show="adding" x-cloak method="POST" action="{{ route('crm.stages.store') }}" class="space-y-3">
            @csrf
            <div>
                <label class="mb-1 block text-sm font-medium" for="new-stage-name">{{ __('crm.stages.name') }}</label>
                <input id="new-stage-name" name="name" required maxlength="80" placeholder="{{ __('crm.stages.name_placeholder') }}"
                    class="field w-full max-w-sm px-3 py-2 text-sm" />
                @error('name')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
            </div>
            <div>
                <p class="mb-1.5 text-sm font-medium">{{ __('crm.stages.color') }}</p>
                <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="{{ __('crm.stages.color') }}">
                    @foreach (\App\Models\CrmStage::COLORS as $color)
                        <label class="flex cursor-pointer items-center gap-2 border border-hairline px-3 py-2 text-sm transition-colors has-[:checked]:border-brand has-[:checked]:bg-brand/5 has-[:checked]:text-brand hover:bg-sunk dark:border-white/10 dark:hover:bg-night-raised">
                            <input class="sr-only" type="radio" name="color" value="{{ $color }}" @checked($color === 'blue') />
                            <span aria-hidden="true" class="h-3.5 w-3.5 shrink-0 rounded-full {{ \App\Models\CrmStage::dotClass($color) }}"></span>
                            <span>{{ __('crm.workspaces.colors.'.$color) }}</span>
                        </label>
                    @endforeach
                </div>
                @error('color')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">{{ __('crm.stages.add') }}</button>
                <button type="button" class="btn btn-secondary btn-sm" @click="adding = false">{{ __('crm.stages.cancel') }}</button>
            </div>
        </form>
    </div>
    @endcan
</div>
@endsection
