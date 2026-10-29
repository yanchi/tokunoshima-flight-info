# 徳之島 フライト運航情報システム

徳之島空港（TKN / RJKN）発着便の運航情報（定刻・遅延・欠航など）を確認するためのシステム。

## 動作環境

- PHP 8.4 以上（拡張：ctype, iconv。`composer.json` の `require` と揃える）
- Composer 2
- Symfony 7.4 LTS

## セットアップ

```sh
composer install
```

ODPT のアクセストークンなどの認証情報は `.env.local` に書く（Git にコミットしない）。`.env` にはコミットしてよい既定値だけを置く。

| 環境変数 | 内容 |
| --- | --- |
| `APP_SECRET` | Symfony のシークレット。本番では実際の環境変数で渡す（コミットしない） |
| `ODPT_CONSUMER_KEY` | ODPT のアクセストークン（`.env.local` のみ） |
| `FLIGHT_DATA_MAX_AGE_SECONDS` | これより古い運航データは「最新」として扱わない（秒）。暫定値 |
| `CONTACT_ADDRESS` | 公式運航情報の案内に出す問い合わせ先。空の間は「お問い合わせは〇〇まで。」の文を出さない。空でよいのは開発・テスト環境だけで、本番公開前に必ず設定する |

## 開発コマンド

```sh
php bin/phpunit                          # テスト
vendor/bin/phpstan analyse               # 静的解析
vendor/bin/php-cs-fixer fix --dry-run --diff   # コーディング規約チェック
composer lint                            # YAML設定・Twig・サービスコンテナの検証
symfony server:start                     # 開発用サーバー（Symfony CLI）
APP_ENV=prod php bin/console app:check-deploy-config  # 本番デプロイ前チェック（APP_SECRET・CONTACT_ADDRESS が空なら失敗。prod 以外では常に失敗）
```

## ディレクトリ構成

- `src/Domain/`   — ドメイン層（便名・空港コード・運航状況・運航日・発着方向・鮮度判定）
- `src/Application/` — ユースケース（`GetDailyFlights`）、表示用DTO、ポート（`FlightBoardRepositoryInterface`）
- `src/Infrastructure/` — ポートの実装。データソース未承認のため、現在は常に「データなし」を返す `NoDataFlightBoardRepository` のみ
- `src/Presentation/Controller/` — 画面（`/` 本日の発着一覧）
- `src/Presentation/Twig/` — 表示ルール（運航状況ラベル、データが古いときの表示の抑止）
- `config/`       — Symfony の設定
- `templates/`    — Twig テンプレート
- `tests/Unit/`   — ユニットテスト
- `tests/Functional/` — 画面の機能テスト
- `tests/Support/` — テスト専用の架空データ・リポジトリ（本番では使わない）
- `docs/`         — 設計メモ・データソース検証記録

アーキテクチャと開発方針は `CLAUDE.md` を参照。
