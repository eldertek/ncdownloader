<?php

namespace OCA\NCDownloader\Tools;

use OCA\NCDownloader\Aria2\Aria2;
use OCA\NCDownloader\Aria2\Options as aria2Options;
use OCA\NCDownloader\Db\Settings;
use OCA\NCDownloader\Http\Client;
use OCA\NCDownloader\Search\Sites\searchInterface;
use OCA\NCDownloader\Ytdl\Ytdl;
use OCP\App\IAppManager;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Server;
use Psr\Log\LoggerInterface;

class Helper
{
    public const DOWNLOADTYPE = ['ARIA2' => 1, 'YOUTUBE-DL' => 2, 'OTHERS' => 3];
    public const STATUS = ['ACTIVE' => 1, 'PAUSED' => 2, 'COMPLETE' => 3, 'WAITING' => 4, 'ERROR' => 5];
    public const MAXFILELEN = 255;

    public static function isUrl(string $url): bool
    {
        $urlPattern = '%^(?:(?:https?|ftps?)://)(?:\S+(?::\S*)?@|\d{1,3}(?:\.\d{1,3}){3}|(?:(?:[a-z\d\x{00a1}-\x{ffff}'
            . ']+-?)*[a-z\d\x{00a1}-\x{ffff}]+)(?:\.(?:[a-z\d\x{00a1}-\x{ffff}]+-?)*[a-z\d\x{00a1}-\x{ffff}]+)*(?:\.'
            . '[a-z\x{00a1}-\x{ffff}]{2,}))(?::\d+)?(?:[^\s]*)?$%iu';

        return preg_match($urlPattern, $url) === 1;
    }

    public static function isMagnet(string $url): bool
    {
        $scheme = (string) parse_url($url, PHP_URL_SCHEME);
        return strtolower($scheme) === 'magnet';
    }

    public static function isHttp(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }

    public static function isFtp(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['ftp', 'ftps'], true);
    }

    public static function isGetUrlSite(string $url): bool
    {
        return false;
    }

    public static function parseUrl(string $url): array
    {
        $rawQuery = (string) parse_url($url, PHP_URL_QUERY);
        parse_str(str_replace('tr=', 'tr[]=', $rawQuery), $query);
        return $query;
    }

    public static function getUrlPath(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $filename = self::cleanString(urldecode(basename($path)));
        return self::clipFilename($filename);
    }

    public static function clipFilename(string $filename): string
    {
        $filename = basename($filename);
        if (($len = strlen($filename)) > self::MAXFILELEN) {
            return substr($filename, $len - self::MAXFILELEN);
        }
        return $filename;
    }

    public static function getFilename(string $url): string
    {
        if (self::isMagnet($url)) {
            $info = self::parseUrl($url);
            $filename = (string) ($info['dn'] ?? '');
        } else {
            $filename = self::getUrlPath($url);
        }
        return self::clipFilename($filename);
    }

    public static function formatBytes($size, int $precision = 2): string
    {
        $size = (float) $size;
        if ($size < 1) {
            return '0';
        }
        $base = log($size, 1024);
        $suffixes = ['', 'K', 'M', 'G', 'T', 'P'];
        $idx = min((int) floor($base), count($suffixes) - 1);
        return round(pow(1024, $base - $idx), $precision) . ' ' . $suffixes[$idx];
    }

    public static function checkMediaType(string $url, string $type = 'video/mp4'): bool
    {
        $result = parse_url($url);
        if (is_array($result) && isset($result['scheme']) && self::isHttp($url)) {
            if (isset($result['query'])) {
                parse_str($result['query'], $output);
                if (!isset($output['mime'])) {
                    return false;
                }
                return $output['mime'] === trim($type);
            }
        }
        return false;
    }

    public static function isYoutubeType(string $url): bool
    {
        $regex = '%^(?:https?://)(?:[a-z0-9_]*\.)?(?:twitter|ytdl)\.com/%i';
        return (bool) preg_match($regex, $url);
    }

    public static function cleanString(?string $string): string
    {
        if ($string === null || $string === '') {
            return '';
        }
        $replace = [
            '/[áàâãªä]/u' => 'a',
            '/[ÁÀÂÃÄ]/u' => 'A',
            '/[ÍÌÎÏ]/u' => 'I',
            '/[íìîï]/u' => 'i',
            '/[éèêë]/u' => 'e',
            '/[ÉÈÊË]/u' => 'E',
            '/[óòôõºö]/u' => 'o',
            '/[ÓÒÔÕÖ]/u' => 'O',
            '/[úùûü]/u' => 'u',
            '/[ÚÙÛÜ]/u' => 'U',
            '/ç/u' => 'c',
            '/Ç/u' => 'C',
            '/ñ/u' => 'n',
            '/Ñ/u' => 'N',
            '/[\x{2013}\x{2014}]/u' => '-',
            '/[\x{2018}\x{2019}\x{2039}\x{203a}\x{201a}]/u' => '',
            '/[\x{201c}\x{201d}\x{00ab}\x{00bb}\x{201e}]/u' => '',
        ];
        return (string) preg_replace(array_keys($replace), array_values($replace), $string);
    }

    public static function debug($msg): void
    {
        if (is_array($msg)) {
            $msg = implode(',', array_map('strval', $msg));
        }
        $logger = self::getLogger();
        $logger->error((string) $msg, ['app' => 'ncdownloader']);
    }

    public static function log($msg, string $file = '/tmp/nc.log'): void
    {
        @file_put_contents($file, print_r($msg, true), FILE_APPEND);
    }

    public static function filterData($data, ?array $filter = null): array
    {
        if (!is_array($data)) {
            return [];
        }
        if ($filter === null) {
            $filter = [
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
            ];
        }
        return array_filter($data, function ($k) use ($filter) {
            return in_array($k, $filter, true);
        }, ARRAY_FILTER_USE_KEY);
    }

    public function getFolderName(string $folder, string $prefix): string
    {
        $folder = ltrim($folder, $prefix);
        $pos = strpos($folder, '/');
        return $pos !== false ? substr($folder, 0, $pos) : $folder;
    }

    public static function Download(string $url, ?string $file = null): void
    {
        $check = UrlValidator::validate($url, ['http', 'https']);
        if (!$check['valid']) {
            throw new \InvalidArgumentException($check['error'] ?? 'Invalid download URL');
        }
        if ($file === null) {
            $file = '/tmp/' . self::getFilename($url);
        }
        $client = Client::create(['max_redirects' => 5, 'timeout' => 120]);
        $response = $client->request('GET', $url);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 400) {
            throw new \RuntimeException('HTTP status ' . $response->getStatusCode());
        }
        if (file_put_contents($file, $response->getContent()) === false) {
            throw new \RuntimeException('Failed to save ' . $file);
        }
    }

    public static function is_function_enabled(string $function_name): bool
    {
        if (!function_exists($function_name)) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array($function_name, $disabled, true)) {
            return false;
        }
        $blacklist = array_map('trim', explode(',', (string) ini_get('suhosin.executor.func.blacklist')));
        if (in_array($function_name, $blacklist, true)) {
            return false;
        }
        return true;
    }

    public static function findBinaryPath(string $program, ?string $default = null): ?string
    {
        $memcache = Server::get(ICacheFactory::class)->createDistributed('findBinaryPath');
        if ($memcache->hasKey($program)) {
            $cached = $memcache->get($program);
            if (is_string($cached) && @is_file($cached)) {
                return $cached;
            }
        }
        $dataPath = self::getDataDir();
        $paths = [
            '/usr/local/sbin',
            '/usr/local/bin',
            '/usr/sbin',
            '/usr/bin',
            '/sbin',
            '/bin',
            '/opt/bin',
            rtrim($dataPath, '/') . '/bin',
        ];
        $exeSniffer = new ExecutableFinder();
        $result = $exeSniffer->find($program, $default, $paths);
        if ($result && @is_file($result)) {
            $memcache->set($program, $result, 300);
            return $result;
        }
        return $default;
    }

    public static function formatInterval($interval, int $granularity = 2): string
    {
        $interval = max(0, (int) round((float) $interval));
        $units = [
            '1 year|years' => 31536000,
            '1 month|months' => 2592000,
            '1 week|weeks' => 604800,
            '1 day|days' => 86400,
            '1 hour|hours' => 3600,
            '1 min|mins' => 60,
            '1 sec|sec' => 1,
        ];
        $output = '';
        foreach ($units as $key => $value) {
            $parts = explode('|', $key);
            if ($interval >= $value) {
                $output .= ($output ? ' ' : '') . self::formatPlural((int) floor($interval / $value), $parts[0], $parts[1]);
                $interval %= $value;
                $granularity--;
            }
            if ($granularity === 0) {
                break;
            }
        }
        return $output !== '' ? $output : '0 sec';
    }

    public static function formatPlural(int $count, string $singular, string $plural): string
    {
        if ($count === 1) {
            return $singular;
        }
        return $count . ' ' . $plural;
    }

    public static function aria2Options(): array
    {
        return aria2Options::get();
    }

    public static function aria2UserOptions(): array
    {
        return aria2Options::getUserAllowed();
    }

    public static function getTableTitles(?string $type = null): array
    {
        $general = ['filename', 'status', 'actions'];
        if ($type === null) {
            return $general;
        }
        $titles = [
            'active' => ['filename', 'speed', 'progress', 'actions'],
            'waiting' => $general,
            'fail' => $general,
            'complete' => $general,
        ];
        return $titles[$type] ?? $general;
    }

    public static function getUserFolder(?string $uid = null): string
    {
        $uid = $uid ?? self::getUID();
        if ($uid === '') {
            return '';
        }
        try {
            /** @var IRootFolder $rootFolder */
            $rootFolder = \OC::$server->get(IRootFolder::class);
            return $rootFolder->getUserFolder($uid)->getPath();
        } catch (\Throwable $e) {
            return '/' . $uid . '/files';
        }
    }

    public static function ensureUserSubFolder(string $relativePath, ?string $uid = null): ?Folder
    {
        $uid = $uid ?? self::getUID();
        if ($uid === '') {
            return null;
        }
        try {
            /** @var IRootFolder $rootFolder */
            $rootFolder = \OC::$server->get(IRootFolder::class);
            $userFolder = $rootFolder->getUserFolder($uid);
            $cleanRel = trim(str_replace('\\', '/', $relativePath), '/');
            if ($cleanRel === '') {
                return $userFolder;
            }
            $current = $userFolder;
            foreach (explode('/', $cleanRel) as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    continue;
                }
                if ($current->nodeExists($segment)) {
                    $node = $current->get($segment);
                    if ($node instanceof Folder) {
                        $current = $node;
                    } else {
                        return null;
                    }
                } else {
                    $current = $current->newFolder($segment);
                }
            }
            return $current;
        } catch (\Throwable $e) {
            self::debug($e->getMessage());
            return null;
        }
    }

    public static function generateGID(?string $str = null): string
    {
        if ($str !== null) {
            return md5($str);
        }
        return sprintf(
            '%04x%04x%04x%04x%04x%04x%04x%04x',
            mt_rand(0, 65535),
            mt_rand(0, 65535),
            mt_rand(0, 65535),
            mt_rand(16384, 20479),
            mt_rand(0, 65535),
            mt_rand(0, 65535),
            mt_rand(0, 65535),
            mt_rand(16384, 20479)
        );
    }

    public static function ffmpegInstalled(): bool
    {
        return (bool) self::findBinaryPath('ffmpeg');
    }

    public static function getBasicFilename(string $path): string
    {
        return pathinfo($path, PATHINFO_FILENAME);
    }

    public static function sanitize($value)
    {
        if (!is_string($value)) {
            return $value;
        }
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        return trim(strip_tags((string) $clean));
    }

    public static function doSignal(int $pid, int $signal): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (\function_exists('posix_kill')) {
            return (bool) @posix_kill($pid, $signal);
        }
        $proc = @proc_open(sprintf('kill -%d %d', $signal, $pid), [2 => ['pipe', 'w']], $pipes);
        if (is_resource($proc)) {
            $err = fgets($pipes[2]);
            fclose($pipes[2]);
            proc_close($proc);
            return $err === false;
        }
        return false;
    }

    public static function isRunning($pid): bool
    {
        return self::doSignal((int) $pid, 0);
    }

    public static function stop($pid): bool
    {
        return self::doSignal((int) $pid, 9);
    }

    public static function pythonInstalled(): bool
    {
        return (bool) (self::findBinaryPath('python3') || self::findBinaryPath('python'));
    }

    public static function findSearchSites(string $dir, string $suffix = 'php'): array
    {
        $filetool = File::create($dir, $suffix);
        $files = $filetool->scandir();
        $sites = [];
        foreach ($files as $file) {
            $basename = File::getBasename($file);
            $namespace = 'OCA\\NCDownloader\\Search\\Sites\\';
            $className = $namespace . $basename;
            if (class_exists($className) && in_array(searchInterface::class, class_implements($className) ?: [], true)) {
                $sites[] = ['class' => $className, 'name' => $basename];
            }
        }
        return $sites;
    }

    public static function getSearchSites(): array
    {
        $key = 'searchSites';
        $memcache = Server::get(ICacheFactory::class)->createDistributed($key);
        if ($memcache->hasKey($key)) {
            $cached = $memcache->get($key);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $sites = [];
        try {
            $sites = self::findSearchSites(__DIR__ . '/../Search/Sites/');
            $memcache->set($key, $sites, 300);
        } catch (\Throwable $e) {
            self::debug($e->getMessage());
        }
        return $sites;
    }

    public static function getDataDir(): string
    {
        return (string) \OC::$server->get(IConfig::class)->getSystemValue('datadirectory', '/var/www/html/data');
    }

    public static function getLocalFolder(string $path, ?string $uid = null): string
    {
        $uid = $uid ?? self::getUID();
        if ($uid === '') {
            return '';
        }
        $folder = self::ensureUserSubFolder($path, $uid);
        if ($folder !== null) {
            $local = $folder->getStorage()->getLocalFile($folder->getInternalPath());
            if (is_string($local) && $local !== '') {
                if (!is_dir($local)) {
                    @mkdir($local, 0755, true);
                }
                return $local;
            }
        }
        $cleanRel = trim(str_replace(['\\', '..'], ['/', ''], $path), '/');
        $fallback = rtrim(self::getDataDir(), '/') . '/' . $uid . '/files' . ($cleanRel !== '' ? '/' . $cleanRel : '');
        if (!is_dir($fallback)) {
            @mkdir($fallback, 0755, true);
        }
        return $fallback;
    }

    public static function getRealDownloadDir(?string $uid = null): string
    {
        $dlDir = self::getDownloadDir($uid);
        return self::getLocalFolder($dlDir, $uid);
    }

    public static function getRealTorrentsDir(?string $uid = null): string
    {
        $dir = (string) (self::newSettings($uid)->get('ncd_torrents_dir', '/Torrents') ?: '/Torrents');
        return self::getLocalFolder($dir, $uid);
    }

    public static function getUser(): ?IUser
    {
        return \OC::$server->get(IUserSession::class)->getUser();
    }

    public static function getUID(): string
    {
        $user = self::getUser();
        return $user ? $user->getUID() : '';
    }

    public static function isAdmin(?string $uid = null): bool
    {
        $uid = $uid ?? self::getUID();
        if ($uid === '') {
            return false;
        }
        /** @var IGroupManager $groupManager */
        $groupManager = \OC::$server->get(IGroupManager::class);
        return $groupManager->isAdmin($uid);
    }

    public static function getSettings(string $key, $default = null, int $type = Settings::TYPE['USER'], ?string $uid = null)
    {
        $settings = self::newSettings($uid);
        return $settings->setType($type)->get($key, $default);
    }

    public static function newSettings(?string $uid = null): Settings
    {
        $uid = $uid ?? self::getUID();
        return Settings::create($uid);
    }

    public static function getYtdlConfig(?string $uid = null): array
    {
        $uid = $uid ?? self::getUID();
        return [
            'binary' => self::getAdminSettings('ncd_yt_binary'),
            'downloadDir' => self::getRealDownloadDir($uid),
            'settings' => self::newSettings($uid)->getYtdl(),
        ];
    }

    public static function getAria2Config(?string $uid = null): array
    {
        $options = [];
        $uid = $uid ?? self::getUID();
        $settings = self::newSettings($uid);
        $realDownloadDir = $uid !== '' ? self::getRealDownloadDir($uid) : '/tmp/Downloads';
        $torrentsDir = $uid !== '' ? self::getRealTorrentsDir($uid) : '/tmp/Torrents';
        $appPath = self::getAppPath();
        $dataDir = self::getDataDir();
        $aria2Dir = rtrim($dataDir, '/') . '/aria2';

        $seedTime = $settings->get('ncd_seed_time');
        $seedRatio = $settings->get('ncd_seed_ratio');
        if ($seedTime !== null && $seedTime !== '') {
            $options['seed-time'] = $seedTime;
        }
        if ($seedRatio !== null && $seedRatio !== '') {
            $options['seed-ratio'] = $seedRatio;
        }
        $customSettings = $settings->getAria2();
        if (is_array($customSettings)) {
            $options = array_merge($customSettings, $options);
        }

        $token = self::getAdminSettings('ncd_aria2_rpc_token');
        $rpcHost = self::getAdminSettings('ncd_aria2_rpc_host');
        $rpcPort = self::getAdminSettings('ncd_aria2_rpc_port');
        $binary = self::getAdminSettings('ncd_aria2_binary');
        $globalConf = self::getSettings('global_aria2_config', [], Settings::TYPE['SYSTEM']);

        return [
            'dir' => $realDownloadDir,
            'torrentsDir' => $torrentsDir,
            'confDir' => $aria2Dir,
            'token' => $token !== '' ? $token : 'ncdownloader123',
            'settings' => $options,
            'rpcHost' => $rpcHost !== '' ? $rpcHost : '127.0.0.1',
            'rpcPort' => $rpcPort !== '' ? $rpcPort : '6800',
            'binary' => $binary,
            'startHook' => $appPath . '/hooks/startHook.sh',
            'completeHook' => $appPath . '/hooks/completeHook.sh',
            'errorHook' => $appPath . '/hooks/errorHook.sh',
            'aria2Conf' => is_array($globalConf) ? $globalConf : [],
        ];
    }

    public static function getAppPath(): string
    {
        return \OC::$server->get(IAppManager::class)->getAppPath('ncdownloader');
    }

    public static function folderUpdated(string $dir): bool
    {
        if ($dir === '' || !file_exists($dir)) {
            return false;
        }
        $checkFile = rtrim($dir, '/') . '/.lastmodified';
        clearstatcache(true, $dir);
        $time = (int) @filemtime($dir);

        if (!file_exists($checkFile)) {
            @file_put_contents($checkFile, (string) $time);
            return true;
        }
        $lastModified = (int) @file_get_contents($checkFile);
        if ($time > $lastModified) {
            @file_put_contents($checkFile, (string) $time);
            return true;
        }
        return false;
    }

    public static function getDownloadDir(?string $uid = null): string
    {
        $dir = self::newSettings($uid)->get('ncd_downloader_dir', '/Downloads');
        return (is_string($dir) && trim($dir) !== '') ? $dir : '/Downloads';
    }

    public static function getVersion(): array
    {
        $config = \OC::$server->get(IConfig::class);
        $version = (string) $config->getSystemValue('version', '28.0.0');
        return explode('.', $version);
    }

    public static function query(string $key)
    {
        return \OC::$server->get($key);
    }

    public static function getLogger(): LoggerInterface
    {
        return \OC::$server->get(LoggerInterface::class);
    }

    public static function getDatabaseConnection(): IDBConnection
    {
        return \OC::$server->get(IDBConnection::class);
    }

    public static function getAdminSettings(string $key): string
    {
        $settings = self::getAllAdminSettings();
        return isset($settings[$key]) ? (string) $settings[$key] : '';
    }

    public static function getAllAdminSettings(): array
    {
        $val = self::getSettings('ncd_admin_settings', [], Settings::TYPE['SYSTEM']);
        return is_array($val) ? $val : [];
    }

    public static function getAria2Version(): ?string
    {
        $aria2 = self::query(Aria2::class);
        $ver = $aria2->version();
        return is_string($ver) ? $ver : null;
    }

    public static function getYtdlVersion(): ?string
    {
        $ytdl = self::query(Ytdl::class);
        $ver = $ytdl->version();
        return is_string($ver) ? $ver : null;
    }

    public static function getAdminOptions(array $data): array
    {
        /** @var IL10N $l10n */
        $l10n = \OC::$server->get(\OCP\L10N\IFactory::class)->get('ncdownloader');
        return [
            [
                'label' => $l10n->t('Aria2 RPC Host'),
                'id' => 'ncd_aria2_rpc_host',
                'value' => $data['ncd_aria2_rpc_host'] ?? '',
                'placeholder' => !empty($data['ncd_aria2_rpc_host']) ? $data['ncd_aria2_rpc_host'] : '127.0.0.1',
                'path' => $data['path'],
            ],
            [
                'label' => $l10n->t('Aria2 RPC Port'),
                'id' => 'ncd_aria2_rpc_port',
                'value' => $data['ncd_aria2_rpc_port'] ?? '',
                'placeholder' => !empty($data['ncd_aria2_rpc_port']) ? $data['ncd_aria2_rpc_port'] : 6800,
                'path' => $data['path'],
            ],
            [
                'label' => $l10n->t('Aria2 RPC Token'),
                'id' => 'ncd_aria2_rpc_token',
                'value' => $data['ncd_aria2_rpc_token'] ?? '',
                'placeholder' => !empty($data['ncd_aria2_rpc_token']) ? $data['ncd_aria2_rpc_token'] : 'ncdownloader123',
                'path' => $data['path'],
            ],
            [
                'label' => $l10n->t('yt-dlp binary'),
                'id' => 'ncd_yt_binary',
                'value' => $data['ncd_yt_binary'] ?? '',
                'placeholder' => !empty($data['ncd_yt_binary']) ? $data['ncd_yt_binary'] : '/usr/bin/yt-dlp',
                'path' => $data['path'],
            ],
            [
                'label' => $l10n->t('Aria2c binary'),
                'id' => 'ncd_aria2_binary',
                'value' => $data['ncd_aria2_binary'] ?? '',
                'placeholder' => !empty($data['ncd_aria2_binary']) ? $data['ncd_aria2_binary'] : '/usr/bin/aria2c',
                'path' => $data['path'],
            ],
        ];
    }

    public static function getLatestRelease(string $owner, string $repo): ?string
    {
        try {
            $client = Client::create();
            $response = $client->request('GET', "https://api.github.com/repos/$owner/$repo/releases/latest", [
                'headers' => [
                    'User-Agent' => 'Nextcloud-NCDownloader',
                ],
            ]);
            $data = json_decode($response->getContent(), true);
            if (is_array($data) && isset($data['tag_name'])) {
                return (string) $data['tag_name'];
            }
        } catch (\Throwable $e) {
            self::debug($e->getMessage());
        }
        return null;
    }

    public static function downloadLatestRelease(string $owner, string $repo, string $file)
    {
        $client = Client::create(['max_redirects' => 10, 'timeout' => 120]);
        $response = $client->request('GET', "https://api.github.com/repos/$owner/$repo/releases/latest", [
            'headers' => [
                'User-Agent' => 'Nextcloud-NCDownloader',
            ],
        ]);
        $data = json_decode($response->getContent(), true);
        $downloadUrl = null;
        if (is_array($data) && isset($data['assets']) && is_array($data['assets'])) {
            foreach ($data['assets'] as $asset) {
                if (($asset['name'] ?? '') === $repo) {
                    $downloadUrl = $asset['browser_download_url'] ?? null;
                    break;
                }
            }
        }
        if ($downloadUrl) {
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (!is_writable($dir)) {
                throw new \RuntimeException($dir . ' is not writable');
            }
            $binResp = $client->request('GET', $downloadUrl);
            $bytes = file_put_contents($file, $binResp->getContent());
            if ($bytes !== false) {
                return $bytes;
            }
            throw new \RuntimeException("Failed to download $downloadUrl");
        }
        return false;
    }

    public static function removeLetters(string $str): string
    {
        return (string) preg_replace('/[^0-9.]+/', '', $str);
    }
}
