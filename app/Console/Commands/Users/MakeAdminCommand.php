<?php

namespace App\Console\Commands\Users;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * bootstrap 用: 既存ユーザーに admin role を付与する、
 * または新規で admin ユーザーを作成する。
 *
 * 使用例:
 *   php artisan user:make-admin foo@example.com
 *     → 既存 user なら admin role を付与
 *     → 新規なら name/password を対話入力で作成 + admin
 *
 *   php artisan user:make-admin foo@example.com --name=秦野 --password=secret
 *     → 非対話で作成
 */
class MakeAdminCommand extends Command
{
    protected $signature = 'user:make-admin
        {email : 対象ユーザーの email}
        {--name= : 新規作成時の名前}
        {--password= : 新規作成時のパスワード（8文字以上）}';

    protected $description = '既存ユーザーに admin role を付与、または新規で admin ユーザーを作成';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('有効な email を指定してください。');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            $user->syncRoles([User::ROLE_ADMIN]);
            $this->info("既存ユーザー {$email} に admin role を付与しました。");

            return self::SUCCESS;
        }

        $name = (string) ($this->option('name') ?? $this->ask('名前', $email));
        $password = (string) ($this->option('password') ?? $this->secret('パスワード (8文字以上)'));

        if (strlen($password) < 8) {
            $this->error('パスワードは 8 文字以上必要です。');

            return self::FAILURE;
        }

        $user = User::create([
            'email' => $email,
            'name' => $name,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
        ]);
        $user->syncRoles([User::ROLE_ADMIN]);

        $this->info("新規 admin ユーザー {$email} を作成しました。");

        return self::SUCCESS;
    }
}
