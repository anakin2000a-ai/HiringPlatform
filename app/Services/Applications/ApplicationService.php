<?php

namespace App\Services\Applications;

use App\Models\Application;

class ApplicationService
{
    public function update(Application $application, array $data): Application
    {
        $updates = [];

        if (isset($data['score'])) {
            $updates['score'] = $data['score'];
        }

        if (isset($data['status'])) {
            $newStatus = $data['status'];
            $updates['status'] = $newStatus;

            // Set the corresponding timestamp on status transitions
            if ($newStatus === 'rejected' && $application->rejected_at === null) {
                $updates['rejected_at'] = now();
            } elseif ($newStatus === 'hired' && $application->hired_at === null) {
                $updates['hired_at'] = now();
            } elseif ($newStatus === 'withdrawn' && $application->withdrawn_at === null) {
                $updates['withdrawn_at'] = now();
            }
        }

        $application->update($updates);

        return $application->fresh();
    }
}
