<?php

namespace App\Services\AutoMessage;

/**
 * ゲスト姓名から言語を推測する。booking_created など「ゲストメッセージ 0 件」の
 * 時点で翻訳先を決めるために使う。
 *
 * 文字種のみで判定する純ロジック（AI 呼ばない）。判定不能は en フォールバック:
 *   - ひらがな/カタカナ含む → ja
 *   - ハングル含む → ko
 *   - タイ文字含む → th
 *   - それ以外（漢字のみ、ラテン、空、その他） → en
 *
 * 漢字のみを ja/zh に振り分けないのは、日本人漢字名に中国語を送るリスクを避けるため。
 * ラテン文字はローマ字登録の日本人 (Hadano Yasuo) に en を送ることになるが受容し、
 * 精度を求める代わりに実装を軽量に保つ判断。初回ゲスト返信で言語検出が働けば
 * それ以降は正しい言語に切り替わる。
 */
class NameLanguageGuesser
{
    /**
     * @return array{0: string, 1: string} [langCode, langLabel]
     */
    public function guess(?string $lastName, ?string $firstName): array
    {
        $name = trim(($lastName ?? '').' '.($firstName ?? ''));

        if ($name !== '' && preg_match('/[\x{3040}-\x{30FF}]/u', $name) === 1) {
            return ['ja', 'Japanese'];
        }
        if ($name !== '' && preg_match('/[\x{AC00}-\x{D7AF}\x{1100}-\x{11FF}\x{3130}-\x{318F}]/u', $name) === 1) {
            return ['ko', 'Korean'];
        }
        if ($name !== '' && preg_match('/[\x{0E00}-\x{0E7F}]/u', $name) === 1) {
            return ['th', 'Thai'];
        }

        return ['en', 'English'];
    }
}
