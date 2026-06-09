<?php

namespace App\Models;

use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use HasFactory;

    protected $fillable = [
        'franchise_account_id',
        'store_name',
    ];

    public function franchiseAccount(): BelongsTo
    {
        return $this->belongsTo(FranchiseAccount::class);
    }

    public function userAccesses(): HasMany
    {
        return $this->hasMany(UserStoreAccess::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_store_access');
    }

    public function hiringWorkflows(): HasMany
    {
        return $this->hasMany(HiringWorkflow::class);
    }
}
