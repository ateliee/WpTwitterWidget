# WP Twitter Widget

WPユーザー情報にtwitterユーザー名を入力すると、該当ユーザーのtweetのみをタイムライン表示するWordpressプラグイン。

環境
----------
* PHP >= 7.0

インストール
-----------

ダウンロードしたプラグインをWordpressのwp-content/pluginsに入れてください。

プラグインを有効化後、「Twitter設定」より設定を行います。

設定項目
-------------

|項目名|説明|
|-----|---|
|APIトークン| [Twitter Developer Portal](https://developer.twitter.com/en/portal/projects-and-apps) より アプリを追加し、「Bearer Token」を登録。APIトークンは複数設定可能 |
|定期実行期間| Twitter APIをリクエストする期間。短い分最新データが取得できますが、リクエスト数が増えます。 |
|twitterユーザー名キー|get_user_meta()で取得するためのユーザー属性キー名。基本変更する必要はありません。|
|表示件数|1ページ表示時のツイート表示件数|
|ログ保持期間|デバッグ用ログデータの保存期間|

動作方法
-------------

![](docs/sample.jpg)

プラグインを有効にすると、ユーザー情報に新たに「Twitterユーザー名」という項目が自動追加されます。

Twitterユーザー名(@以降の名前)を入力することで定期的に該当TwitterアカウントのユーザーTweetが自動取得される作りになっています。

固定ページなどの本文に下記のショートコードを入力することで取得されたTweetが一覧表示されます。

```
[wp_twitter_timeline]
```

実行ログについて
-------------

ログはプラグインのlogsに保存されます。

Webからアクセスできないようにご注意ください。