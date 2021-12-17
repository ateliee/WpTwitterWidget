jQuery(function(){
    loadingWpTweet();
    renderWpTweet();
    jQuery(document).on('click', '#wp-twitter-timeline-more a', function(){
        var $this = jQuery(this);
        requestWpNextTweet($this.attr('href'));
        return false;
    });
});
jQuery.fn.notify = function(data) {
    var $notify = jQuery('<div class="wp-twitter-notify"><div class="wp-twitter-notify-box"></div></div>');
    $notify.find('.wp-twitter-notify-box').text(data['message']);
    $notify.delay(1000).queue(function (){
        jQuery(this).addClass('animated').one('transitionend webkitTransitionEnd', function() {
            jQuery(this).remove();
        });
    })
    this.append($notify);
};

/**
 * tweet render
 */
function renderWpTweet()
{
    var $timeline = jQuery('#wp-twitter-timeline');
    $timeline.each(function() {
        var $target = jQuery('.wp-twitter-tweet').not('.loaded');
        if($target.length <= 0){
            loadingFinishWpTweet(false);
        }
        var promises = [];
        $target.each(function(){
            var $this = jQuery(this);
            var tweet_id = $this.data('tweet-id');
            $this.addClass('loaded');
            var p = twttr.widgets.createTweet(
                tweet_id,
                this,
                {
                    theme: 'light',
                    align: 'center',
                }
            );
            promises.push(p);
        });
        if(promises.length > 0){
            Promise.all(promises).then(function(){
                // console.debug('loaded');
                loadingFinishWpTweet(true);
            });
        }else{
            loadingFinishWpTweet(true);
        }
    });
}
function loadingWpTweet()
{
    var $more = jQuery('#wp-twitter-timeline-more');
    $more.hide();
    jQuery('#wp-twitter-timeline').append('<div class="loader"><i class="fa fa-circle-o-notch fa-spin fa-2x fa-fw"></i></div>');
}
function loadingFinishWpTweet(more_enable)
{
    var $more = jQuery('#wp-twitter-timeline-more');
    var more_link = $more.find('a').attr('href');
    if(more_enable && more_link && (more_link !== '#')){
        $more.show();
    }
    jQuery('#wp-twitter-timeline').find('.loader').remove();
}
function requestWpNextTweet(url)
{
    if(!url){
        return;
    }
    loadingWpTweet();
    jQuery.ajax(url, {
        timeout : 30000,
        datatype: 'html'
    }).success(function (data){
        var out_html = jQuery(data);
        var target = out_html.find('#wp-twitter-timeline').first();
        if(target){
            jQuery('#wp-twitter-timeline-more a').attr('href', out_html.find('#wp-twitter-timeline-more a').attr('href'));
            jQuery('#wp-twitter-timeline').append(target.html());
            renderWpTweet();
        }
    }).error(function(XMLHttpRequest, textStatus, errorThrown) {
        // console.debug("XMLHttpRequest : " + XMLHttpRequest.status);
        // console.debug("textStatus     : " + textStatus);
        // console.debug("errorThrown    : " + errorThrown.message);
        loadingFinishWpTweet(false);
        jQuery('#wp-twitter-timeline').append('<div class="error">Error</div>');
    });
}
