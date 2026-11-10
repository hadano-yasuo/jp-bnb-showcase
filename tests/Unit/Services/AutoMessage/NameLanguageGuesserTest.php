<?php

namespace Tests\Unit\Services\AutoMessage;

use App\Services\AutoMessage\NameLanguageGuesser;
use PHPUnit\Framework\TestCase;

class NameLanguageGuesserTest extends TestCase
{
    public function test_hiragana_returns_ja(): void
    {
        $this->assertSame(['ja', 'Japanese'], (new NameLanguageGuesser)->guess('やまだ', 'たろう'));
    }

    public function test_katakana_returns_ja(): void
    {
        $this->assertSame(['ja', 'Japanese'], (new NameLanguageGuesser)->guess('サトウ', 'イチロウ'));
    }

    public function test_mixed_kanji_and_kana_returns_ja(): void
    {
        $this->assertSame(['ja', 'Japanese'], (new NameLanguageGuesser)->guess('田中', 'たろう'));
    }

    public function test_hangul_returns_ko(): void
    {
        $this->assertSame(['ko', 'Korean'], (new NameLanguageGuesser)->guess('김', '윤호'));
    }

    public function test_thai_returns_th(): void
    {
        $this->assertSame(['th', 'Thai'], (new NameLanguageGuesser)->guess('สมชาย', 'ใจดี'));
    }

    public function test_kanji_only_returns_en_fallback(): void
    {
        // 漢字のみは ja/zh 曖昧。日本人漢字名に中国語を送るリスクを避けて en に倒す。
        $this->assertSame(['en', 'English'], (new NameLanguageGuesser)->guess('王', '伟'));
        $this->assertSame(['en', 'English'], (new NameLanguageGuesser)->guess('田中', '太郎'));
    }

    public function test_latin_returns_en(): void
    {
        // ローマ字登録の日本人 (Hadano Yasuo) も en 扱い。精度より軽量さを取る判断。
        $this->assertSame(['en', 'English'], (new NameLanguageGuesser)->guess('Hadano', 'Yasuo'));
        $this->assertSame(['en', 'English'], (new NameLanguageGuesser)->guess('Smith', 'John'));
        $this->assertSame(['en', 'English'], (new NameLanguageGuesser)->guess('Domínguez', 'Javier'));
    }

    public function test_empty_name_returns_en(): void
    {
        $this->assertSame(['en', 'English'], (new NameLanguageGuesser)->guess(null, null));
        $this->assertSame(['en', 'English'], (new NameLanguageGuesser)->guess('', ''));
        $this->assertSame(['en', 'English'], (new NameLanguageGuesser)->guess('   ', ''));
    }

    public function test_cyrillic_and_other_scripts_return_en_fallback(): void
    {
        $this->assertSame(['en', 'English'], (new NameLanguageGuesser)->guess('Иванов', 'Иван'));
    }
}
