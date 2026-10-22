<?php

namespace Tests\Unit;

use App\Services\DataGouvResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class DataGouvResolverTest extends TestCase
{
    public function test_resolves_latest_matching_csv(): void
    {
        Http::fake([
            'www.data.gouv.fr/api/1/datasets/atlas-de-la-biodiversite-communale-abc/*' => Http::response([
                'resources' => [
                    ['format' => 'xlsx', 'url' => 'https://static.data.gouv.fr/x/atlas-biodiversite.csv.xlsx', 'last_modified' => '2026-09-01T00:00:00'],
                    ['format' => 'csv', 'url' => 'https://static.data.gouv.fr/r/20260709-081207/atlas-biodiversite.csv', 'last_modified' => '2026-07-09'],
                    ['format' => 'csv', 'url' => 'https://static.data.gouv.fr/r/20260912-090102/atlas-biodiversite.csv', 'last_modified' => '2026-09-12'],
                ],
            ]),
        ]);

        $url = app(DataGouvResolver::class)->resolve('datagouv');

        $this->assertSame('https://static.data.gouv.fr/r/20260912-090102/atlas-biodiversite.csv', $url);
    }

    public function test_falls_back_to_pinned_url_when_no_match(): void
    {
        Http::fake([
            'www.data.gouv.fr/api/1/datasets/*' => Http::response([
                'resources' => [
                    ['format' => 'csv', 'url' => 'https://static.data.gouv.fr/r/autre-chose.csv', 'last_modified' => '2026-09-12'],
                ],
            ]),
        ]);

        $url = app(DataGouvResolver::class)->resolve('datagouv');

        $this->assertSame(
            'https://static.data.gouv.fr/resources/atlas-de-la-biodiversite-communale-abc/20260709-081207/atlas-biodiversite.csv',
            $url,
        );
    }

    public function test_falls_back_on_api_error(): void
    {
        Http::fake([
            'www.data.gouv.fr/api/1/datasets/*' => Http::response(null, 500),
        ]);

        $pinned = config('abc.sources.fondsvert2024');
        $this->assertSame($pinned, app(DataGouvResolver::class)->resolve('fondsvert2024'));
    }

    public function test_auto_resolve_disabled_returns_pinned_url(): void
    {
        config(['abc.auto_resolve_sources' => false]);
        Http::fake([]);

        $this->assertSame(config('abc.sources.datagouv'), app(DataGouvResolver::class)->resolve('datagouv'));
        Http::assertNothingSent();
    }

    public function test_unknown_key_returns_pinned_url(): void
    {
        $this->assertSame(config('abc.sources.wayback'), app(DataGouvResolver::class)->resolve('wayback'));
    }

    public function test_fallback_is_never_silent(): void
    {
        Http::fake([
            'www.data.gouv.fr/api/1/datasets/*' => Http::response(null, 500),
        ]);
        Log::shouldReceive('warning')->once()->andReturnNull();

        $pinned = config('abc.sources.fondsvert2024');
        $this->assertSame($pinned, app(DataGouvResolver::class)->resolve('fondsvert2024'));
    }
}
