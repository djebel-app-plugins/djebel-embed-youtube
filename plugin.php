<?php
/*
plugin_name: Djebel Embed YouTube
plugin_uri: https://djebel.com/plugins/djebel-embed-youtube
description: Converts standalone YouTube links in static Markdown content into privacy-enhanced responsive embeds.
version: 1.0.0
load_priority: 20
tags: youtube, embed, video, markdown
stable_version: 1.0.0
min_php_ver: 7.4
min_dj_app_ver: 1.0.0
tested_with_dj_app_ver: 1.0.0
author_name: Svetoslav Marinov (Slavi)
company_name: Orbisius
author_uri: https://orbisius.com
text_domain: djebel-plugin-embed-youtube
license: gpl2
requires: djebel-markdown, djebel-static-content
*/

$obj = Djebel_Plugin_Embed_Youtube::getInstance();
Dj_App_Hooks::addAction('app.core.init', [$obj, 'init']);

class Djebel_Plugin_Embed_Youtube
{
    public const EMBED_BASE_URL = 'https://youtube-nocookie.com/embed/';
    public const VIDEO_ID_MIN_LEN = 6;
    public const VIDEO_ID_MAX_LEN = 32;

    private const MARKER_PREFIX = 'DJEBEL_PLUGIN_EMBED_YOUTUBE_';
    private const MARKER_SUFFIX = '_END';

    private $static_content_plugin_id = 'djebel-static-content';

    /**
     * Hooks the plugin into rendered Markdown content.
     *
     * @return bool
     */
    public function init()
    {
        Dj_App_Hooks::addFilter('app.plugins.markdown.pre_process_content', [$this, 'prepareContent']);
        Dj_App_Hooks::addFilter('app.plugins.markdown.post_process_content', [$this, 'processContent']);

        return true;
    }

    /**
     * Marks supported YouTube URLs that begin at column zero on their own line.
     *
     * @param string $content
     * @param array $ctx
     * @return string
     */
    public function prepareContent($content, $ctx = [])
    {
        if (empty($content)) {
            return $content;
        }

        if (!$this->isStaticContentContext($ctx)) {
            return $content;
        }

        // The usual page pays only one substring scan; regex runs only on YouTube content.
        if (stripos($content, 'youtu') === false) {
            return $content;
        }

        // Column-zero matching excludes lists, blockquotes and indented code. A marker
        // inside fenced code is not rendered as a paragraph and is restored after parsing.
        $pattern = '#^(https?://[^\s]*youtu[^\s]*)[ \t]*\r?$#im';
        $prepared_content = preg_replace_callback($pattern, [$this, 'markYoutubeLink'], $content);

        if (is_null($prepared_content)) {
            return $content;
        }

        return $prepared_content;
    }

    /**
     * Replaces prepared top-level YouTube paragraphs after Markdown rendering.
     *
     * @param string $content
     * @param array $ctx
     * @return string
     */
    public function processContent($content, $ctx = [])
    {
        if (empty($content) || (strpos($content, self::MARKER_PREFIX) === false)) {
            return $content;
        }

        if (!$this->isStaticContentContext($ctx)) {
            return $content;
        }

        $marker_pattern = preg_quote(self::MARKER_PREFIX, '#');
        $marker_suffix_pattern = preg_quote(self::MARKER_SUFFIX, '#');
        $paragraph_pattern = '#<p>\s*' . $marker_pattern . '([\da-f]+)' . $marker_suffix_pattern . '\s*</p>#i';
        $processed_content = preg_replace_callback($paragraph_pattern, [$this, 'replaceYoutubeMarker'], $content);

        if (is_null($processed_content)) {
            return $content;
        }

        // Restore markers that Markdown kept inside fenced code or another non-paragraph context.
        $marker_restore_pattern = '#' . $marker_pattern . '([\da-f]+)' . $marker_suffix_pattern . '#i';
        $restored_content = preg_replace_callback($marker_restore_pattern, [$this, 'restoreYoutubeMarker'], $processed_content);

        if (is_null($restored_content)) {
            return $content;
        }

        return $restored_content;
    }

    /**
     * Converts one supported URL into a parser-safe marker.
     *
     * @param array $matches
     * @return string
     */
    public function markYoutubeLink($matches)
    {
        $original_url = $matches[0];
        $url = $matches[1];
        $url = Dj_App_String_Util::trim($url);

        $video_data = $this->parseVideoUrl($url);

        if (empty($video_data)) {
            return $original_url;
        }

        $url_hex = bin2hex($url);
        $marker = self::MARKER_PREFIX . $url_hex . self::MARKER_SUFFIX;

        return $marker;
    }

    /**
     * Replaces one validated marker paragraph with an iframe.
     *
     * @param array $matches
     * @return string
     */
    public function replaceYoutubeMarker($matches)
    {
        $original_html = $matches[0];
        $url = hex2bin($matches[1]);

        if ($url === false) {
            return $original_html;
        }

        $video_data = $this->parseVideoUrl($url);

        if (empty($video_data)) {
            return $original_html;
        }

        $embed_params = [
            'original_url' => $url,
            'video_id' => $video_data['video_id'],
            'player_params' => $video_data['player_params'],
        ];
        $embed_html = $this->buildEmbedHtml($embed_params);

        if (empty($embed_html)) {
            return $original_html;
        }

        return $embed_html;
    }

    /**
     * Restores a marker that Markdown did not turn into a top-level paragraph.
     *
     * @param array $matches
     * @return string
     */
    public function restoreYoutubeMarker($matches)
    {
        $url = hex2bin($matches[1]);

        if ($url === false) {
            return $matches[0];
        }

        $url_escaped = Dj_App_HTML::escHtml($url);

        return $url_escaped;
    }

    /**
     * Checks whether Markdown belongs to a full static-content file.
     *
     * @param array $ctx
     * @return bool
     */
    private function isStaticContentContext($ctx = [])
    {
        if (empty($ctx['full']) || empty($ctx['file'])) {
            return false;
        }

        $file = Dj_App_File_Util::normalizePath($ctx['file']);
        $data_dir_params = [
            'plugin' => $this->static_content_plugin_id,
        ];
        $content_data_dir = Dj_App_Util::getContentDataDir($data_dir_params);
        $private_data_dir = Dj_App_Util::getCorePrivateDataDir($data_dir_params);
        $static_content_dirs = [
            $content_data_dir,
            $private_data_dir,
        ];

        foreach ($static_content_dirs as $static_content_dir) {
            if (empty($static_content_dir)) {
                continue;
            }

            $static_content_dir = Dj_App_File_Util::normalizePath($static_content_dir);
            $static_content_dir = rtrim($static_content_dir, '/');
            $static_content_dir .= '/';

            if (strpos($file, $static_content_dir) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extracts a video ID and supported player parameters from a YouTube URL.
     *
     * @param string $url
     * @return array
     */
    public function parseVideoUrl($url)
    {
        if (empty($url) || !is_string($url)) {
            return [];
        }

        $url_parts = parse_url($url);

        if (empty($url_parts) || empty($url_parts['scheme']) || empty($url_parts['host'])) {
            return [];
        }

        $scheme = strtolower($url_parts['scheme']);

        if ($scheme !== 'http' && $scheme !== 'https') {
            return [];
        }

        $host = strtolower($url_parts['host']);
        $host = rtrim($host, '.');
        $short_hosts = [ 'youtu.be', 'www.youtu.be', ];
        $youtube_hosts = [
            'youtube.com',
            'www.youtube.com',
            'm.youtube.com',
            'music.youtube.com',
            'youtube-nocookie.com',
            'www.youtube-nocookie.com',
        ];
        $is_short_host = in_array($host, $short_hosts);
        $is_youtube_host = in_array($host, $youtube_hosts);

        if (!$is_short_host && !$is_youtube_host) {
            return [];
        }

        $url_path = empty($url_parts['path']) ? '' : $url_parts['path'];
        $url_path = Dj_App_String_Util::trim($url_path, '/');
        $path_segments = [];

        if (!empty($url_path)) {
            $path_segments = explode('/', $url_path);
        }

        $query_params = [];

        if (!empty($url_parts['query'])) {
            parse_str($url_parts['query'], $query_params);
        }

        $video_id = '';

        if ($is_short_host) {
            $path_segment_count = count($path_segments);

            if ($path_segment_count === 1) {
                $video_id = $path_segments[0];
            }
        } elseif (!empty($path_segments)) {
            $route = strtolower($path_segments[0]);
            $path_segment_count = count($path_segments);

            if ($route === 'watch' && $path_segment_count === 1) {
                $video_id = empty($query_params['v']) ? '' : $query_params['v'];
            } else {
                $video_routes = [ 'embed', 'shorts', 'live', 'v', ];

                $is_video_route = in_array($route, $video_routes);

                if ($is_video_route && $path_segment_count === 2) {
                    $video_id = $path_segments[1];
                }
            }
        }

        if (empty($video_id) || !is_scalar($video_id)) {
            return [];
        }

        $video_id = (string) $video_id;
        $video_id = rawurldecode($video_id);
        $video_id_len = strlen($video_id);

        if ($video_id_len < self::VIDEO_ID_MIN_LEN || $video_id_len > self::VIDEO_ID_MAX_LEN) {
            return [];
        }

        $valid_video_id = preg_match('#^[\w-]+$#D', $video_id);

        if ($valid_video_id !== 1) {
            return [];
        }

        $player_params_data = [
            'query_params' => $query_params,
            'video_id' => $video_id,
        ];
        $player_params = $this->normalizePlayerParams($player_params_data);

        return [
            'video_id' => $video_id,
            'player_params' => $player_params,
        ];
    }

    /**
     * Keeps valid iframe-player parameters and drops tracking and playlist input.
     *
     * @param array $params query_params and video_id
     * @return array
     */
    public function normalizePlayerParams($params = [])
    {
        $query_params = empty($params['query_params']) ? [] : (array) $params['query_params'];
        $video_id = empty($params['video_id']) ? '' : $params['video_id'];
        $player_params = [];
        $boolean_param_names = [
            'autoplay',
            'cc_load_policy',
            'controls',
            'disablekb',
            'fs',
            'loop',
            'playsinline',
            'rel',
        ];
        $valid_boolean_values = [ '0', '1', ];

        foreach ($boolean_param_names as $param_name) {
            if (!isset($query_params[$param_name]) || !is_scalar($query_params[$param_name])) {
                continue;
            }

            $param_value = (string) $query_params[$param_name];

            if (!in_array($param_value, $valid_boolean_values)) {
                continue;
            }

            $player_params[$param_name] = $param_value;
        }

        if (!empty($query_params['color']) && is_scalar($query_params['color'])) {
            $color = (string) $query_params['color'];
            $color = strtolower($color);

            if ($color === 'red' || $color === 'white') {
                $player_params['color'] = $color;
            }
        }

        if (!empty($query_params['iv_load_policy']) && is_scalar($query_params['iv_load_policy'])) {
            $iv_load_policy = (string) $query_params['iv_load_policy'];

            $valid_iv_load_policy_values = [ '1', '3', ];

            if (in_array($iv_load_policy, $valid_iv_load_policy_values)) {
                $player_params['iv_load_policy'] = $iv_load_policy;
            }
        }

        $locale_param_names = [ 'cc_lang_pref', 'hl', ];

        foreach ($locale_param_names as $param_name) {
            if (empty($query_params[$param_name]) || !is_scalar($query_params[$param_name])) {
                continue;
            }

            $locale = (string) $query_params[$param_name];
            $valid_locale = preg_match('#^[\w-]{2,35}$#D', $locale);

            if ($valid_locale === 1) {
                $player_params[$param_name] = $locale;
            }
        }

        $start_value = '';

        if (isset($query_params['start']) && is_scalar($query_params['start'])) {
            $start_value = $query_params['start'];
        } elseif (isset($query_params['t']) && is_scalar($query_params['t'])) {
            $start_value = $query_params['t'];
        } elseif (isset($query_params['time_continue']) && is_scalar($query_params['time_continue'])) {
            $start_value = $query_params['time_continue'];
        }

        $start = $this->parseTime($start_value);

        if ($start >= 0) {
            $player_params['start'] = $start;
        }

        $end_value = '';

        if (isset($query_params['end']) && is_scalar($query_params['end'])) {
            $end_value = $query_params['end'];
        }

        $end = $this->parseTime($end_value);

        if ($end >= 0) {
            $player_params['end'] = $end;
        }

        // YouTube requires playlist=VIDEO_ID for a single video to loop.
        if (!empty($player_params['loop']) && !empty($video_id)) {
            $player_params['playlist'] = $video_id;
        }

        return $player_params;
    }

    /**
     * Converts seconds or a compact 1h2m3s duration to seconds.
     *
     * @param mixed $value
     * @return int -1 when invalid
     */
    public function parseTime($value)
    {
        if ($value === '' || is_null($value) || !is_scalar($value)) {
            return -1;
        }

        $value = (string) $value;
        $value = strtolower($value);

        if (ctype_digit($value)) {
            $seconds = (int) $value;

            return $seconds;
        }

        $matches = [];
        $matched = preg_match('#^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$#D', $value, $matches);

        if ($matched !== 1 || count($matches) === 1) {
            return -1;
        }

        $hours = empty($matches[1]) ? 0 : (int) $matches[1];
        $minutes = empty($matches[2]) ? 0 : (int) $matches[2];
        $seconds = empty($matches[3]) ? 0 : (int) $matches[3];
        $total = ($hours * 3600) + ($minutes * 60) + $seconds;

        return $total;
    }

    /**
     * Builds one escaped privacy-enhanced iframe.
     *
     * @param array $params original_url, video_id and player_params
     * @return string
     */
    public function buildEmbedHtml($params = [])
    {
        $original_url = empty($params['original_url']) ? '' : $params['original_url'];
        $video_id = empty($params['video_id']) ? '' : $params['video_id'];
        $player_params = empty($params['player_params']) ? [] : (array) $params['player_params'];

        if (empty($video_id)) {
            return '';
        }

        $video_id_encoded = rawurlencode($video_id);
        $embed_url = self::EMBED_BASE_URL . $video_id_encoded;

        if (!empty($player_params)) {
            $query = http_build_query($player_params, '', '&', PHP_QUERY_RFC3986);
            $embed_url .= '?' . $query;
        }

        $attribute_params = [
            'embed_url' => $embed_url,
            'original_url' => $original_url,
            'video_id' => $video_id,
            'player_params' => $player_params,
        ];
        $iframe_attributes = $this->getIframeAttributes($attribute_params);

        if (empty($iframe_attributes['src'])) {
            return '';
        }

        $attribute_parts = [];
        $has_valid_src = false;

        foreach ($iframe_attributes as $attribute_name => $attribute_value) {
            if (empty($attribute_name) || !is_string($attribute_name)) {
                continue;
            }

            $valid_attribute_name = preg_match('#^[a-z][\w:-]*$#iD', $attribute_name);

            if ($valid_attribute_name !== 1) {
                continue;
            }

            if ($attribute_value === true) {
                $attribute_parts[] = $attribute_name;
                continue;
            }

            if ($attribute_value === false || is_null($attribute_value) || $attribute_value === '') {
                continue;
            }

            if (!is_scalar($attribute_value)) {
                continue;
            }

            if ($attribute_name === 'src') {
                $attribute_value = (string) $attribute_value;
                $attribute_value_escaped = Dj_App_HTML::escUrl($attribute_value);

                if (!empty($attribute_value_escaped)) {
                    $has_valid_src = true;
                }
            } else {
                $attribute_value_escaped = Dj_App_HTML::escAttr($attribute_value);
            }

            if ($attribute_value_escaped === '') {
                continue;
            }

            $attribute_parts[] = sprintf('%s="%s"', $attribute_name, $attribute_value_escaped);
        }

        if (!$has_valid_src || empty($attribute_parts)) {
            return '';
        }

        $attributes_html = implode(' ', $attribute_parts);
        $iframe_html = sprintf('<iframe %s></iframe>', $attributes_html);

        return $iframe_html;
    }

    /**
     * Returns smart iframe defaults after the public attributes filter runs.
     *
     * Filter: app.plugin.embed_youtube.iframe_attributes
     *
     * @param array $params embed_url, original_url, video_id and player_params
     * @return array
     */
    public function getIframeAttributes($params = [])
    {
        $embed_url = empty($params['embed_url']) ? '' : $params['embed_url'];
        $original_url = empty($params['original_url']) ? '' : $params['original_url'];
        $video_id = empty($params['video_id']) ? '' : $params['video_id'];
        $player_params = empty($params['player_params']) ? [] : (array) $params['player_params'];
        $iframe_attributes = [
            'class' => 'djebel-plugin-embed-youtube-iframe',
            'src' => $embed_url,
            'title' => 'YouTube video player',
            'loading' => 'lazy',
            'width' => 560,
            'height' => 315,
            'style' => 'display: block; width: 100%; aspect-ratio: 16 / 9; height: auto; border: 0;',
            'allow' => 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share',
            'referrerpolicy' => 'strict-origin-when-cross-origin',
            'allowfullscreen' => true,
        ];
        $ctx = [
            'original_url' => $original_url,
            'video_id' => $video_id,
            'player_params' => $player_params,
        ];
        $filtered_attributes = Dj_App_Hooks::applyFilter('app.plugin.embed_youtube.iframe_attributes', $iframe_attributes, $ctx);

        if (is_array($filtered_attributes)) {
            return $filtered_attributes;
        }

        return $iframe_attributes;
    }

    /**
     * Returns the singleton plugin instance.
     *
     * @return static
     */
    public static function getInstance()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new static();
        }

        return $instance;
    }
}
