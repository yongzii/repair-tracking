<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_PLATFORM_ADMIN = 'platform_admin';
    public const ROLE_SHOP_ADMIN = 'shop_admin';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_STAFF = 'staff';
    public const ROLE_TECHNICIAN = 'technician';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'shop_id',
        'technician_id',
        'name',
        'email',
        'username',
        'password',
        'role',
        'is_active',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, self::adminRoles(), true);
    }

    public function isStaff(): bool
    {
        return $this->role === self::ROLE_STAFF;
    }

    public function isTechnician(): bool
    {
        return $this->role === self::ROLE_TECHNICIAN;
    }

    public function hasRole(string $role): bool
    {
        return in_array($this->role, self::expandRole($role), true);
    }

    /**
     * @param  array<int, string>  $roles
     */
    public function hasAnyRole(array $roles): bool
    {
        $expandedRoles = collect($roles)
            ->flatMap(fn (string $role) => self::expandRole($role))
            ->unique()
            ->values()
            ->all();

        return in_array($this->role, $expandedRoles, true);
    }

    /**
     * @return array<int, string>
     */
    public static function availableRoles(): array
    {
        return [
            self::ROLE_SHOP_ADMIN,
            self::ROLE_STAFF,
            self::ROLE_TECHNICIAN,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function adminRoles(): array
    {
        return [
            self::ROLE_SHOP_ADMIN,
            self::ROLE_ADMIN,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function portalRoles(): array
    {
        return [
            self::ROLE_PLATFORM_ADMIN,
            self::ROLE_SHOP_ADMIN,
            self::ROLE_ADMIN,
            self::ROLE_STAFF,
            self::ROLE_TECHNICIAN,
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function expandRole(string $role): array
    {
        return match ($role) {
            self::ROLE_ADMIN, self::ROLE_SHOP_ADMIN => self::adminRoles(),
            default => [$role],
        };
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function technician()
    {
        return $this->belongsTo(Technician::class);
    }

    public function setUsernameAttribute(?string $username): void
    {
        $this->attributes['username'] = $username === null
            ? null
            : Str::lower(trim($username));
    }
}
