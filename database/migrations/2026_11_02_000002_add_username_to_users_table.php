<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('name');
        });

        // Email is no longer required for users; login is done with the username.
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });

        // Backfill a unique username for every existing user.
        $users = DB::table('users')->select('id', 'name', 'email')->get();
        foreach ($users as $user) {
            $base = $user->email ? strstr($user->email, '@', true) : null;
            if (!$base) {
                $base = preg_replace('/[^A-Za-z0-9_.-]/', '', $user->name ?: '');
            }
            $base = trim((string) $base, '._-');
            if ($base === '') {
                $base = 'user' . $user->id;
            }

            $candidate = $base;
            $suffix = 1;
            while (
                DB::table('users')
                    ->where('username', $candidate)
                    ->where('id', '!=', $user->id)
                    ->exists()
            ) {
                $candidate = $base . $suffix;
                $suffix++;
            }

            DB::table('users')->where('id', $user->id)->update(['username' => $candidate]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
