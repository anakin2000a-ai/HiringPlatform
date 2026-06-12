<?php

namespace App\Services\Automation;

class RuleConditionEvaluator
{
    public function evaluate(?array $conditions, array $context): bool
    {
        if (empty($conditions)) {
            return true;
        }

        $group = $conditions['group'] ?? 'all';
        $rules = $conditions['rules'] ?? [];

        if (empty($rules)) {
            return true;
        }

        return match ($group) {
            'all'   => collect($rules)->every(fn ($r) => $this->evaluateRule($r, $context)),
            'any'   => collect($rules)->contains(fn ($r) => $this->evaluateRule($r, $context)),
            default => false,
        };
    }

    private function evaluateRule(array $rule, array $context): bool
    {
        $field    = $rule['field']    ?? '';
        $operator = $rule['operator'] ?? '';
        $value    = $rule['value']    ?? null;

        $actual = $this->resolveField($field, $context);

        return $this->applyOperator($operator, $actual, $value);
    }

    private function resolveField(string $field, array $context): mixed
    {
        $parts  = explode('.', $field, 3);
        $prefix = $parts[0] ?? '';
        $key    = $parts[1] ?? null;
        $sub    = $parts[2] ?? null;

        return match ($prefix) {
            'applicant'     => $key !== null ? ($context['applicant'][$key] ?? null) : null,
            'application'   => $key !== null ? ($context['application'][$key] ?? null) : null,
            // current_stage.slug maps to WorkflowStage.name (no slug column in current schema)
            'current_stage' => $key !== null ? ($context['current_stage'][$key] ?? null) : null,
            'answers'       => $key !== null ? ($context['answers'][$key] ?? null) : null,
            'documents'     => $this->resolveDocumentField($key, $sub, $context),
            // Bare field name (no prefix) — fall back to application context shorthand.
            // e.g. "score" resolves to application.score, "status" to application.status.
            default         => $context['application'][$prefix] ?? null,
        };
    }

    private function resolveDocumentField(?string $docKey, ?string $attr, array $context): mixed
    {
        if ($docKey === null) {
            return null;
        }

        $docContext = $context['documents'][$docKey] ?? null;

        if ($docContext === null) {
            return null;
        }

        if ($attr === null) {
            return $docContext;
        }

        return $docContext[$attr] ?? null;
    }

    private function applyOperator(string $operator, mixed $actual, mixed $value): bool
    {
        return match ($operator) {
            'equals',    'eq'  => $actual == $value,
            'not_equals','ne'  => $actual != $value,
            'greater_than',          'gt'  => is_numeric($actual) && is_numeric($value) && $actual > $value,
            'greater_than_or_equal', 'gte' => is_numeric($actual) && is_numeric($value) && $actual >= $value,
            'less_than',             'lt'  => is_numeric($actual) && is_numeric($value) && $actual < $value,
            'less_than_or_equal',    'lte' => is_numeric($actual) && is_numeric($value) && $actual <= $value,
            'contains'              => is_string($actual) && is_string($value) && str_contains($actual, $value),
            'not_contains'          => !(is_string($actual) && is_string($value) && str_contains($actual, $value)),
            'in'                    => in_array($actual, (array) $value, false),
            'not_in'                => ! in_array($actual, (array) $value, false),
            'exists'                => $actual !== null,
            'not_exists'            => $actual === null,
            'is_empty'              => empty($actual),
            'is_not_empty'          => ! empty($actual),
            default                 => false,
        };
    }
}
