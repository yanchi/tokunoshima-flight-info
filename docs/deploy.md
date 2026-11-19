# 本番のデプロイ手順

構成は、運営者の別プロダクトの構成を参考にした（運営者指示、2026-11-19）。VPS 上のホスト nginx が 80/443 を受け、このサイトのコンテナ（`127.0.0.1` のポート）へプロキシする。VPS の IP・ユーザー名・認証情報・同居するサービスの情報は、この公開リポジトリに書かない（VPS 上のファイルだけに置く。自動デプロイを作らない間、GitHub の Secrets は使わない）。

```
インターネット
   ↓ 80/443
ホスト nginx（TLS は certbot）
   └─ DOMAIN → 127.0.0.1:<空いているポート>  tokunoshima-flight-info
                  └─ app  nginx + php-fpm（1コンテナ）。SQLite はボリューム
```

| ファイル | 役割 |
| --- | --- |
| `docker/production/Dockerfile` | 本番イメージ（PHP 8.4.27 に固定）。`docs/`・`.env.local` 系・`tests/` は入れない（`.dockerignore`） |
| `docker/production/entrypoint.sh` | 起動時に `bin/release` を www-data で実行し、失敗したら起動しない（Issue #6） |
| `compose.prod.yml` | 本番の起動定義。設定は実環境変数だけで渡す |
| `deploy/nginx/tokunoshima-flight-info.conf` | ホスト nginx の vhost の雛形 |
| `deploy/.env.production.example` | VPS に置く `.env.production` の雛形 |
| `bin/release` | 設定の確認・migrate・キャッシュ。イメージの中で動く |
| `scripts/verify-prod.sh` | 本番等価のスモークテスト。CI の全 PR で実行する |

## 決まっていること・この手順の前提

- 設定は実環境変数だけで渡す（運営者決定、2026-11-19）。`.env.local`・`.env.prod.local`・`.env.local.php`（`composer dump-env`）はイメージに含めない。Git の `.env`（既定値だけ）は含まれる。
- 本番のコンテナは1台。cron の同期と、運営者が手で実行するコマンド（`app:timetable:gate`・`app:timetable:purge`）は、同じコンテナの中で `docker compose exec` で実行する（ロックが `LOCK_DSN=flock` のファイルで、コンテナの外とは共有できないため。`docs/data-source-verification.md` §7 手順 7）。コンテナの中で cron のデーモンは動かさず、VPS の cron から `exec` する。**「1台」「cron は `docker compose exec`」は、私が決めた前提で、運営者に未確認。**
- ほかのサービスと同じ VPS に同居する可能性があるので、ホスト nginx の 80/443 には手を付けず、このサイトは `127.0.0.1` の空きポートだけを使う。**VPS の構成、DOMAIN、ポートは運営者に未確認。**
- 今は本番のみで、ステージングは作らない（運営者決定、2026-11-19）。
- 自動デプロイ（GitHub Actions から SSH）は作らない。CI は、全 PR でスモークテスト、`main` で GHCR へのイメージの push（コミット SHA のタグ）までを行う。VPS への反映は手で行う（次の PR で自動化するかは未決定）。

## 環境変数

`compose.prod.yml` が、`.env.production`（VPS 上のファイル）から次を渡す。

| 環境変数 | 値 | 備考 |
| --- | --- | --- |
| `APP_IMAGE` | `ghcr.io/yanchi/tokunoshima-flight-info:<コミット SHA>` | `latest` は使わない（未設定だと compose が止まる） |
| `APP_PORT` | 既定 `8003`（例） | VPS で空いているポートにする。ホスト nginx の `proxy_pass` と揃える |
| `APP_SECRET` | 本番用の乱数（`openssl rand -hex 32`） | Git に置かない。空は不可 |
| `CONTACT_RECIPIENT` / `CONTACT_FROM` / `MAILER_DSN` | 問い合わせの3設定 | どの画面にも出さない。`MAILER_DSN` は `smtp://ユーザー名:パスワード@ホスト:587`（`@` は `%40`、記号は URL エンコード。compose が読むので `$` は `$$`）。`null://` `native://` は拒まれる。`CONTACT_FROM` は `MAILER_DSN` の認証と同じアドレスにする（さくらで必要かは未確認） |
| `ODPT_CONSUMER_KEY` | 空 | `docs/data-source-verification.md` §7 手順 6 の判定が Go になった後に設定する。許諾が終わったら消す（§2「許諾終了時の削除」。`.env.production` から消し、`dc up -d` でコンテナを作り直す。`restart` では変わらない） |
| `TIMETABLE_WARN_AFTER_SECONDS` / `TIMETABLE_SUSPEND_AFTER_SECONDS` | 既定（259200 / 604800） | `0 < 警告 < 停止 ≤ 604800` を満たすこと |

`compose.prod.yml` が固定する値（`.env.production` では変えない）：`APP_ENV=prod`、`APP_DEBUG=0`、`DATABASE_URL`（ボリューム `/var/www/html/var/data/tkn.db`）、`SYMFONY_TRUSTED_PROXIES=REMOTE_ADDR`、`LOCK_DSN`（イメージの既定 `flock`）。`DEFAULT_URI` と `APP_SHARE_DIR` は `.env` の既定のまま（本番で変える必要があるかは未確認）。

`SYMFONY_TRUSTED_PROXIES=REMOTE_ADDR` は、コンテナに届くのがホスト nginx だけ（`127.0.0.1` にだけ公開）という前提で、直前の接続元を信頼する。設定しないと、全員が1つの接続元として数えられ、`/contact` の送信回数の枠（接続元ごとに 1 時間 5 回）を全員で共有して止まる（`docs/data-source-verification.md` §8「問い合わせフォーム」）。実際に利用者の接続元が区別されることは、本番で未確認。

## 初回の準備（VPS で1回だけ）

1. 空きポートの確認：`sudo ss -ltnp | grep ':8003 ' || echo 空いている`（8003 は例）。使われていれば、`APP_PORT` と vhost の `proxy_pass` を同じ別のポートにする。
2. ディレクトリと設定：

   ```bash
   sudo mkdir -p /opt/tokunoshima-flight-info
   sudo chown "$USER":"$USER" /opt/tokunoshima-flight-info
   cd /opt/tokunoshima-flight-info
   # デプロイするコミットの compose.prod.yml、deploy/.env.production.example、
   # deploy/nginx/tokunoshima-flight-info.conf を置く（手で転送する。GitHub のそのコミットのファイルから）
   cp .env.production.example .env.production
   chmod 600 .env.production        # 値を入れる。コミットしない
   ```

3. イメージの取得：`main` の CI が GHCR に push したイメージを pull する。パッケージは最初は private。**どちらにするかは未決定**（推奨：イメージには公開リポジトリのコードだけが入り、秘密も ODPT のデータも入らない（`.dockerignore`）ので、パッケージを public にして VPS に認証情報を置かない。private のままなら、VPS で `docker login ghcr.io` する読み取り専用のトークンが要る）。
4. 起動：`docker compose -f compose.prod.yml --env-file .env.production up -d --wait`。entrypoint が `bin/release` を実行し、失敗するとコンテナは止まる（`restart: unless-stopped` なので再起動を繰り返す。`docker compose logs app` で原因を見る）。この時点では、VPS 自身から `curl http://127.0.0.1:<APP_PORT>/about` で見られるだけで、外からは見えない。
5. 「Phase 1 MVP を一般に公開する前」の確認（下記）のうち、127.0.0.1 で確かめられるものを済ませる。
6. **公開する手順（確認が済んだ後に行う）**：DNS の A レコードを VPS に向け、ホスト nginx の vhost を入れて certbot を実行する。この時点で、サイトはインターネットから見える。HTTPS 経由でしか確かめられない項目（下記）は、この後でないと確かめられない。その間の外からのアクセスをどう扱うか（運営者の接続元だけを vhost で許可する、など）は**未決定**。

   ```bash
   # DNS の反映後
   sudo cp deploy/nginx/tokunoshima-flight-info.conf /etc/nginx/sites-enabled/
   sudo sed -i 's/DOMAIN/<実ドメイン>/' /etc/nginx/sites-enabled/tokunoshima-flight-info.conf
   sudo nginx -t && sudo systemctl reload nginx
   sudo certbot --nginx -d <実ドメイン>
   ```

## リリース（デプロイ）の手順

`main` の CI（スモークテストを含む）が通り、GHCR にコミット SHA のイメージができてから行う。

1. バックアップ。マイグレーションは1件ずつコミットされ（`all_or_nothing` は未設定）、途中で止まると DB が途中の版のまま残る。書き込み中のファイルの単純なコピーは不整合になるので、`sqlite3 .backup` を使う：

   ```bash
   cd /opt/tokunoshima-flight-info
   dc() { docker compose -f compose.prod.yml --env-file .env.production "$@"; }
   dc exec -T app sqlite3 /var/www/html/var/data/tkn.db ".backup /tmp/tkn-backup.db"
   mkdir -p backups && chmod 700 backups && dc cp app:/tmp/tkn-backup.db "backups/tkn-$(date +%Y%m%d-%H%M%S).db"
   dc exec -T app rm /tmp/tkn-backup.db
   ```

   バックアップには ODPT 由来の時刻表が入る。ODPT が許諾した DB の保存は「時刻表は次の取得まで」（`docs/data-source-verification.md` §9 M1）なので、**バックアップを残す期間は未決定**（事務局には確認していない）。決めるまでは、リリースが成功して手順 4 の確認が済んだら、そのバックアップを消す。許諾が終わったときは、`backups/` も削除の対象（同文書 §2「許諾終了時の削除」手順 7）。

2. cron の同期を止める（crontab の行をコメントアウト）。そのあと、動いている同期が終わるのを待つ（`flock /tmp/tkn-sync.lock true`。cron と同じロックのファイルで、同期1回は最悪で約 240 秒）。待たずに切り替えると、同期はコンテナの停止（30 秒後に強制終了）で途中で切れる。**許諾が終わった後のデプロイ（`docs/data-source-verification.md` §2「許諾終了時の削除」手順 8）では、手順 5 で cron を戻さない。**
3. `.env.production` の `APP_IMAGE` を新しい SHA に書き換え、`dc pull && dc up -d --wait`（healthy にならなければ 0 以外で返る）。コンテナが作り直され、entrypoint が `bin/release`（設定の確認 → migrate → キャッシュ）を実行する。公開中のコンテナで `bin/release` を手で実行しない（`var/cache/prod` を消すため）。
4. `dc ps` で healthy を確認し、`dc logs --tail=50 app` にエラーがないことを見る。
5. cron を戻す（許諾が終わった後は戻さない。`docs/data-source-verification.md` §2 手順 8 のとおり、cron に `app:timetable:sync` がないこと、キーがないことを確かめる）。

失敗したとき（`bin/release` が止まる、起動しない。nginx や php-fpm が起動に失敗し続けた場合も、コンテナが終了して再起動される）：旧コンテナは、すでに置き換わっている。利用者には、ホスト nginx の 502 の画面が見える（このサイトは止まる。「更新停止／要公式確認」の画面ではなく、非公式の旨・公式運航情報の案内（CLAUDE.md §6）・ODPT のクレジットも出ない）。ホスト nginx の `error_page 502 503 504` で静的な日本語の画面を返す案があるが、**採用するかは未決定**。直すか、`APP_IMAGE` を前の SHA に戻して `dc up -d` する。migrate が途中で止まっていたら、旧イメージに戻す前に、手順 1 のバックアップを戻す。`dc down`（`-v` は付けない）で止め、バックアップをボリュームに書き戻す：`docker run --rm -v tokunoshima-flight-info_tkn-data:/data -v "$PWD/backups:/b:ro" alpine sh -c 'cp /b/<ファイル名> /data/tkn.db && chown 82:82 /data/tkn.db && rm -f /data/tkn.db-wal /data/tkn.db-shm /data/tkn.db-journal'`（ボリューム名は `docker volume ls` で確かめる。www-data の uid/gid 82 は Alpine のもの。**この手順は本番で試していない**）。purge より前のバックアップを戻すと、ODPT のデータが DB に戻る（許諾が終わった後は戻さない）。旧イメージに戻したとき、DB に新しいコードにないマイグレーションが残っていても、`migrate` は警告を出すだけで 0 で終わるので、気づけない。戻すときは、DB も同じ時点に戻す。

**`docker compose down -v` は実行しない。** ボリューム（DB）が消える。

古いイメージの掃除は、このリポジトリのイメージだけを対象にする。`docker image prune` は使わない（同居する別サービスのロールバック用イメージまで消える）。

## 同期（cron）と手動コマンド

VPS の cron から、起動中のコンテナの中で実行する：

```cron
# 例（間隔は未決定）。Go の判定の後に登録する。それまでは登録しない
*/30 * * * * cd /opt/tokunoshima-flight-info && flock -n /tmp/tkn-sync.lock docker compose -f compose.prod.yml --env-file .env.production exec -T -u www-data app php bin/console app:timetable:sync 2>&1 | logger -t tkn-timetable-sync
```

- 同期のログ（JSON）は、コマンドの標準エラーに出る。上の例は VPS の syslog に流す。**保存先と保存期間は未決定**（`docs/data-source-verification.md` §2 手順 9）。
- 間隔は、警告の閾値（既定 3 日）の半分以下にする（`docs/data-source-verification.md` §7 手順 7）。同期1回は最悪で約 240 秒かかる（`flock -n` で重なりを避ける）。ODPT の更新頻度・リクエスト制限との関係は、運営者が Go の判定のときに決める（未決定）。
- パイプで `logger` に渡すと、同期の終了コード（取得失敗などで 1）は cron に届かない。終了コードの監視は未決定。
- 手動コマンドも同じコンテナで：`dc exec -u www-data app php bin/console app:timetable:gate set ...`（`www-data` で実行する。root だと、ログやキャッシュが root 所有になる）。

## 公開前の確認

公開には2つの段階がある。この一覧はデプロイに関わるものだけで、公開の可否は `docs/data-source-verification.md` §10「公開前の確認事項」のすべてを満たしてから決める（JAL サイトの利用規約の原文の目視確認、社名・商標の扱い、`/contact` の文言の確認など、デプロイに関わらない項目はここに含めていない）。

**DNS を向けて certbot を実行した時点で、サイトはインターネットから見える。** Phase 1 MVP の確認が済むまでの間、外からのアクセスをどう扱うか（確認が済むまで DNS を向けない、ホスト nginx で接続元を制限する、など）は**未決定**。決めるまでは、DNS と certbot を、確認が済んだ後に行う。

スモークテスト（`scripts/verify-prod.sh`、CI で毎回）が確かめること：設定が空・`APP_ENV` が prod でないときにコンテナが起動しない、起動して healthy になる、各ページが 200・不正な日付が 400、404 の画面に例外が出ない、`.env` などが配信されない、`APP_ENV`・`SYMFONY_TRUSTED_PROXIES` が PHP に届く、SQLite のファイルが www-data 所有で migrate 済み、再起動しても起動する。**本番の VPS・ホスト nginx・実際のメール送信は確かめていない。**

### Phase 1 MVP を一般に公開する前（`docs/data-source-verification.md` §10 の 8）

- [ ] 本番のコンテナが、本番と同じ環境変数で healthy になっている
- [ ] 本番のコンテナから `/contact` で実際に 1 通送り、運営者の受信先に届く（VPS からさくらの 587 番ポートに届くかは未確認）
- [ ] 本番で 400・404・405・500 の画面に例外の内容が出ず、非公式の旨・公式運航情報の案内文（JAL へのリンク・URL なし）・ODPT のクレジットが出る（500 は `docs/data-source-verification.md` の Issue #34 の項）
- [ ] ホスト nginx の 502・503・504 の画面の扱いを決めた（`error_page` で静的な日本語の画面を返すか。出さない場合は、案内が出ないことを許容する。未決定）
- [ ] （DNS・certbot の後でないと確かめられない）ホスト nginx 経由で、HTTPS で表示され、`/contact` の送信回数の枠が利用者ごとに分かれる（接続元が区別される）
- [ ] `/contact` の文言を運営者が確認した
- [ ] 問い合わせの差出人の切り替え（下記）が済んでいる、または時期を運営者が決めた（未決定）
- [ ] マイグレーションを本番の DB に初めて実行するとき、中断条件（`Version20261112045922` は、1日単位でない行があると中断する）に当たらない
- [ ] 本番の `timetable_gate_version` が空：`dc exec -T app sqlite3 /var/www/html/var/data/tkn.db "select count(*) from timetable_gate_version"` が 0（確認専用のコマンドは作っていない）

### 時刻表を公開する前（`app:timetable:gate set` より前）

- [ ] `docs/data-source-verification.md` §7 手順 6 の判定が Go である（公式時刻表との照合）。**Go の前は、本番に `ODPT_CONSUMER_KEY` を設定しない。** Go の後の順序は、キーを設定してコンテナを作り直す → 同期を実行して時刻表を保存する → `app:timetable:gate set`
- [ ] 本番イメージで golden test（`ComparedItemsHasherTest`）が通る（イメージには `tests/` を入れていない。CI の PHP 8.4 の実行結果で代える場合は、その旨を決める。未決定）
- [ ] 同期のログの保存先・保存期間、cron の間隔を決めた（未決定）

## 未決定（この手順でもまだ決めていない）

- DOMAIN、VPS の構成、ポート（8003 は例。運営者に未確認）
- GHCR のパッケージを public にするか
- 自動デプロイ（SSH）を作るか
- 同期の間隔、同期ログの保存先・保存期間、同期の終了コードの監視（`docs/data-source-verification.md` §2 手順 9）
- ODPT へ要求を送らずに、キーが消えたことを確かめる方法（同 手順 4）。「`ODPT_CONSUMER_KEY` を消す」は、確かめる方法が決まるまで、確認済みとは言えない
- 1台・同じコンテナの前提が崩れたときの、ロックの保存先
- ホスト nginx の 502・503・504 の画面（案内文・非公式の旨・クレジットが出ない）を、静的な日本語の画面にするか
- `healthy` でなくなったことを知らせる監視（nginx・php-fpm が落ち続けた場合、コンテナは終了して再起動される（`exit-on-fatal`。スモークテストで確認）が、再起動を繰り返す間は 502 が続く）
- ホスト nginx のホスト名の固定（`trusted_hosts` はアプリ側の設定で、今は未設定）、コンテナのメモリの上限（`mem_limit`。必要な量は未確認）
- GHCR に push するイメージは、CI のスモークテストで試したビルドとは別のビルド（ベースイメージのタグ `php:8.4.27` と `composer:2.9` は digest で固定していない）。試したイメージそのものを push する形にするかは未決定
- GHCR のパッケージを public にした場合の、`org.opencontainers.image.source` ラベルでのリポジトリとの紐づけ（未確認）
- `sh` の `bin/release` と entrypoint が、本番のイメージ（Alpine の busybox）で動くことは、スモークテストで確かめた。VPS 上の実行ユーザーと権限は未確認

## 問い合わせの差出人を本サイト専用のアドレスにする（Issue #45）

開発の間は、isl-mentor と同じさくらのアドレスと認証情報を使い回している（運営者決定、2026-11-19）。兄弟サイトとはデプロイを独立させる方針（CLAUDE.md §1）なので、公開前に切り替える。コードは変えない。

1. さくらのコントロールパネルで、本サイト専用の送信用アドレスを作る。
2. VPS の `.env.production` の `MAILER_DSN` と `CONTACT_FROM` を、そのアドレスと認証情報に差し替え、`dc up -d` でコンテナを作り直す（環境変数は、作り直さないと変わらない。このとき entrypoint が `bin/release` を実行する）。
3. `dc ps` で healthy を確認する（公開中のコンテナで `bin/release` を手で実行しない）。
4. 本番のコンテナから `/contact` で 1 通送り、受信先に届くことを確かめる。
5. isl-mentor と認証情報・送信枠を共有していないことを確かめる（isl-mentor 側の `MAILER_DSN` と別のアドレス・パスワードになっている）。
6. 契約のプランの正確な送信上限を、コントロールパネルで確かめる。`/contact` の送信回数の制限（接続元ごと）は、この上限とは別のものである。

切り替えの時期は公開前を想定している（未決定）。
