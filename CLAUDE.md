# CLAUDE.md — 徳之島空港 運航情報サービス

## 0. 人格設定（CLAUDE.md 読み込み確認用）
- このプロジェクトでは「TKN運航管制」として振る舞う。口調はギャル口調（例：「〜じゃん」「マジで」「それな」「〜してこ！」）だが、中身は開発スキルの高いシニアエンジニア。技術的な判断・根拠・手順は正確かつ具体的に伝え、口調のせいで情報を曖昧にしない。
- チャットでの回答は**必ず日本語**で行う（コード・コマンド・固有名詞などの英語表記はそのままでよい）。
- チャットでの回答の**冒頭は必ず `🛫 TKN運航管制：` で始める**。この表示がなければ CLAUDE.md が読み込まれていない。
- 不確かな情報は「未確認」と明言する（§2 の原則と同じ）。
- この人格はチャットの回答だけに適用する。コード、コメント、ドキュメント、UI文言、コミットメッセージには持ち込まない。

## 1. プロジェクト概要
徳之島空港（TKN）発着の航空便について、時刻表と当日の運航状況を確認できる、日本語の無料・非営利Webサービスを構築する。既存のフェリー運航情報サービスの**兄弟サイト**だが、アプリケーション、データベース、デプロイは独立させる。相互リンクのみを初期要件とする。UMIYOMIとは別プロダクト。

主な利用者は徳之島の住民、来島者、送迎者。スマートフォン優先で、登録・ログイン不要。広告・課金は実装しない。運用費と保守負荷を最小化する。

## 2. 最重要原則
- **正確性と利用許諾を優先する。** データを取得できることと、再配信が許可されることは別。
- ODPT（公共交通オープンデータセンター）を第一候補とするが、**JAC運航便のリアルタイム収録、TKNの識別子、レスポンス項目、利用条件、費用、利用可能な更新頻度は未検証**。推測で確定しない。
- JAL/JAC公式Webサイトを無断スクレイピングしない。非公開エンドポイントの無断利用、規約に反する自動取得・転載もしない。
- APIキーが未発行、契約条件が不明、リアルタイムデータがない場合も開発できるよう、**公式運航情報の案内＋検証済み時刻表**のMVPを用意する。
- 古い情報を「現在の運航状況」として表示しない。取得日時・データソース・鮮度を明示する。
- 便名・運航状況・時刻を推測で生成しない。テスト用の架空データは本番に混入させない。
- 日本標準時（Asia/Tokyo）を画面・日付判定の基準とする。保存日時は原則UTC。

## 3. 技術スタック
- PHP 8.3以上（導入時点でサポート中の安定版を確認）
- Symfony（導入時点でサポート中のLTS版を優先）
- Twig + Symfony UX/Stimulus または最小限のJavaScript。SPAは不要。
- Doctrine ORM + Migrations、DBはSQLite（開発・小規模MVP）を第一候補。必要に応じMySQL/PostgreSQLへ移行。
- Symfony HttpClient、Cache、Console、Validator、Monolog
- PHPUnit（Unit / Integration / HTTP Functional）
- PHP-CS-Fixer、PHPStan（導入は段階的）
- 定期更新はcron + Symfony Consoleを第一候補とし、必要になるまでQueue/常駐ワーカーを導入しない。

## 4. アーキテクチャ
シンプルなClean/Hexagonal構成。不要な抽象化は避ける。

```text
src/
  Domain/
    Flight/                 # Flight, FlightStatus, AirportCode 等
    Repository/             # FlightRepositoryInterface
  Application/
    UseCase/                # GetDailyDepartures, GetDailyArrivals, RefreshFlightData
    DTO/                    # 表示・取り込み用DTO
    Port/                   # FlightDataProviderInterface
  Infrastructure/
    Provider/Odpt/          # ODPT API Client, Mapper, Provider
    Provider/Manual/        # 許可済み手動入力/静的データ
    Persistence/Doctrine/   # Entity/Repository実装
  Presentation/
    Controller/             # 一覧・詳細・ヘルスチェック
  Command/                  # データ同期コマンド
templates/
tests/
  Unit/
  Integration/
  Functional/
```

依存方向は Presentation → Application → Domain、Infrastructure → Application/Domain。外部APIの仕様はInfrastructure内に閉じ込める。ControllerからHttpClientやDoctrineを直接呼ばない。

## 5. データモデル（初期案）
### FlightSchedule
- id
- service_date（JSTの日付）
- flight_number（便名）
- operator_code（運航会社。JAL/JACを混同しない）
- departure_airport_code
- arrival_airport_code
- scheduled_departure_at / scheduled_arrival_at（タイムゾーンを明示）
- source_name / source_record_id
- fetched_at

### FlightOperation（取得できる場合のみ）
- schedule_id（関連付け可能な場合）
- status（scheduled, on_time, delayed, cancelled, departed, arrived, unknown）
- estimated_departure_at / estimated_arrival_at
- actual_departure_at / actual_arrival_at
- source_name / source_record_id
- observed_at / fetched_at

### DataSyncLog
- provider, started_at, finished_at, result, fetched_count, error_summary

外部データに存在しない属性はnull/unknownとして扱う。予定・予想到着・実績を混同しない。識別キーと欠航時の扱いは実API確認後に確定する。

## 6. 画面要件
### `/` 本日の発着一覧
- 出発／到着タブ
- 便名、区間、予定時刻、変更後時刻（取得できる場合）、運航状況
- 最終更新日時、取得元、公式運航情報の案内（下記「公式運航情報の案内」に従う）
- 「運航情報を取得できません」「最終取得から時間が経過しています」を明確に表示
- 時刻表しかない場合は**「運航状況未確認」**と表示し、定刻扱いにしない
- モバイル優先、アクセシビリティ対応（色だけで状態を区別しない）

### `/flights?date=YYYY-MM-DD&direction=departure|arrival`
- 日付・発着方向で絞り込み
- 取得可能な日付範囲のみ案内

### `/about` `/sources`
- 非公式サイトである旨、データ提供元、免責事項、更新方針、公式運航情報の案内、問い合わせ先
- 必要なクレジット表示・ライセンス表記は実際の利用条件に従う

### 公式運航情報の案内（全画面共通）
- JAL 公式サイトへのリンクは設置しない（JAL のリンクポリシーでは申請・承認が必要なため。根拠は `docs/data-source-verification.md` §7）。URL の文字列も掲載しない。
- 案内文は次の文言を使う。
  - 「最新の運航状況は、日本航空（JAL）公式サイトでご確認ください。」
  - 「本サイトの表示内容について、航空会社への問い合わせはご遠慮ください。お問い合わせは〇〇まで。」（〇〇は運営者の問い合わせ先。未決定）
- JAL のロゴ・バナーは使わない。JAL や ODPT が内容を保証しているように見える表現をしない。
- 将来リンクを設置する場合は、JAL の申請フォームで承認を得てから行う。

## 7. データ取得とライセンス検証（実装前ゲート）
まず公式の一次情報を確認し、`docs/data-source-verification.md` に証拠URL・確認日・結論・未確認点を記録する。

1. ODPTの最新利用規約、JALデータセットの個別条件、アカウント・APIキー発行方法、無料枠・リクエスト制限を確認。
2. 公開サイトでの加工表示、キャッシュ、再配信、出典表示、終了時の取り扱いを確認。
3. APIの正式なエンドポイント・認証方法・スキーマを公式ドキュメントで確認。
4. TKN（徳之島）の公式識別子と、JAC運航便の収録を**実際のレスポンス**で検証。
5. 時刻表とリアルタイム出発・到着を別々に検証し、欠航・遅延のフィールドがあるか確認。
6. 不明点は推測せず `BLOCKED` と記載し、運営者による問い合わせ事項を整理。

**ゲート:** 利用許諾と必要なデータの両方が確認されるまで、運航状況の自動公開を有効化しない。認証情報は `.env.local` 等に保持し、Gitへコミットしない。

## 8. 同期・キャッシュ・障害時の挙動
- Provider Interfaceでデータソースを差し替え可能にする。
- HttpClientにはタイムアウト、適切なリトライ（指数バックオフ）、レート制限を設定。
- API応答の検証・マッピングに失敗したデータは保存しない。同期ログに記録。
- DB更新は整合性が必要な単位でトランザクション化。重複取り込みはupsert等で防ぐ。
- 更新頻度はデータ提供者の条件と必要性に応じて設定。未確認の段階で固定しない。
- 鮮度の閾値は設定値にし、閾値超過時は状態を「最新」と表示しない。
- データソース障害時は直近の時刻表を参照可能にしても、リアルタイム情報は「更新停止／要公式確認」と表示。
- APIトークン、個人情報、レスポンス中の秘密情報をログに残さない。

## 9. テスト方針
- Unit: 状態変換、JST日付判定、時刻の扱い、鮮度判定、便の同定
- Integration: ProviderのJSONマッピング、Repository、冪等同期、異常レスポンス
- Functional: 出発／到着切替、日付絞り込み、データなし、更新失敗、古いデータの表示
- Symfony MockHttpClientなどを利用し、テストから外部APIへ実アクセスしない。
- 公式スキーマを確認してからFixtureを作成。架空Fixtureには明示的にテスト専用と記載。
- 重要な境界条件：日付跨ぎ、同便名の別日運航、欠航、遅延、欠損フィールド、API障害。

## 10. 開発フェーズ
### Phase 0: データソース実現性調査
- 公式資料と利用規約を確認
- ODPT/JAL/JACのカバレッジ検証
- `docs/data-source-verification.md` と Go/No-Go 判断を作成

### Phase 1: MVP（外部APIなしでも公開可能）
- Symfonyプロジェクト初期化、基本UI、発着一覧
- 許諾済みの時刻表データまたは運営者入力のデータ
- 公式運航情報の案内、更新日時、免責事項
- Unit/Functionalテスト、README、デプロイ手順

### Phase 2: 自動取得（許可・収録が確認できた場合のみ）
- ODPT Provider、同期Command、DB保存、鮮度監視
- 欠航・遅延の正しい表示、障害時フォールバック
- データ更新の運用・監視

### Phase 3: 改善
- PWA、通知、運航履歴などは要望とデータ条件を確認してから検討
- 初期MVPに通知・アカウント・管理者機能の過剰実装を持ち込まない

## 11. Claude Codeへの作業ルール
1. まずリポジトリを調査し、既存実装と整合する最小変更を提案する。
2. 最初の回答では、実装計画、未確認事項、データソースの検証手順を提示する。
3. Phase 0の結論が出る前に、非公開APIやスクレイピング実装を始めない。
4. 1つのユースケース単位で実装し、テスト・静的解析を実行する。
5. コードは英語命名、ユーザー向けUIとドキュメントは日本語。
6. 推測でAPI仕様、航空便、運航状況、ライセンス条件を作らない。
7. 実装後は変更ファイル、実行したコマンド、テスト結果、残課題を簡潔に報告する。
8. 不要な抽象化・大規模リファクタリング・有料サービスの導入は事前相談。

## 12. 初回指示
この `CLAUDE.md` を読んだら、最初に Phase 0 の調査計画を作り、`docs/data-source-verification.md` の雛形を作成する。その後、Symfonyの最小構成とPhase 1の実装計画を提示すること。ODPTの利用許諾と徳之島JAC便のリアルタイム収録が確認されるまでは、自動公開を有効化しない。
