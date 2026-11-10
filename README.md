# jp-bnb Admin & Integration — Code Showcase

このリポジトリは、自身で運営する民泊事業向けに開発した **Web サイト＋顧客管理システム** のソースコードを、採用選考用に一部抜粋・抽象化して公開したものです。

実運用中の自社事業のコードを元にしていますが、顧客データ・物件固有情報・API キー・インフラ情報は除外・マスキングしています。

---

## 1. 元のサービスについて

- 自社の民泊事業向けに、以下を 1 つの Laravel アプリで統合運用するシステム
  - 公開 HP（施設紹介・問い合わせ・直接予約）
  - 管理機能（beds24 連携による予約・メッセージ一元管理）
  - ゲスト向けチェックイン前情報登録フロー
  - AI による多言語返信下書き・ゲスト対応補助
- 初版: Next.js 15 + TypeScript で構築
- 現行: **Laravel 13 + Filament 5 + Livewire + Alpine.js へ全面リプレイス**

本リポジトリには **管理機能・外部統合・AI 連携・ゲスト情報登録** の実装を中心に収録しています。
公開 HP の Blade テンプレート・多言語リソース・物件固有情報は含まれません。

---

## 2. 技術スタックと選定理由

| 分野 | 採用技術 | 選定理由 |
|------|----------|----------|
| フレームワーク | Laravel 13 | 1 人運用のメンテコスト最小化。キュー・スケジューラ・通知・メールが標準搭載 |
| 管理画面 | Filament 5 | 宣言的に短期間で admin UI を構築でき、Nova と違いライセンスコストゼロ |
| フロント統合 | Livewire + Alpine.js | フロント／バックの型・認可の二重管理を排除。SPA より保守コスト低 |
| ビルド | Vite 8 + Tailwind CSS 4 | 公開 HP と admin で同一スタック |
| 認証 | Breeze + Spatie Permission | admin／staff の 2 ロール制 |
| 決済 | Stripe Checkout | 直接予約時に Stripe → webhook → beds24 自動投入 |
| 外部連携 | beds24 API v2 | OTA 統合のハブ（Airbnb/Booking.com/自社直販） |
| AI | Anthropic Claude API | 多言語返信下書き、翻訳、身分証 OCR |
| ストレージ | Cloudflare R2 (S3 互換) | 身分証画像保存。R2 を選んだ理由は転送量課金なしで民泊写真の配信にも使いまわせること |
| メール | Resend | SMTP 設定不要、DKIM/SPF 対応が容易 |

### 「なぜ Next.js を廃止したか」

初版の Next.js 15 (App Router) + TypeScript を半年ほど運用した結果、下記の判断で Laravel + Filament へ全面リプレイスしました。

- 1 人運用のメンテナンス負荷を最小化する必要があったこと
- フロント／バックで二重管理していた型・認可ロジックを、サーバーサイド中心に統一し開発速度を優先するため
- Filament で管理画面を宣言的に高速構築でき、特に顧客管理 (beds24 API v2 連携) の実装期間を大幅に短縮できたため

リプレイス後、同じ機能量の実装に要する工数が体感で **1/3 程度** まで短縮されました。

---

## 3. 設計上の見どころ

### 3.1 DDD を意識した層分離

```
app/
├── Domain/              ← エンティティ・リポジトリ interface（将来拡張用）
├── Infrastructures/     ← 外部 API 等の実装
├── Services/            ← ユースケース層（Beds24 / AI / AutoMessage / Stripe 等）
├── Presentations/       ← 画面向け DTO（将来拡張用）
├── Filament/            ← 管理画面（Resources / Pages）
├── Livewire/            ← Livewire コンポーネント
├── Http/                ← Controller / Middleware
├── Jobs/                ← キュージョブ
├── Models/              ← Eloquent モデル
└── Providers/           ← サービスプロバイダ
```

ドメイン駆動を意識した層分離の骨格を用意し、本サービスは規模が小さいため Domain/ 配下は空（.gitkeep のみ）に留め、実ロジックは Services/ 層に集約しています。

### 3.2 beds24 API v2 統合

**`app/Services/Beds24/`**

- `Beds24Client` — 認証・トークンキャッシュ・レート制限対応
- `BookingUpserter` — OTA/直販の予約を 1 本の `bookings` テーブルに統合
- `MessageReadSyncer` — ゲスト↔管理者の双方向メッセージ同期
- `Beds24BookingCreator` — Stripe 決済成立 → beds24 へ POST /bookings で自動予約成立

beds24 の API 仕様は書面と実物が食い違う部分が多く、全ロジックは **staging 実物検証ベース** で実装しています。

### 3.3 AI 連携（Anthropic Claude）

**`app/Services/AI/`**

- `ClaudeClient` — Messages API のラッパー
- `ReplyDrafter` / `GuestChannelReplyDrafter` — ゲスト履歴から返信下書きを生成
- `TranslationService` / `ProofreadService` — 多言語翻訳と校正
- `LanguageDetector` — ゲスト言語の自動判定
- `BuildingInfoResolver` — Prompt に建物情報を注入するためのリゾルバ

Prompt を汎用化し、固有名詞（物件名・ゲスト名）を「翻訳しない」制約として明示的に伝えることで誤訳を抑制しています。

### 3.4 自動メッセージ配信

**`app/Services/AutoMessage/`**

- `AutoMessageScheduler` — ルール（T-3 / T-1 / T-0 など）に沿って送信予定を登録
- `AutoMessageDispatcher` — 送信前バリデーション（dry-run 対応）
- `ChannelMatcher` — 予約の流入経路（Airbnb / Booking / 直販）に応じて配信チャネルを選択
- `BodyRenderer` — Blade テンプレート + 動的変数（ゲスト名・言語・部屋・チェックイン時刻）
- `EnabledFromGuard` — ルール有効化前の予約を対象外とするガード

### 3.5 Stripe + beds24 の直接予約フロー

**`app/Http/Controllers/StripeCheckoutController.php`** / **`app/Services/Beds24/Beds24BookingCreator.php`**

1. ゲストが `/reserve/direct` で予約フォーム送信（公開 HP 側、本リポジトリには含まず）
2. Stripe Checkout Session を発行
3. 決済完了 → Stripe webhook `checkout.session.completed` を受信
4. webhook ハンドラ内で beds24 へ POST /bookings を発火、booking を即時成立

Stripe webhook の URL は公開 HP ドメインに届くため、Laravel の URL ジェネレータが request host をベースに admin URL を誤生成する問題を回避するロジックが `app/Support/PublicUrl.php` にあります。

### 3.6 ゲスト本人情報登録（身分証 OCR）

**`app/Services/GuestIdImage/`** / **`app/Http/Controllers/GuestRegistrationController.php`**

- 48 文字の使い捨てトークンで URL 発行
- アップロードされた身分証画像を Cloudflare R2 の private バケットへ保存
- Claude API で OCR → 氏名・生年月日・パスポート番号を自動抽出
- 重複検出（同一ゲストの再登録を検知）

---

## 4. ディレクトリ構成（抜粋）

```
.
├── app/                 — アプリケーション本体（上記 §3 参照）
├── config/              — 設定（物件固有情報を含むものは除外）
├── database/
│   ├── migrations/      — スキーマ（本番運用中の構成）
│   └── factories/       — テスト用ファクトリー
├── resources/
│   ├── views/filament/  — Filament カスタムビュー
│   ├── views/livewire/  — Livewire ビュー
│   ├── views/emails/    — メールテンプレート
│   ├── views/guest-registrations/  — ゲスト登録フォーム
│   ├── js/
│   └── css/
├── routes/
│   └── web.php          — Guest registration / Stripe webhook のみ公開
├── tests/               — PHPUnit テスト
└── .env.example         — プレースホルダのみ（実運用値は含まず）
```

---

## 5. 公開しないもの

- **シークレット**: `.env`, API キー、refresh token 類
- **顧客データ**: 実際のゲスト情報・予約履歴
- **物件固有情報**: 建物名、住所、beds24 property ID、本番 VPS の IP
- **公開 HP のビュー**: 民泊事業ブランドの意匠そのものに該当するため
- **内部ドキュメント**: `docs/` 配下（運用手順、beds24 検証ノート等）
- **データベースシーダー**: 物件マスタ投入スクリプト

これらは**別リポジトリ**で private 管理しています。

---

## 6. ローカル実行（参考）

```bash
cp .env.example .env
composer install
npm ci
php artisan key:generate
php artisan migrate
npm run dev
php artisan serve
```

ただし本リポジトリには **公開 HP のビューが含まれない**ため、`/`（公開 HP）はルーティングが登録されず動作しません。
動作確認は `/admin`（Filament ログイン）と `/register/{token}`（ゲスト登録フォーム）のみ可能です。

ローカルで admin を作る:

```bash
php artisan user:make-admin your@example.com
```

---

## 7. 開発品質

- **Pint** — コード整形（`vendor/bin/pint --test` でチェック）
- **PHPStan**（phpstan.neon 同梱） — 静的解析
- **PHPUnit**（tests/）— Feature / Unit テスト

---

## 8. 作者について

個人事業主フリーランスエンジニア。Laravel / PHP を主軸に、Web サービスの要件定義から運用まで一貫して担当。
本サービスは自身の民泊事業で稼働中。
