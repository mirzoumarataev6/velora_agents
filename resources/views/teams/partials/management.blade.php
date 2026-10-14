<div class="card p-5 sm:p-6">
    @if (! $canViewTeam)
        <p class="mt-4 rounded-xl bg-sunk px-4 py-3 text-sm text-muted dark:bg-night-raised dark:text-night-muted" data-i18n="settings.team.not_owner">{{ __('settings.team.not_owner') }}</p>
    @else
        @can('team.members.view')
        <h3 class="mt-6 text-sm font-semibold" data-i18n="settings.team.members_heading">{{ __('settings.team.members_heading') }}</h3>
        <ul class="mt-3 divide-y divide-black/5 dark:divide-white/10">
            @foreach ($teamMembers as $memberMembership)
                <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">{{ $memberMembership->user->name }}</p>
                        <p class="truncate text-xs text-muted dark:text-night-muted">{{ $memberMembership->user->email }}</p>
                    </div>
                    @if ($memberMembership->role->is_owner)
                        <span class="badge badge-neutral">{{ __('agents.nav.teams.roles.owner') }}</span>
                    @else
                        <div class="flex flex-wrap items-center gap-2">
                            @can('team.roles.manage')
                            <form method="POST" action="{{ route('teams.members.update', $memberMembership) }}" class="flex items-center gap-2">
                                @csrf
                                @method('PATCH')
                                <label for="team-member-role-{{ $memberMembership->id }}" class="sr-only">{{ __('settings.team.role_label') }}</label>
                                <select id="team-member-role-{{ $memberMembership->id }}" name="role_id" class="field px-3 py-2 text-sm">
                                    @foreach ($teamRoles as $roleOption)
                                        <option value="{{ $roleOption->id }}" @selected((int) $memberMembership->role_id === $roleOption->id)>
                                            {{ $roleOption->displayName() }}
                                        </option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn btn-ghost btn-sm" data-i18n="settings.team.change_role_button">{{ __('settings.team.change_role_button') }}</button>
                            </form>
                            @else
                                <span class="badge badge-neutral">{{ $memberMembership->role->displayName() }}</span>
                            @endcan
                            @can('team.members.remove')
                            <form method="POST" action="{{ route('teams.members.destroy', $memberMembership) }}"
                                x-on:submit="if (! window.confirm(window.__i18n.messages[window.__i18n.current].settings.team.remove_confirm)) $event.preventDefault()">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-ghost btn-sm" data-i18n="settings.team.remove_member_button">{{ __('settings.team.remove_member_button') }}</button>
                            </form>
                            @endcan
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>

        @endcan
        @can('team.members.invite')
        <h3 class="mt-7 border-t border-hairline pt-5 text-sm font-semibold dark:border-white/10" data-i18n="settings.team.invite_heading">{{ __('settings.team.invite_heading') }}</h3>
        <p class="mt-1 text-sm text-muted dark:text-night-muted" data-i18n="settings.team.invite_description">{{ __('settings.team.invite_description') }}</p>

        <form method="POST" action="{{ route('teams.invite') }}" class="mt-4 flex flex-wrap items-start gap-3">
            @csrf
            <div class="min-w-0 flex-1">
                <label for="team-invite-email" class="sr-only" data-i18n="settings.team.email_label">{{ __('settings.team.email_label') }}</label>
                <input id="team-invite-email" name="email" type="email" required autocomplete="email"
                    placeholder="{{ __('settings.team.email_label') }}" value="{{ old('email') }}"
                    class="field w-full px-4 py-2.5 text-sm" />
                @error('email', 'team')
                    <p class="mt-1.5 text-sm text-danger dark:text-danger-light">{{ $message }}</p>
                @enderror
            </div>
            <div class="w-40 shrink-0">
                <label for="team-invite-role" class="sr-only" data-i18n="settings.team.role_label">{{ __('settings.team.role_label') }}</label>
                <select id="team-invite-role" name="role_id" required class="field w-full px-3 py-2.5 text-sm">
                    @foreach ($teamRoles as $roleOption)
                        <option value="{{ $roleOption->id }}" @selected((int) old('role_id') === $roleOption->id)>
                            {{ $roleOption->displayName() }}
                        </option>
                    @endforeach
                </select>
                @error('role_id', 'team')
                    <p class="mt-1.5 text-sm text-danger dark:text-danger-light">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit" class="btn btn-primary shrink-0">
                <span data-i18n="settings.team.invite_button">{{ __('settings.team.invite_button') }}</span>
            </button>
        </form>

        <h3 class="mt-7 border-t border-hairline pt-5 text-sm font-semibold dark:border-white/10" data-i18n="settings.team.pending_heading">{{ __('settings.team.pending_heading') }}</h3>

        @if ($pendingInvitations->isEmpty())
            <p class="mt-3 text-sm text-muted dark:text-night-muted" data-i18n="settings.team.pending_empty">{{ __('settings.team.pending_empty') }}</p>
        @else
            <ul class="mt-3 divide-y divide-black/5 dark:divide-white/10">
                @foreach ($pendingInvitations as $invitation)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">
                                {{ $invitation->email }}
                                <span class="badge badge-neutral ml-2">{{ $invitation->role->displayName() }}</span>
                            </p>
                            <p class="mt-0.5 text-xs text-muted dark:text-night-muted">
                                {{ __('settings.team.pending_invited_by', ['name' => $invitation->inviter?->name ?? '—']) }}
                                · {{ __('settings.team.pending_expires', ['date' => $invitation->expires_at->translatedFormat('d MMM Y')]) }}
                            </p>
                        </div>
                        <form method="POST" action="{{ route('teams.invitations.destroy', $invitation) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-ghost btn-sm shrink-0">
                                <span data-i18n="settings.team.revoke_button">{{ __('settings.team.revoke_button') }}</span>
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
        @endcan
    @endif
</div>
