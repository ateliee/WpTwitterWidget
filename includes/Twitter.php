<?php declare(strict_types=1);

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
class Twitter
{
    const API_V2_USE = false;
    const API_V2_BASE = 'https://api.twitter.com/2';
    const API_BASE = 'https://api.twitter.com/1.1';
    const TIMEOUT = 10;
    const METHOD_GET = 'GET';
    const METHOD_POST = 'POST';
    const DEFAULT_COUNT = 100;

    const TWITTER_ERROR_RATE_LIMIT = 88;
    const TWITTER_ERROR_INVALID_TOKEN = 89;

    protected static $instances = [];
    /**
     * @return Twitter
     */
    public static function instance(string $bearer_token){
        if(!isset(self::$instances[$bearer_token])){
            self::$instances[$bearer_token] = new self($bearer_token);
        }
        return self::$instances[$bearer_token];
    }

    /**
     * @var string
     */
    protected $bearer_token;

    /**
     * Twitter constructor.
     * @param string $bearer_token
     */
    public function __construct(string $bearer_token)
    {
        $this->bearer_token = $bearer_token;
    }

    /**
     * @param string $user_id
     * @param array $params
     * @return array
     */
    public function search(string $query, array $params = [])
    {
        if(self::API_V2_USE){
            return self::getApi('/tweets/search/recent', array_merge([
                'query' => $query,
                'max_results' => self::DEFAULT_COUNT,
                'expansions' => implode(',', ['author_id', 'referenced_tweets.id', 'referenced_tweets.id.author_id', 'attachments.media_keys']),
                'media.fields' => implode(',', ['duration_ms', 'height', 'media_key', 'preview_image_url', 'type', 'url', 'width', 'public_metrics',]),
                'tweet.fields' => implode(',', ['attachments', 'author_id', 'context_annotations', 'conversation_id', 'created_at', 'entities', 'geo', 'id', 'in_reply_to_user_id', 'lang', 'possibly_sensitive', 'referenced_tweets', 'reply_settings', 'source', 'text']),
                'user.fields' => implode(',', ['created_at', 'description', 'entities', 'id', 'location', 'name', 'pinned_tweet_id', 'profile_image_url', 'protected', 'public_metrics', 'url', 'username', 'verified', 'withheld']),
            ], $params));
        }
        return self::getApi('/search/tweets.json', array_merge([
            'q' => $query,
            'count' => self::DEFAULT_COUNT,
        ], $params));
    }

    /**
     * @param string $path
     * @param array $params
     * @return array
     */
    protected function getApi(string $path, array $params){
        return self::request(self::METHOD_GET, self::getEndpoint($path), $params);
    }

    /**
     * @param string $path
     * @return string
     */
    protected static function getEndpoint(string $path){
        if(self::API_V2_USE){
            return self::API_V2_BASE.$path;
        }
        return self::API_BASE.$path;
    }

    /**
     * @param string $method
     * @param string $url
     * @param array $param
     * @return mixed
     * @throws TwitterException
     */
    protected function request(string $method, string $url, array $param){

        if($ch = curl_init()){
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json' ,
                'Authorization: Bearer '.$this->bearer_token,
            ]);
            if($method === self::METHOD_POST){
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $param);
            }else{
                $url .= '?'.http_build_query($param);
                curl_setopt($ch, CURLOPT_URL, $url);
            }
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if(!$res){
                throw new TwitterException(sprintf('error response. %s', $url));
            }
            $res = json_decode($res);
            if(!$res){
                throw new TwitterException(sprintf('error response json. %s', $url));
            }
            if(!$code || ($code < 200 || $code >= 400)){
                if(isset($res->errors) && count($res->errors) === 1){
                    $error = $res->errors[array_key_first($res->errors)];
                    if($error->code === self::TWITTER_ERROR_RATE_LIMIT) {
                        throw new TwitterRateLimitException(sprintf('error rate limit %s %s', $code, $url, var_export($res, true)));
                    }else if($error->code === self::TWITTER_ERROR_INVALID_TOKEN){
                        throw new TwitterRateLimitException(sprintf('invalid token %s %s', $code, $url, var_export($res, true)));
                    }
                }
                throw new TwitterException(sprintf('error http code(%s) %s %s', $code, $url, var_export($res, true)));
            }
            return $res;
        }
        throw new TwitterException('error curl_init()');
    }
}

/**
 * Class TwitterException
 */
class TwitterException extends Exception{
    public function __construct($message = "", $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

/**
 * Class TwitterRateLimitException
 */
class TwitterRateLimitException extends TwitterException{
}
