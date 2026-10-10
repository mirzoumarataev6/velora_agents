<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmStage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The owner's own pipeline: add, rename, recolour, reorder and remove the
 * stages in between the fixed initial/won/lost ones. See CrmStage's
 * docblock for the role invariant this never lets through a request.
 */
class CrmStageController extends Controller
{
    public function index(Request $request): View
    {
        $stages = CrmStage::defaultsFor($request->user());
        $stages->loadCount('deals');

        return view('crm.stages', [
            'i18nExtraNamespaces' => ['crm'],
            'stages' => $stages,
        ]);
    }

    /** A new intermediate stage, inserted right before the WON stage — the owner can reorder it afterward. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', CrmStage::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:80'],
            'color' => ['required', 'string', Rule::in(CrmStage::COLORS)],
        ]);

        $stages = CrmStage::defaultsFor($request->user());
        $won = $stages->firstWhere('role', 'won');

        DB::transaction(function () use ($request, $data, $won) {
            CrmStage::ownedBy($request->user())
                ->where('position', '>=', $won->position)
                ->increment('position');

            CrmStage::forceCreate([
                'user_id' => $request->user()->id,
                'name' => trim($data['name']),
                'role' => null,
                'position' => $won->position,
                'color' => $data['color'],
                'is_system' => false,
            ]);
        });

        return redirect()->route('crm.stages.index')->with('status', __('crm.stages.created'));
    }

    /** Rename and/or recolour — the role is never part of this payload, see CrmStage::booted(). */
    public function update(Request $request, CrmStage $stage): RedirectResponse
    {
        $this->authorize('update', $stage);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:80'],
            'color' => ['required', 'string', Rule::in(CrmStage::COLORS)],
        ]);

        // forceFill: is_system is deliberately outside #[Fillable] (an
        // owner request should never be able to set it directly), so
        // flipping it here — the one legitimate place it changes — needs
        // the same bypass CrmStage::defaultsFor() uses to seed it true.
        // Touched by hand, it stops reading as a translation key and starts
        // reading as the owner's own literal text, even if they happened to
        // retype the exact default word — see CrmStage::displayName().
        $stage->forceFill(['name' => trim($data['name']), 'color' => $data['color'], 'is_system' => false])->save();

        return redirect()->route('crm.stages.index')->with('status', __('crm.stages.saved'));
    }

    /**
     * The owner's chosen order for the intermediate stages — initial and
     * won are never part of this payload, and always stay first and last:
     * "forward" (DealStageAutomation's forward-only rule, the board's own
     * tabs) only means anything with a fixed start and end to measure from.
     */
    public function reorder(Request $request): RedirectResponse
    {
        $stages = CrmStage::defaultsFor($request->user());
        $intermediateIds = $stages->filter(fn (CrmStage $stage) => $stage->role === null)->pluck('id');

        $data = $request->validate([
            'order' => ['required', 'array', 'size:'.$intermediateIds->count()],
            'order.*' => ['integer', Rule::in($intermediateIds)],
        ]);

        // Every id in `order` must be one of this owner's intermediate
        // stages (checked above) AND every one of those stages must appear
        // exactly once — Rule::in alone would silently accept a shorter
        // list with duplicates padding it out to the right `size`.
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

    /**
     * Deletes an intermediate stage, reassigning every deal sitting in it
     * to a destination the owner picks — never silently, and never to a
     * fixed default the way a workspace folder's deletion can (a stage has
     * no single obvious fallback the way the default workspace is one).
     */
    public function destroy(Request $request, CrmStage $stage): RedirectResponse
    {
        $this->authorize('delete', $stage);

        $stages = CrmStage::defaultsFor($request->user());
        $destinationId = $request->input('reassign_to');
        $destination = $destinationId !== null ? $stages->firstWhere('id', (int) $destinationId) : null;

        $data = $request->validate([
            'reassign_to' => [$stage->deals()->exists() ? 'required' : 'nullable', Rule::in($stages->pluck('id'))],
        ], [
            'reassign_to.required' => __('crm.stages.reassign_required'),
        ]);

        DB::transaction(function () use ($stage, $destination, $data) {
            if (($data['reassign_to'] ?? null) !== null) {
                // withTrashed(): a soft-deleted deal still carries this
                // stage's id, and should not keep pointing at a stage the
                // owner can no longer see if it is ever restored.
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
