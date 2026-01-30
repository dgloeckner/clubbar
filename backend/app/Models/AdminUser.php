<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/**
 * AdminUser Model
 *
 * Represents an admin user account for authentication.
 * Implements Pattern 013: Admin Session Authentication
 */
class AdminUser extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'admin_users';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'email',
        'password',
        'display_name',
        'locale',
        'is_active',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password_hash',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    /**
     * Set the password attribute - hashes the password using bcrypt.
     *
     * Pattern 013: Admin Session Authentication
     * Passwords are never stored in plaintext. Use bcrypt with cost 12.
     *
     * @param string $value The plaintext password
     * @return void
     */
    public function setPasswordAttribute(string $value): void
    {
        $this->attributes['password_hash'] = Hash::make($value, [
            'rounds' => 12,
        ]);
    }

    /**
     * Check if admin account is active.
     *
     * Used during login validation to prevent inactive admins from logging in.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /**
     * Update last login timestamp.
     *
     * Called after successful authentication to track login history.
     *
     * @return bool
     */
    public function recordLogin(): bool
    {
        return $this->update(['last_login_at' => now()]);
    }
}
