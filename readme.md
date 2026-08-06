# Djebel Embed YouTube

Converts standalone YouTube video and playlist links in Djebel Markdown output into
responsive, privacy-enhanced players. It is intentionally small: no JavaScript, no
stylesheet, no API request, and no configuration are required.

## Usage

Put a YouTube URL on its own Markdown line, with a blank line around it:

```markdown
Text before the video.

https://youtu.be/dQw4w9WgXcQ

Text after the video.
```

The URL-only paragraph becomes an iframe using:

```text
https://youtube-nocookie.com/embed/dQw4w9WgXcQ
```

Inline links, links with custom Markdown labels, lists, blockquotes, code blocks, and
other non-standalone URL uses stay as normal links or text.

## Supported video URLs

- `https://youtube.com/watch?v=VIDEO_ID`
- `https://youtu.be/VIDEO_ID`
- `https://youtube.com/embed/VIDEO_ID`
- `https://youtube.com/shorts/VIDEO_ID`
- `https://youtube.com/live/VIDEO_ID`
- `https://youtube.com/v/VIDEO_ID`
- The same supported routes on the `www`, `m`, and `music` input hosts, plus
  `youtube-nocookie.com`

Both HTTP and HTTPS input links are accepted. Every generated player uses HTTPS and the
privacy-enhanced `youtube-nocookie.com` domain without `www`.

## Supported playlist URLs

Put a playlist URL on its own line in the same way:

```markdown
https://youtube.com/playlist?list=PLAYLIST_ID
```

The plugin generates a privacy-enhanced playlist player:

```text
https://youtube-nocookie.com/embed?listType=playlist&list=PLAYLIST_ID
```

The `list` parameter is recognized on supported YouTube playlist, watch, short, and
embed links. When a URL contains both a playlist and a video ID, the playlist takes
precedence: the video ID is deliberately ignored so playback starts with the playlist's
first video.

### Programmatic parsing

`parseVideoUrl()` and `parsePlaylistUrl()` return `Dj_App_Result`, following Djebel's
standard result contract. Check the status before reading the structured payload:

```php
$plugin_obj = Djebel_Plugin_Embed_Youtube::getInstance();
$parse_res_obj = $plugin_obj->parsePlaylistUrl($youtube_url);

if ($parse_res_obj->isSuccess()) {
    $playlist_id = $parse_res_obj->playlist_id;
    $player_params = $parse_res_obj->player_params;
}
```

Successful video results contain `video_id` and `player_params`; successful playlist
results contain `playlist_id` and `player_params`. Invalid or unsupported URLs return an
error result with no parsing payload.

## Player parameters

Supported values are validated before being copied to the embed URL:

- Boolean controls: `autoplay`, `cc_load_policy`, `controls`, `disablekb`, `fs`,
  `loop`, `playsinline`, `rel`
- Player choices: `color`, `iv_load_policy`
- Languages: `cc_lang_pref`, `hl`
- Times: `start`, `end`; shared-link `t` and `time_continue` are normalized to `start`

Times can be seconds or compact durations such as `1m30s`. A single-video `loop=1`
automatically adds the same video ID as YouTube's required playlist value.

Tracking parameters (`si`, `feature`, `utm_*`, and similar values), deprecated
parameters, and unknown parameters are not forwarded.

## Smart iframe defaults

Each iframe receives:

- Responsive 16:9 inline layout
- `loading="lazy"`
- `width="560"` and `height="315"`
- A descriptive `YouTube video player` or `YouTube playlist player` title
- Standard player permissions and fullscreen support
- `referrerpolicy="strict-origin-when-cross-origin"`
- `djebel-plugin-embed-youtube-iframe` CSS class

Another plugin can modify, add, or remove these attributes through
`app.plugin.embed_youtube.iframe_attributes`. The filter receives the attribute array and
context containing `original_url`, `content_type`, `video_id`, `playlist_id`, and
`player_params`. Only the identifier for the current content type is populated.

```php
class My_Youtube_Embed_Customizer
{
    public function filterIframeAttributes($attributes, $ctx = [])
    {
        $attributes['loading'] = 'eager';
        $attributes['data-content-type'] = $ctx['content_type'];

        if (!empty($ctx['video_id'])) {
            $attributes['data-video-id'] = $ctx['video_id'];
        }

        return $attributes;
    }
}

$obj = new My_Youtube_Embed_Customizer();
Dj_App_Hooks::addFilter('app.plugin.embed_youtube.iframe_attributes', [$obj, 'filterIframeAttributes']);
```

Set an attribute to `false` or an empty value to omit it. Attribute names are validated,
and scalar values are escaped before output.

## Why it is efficient

- Works on rendered Markdown regardless of whether its source is a file, database, or plugin
- Returns immediately for empty content and output without `youtu`
- Uses a second cheap anchor-prefix check before scanning output rows
- Scans newline offsets without allocating an array of every output row
- Builds a replacement buffer only after finding a valid embed
- Parses a playlist query only after the cheap `list=` and validated-host gates
- Parses video query parameters only after recognizing a supported video route
- Preserves untouched UTF-8 output, including English and Bulgarian text
- Uses no regular expressions
- Performs no network request while rendering
- Lazy-loads YouTube so an off-screen player does not load immediately
- Adds no JavaScript, CSS file, or extra site asset request

The plugin uses only Markdown's post-process hook. It scans rendered output rows and
replaces an exact top-level URL paragraph, while leaving list, quote, code, inline, and
labelled-link output unchanged. It does not inspect or depend on the content source.

## Requirements

- PHP 7.4+
- `djebel-markdown`

## Installation

Install it as a submodule in the site's non-public plugin directory:

```bash
git submodule add https://github.com/djebel-app-plugins/djebel-embed-youtube.git \
  .ht_djebel/app/plugins/djebel-embed-youtube
```

Djebel loads the plugin automatically. The shared addon test runner loads `plugin.php`
directly, so the plugin does not ship a separate test loader. No `app.ini` entry is needed.
