# 本番のデプロイ手順

本番は VPS 上のコンテナで動かす（PHP のバージョンはイメージで固定）。VPS の事業者、Dockerfile・compose はまだ決まっていない。この文書は、イメージの形に依存しない部分（環境変数の渡し方、リリースの手順、公開前の確認）を決める。

## 決まっていること・この手順の前提

- 設定は実環境変数だけで渡す（運営者決定、2026-11-19）。`.env.local`・`.env.prod.local`・`.env.local.php`（`composer dump-env`）はイメージに含めない。Git の `.env`（既定値だけ）は含めてよい。
- 本番のコンテナは1台。cron の `app:timetable:sync` と、運営者が手で実行するコマンド（`app:timetable:gate`・`app:timetable:purge`）は、同じコンテナの中で実行する（ロックの保存先が `LOCK_DSN=flock` のファイルで、別のコンテナとは共有できないため。`docs/data-source-verification.md` §7 手順 7）。**この2点は運営者に未確認。**
- SQLite のファイルは、永続化するボリュームに置く。

## 環境変数

| 環境変数 | 本番での値 | 備考 |
| --- | --- | --- |
| `APP_ENV` | `prod` | `bin/release` が設定する |
| `APP_DEBUG` | `0` | `bin/release` が設定する |
| `APP_SECRET` | 本番用の乱数（空は不可） | Git に置かない |
| `DATABASE_URL` | ボリューム上の SQLite（例：`sqlite:////data/tkn.db`） | 未設定だと `var/` に空のファイルができるので、`bin/release` が拒む |
| `CONTACT_RECIPIENT` | 運営者の受信先 | どの画面にも出さない |
| `CONTACT_FROM` | 送信用アドレス | `MAILER_DSN` の認証と同じアドレスにする（さくらで必要かは未確認） |
| `MAILER_DSN` | `smtp://ユーザー名:パスワード@ホスト:587` | `@` は `%40`、パスワードの記号は URL エンコード。`null://` `native://` は拒まれる |
| `TIMETABLE_WARN_AFTER_SECONDS` / `TIMETABLE_SUSPEND_AFTER_SECONDS` | 既定（259200 / 604800）のままでよい | `0 < 警告 < 停止 ≤ 604800` を満たすこと |
| `ODPT_CONSUMER_KEY` | Go の後に設定する | Go の前は設定しない。許諾が終わったら消す（`docs/data-source-verification.md` §2「許諾終了時の削除」） |
| `LOCK_DSN` | 既定（`flock`）のまま | 1台の前提 |

## リリースの手順

コンテナを起動して公開する前に、リリースの処理として `bin/release` を実行する（コンテナのエントリポイントか、デプロイのスクリプトから呼ぶ）。失敗したら、起動・切り替えをしない（終了コードが 0 以外）。

`bin/release` がすること（Issue #6）：

1. `DATABASE_URL` が実環境変数にあることを確かめる
2. 古い prod のキャッシュ（`var/cache/prod`）を消す
3. `app:check-deploy-config`（`APP_SECRET`・問い合わせの3設定・時刻表の閾値の確認）
4. `doctrine:migrations:migrate`
5. `cache:warmup`

`bin/release` がしないこと（手で確かめる）：

- 本番の `timetable_gate_version` が空か、Go の後に設定した版だけであること（`README.md`。画面は、DB にある最新の版の照合済みの期間をそのまま公開する）
- `DATABASE_URL` のパスが永続化するボリュームと合っていること
- cron の間隔が、警告の閾値の半分以下であること

未確認（実際のイメージで確かめる）：`bin/release` は `sh` で動く。イメージに PHP 8.4 と `ctype`・`iconv`・`pdo_sqlite` があること。本番イメージでの golden test（`ComparedItemsHasherTest`）。マイグレーションを本番の DB に初めて実行するときの、中断条件（`Version20261112045922` は、1日単位でない行があると中断する）。

## 公開前の確認

一般に公開する前（`app:timetable:gate set` より前）に、次を済ませる。済むまで公開しない（`docs/data-source-verification.md` 問い合わせフォームの項）。

- [ ] `bin/release` が、本番と同じ環境変数で成功する
- [ ] 本番のコンテナから `/contact` で実際に 1 通送り、運営者の受信先に届く（VPS からさくらの 587 番ポートに届くかは未確認）
- [ ] 本番で 400・404・500 の画面に例外の内容が出ない（500 は `docs/data-source-verification.md` の Issue #34 の項）
- [ ] 本番の `timetable_gate_version` が空

## 問い合わせの差出人を本サイト専用のアドレスにする（Issue #45）

開発の間は、isl-mentor と同じさくらのアドレスと認証情報を使い回している（運営者決定、2026-11-19）。兄弟サイトとはデプロイを独立させる方針（CLAUDE.md §1）なので、公開前に切り替える。コードは変えない。

1. さくらのコントロールパネルで、本サイト専用の送信用アドレスを作る。
2. 本番の `MAILER_DSN` と `CONTACT_FROM` を、そのアドレスと認証情報に差し替える。コンテナに反映するため、作り直すか再起動する。
3. `bin/release` を実行して成功することを確かめる。
4. 本番のコンテナから `/contact` で 1 通送り、受信先に届くことを確かめる。
5. isl-mentor と認証情報・送信枠を共有していないことを確かめる（isl-mentor 側の `MAILER_DSN` と別のアドレス・パスワードになっている）。
6. 契約のプランの正確な送信上限を、コントロールパネルで確かめる。`/contact` の送信回数の制限（接続元ごと）は、この上限とは別のものである。

切り替えの時期は公開前を想定している（未決定）。
