<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Guard
    |--------------------------------------------------------------------------
    |
    | This guard will be used whenever you do not specify a guard name while
    | assigning roles or permissions to a model.
    |
    */

    'default_guard' => 'sanctum',

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | When using the "HasRoles" trait, you can specify which models should be
    | used for roles and permissions. The default models work fine for most
    | applications, but you may customize them here.
    |
    */

    'models' => [
        'role' => \Spatie\Permission\Models\Role::class,
        'permission' => \Spatie\Permission\Models\Permission::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    */

    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_roles' => 'model_has_roles',
        'model_has_permissions' => 'model_has_permissions',
        'role_has_permissions' => 'role_has_permissions',
    ],

    /*
    |--------------------------------------------------------------------------
    | Column Names
    |--------------------------------------------------------------------------
    */

    'column_names' => [
        'id' => 'id',
        'name' => 'name',
        'guard_name' => 'guard_name',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
    ],

];
