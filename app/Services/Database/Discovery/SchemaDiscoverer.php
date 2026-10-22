<?php

namespace App\Services\Database\Discovery;

use App\Services\Database\DatabaseDriver;
use App\Services\Database\ProductQuery;

class SchemaDiscoverer
{
    protected const MAX_INTERPRETED = 2;

    public function __construct(
        protected CandidateScorer $scorer,
        protected RelationResolver $relations,
        protected ColumnRoleResolver $roles,
        protected SchemaInterpreter $interpreter,
        protected SuggestionValidator $validator,
    ) {}

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

    protected function shouldInterpret(array $shortlist, bool $ambiguous): bool
    {
        return $this->llmEnabled() && $shortlist !== [] && $ambiguous;
    }

    protected function llmEnabled(): bool
    {
        return (bool) config('agent.database.discovery.use_llm', false);
    }

    protected function rescue(DiscoveredSchema $schema, array $unresolved): ?TableCandidate
    {
        foreach (array_slice($unresolved, 0, self::MAX_INTERPRETED) as $candidate) {
            $resolved = $this->interpreter->resolveFields($schema, $candidate->table, $candidate->missing);

            if ($resolved === []) {
                continue;
            }

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

                confidence: TableCandidate::LOW,
                reasons: $reasons,
                roles: $candidate->roles->with($resolved, $numeric, ColumnRoles::INTERPRETED),
                rowCount: $candidate->rowCount,
                nameFrom: $candidate->nameFrom,
            );
        }

        return null;
    }

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

            notes: $this->relations->stockNotes($schema, $candidate->table, $candidate->roles, $joins),
        );
    }

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
