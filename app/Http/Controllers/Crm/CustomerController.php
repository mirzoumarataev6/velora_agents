<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmWorkspace;
use App\Models\Customer;
use App\Models\CustomerMerge;
use App\Models\Deal;
use App\Models\Message;
use App\Services\Crm\CustomerResolver;
use App\Services\Crm\DealBoard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use League\Csv\Bom;
use League\Csv\Writer;

class CustomerController extends Controller
{
    protected const EXCERPT = 140;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Customer::class);

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in(Customer::STATUSES)],
            'segment' => ['nullable', 'string', Rule::in(['all', 'repeat', 'buyers', 'no_purchases'])],
            'origin' => ['nullable', 'string', Rule::in(Deal::SALES_ORIGINS)],
            'workspace' => ['nullable', 'integer'],
        ]);
        $workspaceId = isset($data['workspace']) && CrmWorkspace::ownedBy($request->user())->whereKey($data['workspace'])->exists()
            ? (int) $data['workspace']
            : null;
        $paidDeal = $this->paidDealFilter();
        $repeatBuyer = $this->repeatBuyerFilter();

        $customers = $this->filteredCustomers($request, $data, $workspaceId)
            ->with('workspace:id,name,color,is_default')
            ->withCount('conversations')
            ->withCount(['deals as purchase_count' => $paidDeal])
            ->withSum(['deals as purchase_value' => $paidDeal], 'amount')
            ->orderByDesc('last_contact_at')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        if ($request->ajax()) {
            return view('crm.customers.partials.results', ['customers' => $customers]);
        }

        return view('crm.customers.index', [
            'i18nExtraNamespaces' => ['crm'],
            'customers' => $customers,
            'filters' => array_merge(['q' => '', 'status' => '', 'segment' => 'all', 'origin' => '', 'workspace' => $workspaceId], $data),
            'workspaces' => CrmWorkspace::ownedBy($request->user())->orderByDesc('is_default')->orderBy('name')->get(),
            'counts' => [
                'all' => Customer::ownedBy($request->user())->count(),
                'buyers' => Customer::ownedBy($request->user())->whereHas('deals', $paidDeal)->count(),
                'repeat' => Customer::ownedBy($request->user())->whereHas('deals', $repeatBuyer)->count(),
            ],
        ]);
    }

    private function filteredCustomers(Request $request, array $data, ?int $workspaceId): Builder
    {
        $paidDeal = $this->paidDealFilter();
        $repeatBuyer = $this->repeatBuyerFilter();

        return Customer::ownedBy($request->user())
            ->when($workspaceId, fn ($query) => $query->where('crm_workspace_id', $workspaceId))
            ->when(filled($data['status'] ?? null), fn ($query) => $query->where('status', $data['status']))
            ->when(filled($data['q'] ?? null), fn ($query) => $query->search($data['q']))
            ->when(($data['segment'] ?? 'all') === 'repeat', fn ($query) => $query->whereHas('deals', $repeatBuyer))
            ->when(($data['segment'] ?? 'all') === 'buyers', fn ($query) => $query->whereHas('deals', $paidDeal))
            ->when(($data['segment'] ?? 'all') === 'no_purchases', fn ($query) => $query->whereDoesntHave('deals', $paidDeal))
            ->when(filled($data['origin'] ?? null), fn ($query) => $query->whereHas('deals', fn ($deals) => $paidDeal($deals)->where('sales_origin', $data['origin'])));
    }

    private function paidDealFilter(): \Closure
    {
        return DealBoard::paidInWonStage();
    }

    private function repeatBuyerFilter(): \Closure
    {
        return fn ($query) => DealBoard::paidInWonStage()($query)

            ->select('customer_id')->groupBy('customer_id')->havingRaw('COUNT(*) >= 2');
    }

    public function store(Request $request, CustomerResolver $resolver): RedirectResponse
    {
        $this->authorize('create', Customer::class);

        $teamId = $request->user()->activeTeamId();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'string', 'in:'.implode(',', Customer::STATUSES)],
            'note' => ['nullable', 'string', 'max:5000'],
            'crm_workspace_id' => ['nullable', 'integer', Rule::exists('crm_workspaces', 'id')->where('team_id', $teamId)],
        ]);

        $key = $resolver->phoneKey($data['phone'] ?? null);

        if ($key !== null) {
            $existing = Customer::ownedBy($request->user())->where('phone_key', $key)->first();

            if ($existing !== null) {
                return redirect()->route('crm.customers.show', $existing)
                    ->with('status', __('crm.customers.already_exists', ['name' => $existing->displayName()]));
            }
        }

        $customer = Customer::forceCreate([
            'user_id' => $request->user()->id,

            'team_id' => $teamId,
            'crm_workspace_id' => $data['crm_workspace_id'] ?? CrmWorkspace::defaultFor($request->user(), $teamId)->id,
            'name' => $data['name'],
            'phone' => $key !== null ? $data['phone'] : null,
            'phone_key' => $key,

            'source' => 'manual',
            'status' => $data['status'] ?? 'lead',
            'note' => $data['note'] ?? null,
            'first_contact_at' => now(),
            'last_contact_at' => now(),
        ]);

        return redirect()->route('crm.customers.show', $customer)->with('status', __('crm.customers.created'));
    }

    public function export(Request $request): Response
    {
        $this->authorize('export', Customer::class);

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in(Customer::STATUSES)],
            'segment' => ['nullable', 'string', Rule::in(['all', 'repeat', 'buyers', 'no_purchases'])],
            'origin' => ['nullable', 'string', Rule::in(Deal::SALES_ORIGINS)],
            'workspace' => ['nullable', 'integer'],
        ]);
        $workspaceId = isset($data['workspace']) && CrmWorkspace::ownedBy($request->user())->whereKey($data['workspace'])->exists()
            ? (int) $data['workspace']
            : null;

        $customers = $this->filteredCustomers($request, $data, $workspaceId)
            ->with('workspace:id,name,is_default')
            ->orderByDesc('last_contact_at')->orderByDesc('id')
            ->get();

        $csv = Writer::from(fopen('php://temp', 'w+'));
        $csv->setOutputBOM(Bom::Utf8);
        $csv->insertOne([
            __('crm.customers.name'), __('crm.customers.phone'), __('crm.customers.status'),
            __('crm.customers.workspace'), __('crm.customers.source'),
            __('crm.customers.first_contact'), __('crm.customers.last_contact'), __('crm.customers.note'),
        ]);

        foreach ($customers as $customer) {
            $csv->insertOne([
                $customer->displayName(),
                (string) $customer->phone,
                __('crm.statuses.'.$customer->status),
                $customer->workspace?->displayName() ?? '',
                (string) $customer->source,
                (string) $customer->first_contact_at?->toDateString(),
                (string) $customer->last_contact_at?->toDateString(),
                (string) $customer->note,
            ]);
        }

        return response($csv->toString(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="customers.csv"',
        ]);
    }

    public function lookup(Request $request, CustomerResolver $resolver): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $key = $resolver->phoneKey($request->query('phone'));

        $customer = $key === null
            ? null
            : Customer::ownedBy($request->user())->where('phone_key', $key)->first(['id', 'name']);

        return response()->json([
            'customer' => $customer === null ? null : ['id' => $customer->id, 'name' => $customer->displayName()],
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $data = $request->validate(['q' => ['nullable', 'string', 'max:120']]);
        $term = trim($data['q'] ?? '');

        $customers = Customer::ownedBy($request->user())
            ->when($term !== '', fn ($query) => $query->search($term))
            ->orderByDesc('last_contact_at')->orderByDesc('id')->limit(20)->get();

        return response()->json([
            'customers' => $customers->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->displayName(),
                'phone' => $customer->phone,
            ]),
        ]);
    }

    public function show(Request $request, Customer $customer): View
    {
        $this->authorize('view', $customer);

        $customer->load([
            'workspace',
            'conversations' => fn ($q) => $q->when(! $request->user()->can('conversations.view', $customer), fn ($query) => $query->whereRaw('1 = 0'))->orderByDesc('last_message_at'),
            'conversations.channel:id,type',
            'conversations.agent:id,name,slug',
            'leads' => fn ($q) => $q->latest('id'),
            'leads.agent:id,name,slug',
            'deals' => fn ($q) => $q->orderByDesc('id'),
            'deals.workspace:id,name,is_default',
            'deals.crmStage',

            'tasks' => fn ($q) => $q->visibleInCrm($request->user())->orderByRaw('done_at is not null')
                ->orderByRaw('due_at is null')
                ->orderBy('due_at'),
            'notes.user:id,name',
        ]);

        return view('crm.customers.show', [
            'i18nExtraNamespaces' => ['crm'],
            'customer' => $customer,
            'workspaces' => CrmWorkspace::where('team_id', $customer->team_id)->orderByDesc('is_default')->orderBy('name')->get(),
            'timeline' => $this->timeline($customer),

            'mergeCandidates' => Customer::where('team_id', $customer->team_id)
                ->whereKeyNot($customer->id)
                ->whereNull('phone_key')
                ->orderByDesc('last_contact_at')
                ->limit(50)
                ->get(['id', 'name', 'source', 'last_contact_at']),

            'undoableMerge' => CustomerMerge::where('into_customer_id', $customer->id)
                ->undoable()
                ->with('fromCustomer:id,name,source')
                ->latest('id')
                ->first(),
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $customer);

        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'required', 'string', 'in:'.implode(',', Customer::STATUSES)],
            'tags' => ['sometimes', 'nullable', 'string', 'max:500'],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'crm_workspace_id' => ['sometimes', 'required', 'integer', Rule::exists('crm_workspaces', 'id')->where('team_id', $customer->team_id)],
        ]);

        $updates = [];

        if (array_key_exists('name', $data)) {
            $updates['name'] = $data['name'];
        }

        if (array_key_exists('status', $data)) {
            $updates['status'] = $data['status'];
        }

        if (array_key_exists('tags', $data)) {
            $updates['tags'] = $this->parseTags($data['tags']);
        }

        if (array_key_exists('note', $data)) {
            $updates['note'] = $data['note'];
        }

        if (array_key_exists('crm_workspace_id', $data)) {
            $updates['crm_workspace_id'] = $data['crm_workspace_id'];
        }

        DB::transaction(function () use ($customer, $updates) {
            $customer->update($updates);

            if (array_key_exists('crm_workspace_id', $updates)) {
                $customer->deals()->update(['crm_workspace_id' => $updates['crm_workspace_id']]);
            }
        });

        if ($request->wantsJson()) {
            return response()->json(['status' => $customer->status]);
        }

        return back()->with('status', __('crm.customers.saved'));
    }

    public function merge(Request $request, Customer $customer, CustomerResolver $resolver): RedirectResponse
    {
        $this->authorize('update', $customer);

        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'different:'.$customer->id],
        ]);

        $other = Customer::where('team_id', $customer->team_id)->find($data['customer_id']);

        if ($other === null) {
            return back()->withErrors(['customer_id' => __('crm.customers.merge_not_found')]);
        }

        $resolver->merge(from: $other, into: $customer);

        return redirect()
            ->route('crm.customers.show', $customer)
            ->with('status', __('crm.customers.merged'));
    }

    public function undoMerge(Request $request, Customer $customer, CustomerMerge $merge, CustomerResolver $resolver): RedirectResponse
    {
        $this->authorize('update', $customer);

        if ($merge->into_customer_id !== $customer->id) {
            abort(404);
        }

        $restored = $resolver->undoMerge($merge);

        return redirect()
            ->route('crm.customers.show', $customer)
            ->with('status', $restored !== null
                ? __('crm.customers.merge_undone')
                : __('crm.customers.merge_undo_expired'));
    }

    protected function timeline(Customer $customer): LengthAwarePaginator
    {
        return Message::query()->when(! auth()->user()->can('conversations.view', $customer), fn ($q) => $q->whereRaw('1 = 0'))->whereHas('conversation', fn ($query) => $query->where('customer_id', $customer->id))
            ->with('conversation.channel:id,type')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(30, ['id', 'conversation_id', 'role', 'content', 'created_at'], 'timeline_page')
            ->appends(request()->except('timeline_page'))
            ->through(fn (Message $m) => [
                'id' => $m->id,
                'conversation_id' => $m->conversation_id,
                'channel' => $m->conversation?->channel?->type,
                'role' => $m->role,
                'content' => $m->content,
                'at' => $m->created_at,
            ]);
    }

    protected function parseTags(?string $raw): ?array
    {
        $tags = collect(explode(',', (string) $raw))
            ->map(fn (string $tag) => Str::squish($tag))
            ->filter()
            ->unique()
            ->take(20)
            ->values()
            ->all();

        return $tags ?: null;
    }

    public static function excerpt(?string $text): string
    {
        return Str::limit(Str::squish((string) $text), self::EXCERPT);
    }
}
