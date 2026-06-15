<?php

namespace App\Services\JobOpenings;

use App\Enums\JobOpeningStatus;
use App\Models\JobOpening;
use App\Models\Store;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class JobOpeningService
{
    public function create(Store $store, array $data, User $createdBy): JobOpening
    {
        return JobOpening::create([
            'store_id' => $store->id,
            'hiring_workflow_id' => $data['hiring_workflow_id'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'employment_type' => $data['employment_type'] ?? null,
            'openings_count' => $data['openings_count'] ?? 1,
            'status' => JobOpeningStatus::Draft,
            'created_by' => $createdBy->id,
        ]);
    }

    public function update(JobOpening $jobOpening, array $data): JobOpening
    {
        $jobOpening->update($data);

        return $jobOpening->fresh();
    }

    public function publish(JobOpening $jobOpening): JobOpening
    {
        if (! $jobOpening->isDraft()) {
            throw ValidationException::withMessages([
                'status' => ['Only draft job openings can be published.'],
            ]);
        }

        $jobOpening->update([
            'status'       => JobOpeningStatus::Published,
            'published_at' => now(),
        ]);

        return $jobOpening->fresh();
    }

    public function close(JobOpening $jobOpening): JobOpening
    {
        if ($jobOpening->isClosed()) {
            throw ValidationException::withMessages([
                'status' => ['This job opening is already closed.'],
            ]);
        }

        $jobOpening->update([
            'status'    => JobOpeningStatus::Closed,
            'closed_at' => now(),
        ]);

        return $jobOpening->fresh();
    }

    public function delete(JobOpening $jobOpening): void
    {
        $jobOpening->delete();
    }
}
