<?php

namespace OCA\NCDownloader\Tools;

use OCA\NCDownloader\Aria2\Aria2;
use OCA\NCDownloader\Db\Helper as DbHelper;

class Counters
{
    private array $minmax = [0, 999];
    private Aria2 $aria2;
    private DbHelper $dbconn;
    private string $uid;

    public function __construct(Aria2 $aria2, DbHelper $dbconn, ?string $uid)
    {
        $this->aria2 = $aria2;
        $this->dbconn = $dbconn;
        $this->uid = (string) ($uid ?? '');
    }

    public function getCounters(): array
    {
        return [
            'active' => $this->getCounter('tellActive'),
            'waiting' => $this->getCounter('tellWaiting'),
            'complete' => $this->getCounter('tellStopped'),
            'fail' => $this->getCounter('tellFail'),
            'ytdl' => $this->getCounter('ytdl'),
        ];
    }

    private function getCounter(string $action = 'tellActive'): int
    {
        if ($action === 'ytdl') {
            $data = $this->dbconn->getYtdlByUid($this->uid);
        } elseif ($action === 'tellActive') {
            $data = $this->aria2->{$action}([]);
        } else {
            $data = $this->aria2->{$action}($this->minmax);
        }

        if (!is_array($data) || isset($data['error']) || count($data) < 1) {
            return 0;
        }
        if ($action !== 'ytdl') {
            $data = $this->filterData($data);
        }
        return is_array($data) ? count($data) : 0;
    }

    private function filterData($resp): array
    {
        if (!is_array($resp) || empty($resp) || isset($resp['error'])) {
            return [];
        }

        return array_filter($resp, function ($value) {
            if (!is_array($value)) {
                return false;
            }
            $gid = $value['following'] ?? ($value['gid'] ?? '');
            return $gid !== '' && $this->dbconn->getUidByGid((string) $gid) === $this->uid;
        });
    }
}
