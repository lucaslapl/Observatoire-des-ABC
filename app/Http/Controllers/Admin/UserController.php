<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Gestion des rôles depuis le panneau admin (carte « Comptes »).
 * Seul le rôle « moderateur » est gérable ici : le rôle « admin »
 * reste réservé au compte seedé (ADMIN_*).
 */
class UserController extends Controller
{
    public function promoteModerateur(User $user): JsonResponse
    {
        if ($user->hasRole('admin')) {
            return response()->json(['error' => 'Un administrateur ne peut pas être modifié ici.'], 400);
        }

        if (! $user->hasVerifiedEmail()) {
            return response()->json(['error' => "L'adresse email de ce compte n'est pas vérifiée."], 400);
        }

        $user->assignRole('moderateur');

        return response()->json(['ok' => true, 'roles' => $user->refresh()->getRoleNames()->values()->all()]);
    }

    public function demoteModerateur(User $user): JsonResponse
    {
        if ($user->hasRole('admin')) {
            return response()->json(['error' => 'Un administrateur ne peut pas être modifié ici.'], 400);
        }

        $user->removeRole('moderateur');

        return response()->json(['ok' => true, 'roles' => $user->refresh()->getRoleNames()->values()->all()]);
    }
}
