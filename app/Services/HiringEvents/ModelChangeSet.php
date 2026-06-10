<?php

namespace App\Services\HiringEvents;

use Illuminate\Database\Eloquent\Model;

class ModelChangeSet
{
    public static function fromArrays(array $old, array $new, array $allowFields): array
    {
        $changes = [];

        foreach ($allowFields as $field) {
            $oldVal = $old[$field] ?? null;
            $newVal = $new[$field] ?? null;

            if ($oldVal !== $newVal) {
                $changes[$field] = ['old' => $oldVal, 'new' => $newVal];
            }
        }

        return $changes;
    }

    public static function fields(Model $model, array $allowFields): array
    {
        $changes = [];

        foreach ($model->getChanges() as $field => $newVal) {
            if (! in_array($field, $allowFields, true)) {
                continue;
            }

            $changes[$field] = [
                'old' => $model->getOriginal($field),
                'new' => $newVal,
            ];
        }

        return $changes;
    }
}
