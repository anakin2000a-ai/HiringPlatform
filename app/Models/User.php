<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'franchise_account_id',
        'name',
        'email',
        'password',
        'role',
        'access_scope',
        'status',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function franchiseAccount(): BelongsTo
    {
        return $this->belongsTo(FranchiseAccount::class);
    }

    public function storeAccesses(): HasMany
    {
        return $this->hasMany(UserStoreAccess::class);
    }

    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'user_store_access');
    }

    public function isFranchiseAdmin(): bool
    {
        return $this->role === 'franchise_admin';
    }

    public function hasFranchiseScope(): bool
    {
        return $this->access_scope === 'franchise';
    }
}
