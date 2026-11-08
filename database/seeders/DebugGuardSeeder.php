<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;

class DebugGuardSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'admin@scarletresta.com')->first();
        if (!$user) {
            $this->command->info('No admin user.');
            return;
        }
        $this->command->info('User guard name: ' . $user->getGuardName());
        $this->command->info('User roles: ' . implode(',', $user->getRoleNames()->all()));
    }
}
