<?php

/**
 * Config class.
 */

namespace Alltube;

use Exception;
use Symfony\Component\Yaml\Yaml;

/**
 * Manage config parameters.
 */
class Config
{
    /**
     * Singleton instance.
     *
     * @var Config|null
     */
    private static $instance;

    /**
     * youtube-dl binary path.
     *
     * @var string
     */
    public $youtubedl = 'vendor/rg3/youtube-dl/youtube_dl/__main__.py';

    /**
     * python binary path.
     *
     * @var string
     */
    public $python = '/usr/bin/python';

    /**
     * youtube-dl parameters.
     *
     * @var array
     */
    public $params = ['--no-warnings', '--ignore-errors', '--flat-playlist', '--restrict-filenames', '--no-playlist'];

    /**
     * Enable audio conversion.
     *
     * @var bool
     */
    public $convert = false;

    /**
     * Enable advanced conversion mode.
     *
     * @var bool
     */
    public $convertAdvanced = false;

    /**
     * List of formats available in advanced conversion mode.
     *
     * @var array
     */
    public $convertAdvancedFormats = ['mp3', 'avi', 'flv', 'wav'];

    /**
     * avconv or ffmpeg binary path.
     *
     * @var string
     */
    public $avconv = 'vendor/bin/ffmpeg';

    /**
     * Path to the directory that contains the phantomjs binary.
     *
     * @var string
     */
    public $phantomjsDir = 'vendor/bin/';

    /**
     * Disable URL rewriting.
     *
     * @var bool
     */
    public $uglyUrls = false;

    /**
     * Stream downloaded files trough server?
     *
     * @var bool
     */
    public $stream = false;

    /**
     * Allow to remux video + audio?
     *
     * @var bool
     */
    public $remux = false;

    /**
     * MP3 bitrate when converting (in kbit/s).
     *
     * @var int
     */
    public $audioBitrate = 128;

    /**
     * avconv/ffmpeg logging level.
     * Must be one of these: quiet, panic, fatal, error, warning, info, verbose, debug.
     *
     * @var string
     */
    public $avconvVerbosity = 'error';

    /**
     * App name.
     *
     * @var string
     */
    public $appName = 'AllTube Download';

    /**
     * YAML config file path.
     *
     * @var string
     */
    private $file;

    /**
     * Generic formats supported by youtube-dl.
     *
     * @var array
     */
    public $genericFormats = [];

    /**
     * Config constructor.
     *
     * @param array $options Options
     */
    private function __construct(array $options = [])
    {
        $this->applyOptions($options);
        $this->getEnv();

        if (empty($this->genericFormats)) {
            // We don't put this in the class definition so it can be detected by xgettext.
            $this->genericFormats = [
                'best'                => _('Best'),
                'bestvideo+bestaudio' => _('Remux best video with best audio'),
                'worst'               => _('Worst'),
            ];
        }

        foreach ($this->genericFormats as $format => $name) {
            if (strpos($format, '+') !== false) {
                if (!$this->remux) {
                    // Disable combined formats if remux mode is not enabled.
                    unset($this->genericFormats[$format]);
                }
            } elseif (!$this->stream) {
                // Force HTTP if stream is not enabled.
                $this->replaceGenericFormat($format, $format . '[protocol=https]/' . $format . '[protocol=http]');
            }
        }
    }

    /**
     * Replace a format key.
     *
     * @param string $oldFormat Old format
     * @param string $newFormat New format
     *
     * @return void
     */
    private function replaceGenericFormat($oldFormat, $newFormat)
    {
        $keys = array_keys($this->genericFormats);
        $keys[array_search($oldFormat, $keys)] = $newFormat;
        if ($genericFormats = array_combine($keys, $this->genericFormats)) {
            $this->genericFormats = $genericFormats;
        }
    }

    /**
     * Throw an exception if some of the options are invalid.
     *
     * @throws Exception If youtube-dl is missing
     * @throws Exception If Python is missing
     *
     * @return void
     */
    private function validateOptions()
    {
        /*
        We don't translate these exceptions because they usually occur before Slim can catch them
        so they will go to the logs.
         */
        if (!is_file($this->youtubedl)) {
            throw new Exception("Can't find youtube-dl at " . $this->youtubedl);
        } elseif (!Video::checkCommand([$this->python, '--version'])) {
            throw new Exception("Can't find Python at " . $this->python);
        }
    }

    /**
     * Apply the provided options.
     *
     * @param array $options Options
     *
     * @return void
     */
    private function applyOptions(array $options)
    {
        foreach ($options as $option => $value) {
            if (isset($this->$option) && isset($value)) {
                $this->$option = $value;
            }
        }
    }

    /**
     * Override options from environement variables.
     * Supported environment variables: CONVERT, PYTHON, AUDIO_BITRATE.
     *
     * @return void
     */
    private function getEnv()
    {
        foreach (['CONVERT', 'PYTHON', 'AUDIO_BITRATE', 'STREAM'] as $var) {
            $env = getenv($var);
            if ($env) {
                $prop = lcfirst(str_replace('_', '', ucwords(strtolower($var), '_')));
                $this->$prop = $env;
            }
        }
    }

    /**
     * Get Config singleton instance.
     *
     * @return Config
     */
    public static function getInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Set options from a YAML file.
     *
     * @param string $file Path to the YAML file
     */
    public static function setFile($file)
    {
        if (is_file($file)) {
            $options = Yaml::parse(file_get_contents($file));
            self::$instance = new self($options);
            self::$instance->validateOptions();
        } else {
            throw new Exception("Can't find config file at " . $file);
        }
    }

    /**
     * Manually set some options.
     *
     * @param array $options Options (see `config/config.example.yml` for available options)
     * @param bool  $update  True to update an existing instance
     */
    public static function setOptions(array $options, $update = true)
    {
        if ($update) {
            $config = self::getInstance();
            $config->applyOptions($options);
            $config->validateOptions();
        } else {
            self::$instance = new self($options);
        }
    }

    /**
     * Destroy singleton instance.
     *
     * @return void
     */
    public static function destroyInstance()
    {
        self::$instance = null;
    }
}
