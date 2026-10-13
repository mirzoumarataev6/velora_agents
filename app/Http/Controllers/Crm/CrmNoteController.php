<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmNote;
use App\Models\Customer;
use App\Models\Deal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The notes/activity thread that replaced the single freeform `note` field
 * on deals and customers — an entry here is either a free note or the owner
 * manually logging a call/visit/meeting that happened (possibly earlier).
 */
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

    /** Validates and creates one thread entry, keeping the legacy `note` column in sync for kind='note' entries. */
    private function create(Request $request, Deal|Customer $notable): CrmNote
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'in:'.implode(',', CrmNote::KINDS)],
            'body' => ['required', 'string', 'max:5000'],
            // A log entry can be backdated (the owner logging a call that
            // already happened) but never postdated beyond "today" — there
            // is no such thing as a future activity log.
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

    /**
     * Keeps `deals.note` / `customers.note` pointed at the latest kind='note'
     * entry, one-way, so CSV export and anything else still reading that
     * column do not go stale once the UI stops writing it directly. The
     * thread is the source of truth now; this is purely a courtesy to old
     * readers, which is why a deleted note resyncs to the NEXT most recent
     * one (or clears the column) instead of leaving it pointing at nothing.
     */
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
