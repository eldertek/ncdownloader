<?php

namespace OCA\NCDownloader\Aria2;

use OCA\NCDownloader\Tools\Helper;
use OCA\NCDownloader\Tools\UrlValidator;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class Aria2
{
    private array $config = [];
    private array $options = [];
    private ?string $token = null;
    public ?string $methodName = null;
    private ?string $bin = null;
    private array $dataFilter = [
        'status',
        'followedBy',
        'totalLength',
        'errorMessage',
        'dir',
        'uploadLength',
        'completedLength',
        'downloadSpeed',
        'files',
        'numSeeders',
        'connections',
        'gid',
        'following',
        'bittorrent',
    ];
    private bool $filterResponse = true;
    private string $downloadDir = '/tmp/Downloads';
    private string $confDir = '/tmp/aria2';
    private string $sessionFile = '';
    private string $rpcUrl = '';
    private ?string $php = null;
    private $ch = null;
    private ?string $onDownloadStart = null;
    private ?string $content = null;
    private string $torrentsDir = '/tmp/Torrents';

    private function resolveBinaryPath(?string $binary): ?string
    {
        if (!empty($binary)) {
            if (@is_file($binary)) {
                return $binary;
            }
            if ($resolved = Helper::findBinaryPath($binary)) {
                return $resolved;
            }
            if (basename($binary) === 'aria2') {
                if ($resolved = Helper::findBinaryPath('aria2c')) {
                    return $resolved;
                }
            }
        }
        return Helper::findBinaryPath('aria2c', __DIR__ . '/../../bin/aria2c');
    }

    private function ensureBinaryPath(): void
    {
        if (empty($this->bin) || !@is_file($this->bin)) {
            $this->bin = $this->resolveBinaryPath($this->bin);
        }
    }

    public function __construct(array $options = [])
    {
        $appPath = Helper::getAppPath();
        $options += [
            'rpcHost' => '127.0.0.1',
            'rpcPort' => 6800,
            'dir' => '/tmp/Downloads',
            'torrentsDir' => '/tmp/Torrents',
            'token' => 'ncdownloader123',
            'confDir' => '/tmp/aria2',
            'settings' => [],
            'aria2Conf' => [],
        ];

        $hooks = [
            'on-download-complete' => $options['completeHook'] ?? ($appPath . '/hooks/completeHook.sh'),
            'on-download-start' => $options['startHook'] ?? ($appPath . '/hooks/startHook.sh'),
            'on-download-error' => $options['errorHook'] ?? ($appPath . '/hooks/errorHook.sh'),
        ];
        foreach ($hooks as $hookFile) {
            if (is_string($hookFile) && @is_file($hookFile) && !@is_executable($hookFile)) {
                @chmod($hookFile, 0755);
            }
        }
        $options['aria2Conf'] = is_array($options['aria2Conf']) ? ($options['aria2Conf'] + $hooks) : $hooks;

        $this->bin = $this->resolveBinaryPath(isset($options['binary']) ? (string) $options['binary'] : null);
        if ($this->isInstalled() && !$this->isExecutable()) {
            @chmod((string) $this->bin, 0755);
        }
        $this->confDir = (string) $options['confDir'];
        $this->php = Helper::findBinaryPath('php');
        $this->rpcUrl = sprintf('http://%s:%s/jsonrpc', $options['rpcHost'], $options['rpcPort']);
        $this->sessionFile = (string) ($options['sessionFile'] ?? ($this->confDir . '/aria2.session'));
        $this->configureGlobalAria2($options);
        $this->configureLocalAria2($options);
    }

    public function init(): void
    {
        $this->ch = curl_init($this->rpcUrl);
        curl_setopt_array($this->ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);
        $this->configure();
    }

    private function configureLocalAria2(array $options): void
    {
        $this->setDownloadDir((string) $options['dir']);
        $this->setTorrentsDir((string) $options['torrentsDir']);
        $this->setToken((string) $options['token']);
        if (!empty($options['settings']) && is_array($options['settings'])) {
            foreach ($options['settings'] as $key => $value) {
                $this->setOption((string) $key, $value);
            }
        }
    }

    private function configureGlobalAria2(array $options): void
    {
        $runOptions = new RunOptions(is_array($options['aria2Conf']) ? $options['aria2Conf'] : []);
        $runOptions->add('--rpc-secret=' . $options['token']);
        $runOptions->add('--rpc-listen-port=' . $options['rpcPort']);
        $runOptions->add('--save-session=' . $this->sessionFile);
        $runOptions->add('--input-file=' . $this->sessionFile);
        $runOptions->add('--log=' . $this->confDir . '/aria2.log');

        $this->config = $runOptions->getOptions();
    }

    public function setonDownloadStart(string $path): void
    {
        $this->onDownloadStart = $path;
    }

    public function reset(): void
    {
        $this->init();
    }

    private function configure(): void
    {
        if ($this->confDir !== '' && !is_dir($this->confDir)) {
            @mkdir($this->confDir, 0755, true);
        }
        $dir = $this->getDownloadDir();
        if ($dir !== '' && !is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $tDir = $this->getTorrentsDir();
        if ($tDir !== '' && !is_dir($tDir)) {
            @mkdir($tDir, 0755, true);
        }
        $this->followTorrent(true);
    }

    public function setToken(string $token): self
    {
        $this->token = "token:$token";
        return $this;
    }

    public function setOption(string $key, $value): self
    {
        $this->options[$key] = $value;
        return $this;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function setTorrentsDir(string $dir): self
    {
        $this->torrentsDir = $dir;
        return $this;
    }

    public function getTorrentsDir(): string
    {
        return $this->torrentsDir;
    }

    public function setDownloadDir(string $dir): self
    {
        $this->setOption('dir', $dir);
        $this->downloadDir = $dir;
        return $this;
    }

    public function getDownloadDir(): string
    {
        return $this->downloadDir;
    }

    public function setFileName(string $file): self
    {
        $safe = basename($file);
        if ($safe !== '' && $safe !== '.' && $safe !== '..') {
            $this->options['out'] = $safe;
        }
        return $this;
    }

    public function followTorrent(bool $follow): self
    {
        $this->options['follow-torrent'] = $follow ? 'true' : 'false';
        return $this;
    }

    private function request(array $data): ?array
    {
        $this->init();
        $defaults = [
            'jsonrpc' => '2.0',
            'id' => 'ncdownloader',
            'method' => 'aria2.addUri',
            'params' => null,
        ];

        $data += $defaults;
        $this->content = (string) json_encode($data);
        curl_setopt($this->ch, CURLOPT_POSTFIELDS, $this->content);
        $resp = curl_exec($this->ch);
        if (is_resource($this->ch) || $this->ch instanceof \CurlHandle) {
            curl_close($this->ch);
        }
        if (!is_string($resp) || $resp === '') {
            return null;
        }
        $decoded = json_decode($resp, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function getFollowingGid(string $gid)
    {
        $data = $this->tellStatus($gid);
        if (!is_array($data) || empty($data)) {
            return 0;
        }
        $first = reset($data);
        return is_array($first) ? ($first['following'] ?? 0) : 0;
    }

    public function tellFail(array $range = [0, 999]): array
    {
        $this->filterResponse = false;
        $resp = $this->tellStopped($range);
        $this->filterResponse = true;
        if (!is_array($resp) || !isset($resp['result']) || !is_array($resp['result'])) {
            return [];
        }
        return $this->sortDownloadsResult($resp['result'], ['complete', 'removed']);
    }

    public function tellAll(): array
    {
        $this->filterResponse = false;
        $active = $this->tellActive([]) ?: [];
        $waiting = $this->tellWaiting([0, 999]) ?: [];
        $stopped = $this->tellStopped([0, 999]) ?: [];
        $this->filterResponse = true;
        return array_merge($active, $waiting, $stopped);
    }

    public function __call(string $name, array $args)
    {
        $this->methodName = $name;

        if (isset($args[0]) && is_array($args[0]) && count($args) === 1 && strtolower($name) !== 'adduri') {
            $args = reset($args);
        }
        switch ($name) {
            case 'addUri':
            case 'addTorrent':
                $args[] = $this->options;
                break;
            case 'tellActive':
            case 'tellWaiting':
            case 'tellStopped':
            case 'tellStatus':
            case 'getFiles':
                $args[] = $this->dataFilter;
                break;
        }
        if ($this->token !== null) {
            array_unshift($args, $this->token);
        }
        $data = ['params' => $args, 'method' => 'aria2.' . $name];
        $rawResp = $this->request($data);

        if (!$this->filterResponse) {
            return $rawResp;
        }
        return $this->parseResp($rawResp ?? []);
    }

    private function sortDownloadsResult(array $result, ?array $statusFilter = null): array
    {
        $data = [];
        if ($statusFilter === null) {
            $statusFilter = ['error'];
        }
        if (empty($result)) {
            return [];
        }
        foreach ($result as $info) {
            if (!is_array($info)) {
                continue;
            }
            $info = Helper::filterData($info);
            if (isset($info['files']) && is_array($info['files'])) {
                foreach ($info['files'] as &$files) {
                    $files = Helper::filterData($files, ['path', 'length']);
                }
                unset($files);
            }
            if (isset($info['status']) && in_array($info['status'], $statusFilter, true)) {
                continue;
            }
            $data[] = $info;
        }
        return $data;
    }

    public function parseResp(?array $resp = null): array
    {
        $resp = $resp ?? [];
        $data = [];
        if (isset($resp['error']['message'])) {
            $data['error'] = $resp['error']['message'];
            return $data;
        }
        $result = $resp['result'] ?? null;
        if ($result === null) {
            return $data;
        }
        if ($this->methodName === 'tellStatus' && is_array($result) && isset($result['files'])) {
            foreach ($result['files'] as &$files) {
                $files = Helper::filterData($files, ['path', 'length']);
            }
            unset($files);
            $data[] = $result;
            return $data;
        }
        if ($this->methodName !== null && str_contains($this->methodName, 'tell') && is_array($result)) {
            return $this->sortDownloadsResult($result);
        }
        return $resp;
    }

    public function getStatus(string $gid)
    {
        return $this->tellStatus($gid);
    }

    public function download(string $url)
    {
        $validation = UrlValidator::validate($url, ['http', 'https', 'ftp', 'ftps', 'magnet']);
        if (!$validation['valid']) {
            return ['error' => $validation['error'] ?? 'Invalid URL'];
        }

        if (!$this->isRunning() && $this->isInstalled() && $this->isExecutable()) {
            $this->start();
            usleep(300000);
        }

        $resp = $this->addUri([$url]);
        if (is_array($resp) && isset($resp['error'])) {
            return $resp;
        }
        if (is_array($resp) && isset($resp['result']) && is_string($gid = $resp['result'])) {
            return $gid;
        }
        return false;
    }

    public function btDownload(string $file)
    {
        if (!@is_file($file)) {
            return ['error' => 'no valid torrents file!'];
        }
        $data = @file_get_contents($file);
        if ($data === false || $data === '') {
            return ['error' => 'no valid torrents file!'];
        }

        if (!$this->isRunning() && $this->isInstalled() && $this->isExecutable()) {
            $this->start();
            usleep(300000);
        }

        $filename = Helper::getBasicFilename($file);
        $torrent = base64_encode($data);
        $resp = $this->addTorrent($torrent, []);
        if (is_array($resp) && isset($resp['error'])) {
            return $resp;
        }
        if (is_array($resp) && isset($resp['result']) && is_string($gid = $resp['result'])) {
            return ['gid' => $gid, 'filename' => $filename];
        }
        return false;
    }

    public function restart(): void
    {
        $this->stop();
        $this->start();
    }

    public function stop()
    {
        $resp = $this->shutdown();
        sleep(2);
        return $resp ?: null;
    }

    public function start(?string $bin = null): array
    {
        $this->ensureBinaryPath();
        if (empty($this->bin) || !@is_file($this->bin)) {
            return ['status' => false, 'error' => 'aria2c binary not found'];
        }
        if ($this->confDir !== '' && !is_dir($this->confDir)) {
            @mkdir($this->confDir, 0755, true);
        }
        if (!file_exists($this->sessionFile)) {
            @file_put_contents($this->sessionFile, '');
        }

        $cmd = array_merge([$this->bin], $this->config);
        $process = new Process($cmd);
        try {
            $process->mustRun();
        } catch (ProcessFailedException $exception) {
            return ['status' => false, 'error' => $exception->getMessage()];
        }
        return ['status' => true];
    }

    public function isInstalled(): bool
    {
        $this->ensureBinaryPath();
        return !empty($this->bin) && @is_file($this->bin);
    }

    public function isExecutable(): bool
    {
        $this->ensureBinaryPath();
        return !empty($this->bin) && @is_executable($this->bin);
    }

    public function isRunning(): bool
    {
        $resp = $this->getSessionInfo();
        return is_array($resp) && isset($resp['result']);
    }

    public function getBin(): ?string
    {
        $this->ensureBinaryPath();
        return $this->bin;
    }

    public function version(): ?string
    {
        $resp = $this->getVersion();
        if (is_array($resp) && isset($resp['result']['version'])) {
            return (string) $resp['result']['version'];
        }
        if ($this->isInstalled() && $this->isExecutable()) {
            $proc = new Process([(string) $this->bin, '--version']);
            $proc->run();
            if ($proc->isSuccessful()) {
                $firstLine = strtok(trim($proc->getOutput()), "\r\n");
                if (is_string($firstLine) && preg_match('/([0-9]+\.[0-9]+(?:\.[0-9]+)?)/', $firstLine, $m)) {
                    return $m[1];
                }
            }
        }
        return null;
    }
}
