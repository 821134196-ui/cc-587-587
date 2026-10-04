<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    public const ROLE_OPERATOR = 'operator';
    public const ROLE_QC = 'qc';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isOperator(): bool
    {
        return $this->role === self::ROLE_OPERATOR;
    }

    public function isQc(): bool
    {
        return $this->role === self::ROLE_QC;
    }

    public function batchEvents(): HasMany
    {
        return $this->hasMany(BatchEvent::class, 'actor_id');
    }
}
