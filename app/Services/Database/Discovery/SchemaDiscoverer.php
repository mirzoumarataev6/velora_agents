<?php

namespace App\Services\Database\Discovery;

use App\Services\Database\DatabaseConnectionException;
use App\Services\Database\DatabaseDriver;
use App\Services\Database\ProductQuery;

/**
 * The whole discovery pass, in the order the plan calls for: cheapest and most
 * reliable first, a language model last and only if it is needed at all.
 *
 *   1. READ the schema — one query set, no row scanning.
 *   2. INFER the relationships a legacy schema never declared.
 *   3. RANK candidate product tables on declared facts and name heuristics.
 *      Column names are read in the vocabulary's own languages first and, when
 *      that fails, by transliterating a latin spelling back into it — so
 *      `nazvanie` and `narkh` are resolved here, deterministically and without
 *      a model. See ColumnRoleResolver and Transliterator.
 *   4. Only if that came back genuinely undecided, and only if enabled, ASK a
 *      model — either to break a tie between shortlisted tables, or (when the
 *      ranking produced nothing at all) to say what the unread columns of one
 *      almost-candidate mean. Either answer is validated against the schema and
 *      discarded whole if it names anything unreal.
 *   5. PROPOSE: for each shortlisted table, the mapping and the joins that would
 *      actually be read.
 *
 * Step 4 is skipped entirely on a conventionally named database, and on a
 * transliterated one — which between them is nearly all of them. That is the
 * intent: the model is the last resort for a schema written in a language we do
 * not carry, not a component this feature depends on.
 *
 * This runs ONCE, when the owner connects or asks for a re-scan. Nothing here is
 * on the path of a customer's message.
 */
class SchemaDiscoverer
{
    /**
     * Tables the model fallback may be asked about in one scan.
     *
     * Two, because the fallback runs only when nothing was found at all, and a
     * schema with three plausible-but-unreadable catalogs is a schema whose
     * owner is better served by the advanced panel than by a third guess.
     */
    protected const MAX_INTERPRETED = 2;

    public function __construct(
        protected CandidateScorer $scorer,
        protected RelationResolver $relations,
        protected ColumnRoleResolver $roles,
        protected SchemaInterpreter $interpreter,
        protected SuggestionValidator $validator,
    ) {}

    /**
     * Scan a connected database and propose where its products are.
     *
     * @throws DatabaseConnectionException when the schema cannot be read.
     */
    public function discover(DatabaseDriver $driver): DiscoveryResult
    {
        $schema = $this->relations->infer(DiscoveredSchema::fromDriver($driver));

        $survey = $this->scorer->survey($schema);
        $ranked = $survey['ranked'];
        $shortlist = $this->scorer->shortlist($ranked);
        $ambiguous = $this->scorer->isAmbiguous($ranked);

        $method = DiscoveryResult::DETERMINISTIC;

        if ($this->shouldInterpret($shortlist, $ambiguous)) {
            $suggestion = $this->interpreter->interpret($schema, $shortlist);

            if ($suggestion !== null) {
                $shortlist = $this->promote($schema, $shortlist, $suggestion);
                $method = DiscoveryResult::LLM_ASSISTED;
            }
        }

        // The last resort, and only when the deterministic pass produced NOTHING
        // to offer: a shop whose columns are in a language the vocabulary does
        // not carry. Asked per table and per unread field, never about the
        // schema as a whole, and never when there is already an answer.
        if ($shortlist === [] && $this->llmEnabled()) {
            $rescued = $this->rescue($schema, $survey['unresolved']);

            if ($rescued !== null) {
                $shortlist = [$rescued];
                $method = DiscoveryResult::LLM_ASSISTED;
            }
        }

        $proposals = [];
        foreach ($shortlist as $candidate) {
            $proposals[] = $this->propose($schema, $candidate);
        }

        return new DiscoveryResult($schema, $proposals, $ambiguous, $method);
    }

    /**
     * A few real rows, exactly as the agent would see them.
     *
     * This is the part of the confirmation screen that does the actual work. An
     * owner cannot reliably check a mapping by reading column names — but they
     * know their own products on sight, and a table showing "Куртка зимняя /
     * 4500 / 3 / Верхняя одежда" is either obviously right or obviously wrong.
     *
     * It is built through the SAME ProductQuery the agent uses, so what is
     * previewed is what would be run: the joins, the quoting, the SELECT-only
     * rule and the row cap are all the real ones, not a rehearsal.
     *
     * @return array<int, array<string, string>>
     *
     * @throws DatabaseConnectionException
     */
    public function preview(DatabaseDriver $driver, DiscoveredSchema $schema, DiscoveryProposal $proposal): array
    {
        $columns = $proposal->columns();

        if ($columns === []) {
            return [];
        }

        $limit = max(1, min((int) config('agent.database.discovery.preview_rows', 5), 20));

        $rows = $driver->query(new ProductQuery(
            table: $proposal->table,
            columns: $columns,
            limit: $limit,
            joins: $proposal->joins,
            schema: $schema,
        ));

        return array_map(fn (array $row) => $this->trim($row, array_keys($columns)), $rows);
    }

    /**
     * Whether the deterministic pass left a question worth asking a model.
     *
     * A GENUINE TIE, and nothing else. Two tables that both look like the
     * catalog — retail and wholesale, a table and the view over it — is a real
     * question with a right answer, and a model reading the column names is a
     * reasonable way to break it.
     *
     * Low confidence on a clear leader is NOT such a question. It is very often
     * just "the product name lives on a related table", which is a shape we
     * understand perfectly well and have already composed a join for; asking
     * about it would spend a call on a decision that is not in doubt, on a large
     * share of all scans. Where low confidence means something is genuinely
     * unread, it is rescue() — narrow, per field — that answers it.
     *
     * @param  array<int, TableCandidate>  $shortlist
     */
    protected function shouldInterpret(array $shortlist, bool $ambiguous): bool
    {
        return $this->llmEnabled() && $shortlist !== [] && $ambiguous;
    }

    protected function llmEnabled(): bool
    {
        return (bool) config('agent.database.discovery.use_llm', false);
    }

    /**
     * Complete the best almost-candidate by asking what its unread columns mean.
     *
     * Four properties hold this together, and all four are load-bearing:
     *
     *  - NARROW. One table and its unread fields per call, not the schema.
     *  - BOUNDED. At most MAX_INTERPRETED tables are ever asked about, so a
     *    schema full of near-misses cannot turn one scan into twenty calls.
     *  - ALL OR NOTHING. A table is only rescued if the answer resolves
     *    EVERYTHING the deterministic pass could not. The model may complete a
     *    candidate; it may never lower the bar one has to clear.
     *  - SAID OUT LOUD. What came back is marked INTERPRETED, the confidence
     *    drops to LOW, and each field gets a reason naming the column — because
     *    "«narkh» we read as the price" is exactly the sentence an owner can
     *    check in one second and we cannot check at all.
     *
     * @param  array<int, TableCandidate>  $unresolved  Best first.
     */
    protected function rescue(DiscoveredSchema $schema, array $unresolved): ?TableCandidate
    {
        foreach (array_slice($unresolved, 0, self::MAX_INTERPRETED) as $candidate) {
            $resolved = $this->interpreter->resolveFields($schema, $candidate->table, $candidate->missing);

            if ($resolved === []) {
                continue;
            }

            // Everything that was missing, or nothing.
            foreach ($candidate->missing as $field) {
                if (! isset($resolved[$field])) {
                    continue 2;
                }
            }

            $numeric = [];
            $reasons = $candidate->reasons;

            foreach ($resolved as $field => $column) {
                $numeric[$field] = $schema->isNumeric($candidate->table, $column);
                $reasons[] = ['code' => 'interpreted_column', 'params' => ['column' => $column, 'field' => $field]];
            }

            return new TableCandidate(
                table: $candidate->table,
                type: $candidate->type,
                score: $candidate->score,
                // Never anything but LOW. A reading nothing in the schema
                // confirms is a reading the owner has to look at.
                confidence: TableCandidate::LOW,
                reasons: $reasons,
                roles: $candidate->roles->with($resolved, $numeric, ColumnRoles::INTERPRETED),
                rowCount: $candidate->rowCount,
                nameFrom: $candidate->nameFrom,
            );
        }

        return null;
    }

    /**
     * Move the model's choice to the front and adopt its mapping for that table.
     *
     * The mapping is rebuilt through SuggestionValidator, so the columns are the
     * validated ones and their types come from the schema rather than from
     * anything the model claimed about them. The candidate's score and reasons
     * are left alone: those are the deterministic pass's findings, and the model
     * did not change them.
     *
     * @param  array<int, TableCandidate>  $shortlist
     * @param  array{table: string, mapping: array<string, string>}  $suggestion
     * @return array<int, TableCandidate>
     */
    protected function promote(DiscoveredSchema $schema, array $shortlist, array $suggestion): array
    {
        $promoted = [];
        $rest = [];

        foreach ($shortlist as $candidate) {
            if ($candidate->table !== $suggestion['table']) {
                $rest[] = $candidate;

                continue;
            }

            $promoted[] = new TableCandidate(
                table: $candidate->table,
                type: $candidate->type,
                score: $candidate->score,
                // A model's reading is never the reason to claim certainty: it
                // was only asked because the facts did not settle it.
                confidence: TableCandidate::LOW,
                reasons: [...$candidate->reasons, ['code' => 'interpreted_by_model']],
                roles: $this->validator->toRoles($schema, $candidate->table, $suggestion['mapping']),
                rowCount: $candidate->rowCount,
                nameFrom: $candidate->nameFrom,
            );
        }

        return [...$promoted, ...$rest];
    }

    protected function propose(DiscoveredSchema $schema, TableCandidate $candidate): DiscoveryProposal
    {
        $joins = $this->relations->plan($schema, $candidate->table, $candidate->roles);

        return new DiscoveryProposal(
            table: $candidate->table,
            type: $candidate->type,
            confidence: $candidate->confidence,
            reasons: $candidate->reasons,
            roles: $candidate->roles,
            joins: $joins,
            rowCount: $candidate->rowCount,
            // What we found and did NOT use. A proposal that simply omits stock
            // is indistinguishable from a shop that keeps none.
            notes: $this->relations->stockNotes($schema, $candidate->table, $candidate->roles, $joins),
        );
    }

    /**
     * Preview values, shortened.
     *
     * A product row can hold a 50KB description or a base64 image; the owner
     * needs enough to recognize their own catalog, not the catalog's contents.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $fields
     * @return array<string, string>
     */
    protected function trim(array $row, array $fields): array
    {
        $trimmed = [];

        foreach ($fields as $field) {
            $value = $row[$field] ?? null;

            $trimmed[$field] = match (true) {
                $value === null => '',
                is_bool($value) => $value ? '1' : '0',
                is_scalar($value) => mb_substr((string) $value, 0, 80),
                default => '…',
            };
        }

        return $trimmed;
    }
}
