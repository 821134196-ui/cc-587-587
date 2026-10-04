<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'display_name', 'email', 'password',
        'role', 'station_id', 'is_active',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function station()
    {
        return $this->belongsTo(Station::class);
    }

    public function isOperator(): bool { return $this->role === 'operator'; }
    public function isQc(): bool { return $this->role === 'qc'; }
    public function isEngineer(): bool { return $this->role === 'engineer'; }
}
