<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CrmWorkspaceController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', CrmWorkspace::class);

        $teamId = $request->user()->activeTeamId();

        $data = $request->validate([
            'name' => [
                'required', 'string', 'min:2', 'max:80',
                Rule::unique('crm_workspaces', 'name')->where('team_id', $teamId),
                function ($attribute, $value, $fail) {
                    if (mb_strtolower(trim($value)) === '__main__') {
                        $fail(__('crm.workspaces.reserved_name'));
                    }
                },
            ],
            'color' => ['required', 'string', Rule::in(CrmWorkspace::COLORS)],
        ]);

        $workspace = new CrmWorkspace;
        $workspace->forceFill([
            'user_id' => $request->user()->id,

            'team_id' => $teamId,
            'name' => trim($data['name']),
            'color' => $data['color'],
        ])->save();

        return redirect()->route('crm.board', $this->returnFilters($request) + ['workspace' => $workspace->id])
            ->with('status', __('crm.workspaces.created'));
    }

    public function update(Request $request, CrmWorkspace $workspace): RedirectResponse
    {
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'min:2', 'max:80',
                Rule::unique('crm_workspaces', 'name')->where('team_id', $workspace->team_id)->ignore($workspace->id),
            ],
            'color' => ['required', 'string', Rule::in(CrmWorkspace::COLORS)],
        ]);

        $workspace->update(['name' => trim($data['name']), 'color' => $data['color']]);

        return redirect()->route('crm.board', $this->returnFilters($request) + ['workspace' => $workspace->id])
            ->with('status', __('crm.workspaces.saved'));
    }

    public function destroy(Request $request, CrmWorkspace $workspace): RedirectResponse
    {
        $this->authorize('delete', $workspace);

        DB::transaction(function () use ($request, $workspace) {

            $default = CrmWorkspace::defaultFor($request->user(), $workspace->team_id);
            $workspace->deals()->update(['crm_workspace_id' => $default->id]);
            $workspace->customers()->update(['crm_workspace_id' => $default->id]);
            $workspace->delete();
        });

        return redirect()->route('crm.board', $this->returnFilters($request) + ['workspace' => null])
            ->with('status', __('crm.workspaces.deleted'));
    }

    private function returnFilters(Request $request): array
    {
        return array_filter([
            'origin' => $request->input('origin'),
            'stage' => $request->input('stage'),
        ], fn ($value) => filled($value));
    }
}
