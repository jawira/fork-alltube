<?php

/**
 * DownloadControllerTest class.
 */

namespace Alltube\Test;

use Alltube\Config;
use Alltube\Controller\DownloadController;

/**
 * Unit tests for the FrontController class.
 */
class DownloadControllerTest extends ControllerTest
{
    /**
     * Prepare tests.
     */
    protected function setUp()
    {
        parent::setUp();

        $this->controller = new DownloadController($this->container);
    }

    /**
     * Test the download() function without the URL parameter.
     *
     * @return void
     */
    public function testDownloadWithoutUrl()
    {
        $this->assertRequestIsRedirect('download');
    }

    /**
     * Test the download() function.
     *
     * @return void
     */
    public function testDownload()
    {
        $this->assertRequestIsRedirect('download', ['url' => 'https://www.youtube.com/watch?v=M7IpKCZ47pU']);
    }

    /**
     * Test the download() function with a specific format.
     *
     * @return void
     */
    public function testDownloadWithFormat()
    {
        $this->assertRequestIsRedirect(
            'download',
            ['url' => 'https://www.youtube.com/watch?v=M7IpKCZ47pU', 'format' => 'worst']
        );
    }

    /**
     * Test the download() function with streams enabled.
     *
     * @return void
     */
    public function testDownloadWithStream()
    {
        Config::setOptions(['stream' => true]);

        $this->assertRequestIsOk(
            'download',
            ['url' => 'https://www.youtube.com/watch?v=M7IpKCZ47pU', 'stream' => true]
        );
    }

    /**
     * Test the download() function with an M3U stream.
     *
     * @return void
     */
    public function testDownloadWithM3uStream()
    {
        if (getenv('CI')) {
            $this->markTestSkipped('Twitter returns a 429 error when the test is ran too many times.');
        }

        Config::setOptions(['stream' => true]);

        $this->assertRequestIsOk(
            'download',
            [
                'url'    => 'https://twitter.com/verge/status/813055465324056576/video/1',
                'format' => 'hls-2176',
                'stream' => true,
            ]
        );
    }

    /**
     * Test the download() function with an RTMP stream.
     *
     * @return void
     */
    public function testDownloadWithRtmpStream()
    {
        $this->markTestIncomplete('We need to find another RTMP video.');

        Config::setOptions(['stream' => true]);

        $this->assertRequestIsOk(
            'download',
            ['url' => 'http://www.rtvnh.nl/video/131946', 'format' => 'rtmp-264']
        );
    }

    /**
     * Test the download() function with a remuxed video.
     *
     * @return void
     */
    public function testDownloadWithRemux()
    {
        Config::setOptions(['remux' => true]);

        $this->assertRequestIsOk(
            'download',
            [
                'url'    => 'https://www.youtube.com/watch?v=M7IpKCZ47pU',
                'format' => 'bestvideo+bestaudio',
            ]
        );
    }

    /**
     * Test the download() function with a remuxed video but remux disabled.
     *
     * @return void
     */
    public function testDownloadWithRemuxDisabled()
    {
        $this->assertRequestIsServerError(
            'download',
            [
                'url'    => 'https://www.youtube.com/watch?v=M7IpKCZ47pU',
                'format' => 'bestvideo+bestaudio',
            ]
        );
    }

    /**
     * Test the download() function with a missing password.
     *
     * @return void
     */
    public function testDownloadWithMissingPassword()
    {
        if (getenv('CI')) {
            $this->markTestSkipped('Travis is blacklisted by Vimeo.');
        }
        $this->assertRequestIsRedirect('download', ['url' => 'http://vimeo.com/68375962']);
    }

    /**
     * Test the download() function with an error.
     *
     * @return void
     */
    public function testDownloadWithError()
    {
        $this->assertRequestIsServerError('download', ['url' => 'http://example.com/foo']);
    }

    /**
     * Test the download() function with an video that returns an empty URL.
     * This can be caused by trying to redirect to a playlist.
     *
     * @return void
     */
    public function testDownloadWithEmptyUrl()
    {
        $this->assertRequestIsServerError(
            'download',
            ['url' => 'https://www.youtube.com/playlist?list=PLgdySZU6KUXL_8Jq5aUkyNV7wCa-4wZsC']
        );
    }

    /**
     * Test the download() function with a playlist stream.
     *
     * @return void
     * @requires OS Linux
     */
    public function testDownloadWithPlaylist()
    {
        Config::setOptions(['stream' => true]);

        $this->assertRequestIsOk(
            'download',
            ['url' => 'https://www.youtube.com/playlist?list=PLgdySZU6KUXL_8Jq5aUkyNV7wCa-4wZsC']
        );
    }

    /**
     * Test the download() function with an advanced conversion.
     *
     * @return void
     */
    public function testDownloadWithAdvancedConversion()
    {
        Config::setOptions(['convertAdvanced' => true]);

        $this->assertRequestIsOk(
            'download',
            [
                'url'           => 'https://www.youtube.com/watch?v=M7IpKCZ47pU',
                'format'        => 'best',
                'customConvert' => 'on',
                'customBitrate' => 32,
                'customFormat'  => 'flv',
            ]
        );
    }
}
