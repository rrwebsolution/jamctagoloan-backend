<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ListOfMemberController;
use App\Http\Controllers\EditMemberController;
use App\Http\Controllers\TithesController;
use App\Http\Controllers\Api\PlaylistController;
use App\Http\Controllers\Api\ObsSyncController;
use App\Http\Controllers\Api\ObsStateController;
use App\Http\Controllers\Api\BackgroundVideoController;
use App\Http\Controllers\Api\PptPresentationController;
use App\Http\Controllers\Api\AuthController;

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/google', [AuthController::class, 'google']);
Route::get('/auth/users', [AuthController::class, 'users'])->middleware('auth:sanctum');
Route::put('/auth/users/{user}', [AuthController::class, 'updateUser'])->middleware('auth:sanctum');
Route::get('/auth/roles', [AuthController::class, 'roles'])->middleware('auth:sanctum');
Route::post('/auth/roles', [AuthController::class, 'storeRole'])->middleware('auth:sanctum');
Route::put('/auth/roles/{role}', [AuthController::class, 'updateRole'])->middleware('auth:sanctum');
Route::delete('/auth/roles/{role}', [AuthController::class, 'destroyRole'])->middleware('auth:sanctum');
Route::get('/auth/role-permissions', [AuthController::class, 'rolePermissions'])->middleware('auth:sanctum');
Route::put('/auth/role-permissions', [AuthController::class, 'updateRolePermissions'])->middleware('auth:sanctum');
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('auth:sanctum');
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::apiResource('list-of-member', ListOfMemberController::class);
Route::apiResource('tithes', TithesController::class);
Route::post('expenses', [TithesController::class, 'create']);
Route::post('edit-member/{id}', [EditMemberController::class, 'edit']);

Route::get('/playlists', [PlaylistController::class, 'index']);
Route::post('/playlists/sync', [PlaylistController::class, 'sync']);
Route::post('/playlists/upload', [PlaylistController::class, 'upload']);
Route::post('/playlists/fetch-song-resources', [PlaylistController::class, 'fetchSongResources']);

Route::get('/obs-state', [ObsStateController::class, 'show']);
Route::get('/obs-state/stream', [ObsStateController::class, 'stream']);
Route::post('/obs-state', [ObsStateController::class, 'update']);

Route::post('/background-videos/upload', [BackgroundVideoController::class, 'upload']);
Route::post('/background-videos/delete', [BackgroundVideoController::class, 'delete']);

Route::get('/ppt-presentations', [PptPresentationController::class, 'index']);
Route::post('/ppt-presentations/sync', [PptPresentationController::class, 'sync']);






