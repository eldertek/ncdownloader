<?php

namespace OCA\NCDownloader\Ytdl;

use OCA\NCDownloader\Db\Helper as DbHelper;
use OCA\NCDownloader\Tools\Helper as ToolsHelper;
use OCP\IUserSession;

class Helper
{
    public ?string $file = null;
    protected int $pid = 0;
    protected DbHelper $dbconn;
    protected string $user;
    protected ?string $gid = null;
    protected int $status = ToolsHelper::STATUS['ACTIVE'];

    public function __construct(?string $uid = null)
    {
        $this->dbconn = new DbHelper();
        if ($uid !== null && $uid !== '') {
            $this->user = $uid;
        } else {
            $sessionUser = \OC::$server->get(IUserSession::class)->getUser();
            $this->user = $sessionUser ? $sessionUser->getUID() : '';
        }
    }

    public static function create(?string $uid = null): self
    {
        return new static($uid);
    }

    public function getDownloadInfo(string $output): ?array
    {
        $rules = '#\[(?<module>(download|ExtractAudio|VideoConvertor|VideoRemuxer|Merger|ffmpeg))\]((\s+|\s+Converting.*;\s+)Destination:\s+|\s+Merging formats into\s+\")'
            . '(?<filename>.*\.(?<ext>(mp4|mp3|aac|opus|webm|m4a|ogg|3gp|mkv|wav|flv)))#i';

        if (preg_match($rules, $output, $matches)) {
            return $matches;
        }
        return null;
    }

    public function getSiteInfo(string $buffer): ?array
    {
        $regex = '/\[(?<site>[^\]]+)\]\s(?<id>[^:\s]+):\sDownloading\s.*/i';
        if (preg_match($regex, $buffer, $matches)) {
            return ['id' => $matches['id'], 'site' => $matches['site']];
        }
        return null;
    }

    public function getProgress(string $buffer): ?array
    {
        $progressRegex = '#\[download\]\s+'
            . '(?<percentage>\d+(?:\.\d+)?%)'
            . '\s+of\s+[~]?\s*'
            . '(?<filesize>\d+(?:\.\d+)?(?:K|M|G|T)?i?B)'
            . '(?:\s+at\s+'
            . '(?<speed>(\d+(?:\.\d+)?(?:K|M|G|T)?i?B/s)|Unknown(?:\s+speed)?))?'
            . '(?:\s+ETA\s+(?<eta>([\d:]{2,8}|Unknown(?:\s+ETA)?)))?'
            . '(\s+in\s+(?<totalTime>[\d:]{2,8}))?#i';

        if (preg_match_all($progressRegex, $buffer, $matches, PREG_SET_ORDER) !== false) {
            if (count($matches) > 0) {
                return end($matches) ?: reset($matches);
            }
        }
        return null;
    }

    protected function updateProgress(array $data): void
    {
        if (!$this->gid) {
            return;
        }
        $filesize = (string) ($data['filesize'] ?? '');
        $speed = (string) ($data['speed'] ?? '');
        $percentage = (string) ($data['percentage'] ?? '');
        $this->dbconn->updateProgress($this->gid, $filesize, $speed, $percentage);
    }

    public function log(\Stringable|string $message): void
    {
        ToolsHelper::debug((string) $message);
    }

    public function updateStatus(?int $status = null): void
    {
        if ($status !== null) {
            $this->status = $status;
        }
        if ($this->gid) {
            $this->dbconn->updateStatus($this->gid, $this->status);
        }
    }

    public function setPid(int $pid): void
    {
        $this->pid = $pid;
    }

    public function run(string $buffer, array $extra): void
    {
        $info = $this->getSiteInfo($buffer);
        if (isset($info['id']) && !$this->gid) {
            $this->gid = ToolsHelper::generateGID($info['id']);
        }
        if (!$this->gid && !empty($extra['link'])) {
            $this->gid = ToolsHelper::generateGID((string) $extra['link']);
        }
        $downloadInfo = $this->getDownloadInfo($buffer);
        if ($downloadInfo) {
            $file = trim($downloadInfo['filename'], "\"' \t\r\n");
            $module = $downloadInfo['module'];
            $this->file = basename($file);
            if (strtolower($module) === 'download') {
                $this->save($file, $extra);
            } else {
                $this->updateFilename($file);
            }
        }
        $progress = $this->getProgress($buffer);
        if ($progress) {
            $this->updateProgress($progress);
        }
    }

    protected function save(string $file, array $extra): void
    {
        if (!$this->gid) {
            return;
        }
        $data = [
            'uid' => $this->user,
            'gid' => $this->gid,
            'type' => ToolsHelper::DOWNLOADTYPE['YOUTUBE-DL'],
            'filename' => basename($file),
            'status' => ToolsHelper::STATUS['ACTIVE'],
            'timestamp' => time(),
            'data' => $extra,
        ];
        $this->dbconn->insert($data);
    }

    private function updateFilename(string $file): void
    {
        if (!$this->gid) {
            return;
        }
        $this->dbconn->updateFilename($this->gid, basename($file), false);
    }
}
