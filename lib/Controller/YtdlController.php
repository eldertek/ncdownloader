<?php

namespace OCA\NCDownloader\Controller;

use OCA\NCDownloader\AppInfo\Application;
use OCA\NCDownloader\Aria2\Aria2;
use OCA\NCDownloader\Db\Helper as DbHelper;
use OCA\NCDownloader\Tools\folderScan;
use OCA\NCDownloader\Tools\Helper;
use OCA\NCDownloader\Tools\UrlValidator;
use OCA\NCDownloader\Ytdl\Ytdl;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

class YtdlController extends Controller
{
    private IL10N $l10n;
    private array $audio_extensions = ['mp3', 'm4a', 'vorbis', 'opus'];
    private array $video_extensions = ['mp4', 'webm', 'mkv'];
    private string $uid;
    private string $downloadDir;
    private DbHelper $dbconn;
    private Ytdl $ytdl;
    private Aria2 $aria2;
    private IURLGenerator $urlGenerator;

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
        $this->urlGenerator = $urlGenerator;
        $this->l10n = $l10n;
        $this->downloadDir = Helper::getDownloadDir($this->uid);
        $this->dbconn = new DbHelper();
        $this->ytdl = $ytdl;
        $this->aria2 = $aria2;
        $this->aria2->init();
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function Index(): JSONResponse
    {
        $data = $this->dbconn->getYtdlByUid($this->uid);
        if (count($data) < 1) {
            return new JSONResponse([]);
        }
        $resp = [
            'title' => [],
            'row' => [],
        ];
        foreach ($data as $value) {
            $tmp = [];
            $extra = $this->dbconn->getExtra($value['data'] ?? null);
            $dirParam = (string) ($extra['path'] ?? $this->downloadDir);
            $folderLink = $this->urlGenerator->linkToRoute('files.view.index', ['dir' => $dirParam]);

            $safeHref = htmlspecialchars($folderLink, ENT_QUOTES, 'UTF-8');
            $safeName = htmlspecialchars((string) ($value['filename'] ?? ''), ENT_QUOTES, 'UTF-8');
            $safeLink = htmlspecialchars((string) ($extra['link'] ?? ''), ENT_QUOTES, 'UTF-8');
            $safeSize = htmlspecialchars((string) ($value['filesize'] ?? ''), ENT_QUOTES, 'UTF-8');

            $filename = sprintf('<a class="download-file-folder" href="%s">%s</a>', $safeHref, $safeName);
            $fileInfo = sprintf(
                '<div class="ncd-file-info"><button id="icon-clipboard" class="icon-clipboard" data-text="%s"></button> %s | %s</div>',
                $safeLink,
                $safeSize,
                date('Y-m-d H:i:s', (int) ($value['timestamp'] ?? time()))
            );
            $tmp['filename'] = [$filename, $fileInfo];
            $tmp['speed'] = explode('|', (string) ($value['speed'] ?? '0'));
            $tmp['progress'] = $value['progress'] ?? '0';

            $deletePath = $this->urlGenerator->linkToRoute('ncdownloader.Ytdl.Delete');
            $tmp['actions'][] = ['name' => 'delete', 'path' => $deletePath];
            $redownloadPath = $this->urlGenerator->linkToRoute('ncdownloader.Ytdl.Redownload');
            $tmp['actions'][] = ['name' => 'refresh', 'path' => $redownloadPath];

            $tmp['data_gid'] = $value['gid'] ?? 0;
            $resp['row'][] = $tmp;
        }

        $resp['title'] = ['filename', 'speed', 'progress', 'actions'];
        $resp['counter'] = ['ytdl' => count($data)];
        folderScan::sync(false, $this->uid);
        return new JSONResponse($resp);
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function Download(string $url = '', ?string $extension = 'mp4'): JSONResponse
    {
        $url = trim($url !== '' ? $url : (string) $this->request->getParam('url', ''));
        $check = UrlValidator::validate($url, ['http', 'https']);
        if (!$check['valid']) {
            return new JSONResponse(['error' => $check['error'] ?? 'Invalid URL']);
        }

        $dlDir = Helper::getRealDownloadDir($this->uid);
        if ($dlDir !== '') {
            $this->ytdl->setDownloadDir($dlDir);
        } else {
            $dlDir = $this->ytdl->getDownloadDir();
        }
        if (!is_writable($dlDir)) {
            return new JSONResponse(['error' => sprintf('%s is not writable', $dlDir)]);
        }

        $extension = strtolower(trim((string) ($extension ?? 'mp4')));
        $yt = $this->ytdl;
        if (in_array($extension, $this->audio_extensions, true)) {
            $yt->audioOnly = true;
            $yt->audioFormat = $extension;
        } else {
            $yt->audioOnly = false;
            $yt->videoFormat = in_array($extension, $this->video_extensions, true) ? $extension : 'mp4';
        }
        if (!$yt->isInstalled()) {
            return new JSONResponse([
                'error' => 'Please install yt-dlp on the server or download it from NCDownloader admin settings',
            ]);
        }
        if (Helper::isGetUrlSite($url)) {
            return new JSONResponse($this->downloadUrlSite($url));
        }

        $yt->dbDlPath = $this->downloadDir;
        $resp = $yt->forceIPV4()->download($url);
        if (!isset($resp['error'])) {
            folderScan::scanTarget(
                $this->uid,
                $this->downloadDir,
                isset($resp['filename']) && is_string($resp['filename']) ? $resp['filename'] : null
            );
        }
        return new JSONResponse($resp);
    }

    private function downloadUrlSite(string $url): array
    {
        $data = $this->ytdl->forceIPV4()->getDownloadUrl($url);
        if (!empty($data['url'])) {
            return $this->_download((string) $data['url'], $data['filename'] ?? null);
        }
        return ['error' => $this->l10n->t('Failed to extract media URL')];
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function Delete(string $gid = ''): JSONResponse
    {
        $gid = trim($gid !== '' ? $gid : (string) $this->request->getParam('gid', ''));
        if ($gid === '') {
            return new JSONResponse(['error' => 'No gid value received']);
        }

        $row = $this->dbconn->getByGid($gid);
        if (!is_array($row) || (($row['uid'] ?? '') !== $this->uid && !Helper::isAdmin($this->uid))) {
            return new JSONResponse(['error' => 'Not found'], 404);
        }

        $data = $this->dbconn->getExtra($row['data'] ?? null);
        $msg = 'Removed';
        if (!isset($data['pid'])) {
            if ($this->dbconn->deleteByGid($gid)) {
                $msg = sprintf('%s deleted from database', $gid);
            }
            return new JSONResponse(['message' => $msg]);
        }

        $pid = (int) $data['pid'];
        if (!Helper::isRunning($pid)) {
            if ($this->dbconn->deleteByGid($gid)) {
                $msg = sprintf('%s deleted from database', $gid);
            } else {
                $msg = sprintf('Process %d is not running', $pid);
            }
        } else {
            if (Helper::stop($pid)) {
                $msg = sprintf('Process %d terminated', $pid);
            } else {
                $msg = sprintf('Failed to terminate process %d', $pid);
            }
            $this->dbconn->deleteByGid($gid);
        }
        return new JSONResponse(['message' => $msg]);
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function Redownload(string $gid = ''): JSONResponse
    {
        $gid = trim($gid !== '' ? $gid : (string) $this->request->getParam('gid', ''));
        if ($gid === '') {
            return new JSONResponse(['error' => 'No gid value received']);
        }
        $row = $this->dbconn->getByGid($gid);
        if (!is_array($row) || (($row['uid'] ?? '') !== $this->uid && !Helper::isAdmin($this->uid))) {
            return new JSONResponse(['error' => 'Not found'], 404);
        }
        $data = $this->dbconn->getExtra($row['data'] ?? null);
        if (!empty($data['link'])) {
            $link = (string) $data['link'];
            $check = UrlValidator::validate($link, ['http', 'https']);
            if (!$check['valid']) {
                return new JSONResponse(['error' => $check['error'] ?? 'Invalid URL']);
            }
            if (isset($data['ext'])) {
                if (in_array($data['ext'], $this->audio_extensions, true)) {
                    $this->ytdl->audioOnly = true;
                    $this->ytdl->audioFormat = $data['ext'];
                } else {
                    $this->ytdl->audioOnly = false;
                    $this->ytdl->videoFormat = $data['ext'];
                }
            }
            $this->ytdl->dbDlPath = $this->downloadDir;
            $resp = $this->ytdl->forceIPV4()->download($link);
            if (!isset($resp['error'])) {
                folderScan::scanTarget(
                    $this->uid,
                    $this->downloadDir,
                    isset($resp['filename']) && is_string($resp['filename']) ? $resp['filename'] : null
                );
            }
            return new JSONResponse($resp);
        }
        return new JSONResponse(['error' => 'No link']);
    }

    private function _download(string $url, ?string $filename = null): array
    {
        $check = UrlValidator::validate($url, ['http', 'https']);
        if (!$check['valid']) {
            return ['error' => $check['error'] ?? 'Invalid URL'];
        }
        if (!$filename) {
            $filename = Helper::getFilename($url);
        }
        $this->aria2->setFileName($filename);

        $result = $this->aria2->download($url);
        if (!$result) {
            return ['error' => 'Failed to start download'];
        }
        if (is_array($result) && isset($result['error'])) {
            return $result;
        }

        $data = [
            'uid' => $this->uid,
            'gid' => (string) $result,
            'type' => Helper::DOWNLOADTYPE['ARIA2'],
            'filename' => $filename ?: 'unknown',
            'timestamp' => time(),
            'data' => ['link' => $url, 'path' => $this->downloadDir],
        ];
        $this->dbconn->save($data);
        return ['message' => $filename, 'result' => $result];
    }
}
