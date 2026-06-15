<?php

namespace App\Models;

use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
        'id',
        'name',
        'email',
        'status',
    ];

    protected $hidden = [ 'remember_token'];

    protected function casts(): array
    {
        return [
       
            'status'            => UserStatus::class,
        ];
    }
    

    public function storeAccesses(): HasMany
    {
        return $this->hasMany(UserStoreAccess::class);
    }

    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'user_store_access');
    }
}
