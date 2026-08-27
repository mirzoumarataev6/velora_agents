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
 *   4. Only if that came back genuinely undecided, and only if enabled, ASK a
 *      model to interpret the shortlisted NAMES — then validate its answer
 *      against the schema and discard it whole if it names anything unreal.
 *   5. PROPOSE: for each shortlisted table, the mapping and the joins that would
 *      actually be read.
 *
 * Step 4 is skipped entirely on a conventionally named database, which is most
 * of them. That is the intent: the model is a tie-breaker for hard schemas, not
 * a component this feature depends on.
 *
 * This runs ONCE, when the owner connects or asks for a re-scan. Nothing here is
 * on the path of a customer's message.
 */
class SchemaDiscoverer
{
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

        $ranked = $this->scorer->rank($schema);
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
     * Three conditions, all required: the feature is switched on, there is
     * something to choose between, and the choice is genuinely close or weakly
     * supported. A confident answer is never second-guessed — paying for a
     * model call to re-confirm `products` would be a cost with no decision
     * attached to it.
     *
     * @param  array<int, TableCandidate>  $shortlist
     */
    protected function shouldInterpret(array $shortlist, bool $ambiguous): bool
    {
        if (! config('agent.database.discovery.use_llm', false) || $shortlist === []) {
            return false;
        }

        return $ambiguous || $shortlist[0]->confidence === TableCandidate::LOW;
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
