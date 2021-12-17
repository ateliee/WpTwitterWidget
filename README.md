# WP Twitter Widget

WPユーザー情報にtwitterユーザー名を入力すると、該当ユーザーのtweetのみをタイムライン表示するWordpressプラグイン。

環境
----------
* PHP >= 7.0

インストール
-----------

ダウンロードしたプラグインをWordpressのwp-content/pluginsに入れてください。

プラグインを有効化後、「Twitter設定」より設定を行います。

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