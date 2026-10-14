{{-- The sidebar is Turbo-permanent. A full redirect refreshes its workspace label after promotion. --}}
<form method="POST" action="{{ route('teams.store') }}" data-turbo="false" class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-end">
    @csrf
    <div class="min-w-0 flex-1">
        <label for="team-create-name" class="block text-sm font-medium">{{ __('settings.team.name_label') }}</label>
        <input id="team-create-name" name="name" type="text" maxlength="255" required
            value="{{ old('name') }}" placeholder="{{ __('settings.team.name_placeholder') }}"
            class="field mt-1.5 w-full px-4 py-2.5 text-sm" />
        @error('name', 'createTeam')
            <p class="mt-1.5 text-sm text-danger dark:text-danger-light">{{ $message }}</p>
        @enderror
    </div>
    <button type="submit" class="btn btn-primary shrink-0">{{ __('settings.team.create_button') }}</button>
</form>
