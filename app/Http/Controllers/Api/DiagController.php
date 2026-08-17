<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

/**
 * Diagnostic public : aucun secret, uniquement l'état de la configuration.
 */
class DiagController extends Controller
{
    public function index(): array
    {
        return [
            'adminState' => config('abc.admin.password') ? 'hash' : 'absent',
            'envFilePresent' => file_exists(base_path('.env')),
            'root' => base_path(),
            'dbDefault' => config('database.default'),
            'phpVersion' => PHP_VERSION,
        ];
    }
}
