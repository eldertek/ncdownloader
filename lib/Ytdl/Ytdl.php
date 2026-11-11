<?php

namespace OCA\NCDownloader\Ytdl;

use OCA\NCDownloader\Tools\Helper;
use OCA\NCDownloader\Tools\UrlValidator;
use OCA\NCDownloader\Ytdl\Helper as YtdHelper;
use Symfony\Component\Process\Process;

class Ytdl
{
    public $audioOnly = 0;
    public $audioFormat = 'm4a';
    public $videoFormat = null;
    public $dbDlPath = null;
    private string $format = 'bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]/best';
    private array $options = [];
    private string $downloadDir = '/tmp/downloads';
    private int $timeout = 36000;
    private string $outTpl = '%(title).64s.%(ext)s';
    private string $defaultDir = '/tmp/downloads';
    private array $env = [];
    private string $bin = '';
    public ?YtdHelper $helper = null;

    public function __construct(array $options)
    {
        $options += ['downloadDir' => '/tmp/downloads', 'settings' => []];
        $this->init($options);
    }

    private function resolveBinary(?string $configuredBinary = null): string
    {
        if (is_string($configuredBinary) && trim($configuredBinary) !== '' && @is_file(trim($configuredBinary))) {
            return trim($configuredBinary);
        }

        $bundledYtDlp = __DIR__ . '/../../bin/yt-dlp';
        $bundledYoutubeDl = __DIR__ . '/../../bin/youtube-dl';

        $sysYtDlp = Helper::findBinaryPath('yt-dlp');
        if ($sysYtDlp && @is_file($sysYtDlp)) {
            return $sysYtDlp;
        }
        if (@is_file($bundledYtDlp)) {
            return $bundledYtDlp;
        }

        $sysYoutubeDl = Helper::findBinaryPath('youtube-dl');
        if ($sysYoutubeDl && @is_file($sysYoutubeDl)) {
            return $sysYoutubeDl;
        }
        if (@is_file($bundledYoutubeDl)) {
            return $bundledYoutubeDl;
        }

        return $bundledYtDlp;
    }

    public function init(array $options): void
    {
        $binary = $options['binary'] ?? null;
        $downloadDir = (string) ($options['downloadDir'] ?? $this->defaultDir);
        $settings = is_array($options['settings'] ?? null) ? $options['settings'] : [];

        $this->bin = $this->resolveBinary(is_string($binary) ? $binary : null);
        if ($this->isInstalled() && !$this->isExecutable()) {
            @chmod($this->bin, 0755);
        }
        $this->setDownloadDir($downloadDir);

        $allowedKeys = Options::get();
        foreach ($settings as $key => $value) {
            $cleanKey = ltrim((string) $key, '-');
            if (!in_array($cleanKey, $allowedKeys, true)) {
                continue;
            }
            if ($value === '' || $value === null || $value === true) {
                $this->addOption($cleanKey, true);
            } elseif (is_scalar($value)) {
                $this->setOption($cleanKey, (string) $value, true);
            }
        }

        $lang = (string) getenv('LANG');
        if ($lang === '' || stripos($lang, 'utf-8') === false) {
            $lang = 'C.UTF-8';
        }
        $this->setEnv('LANG', $lang);
        $this->addOption('--no-config');
        $this->addOption('--no-mtime');
        $this->addOption('--ignore-errors');
    }

    public function setEnv(string $key, string $val): void
    {
        $this->env[$key] = $val;
    }

    public function audioMode(): self
    {
        if (Helper::ffmpegInstalled()) {
            $this->addOption('--extract-audio');
        } else {
            $this->audioFormat = 'm4a';
        }
        $this->setAudioFormat((string) $this->audioFormat);
        return $this;
    }

    public function setAudioQuality($value = 0): void
    {
        $this->setOption('--audio-quality', (string) $value);
    }

    public function setAudioFormat(string $format): void
    {
        $this->setOption('--audio-format', $format);
    }

    public function setVideoFormat(string $format): void
    {
        $this->setOption('--recode-video', $format);
    }

    public function GetUrlOnly(): self
    {
        $this->addOption('--get-filename');
        $this->addOption('--get-url');
        return $this;
    }

    public static function create(array $options): self
    {
        return new self($options);
    }

    public function setDownloadDir(string $dir): void
    {
        $this->downloadDir = rtrim($dir !== '' ? $dir : $this->defaultDir, '/');
    }

    public function getDownloadDir(): string
    {
        return $this->downloadDir;
    }

    public function prependOption(string $option): void
    {
        array_unshift($this->options, $option);
    }

    public function download(string $url): array
    {
        $check = UrlValidator::validate($url, ['http', 'https']);
        if (!$check['valid']) {
            return ['error' => $check['error'] ?? 'Invalid URL'];
        }
        if (!$this->isInstalled()) {
            return ['error' => 'yt-dlp / youtube-dl binary is not installed'];
        }

        $args = $this->options;
        if ($this->audioOnly) {
            if (Helper::ffmpegInstalled()) {
                $args[] = '--extract-audio';
            } else {
                $this->audioFormat = 'm4a';
            }
            $args[] = '--audio-format';
            $args[] = (string) $this->audioFormat;
        } else {
            if (Helper::ffmpegInstalled() && $this->videoFormat) {
                $args[] = '--format';
                $args[] = 'bestvideo+bestaudio/best';
                $args[] = '--recode-video';
                $args[] = (string) $this->videoFormat;
            } else {
                $args[] = '--format';
                $args[] = $this->format;
            }
        }

        $this->helper = YtdHelper::create();
        $targetDir = $this->downloadDir !== '' ? $this->downloadDir : $this->defaultDir;
        $args[] = '--output';
        $args[] = $targetDir . '/' . $this->outTpl;
        $args[] = '--';
        $args[] = $url;
        array_unshift($args, $this->bin);

        $process = new Process($args, null, $this->env);
        $process->setTimeout($this->timeout);
        $data = [
            'link' => $url,
            'path' => $this->dbDlPath,
            'ext' => $this->audioOnly ? $this->audioFormat : $this->videoFormat,
        ];

        $process->run(function ($type, $buffer) use ($data, $process) {
            if (Process::ERR === $type) {
                $this->onError($buffer);
            } else {
                $data['pid'] = $process->getPid();
                $this->onOutput($buffer, $data);
            }
        });

        if ($process->isSuccessful()) {
            $this->helper->updateStatus(Helper::STATUS['COMPLETE']);
            return [
                'message' => $this->helper->file ?? trim($process->getOutput()),
                'filename' => $this->helper->file,
            ];
        }
        $this->helper->updateStatus(Helper::STATUS['ERROR']);
        return ['error' => trim($process->getErrorOutput()) ?: 'Media download failed'];
    }

    private function onError(string $buffer): void
    {
        if ($this->helper !== null) {
            $this->helper->log($buffer);
        }
    }

    public function onOutput(string $buffer, array $extra): void
    {
        if ($this->helper !== null) {
            $this->helper->run($buffer, $extra);
        }
    }

    public function getDownloadUrl(string $url): array
    {
        $check = UrlValidator::validate($url, ['http', 'https']);
        if (!$check['valid'] || !$this->isInstalled()) {
            return [];
        }

        $args = array_merge($this->options, ['--get-filename', '--get-url', '--', $url]);
        array_unshift($args, $this->bin);

        $process = new Process($args, null, $this->env);
        $process->setTimeout(60);
        $process->run();
        if (!$process->isSuccessful()) {
            return [];
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", trim($process->getOutput())))));
        if (count($lines) === 0) {
            return [];
        }
        if (count($lines) === 1) {
            return ['url' => $lines[0]];
        }
        [$resolvedUrl, $filename] = $lines;
        $filename = Helper::cleanString($filename);
        return ['url' => $resolvedUrl, 'filename' => Helper::clipFilename($filename)];
    }

    public function setUrl(string $url): void
    {
        $this->options[] = '--';
        $this->options[] = $url;
    }

    public function setOption(string $key, $value, bool $hyphens = false): self
    {
        $this->addOption($key, $hyphens);
        $this->addOption((string) $value, false);
        return $this;
    }

    public function addOption(string $option, bool $hyphens = false): void
    {
        if ($hyphens && !str_starts_with($option, '--')) {
            $option = '--' . $option;
        }
        $this->options[] = $option;
    }

    protected function hasOption(string $name)
    {
        return array_search($name, $this->options, true);
    }

    public function forceIPV4(): self
    {
        $this->addOption('force-ipv4', true);
        return $this;
    }

    public function isInstalled(): bool
    {
        return $this->bin !== '' && @is_file($this->bin);
    }

    public function isExecutable(): bool
    {
        return $this->bin !== '' && @is_executable($this->bin);
    }

    public function isReadable(): bool
    {
        return $this->bin !== '' && @is_readable($this->bin);
    }

    public function getBin(): string
    {
        return $this->bin;
    }

    public function version()
    {
        if (!$this->isInstalled()) {
            return false;
        }
        $process = new Process([$this->bin, '--version']);
        $process->setTimeout(15);
        $process->run();
        if ($process->isSuccessful()) {
            return trim($process->getOutput());
        }
        return false;
    }

    public function check(): array
    {
        if ($tagName = Helper::getLatestRelease('yt-dlp', 'yt-dlp')) {
            $tagName = Helper::removeLetters($tagName);
            $version = $this->version();
            if ($version && version_compare($version, $tagName, '<')) {
                return ['status' => true, 'message' => $tagName];
            }
        }
        return ['status' => false, 'message' => 'No update available'];
    }

    public function update(): array
    {
        $file = __DIR__ . '/../../bin/yt-dlp';
        try {
            Helper::downloadLatestRelease('yt-dlp', 'yt-dlp', $file);
            @chmod($file, 0755);
            $this->bin = $file;
        } catch (\Throwable $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }
        return ['status' => true, 'message' => 'Updated to latest version', 'data' => $this->version()];
    }
}
