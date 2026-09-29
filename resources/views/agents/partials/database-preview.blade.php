@php
    /**
     * A few real rows of a discovery candidate, as the agent would see them.
     *
     * Shared by the proposed table and by each alternative, because they must
     * show the same rows the same way — the only difference between them is how
     * much the HEADINGS are allowed to claim:
     *
     *   $assertive true  — this is the table we are proposing, so "Название
     *                      товара" is a fair label.
     *   $assertive false — this is an option the owner is judging, so the label
     *                      stays neutral ("Название") and names the column it
     *                      came from. A customer's name under a heading that
     *                      says "product name" is the UI answering the very
     *                      question it is asking.
     *
     * @var string $proposal   The Alpine expression holding the proposal.
     * @var bool   $assertive  Whether this is the candidate we are asserting.
     */
@endphp

<table class="table-flat">
    <thead class="text-muted dark:text-night-muted/70">
        <tr>
            <template x-for="field in {{ $proposal }}.fields" :key="field">
                <th class="px-3 py-2 font-medium">
                    <span x-text="headerFor(field, {{ $assertive ? 'true' : 'false' }})"></span>
                    @unless ($assertive)
                        <span class="block text-[10px] font-normal normal-case text-muted dark:text-night-muted/70"
                            x-text="sourceFor({{ $proposal }}, field)"></span>
                    @endunless
                </th>
            </template>
        </tr>
    </thead>
    <tbody>
        <template x-for="(row, rowIndex) in {{ $proposal }}.preview" :key="rowIndex">
            <tr class="border-t border-hairline dark:border-white/10">
                <template x-for="field in {{ $proposal }}.fields" :key="field">
                    <td class="max-w-[14rem] truncate px-3 py-2" x-text="row[field] || '—'"></td>
                </template>
            </tr>
        </template>
    </tbody>
</table>
