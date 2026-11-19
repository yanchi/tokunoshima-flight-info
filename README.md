# 徳之島 フライト運航情報システム

徳之島空港（TKN / RJKN）発着便の運航情報（定刻・遅延・欠航など）を確認するためのシステム。

## 動作環境

- PHP 8.4 以上（拡張：ctype, iconv。`composer.json` の `require` と揃える）
- Composer 2
- Symfony 7.4 LTS

## セットアップ

```sh
composer install
php bin/console doctrine:migrations:migrate   # DB（SQLite）のテーブルを作る・更新する
```

デプロイの手順は `docs/deploy.md`。本番イメージは起動時に `bin/release`（デプロイ前チェック・`doctrine:migrations:migrate`・キャッシュの作り直し）を実行し、失敗したら起動しない。`scripts/verify-prod.sh` で、本番と同じ構成をローカルで確かめられる（Docker が要る）。テーブルがないと、`/` は「運航情報を取得できません」の表示になる（エラーはログに残る）。SQLite は、`DATABASE_URL` のパスにファイルがないと空のファイルを作るので、パスを誤っても気づきにくい。永続化するボリュームのパスと合っているかを確かめる。

時刻表の公開について（`docs/data-source-verification.md` §7 手順 7・§8）：

- 画面は、DB にある最新のゲートの版の照合済みの期間を、そのまま公開する。§7 手順 6 の判定が Go になるまで、本番で `app:timetable:gate set` を実行しない
- デプロイの前に、本番の `timetable_gate_version` が空か、Go の後に設定した版だけであることを確かめる

ODPT のアクセストークンなどの認証情報は、開発では `.env.local` に書く（Git にコミットしない）。本番は実環境変数だけで渡す（`docs/deploy.md`）。`.env` にはコミットしてよい既定値だけを置く。

| 環境変数 | 内容 |
| --- | --- |
| `APP_SECRET` | Symfony のシークレット。値は Git にコミットしない。本番では実環境変数だけで渡す（`docs/deploy.md`） |
| `ODPT_CONSUMER_KEY` | ODPT のアクセストークン（開発は `.env.local` のみ。本番は実環境変数） |
| `DATABASE_URL` | DB の接続先。既定は環境ごとの SQLite ファイル（`var/data_<環境>.db`）。本番ではファイルを永続化するボリュームに置く（`docs/deploy.md`。本番では必ず設定する） |
| `CONTACT_RECIPIENT` | 問い合わせフォーム（`/contact`）の受信先メールアドレス（運営者）。どの画面にも出さない。空の間、`/contact` は 503「準備中」を返す。空でよいのは開発・テスト環境だけ |
| `CONTACT_FROM` | 問い合わせメールの差出人アドレス。さくらのレンタルサーバーでは、SMTP 認証のアドレスと同じにする（未確認）。どの画面にも出さない |
| `MAILER_DSN` | メール送信の接続先（例：`smtp://ユーザー名:パスワード@ホスト:587`。`@` は `%40`、パスワードの記号は URL エンコード）。パスワードを含むので `.env.local` か本番の実環境変数にだけ置き、Git にコミットしない。既定の `null://null` は何も送らない。本番では `null://` と `native://` をデプロイ前チェックで拒み、`/contact` も 503 にする |

## 開発コマンド

```sh
php bin/phpunit                          # テスト
vendor/bin/phpstan analyse               # 静的解析
vendor/bin/php-cs-fixer fix --dry-run --diff   # コーディング規約チェック
composer lint                            # YAML設定・Twig・サービスコンテナの検証
symfony server:start                     # 開発用サーバー（Symfony CLI）
APP_ENV=prod php bin/console app:check-deploy-config  # 本番デプロイ前チェック（APP_SECRET・CONTACT_RECIPIENT・CONTACT_FROM・MAILER_DSN が空、または MAILER_DSN が何も送らない設定（`null://` `native://`）なら失敗。prod 以外では常に失敗。本番のリリースでは `bin/release` が実行する）
```

## ディレクトリ構成

- `src/Domain/`   — ドメイン層（便名・空港コード・運航状況・運航日・発着方向・鮮度判定）
- `src/Application/` — ユースケース（`GetDailyFlights`、`SyncTimetable`、`SetTimetableGate` など）、表示用DTO、照合のゲート（`Gate/`）、ポート（`TimetableProviderInterface`、`TimetableRepositoryInterface`、`TimetableGateRepositoryInterface` など）
- `src/Infrastructure/` — ポートの実装。ODPT の時刻表の取得（`Provider/Odpt/`）と、時刻表・ゲートの保存（`Persistence/Doctrine/`）。画面は、保存した時刻表をゲートの公開範囲と鮮度の判定に従って表示する（`docs/data-source-verification.md` §7 手順 7・§8）
- `src/Presentation/Controller/` — 画面（`/` 本日の発着一覧）
- `src/Presentation/Twig/` — 表示ルール（運航状況ラベル）
- `config/`       — Symfony の設定
- `migrations/`   — Doctrine のマイグレーション
- `templates/`    — Twig テンプレート
- `tests/Unit/`   — ユニットテスト
- `tests/Functional/` — 画面の機能テスト
- `tests/Support/` — テスト専用の架空データ・リポジトリ（本番では使わない）
- `docs/`         — 設計メモ・データソース検証記録

アーキテクチャと開発方針は `CLAUDE.md` を参照。
