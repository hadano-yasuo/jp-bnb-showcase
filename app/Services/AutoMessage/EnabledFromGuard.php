<?php

namespace App\Services\AutoMessage;

use App\Models\AutoMessageRule;
use App\Models\Booking;

/**
 * ルール単位の有効化日時ガード。
 *
 * rule.enabled_from より前に予約作成された予約 (booking.booking_time) は
 * 自動送信の対象外とする。過去分への誤送信 (機能リリース前の既存予約や
 * チェックアウト済み予約への「ご予約ありがとうございます」等) を防ぐ。
 *
 * rule.enabled_from が null なら「未有効化」として全予約をブロックする
 * (安全側デフォルト)。ルール作成時はデフォルトで now() が入る想定。
 */
class EnabledFromGuard
{
    /**
     * 予約がこのルールの有効化日時ガードで弾かれるか。true なら送信対象外。
     */
    public function blocks(AutoMessageRule $rule, Booking $booking): bool
    {
        if ($rule->enabled_from === null) {
            return true;
        }

        if ($booking->booking_time === null) {
            return true;
        }

        return $booking->booking_time->lt($rule->enabled_from);
    }
}
