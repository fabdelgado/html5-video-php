<?php
/**
 * html5-video-php - ffmpeg wrapper for HTML5 videos
 *
 * Copyright (c) 2013 Sebastian Felis <sebastian@phtagr.org>
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE file
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Sebastian Felis <sebastian@phtagr.org>
 * @link          http://github.com/xemle/html5-video-php
 * @package       Html5Video
 * @since         html5-video-php v 1.0.0
 * @license       MIT License (http://www.opensource.org/licenses/mit-license.php)
 */

namespace Html5Video;

/**
 * Html5 Video converter using ffmpeg to create compatible video for common
 * mobile and desktop devices.
 */
class Html5Video {

  private $Cache;
  private $Process;
  private $defaults = array(
      /**
       * Binary of ffmpeg
       */
      'ffmpeg.bin' => 'ffmpeg',
      /**
       * Binary of ffmpeg
       */
      'qt-faststart.bin' => 'qt-faststart',
      /**
       * List of profile directories
       */
      'profile.dirs' => array(),
      /**
       * For cached values
       */
      'tmp.dir' => __DIR__
  );
  private $config;

  private $videoEncoderMap = array(
      'mp4'  => 'x264',
      'webm' => 'vpx',
      'ogg'  => 'theora'
  );
  private $audioEncoderMap = array(
      'mp4'  => 'aac',
      'webm' => 'vorbis',
      'ogg'  => 'vorbis'
  );

  /**
   * Constructor
   *
   * @param array $config Config array
   * @param object $cache Cache object to store ffmpeg settings
   * @param object $process Process object to call external ffmpeg process
   */
  function __construct($config = array(), $cache = null, $process = null) {
    $this->config = array_merge((array) $config, $this->defaults);
    $this->config['profile.dirs'] = (array) $this->config['profile.dirs'];
    $this->config['profile.dirs'][] = __DIR__ . DIRECTORY_SEPARATOR . 'Profiles';

    if ($process) {
      $this->Process = $process;
    } else {
      $this->Process = new Process\Process();
    }
    if ($cache) {
      $this->Cache = $cache;
    } else {
      $this->Cache = new Cache\FileCache($this->config['tmp.dir']);
    }
  }

  /**
   * Extract encoder names from output lines
   *
   * @param array $lines ffmpeg output lines
   * @return array List of supported encoders
   */
  protected function parseEncoder($lines) {
    $encoders = array();
    foreach ($lines as $line) {
      if (preg_match('/^\s+([A-Z .]+)\s+(\w{2,})\s+(.*)$/', $line, $m)) {
        $type = trim($m[1]);
        if (strpos($type, 'E') !== false) {
          $encoder = trim($m[2]);
          if (strpos($encoder, ',') !== false) {
            foreach (split(',', $encoder) as $e) {
              $encoders[] = $e;
            }
          } else {
            $encoders[] = $encoder;
          }
        }
      }
    }

    sort($encoders);
    return $encoders;
  }

  /**
   * Parse streaming informations
   *
   * @param array $lines
   * @return array
   */
  protected function parseInfo($lines) {
    $result = array('videoStreams' => 0, 'audioStreams' => 0);
    foreach ($lines as $line) {
      if (preg_match('/Duration:\s+(\d\d):(\d\d):(\d\d\.\d+)/', $line, $m)) {
        $result['duration'] = $m[1] * 3600 + $m[2] * 60 + $m[3];
      } else if (preg_match('/\s+Stream #(\d)+.*$/', $line, $sm)) {
        if (strpos($line, 'Video:')) {
          $result['videoStreams']++;
          $words = preg_split('/,?\s+/', trim($line));
          for ($i = 0; $i < count($words); $i++) {
            if (preg_match('/(\d+)x(\d+)/', $words[$i], $m)) {
              $result['width'] = $m[1];
              $result['height'] = $m[2];
            }
          }
        } else if (strpos($line, 'Audio:')) {
          $result['audioStreams']++;
        }
      }
    }
    return $result;
  }

  /**
   * Compare two versions
   *
   * @param array $version Version array
   * @param array $other Version array
   * @return boolean True if first version is greater or equal to other version
   */
  protected function isVersionIsGreaterOrEqual($version, $other) {
    $max = min(count($version), count($other));
    for ($i = 0; $i < $max; $i++) {
      if ($version[$i] < $other[$i]) {
        return false;
      }
    }
    return true;
  }

  /**
   * Search for matching encoder
   *
   * @param string $needle Encoder type
   * @return mixed
   */
  protected function searchEncoder($needle) {
    $encoders = $this->getEncoders();
    foreach ($encoders as $encoder) {
      if (strpos($encoder, $needle) !== false) {
        return $encoder;
      }
    }
    return false;
  }

  /**
   * Get current ffmpeg driver
   *
   * @return object FfmpegDriver
   */
  protected function getDriver() {
    $version = $this->getVersion();
    if ($this->isVersionIsGreaterOrEqual($version, array(0, 11, 0))) {
      return new Driver\FfmpegDriver();
    } else if ($this->isVersionIsGreaterOrEqual($version, array(0, 9, 0))) {
      return new Driver\FfmpegDriver10();
    } else if ($this->isVersionIsGreaterOrEqual($version, array(0, 8, 0))) {
      return new Driver\FfmpegDriver08();
    } else {
      return new Driver\FfmpegDriver06();
    }
  }

  /**
   * Create a video convert for given profile and target container
   *
   * @param string $targetFormat Target container format
   * @param string $profileName Profile name
   * @return \Html5Video\Converter\Mp4Converter|\Html5Video\Converter\GenericConverter
   * @throws \Exception
   */
  protected function createConverter($targetFormat, $profileName) {
    $profile = $this->getProfile($profileName);

    if (!isset($this->videoEncoderMap[$targetFormat]) || !isset($this->audioEncoderMap[$targetFormat])) {
      throw new \Exception("Unsupported target video container");
    }

    $videoEncoder = $this->searchEncoder($this->videoEncoderMap[$targetFormat]);
    if (!$videoEncoder) {
      throw new \Exception("Video encoder not found for ${$this->videoEncoderMap[$targetFormat]}");
    }
    $audioEncoder = $this->searchEncoder($this->audioEncoderMap[$targetFormat]);
    if (!$audioEncoder) {
      throw new \Exception("Audio encoder not found for ${$this->audioEncoderMap[$targetFormat]}");
    }

    if ($targetFormat == 'mp4') {
      return new Converter\Mp4Converter($this->Process, $this->getDriver(), $this->config, $profile, $videoEncoder, $audioEncoder);
    }
    return new Converter\GenericConverter($this->Process, $this->getDriver(), $this->config, $profile, $videoEncoder, $audioEncoder);
  }

  /**
   * If no width and height are given in the option read the video
   * file and set width and height from the souce
   *
   * @param string $src Source video filename
   * @param array $options Convert options
   */
  protected function mergeOptions($src, &$options) {
    if (!isset($options['width']) && !isset($options['height'])) {
      $info = $this->getVideoInfo($src);
      $options['width'] = $info['width'];
      $options['height'] = $info['height'];
      if (!$info['audioStreams']) {
        $options['audio'] = false;
      }
    }
  }
  
  /**
   * Get current version of ffmpeg
   *
   * @return array Version array(major, minor, patch)
   */
  public function getVersion() {
    $version = $this->Cache->read('version', null);
    if ($version !== null) {
      return $version;
    }
    $version = false;

    $lines = array();
    $result = $this->Process->run($this->config['ffmpeg.bin'], array('-version'), $lines);
    if ($result == 0 && $lines && preg_match('/^\w+\s(version\s)?(\d+)\.(\d+)\.(\d+).*/', $lines[0], $m)) {
      $version = array($m[2], $m[3], $m[4]);
    }
    $this->Cache->write('version', $version);
    return $version;
  }

  /**
   * Get supported encoder names
   *
   * @return array Sorted list of supported encoders
   */
  public function getEncoders() {
    $encoders = $this->Cache->read('encoders');
    if ($encoders !== null) {
      return $encoders;
    }

    $args = array('-codecs');
    if (!$this->isVersionIsGreaterOrEqual($this->getVersion(), array(0, 8))) {
      $args = array('-formats');
    }
    $lines = array();
    $errCode = $this->Process->run($this->config['ffmpeg.bin'], $args, $lines);
    if (!count($lines)) {
      return array();
    }

    $encoders = $this->parseEncoder($lines);
    $this->Cache->write('encoders', $encoders);
    return $encoders;
  }

  /**
   * Read the video profile in given profile directories
   *
   * @param string $name Profile name
   * @return object Profile
   * @throws Exception
   */
  public function getProfile($name) {
    $dirs = $this->config['profile.dirs'];
    foreach ($dirs as $dir) {
      if (!is_dir($dir) || !is_readable($dir)) {
        continue;
      }
      $filename = $dir . DIRECTORY_SEPARATOR . $name . '.profile';
      if (is_readable($filename)) {
        $content = file_get_contents($filename);
        $json = json_decode($content);
        return $json;
      }
    }
    throw new \Exception("Profile $name not found");
  }

  /**
   * List all available profiles
   *
   * @return array List of available profiles
   */
  public function listProfiles() {
    $dirs = $this->config['profile.dirs'];
    $profiles = array();
    foreach ($dirs as $dir) {
      if (!is_dir($dir) || !is_readable($dir)) {
        continue;
      }
      $files = scandir($dir);
      foreach ($files as $file) {
        if (preg_match('/(.*)\.profile$/', $file, $m)) {
           $profiles[] = $m[1];
        }
      }
    }
    return $profiles;
  }

  /**
   * Get information about a video file
   *
   * @param string $src Video filename
   * @return mixed False on error
   */
  public function getVideoInfo($src) {
    $lines = array();
    if (!is_readable($src)) {
      throw new \Exception("Source file '$src' is not readable");
    }
    $this->Process->run($this->config['ffmpeg.bin'], array('-i', $src), $lines);
    if (count($lines)) {
      return $this->parseInfo($lines);
    }
    return false;
  }
  
  /**
   * Convert a given video to html5 video
   *
   * @param string $src Source filename
   * @param string $dst Destination filename
   * @param string $targetFormat Target container format
   * @param string $profileName Profile name
   * @param array $options Additional options
   * @return mixed
   */
  public function create($src, $dst, $targetFormat, $profileName, $options = array()) {
    $this->mergeOptions($src, $options);
    $converter = $this->createConverter($targetFormat, $profileName);
    $result = $converter->create($src, $dst, $options);
    return $result;
  }

}