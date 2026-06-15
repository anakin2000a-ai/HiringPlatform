<?php

namespace App\Models;

use App\Enums\StoreStatus;
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
        'id',
        'franchise_account_id',
        'store_name',
        'status',
    ];

    /**
     * Route model binding resolves Store by store_name instead of id.
     * All {store} parameters in routes accept a store_name value.
     */
    protected function casts(): array
    {
        return [
            'status' => StoreStatus::class,
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'store_name';
    }

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

    public function jobOpenings(): HasMany
    {
        return $this->hasMany(JobOpening::class);
    }
}
