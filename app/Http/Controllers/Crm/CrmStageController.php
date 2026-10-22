<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmStage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CrmStageController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', CrmStage::class);

        $stages = CrmStage::defaultsFor($request->user(), $request->user()->activeTeamId());
        $stages->loadCount('deals');

        return view('crm.stages', [
            'i18nExtraNamespaces' => ['crm'],
            'stages' => $stages,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', CrmStage::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:80'],
            'color' => ['required', 'string', Rule::in(CrmStage::COLORS)],
        ]);

        $stages = CrmStage::defaultsFor($request->user(), $request->user()->activeTeamId());
        $won = $stages->firstWhere('role', 'won');

        DB::transaction(function () use ($request, $data, $won) {
            CrmStage::ownedBy($request->user())
                ->where('position', '>=', $won->position)
                ->increment('position');

            CrmStage::forceCreate([
                'user_id' => $request->user()->id,

                'team_id' => $request->user()->activeTeamId(),
                'name' => trim($data['name']),
                'role' => null,
                'position' => $won->position,
                'color' => $data['color'],
                'is_system' => false,
            ]);
        });

        return redirect()->route('crm.stages.index')->with('status', __('crm.stages.created'));
    }

    public function update(Request $request, CrmStage $stage): RedirectResponse
    {
        $this->authorize('update', $stage);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:80'],
            'color' => ['required', 'string', Rule::in(CrmStage::COLORS)],
        ]);

        $stage->forceFill(['name' => trim($data['name']), 'color' => $data['color'], 'is_system' => false])->save();

        return redirect()->route('crm.stages.index')->with('status', __('crm.stages.saved'));
    }

    public function reorder(Request $request): RedirectResponse
    {
        $this->authorize('create', CrmStage::class);

        $stages = CrmStage::defaultsFor($request->user(), $request->user()->activeTeamId());
        $intermediateIds = $stages->filter(fn (CrmStage $stage) => $stage->role === null)->pluck('id');

        $data = $request->validate([
            'order' => ['required', 'array', 'size:'.$intermediateIds->count()],
            'order.*' => ['integer', Rule::in($intermediateIds)],
        ]);

        if (collect($data['order'])->unique()->count() !== $intermediateIds->count()) {
            return back()->withErrors(['order' => __('crm.stages.reorder_invalid')]);
        }

        $initial = $stages->firstWhere('role', 'initial');
        $won = $stages->firstWhere('role', 'won');

        DB::transaction(function () use ($data, $initial, $won) {
            $initial->update(['position' => 0]);

            foreach ($data['order'] as $index => $stageId) {
                CrmStage::whereKey($stageId)->update(['position' => $index + 1]);
            }

            $won->update(['position' => count($data['order']) + 1]);
        });

        return redirect()->route('crm.stages.index')->with('status', __('crm.stages.saved'));
    }

    public function destroy(Request $request, CrmStage $stage): RedirectResponse
    {
        $this->authorize('delete', $stage);

        $stages = CrmStage::defaultsFor($request->user(), $stage->team_id);
        $destinationId = $request->input('reassign_to');
        $destination = $destinationId !== null ? $stages->firstWhere('id', (int) $destinationId) : null;

        $data = $request->validate([
            'reassign_to' => [$stage->deals()->exists() ? 'required' : 'nullable', Rule::in($stages->pluck('id'))],
        ], [
            'reassign_to.required' => __('crm.stages.reassign_required'),
        ]);

        DB::transaction(function () use ($stage, $destination, $data) {
            if (($data['reassign_to'] ?? null) !== null) {

                $stage->deals()->withTrashed()->update([
                    'crm_stage_id' => $destination->id,
                    'stage' => $destination->name,
                    'stage_role' => $destination->role,
                ]);
            }

            $stage->delete();
        });

        return redirect()->route('crm.stages.index')->with('status', __('crm.stages.deleted'));
    }
}
