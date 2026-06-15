<?php

namespace App\Models;

use App\Enums\UserAccessScope;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserStoreAccess extends Model
{
    protected $table = 'user_store_access';

    protected $fillable = ['user_id', 'store_id', 'role', 'access_scope', 'status'];

    protected function casts(): array
    {
        return [
            'role'         => UserRole::class,
            'access_scope' => UserAccessScope::class,
            'status'       => UserStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function isFranchiseScope(): bool
    {
        return $this->access_scope === UserAccessScope::Franchise;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }
}
