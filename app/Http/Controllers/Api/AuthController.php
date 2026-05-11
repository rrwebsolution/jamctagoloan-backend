<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const ROLE_PERMISSIONS = [
        'users.manage',
        'roles.manage',
        'playlists.view',
        'playlists.manage',
        'presentations.view',
        'presentations.manage',
        'offerings.manage',
        'announcements.manage',
        'settings.manage',
    ];

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $login = trim($credentials['login']);

        $user = User::query()
            ->where('email', $login)
            ->orWhere('username', $login)
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['Invalid email/username or password.'],
            ]);
        }

        return $this->respondWithToken($user, $credentials['device_name'] ?? 'web');
    }

    public function google(Request $request): JsonResponse
    {
        $data = $request->validate([
            'credential' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $googleUser = $this->verifyGoogleCredential($data['credential']);

        if (empty($googleUser['email'])) {
            throw ValidationException::withMessages([
                'credential' => ['Google account email was not found.'],
            ]);
        }

        $user = User::query()
            ->where('email', $googleUser['email'])
            ->orWhere('google_id', $googleUser['sub'])
            ->first();

        if (! $user) {
            $user = User::create([
                'name' => $googleUser['name'] ?? Str::before($googleUser['email'], '@'),
                'username' => $this->makeUniqueUsername($googleUser['email']),
                'email' => $googleUser['email'],
                'email_verified_at' => now(),
                'password' => Str::password(32),
                'role' => $this->defaultRole(),
                'google_id' => $googleUser['sub'],
                'avatar' => $googleUser['picture'] ?? null,
            ]);
        } else {
            $user->forceFill([
                'name' => $googleUser['name'] ?? $user->name,
                'email_verified_at' => $user->email_verified_at ?? now(),
                'google_id' => $googleUser['sub'],
                'avatar' => $googleUser['picture'] ?? $user->avatar,
            ])->save();
        }

        return $this->respondWithToken($user, $data['device_name'] ?? 'google-web');
    }

    public function roles(): JsonResponse
    {
        return response()->json([
            'roles' => $this->getRoles(),
        ]);
    }

    public function storeRole(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
        ]);

        $role = $this->normalizeRole($data['name']);

        if ($role === '') {
            throw ValidationException::withMessages([
                'name' => ['Please enter a valid role name.'],
            ]);
        }

        if (DB::table('role_permissions')->where('role', $role)->exists()) {
            throw ValidationException::withMessages([
                'name' => ['This role already exists.'],
            ]);
        }

        DB::table('role_permissions')->insert([
            'role' => $role,
            'permissions' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Role added successfully.',
            'role' => $role,
            'permissions' => [],
            'roles' => $this->getRoles(),
        ], 201);
    }

    public function updateRole(Request $request, string $role): JsonResponse
    {
        $oldRole = $this->normalizeRole($role);

        if (! DB::table('role_permissions')->where('role', $oldRole)->exists()) {
            throw ValidationException::withMessages([
                'role' => ['Role was not found.'],
            ]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
        ]);

        $newRole = $this->normalizeRole($data['name']);

        if ($newRole === '') {
            throw ValidationException::withMessages([
                'name' => ['Please enter a valid role name.'],
            ]);
        }

        if ($newRole !== $oldRole && DB::table('role_permissions')->where('role', $newRole)->exists()) {
            throw ValidationException::withMessages([
                'name' => ['This role already exists.'],
            ]);
        }

        DB::transaction(function () use ($oldRole, $newRole) {
            DB::table('role_permissions')
                ->where('role', $oldRole)
                ->update([
                    'role' => $newRole,
                    'updated_at' => now(),
                ]);

            User::where('role', $oldRole)->update([
                'role' => $newRole,
                'updated_at' => now(),
            ]);
        });

        return response()->json([
            'message' => 'Role updated successfully.',
            'role' => $newRole,
            'roles' => $this->getRoles(),
            'permissions' => $this->getRolePermissions()[$newRole] ?? [],
        ]);
    }
    public function destroyRole(string $role): JsonResponse
    {
        $role = $this->normalizeRole($role);

        if (! DB::table('role_permissions')->where('role', $role)->exists()) {
            throw ValidationException::withMessages([
                'role' => ['Role was not found.'],
            ]);
        }

        if (User::where('role', $role)->exists()) {
            throw ValidationException::withMessages([
                'role' => ['This role is assigned to existing users and cannot be deleted.'],
            ]);
        }

        DB::table('role_permissions')->where('role', $role)->delete();

        return response()->json([
            'message' => 'Role deleted successfully.',
            'roles' => $this->getRoles(),
            'permissions' => $this->getRolePermissions(),
        ]);
    }

    public function rolePermissions(): JsonResponse
    {
        return response()->json([
            'roles' => $this->getRoles(),
            'available_permissions' => self::ROLE_PERMISSIONS,
            'permissions' => $this->getRolePermissions(),
        ]);
    }

    public function updateRolePermissions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['array'],
            'permissions.*.*' => ['string', 'in:'.implode(',', self::ROLE_PERMISSIONS)],
        ]);

        $roles = $this->getRoles();
        $now = now();

        foreach ($roles as $role) {
            $permissions = array_values(array_unique($data['permissions'][$role] ?? []));

            DB::table('role_permissions')->updateOrInsert(
                ['role' => $role],
                [
                    'permissions' => json_encode($permissions),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        return response()->json([
            'message' => 'Role permissions updated successfully.',
            'roles' => $this->getRoles(),
            'permissions' => $this->getRolePermissions(),
        ]);
    }

    public function users(): JsonResponse
    {
        $users = User::query()
            ->select(['id', 'name', 'username', 'email', 'role', 'avatar', 'google_id', 'created_at'])
            ->latest()
            ->get();

        return response()->json([
            'users' => $users,
        ]);
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255', 'unique:users,username,'.$user->id],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'role' => ['required', 'string', 'exists:role_permissions,role'],
        ]);

        $user->forceFill([
            'name' => $data['name'],
            'username' => $data['username'] ?: $this->makeUniqueUsername($data['email']),
            'email' => $data['email'],
            'role' => $data['role'],
        ])->save();

        return response()->json([
            'message' => 'User updated successfully.',
            'user' => $user->only(['id', 'name', 'username', 'email', 'role', 'avatar', 'google_id', 'created_at']),
        ]);
    }
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'string', 'exists:role_permissions,role'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'username' => $data['username'] ?: $this->makeUniqueUsername($data['email']),
            'email' => $data['email'],
            'role' => $data['role'],
            'password' => $data['password'],
        ]);

        return response()->json([
            'message' => 'User account created successfully.',
            'user' => $user,
        ], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    private function getRoles(): array
    {
        return DB::table('role_permissions')
            ->orderByRaw("CASE role WHEN 'admin' THEN 0 WHEN 'staff' THEN 1 WHEN 'viewer' THEN 2 ELSE 3 END")
            ->orderBy('role')
            ->pluck('role')
            ->values()
            ->all();
    }

    private function getRolePermissions(): array
    {
        $records = DB::table('role_permissions')->orderBy('role')->get();
        $permissions = [];

        foreach ($records as $record) {
            $decoded = json_decode($record->permissions ?? '[]', true);
            $permissions[$record->role] = is_array($decoded) ? $decoded : [];
        }

        return $permissions;
    }

    private function defaultRole(): string
    {
        if (DB::table('role_permissions')->where('role', 'staff')->exists()) {
            return 'staff';
        }

        return $this->getRoles()[0] ?? 'staff';
    }

    private function normalizeRole(string $name): string
    {
        return Str::slug(Str::lower(trim($name)));
    }

    private function verifyGoogleCredential(string $credential): array
    {
        $response = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $credential,
        ]);

        if (! $response->ok()) {
            throw ValidationException::withMessages([
                'credential' => ['Invalid Google sign-in credential.'],
            ]);
        }

        $payload = $response->json();
        $clientId = config('services.google.client_id');

        if ($clientId && (($payload['aud'] ?? null) !== $clientId)) {
            throw ValidationException::withMessages([
                'credential' => ['Google credential is not for this app.'],
            ]);
        }

        if (($payload['email_verified'] ?? 'false') !== 'true' && ($payload['email_verified'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'credential' => ['Google account email is not verified.'],
            ]);
        }

        return $payload;
    }

    private function respondWithToken(User $user, string $deviceName): JsonResponse
    {
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
    }

    private function makeUniqueUsername(string $email): string
    {
        $base = Str::slug(Str::before($email, '@')) ?: 'google-user';
        $username = $base;
        $count = 1;

        while (User::where('username', $username)->exists()) {
            $username = $base.'-'.$count;
            $count++;
        }

        return $username;
    }
}





