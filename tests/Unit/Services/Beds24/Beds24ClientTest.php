<?php

namespace Tests\Unit\Services\Beds24;

use App\Services\Beds24\Beds24Client;
use App\Services\Beds24\Exceptions\Beds24Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Beds24ClientTest extends TestCase
{
    private const API_BASE = 'https://beds24.example/api/v2';

    private const CACHE_KEY = 'beds24:test:access_token';

    private const REFRESH_TOKEN = 'test-refresh-token';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(self::CACHE_KEY);
    }

    private function makeClient(): Beds24Client
    {
        return new Beds24Client(
            apiBase: self::API_BASE,
            refreshToken: self::REFRESH_TOKEN,
            accessTokenCacheKey: self::CACHE_KEY,
            accessTokenTtlSeconds: 3600,
            httpTimeout: 5,
        );
    }

    public function test_get_returns_unwrapped_envelope(): void
    {
        Cache::put(self::CACHE_KEY, 'cached-token', 3600);
        Http::fake([
            self::API_BASE.'/properties*' => Http::response([
                'success' => true,
                'type' => 'property',
                'count' => 1,
                'pages' => ['nextPageExists' => false],
                'data' => [['id' => 1]],
            ]),
        ]);

        $result = $this->makeClient()->get('/properties');

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['count']);
        $this->assertSame([['id' => 1]], $result['data']);
    }

    public function test_get_refreshes_token_when_cache_miss(): void
    {
        Http::fake([
            self::API_BASE.'/authentication/token' => Http::response(['token' => 'fresh-token']),
            self::API_BASE.'/properties*' => Http::response([
                'success' => true, 'type' => 'property', 'count' => 0, 'pages' => [], 'data' => [],
            ]),
        ]);

        $this->makeClient()->get('/properties');

        Http::assertSent(fn ($req) => str_contains($req->url(), '/authentication/token')
            && $req->header('refreshToken')[0] === self::REFRESH_TOKEN);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/properties')
            && $req->header('token')[0] === 'fresh-token');
        $this->assertSame('fresh-token', Cache::get(self::CACHE_KEY));
    }

    public function test_get_throws_when_envelope_success_false(): void
    {
        Cache::put(self::CACHE_KEY, 'token', 3600);
        Http::fake([
            self::API_BASE.'/*' => Http::response(['success' => false, 'error' => 'no scope'], 500),
        ]);

        $this->expectException(Beds24Exception::class);
        $this->makeClient()->get('/properties');
    }

    public function test_post_throws_on_empty_items(): void
    {
        Cache::put(self::CACHE_KEY, 'token', 3600);

        $this->expectException(Beds24Exception::class);
        $this->expectExceptionMessage('POST items must not be empty');

        $this->makeClient()->post('/bookings/messages', []);
    }

    public function test_post_returns_response_array_on_success(): void
    {
        Cache::put(self::CACHE_KEY, 'token', 3600);
        Http::fake([
            self::API_BASE.'/bookings/messages' => Http::response(
                [['success' => true, 'new' => ['id' => 42, 'source' => 'host']]],
                201
            ),
        ]);

        $result = $this->makeClient()->post('/bookings/messages', [
            ['bookingId' => 100, 'message' => 'hi', 'source' => 'host'],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame(42, $result[0]['new']['id']);

        Http::assertSent(function ($req) {
            $body = $req->data();

            return $req->method() === 'POST'
                && $req->hasHeader('Content-Type', 'application/json')
                && is_array($body) && count($body) === 1
                && $body[0]['source'] === 'host';
        });
    }

    public function test_post_throws_when_response_not_a_list(): void
    {
        Cache::put(self::CACHE_KEY, 'token', 3600);
        Http::fake([
            self::API_BASE.'/*' => Http::response(['foo' => 'bar'], 201),
        ]);

        $this->expectException(Beds24Exception::class);

        $this->makeClient()->post('/bookings/messages', [['x' => 1]]);
    }

    public function test_paginate_single_page(): void
    {
        Cache::put(self::CACHE_KEY, 'token', 3600);
        Http::fake([
            self::API_BASE.'/bookings/messages*' => Http::response([
                'success' => true, 'type' => 'message', 'count' => 2,
                'pages' => ['nextPageExists' => false],
                'data' => [['id' => 1], ['id' => 2]],
            ]),
        ]);

        $pages = iterator_to_array($this->makeClient()->paginate('/bookings/messages'));

        $this->assertCount(1, $pages);
        $this->assertCount(2, $pages[0]['data']);
    }

    public function test_paginate_follows_next_page_link(): void
    {
        Cache::put(self::CACHE_KEY, 'token', 3600);
        $page2Url = self::API_BASE.'/bookings/messages?page=2';

        Http::fakeSequence(self::API_BASE.'/bookings/messages*')
            ->push([
                'success' => true, 'type' => 'message', 'count' => 1,
                'pages' => ['nextPageExists' => true, 'nextPageLink' => $page2Url],
                'data' => [['id' => 3]],
            ])
            ->push([
                'success' => true, 'type' => 'message', 'count' => 1,
                'pages' => ['nextPageExists' => false],
                'data' => [['id' => 2]],
            ]);

        $pages = iterator_to_array($this->makeClient()->paginate('/bookings/messages'));

        $this->assertCount(2, $pages);
        $this->assertSame(3, $pages[0]['data'][0]['id']);
        $this->assertSame(2, $pages[1]['data'][0]['id']);
    }

    public function test_paginate_throws_when_next_page_link_missing(): void
    {
        Cache::put(self::CACHE_KEY, 'token', 3600);
        Http::fake([
            self::API_BASE.'/bookings/messages*' => Http::response([
                'success' => true, 'type' => 'message', 'count' => 0,
                'pages' => ['nextPageExists' => true, 'nextPageLink' => null],
                'data' => [],
            ]),
        ]);

        $this->expectException(Beds24Exception::class);

        iterator_to_array($this->makeClient()->paginate('/bookings/messages'));
    }

    public function test_get_encodes_array_query_as_repeated_keys(): void
    {
        // beds24 API v2 は OpenAPI 3 form-style explode=true 準拠なので、
        // 配列値は `status=confirmed&status=cancelled` の形式で送る必要がある。
        // PHP デフォルトの `status[0]=...` では解釈されない。
        Cache::put(self::CACHE_KEY, 'token', 3600);
        Http::fake([
            self::API_BASE.'/bookings*' => Http::response([
                'success' => true, 'type' => 'booking', 'count' => 0,
                'pages' => ['nextPageExists' => false], 'data' => [],
            ]),
        ]);

        $this->makeClient()->get('/bookings', [
            'arrivalFrom' => '2023-09-10',
            'status' => ['confirmed', 'cancelled'],
        ]);

        Http::assertSent(function ($req) {
            $url = $req->url();

            return str_contains($url, 'arrivalFrom=2023-09-10')
                && str_contains($url, 'status=confirmed')
                && str_contains($url, 'status=cancelled')
                && ! str_contains($url, 'status%5B') // PHP-style brackets is not what beds24 wants
                && ! str_contains($url, 'status[');
        });
    }

    public function test_refresh_access_token_throws_on_failure(): void
    {
        Http::fake([
            self::API_BASE.'/authentication/token' => Http::response(['error' => 'invalid'], 401),
        ]);

        $this->expectException(Beds24Exception::class);
        $this->expectExceptionMessage('Failed to refresh access token');

        $this->makeClient()->refreshAccessToken();
    }
}
