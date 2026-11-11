<?php

namespace OCA\NCDownloader\Controller;

use OCA\NCDownloader\AppInfo\Application;
use OCA\NCDownloader\Aria2\Aria2;
use OCA\NCDownloader\Db\Helper as DbHelper;
use OCA\NCDownloader\Tools\Counters;
use OCA\NCDownloader\Tools\folderScan;
use OCA\NCDownloader\Tools\Helper;
use OCA\NCDownloader\Tools\UrlValidator;
use OCA\NCDownloader\Ytdl\Ytdl;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;

class MainController extends Controller
{
    private IL10N $l10n;
    private IURLGenerator $urlGenerator;
    private string $uid;
    private bool $isAdmin;
    private bool $hideError;
    private string $disable_bt_nonadmin;
    private Aria2 $aria2;
    private DbHelper $dbconn;
    private Counters $counters;
    private Ytdl $ytdl;
    private string $accessDenied;

    public function __construct(
        IRequest $request,
        IUserSession $userSession,
        IL10N $l10n,
        IURLGenerator $urlGenerator,
        Aria2 $aria2,
        Ytdl $ytdl
    ) {
        parent::__construct(Application::APP_ID, $request);
        $user = $userSession->getUser();
        $this->uid = $user ? $user->getUID() : '';
        $this->l10n = $l10n;
        $this->urlGenerator = $urlGenerator;
        $this->aria2 = $aria2;
        $this->aria2->init();
        $this->dbconn = new DbHelper();
        $this->counters = new Counters($aria2, $this->dbconn, $this->uid);
        $this->ytdl = $ytdl;
        $this->isAdmin = Helper::isAdmin($this->uid);
        $this->hideError = (bool) Helper::getSettings('ncd_hide_errors', false, 1, $this->uid);
        $this->disable_bt_nonadmin = Helper::getAdminSettings('ncd_disable_bt');
        $this->accessDenied = $this->l10n->t('Sorry, only admin users can download files via BitTorrent!');
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function Index(): TemplateResponse
    {
        Util::addScript($this->appName, 'app');
        Util::addStyle($this->appName, 'app');

        $params = $this->buildParams();
        return new TemplateResponse($this->appName, 'Index', $params);
    }

    private function buildParams(): array
    {
        $params = [];
        $params['aria2_installed'] = $aria2Installed = $this->aria2->isInstalled();
        $params['aria2_bin'] = $aria2Bin = (string) $this->aria2->getBin();
        $params['aria2_executable'] = $aria2Executable = $this->aria2->isExecutable();
        $params['aria2_running'] = $this->aria2->isRunning();

        if ($aria2Installed && $aria2Executable && !$params['aria2_running']) {
            $this->aria2->start();
            $params['aria2_running'] = $this->aria2->isRunning();
        }

        $params['ytdlinstalled'] = $ytdlInstalled = $this->ytdl->isInstalled();
        $params['ytdlbin'] = $ytdlBin = $this->ytdl->getBin();
        $params['ytdlexecutable'] = $ytdlExecutable = $this->ytdl->isExecutable();
        $params['ncd_hide_errors'] = $this->hideError;
        $params['counter'] = $this->counters->getCounters();
        $params['python_installed'] = Helper::pythonInstalled();
        $params['ffmpeg_installed'] = Helper::ffmpegInstalled();
        $params['is_admin'] = $this->isAdmin;

        $sites = [];
        foreach (Helper::getSearchSites() as $site) {
            $label = $site['class']::getLabel();
            $sites[] = ['name' => $site['name'], 'label' => strtoupper($label)];
        }
        $params['search_sites'] = json_encode($sites);

        $errors = [];
        if ($aria2Installed && !$aria2Executable) {
            $errors[] = sprintf(
                'aria2 is installed but does not have executable permissions. Run chmod 755 %s',
                $aria2Bin
            );
        }
        if ($ytdlInstalled && (!$ytdlExecutable || !@is_readable($ytdlBin))) {
            $errors[] = sprintf(
                'yt-dlp is installed but does not have executable permissions. Run chmod 755 %s',
                $ytdlBin
            );
        }

        foreach ($params as $key => $value) {
            if (!str_contains($key, '_')) {
                continue;
            }
            [$name, $suffix] = explode('_', $key, 2);
            if ($suffix !== 'installed') {
                continue;
            }
            if (!$value) {
                $errors[] = $this->l10n->t(sprintf('%s is not installed', $name));
            }
        }
        $params['errors'] = $errors;

        $params['settings'] = json_encode([
            'is_admin' => $this->isAdmin,
            'admin_url' => $this->urlGenerator->linkToRoute('settings.AdminSettings.index', ['section' => 'ncdownloader']),
            'personal_url' => $this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'ncdownloader']),
            'ncd_hide_errors' => $this->hideError,
            'ncd_disable_bt' => $this->disable_bt_nonadmin,
            'ncd_downloader_dir' => Helper::getDownloadDir($this->uid),
            'disallow_aria2_settings' => Helper::getAdminSettings('disallow_aria2_settings'),
        ]);
        return $params;
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function Download(string $url = ''): JSONResponse
    {
        $url = trim($url !== '' ? $url : (string) $this->request->getParam('url', ''));
        $check = UrlValidator::validate($url, ['http', 'https', 'ftp', 'ftps', 'magnet']);
        if (!$check['valid']) {
            return new JSONResponse(['error' => $check['error'] ?? 'Invalid URL']);
        }

        if (Helper::isMagnet($url) && $this->disable_bt_nonadmin && !$this->isAdmin) {
            return new JSONResponse(['error' => $this->accessDenied]);
        }

        $dlDir = Helper::getRealDownloadDir($this->uid);
        if ($dlDir !== '') {
            $this->aria2->setDownloadDir($dlDir);
        } else {
            $dlDir = $this->aria2->getDownloadDir();
        }
        if (!is_writable($dlDir)) {
            return new JSONResponse(['error' => sprintf('%s is not writable', $dlDir)]);
        }

        if (!$this->aria2->isRunning() && $this->aria2->isInstalled() && $this->aria2->isExecutable()) {
            $this->aria2->start();
        }

        $resp = $this->_download($url);
        return new JSONResponse($resp);
    }

    private function _download(string $url): array
    {
        $filename = Helper::getFilename($url);
        if ($filename !== '') {
            $this->aria2->setFileName($filename);
        }
        $result = $this->aria2->download($url);
        if (!$result) {
            return ['error' => 'Failed to start download'];
        }
        if (is_array($result) && isset($result['error'])) {
            return $result;
        }

        $gid = (string) $result;
        $relDir = Helper::getDownloadDir($this->uid);
        $data = [
            'uid' => $this->uid,
            'gid' => $gid,
            'type' => Helper::DOWNLOADTYPE['ARIA2'],
            'filename' => $filename !== '' ? $filename : 'unknown',
            'timestamp' => time(),
            'data' => ['link' => $url, 'path' => $relDir],
        ];
        $this->dbconn->save($data);

        // If a fast HTTP download already completed within a few hundred ms, index immediately
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $statusResp = $this->aria2->tellStatus($gid);
            $st = is_array($statusResp) ? strtolower((string) ($statusResp['status'] ?? '')) : '';
            if ($st === 'complete') {
                $donePath = (string) ($statusResp['files'][0]['path'] ?? '');
                $doneName = $donePath !== '' ? basename($donePath) : ($filename !== '' ? $filename : null);
                if ($doneName !== null) {
                    $this->dbconn->updateFilename($gid, $doneName, true);
                }
                $this->dbconn->updateStatus($gid, Helper::STATUS['COMPLETE']);
                folderScan::scanTarget($this->uid, $relDir, $doneName);
                break;
            }
            if ($st === 'error') {
                $this->dbconn->updateStatus($gid, Helper::STATUS['ERROR']);
                break;
            }
            usleep(150000);
        }

        return ['message' => $filename, 'result' => $gid, 'file' => $filename];
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function Upload(): JSONResponse
    {
        if ($this->disable_bt_nonadmin && !$this->isAdmin) {
            return new JSONResponse(['error' => $this->accessDenied]);
        }
        $uploaded = $this->request->getUploadedFile('torrentfile');
        $tmpName = $uploaded['tmp_name'] ?? ($_FILES['torrentfile']['tmp_name'] ?? '');
        $origName = $uploaded['name'] ?? ($_FILES['torrentfile']['name'] ?? 'upload.torrent');

        if (!$tmpName || !is_uploaded_file($tmpName)) {
            return new JSONResponse(['error' => 'No valid torrent file uploaded']);
        }

        $torrentsDir = Helper::getRealTorrentsDir($this->uid) ?: $this->aria2->getTorrentsDir();
        if (!is_dir($torrentsDir)) {
            @mkdir($torrentsDir, 0755, true);
        }
        $safeName = Helper::clipFilename(Helper::cleanString(basename((string) $origName)));
        if ($safeName === '' || !str_ends_with(strtolower($safeName), '.torrent')) {
            return new JSONResponse(['error' => 'Only .torrent files are allowed']);
        }
        $destFile = rtrim($torrentsDir, '/') . '/' . $safeName;
        if (!move_uploaded_file($tmpName, $destFile)) {
            return new JSONResponse(['error' => 'Failed to store uploaded torrent file']);
        }

        if (!$this->aria2->isRunning() && $this->aria2->isInstalled() && $this->aria2->isExecutable()) {
            $this->aria2->start();
        }

        $result = $this->aria2->btDownload($destFile);
        if (!$result) {
            return new JSONResponse(['error' => 'Failed to start BitTorrent download']);
        }
        if (isset($result['error'])) {
            return new JSONResponse($result);
        }

        $data = [
            'uid' => $this->uid,
            'gid' => $result['gid'],
            'type' => Helper::DOWNLOADTYPE['ARIA2'],
            'filename' => $result['filename'] ?? 'unknown',
            'timestamp' => time(),
            'data' => ['link' => $safeName, 'path' => Helper::getDownloadDir($this->uid)],
        ];
        $this->dbconn->save($data);
        folderScan::scanTarget($this->uid, (string) Helper::newSettings($this->uid)->get('ncd_torrents_dir', '/Torrents'), $safeName);

        return new JSONResponse([
            'message' => $result['filename'] ?? $safeName,
            'result' => $result['gid'],
            'file' => $result['filename'] ?? $safeName,
        ]);
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function scanFolder(): JSONResponse
    {
        $force = (bool) ($this->request->getParam('force') ?? false);
        $resp = $force ? folderScan::create($this->uid)->scan() : folderScan::sync(false, $this->uid);
        return new JSONResponse($resp);
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function getCounters(): JSONResponse
    {
        $counter = $this->counters->getCounters();
        return new JSONResponse(['counter' => $counter]);
    }

    public function ytdlCheck(): JSONResponse
    {
        if (!$this->isAdmin) {
            return new JSONResponse(['status' => false, 'message' => 'Admin access required'], 403);
        }
        return new JSONResponse($this->ytdl->check());
    }

    public function ytdlUpdate(): JSONResponse
    {
        if (!$this->isAdmin) {
            return new JSONResponse(['status' => false, 'message' => 'Admin access required'], 403);
        }
        return new JSONResponse($this->ytdl->update());
    }
}
