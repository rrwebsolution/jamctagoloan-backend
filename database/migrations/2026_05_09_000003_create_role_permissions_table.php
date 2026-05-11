<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('role')->unique();
            $table->json('permissions');
            $table->timestamps();
        });

        $now = now();
        DB::table('role_permissions')->insert([
            [
                'role' => 'admin',
                'permissions' => json_encode(['users.manage', 'roles.manage', 'playlists.manage', 'presentations.manage', 'offerings.manage', 'announcements.manage', 'settings.manage']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'role' => 'staff',
                'permissions' => json_encode(['playlists.manage', 'presentations.manage', 'announcements.manage']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'role' => 'viewer',
                'permissions' => json_encode(['playlists.view', 'presentations.view']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};