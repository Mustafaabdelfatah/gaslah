<?php

namespace App\Models;

use App\Enum\Tenancy\PlatformRoleEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Enum\User\UserGenderEnum;
use App\Scopes\User\UserScopes;
use App\Trait\Global\ApplyNotification;
use App\Trait\Global\CreatedByObserver;
use App\Trait\Global\LogsActivityOptions;
use HasanHawary\MediaManager\Facades\Media;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use LdapRecord\Laravel\Auth\AuthenticatesWithLdap;
use LdapRecord\Laravel\Auth\LdapAuthenticatable;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements LdapAuthenticatable
{
    use ApplyNotification, AuthenticatesWithLdap, CreatedByObserver, HasApiTokens, HasFactory, HasRoles, InteractsWithSockets, LogsActivityOptions, Notifiable, SoftDeletes, UserScopes;

    protected string $guard_name = 'api';

    public bool $inPermission = true;

    public array $basicOperations = ['create', 'update', 'delete'];

    public array $specialOperations = ['view-all', 'view-own', 'restore', 'force-delete', 'toggle-active'];

    /**
     * `role`, `is_platform_owner` and `platform_role` are deliberately absent:
     * `role` is derived from branch memberships rather than set, and the two platform
     * columns decide who reaches the operator console. Leaving them out of mass
     * assignment keeps a tenant-facing request from granting itself either.
     */
    protected $fillable = [
        'legacy_cuid',
        'name', 'email', 'phone_code_id', 'phone', 'avatar', 'gender', 'password', 'otp_data',
        'is_active', 'last_login', 'ldap_name', 'guid', 'uid', 'created_by',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $with = ['phoneCode'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login' => 'datetime',
        'is_active' => 'boolean',
        'password' => 'hashed',
        'gender' => UserGenderEnum::class,
        'otp_data' => 'array',
        'role' => StaffRoleEnum::class,
        'is_platform_owner' => 'boolean',
        'platform_role' => PlatformRoleEnum::class,
    ];

    /*
    |--------------------------------------------------------------------------
    | Activity logs
    |--------------------------------------------------------------------------
    */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty()->logOnly($this->fillable);
    }

    /*
     |--------------------------------------------------------------------------
     | Casts && Set Custom Attributes
     |--------------------------------------------------------------------------
     */
    public function avatar(): Attribute
    {
        return Attribute::make(
            get: static fn ($value) => Media::url($value)
        );
    }

    protected function password(): Attribute
    {
        return Attribute::make(
            set: static fn ($value) => bcrypt($value),
        );
    }

    public function getFullPhone(): string
    {
        $code = $this->phoneCode?->phone_code ?? '';
        $number = $this->phone ?? '';

        $fullPhone = trim(($code ?? '').$number);

        return preg_replace('/\s+/', '', $fullPhone) ?: '---';
    }

    /*
    |--------------------------------------------------------------------------
    | Relations methods
    |--------------------------------------------------------------------------
    */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(__CLASS__, 'created_by');
    }

    public function phoneCode(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'phone_code_id');
    }

    public function settings(): HasOne
    {
        return $this->hasOne(UserSetting::class);
    }

    public function userBranches(): HasMany
    {
        return $this->hasMany(UserBranch::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'user_branches')
            ->withPivot('role');
    }

    public function permissionOverride(): HasOne
    {
        return $this->hasOne(UserPermissionOverride::class);
    }

    public function platformPermissions(): HasMany
    {
        return $this->hasMany(UserPlatformPermission::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Tenancy helpers
    |--------------------------------------------------------------------------
    */

    /**
     * The organization this account belongs to, derived from its branches.
     *
     * Membership is intentionally not stored on the user: branches own it, so
     * removing the last membership detaches the account with no second write.
     */
    public function organizationId(): ?int
    {
        return $this->branches()->value('branches.organization_id');
    }

    /**
     * Every branch of the user's organization they hold a membership in.
     *
     * @return array<int, int>
     */
    public function branchIds(): array
    {
        return $this->branches()->pluck('branches.id')->all();
    }

    public function isPlatformAdmin(): bool
    {
        return $this->is_platform_owner === true;
    }

    /**
     * A null platform role on a platform account means Owner, so an account
     * predating role assignment keeps full access rather than silently losing it.
     */
    public function effectivePlatformRole(): ?PlatformRoleEnum
    {
        if (! $this->isPlatformAdmin()) {
            return null;
        }

        return $this->platform_role ?? PlatformRoleEnum::Owner;
    }
}
