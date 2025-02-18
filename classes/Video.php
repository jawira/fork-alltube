<?php

/**
 * VideoDownload class.
 */

namespace Alltube;

use Alltube\Exception\EmptyUrlException;
use Alltube\Exception\PasswordException;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use stdClass;
use Symfony\Component\Process\Process;

/**
 * Extract info about videos.
 *
 * Due to the way youtube-dl behaves, this class can also contain information about a playlist.
 *
 * @property-read string      $title         Title
 * @property-read string      $protocol      Network protocol (HTTP, RTMP, etc.)
 * @property-read string      $url           File URL
 * @property-read string      $ext           File extension
 * @property-read string      $extractor_key youtube-dl extractor class used
 * @property-read array       $entries       List of videos (if the object contains information about a playlist)
 * @property-read array       $rtmp_conn
 * @property-read string|null $_type         Object type (usually "playlist" or null)
 * @property-read stdClass    $downloader_options
 * @property-read stdClass    $http_headers
 */
class Video
{
    /**
     * Config instance.
     *
     * @var Config
     */
    private $config;

    /**
     * URL of the page containing the video.
     *
     * @var string
     */
    private $webpageUrl;

    /**
     * Requested video format.
     *
     * @var string
     */
    private $requestedFormat;

    /**
     * Password.
     *
     * @var string|null
     */
    private $password;

    /**
     * JSON object returned by youtube-dl.
     *
     * @var stdClass
     */
    private $json;

    /**
     * URLs of the video files.
     *
     * @var array
     */
    private $urls;

    /**
     * VideoDownload constructor.
     *
     * @param string $webpageUrl      URL of the page containing the video
     * @param string $requestedFormat Requested video format
     * @param string $password        Password
     */
    public function __construct($webpageUrl, $requestedFormat = 'best', $password = null)
    {
        $this->webpageUrl = $webpageUrl;
        $this->requestedFormat = $requestedFormat;
        $this->password = $password;
        $this->config = Config::getInstance();
    }

    /**
     * Return a youtube-dl process with the specified arguments.
     *
     * @param string[] $arguments Arguments
     *
     * @return Process
     */
    private static function getProcess(array $arguments)
    {
        $config = Config::getInstance();

        return new Process(
            array_merge(
                [$config->python, $config->youtubedl],
                $config->params,
                $arguments
            )
        );
    }

    /**
     * List all extractors.
     *
     * @return string[] Extractors
     * */
    public static function getExtractors()
    {
        return explode("\n", trim(self::callYoutubedl(['--list-extractors'])));
    }

    /**
     * Call youtube-dl.
     *
     * @param array $arguments Arguments
     *
     * @throws PasswordException If the video is protected by a password and no password was specified
     * @throws Exception         If the password is wrong
     * @throws Exception         If youtube-dl returns an error
     *
     * @return string Result
     */
    private static function callYoutubedl(array $arguments)
    {
        $config = Config::getInstance();

        $process = self::getProcess($arguments);
        //This is needed by the openload extractor because it runs PhantomJS
        $process->setEnv(['PATH' => $config->phantomjsDir]);
        $process->inheritEnvironmentVariables();
        $process->run();
        if (!$process->isSuccessful()) {
            $errorOutput = trim($process->getErrorOutput());
            $exitCode = $process->getExitCode();
            if ($errorOutput == 'ERROR: This video is protected by a password, use the --video-password option') {
                throw new PasswordException($errorOutput, $exitCode);
            } elseif (substr($errorOutput, 0, 21) == 'ERROR: Wrong password') {
                throw new Exception(_('Wrong password'), $exitCode);
            } else {
                throw new Exception($errorOutput, $exitCode);
            }
        } else {
            return trim($process->getOutput());
        }
    }

    /**
     * Get a property from youtube-dl.
     *
     * @param string $prop Property
     *
     * @return string
     */
    private function getProp($prop = 'dump-json')
    {
        $arguments = ['--' . $prop];

        if (isset($this->webpageUrl)) {
            $arguments[] = $this->webpageUrl;
        }
        if (isset($this->requestedFormat)) {
            $arguments[] = '-f';
            $arguments[] = $this->requestedFormat;
        }
        if (isset($this->password)) {
            $arguments[] = '--video-password';
            $arguments[] = $this->password;
        }

        return $this::callYoutubedl($arguments);
    }

    /**
     * Get all information about a video.
     *
     * @return stdClass Decoded JSON
     * */
    public function getJson()
    {
        if (!isset($this->json)) {
            $this->json = json_decode($this->getProp('dump-single-json'));
        }

        return $this->json;
    }

    /**
     * Magic method to get a property from the JSON object returned by youtube-dl.
     *
     * @param string $name Property
     *
     * @return mixed
     */
    public function __get($name)
    {
        if (isset($this->$name)) {
            return $this->getJson()->$name;
        }
    }

    /**
     * Magic method to check if the JSON object returned by youtube-dl has a property.
     *
     * @param string $name Property
     *
     * @return bool
     */
    public function __isset($name)
    {
        return isset($this->getJson()->$name);
    }

    /**
     * Get URL of video from URL of page.
     *
     * It generally returns only one URL.
     * But it can return two URLs when multiple formats are specified
     * (eg. bestvideo+bestaudio).
     *
     * @return string[] URLs of video
     * */
    public function getUrl()
    {
        // Cache the URLs.
        if (!isset($this->urls)) {
            $this->urls = explode("\n", $this->getProp('get-url'));

            if (empty($this->urls[0])) {
                throw new EmptyUrlException(_('youtube-dl returned an empty URL.'));
            }
        }

        return $this->urls;
    }

    /**
     * Get filename of video file from URL of page.
     *
     * @return string Filename of extracted video
     * */
    public function getFilename()
    {
        return trim($this->getProp('get-filename'));
    }

    /**
     * Get filename of video with the specified extension.
     *
     * @param string $extension New file extension
     *
     * @return string Filename of extracted video with specified extension
     */
    public function getFileNameWithExtension($extension)
    {
        return html_entity_decode(
            pathinfo(
                $this->getFilename(),
                PATHINFO_FILENAME
            ) . '.' . $extension,
            ENT_COMPAT,
            'ISO-8859-1'
        );
    }

    /**
     * Return arguments used to run rtmp for a specific video.
     *
     * @return array Arguments
     */
    private function getRtmpArguments()
    {
        $arguments = [];

        if ($this->protocol == 'rtmp') {
            foreach (
                [
                'url'           => '-rtmp_tcurl',
                'webpage_url'   => '-rtmp_pageurl',
                'player_url'    => '-rtmp_swfverify',
                'flash_version' => '-rtmp_flashver',
                'play_path'     => '-rtmp_playpath',
                'app'           => '-rtmp_app',
                ] as $property => $option
            ) {
                if (isset($this->{$property})) {
                    $arguments[] = $option;
                    $arguments[] = $this->{$property};
                }
            }

            if (isset($this->rtmp_conn)) {
                foreach ($this->rtmp_conn as $conn) {
                    $arguments[] = '-rtmp_conn';
                    $arguments[] = $conn;
                }
            }
        }

        return $arguments;
    }

    /**
     * Check if a command runs successfully.
     *
     * @param array $command Command and arguments
     *
     * @return bool False if the command returns an error, true otherwise
     */
    public static function checkCommand(array $command)
    {
        $process = new Process($command);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Get a process that runs avconv in order to convert a video.
     *
     * @param int    $audioBitrate Audio bitrate of the converted file
     * @param string $filetype     Filetype of the converted file
     * @param bool   $audioOnly    True to return an audio-only file
     * @param string $from         Start the conversion at this time
     * @param string $to           End the conversion at this time
     *
     * @throws Exception If avconv/ffmpeg is missing
     *
     * @return Process Process
     */
    private function getAvconvProcess(
        $audioBitrate,
        $filetype = 'mp3',
        $audioOnly = true,
        $from = null,
        $to = null
    ) {
        if (!$this->checkCommand([$this->config->avconv, '-version'])) {
            throw new Exception(_('Can\'t find avconv or ffmpeg at ') . $this->config->avconv . '.');
        }

        $durationRegex = '/(\d+:)?(\d+:)?(\d+)/';

        $afterArguments = [];

        if ($audioOnly) {
            $afterArguments[] = '-vn';
        }

        if (!empty($from)) {
            if (!preg_match($durationRegex, $from)) {
                throw new Exception(_('Invalid start time: ') . $from . '.');
            }
            $afterArguments[] = '-ss';
            $afterArguments[] = $from;
        }
        if (!empty($to)) {
            if (!preg_match($durationRegex, $to)) {
                throw new Exception(_('Invalid end time: ') . $to . '.');
            }
            $afterArguments[] = '-to';
            $afterArguments[] = $to;
        }

        $urls = $this->getUrl();

        $arguments = array_merge(
            [
                $this->config->avconv,
                '-v', $this->config->avconvVerbosity,
            ],
            $this->getRtmpArguments(),
            [
                '-i', $urls[0],
                '-f', $filetype,
                '-b:a', $audioBitrate . 'k',
            ],
            $afterArguments,
            [
                'pipe:1',
            ]
        );

        //Vimeo needs a correct user-agent
        $arguments[] = '-user_agent';
        $arguments[] = $this->getProp('dump-user-agent');

        return new Process($arguments);
    }

    /**
     * Get audio stream of converted video.
     *
     * @param string $from Start the conversion at this time
     * @param string $to   End the conversion at this time
     *
     * @throws Exception If your try to convert an M3U8 video
     * @throws Exception If the popen stream was not created correctly
     *
     * @return resource popen stream
     */
    public function getAudioStream($from = null, $to = null)
    {
        if (isset($this->_type) && $this->_type == 'playlist') {
            throw new Exception(_('Conversion of playlists is not supported.'));
        }

        if (isset($this->protocol)) {
            if (in_array($this->protocol, ['m3u8', 'm3u8_native'])) {
                throw new Exception(_('Conversion of M3U8 files is not supported.'));
            } elseif ($this->protocol == 'http_dash_segments') {
                throw new Exception(_('Conversion of DASH segments is not supported.'));
            }
        }

        $avconvProc = $this->getAvconvProcess($this->config->audioBitrate, 'mp3', true, $from, $to);

        $stream = popen($avconvProc->getCommandLine(), 'r');

        if (!is_resource($stream)) {
            throw new Exception(_('Could not open popen stream.'));
        }

        return $stream;
    }

    /**
     * Get video stream from an M3U playlist.
     *
     * @throws Exception If avconv/ffmpeg is missing
     * @throws Exception If the popen stream was not created correctly
     *
     * @return resource popen stream
     */
    public function getM3uStream()
    {
        if (!$this->checkCommand([$this->config->avconv, '-version'])) {
            throw new Exception(_('Can\'t find avconv or ffmpeg at ') . $this->config->avconv . '.');
        }

        $urls = $this->getUrl();

        $process = new Process(
            [
                $this->config->avconv,
                '-v', $this->config->avconvVerbosity,
                '-i', $urls[0],
                '-f', $this->ext,
                '-c', 'copy',
                '-bsf:a', 'aac_adtstoasc',
                '-movflags', 'frag_keyframe+empty_moov',
                'pipe:1',
            ]
        );

        $stream = popen($process->getCommandLine(), 'r');
        if (!is_resource($stream)) {
            throw new Exception(_('Could not open popen stream.'));
        }

        return $stream;
    }

    /**
     * Get an avconv stream to remux audio and video.
     *
     * @throws Exception If the popen stream was not created correctly
     *
     * @return resource popen stream
     */
    public function getRemuxStream()
    {
        $urls = $this->getUrl();

        if (!isset($urls[0]) || !isset($urls[1])) {
            throw new Exception(_('This video does not have two URLs.'));
        }

        $process = new Process(
            [
                $this->config->avconv,
                '-v', $this->config->avconvVerbosity,
                '-i', $urls[0],
                '-i', $urls[1],
                '-c', 'copy',
                '-map', '0:v:0 ',
                '-map', '1:a:0',
                '-f', 'matroska',
                'pipe:1',
            ]
        );

        $stream = popen($process->getCommandLine(), 'r');
        if (!is_resource($stream)) {
            throw new Exception(_('Could not open popen stream.'));
        }

        return $stream;
    }

    /**
     * Get video stream from an RTMP video.
     *
     * @throws Exception If the popen stream was not created correctly
     *
     * @return resource popen stream
     */
    public function getRtmpStream()
    {
        $urls = $this->getUrl();

        $process = new Process(
            array_merge(
                [
                    $this->config->avconv,
                    '-v', $this->config->avconvVerbosity,
                ],
                $this->getRtmpArguments(),
                [
                    '-i', $urls[0],
                    '-f', $this->ext,
                    'pipe:1',
                ]
            )
        );
        $stream = popen($process->getCommandLine(), 'r');
        if (!is_resource($stream)) {
            throw new Exception(_('Could not open popen stream.'));
        }

        return $stream;
    }

    /**
     * Get the stream of a converted video.
     *
     * @param int    $audioBitrate Audio bitrate of the converted file
     * @param string $filetype     Filetype of the converted file
     *
     * @throws Exception If your try to convert and M3U8 video
     * @throws Exception If the popen stream was not created correctly
     *
     * @return resource popen stream
     */
    public function getConvertedStream($audioBitrate, $filetype)
    {
        if (in_array($this->protocol, ['m3u8', 'm3u8_native'])) {
            throw new Exception(_('Conversion of M3U8 files is not supported.'));
        }

        $avconvProc = $this->getAvconvProcess($audioBitrate, $filetype, false);

        $stream = popen($avconvProc->getCommandLine(), 'r');

        if (!is_resource($stream)) {
            throw new Exception(_('Could not open popen stream.'));
        }

        return $stream;
    }

    /**
     * Get the same video but with another format.
     *
     * @param string $format New format
     *
     * @return Video
     */
    public function withFormat($format)
    {
        return new self($this->webpageUrl, $format, $this->password);
    }

    /**
     * Get a HTTP response containing the video.
     *
     * @param array $headers HTTP headers of the request
     *
     * @return Response
     */
    public function getHttpResponse(array $headers = [])
    {
        $client = new Client();
        $urls = $this->getUrl();

        return $client->request('GET', $urls[0], ['stream' => true, 'headers' => $headers]);
    }
}
