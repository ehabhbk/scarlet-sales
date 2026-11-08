<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class FixAdminRoleSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'admin@scarletresta.com')->first();
        if (!$user) {
            $this->command->info('Admin user not found.');
            return;
        }

        $roleId = DB::table('roles')->where('name', 'Super Admin')->where('guard_name', 'sanctum')->value('id');
        if (!$roleId) {
            $this->command->info('Super Admin role not found.');
            return;
        }

        $exists = DB::table('model_has_roles')
            ->where('role_id', $roleId)
            ->where('model_type', User::class)
            ->where('model_id', $user->id)
            ->exists();

        if (!$exists) {
            DB::table('model_has_roles')->insert([
                'role_id' => $roleId,
                'model_type' => User::class,
                'model_id' => $user->id,
            ]);
            $this->command->info('Assigned Super Admin role to ' . $user->name);
        } else {
            $this->command->info('Already has Super Admin role.');
        }
    }
}
