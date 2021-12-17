<?php
/*
  Plugin Name: WP Twitter Widget
  Plugin URI: https://ateliee.com/
  Description: twitter widget/Twitter API 1.1対応
  Version: 1.0.0
  Author: ateliee
  License: GPLv2
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if( !function_exists('array_key_last') ) {
    function array_key_last(array $array) {
        if( !empty($array) ) return key(array_slice($array, -1, 1, true));
    }
}
if (!function_exists('array_key_first')) {
    function array_key_first(array $arr) {
        foreach($arr as $key => $unused) return $key;
    }
}
class WPTwitterWidget
{
    const VERSION           = '1.0.0';
    const PLUGIN_ID         = 'wp_twitter';
    const CREDENTIAL_ACTION = self::PLUGIN_ID . '-nonce-action';
    const CREDENTIAL_NAME   = self::PLUGIN_ID . '-nonce-key';
    const PLUGIN_DB_PREFIX  = self::PLUGIN_ID . '_';
    const PLUGIN_DB_API_TOKEN = self::PLUGIN_ID . 'api_token';
    const PLUGIN_DB_API_TOKEN_ARR = self::PLUGIN_ID . 'api_token_arr';
    const PLUGIN_DB_CRON_INTERVAL = self::PLUGIN_ID . 'cron_interval';
    const PLUGIN_DB_TWITTER_USERMETA_KEY = self::PLUGIN_ID . 'twitter_usermeta_key';
    const PLUGIN_DB_TWITTER_LIST_COUNT = self::PLUGIN_ID . 'twitter_list_count';
    const PLUGIN_DB_DELETE_API_LOG_DAYS = self::PLUGIN_ID . 'delete_api_log_days';

    const CONFIG_MENU_SLUG  = self::PLUGIN_ID . '-config';
    const COMPLETE_CONFIG   = self::PLUGIN_ID . '-complete';

    const SCHEDULE_EVENT_NAME = self::PLUGIN_ID . '_cron_interval';
    const TIMELINE_SHORTCODE = self::PLUGIN_ID . '_timeline';

    /**
     * デバッグ有効かどうか
     */
    const DEBUG_ENABLE = true;

    /**
     * tweet検索付与query
     */
    const TWEEET_SERACH_ADD_QUERY = ' -filter:retweets';
    /**
     * 一度のリクエストツイート数(mx: 100)
     */
    const TWEET_REQUEST_COUNT = 50;

    const TWEET_PERMALINK = 'https://twitter.com/%s/status/%s';
    /**
     * 検索クエリの最大長(バイト文字数)
     */
    const TWEEET_SERACH_MAX_QUERY_SIZE = 500;

    /**
     * ログの保持期間
     */
    const DEFAULT_DELETE_API_LOG_DAYS = 7;

    /**
     * twitter user nameの長さチェック(不正twitter名はapiがエラーとなるため)
     */
    const TWEETER_USER_NAME_MIN = 4;
    const TWEETER_USER_NAME_MAX = 15;
    const TWEETER_USER_NAME_LENGTH_CHECK = true;

    /**
     * 1グループでの最大取得ツイート数(次のページを読み込むかどうかのチェック/0なら無制限)
     */
    const TWEETER_REQUEST_GROUP_MAX_COUNT = 100;

    /**
     * 削除するツイート有効期限(秒)
     */
    const TWEETER_DELETE_LIMIT_TIME = 3600*24*7;

    /**
     * 一度に削除する最大数(deleteは重くなるので)
     */
    const TWEETER_DELETE_MAX_COUNT = 1000;

    /**
     * デフォルトのcronでの実行間隔(秒)
     */
    const CRON_DEFAULT_REQUEST_INTERVAL_TIME = 60 * 5;
    /**
     * ログローテート期間(日)
     */
    const LOG_LOTATE_DAYS = 7;

    /**
     * デフォルトtwitterユーザー名のキー
     */
    const DEFAULT_TWITTER_USERNAME_KEY = 'twitter_username';

    /**
     * 表示件数
     */
    const DEFAULT_TWITTER_LIST_COUNT = 10;

    /**
     * @var string[] 追加クエリ
     */
    static $add_query_vars = [
        'next_id',
    ];

    /**
     * @var WPTwitterWidget|null
     */
    protected static $instance = null;

    /**
     * @return string
     */
    protected function getTwitterUsernameKey(){
        return get_option(self::PLUGIN_DB_TWITTER_USERMETA_KEY, self::DEFAULT_TWITTER_USERNAME_KEY);
    }

    /**
     * @return int
     */
    protected function getTwitterListCount(){
        return (int)get_option(self::PLUGIN_DB_TWITTER_LIST_COUNT, self::DEFAULT_TWITTER_LIST_COUNT);
    }

    /**
     * @return int
     */
    protected function getDeleteApiLogDays(){
        return (int)get_option(self::PLUGIN_DB_DELETE_API_LOG_DAYS, self::DEFAULT_DELETE_API_LOG_DAYS);
    }

    /**
     * @return WPTwitterWidget|null
     */
    public static function getInstance(){
        if(!self::$instance){
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @return WPTwitterWidget
     */
    static function init()
    {
        return self::getInstance();
    }

    /**
     * WPTwitterWidget constructor.
     */
    function __construct()
    {
        if (is_admin() && is_user_logged_in()) {
            // メニュー追加
            add_action('admin_menu', [$this, 'setPluginMenu']);
            add_action('admin_init', [$this, 'saveConfig']);
            add_action('profile_update', [$this, 'profileUpdate'], 10, 2 );
        }
        add_action(self::SCHEDULE_EVENT_NAME, [$this, 'cron']);
        if (!wp_next_scheduled( self::SCHEDULE_EVENT_NAME)) {
            $interval = (int)get_option(self::PLUGIN_DB_CRON_INTERVAL, self::CRON_DEFAULT_REQUEST_INTERVAL_TIME);
            if($interval > 0){
                wp_schedule_single_event(strtotime(date('Y-m-d H:i:00', time() + $interval)), self::SCHEDULE_EVENT_NAME);
            }
        }
        add_shortcode(self::TIMELINE_SHORTCODE, [$this, 'timelineShortcode']);

        add_action('wp_enqueue_scripts', function () {
            wp_enqueue_script( 'twitter-widgets', 'https://platform.twitter.com/widgets.js', array(), self::VERSION, true );
            wp_enqueue_script(
                self::PLUGIN_ID.'-widgets',
                plugin_dir_url(__FILE__).'js/widgets.js',
                ['jquery', 'twitter-widgets'],
                self::VERSION,
                true
            );
            wp_enqueue_style(
                self::PLUGIN_ID.'-widgets',
                plugin_dir_url(__FILE__).'css/widgets.css',
                "",
                self::VERSION
            );
        });

        add_filter('user_contactmethods', function ($profile) {
            $key = $this->getTwitterUsernameKey();
            if(!isset($profile[$key])){
                $profile[$key] = 'Twitterユーザー名';
            }
            return $profile;
        }, 10, 1);
        add_action('delete_user', function ($user_id){
            delete_user_meta($user_id, $this->getTwitterUsernameKey());
        });


        $add_query_vars = self::$add_query_vars;
        add_filter('query_vars', function ($vars) use ($add_query_vars){
            foreach($add_query_vars as $key){
                $vars[] = $key;
            }
            return $vars;
        });
    }

    /**
     * @param $id
     * @return mixed|string
     */
    protected function parseTwitterId($id)
    {
        if(!is_string($id)){
            return $id;
        }
        return ltrim(trim($id), '@');
    }

    /**
     * @param $user_id
     * @param $old_user_data
     */
    public function profileUpdate( $user_id, $old_user_data ) {
        global $wpdb;
        if($user_id < 0){
            return false;
        }
        $user = get_userdata($user_id);
        if(!$user){
            return false;
        }
        $twitter_id = $this->validTwitterUsernameByUser($user);
        if(!$twitter_id){
            return false;
        }
        $time = current_datetime()->format('Y-m-d H:i:s');
        $wpdb->query("START TRANSACTION");
        try{

            $user_ids = [$twitter_id];
            $sql = $wpdb->prepare('DELETE FROM `'.self::pluginTableName('tweet').'` WHERE `user_id` = %d ', $user->ID);
            $wpdb->query($sql);

            $query = $this->getTweetQuery($user_ids);
            $this->requestInsertTweet($query, $user_ids, $time);

            $wpdb->query("COMMIT");
        }catch (Exception $e){
            $wpdb->query("ROLLBACK");
            $this->log($e->getMessage());
            return false;
        }
        return true;
    }

    /**
     * タイムラインの表示
     *
     * @param array|null $attr
     * @param string|null $content
     * @param string|null $tag
     * @return string
     */
    public function timelineShortcode($attr, $content, $tag)
    {
        $attr = shortcode_atts([
            'next_id' => get_query_var('next_id', null),
        ], $attr ?? [], self::TIMELINE_SHORTCODE);

        ob_start();
        $this->renderTimeline($attr);
        $buffer = ob_get_contents();
        ob_end_clean();
        return $buffer;
    }

    /**
     * tweetのパーマリンクを取得
     *
     * @param string $username
     * @param string $id
     * @return string
     */
    protected function getTweetPermaLink(string $username, string $id)
    {
        return sprintf(self::TWEET_PERMALINK, $username, $id);
    }

    /**
     * @param array $attr
     * @return array
     */
    protected function mergeQueryAttr(array $attr)
    {
        return $attr;
    }

    /**
     * @param array $attr
     * @return string
     */
    protected function make_permalink(array $attr)
    {
        $more_link = get_permalink();
        return $more_link;
    }

    /**
     * @param array $attr
     */
    public function renderTimeline(array $attr)
    {
        $attr = $this->mergeQueryAttr($attr);
        $limit = $this->getTwitterListCount();
        $cnt = $this->getTweetCount($attr);
        $rows = $this->getTweetList($attr, $limit);

        $more_link = null;
        if($cnt > $limit){
            $more_link = $this->make_permalink($attr);
            $more_link = add_query_arg('next_id', ($rows[array_key_last($rows)]->tweet_id ?? null), $more_link);
            $more_link = add_query_arg('ajax', 1, $more_link);
        }
?>
        <div id="wp-twitter-timeline" data-ajax="<?= esc_attr(plugin_dir_url(__FILE__).'ajax.php'); ?>">
            <?php if(count($rows) > 0): ?>
                <?php foreach($rows as $row): ?>
                    <div class="wp-twitter-tweet" data-tweet-id="<?= esc_attr($row->tweet_id); ?>" data-tweet-user="<?= esc_attr($row->tweet_username); ?>" data-user="<?= esc_attr($row->user_id); ?>">
                        <?php if(self::DEBUG_ENABLE && defined('WP_DEBUG') && WP_DEBUG): ?>
                            <div style="font-size: 0.8rem; padding: 6px; background: #fff; color: #999; word-break: break-all;">
                                デバッグ情報:
                                ユーザーID: <?= $row->user_id; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-list">Not Found Tweet.</div>
            <?php endif; ?>
        </div>
        <?php if($more_link): ?>
        <div id="wp-twitter-timeline-more">
            <a href="<?= $more_link; ?>">More</a>
        </div>
        <?php endif; ?>
<?php
    }

    /**
     * @param array $attr
     * @param int $limit
     * @return mixed
     */
    protected function getTweetList(array $attr, int $limit)
    {
        global $wpdb;

        $where = $this->getTeeetSqlWhere($attr);
        $sql = 'SELECT * FROM '.self::pluginTableName('tweet');
        if($where){
            $sql .= ' WHERE '.$where;
        }
        $sql .= ' ORDER BY `tweet_date` DESC ';
        $sql .= ' LIMIT '.$limit.' ';

        return $wpdb->get_results($sql);
    }

    /**
     * @param array $attr
     * @param int $limit
     * @return int
     */
    protected function getTweetCount(array $attr)
    {
        global $wpdb;

        $where = $this->getTeeetSqlWhere($attr);
        $sql = 'SELECT COUNT(*) AS `cnt` FROM '.self::pluginTableName('tweet');
        if($where){
            $sql .= ' WHERE '.$where;
        }
        $row = $wpdb->get_row($sql);
        if($row && $row->cnt){
            return (int)$row->cnt;
        }
        return 0;
    }

    /**
     * @param array $arr
     * @return string
     */
    protected function getTeeetSqlWhere(array $arr)
    {
        global $wpdb;

        $table = self::pluginTableName('tweet');
        $wheres = [];
        if(!empty($arr['next_id'])){
            $wheres[] = $wpdb->prepare('tweet_id < %s', $arr['next_id']);
        }
        return implode(' AND ', $wheres);
    }

    /**
     * cronでの実行
     * @throws Exception
     */
    public function cron()
    {
	    $this->log('run cron.');
        $this->loadTweet();
    }

    /**
     * @param string $name
     * @return string
     */
    protected static function pluginTableName(string $name)
    {
        global $wpdb;
        return $wpdb->prefix.self::PLUGIN_DB_PREFIX.$name;
    }

    /**
     * @return string
     */
    public static function logDir(){
        return __DIR__.'/logs';
    }

    /**
     * ログのローテート
     */
    protected function lotateLogs()
    {
        $dir = self::logDir();
        if(!is_dir($dir)){
            return;
        }
        $limit = current_datetime()->getTimestamp() - (3600*24*self::LOG_LOTATE_DAYS);
        if ($dh = opendir($dir)) {
            while (($file = readdir($dh)) !== false) {
                $is_delete = false;
                if(preg_match('/^'.preg_quote(self::PLUGIN_ID, '/').'\-(.+)\.log$/',$file, $matchs)){
                    $date = strtotime($matchs[1]);
                    if($date){
                        $is_delete = ($date < $limit);
                    }
                }
                if($is_delete){
                    unlink($dir.'/'.$file);
                }
            }
            closedir($dh);
        }
    }

    /**
     * ログの出力
     *
     * @param string $message
     */
    protected function log($message) {
        $this->lotateLogs();

        $dir = self::logDir();
        $log_message = sprintf("%s:%s\n", date_i18n('Y-m-d H:i:s'), $message);
        if(!is_dir($dir)){
            mkdir($dir, 0755, TRUE);
        }
        $filename = self::PLUGIN_ID.'-'.date('Y-m-d', current_datetime()->getTimestamp()).'.log';
        error_log($log_message, 3, $dir . '/'.$filename);
    }

    /**
     *
     */
    public static function pluginActivate()
    {
        global $wpdb;
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        $charset_collate = $wpdb->get_charset_collate();

        $table_name = self::pluginTableName('tweet');
        $sql = "CREATE TABLE ".$table_name." (
        `tweet_date` DATETIME NOT NULL,
        `tweet_user_id` varchar(100) NOT NULL,
        `tweet_username` varchar(100) NOT NULL,
        `tweet_id` varchar(100) NOT NULL UNIQUE,
        `user_id` BIGINT UNSIGNED NOT NULL,
        `created_at` DATETIME NOT NULL,
        INDEX `user_id_tweet_date_index` (`user_id`, `tweet_date`),
        INDEX tweet_date_index (tweet_date)
    ) ".$charset_collate.";";
        dbDelta($sql);

        $table_name = self::pluginTableName('api_log');
        $sql = "CREATE TABLE ".$table_name." (
        `id` BIGINT NOT NULL AUTO_INCREMENT,
        `request_date` DATETIME NOT NULL,
        `user_count` INT UNSIGNED DEFAULT 0 NOT NULL,
        `request_count` INT UNSIGNED DEFAULT 0 NOT NULL,
        `tweet_count` INT UNSIGNED DEFAULT 0 NOT NULL,
        `tweet_delete_count` INT UNSIGNED DEFAULT 0 NOT NULL,
        `result_code` INT UNSIGNED DEFAULT 0 NOT NULL,
        PRIMARY KEY (id),
        INDEX `request_date_index` (`request_date`)
    ) ".$charset_collate.";";
        dbDelta($sql);

        add_option( 'data_db_version', self::VERSION );
    }

    /**
     *
     */
    function setPluginMenu()
    {
        add_menu_page(
            'Twitter設定',
            'Twitter設定',
            'manage_options',
            self::PLUGIN_ID,
            [$this, 'showConfigForm'],
            'dashicons-twitter', /* アイコン see: https://developer.wordpress.org/resource/dashicons/#awards */
            99
        );
    }

    /**
     * 設定画面の表示
     */
    function showConfigForm() {
        global $wpdb;

        $rows_limit = 100;
        $sql = "SELECT `user_id`, `tweet_user_id`, `tweet_username`, COUNT(`tweet_id`) AS `cnt` FROM ".self::pluginTableName('tweet')." GROUP BY `user_id`, `tweet_user_id` LIMIT ".$rows_limit." ";
        $rows = $wpdb->get_results($sql);
        $tweet_sum = array_reduce($rows, function ($sum, $item){
            $sum += $item->cnt;
            return $sum;
        });

        $sql = "SELECT * FROM ".self::pluginTableName('api_log')." ORDER BY `request_date` DESC LIMIT 30 ";
        $api_logs = $wpdb->get_results($sql);

        $api_token_arr = get_option(self::PLUGIN_DB_API_TOKEN_ARR, []);
        ?>
            <script type="text/javascript">
                jQuery(function(){
                    jQuery('#token_list_add').on('click', function(){
                        var $input = jQuery('<div style="margin: 6px 0;">').append(jQuery('<input type="text" name="api_token_arr[]" value="" class="large-text">'));
                        jQuery('#token_list').append($input);
                        return false;
                    });
                });
            </script>
        <div class="wrap">
            <h1>Twitter設定</h1>

            <div>
                <?php if($notice = get_transient(self::COMPLETE_CONFIG)): ?>
                    <div class="notice notice-success">
                        <p><?= esc_html($notice) ?></p>
                    </div>
                    <?php delete_transient(self::COMPLETE_CONFIG); endif; ?>
                <form action="" method='post'>
                    <input type="hidden" name="action" value="save">
                    <?php wp_nonce_field(self::CREDENTIAL_ACTION, self::CREDENTIAL_NAME) ?>
                    <table class="form-table">
                        <tbody>
                        <tr>
                            <th>
                                <label for="title">APIトークン</label>
                            </th>
                            <td>
                                <input type="text" name="api_token" value="<?= esc_attr(get_option(self::PLUGIN_DB_API_TOKEN)) ?>" placeholder="Bearer Tokenを入力" class="large-text" />
                                <div style="margin-top: 10px">
                                    <a href="https://developer.twitter.com/en/portal/projects-and-apps" target="_blank">Twitter Developer Portal</a>より
                                    アプリを追加し、「Bearer Token」を登録してください。
                                </div>
                                <div style="margin-top: 20px;">
                                    <div id="token_list">
                                        <?php foreach($api_token_arr as $token): ?>
                                            <div style="margin: 6px 0;">
                                                <input type="text" name="api_token_arr[]" value="<?= esc_attr($token) ?>" class="large-text">
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div style="display: flex; justify-content: start; align-items: center;">
                                        <div><a href="#" class="button button-secondary button-large" id="token_list_add">トークンを追加</a></div>
                                        <div style="padding: 0 12px;">※ 複数のトークンがある場合、有効なものが順次利用されます。</div>
                                    </div>
                                </div>
                                <div class="help" style="padding-top: 12px;">
                                    ※ Search APIは無料枠の場合、450/15分の使用制限があります。<br>
                                    ※ Standardアプリのtweet取得は無料枠の場合、500,000ツイート/月のプロジェクト制限があります。<br>
                                    詳しくは<a href="https://developer.twitter.com/en/docs/twitter-api/v1/rate-limits" target="_blank">Doc</a>を確認してみてください。
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th>
                                <label for="title">定期実行期間</label>
                            </th>
                            <td>
                                <input type="number" name="cron_interval" value="<?= esc_attr(get_option(self::PLUGIN_DB_CRON_INTERVAL, self::CRON_DEFAULT_REQUEST_INTERVAL_TIME)) ?>" class="regular-text" />
                                秒
                                <div style="margin-top: 10px">
                                    0以下の場合は定期実行は止まります。
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th>
                                <label for="title">twitterユーザー名キー</label>
                            </th>
                            <td>
                                <input type="text" name="twitter_usermeta_key" value="<?= esc_attr($this->getTwitterUsernameKey()) ?>" class="regular-text" />
                                <div style="margin-top: 10px">
                                    user_metaのtwitterID(@以降)の設定されているキー(基本変更する必要はありません)
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th>
                                <label for="title">表示件数</label>
                            </th>
                            <td>
                                <input type="number" name="twitter_list_count" value="<?= esc_attr($this->getTwitterListCount()) ?>" class="regular-text" />
                            </td>
                        </tr>
                        <tr>
                            <th>
                                <label for="title">ログ保持期間</label>
                            </th>
                            <td>
                                <input type="number" name="delete_api_log_days" value="<?= esc_attr($this->getDeleteApiLogDays()) ?>" class="regular-text" />
                                日間
                            </td>
                        </tr>
                        </tbody>
                    </table>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 20px;">
                        <div>
                            <button type="submit" name="method" value="save" class="button button-primary button-large">設定を保存</button>
                        </div>
                        <?php if(count($rows) > 0): ?>
                        <div style="text-align: right;">
                            <button type="submit" name="method" value="trash_tweet" class="button button-secondary button-large">tweetリストの削除</button>
                        </div>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <div id="col-container" class="wp-clearfix">
                <div id="col-left">
                    <div class="col-wap" style="padding-right: 20px;">
                        <h2 class="screen-reader-text">APIログ</h2>
                        <?php if(count($api_logs) > 0): ?>
                        <table class="wp-list-table widefat fixed striped table-view-list tags">
                            <thead>
                            <tr>
                                <th scope="col" class="manage-column column-name column-primary">日付</th>
                                <th scope="col" class="manage-column column-name column-primary">ユーザー数</th>
                                <th scope="col" class="manage-column column-name column-primary">リクエスト回数</th>
                                <th scope="col" class="manage-column column-name column-primary">Tweet件数</th>
                                <th scope="col" class="manage-column column-name column-primary">結果コード</th>
                            </tr>
                            </thead>

                            <tbody id="the-list" data-wp-lists="list:tag">
                            <?php foreach($api_logs as $row): ?>
                                <tr id="tag-1" class="level-0">
                                    <th scope="row"><?= esc_html($row->request_date); ?></th>
                                    <th scope="row" style="text-align: right;"><?= esc_html($row->user_count); ?></th>
                                    <th scope="row" style="text-align: right;"><?= esc_html($row->request_count); ?></th>
                                    <th scope="row" style="text-align: right;"><?= esc_html($row->tweet_count); ?></th>
                                    <th scope="row" style="text-align: right;">
                                        <?= ($row->result_code ? '<span style="color: red;"><span class="dashicons dashicons-info"></span></span>' : ''); ?>
                                        <?= esc_html($row->result_code); ?></th>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>

                            <tfoot>
                            <tr>
                                <th scope="col" class="manage-column column-name column-primary">日付</th>
                                <th scope="col" class="manage-column column-name column-primary">ユーザー数</th>
                                <th scope="col" class="manage-column column-name column-primary">リクエスト回数</th>
                                <th scope="col" class="manage-column column-name column-primary">Tweet件数</th>
                                <th scope="col" class="manage-column column-name column-primary">結果コード</th>
                            </tfoot>

                        </table>
                        <?php endif; ?>
                        <form action="" method='post'>
                            <input type="hidden" name="action" value="api_call">
                            <?php wp_nonce_field(self::CREDENTIAL_ACTION, self::CREDENTIAL_NAME) ?>
                            <p>
                                <button type="submit" name="method" value="api_call" class="button button-primary button-large">tweetリストの更新</button>
                            </p>
                        </form>
                    </div>
                </div>
                <div id="col-right">
                    <?php if(count($rows) > 0): ?>
                    <div class="col-wap">
                        <h2 class="screen-reader-text">tweetリスト</h2>
                        <table class="wp-list-table widefat fixed striped table-view-list tags">
                            <thead>
                            <tr>
                                <th scope="col" id="name" class="manage-column column-name column-primary">TwitterユーザーID</th>
                                <th scope="col" id="name" class="manage-column column-name column-primary">Twitterユーザー名</th>
                                <th scope="col" id="name" class="manage-column column-name column-primary">ユーザーID</th>
                                <th scope="col" id="name" class="manage-column column-name column-primary" style="text-align: right;">Tweet件数(<?= $tweet_sum; ?>)</th>
                            </tr>
                            </thead>

                            <tbody id="the-list" data-wp-lists="list:tag">
                            <?php foreach($rows as $row): ?>
                                <tr id="tag-1" class="level-0">
                                    <th scope="row"><?= esc_html($row->tweet_user_id); ?></th>
                                    <th scope="row"><a href="https://twitter.com/<?= esc_attr($row->tweet_username); ?>" target="_blank"><?= esc_html($row->tweet_username); ?></a></th>
                                    <th scope="row"><a href="<?= esc_attr(admin_url('user-edit.php?user_id='.$row->user_id)); ?>"><?= esc_html($row->user_id); ?></a></th>
                                    <th scope="row" style="text-align: right;"><?= esc_html($row->cnt); ?></th>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>

                            <tfoot>
                            <tr>
                                <td scope="col" id="name" class="manage-column column-name column-primary">TwitterユーザーID</td>
                                <th scope="col" id="name" class="manage-column column-name column-primary">Twitterユーザー名</th>
                                <td scope="col" id="name" class="manage-column column-name column-primary">ユーザーID</td>
                                <td scope="col" id="name" class="manage-column column-name column-primary" style="text-align: right;">Tweet件数(<?= $tweet_sum; ?>)</td>
                            </tfoot>

                        </table>
                        <div class="help" style="padding-top: 12px;">
                            ※ <?= $rows_limit; ?>件のみ表示されます
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * @throws Exception
     */
    function saveConfig()
    {
        // nonceで設定したcredentialのチェック
        if (isset($_POST[self::CREDENTIAL_NAME]) && $_POST[self::CREDENTIAL_NAME] && check_admin_referer(self::CREDENTIAL_ACTION, self::CREDENTIAL_NAME)) {
            $action = $_POST['action'];
            $method = $_POST['method'];
            if ($action === 'save') {
                if($method === 'save') {
                    // 保存処理
                    update_option(self::PLUGIN_DB_API_TOKEN, trim($_POST['api_token']) ?? "");
                    update_option(self::PLUGIN_DB_API_TOKEN_ARR, array_filter(array_map('trim', $_POST['api_token_arr']), 'strlen') ?? []);
                    update_option(self::PLUGIN_DB_CRON_INTERVAL, (int)trim($_POST['cron_interval']) ?? self::CRON_DEFAULT_REQUEST_INTERVAL_TIME);
                    update_option(self::PLUGIN_DB_TWITTER_USERMETA_KEY, trim($_POST['twitter_usermeta_key']) ?? self::DEFAULT_TWITTER_USERNAME_KEY);
                    update_option(self::PLUGIN_DB_TWITTER_LIST_COUNT, trim($_POST['twitter_list_count']) ?? self::DEFAULT_TWITTER_LIST_COUNT);
                    update_option(self::PLUGIN_DB_DELETE_API_LOG_DAYS, trim($_POST['delete_api_log_days']) ?? self::DEFAULT_DELETE_API_LOG_DAYS);

                    $completed_text = "設定の保存が完了しました。";
                    set_transient(self::COMPLETE_CONFIG, $completed_text, 5);
                }else if($method === 'trash_tweet'){
                    if($this->truncateTweetTable()) {
                        $completed_text = "twitterリストを削除しました。";
                    }else{
                        $completed_text = "twitterリスト削除でエラーが発生しました。";
                    }
                    set_transient(self::COMPLETE_CONFIG, $completed_text, 5);
                }
                wp_safe_redirect(menu_page_url(self::CONFIG_MENU_SLUG), false);
            }else if ($action === 'api_call') {
                if($method === 'api_call'){
                    if($this->loadTweet()) {
                        $completed_text = "twitterリストを更新しました。";
                    }else{
                        $completed_text = "twitterリスト更新でエラーが発生しました。";
                    }
                    set_transient(self::COMPLETE_CONFIG, $completed_text, 5);
                }
                wp_safe_redirect(menu_page_url(self::CONFIG_MENU_SLUG), false);
            }
        }
    }

    /**
     * APIログの削除
     */
    protected function trashApiLog()
    {
        global $wpdb;
        $sql = $wpdb->prepare(
            'DELETE FROM `'.self::pluginTableName('api_log').'` WHERE `request_date` <= %s ORDER BY `request_date` ASC LIMIT 100 ',
            date('Y-m-d H:i:s', current_datetime()->getTimestamp() - (24*60*$this->getDeleteApiLogDays()))
        );
        $wpdb->query($sql);
    }

    /**
     * tweetリストの完全削除
     *
     * @return bool
     */
    protected function truncateTweetTable(){
        global $wpdb;
        return $wpdb->query('TRUNCATE TABLE `'.self::pluginTableName('tweet').'`');
    }

    /**
     * tweetリストの期間切れ削除
     *
     * @return int 削除件数
     * @throws Exception
     */
    protected function deleteLimitedTweet(){
        global $wpdb;

        $limit = current_datetime()->sub(new DateInterval(sprintf('PT%dS', self::TWEETER_DELETE_LIMIT_TIME)))->format('Y-m-d H:i:s');
        $sql = $wpdb->prepare('DELETE FROM `'.self::pluginTableName('tweet').'` WHERE `tweet_date` < %s ORDER BY `tweet_date` ASC LIMIT %d', $limit, self::TWEETER_DELETE_MAX_COUNT);
        $count = $wpdb->query($sql);

        // 全ユーザーIDを取得
        $user_ids = get_users([
            'orderby' => 'ID',
            'fields' => [
                'ID',
            ],
        ]);
        // 削除されたユーザーも対象にする
        if(count($user_ids) > 0){
            $user_ids = array_map(function($it){
                return $it->ID;
            }, $user_ids);
            $sql = $wpdb->prepare('DELETE FROM `'.self::pluginTableName('tweet').'` WHERE `user_id` NOT IN ('.implode(',', $user_ids).') LIMIT %d ', self::TWEETER_DELETE_MAX_COUNT);
            $count += $wpdb->query($sql);
        }
        return $count;
    }

    /**
     * twitter APIをコールしDBに保存
     *
     * @return bool
     * @throws
     */
    protected function loadTweet(){
        global $wpdb;

        // ログの削除
        $this->trashApiLog();
        // 古いツイートの削除
        $delete_count = $this->deleteLimitedTweet();

        $time = current_datetime()->format('Y-m-d H:i:s');
        $request_count = 0;
        $tweet_count = 0;
        $user_count = 0;

        $success = $this->chunkTwitterUsers(function($user_ids) use ($wpdb, $time, &$request_count, &$tweet_count, &$user_count){
            $wpdb->query("START TRANSACTION");
            try{
                $query = $this->getTweetQuery($user_ids);
                list($t, $r) = $this->requestInsertTweet($query, $user_ids, $time);
                $tweet_count += $t;
                $request_count += $r;
                $user_count += count($user_ids);

                $wpdb->query("COMMIT");
            }catch (Exception $e){
                $wpdb->query("ROLLBACK");
                $this->log($e->getMessage());
                return false;
            }
            return true;
        });
        $wpdb->insert(self::pluginTableName('api_log'), [
            'request_date' => $time,
            'request_count' => $request_count,
            'tweet_count' => $tweet_count,
            'tweet_delete_count' => $delete_count,
            'user_count' => $user_count,
            'result_code' => $success ? 0 : 1,
        ], [
            '%s',
            '%d',
            '%d',
            '%d',
            '%d',
            '%d',
        ]);
        return $success;
    }

    /**
     * @param string $query
     * @param array $user_ids
     * @param string $created_at
     * @param array $params
     * @param int $current_tweet_count
     * @return array
     * @throws
     */
    protected function requestInsertTweet(string $query, array $user_ids, string $created_at, array $params = [], $current_tweet_count = 0)
    {
        global $wpdb;

        $request_count = 1;
        $tweet_count = 0;
        $res = $this->requestTweet($query, $params);
        $wordpress_twitter_ids = array_map('strtolower', $user_ids);
        $check_next_page = false;
        if(isset($res->statuses)){
            $fields_keys = [
                'tweet_date',
                'tweet_user_id',
                'tweet_username',
                'tweet_id',
                'user_id',
                'created_at',
            ];
            $values = [];
            foreach($res->statuses as $tweet){
                $tweet_date = (new DateTime($tweet->created_at))->setTimezone(new DateTimeZone(wp_timezone_string()));
                $values[$tweet->id] = $wpdb->prepare(
                    "(%s, %s, %s, %s, %d, %s)" ,
                    $tweet_date->format('Y-m-d H:i:s'),
                    $tweet->user->id,
                    $tweet->user->screen_name,
                    $tweet->id,
                    array_search(strtolower($tweet->user->screen_name), $wordpress_twitter_ids) ?? null,
                    $created_at
                );
                $tweet_count ++;
            }
            if(count($values) > 0){
                $delete_num = $wpdb->query('DELETE FROM `'.self::pluginTableName('tweet').'` WHERE `tweet_id` IN ('.implode(',', array_keys($values)).')');

                $sql = 'INSERT INTO `'.self::pluginTableName('tweet').'` ';
                $sql .= '('.implode(',', array_map(function($key){
                        return sprintf('`%s`', $key);
                    }, $fields_keys)).') VALUES ';
                $sql .= implode(', ', $values);
                $wpdb->query($sql);
                $check_next_page = ($delete_num === 0);
            }
        }
        if($check_next_page){
            $check_next_page = (self::TWEETER_REQUEST_GROUP_MAX_COUNT === 0) || (($tweet_count + $current_tweet_count) < self::TWEETER_REQUEST_GROUP_MAX_COUNT);
            if($check_next_page && isset($res->search_metadata) && !empty($res->search_metadata->next_results)){
                $query = $res->search_metadata->next_results;
                if(strpos($query, '?') === 0){
                    $query = substr($query, 1);
                }
                if(!empty($query)){
                    parse_str($query, $next_params);
                    list($t, $r) = $this->requestInsertTweet($query, $user_ids, $created_at, $next_params, $tweet_count);
                    $tweet_count += $t;
                    $request_count += $r;
                }
            }
        }
        return [$tweet_count, $request_count];
    }

    /**
     * @param array $user_ids
     * @return string
     */
    protected function getTweetQuery(array $user_ids)
    {
        return sprintf('(%s)'.self::TWEEET_SERACH_ADD_QUERY, implode(' OR ', array_map(function($id){
            return 'from:'.$id;
        }, $user_ids)));
    }

    /**
     * 有効なtwitter nameを取得
     *
     * @param string $name
     * @return string|null
     */
    protected function validTwitterUserName(string $name)
    {
        $twitter_id = $this->parseTwitterId($name);
        if(!$twitter_id){
            return null;
        }
        if(self::TWEETER_USER_NAME_LENGTH_CHECK){
            $size = strlen($twitter_id);
            if($size < self::TWEETER_USER_NAME_MIN || $size > self::TWEETER_USER_NAME_MAX){
                $this->log(sprintf('%s is invalid twitter username.', $twitter_id));
                return null;
            }
        }
        return $twitter_id;
    }

    /**
     *
     * @param object $user
     * @return string|null
     */
    protected function validTwitterUsernameByUser($user){
        $role = get_userdata($user->ID)->roles;
        if(in_array($role, ['administrator'])){
            return null;
        }
        return $this->validTwitterUserName(get_user_meta($user->ID, $this->getTwitterUsernameKey(), true));
    }

    /**
     * @param callable $callback
     * @param int $count
     * @return bool 全て成功
     */
    protected function chunkTwitterUsers(callable $callback)
    {
        $n = 0;
        $count = 100;
        $twitter_usernames = [];
        $success = true;
        while(true){
            $users = get_users([
                'orderby' => 'ID',
                'offset' => $n * $count,
                'number'  => $count,
            ]);
            $n ++;
            foreach($users as $user){
                $twitter_id = $this->validTwitterUsernameByUser($user);
                if(!$twitter_id){
                    continue;
                }
                $twitter_usernames[$user->ID] = $twitter_id;
                $size = strlen(urlencode($this->getTweetQuery($twitter_usernames)));
                if($size >= self::TWEEET_SERACH_MAX_QUERY_SIZE){
                    if($callback($twitter_usernames) === false){
                        $success = false;
                        break 2;
                    }
                    $twitter_usernames = [];
                }
            }
            if(count($users) < $count){
                break;
            }
        }
        if($success && (count($twitter_usernames) > 0)){
            if($callback($twitter_usernames) === false){
                $success = false;
            }
        }
        return $success;
    }

    /**
     * @var array|null
     */
    protected $enable_api_tokens;

    /**
     * APIトークンの取得
     *
     * @return string|null
     */
    protected function getApiToken()
    {
        if(!$this->enable_api_tokens){
            $this->enable_api_tokens = [];
            $this->enable_api_tokens[] = get_option(self::PLUGIN_DB_API_TOKEN);
            $arr = get_option(self::PLUGIN_DB_API_TOKEN_ARR, []);
            if(count($arr) > 0){
                $this->enable_api_tokens = array_merge_recursive($this->enable_api_tokens, $arr);
            }
            $this->enable_api_tokens = array_filter($this->enable_api_tokens, 'strlen');
        }
        if(count($this->enable_api_tokens) > 0){
            return $this->enable_api_tokens[array_key_first($this->enable_api_tokens)];
        }
        return null;
    }

    /**
     * トークンを無効化
     *
     * @param string $token
     */
    protected function disableApiToken($token)
    {
        if(!$this->enable_api_tokens){
            return;
        }
        $key = array_search($token, $this->enable_api_tokens);
        if($key === false){
            return;
        }
        unset($this->enable_api_tokens[$key]);
        $this->enable_api_tokens = array_values($this->enable_api_tokens);
    }

    /**
     * @param string $text
     * @param array $params
     * @return array|void
     * @throws Exception
     */
    protected function requestTweet(string $text, array $params = []){
        require_once( __DIR__ . '/includes/Twitter.php' );

        $api_token = $this->getApiToken();
        if(!$api_token){
            throw new TwitterException('not found api token.');
        }

        $twitter = Twitter::instance($api_token);
        try{
            return $twitter->search($text, array_merge([
                'count' => self::TWEET_REQUEST_COUNT,
            ], $params));
        }catch (Exception $e){
            if($e instanceof TwitterRateLimitException){
                $this->disableApiToken($api_token);
                return $this->requestTweet($text, $params);
            }else{
                throw $e;
            }
        }
    }
}
add_action('init', [WPTwitterWidget::class, 'init']);
register_activation_hook(__FILE__, [WPTwitterWidget::class, 'pluginActivate']);
