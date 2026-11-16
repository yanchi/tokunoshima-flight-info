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

デプロイでは、アプリを公開する前に `doctrine:migrations:migrate` を実行する。テーブルがないと、`/` は「運航情報を取得できません」の表示になる（エラーはログに残る）。SQLite は、`DATABASE_URL` のパスにファイルがないと空のファイルを作るので、パスを誤っても気づきにくい。永続化するボリュームのパスと合っているかを確かめる。

時刻表の公開について（`docs/data-source-verification.md` §7 手順 7・§8）：

- 画面は、DB にある最新のゲートの版の照合済みの期間を、そのまま公開する。§7 手順 6 の判定が Go になるまで、本番で `app:timetable:gate set` を実行しない
- デプロイの前に、本番の `timetable_gate_version` が空か、Go の後に設定した版だけであることを確かめる

ODPT のアクセストークンなどの認証情報は `.env.local` に書く（Git にコミットしない）。`.env` にはコミットしてよい既定値だけを置く。

| 環境変数 | 内容 |
| --- | --- |
| `APP_SECRET` | Symfony のシークレット。値は Git にコミットしない。本番での渡し方はデプロイ手順で決める（#6） |
| `ODPT_CONSUMER_KEY` | ODPT のアクセストークン（`.env.local` のみ） |
| `DATABASE_URL` | DB の接続先。既定は環境ごとの SQLite ファイル（`var/data_<環境>.db`）。本番ではファイルを永続化するボリュームに置く（置き場所はデプロイ手順で決める） |
| `CONTACT_ADDRESS` | 問い合わせ先のメールアドレス。公式運航情報の案内と、全ページのフッターの ODPT のクレジット（「以下のメールアドレスにお願いします」）に出すので、メールアドレスを設定する。デプロイ前チェックはメールアドレスの形式までは確かめないので、URL などを設定しないよう注意する。空の間は「お問い合わせは〇〇まで。」の文を出さない。空でよいのは開発・テスト環境だけで、本番公開前に必ず設定する。プレースホルダーの「〇〇」（「○○」も）を含む値は、デプロイ前チェックで未設定として扱う |

## 開発コマンド

```sh
php bin/phpunit                          # テスト
vendor/bin/phpstan analyse               # 静的解析
vendor/bin/php-cs-fixer fix --dry-run --diff   # コーディング規約チェック
composer lint                            # YAML設定・Twig・サービスコンテナの検証
symfony server:start                     # 開発用サーバー（Symfony CLI）
APP_ENV=prod php bin/console app:check-deploy-config  # 本番デプロイ前チェック（APP_SECRET・CONTACT_ADDRESS が空、または CONTACT_ADDRESS がプレースホルダーを含むなら失敗。prod 以外では常に失敗。現状は手動で実行する。自動実行は #6）
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
