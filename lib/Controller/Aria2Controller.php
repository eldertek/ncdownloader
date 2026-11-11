<?php

namespace OCA\NCDownloader\Controller;

use OCA\NCDownloader\AppInfo\Application;
use OCA\NCDownloader\Aria2\Aria2;
use OCA\NCDownloader\Db\Helper as DbHelper;
use OCA\NCDownloader\Tools\Counters;
use OCA\NCDownloader\Tools\folderScan;
use OCA\NCDownloader\Tools\Helper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

class Aria2Controller extends Controller
{
    private string $uid;
    private IL10N $l10n;
    private array $minmax = [-1, 999];
    private Aria2 $aria2;
    private DbHelper $dbconn;
    private Counters $counters;
    private IRootFolder $rootFolder;
    private string $downloadDir;
    private IURLGenerator $urlGenerator;

    public function __construct(
        IRequest $request,
        IUserSession $userSession,
        IL10N $l10n,
        IRootFolder $rootFolder,
        IURLGenerator $urlGenerator,
        Aria2 $aria2
    ) {
        parent::__construct(Application::APP_ID, $request);
        $user = $userSession->getUser();
        $this->uid = $user ? $user->getUID() : '';
        $this->l10n = $l10n;
        $this->rootFolder = $rootFolder;
        $this->urlGenerator = $urlGenerator;
        $this->downloadDir = Helper::getDownloadDir($this->uid);
        $this->aria2 = $aria2;
        $this->aria2->init();
        $this->dbconn = new DbHelper();
        $this->counters = new Counters($aria2, $this->dbconn, $this->uid);
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function Action(string $path): JSONResponse
    {
        $path = strtolower(trim($path));
        $resp = [];
        $gid = (string) ($this->request->getParam('gid') ?? '');

        if (!in_array($path, ['start', 'check'], true)) {
            if ($gid === '') {
                return new JSONResponse(['error' => 'No gid value received']);
            }
            if ($this->dbconn->getUidByGid($gid) !== $this->uid && !Helper::isAdmin($this->uid)) {
                return new JSONResponse(['error' => 'Forbidden'], 403);
            }
        }

        switch ($path) {
            case 'check':
                $resp = $this->aria2->isRunning();
                break;
            case 'start':
                if (!Helper::isAdmin($this->uid)) {
                    return new JSONResponse(['error' => 'Admin access required'], 403);
                }
                $resp = $this->Start();
                break;
            case 'unpause':
            case 'remove':
            case 'pause':
                $resp = $this->doAction($path, $gid);
                if ($path === 'remove' && !empty($resp['status'])) {
                    $this->dbconn->deleteByGid($gid);
                }
                break;
            case 'get':
                $resp = $this->doAction('tellStatus', $gid);
                break;
            case 'purge':
                $resp = $this->doAction('removeDownloadResult', $gid);
                if (!empty($resp['status'])) {
                    $this->dbconn->deleteByGid($gid);
                }
                break;
        }
        return new JSONResponse($resp);
    }

    private function doAction(string $action, string $gid): array
    {
        if ($action === '' || $gid === '') {
            return [];
        }
        $resp = $this->aria2->{$action}($gid);
        if (is_array($resp) && isset($resp['result'])) {
            switch ($action) {
                case 'pause':
                    $this->dbconn->updateStatus($gid, Helper::STATUS['PAUSED']);
                    break;
                case 'unpause':
                    $this->dbconn->updateStatus($gid, Helper::STATUS['ACTIVE']);
                    break;
            }
        }
        if (in_array($action, ['removeDownloadResult', 'remove'], true)) {
            $result = is_array($resp) ? ($resp['result'] ?? null) : null;
            if ($result !== null && is_string($result)) {
                return ['message' => $this->l10n->t('DONE!'), 'status' => 1];
            }
            return ['error' => $this->l10n->t('FAILED!'), 'status' => 0];
        }
        return is_array($resp) ? $resp : [];
    }

    private function Start(): array
    {
        if ($this->aria2->isRunning()) {
            return ['status' => (bool) $this->aria2->stop()];
        }
        return $this->aria2->start();
    }

    private function createActionItem(string $name, string $path): array
    {
        return [
            'name' => $name,
            'path' => $this->urlGenerator->linkToRoute('ncdownloader.Aria2.Action', ['path' => $path]),
        ];
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function getStatus(string $path): JSONResponse
    {
        $counter = $this->counters->getCounters();
        folderScan::sync(false, $this->uid);

        switch (strtolower($path)) {
            case 'active':
                $resp = $this->aria2->tellActive();
                break;
            case 'waiting':
                $resp = $this->aria2->tellWaiting($this->minmax);
                break;
            case 'complete':
                $resp = $this->aria2->tellStopped($this->minmax);
                break;
            case 'fail':
                $resp = $this->aria2->tellFail($this->minmax);
                break;
            default:
                $resp = $this->aria2->tellActive();
        }
        if (is_array($resp) && isset($resp['error'])) {
            return new JSONResponse($resp);
        }
        $data = $this->transformResp(is_array($resp) ? $resp : []);
        $data['counter'] = $counter;
        return new JSONResponse($data);
    }

    private function transformResp(array $resp): array
    {
        $data = [];
        if (empty($resp)) {
            return $data;
        }
        $data['row'] = [];
        $resp = $this->filterData($resp);

        $userFolder = null;
        if ($this->uid !== '') {
            try {
                $userFolder = $this->rootFolder->getUserFolder($this->uid);
            } catch (\Throwable $e) {
                $userFolder = null;
            }
        }

        foreach ($resp as $value) {
            if (!is_array($value)) {
                continue;
            }
            $gid = (string) ($value['following'] ?? $value['gid'] ?? '');
            $timestamp = 0;
            $extra = [];
            $rawFilename = 'Unknown';

            $row = $gid !== '' ? $this->dbconn->getByGid($gid) : false;
            if (is_array($row)) {
                $rawFilename = (string) ($row['filename'] ?? 'Unknown');
                $timestamp = (int) ($row['timestamp'] ?? 0);
                $extra = $this->dbconn->getExtra($row['data'] ?? null);
            }
            if (($rawFilename === '' || strtolower($rawFilename) === 'unknown') && !empty($value['files'][0]['path'])) {
                $filePath = (string) $value['files'][0]['path'];
                $rawFilename = basename($filePath);
                if ($gid !== '' && $rawFilename !== '') {
                    $this->dbconn->updateFilename($gid, $rawFilename, true);
                }
            }

            if (!isset($value['completedLength'])) {
                continue;
            }

            $dlDir = (string) ($extra['path'] ?? $this->downloadDir);
            $relItemPath = trim($dlDir, '/') . '/' . $rawFilename;

            // Ensure completed files are indexed into Nextcloud Files cache immediately
            if (strtolower((string) ($value['status'] ?? '')) === 'complete' && $userFolder !== null && $rawFilename !== 'Unknown') {
                if (!$userFolder->nodeExists($relItemPath)) {
                    folderScan::scanTarget($this->uid, $dlDir, $rawFilename);
                }
            }

            $params = ['dir' => $dlDir];
            if ($userFolder !== null && $userFolder->nodeExists($relItemPath)) {
                try {
                    $node = $userFolder->get($relItemPath);
                    if ($node instanceof Folder) {
                        $params = ['dir' => '/' . $relItemPath];
                    }
                } catch (\Throwable $e) {
                }
            }
            $folderLink = $this->urlGenerator->linkToRoute('files.view.index', $params);

            $completedBytes = (float) ($value['completedLength'] ?? 0);
            $totalBytes = (float) ($value['totalLength'] ?? 0);
            $dlSpeed = (float) ($value['downloadSpeed'] ?? 0);

            $completed = Helper::formatBytes($completedBytes);
            $percentage = $totalBytes > 0 ? 100.0 * ($completedBytes / $totalBytes) : 0.0;
            $total = Helper::formatBytes($totalBytes);

            $remainingBytes = max(0.0, $totalBytes - $completedBytes);
            $remainingSec = $dlSpeed > 0 ? ($remainingBytes / $dlSpeed) : 0;
            $left = Helper::formatInterval($remainingSec);

            $numSeeders = (int) ($value['numSeeders'] ?? 0);
            $upload = Helper::formatBytes((float) ($value['uploadLength'] ?? 0));
            $extraInfo = "Seeders: $numSeeders|Up:$upload";
            $progressDisplay = [sprintf('%s(%.2f%%)', $completed, $percentage), $extraInfo];

            $safeHref = htmlspecialchars($folderLink, ENT_QUOTES, 'UTF-8');
            $safeName = htmlspecialchars($rawFilename, ENT_QUOTES, 'UTF-8');
            $safeLink = htmlspecialchars((string) ($extra['link'] ?? 'nolink'), ENT_QUOTES, 'UTF-8');

            $tmp = [];
            $actions = [];
            $filenameHtml = sprintf('<a class="download-file-folder" href="%s">%s</a>', $safeHref, $safeName);
            $fileInfoHtml = sprintf(
                '<button id="icon-clipboard" class="icon-clipboard" data-text="%s"></button> %s | %s',
                $safeLink,
                htmlspecialchars($total, ENT_QUOTES, 'UTF-8'),
                date('Y-m-d H:i:s', $timestamp > 0 ? $timestamp : time())
            );
            $tmp['filename'] = [$filenameHtml, $fileInfoHtml];

            if ($this->aria2->methodName === 'tellStopped') {
                $actions[] = $this->createActionItem('purge', 'purge');
            }
            if ($this->aria2->methodName === 'tellWaiting') {
                $actions[] = $this->createActionItem('unpause', 'unpause');
                $actions[] = $this->createActionItem('delete', 'remove');
            }
            if ($this->aria2->methodName === 'tellActive') {
                $tmp['speed'] = [Helper::formatBytes($dlSpeed), $left];
                $tmp['progress'] = $progressDisplay;
                $actions[] = $this->createActionItem('pause', 'pause');
            }
            if (strtolower((string) ($value['status'] ?? '')) === 'error') {
                $tmp['status'] = (string) ($value['errorMessage'] ?? 'error');
            } elseif ($this->aria2->methodName !== 'tellActive') {
                $tmp['status'] = (string) ($value['status'] ?? '');
            }
            $tmp['data_gid'] = $value['gid'] ?? 0;
            $tmp['actions'] = $actions;
            $data['row'][] = $tmp;
        }

        $data['title'] = $this->aria2->methodName === 'tellActive'
            ? Helper::getTableTitles('active')
            : Helper::getTableTitles();

        return $data;
    }

    private function filterData(array $resp): array
    {
        if (empty($resp) || isset($resp['error'])) {
            return $resp;
        }
        return array_values(array_filter($resp, function ($value) {
            if (!is_array($value)) {
                return false;
            }
            $gid = (string) ($value['following'] ?? $value['gid'] ?? '');
            return $gid !== '' && $this->dbconn->getUidByGid($gid) === $this->uid;
        }));
    }
}
