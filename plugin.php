<?php
/*
plugin_name: Djebel Embed YouTube
plugin_uri: https://djebel.com/plugins/djebel-embed-youtube
description: Converts standalone YouTube links in rendered Markdown into privacy-enhanced responsive embeds.
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
requires: djebel-markdown
*/

$obj = Djebel_Plugin_Embed_Youtube::getInstance();
Dj_App_Hooks::addAction('app.core.init', [$obj, 'init']);

class Djebel_Plugin_Embed_Youtube
{
    public const EMBED_BASE_URL = 'https://youtube-nocookie.com/embed';
    public const VIDEO_ID_MIN_LEN = 6;
    public const VIDEO_ID_MAX_LEN = 32;
    public const PLAYLIST_ID_MIN_LEN = 10;
    public const PLAYLIST_ID_MAX_LEN = 128;

    private const ANCHOR_PREFIX = '<p><a href=';
    private const ANCHOR_SUFFIX = '</a></p>';
    private const BLOCKED_CONTAINER_TAGS = [
        '<ul' => '</ul>',
        '<ol' => '</ol>',
        '<blockquote' => '</blockquote>',
    ];
    private const VIDEO_ROUTES = [ 'embed', 'shorts', 'live', 'v', ];
    private const YOUTUBE_ID_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-';
    private const BOOLEAN_PARAM_NAMES = [
        'autoplay',
        'cc_load_policy',
        'controls',
        'disablekb',
        'fs',
        'loop',
        'playsinline',
        'rel',
    ];

    private const BOOLEAN_PARAM_VALUES = [ '0', '1', ];
    private const IV_LOAD_POLICY_VALUES = [ '1', '3', ];
    private const LOCALE_PARAM_NAMES = [ 'cc_lang_pref', 'hl', ];
    private const LOCALE_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-';
    private const DURATION_PARTS = [
        'h' => 3600,
        'm' => 60,
        's' => 1,
    ];

    private const ATTRIBUTE_NAME_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_:-';

    /**
     * Hooks the plugin into rendered Markdown content.
     *
     * @return bool
     */
    public function init()
    {
        // Work only with Markdown's rendered output. The content source can be a file,
        // database or another plugin; this plugin needs no knowledge of its storage.
        Dj_App_Hooks::addFilter('app.plugins.markdown.post_process_content', [$this, 'processContent']);

        return true;
    }

    /**
     * Replaces standalone top-level YouTube paragraphs after Markdown rendering.
     *
     * @param string $content
     * @param array $ctx
     * @return string
     */
    public function processContent($content, $ctx = [])
    {
        if (empty($content)) {
            return $content;
        }

        // Most content exits after one cheap scan, without line parsing or URL work.
        if (stripos($content, 'youtu') === false) {
            return $content;
        }

        // If Markdown did not emit an auto-linked paragraph, no row can be embedded.
        if (strpos($content, self::ANCHOR_PREFIX) === false) {
            return $content;
        }

        $content_len = strlen($content);
        $anchor_prefix_len = strlen(self::ANCHOR_PREFIX);
        $blocked_container_tags = [];
        $line_start_pos = 0;
        $copy_start_pos = 0;
        $blocked_container_depth = 0;
        $processed_content = '';
        $has_replacement = false;

        // Walk newline offsets instead of explode(): ordinary rows are never copied.
        // Container depth prevents loose list and blockquote paragraphs from embedding.
        while ($line_start_pos < $content_len) {
            $line_end_pos = strpos($content, "\n", $line_start_pos);

            if ($line_end_pos === false) {
                // strpos() returns false for the final row when the buffer has no
                // trailing newline. Treat the buffer end as that row's boundary.
                $line_end_pos = $content_len;
            }

            $line_len = $line_end_pos - $line_start_pos;
            $is_opening_container = false;
            $is_closing_container = false;
            $is_potential_container = false;

            // Inspect ASCII HTML tag bytes only. Most rows begin with <p>, so they
            // skip all container metadata setup and comparisons.
            if ($line_len > 1 && $content[$line_start_pos] == '<') {
                $tag_name_first_byte = $content[$line_start_pos + 1];
                $is_potential_container = $tag_name_first_byte == 'u';

                if (!$is_potential_container) {
                    $is_potential_container = $tag_name_first_byte == 'o';
                }

                if (!$is_potential_container) {
                    $is_potential_container = $tag_name_first_byte == 'b';
                }

                if (!$is_potential_container) {
                    $is_potential_container = $tag_name_first_byte == '/';
                }
            }

            if ($is_potential_container && empty($blocked_container_tags)) {
                // Derive lengths once, and only when this document actually contains
                // a row that could affect list or blockquote depth.
                foreach (self::BLOCKED_CONTAINER_TAGS as $opening_prefix => $closing_tag) {
                    $blocked_container_tags[] = [
                        'opening_prefix' => $opening_prefix,
                        'opening_prefix_len' => strlen($opening_prefix),
                        'closing_tag' => $closing_tag,
                        'closing_tag_len' => strlen($closing_tag),
                    ];
                }
            }

            if ($is_potential_container) {
                foreach ($blocked_container_tags as $container_tag) {
                    $opening_prefix = $container_tag['opening_prefix'];
                    $opening_prefix_len = $container_tag['opening_prefix_len'];
                    $closing_tag = $container_tag['closing_tag'];
                    $closing_tag_len = $container_tag['closing_tag_len'];

                    if ($line_len == $closing_tag_len) {
                        $is_closing_container = substr_compare(
                            $content,
                            $closing_tag,
                            $line_start_pos,
                            $closing_tag_len
                        ) == 0;
                    }

                    if ($is_closing_container) {
                        break;
                    }

                    if ($line_len <= $opening_prefix_len) {
                        continue;
                    }

                    $has_opening_prefix = substr_compare(
                        $content,
                        $opening_prefix,
                        $line_start_pos,
                        $opening_prefix_len
                    ) == 0;

                    if (!$has_opening_prefix) {
                        continue;
                    }

                    // This byte offset inspects ASCII HTML syntax, never UTF-8 user text.
                    // Requiring a boundary keeps <ol from matching a tag such as <old-tag>.
                    $tag_boundary_pos = $line_start_pos + $opening_prefix_len;
                    $tag_boundary = $content[$tag_boundary_pos];
                    $is_opening_container = $tag_boundary == '>' || ctype_space($tag_boundary);

                    if ($is_opening_container) {
                        break;
                    }
                }
            }

            if ($is_opening_container) {
                $blocked_container_depth++;
            } elseif ($is_closing_container) {
                if ($blocked_container_depth > 0) {
                    $blocked_container_depth--;
                }
            } elseif ($blocked_container_depth == 0 && $line_len > $anchor_prefix_len) {
                $has_anchor_prefix = substr_compare(
                    $content,
                    self::ANCHOR_PREFIX,
                    $line_start_pos,
                    $anchor_prefix_len
                ) == 0;

                if ($has_anchor_prefix) {
                    $content_line = substr($content, $line_start_pos, $line_len);

                    if (stripos($content_line, 'youtu') !== false) {
                        $replacement = $this->replaceYoutubeOutputLine($content_line);

                        if ($replacement != $content_line) {
                            $copy_len = $line_start_pos - $copy_start_pos;
                            $processed_content .= substr($content, $copy_start_pos, $copy_len);
                            $processed_content .= $replacement;
                            // Copy from the row ending next so its newline and every
                            // untouched row remain byte-for-byte identical.
                            $copy_start_pos = $line_end_pos;
                            $has_replacement = true;
                        }
                    }
                }
            }

            if ($line_end_pos == $content_len) {
                break;
            }

            $line_start_pos = $line_end_pos + 1;
        }

        if (!$has_replacement) {
            return $content;
        }

        $processed_content .= substr($content, $copy_start_pos);

        return $processed_content;
    }

    /**
     * Replaces one exact URL-only paragraph emitted by Markdown.
     *
     * @param string $content_line
     * @return string
     */
    public function replaceYoutubeOutputLine($content_line)
    {
        if (strpos($content_line, self::ANCHOR_PREFIX) !== 0) {
            return $content_line;
        }

        $anchor_prefix_len = strlen(self::ANCHOR_PREFIX);

        if (!isset($content_line[$anchor_prefix_len])) {
            return $content_line;
        }

        // ANCHOR_PREFIX is ASCII HTML syntax, so this byte offset cannot split
        // English, Bulgarian or any other UTF-8 text in the URL label.
        $quote_char = $content_line[$anchor_prefix_len];

        if ($quote_char != '"' && $quote_char != "'") {
            return $content_line;
        }

        $quote_char_len = strlen($quote_char);
        $url_start_pos = $anchor_prefix_len + $quote_char_len;
        $href_end_marker = $quote_char . '>';
        $href_end_pos = strpos($content_line, $href_end_marker, $url_start_pos);

        if ($href_end_pos === false) {
            return $content_line;
        }

        $href_end_marker_len = strlen($href_end_marker);
        $anchor_suffix_pos = strrpos($content_line, self::ANCHOR_SUFFIX);

        if ($anchor_suffix_pos === false) {
            return $content_line;
        }

        $content_line_len = strlen($content_line);
        $anchor_suffix_len = strlen(self::ANCHOR_SUFFIX);
        $expected_suffix_pos = $content_line_len - $anchor_suffix_len;

        if ($anchor_suffix_pos != $expected_suffix_pos) {
            return $content_line;
        }

        $url_len = $href_end_pos - $url_start_pos;
        $url = substr($content_line, $url_start_pos, $url_len);
        $label_start_pos = $href_end_pos + $href_end_marker_len;
        $label_len = $anchor_suffix_pos - $label_start_pos;
        $label = substr($content_line, $label_start_pos, $label_len);

        if ($url != $label) {
            return $content_line;
        }

        $url = Dj_App_HTML::decodeEntities($url);
        $url = Dj_App_String_Util::trim($url);
        $embed_params = [];

        // A playlist is the primary content whenever list= is present. Parse it
        // first so an attached video ID cannot choose the starting video.
        if (strpos($url, 'list=') !== false) {
            $playlist_res = $this->parsePlaylistUrl($url);

            if ($playlist_res->isSuccess()) {
                $playlist_data = $playlist_res->data();
                $embed_params = [
                    'original_url' => $url,
                    'playlist_id' => $playlist_data['playlist_id'],
                    'player_params' => $playlist_data['player_params'],
                ];
            }
        }

        if (empty($embed_params)) {
            $video_res = $this->parseVideoUrl($url);

            if ($video_res->isError()) {
                return $content_line;
            }

            $video_data = $video_res->data();
            $embed_params = [
                'original_url' => $url,
                'video_id' => $video_data['video_id'],
                'player_params' => $video_data['player_params'],
            ];
        }

        $embed_html = $this->buildEmbedHtml($embed_params);

        if (empty($embed_html)) {
            return $content_line;
        }

        return $embed_html;
    }

    /**
     * Extracts a video ID and supported player parameters from a YouTube URL.
     *
     * @param string $url
     * @return Dj_App_Result video_id and player_params on success
     */
    public function parseVideoUrl($url)
    {
        $res_obj = $this->parseYoutubeUrlParts($url);

        if ($res_obj->isError()) {
            return $res_obj;
        }

        $url_parts = $res_obj->url_parts;
        $is_short_host = $res_obj->is_short_host;

        $url_path = empty($url_parts['path']) ? '' : $url_parts['path'];
        $url_path = Dj_App_String_Util::trim($url_path, '/');
        $path_segments = [];

        if (!empty($url_path)) {
            $path_segments = explode('/', $url_path);
        }

        $query_string = empty($url_parts['query']) ? '' : $url_parts['query'];
        $query_params = [];
        $query_is_parsed = false;
        $video_id = '';

        if ($is_short_host) {
            $path_segment_count = count($path_segments);

            if ($path_segment_count == 1) {
                $video_id = $path_segments[0];
            }
        } elseif (!empty($path_segments)) {
            $route = strtolower($path_segments[0]);
            $path_segment_count = count($path_segments);

            if ($route == 'watch' && $path_segment_count == 1) {
                if (!empty($query_string)) {
                    parse_str($query_string, $query_params);
                    $query_is_parsed = true;
                }

                $video_id = empty($query_params['v']) ? '' : $query_params['v'];
            } else {
                $is_video_route = in_array($route, self::VIDEO_ROUTES);

                if ($is_video_route && $path_segment_count == 2) {
                    $video_id = $path_segments[1];
                }
            }
        }

        if (empty($video_id) || !is_scalar($video_id)) {
            return $this->markResultError($res_obj, 'YouTube video ID not found');
        }

        $video_id = (string) $video_id;
        $video_id = rawurldecode($video_id);
        $video_id_len = strlen($video_id);

        if ($video_id_len < self::VIDEO_ID_MIN_LEN || $video_id_len > self::VIDEO_ID_MAX_LEN) {
            return $this->markResultError($res_obj, 'Invalid YouTube video ID length');
        }

        $valid_video_id_len = strspn($video_id, self::YOUTUBE_ID_CHARS);

        if ($valid_video_id_len != $video_id_len) {
            return $this->markResultError($res_obj, 'Invalid YouTube video ID');
        }

        // watch needed its query to find v; other routes parse only after their ID is valid.
        if (!$query_is_parsed && !empty($query_string)) {
            parse_str($query_string, $query_params);
        }

        // Routing identifiers have already done their job and are not player
        // parameters. Removing them lets an ordinary watch?v= URL skip every
        // normalization loop below.
        unset($query_params['v'], $query_params['list']);
        $player_params = [];

        // Avoid parameter loops and time parsing unless candidates remain.
        if (!empty($query_params)) {
            $player_params_data = [
                'query_params' => $query_params,
                'video_id' => $video_id,
            ];
            $player_params = $this->normalizePlayerParams($player_params_data);
        }

        $result_data = [
            'video_id' => $video_id,
            'player_params' => $player_params,
        ];
        $res_obj->data($result_data, Dj_App_Result::OVERRIDE_FLAG);
        $res_obj->status(true);

        return $res_obj;
    }

    /**
     * Extracts a playlist ID and supported player parameters from a YouTube URL.
     *
     * A list parameter is valid on playlist, watch, short, and embed URLs. The
     * route and any attached video therefore do not participate in playlist parsing.
     *
     * @param string $url
     * @return Dj_App_Result playlist_id and player_params on success
     */
    public function parsePlaylistUrl($url)
    {
        $res_obj = $this->parseYoutubeUrlParts($url);

        if ($res_obj->isError()) {
            return $res_obj;
        }

        $url_parts = $res_obj->url_parts;

        if (empty($url_parts['query'])) {
            return $this->markResultError($res_obj, 'YouTube playlist query not found');
        }

        $query_params = [];
        parse_str($url_parts['query'], $query_params);

        if (empty($query_params['list']) || !is_scalar($query_params['list'])) {
            return $this->markResultError($res_obj, 'YouTube playlist ID not found');
        }

        // parse_str() has already decoded the query value. Decoding it again could
        // turn an encoded percent sequence inside an otherwise valid ID into data.
        $playlist_id = (string) $query_params['list'];
        $playlist_id_len = strlen($playlist_id);

        if ($playlist_id_len < self::PLAYLIST_ID_MIN_LEN || $playlist_id_len > self::PLAYLIST_ID_MAX_LEN) {
            return $this->markResultError($res_obj, 'Invalid YouTube playlist ID length');
        }

        $valid_playlist_id_len = strspn($playlist_id, self::YOUTUBE_ID_CHARS);

        if ($valid_playlist_id_len != $playlist_id_len) {
            return $this->markResultError($res_obj, 'Invalid YouTube playlist ID');
        }

        // The playlist and attached video select content, not player behavior.
        // Removing both lets the common watch?v=...&list=... form stop here.
        unset($query_params['list'], $query_params['v']);
        $player_params = [];

        if (!empty($query_params)) {
            $player_params_data = [
                'query_params' => $query_params,
            ];
            $player_params = $this->normalizePlayerParams($player_params_data);
        }

        $result_data = [
            'playlist_id' => $playlist_id,
            'player_params' => $player_params,
        ];
        $res_obj->data($result_data, Dj_App_Result::OVERRIDE_FLAG);
        $res_obj->status(true);

        return $res_obj;
    }

    /**
     * Validates a YouTube URL once and returns the parts needed by route parsers.
     *
     * @param string $url
     * @return Dj_App_Result url_parts and is_short_host on success
     */
    private function parseYoutubeUrlParts($url)
    {
        $res_obj = new Dj_App_Result();

        if (empty($url) || !is_string($url)) {
            $res_obj->msg('Empty YouTube URL');

            return $res_obj;
        }

        $url_parts = parse_url($url);

        if (empty($url_parts) || empty($url_parts['scheme']) || empty($url_parts['host'])) {
            $res_obj->msg('Invalid YouTube URL');

            return $res_obj;
        }

        $scheme = strtolower($url_parts['scheme']);

        if ($scheme != 'http' && $scheme != 'https') {
            $res_obj->msg('Unsupported YouTube URL scheme');

            return $res_obj;
        }

        $host = strtolower($url_parts['host']);
        $host = rtrim($host, '.');
        $is_short_host = false;

        // One dispatch both validates the host and identifies short links, so a
        // recognized host is never searched through multiple host collections.
        switch ($host) {
            case 'youtu.be':
            case 'www.youtu.be':
                $is_short_host = true;
                break;

            case 'youtube.com':
            case 'www.youtube.com':
            case 'm.youtube.com':
            case 'music.youtube.com':
            case 'youtube-nocookie.com':
            case 'www.youtube-nocookie.com':
                break;

            default:
                $res_obj->msg('Unsupported YouTube host');

                return $res_obj;
        }

        $result_data = [
            'url_parts' => $url_parts,
            'is_short_host' => $is_short_host,
        ];
        $res_obj->data($result_data, Dj_App_Result::OVERRIDE_FLAG);
        $res_obj->status(true);

        return $res_obj;
    }

    /**
     * Reuses a parser result for a later validation error without another allocation.
     *
     * @param Dj_App_Result $res_obj
     * @param string $message
     * @return Dj_App_Result
     */
    private function markResultError($res_obj, $message)
    {
        // Common URL validation populated internal parsing data. It must not leak
        // through an error returned by the more specific video or playlist parser.
        $res_obj->clearData();
        $res_obj->status(false);
        $res_obj->msg($message);

        return $res_obj;
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
        $player_params = [];

        if (empty($query_params)) {
            return $player_params;
        }

        $video_id = empty($params['video_id']) ? '' : $params['video_id'];

        foreach (self::BOOLEAN_PARAM_NAMES as $param_name) {
            if (!isset($query_params[$param_name]) || !is_scalar($query_params[$param_name])) {
                continue;
            }

            $param_value = (string) $query_params[$param_name];

            if (!in_array($param_value, self::BOOLEAN_PARAM_VALUES)) {
                continue;
            }

            $player_params[$param_name] = $param_value;
        }

        if (!empty($query_params['color']) && is_scalar($query_params['color'])) {
            $color = (string) $query_params['color'];
            $color = strtolower($color);

            if ($color == 'red' || $color == 'white') {
                $player_params['color'] = $color;
            }
        }

        if (!empty($query_params['iv_load_policy']) && is_scalar($query_params['iv_load_policy'])) {
            $iv_load_policy = (string) $query_params['iv_load_policy'];

            if (in_array($iv_load_policy, self::IV_LOAD_POLICY_VALUES)) {
                $player_params['iv_load_policy'] = $iv_load_policy;
            }
        }

        foreach (self::LOCALE_PARAM_NAMES as $param_name) {
            if (empty($query_params[$param_name]) || !is_scalar($query_params[$param_name])) {
                continue;
            }

            $locale = (string) $query_params[$param_name];
            $locale_len = strlen($locale);

            if ($locale_len < 2 || $locale_len > 35) {
                continue;
            }

            $valid_locale_len = strspn($locale, self::LOCALE_CHARS);

            if ($valid_locale_len != $locale_len) {
                continue;
            }

            $player_params[$param_name] = $locale;
        }

        $start_value = '';
        $has_start_value = false;

        if (isset($query_params['start']) && is_scalar($query_params['start'])) {
            $start_value = $query_params['start'];
            $has_start_value = true;
        } elseif (isset($query_params['t']) && is_scalar($query_params['t'])) {
            $start_value = $query_params['t'];
            $has_start_value = true;
        } elseif (isset($query_params['time_continue']) && is_scalar($query_params['time_continue'])) {
            $start_value = $query_params['time_continue'];
            $has_start_value = true;
        }

        if ($has_start_value) {
            $start = $this->parseTime($start_value);

            if ($start >= 0) {
                $player_params['start'] = $start;
            }
        }

        if (isset($query_params['end']) && is_scalar($query_params['end'])) {
            $end_value = $query_params['end'];
            $end = $this->parseTime($end_value);

            if ($end >= 0) {
                $player_params['end'] = $end;
            }
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

        if (ctype_digit($value)) {
            $seconds = (int) $value;

            return $seconds;
        }

        $remaining_value = $value;
        $total = 0;
        $has_duration_part = false;

        foreach (self::DURATION_PARTS as $unit => $multiplier) {
            $unit_pos = strpos($remaining_value, $unit);

            if ($unit_pos === false) {
                continue;
            }

            $unit_value = substr($remaining_value, 0, $unit_pos);

            if ($unit_value === '' || !ctype_digit($unit_value)) {
                return -1;
            }

            $unit_value = (int) $unit_value;
            $total += $unit_value * $multiplier;
            $remaining_value = substr($remaining_value, $unit_pos + 1);
            $has_duration_part = true;
        }

        if (!$has_duration_part || $remaining_value != '') {
            return -1;
        }

        return $total;
    }

    /**
     * Builds one escaped privacy-enhanced iframe.
     *
     * @param array $params original_url, video_id or playlist_id, and player_params
     * @return string
     */
    public function buildEmbedHtml($params = [])
    {
        $playlist_id = empty($params['playlist_id']) ? '' : $params['playlist_id'];

        if (!empty($playlist_id)) {
            // A playlist is already sufficient; do not inspect or encode a video ID.
            $content_type = 'playlist';
            $video_id = '';
        } else {
            $video_id = empty($params['video_id']) ? '' : $params['video_id'];

            if (empty($video_id)) {
                return '';
            }

            $content_type = 'video';
        }

        $original_url = empty($params['original_url']) ? '' : $params['original_url'];
        $player_params = empty($params['player_params']) ? [] : (array) $params['player_params'];

        if ($content_type == 'playlist') {
            // The player accepts a playlist without a video path. Keeping the path
            // empty makes YouTube begin with the first item in the supplied list.
            $embed_url = self::EMBED_BASE_URL;
            $embed_query_params = [
                'listType' => 'playlist',
                'list' => $playlist_id,
            ];

            if (!empty($player_params)) {
                // Required playlist routing values win if a filter adds a colliding
                // player parameter in the future.
                $embed_query_params += $player_params;
            }
        } else {
            // Encoding is deferred until the video branch actually needs it.
            $video_id_encoded = rawurlencode($video_id);
            $embed_url = self::EMBED_BASE_URL . '/' . $video_id_encoded;
            $embed_query_params = $player_params;
        }

        if (!empty($embed_query_params)) {
            $query = http_build_query($embed_query_params, '', '&', PHP_QUERY_RFC3986);
            $embed_url .= '?' . $query;
        }

        $attribute_params = [
            'embed_url' => $embed_url,
            'original_url' => $original_url,
            'content_type' => $content_type,
            'video_id' => $video_id,
            'playlist_id' => $playlist_id,
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

            if ($attribute_value === false || is_null($attribute_value) || $attribute_value === '') {
                continue;
            }

            $attribute_name_len = strlen($attribute_name);
            $valid_attribute_len = strspn($attribute_name, self::ATTRIBUTE_NAME_CHARS);
            $first_attribute_char = substr($attribute_name, 0, 1);
            $has_valid_first_char = ctype_alpha($first_attribute_char);

            if ($valid_attribute_len != $attribute_name_len || !$has_valid_first_char) {
                continue;
            }

            if ($attribute_value === true) {
                $attribute_parts[] = $attribute_name;
                continue;
            }

            if (!is_scalar($attribute_value)) {
                continue;
            }

            if ($attribute_name == 'src') {
                $attribute_value = (string) $attribute_value;
                $attribute_value_escaped = Dj_App_HTML::escUrl($attribute_value);

                if (!empty($attribute_value_escaped)) {
                    $has_valid_src = true;
                }
            } else {
                $attribute_value_escaped = Dj_App_HTML::escAttr($attribute_value);
            }

            if ($attribute_value_escaped == '') {
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
     * @param array $params embed_url, original_url, content identifiers and player_params
     * @return array
     */
    public function getIframeAttributes($params = [])
    {
        $embed_url = empty($params['embed_url']) ? '' : $params['embed_url'];
        $original_url = empty($params['original_url']) ? '' : $params['original_url'];
        $content_type = empty($params['content_type']) ? 'video' : $params['content_type'];
        $player_params = empty($params['player_params']) ? [] : (array) $params['player_params'];

        if ($content_type == 'playlist') {
            // Only read the identifier relevant to the current player type.
            $playlist_id = empty($params['playlist_id']) ? '' : $params['playlist_id'];
            $video_id = '';
            $title = 'YouTube playlist player';
        } else {
            $video_id = empty($params['video_id']) ? '' : $params['video_id'];
            $playlist_id = '';
            $title = 'YouTube video player';
        }
        $iframe_attributes = [
            'class' => 'djebel-plugin-embed-youtube-iframe',
            'src' => $embed_url,
            'title' => $title,
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
            'content_type' => $content_type,
            'video_id' => $video_id,
            'playlist_id' => $playlist_id,
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
