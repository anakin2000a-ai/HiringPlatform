<?php

namespace App\Models;

use App\Enums\AccountStatus;
use Database\Factories\FranchiseAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FranchiseAccount extends Model
{
    /** @use HasFactory<FranchiseAccountFactory> */
    use HasFactory;

    protected $fillable = ['name', 'status'];

    protected function casts(): array
    {
        return [
            'status' => AccountStatus::class,
        ];
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }
}
