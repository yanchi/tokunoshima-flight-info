# ODPT 実レスポンスの証拠

ODPT API の実レスポンスを、キーを伏せて保存したもの。検証記録として手元にだけ置き、レスポンス本体はコミットしない（ODPT の保存・再配信の可否の回答にかかわらず）。コミットするのはこの README（対応表）だけ。キーやレスポンス本文を書き込まないこと。
URL の `<KEY>` は、本物のキーを伏せた箇所。

## 2026-10-29（23:41 JST 頃）
| ファイル | リクエスト | HTTP |
|---|---|---|
| airport_tkn.{json,headers} | `odpt:Airport?owl:sameAs=odpt.Airport:TKN&acl:consumerKey=<KEY>` | 200 |
| badkey_probe.headers | `odpt:Airport?owl:sameAs=odpt.Airport:XXX&acl:consumerKey=<KEY>`（存在しない空港 ID。本文は `[]`） | 200 |
| sched_from.{json,headers} | `odpt:FlightSchedule?odpt:operator=odpt.Operator:JAL&odpt:originAirport=odpt.Airport:TKN&acl:consumerKey=<KEY>` | 200 |
| sched_to.{json,headers} | `odpt:FlightSchedule?odpt:operator=odpt.Operator:JAL&odpt:destinationAirport=odpt.Airport:TKN&acl:consumerKey=<KEY>` | 200 |
| dep.{json,headers} | `odpt:FlightInformationDeparture?odpt:operator=odpt.Operator:JAL&odpt:departureAirport=odpt.Airport:TKN&acl:consumerKey=<KEY>` | 200 |
| arr.{json,headers} | `odpt:FlightInformationArrival?odpt:operator=odpt.Operator:JAL&odpt:arrivalAirport=odpt.Airport:TKN&acl:consumerKey=<KEY>` | 200 |
| dep_anyop.{json,headers} | `odpt:FlightInformationDeparture?odpt:departureAirport=odpt.Airport:TKN&acl:consumerKey=<KEY>`（operator 指定なし） | 200 |
| status.{json,headers} | `odpt:FlightStatus?acl:consumerKey=<KEY>` | 200 |
| badparam.{json,headers} | `odpt:FlightInformationDeparture?odpt:departureAirport=odpt.Airport:TKN&foo=bar&acl:consumerKey=<KEY>` | 200（本文 `[]`） |
| badtype.{body.txt,headers} | `odpt:NoSuchType?acl:consumerKey=<KEY>` | 404 |
| nokey.body.txt | `odpt:Airport?owl:sameAs=odpt.Airport:TKN`（キーなし） | 403（ヘッダーは未保存。2026-10-30 に再取得） |
| badkey.body.txt | `odpt:Airport?owl:sameAs=odpt.Airport:TKN&acl:consumerKey=invalid000` | 403（同上） |

## 2026-10-30
| ファイル | リクエスト | HTTP |
|---|---|---|
| nokey.{body.txt,headers} | `odpt:Airport?owl:sameAs=odpt.Airport:TKN`（キーなし） | 403 |
| badkey.{body.txt,headers} | `odpt:Airport?owl:sameAs=odpt.Airport:TKN&acl:consumerKey=invalid000` | 403 |

## 2026-11-11（15:07 JST 頃）
公式時刻表との照合（検証記録 §7）に使うため、時刻表を再取得した。`2026-11-11/` に保存し、2026-10-29 のファイルは上書きしていない。`cmp` で比べると、`2026-10-29/` の `sched_from.json` / `sched_to.json` とバイト単位で同一だった（`dc:date` 2026-08-21T13:00:00+09:00）。

| ファイル | リクエスト | HTTP |
|---|---|---|
| sched_from.{json,headers} | `odpt:FlightSchedule?odpt:operator=odpt.Operator:JAL&odpt:originAirport=odpt.Airport:TKN&acl:consumerKey=<KEY>` | 200 |
| sched_to.{json,headers} | `odpt:FlightSchedule?odpt:operator=odpt.Operator:JAL&odpt:destinationAirport=odpt.Airport:TKN&acl:consumerKey=<KEY>` | 200 |
