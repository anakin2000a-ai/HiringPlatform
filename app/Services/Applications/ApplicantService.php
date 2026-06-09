<?php

namespace App\Services\Applications;

use App\Models\Applicant;

class ApplicantService
{
    /**
     * Find an existing applicant by email (priority) or phone, or create a new one.
     */
    public function findOrCreate(array $data): Applicant
    {
        $email = $data['email'] ?? null;
        $phone = $data['phone'] ?? null;

        if ($email !== null) {
            $applicant = Applicant::where('email', $email)->first();
            if ($applicant !== null) {
                return $applicant;
            }
        }

        if ($phone !== null) {
            $applicant = Applicant::where('phone', $phone)->first();
            if ($applicant !== null) {
                return $applicant;
            }
        }

        return Applicant::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $email,
            'phone' => $phone,
            'birth_date' => $data['birth_date'] ?? null,
            'source' => $data['source'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ]);
    }
}
