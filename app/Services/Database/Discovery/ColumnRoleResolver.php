<?php

namespace App\Services\Database\Discovery;

use App\Services\Database\LiveProductSource;
use App\Services\Dataset\ColumnMapper;
use App\Services\Dataset\Transliterator;

class ColumnRoleResolver
{
    protected const TYPE_FIT = [
        'price' => ['numeric' => 2, 'text' => 0],
        'stock' => ['numeric' => 2, 'text' => 1],

        'name' => ['numeric' => -1, 'text' => 2],
        'description' => ['numeric' => -1, 'text' => 2],
        'url' => ['numeric' => -1, 'text' => 2],

        'sku' => ['numeric' => 1, 'text' => 1],
        'category' => ['numeric' => -1, 'text' => 2],
    ];

    protected const TEXT_ONLY = ['name', 'description', 'url', 'category'];

    public function resolve(DiscoveredSchema $schema, string $table): ColumnRoles
    {
        $columns = $schema->columns($table);

        if ($columns === []) {
            return new ColumnRoles;
        }

        $folded = [];
        $latin = [];

        foreach ($columns as $index => $column) {
            $folded[$index] = ColumnMapper::fold($column['name']);
            $latin[$index] = Transliterator::candidates($folded[$index]);
        }

        $aliases = ColumnMapper::foldedAliases();
        $roles = [];
        $numeric = [];
        $sources = [];
        $taken = [];

        foreach ([[true, false], [true, true], [false, false], [false, true]] as [$exactPass, $translitPass]) {
            foreach (LiveProductSource::FIELDS as $field) {
                if (isset($roles[$field]) || ! isset($aliases[$field])) {
                    continue;
                }

                $best = null;
                $bestFit = PHP_INT_MIN;

                foreach ($columns as $index => $column) {
                    if (isset($taken[$index]) || $folded[$index] === '') {
                        continue;
                    }

                    $forms = $translitPass ? $latin[$index] : [$folded[$index]];

                    if (! $this->matchesAny($forms, $aliases[$field], $exactPass)) {
                        continue;
                    }

                    if ($this->isSurrogateKey($field, $folded[$index], (bool) $column['numeric'])) {
                        continue;
                    }

                    $fit = $this->typeFit($field, (bool) $column['numeric']);

                    if ($fit < 0) {
                        continue;
                    }

                    if ($fit > $bestFit) {
                        $bestFit = $fit;
                        $best = $index;
                    }
                }

                if ($best !== null) {
                    $roles[$field] = $columns[$best]['name'];
                    $numeric[$field] = (bool) $columns[$best]['numeric'];
                    $taken[$best] = true;

                    if ($translitPass) {
                        $sources[$field] = ColumnRoles::TRANSLITERATED;
                    }
                }
            }
        }

        return new ColumnRoles($roles, $numeric, $sources);
    }

    public static function accepts(string $field, bool $numeric): bool
    {
        return ! ($numeric && in_array($field, self::TEXT_ONLY, true));
    }

    public function supplies(DiscoveredSchema $schema, string $table, string $field): bool
    {
        return $this->resolve($schema, $table)->has($field);
    }

    protected function matchesAny(array $forms, array $aliases, bool $exact): bool
    {
        foreach ($forms as $form) {
            if ($form !== '' && $this->matches($form, $aliases, $exact)) {
                return true;
            }
        }

        return false;
    }

    protected function matches(string $column, array $aliases, bool $exact): bool
    {
        foreach ($aliases as $alias) {
            if ($exact ? $column === $alias : str_contains($column, $alias)) {
                return true;
            }
        }

        return false;
    }

    protected function isSurrogateKey(string $field, string $folded, bool $numeric): bool
    {
        return $field === 'sku'
            && $numeric
            && ($folded === 'id' || str_ends_with($folded, ' id'));
    }

    protected function typeFit(string $field, bool $numeric): int
    {
        if (! self::accepts($field, $numeric)) {
            return -1;
        }

        $fit = self::TYPE_FIT[$field] ?? ['numeric' => 1, 'text' => 1];

        return $numeric ? $fit['numeric'] : $fit['text'];
    }
}
