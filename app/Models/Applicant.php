<?php

namespace App\Models;

use Database\Factories\ApplicantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Applicant extends Model
{
    /** @use HasFactory<ApplicantFactory> */
    use HasFactory;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'birth_date',
        'source',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'metadata' => 'array',
        ];
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }
}
