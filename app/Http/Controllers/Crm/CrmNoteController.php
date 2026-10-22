<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmNote;
use App\Models\Customer;
use App\Models\Deal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CrmNoteController extends Controller
{
    public function storeForDeal(Request $request, Deal $deal): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $deal);

        $note = $this->create($request, $deal);

        if ($request->wantsJson()) {
            return response()->json(['note' => $this->present($note)], 201);
        }

        return back()->with('status', __('crm.notes.added'));
    }

    public function storeForCustomer(Request $request, Customer $customer): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $customer);

        $this->create($request, $customer);

        return back()->with('status', __('crm.notes.added'));
    }

    public function destroy(Request $request, CrmNote $note): RedirectResponse|JsonResponse
    {
        $this->authorize('delete', $note);

        $note->delete();
        $this->resyncLegacyColumn($note->notable);

        if ($request->wantsJson()) {
            return response()->json(['status' => 'ok']);
        }

        return back()->with('status', __('crm.notes.deleted'));
    }

    private function create(Request $request, Deal|Customer $notable): CrmNote
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'in:'.implode(',', CrmNote::KINDS)],
            'body' => ['required', 'string', 'max:5000'],

            'happened_at' => ['nullable', 'date', 'before_or_equal:'.now()->addMinute()->toDateTimeString()],
        ], [
            'kind.in' => __('crm.notes.validation.kind'),
            'happened_at.before_or_equal' => __('crm.notes.validation.happened_at'),
        ]);

        $note = new CrmNote;
        $note->forceFill([
            'notable_type' => $notable::class,
            'notable_id' => $notable->id,
            'user_id' => $request->user()->id,
            'team_id' => $notable->team_id,
            'kind' => $data['kind'],
            'body' => $data['body'],
            'happened_at' => $data['happened_at'] ?? now(),
        ])->save();

        $this->resyncLegacyColumn($notable);

        return $note;
    }

    private function resyncLegacyColumn(Deal|Customer $notable): void
    {
        $latest = $notable->notes()->where('kind', 'note')->first();

        $notable->forceFill(['note' => $latest?->body])->saveQuietly();
    }

    private function present(CrmNote $note): array
    {
        return [
            'id' => $note->id,
            'kind' => $note->kind,
            'body' => $note->body,
            'happened_at' => $note->happened_at->toIso8601String(),
            'author' => $note->user->name,
            'can_delete' => true,
        ];
    }
}
