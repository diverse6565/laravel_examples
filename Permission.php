<?php

namespace App\Facades;

use App\Models\Configuration;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;

class Permission extends Facade
{
    public const CAN_USE_PROJECT_SEARCH_BY_DATES = 'can_use_project_search_by_dates';
    public const CAN_USE_COUNTRIES_FILTER = 'can_use_countries_filter';
    public const CAN_CONFIGURE_PROJECT_SEARCH_BY_DATES = 'can_configure_project_search_by_dates';
    public const CAN_CREATE_USER = 'can_create_user';
    public const CAN_SEE_STATISTIC_FROM = 'can_see_statistic_from';
    public const MAX_JOBS_CAN_TAKE = 'max_jobs_can_take';
    public const CAN_CHANGE_PROJECT_PRICE = 'can_change_product_price';
    public const CAN_CONFIGURE_CHANGERS_PROJECT_PRICE = 'can_configure_changers_product_price';
    public const PARSER_SHOW = 'parser_show';

    protected const PERMISSIONS = [
        Role::ADMIN => [],
        Role::SUPER_MODERATOR => [
            self::CAN_USE_PROJECT_SEARCH_BY_DATES => true,
            self::CAN_CONFIGURE_PROJECT_SEARCH_BY_DATES => [
                Role::MODERATOR
            ],
            self::CAN_CREATE_USER => true,
            self::CAN_CHANGE_PROJECT_PRICE => true,
            self::CAN_CONFIGURE_CHANGERS_PROJECT_PRICE => true,
            self::CAN_USE_COUNTRIES_FILTER => true,
            self::PARSER_SHOW => false,
        ],
        Role::MODERATOR => [
            self::CAN_USE_PROJECT_SEARCH_BY_DATES => true,
            self::CAN_CHANGE_PROJECT_PRICE => false,
        ],
        Role::CHECKER => [
            self::MAX_JOBS_CAN_TAKE => 2,
            self::CAN_USE_PROJECT_SEARCH_BY_DATES => false,
        ],
        Role::DRAFTER => [
            self::MAX_JOBS_CAN_TAKE => 2
        ],
    ];

    protected static $role;
    protected static $allUserPermissions;
    protected static $userPermissions;
    protected static $currentUserRolePermissions;
    protected static $allUsedPermissions;

    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'permission';
    }


    public static function getAllPermissions(): array
    {
        if (!self::$allUsedPermissions) {

            $allUsedPermissions = [];
            foreach (self::PERMISSIONS as $role => $permission) {
                foreach ($permission as $permissionName => $permissionData) {
                    if (!array_key_exists($permissionName, $allUsedPermissions)) {
                        array_push($allUsedPermissions, $permissionName);
                    }
                }
            }
            return self::$allUsedPermissions = $allUsedPermissions;
        }

        return self::$allUsedPermissions;
    }

    public static function getGlobalPermission(string $role = null): array
    {
        return ($role) ? self::PERMISSIONS[$role] : self::PERMISSIONS;
    }

    public static function hasUserPermission(string $permission, $value = true): bool
    {
        if (!self::$allUserPermissions) {
            self::getAllUserPermissions();
        }

        if (self::$allUserPermissions->has($permission)) {

            $permissionData = self::$allUserPermissions->get($permission);

            return (is_array($permissionData))
                ? in_array(mb_strtolower($value), $permissionData)
                : ($permissionData === $value);
        }

        return false;
    }

    public static function getAllUserPermissions(): Collection
    {
        if (!self::$allUserPermissions) {

            $rolePermissions = self::getCurrentUserRolePermissions();
            $userPermissions = self::getCurrentUserPermissions();

            $permissionData = [];
            foreach ($rolePermissions as $rolePermission) {
                $permissionData[$rolePermission->name] = ($userPermissions->contains('name', $rolePermission->name))
                    ? json_decode($userPermissions->firstWhere('name', '=', $rolePermission->name)->pivot->value)
                    : json_decode($rolePermission->pivot->value);
            }
            return self::$allUserPermissions = collect($permissionData);
        }

        return self::$allUserPermissions;
    }

    public static function getDefaultRolePermissions(Role $role): Collection
    {
        $rolePermissions = $role->configurations()->get();
        $permissionData = [];

        foreach ($rolePermissions as $rolePermission) {
            $permissionData[$rolePermission->name] = json_decode($rolePermission->pivot->value);
        }

        return collect($permissionData);
    }

    public static function getDefaultUserRolePermissions(Role $role, User $user = null): Collection
    {
        $rolePermissions = $role->configurations()->get();
        $userPermissions = ($user) ? $user->configurations()->get() : collect([]);

        $permissionData = [];
        foreach ($rolePermissions as $rolePermission) {
            $permissionData[$rolePermission->name] = ($userPermissions->contains('name', $rolePermission->name))
                ? json_decode($userPermissions->firstWhere('name', '=', $rolePermission->name)->pivot->value)
                : json_decode($rolePermission->pivot->value);
        }

        return collect($permissionData);
    }

    public static function getCurrentUserRolePermissions()
    {
        if (!self::$currentUserRolePermissions) {
            self::$role = self::$role ?? self::getUserRole();

            return self::$currentUserRolePermissions = self::$role->configurations()->get();
        }

        return self::$currentUserRolePermissions;
    }

//    public function getRolePermissions($role)
//    {
//        if(!self::$rolePermissions){
//            self::$rolePermissions = Role::where('slug', $role)->first()->conditions()->get();
//        }
//        return self::$rolePermissions;
//    }

    public static function getCurrentUserPermissions()
    {
        if (!self::$userPermissions) {
            return self::$userPermissions = Auth::user()->configurations()->get();
        }

        return self::$userPermissions;
    }

    public static function getUserRole()
    {
        if (!self::$role) {
            return self::$role = Auth::user()->currentRole()->first();
        }

        return self::$role;
    }

    /**
     * @param null $roleName
     * @return bool
     */
    public static function hasUserRole($roleName = null): bool
    {
        if (!self::$role) {
            self::$role = self::getUserRole();
        }

        if (is_array($roleName)) {
            return in_array(self::$role->slug, $roleName);
        } elseif (is_string($roleName)) {
            return (self::$role->slug === $roleName);
        }
        return false;
    }

    /**
     * @param User $user
     * @param string $permission
     * @param $value
     */
    public static function updatePermission(User $user, string $permission, $value): void
    {
        $configuration = $user->configurations->where('name', $permission)->first();

        if ($configuration) {
            $user->configurations()->updateExistingPivot(
                $configuration->id,
                ['value'=> $value]
            );
            return;
        }

        $configuration = Configuration::where('name', $permission)->first();
        $user->configurations()->attach(
            $configuration->id,
            ['value'=> $value]
        );
    }
}