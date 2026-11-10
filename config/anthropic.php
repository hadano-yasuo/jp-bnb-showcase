<?php

return [
    /**
     * Anthropic API key（Console: https://console.anthropic.com/settings/keys）
     * Cline 等の他ツールと分けて用途別に発行推奨（漏洩時の切り分け・使用量把握）。
     */
    'api_key' => env('ANTHROPIC_API_KEY'),

    /**
     * Messages API のベース URL（通常デフォルトのまま）
     */
    'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),

    /**
     * デフォルトモデル。Sonnet 4.6 は日英中韓西の返信生成で
     * コスト・品質のバランスが最も良い（2026-09時点）。
     */
    'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),

    /**
     * 翻訳専用モデル。Haiku 4.5 は定型的な翻訳作業で十分な品質で、
     * Sonnet の 1/5 程度のコスト。バックフィルや sync 時の大量翻訳に有利。
     */
    'translation_model' => env('ANTHROPIC_TRANSLATION_MODEL', 'claude-haiku-4-5'),

    /**
     * Anthropic API version（Console 側で固定推奨バージョンを指定）
     */
    'api_version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),

    /**
     * HTTP タイムアウト（秒）。draft 生成は数秒で完了するが余裕を持たせる。
     */
    'http_timeout' => 30,

    /**
     * max_tokens for reply drafts. メッセージ返信は長くて 500 tokens 程度で足りる。
     */
    'draft_max_tokens' => 1024,
];
