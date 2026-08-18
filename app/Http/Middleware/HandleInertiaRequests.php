<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'site' => [
                'name' => config('seo.site_name'),
                'title' => config('seo.default_title'),
                'description' => config('seo.default_description'),
                'url' => url('/'),
                'ogImage' => url(config('seo.og_image')),
                'sources' => config('seo.sources'),
                'licenseNote' => config('seo.license_note'),
                'tracking' => config('seo.tracking'),
            ],
            'auth' => [
                'user' => $request->user()?->only('id', 'name', 'email')
                    ? [...$request->user()->only('id', 'name', 'email'), 'roles' => $request->user()->getRoleNames()]
                    : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
