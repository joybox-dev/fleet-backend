<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\PermissionService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'permissions', 'is_super_admin', 'company_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_super_admin'    => 'boolean',
            'permissions'       => 'array',
        ];
    }

    /* ── Multi-Tenant ── */

    /**
     * The company this user belongs to.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Check if user is a platform-level super admin.
     */
    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    /**
     * Resolve effective permissions (role defaults + user overrides).
     */
    public function resolvePermissions(): array
    {
        return PermissionService::resolve(
            $this->role ?? 'admin',
            $this->permissions,
            $this->isSuperAdmin(),
            $this
        );
    }

    /**
     * Check if user has a specific permission.
     */
    public function can($ability, $arguments = []): bool
    {
        // If called with a dotted permission key (our system), use PermissionService
        if (is_string($ability) && str_contains($ability, '.')) {
            return PermissionService::can(
                $this->role ?? 'admin',
                $ability,
                $this->permissions,
                $this->isSuperAdmin(),
                $this
            );
        }

        // Fall back to Laravel's default Gate check
        return parent::can($ability, $arguments);
    }
}

