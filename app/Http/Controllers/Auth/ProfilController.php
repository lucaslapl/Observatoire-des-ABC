<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfilController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $contributions = $user->contributions()
            ->with('projet:id,nom,slug')
            ->latest('id')
            ->limit(100)
            ->get(['id', 'projet_id', 'type', 'commentaire', 'statut', 'created_at'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'projet' => $c->projet?->nom,
                'projet_slug' => $c->projet?->slug,
                'type' => $c->type,
                'commentaire' => $c->commentaire,
                'statut' => $c->statut,
                'date' => $c->created_at->toIso8601String(),
            ]);

        return Inertia::render('Profil', [
            'user' => $user->only('id', 'name', 'email'),
            'roles' => $user->getRoleNames(),
            'contributions' => $contributions,
        ]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', Password::min(12)],
        ]);

        if (! Hash::check($validated['current_password'], $request->user()->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Le mot de passe actuel est incorrect.'],
            ]);
        }

        $request->user()->update(['password' => $validated['password']]);

        return back()->with('success', 'Mot de passe mis à jour.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        if (! Hash::check($validated['current_password'], $request->user()->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Le mot de passe actuel est incorrect.'],
            ]);
        }

        $user = $request->user();

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Les contributions (travail humain) sont conservées et deviennent
        // anonymes : la clé étrangère contributions.user_id passe à NULL.
        $user->delete();

        return redirect('/')->with('success', 'Votre compte a été supprimé.');
    }
}
