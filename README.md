# 徳之島 フライト運航情報システム

徳之島空港（TKN / RJKN）発着便の運航情報（定刻・遅延・欠航など）を確認するためのシステム。

## 動作環境

- PHP 8.4 以上（拡張：ctype, iconv, intl, mbstring, pdo_sqlite）
- Composer 2
- Symfony 7.4 LTS

## セットアップ

```sh
composer install
```

ODPT のアクセストークンなどの認証情報は `.env.local` に書く（Git にコミットしない）。`.env` にはコミットしてよい既定値だけを置く。

## 開発コマンド

```sh
php bin/phpunit                          # テスト
vendor/bin/phpstan analyse               # 静的解析
vendor/bin/php-cs-fixer fix --dry-run --diff   # コーディング規約チェック
composer lint                            # YAML設定・Twig・サービスコンテナの検証
symfony server:start                     # 開発用サーバー（Symfony CLI）
```

## ディレクトリ構成

- `src/Domain/`   — ドメイン層（便名・空港コード・運航状況・運航日・鮮度判定）
- `config/`       — Symfony の設定
- `templates/`    — Twig テンプレート
- `tests/Unit/`   — ユニットテスト
- `docs/`         — 設計メモ・データソース検証記録

アーキテクチャと開発方針は `CLAUDE.md` を参照。
