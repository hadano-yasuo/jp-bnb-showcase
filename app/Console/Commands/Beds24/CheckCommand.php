<?php

namespace App\Console\Commands\Beds24;

use App\Models\Beds24Credential;
use App\Models\Beds24SyncLog;
use App\Models\Message;
use App\Services\Beds24\Beds24Client;
use App\Services\Beds24\Exceptions\Beds24Exception;
use Illuminate\Console\Command;
use Throwable;

/**
 * beds24 連携のヘルスチェック。staging 初回セットアップ時の疎通確認用。
 *
 * - config 値
 * - Beds24Credential seeder 実行済みか
 * - Refresh → Access Token 交換
 * - GET /properties が返るか
 * - messages テーブルの件数
 * - 直近 sync log
 */
class CheckCommand extends Command
{
    protected $signature = 'beds24:check';

    protected $description = 'beds24 連携のヘルスチェック（token・API 疎通・DB状態）';

    public function handle(Beds24Client $client): int
    {
        $this->info('== config ==');
        $refreshToken = (string) config('beds24.refresh_token');
        $refreshTokenSet = $refreshToken !== '';
        $this->line('  api_base           : '.(string) config('beds24.api_base'));
        $this->line('  refresh_token      : '.($refreshTokenSet ? 'set ('.strlen($refreshToken).' chars)' : '(empty)'));

        $this->info('== DB ==');
        $credential = Beds24Credential::query()->where('name', 'self')->first();
        $this->line('  credential name=self: '.($credential ? "id={$credential->id}, active=".($credential->is_active ? 'yes' : 'no') : '(missing) → run: php artisan db:seed'));

        $this->line('  messages count     : '.Message::count());

        $lastLog = Beds24SyncLog::latest('id')->first();
        if ($lastLog) {
            $this->line('  last sync log      : '.$lastLog->status.' at '.$lastLog->started_at->toDateTimeString()." (created={$lastLog->records_created})");
        } else {
            $this->line('  last sync log      : (none) → run: php artisan beds24:sync-messages');
        }

        $this->info('== API 疎通 ==');
        if (! $refreshTokenSet) {
            $this->error('  refresh_token 未設定のため API 疎通チェックはスキップします');

            return self::FAILURE;
        }

        try {
            $token = $client->refreshAccessToken();
            $this->line('  refresh token      : OK (access_token '.strlen($token).' chars)');
        } catch (Beds24Exception $e) {
            $this->error('  refresh token      : NG - '.$e->getMessage());

            return self::FAILURE;
        }

        $apiOk = true;
        try {
            $result = $client->get('/properties');
            $this->line('  GET /properties    : OK ('.$result['count'].' properties)');
        } catch (Throwable $e) {
            $this->error('  GET /properties    : NG - '.$e->getMessage());
            $apiOk = false;
        }

        return ($credential !== null && $apiOk) ? self::SUCCESS : self::FAILURE;
    }
}
