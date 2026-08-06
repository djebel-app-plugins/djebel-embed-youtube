<?php
/**
 * Unit tests for Djebel_Plugin_Embed_Youtube. The framework and required Markdown
 * plugin are loaded by djebel-app's shared addon test runner.
 */

use PHPUnit\Framework\TestCase;

class Djebel_Plugin_Embed_Youtube_Test extends TestCase
{
    private $plugin_obj;
    private $markdown_obj;

    protected function setUp(): void
    {
        $this->plugin_obj = Djebel_Plugin_Embed_Youtube::getInstance();
        $this->markdown_obj = Djebel_App_Plugin_Markdown::getInstance();
        $this->plugin_obj->init();
    }

    protected function tearDown(): void
    {
        Dj_App_Hooks::removeFilter('app.plugins.markdown.post_process_content', [$this->plugin_obj, 'processContent']);
        Dj_App_Hooks::removeFilter('app.plugin.embed_youtube.iframe_attributes', [$this, 'filterIframeAttributes']);
    }

    /**
     * Converts a Markdown fixture without assuming where its content came from.
     *
     * @param string $markdown
     * @return string
     */
    private function renderMarkdown($markdown)
    {
        $ctx = [];
        $html = $this->markdown_obj->processMarkdown($markdown, $ctx);

        return $html;
    }

    public function testSupportedVideoUrlsUsePrivacyEnhancedEmbed()
    {
        $video_id = 'dQw4w9WgXcQ';
        $urls = [
            "https://youtube.com/watch?v={$video_id}",
            "https://youtu.be/{$video_id}",
            "https://youtube.com/embed/{$video_id}",
            "https://youtube.com/shorts/{$video_id}",
            "https://youtube.com/live/{$video_id}",
            "https://youtube.com/v/{$video_id}",
            "https://www.youtube-nocookie.com/embed/{$video_id}",
            "https://m.youtube.com/watch?v={$video_id}",
            "https://music.youtube.com/watch?v={$video_id}",
        ];

        foreach ($urls as $url) {
            $html = $this->renderMarkdown($url);

            $this->assertStringContainsString('<iframe ', $html);
            $this->assertStringContainsString("https://youtube-nocookie.com/embed/{$video_id}", $html);
            $this->assertStringNotContainsString('www.youtube-nocookie.com', $html);
        }
    }

    public function testStandaloneRulePreservesOtherMarkdownContexts()
    {
        $video_url = 'https://youtu.be/dQw4w9WgXcQ';
        $markdown_lines = [
            "Inline {$video_url}",
            '',
            "[Watch this]({$video_url})",
            '',
            "- {$video_url}",
            '',
            '- List item',
            '',
            "  {$video_url}",
            '',
            "> {$video_url}",
            '',
            '```',
            $video_url,
            '```',
        ];
        $markdown = implode("\n", $markdown_lines);
        $html = $this->renderMarkdown($markdown);

        $this->assertStringNotContainsString('<iframe ', $html);
        $this->assertStringContainsString('<blockquote>', $html);
        $this->assertStringContainsString("<pre><code>{$video_url}</code></pre>", $html);
    }

    public function testPlaylistOnlyUrlRemainsLink()
    {
        $url = 'https://youtube.com/playlist?list=PL1234567890';
        $html = $this->renderMarkdown($url);

        $this->assertStringNotContainsString('<iframe ', $html);
        $this->assertStringContainsString('youtube.com/playlist', $html);
    }

    public function testOrderedLooseListWithStartAttributeRemainsLink()
    {
        $url = 'https://youtu.be/dQw4w9WgXcQ';
        $markdown_lines = [
            '3. List item',
            '',
            "   {$url}",
        ];
        $markdown = implode("\n", $markdown_lines);
        $html = $this->renderMarkdown($markdown);

        $this->assertStringContainsString('<ol start="3">', $html);
        $this->assertStringNotContainsString('<iframe ', $html);
        $this->assertStringContainsString($url, $html);
    }

    public function testRepeatedUrlEmbedsEveryStandaloneParagraph()
    {
        $url = 'https://youtu.be/dQw4w9WgXcQ';
        $markdown_lines = [
            $url,
            '',
            $url,
        ];
        $markdown = implode("\n", $markdown_lines);
        $html = $this->renderMarkdown($markdown);
        $iframe_count = substr_count($html, '<iframe ');

        $this->assertSame(2, $iframe_count);
    }

    public function testMixedEnglishAndBulgarianContentIsPreserved()
    {
        $url = 'https://youtu.be/dQw4w9WgXcQ';
        $markdown_lines = [
            'Български текст преди видеото.',
            '',
            $url,
            '',
            'English и български текст след видеото.',
        ];
        $markdown = implode("\n", $markdown_lines);
        $html = $this->renderMarkdown($markdown);

        $this->assertStringContainsString('Български текст преди видеото.', $html);
        $this->assertStringContainsString('English и български текст след видеото.', $html);
        $this->assertStringContainsString('<iframe ', $html);
    }

    public function testDeceptiveHostRemainsLink()
    {
        $url = 'https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ';
        $html = $this->renderMarkdown($url);

        $this->assertStringNotContainsString('<iframe ', $html);
        $this->assertStringContainsString('youtube.com.evil.example', $html);
    }

    public function testAllowedParametersSurviveNormalization()
    {
        $url = 'https://youtube.com/watch?v=dQw4w9WgXcQ&t=1m30s&autoplay=1&controls=0&color=white&hl=en-ca';
        $html = $this->renderMarkdown($url);

        $this->assertStringContainsString('autoplay=1', $html);
        $this->assertStringContainsString('controls=0', $html);
        $this->assertStringContainsString('color=white', $html);
        $this->assertStringContainsString('hl=en-ca', $html);
        $this->assertStringContainsString('start=90', $html);
    }

    public function testTrackingParametersAreRemoved()
    {
        $url = 'https://youtube.com/watch?v=dQw4w9WgXcQ&list=PL123&si=tracking&feature=share&utm_source=test&modestbranding=1&unknown=1';
        $html = $this->renderMarkdown($url);

        $this->assertStringContainsString('https://youtube-nocookie.com/embed/dQw4w9WgXcQ', $html);
        $this->assertStringNotContainsString('list=', $html);
        $this->assertStringNotContainsString('tracking', $html);
        $this->assertStringNotContainsString('feature=', $html);
        $this->assertStringNotContainsString('utm_', $html);
        $this->assertStringNotContainsString('modestbranding', $html);
        $this->assertStringNotContainsString('unknown=', $html);
    }

    public function testSingleVideoLoopGetsRequiredPlaylistValue()
    {
        $url = 'https://youtu.be/dQw4w9WgXcQ?loop=1&list=PL-ignored';
        $html = $this->renderMarkdown($url);

        $this->assertStringContainsString('loop=1', $html);
        $this->assertStringContainsString('playlist=dQw4w9WgXcQ', $html);
        $this->assertStringNotContainsString('PL-ignored', $html);
    }

    public function testIframeUsesSmartDefaults()
    {
        $html = $this->renderMarkdown('https://youtu.be/dQw4w9WgXcQ');

        $this->assertStringContainsString('class="djebel-plugin-embed-youtube-iframe"', $html);
        $this->assertStringContainsString('title="YouTube video player"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('width="560"', $html);
        $this->assertStringContainsString('height="315"', $html);
        $this->assertStringContainsString('aspect-ratio: 16 / 9', $html);
        $this->assertStringContainsString('referrerpolicy="strict-origin-when-cross-origin"', $html);
        $this->assertStringContainsString('allowfullscreen', $html);
    }

    public function testIframeAttributeFilterChangesDefaults()
    {
        Dj_App_Hooks::addFilter('app.plugin.embed_youtube.iframe_attributes', [$this, 'filterIframeAttributes']);
        $html = $this->renderMarkdown('https://youtu.be/dQw4w9WgXcQ');

        $this->assertStringContainsString('loading="eager"', $html);
        $this->assertStringContainsString('data-video-id="dQw4w9WgXcQ"', $html);
        $this->assertStringNotContainsString('allowfullscreen', $html);
    }

    public function testRenderedMarkdownDoesNotDependOnStorageContext()
    {
        $content = '<p><a href="https://youtu.be/dQw4w9WgXcQ">https://youtu.be/dQw4w9WgXcQ</a></p>';
        $ctx = [
            'file' => '/tmp/other/plugin/content.md',
            'full' => 1,
        ];
        $processed_content = $this->plugin_obj->processContent($content, $ctx);

        $this->assertStringContainsString('<iframe ', $processed_content);
    }

    public function testSingleQuotedRenderedAnchorIsSupported()
    {
        $content = "<p><a href='https://youtu.be/dQw4w9WgXcQ'>https://youtu.be/dQw4w9WgXcQ</a></p>";
        $processed_content = $this->plugin_obj->processContent($content);

        $this->assertStringContainsString('<iframe ', $processed_content);
    }

    /**
     * Changes iframe defaults through the plugin's public filter.
     *
     * @param array $attributes
     * @param array $ctx
     * @return array
     */
    public function filterIframeAttributes($attributes, $ctx = [])
    {
        $attributes['loading'] = 'eager';
        $attributes['data-video-id'] = $ctx['video_id'];
        $attributes['allowfullscreen'] = false;

        return $attributes;
    }
}
