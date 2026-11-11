<?php

namespace OCA\NCDownloader\Db;

use OCA\NCDownloader\Tools\Helper as ToolsHelper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;

class Helper
{
    private IDBConnection $conn;
    private string $table = 'ncdownloader_info';
    public IQueryBuilder $queryBuilder;

    public function __construct(?IDBConnection $conn = null)
    {
        $this->conn = $conn ?? \OC::$server->get(IDBConnection::class);
        $this->queryBuilder = $this->conn->getQueryBuilder();
    }

    public function encodeExtra($extra): string
    {
        if (is_array($extra)) {
            return base64_encode(serialize($extra));
        }
        if (is_string($extra) && $extra !== '') {
            return base64_encode($extra);
        }
        return '';
    }

    public function insert(array $insert): bool
    {
        $gid = (string) ($insert['gid'] ?? '');
        if ($gid === '') {
            return false;
        }
        if ($this->getByGid($gid)) {
            return false;
        }

        if (array_key_exists('data', $insert) && $insert['data'] !== null) {
            $insert['data'] = $this->encodeExtra($insert['data']);
        }

        $qb = $this->conn->getQueryBuilder();
        $qb->insert($this->table);
        $values = [];
        foreach ($insert as $col => $val) {
            if ($col === 'data' && $val !== null) {
                $values[$col] = $qb->createNamedParameter($val, IQueryBuilder::PARAM_LOB);
            } elseif (is_int($val)) {
                $values[$col] = $qb->createNamedParameter($val, IQueryBuilder::PARAM_INT);
            } elseif ($val === null) {
                $values[$col] = $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL);
            } else {
                $values[$col] = $qb->createNamedParameter((string) $val, IQueryBuilder::PARAM_STR);
            }
        }
        $qb->values($values);
        return $qb->executeStatement() > 0;
    }

    public function getAll(): array
    {
        $qb = $this->conn->getQueryBuilder();
        $result = $qb
            ->select('filename', 'type', 'gid', 'timestamp', 'status')
            ->from($this->table)
            ->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }

    public function getByUid(string $uid): array
    {
        $qb = $this->conn->getQueryBuilder();
        $result = $qb
            ->select('*')
            ->from($this->table)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
            ->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }

    public function getUidByGid(string $gid)
    {
        $qb = $this->conn->getQueryBuilder();
        $result = $qb
            ->select('uid')
            ->from($this->table)
            ->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
            ->executeQuery();
        $val = $result->fetchOne();
        $result->closeCursor();
        return $val;
    }

    public function getYtdlByUid(string $uid): array
    {
        $qb = $this->conn->getQueryBuilder();
        $result = $qb
            ->select('*')
            ->from($this->table)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->eq('type', $qb->createNamedParameter(ToolsHelper::DOWNLOADTYPE['YOUTUBE-DL'], IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'DESC')
            ->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }

    public function getByGid(string $gid)
    {
        $qb = $this->conn->getQueryBuilder();
        $result = $qb
            ->select('*')
            ->from($this->table)
            ->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
            ->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row;
    }

    public function save(array $data): bool
    {
        return $this->insert($data);
    }

    public function deleteByGid(string $gid): int
    {
        $qb = $this->conn->getQueryBuilder();
        return $qb
            ->delete($this->table)
            ->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
            ->executeStatement();
    }

    public function updateStatus(string $gid, int $status = 1): int
    {
        $qb = $this->conn->getQueryBuilder();
        return $qb
            ->update($this->table)
            ->set('status', $qb->createNamedParameter($status, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
            ->executeStatement();
    }

    public function updateFilename(string $gid, string $filename, bool $onlyIfUnknown = true): int
    {
        $qb = $this->conn->getQueryBuilder();
        $qb->update($this->table)
            ->set('filename', $qb->createNamedParameter($filename))
            ->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)));
        if ($onlyIfUnknown) {
            $qb->andWhere($qb->expr()->eq('filename', $qb->createNamedParameter('unknown')));
        }
        return $qb->executeStatement();
    }

    public function updateProgress(string $gid, string $filesize, string $speed, string $progress): int
    {
        $qb = $this->conn->getQueryBuilder();
        return $qb
            ->update($this->table)
            ->set('filesize', $qb->createNamedParameter($filesize))
            ->set('speed', $qb->createNamedParameter($speed))
            ->set('progress', $qb->createNamedParameter($progress))
            ->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
            ->executeStatement();
    }

    public function getDBType(): string
    {
        return (string) \OC::$server->get(IConfig::class)->getSystemValue('dbtype', 'mysql');
    }

    public function getExtra($data): array
    {
        if ($data === null || $data === '') {
            return [];
        }
        if (is_resource($data)) {
            $raw = (string) stream_get_contents($data);
        } else {
            $raw = (string) $data;
        }
        if ($raw === '') {
            return [];
        }

        // PostgreSQL hex bytea format (\x...)
        if (str_starts_with($raw, '\\x')) {
            $hexDecoded = @hex2bin(substr($raw, 2));
            if (is_string($hexDecoded) && $hexDecoded !== '') {
                $raw = $hexDecoded;
            }
        }

        // Base64-encoded serialized payload
        $b64 = base64_decode($raw, true);
        if (is_string($b64) && $b64 !== '') {
            $parsed = @unserialize($b64, ['allowed_classes' => false]);
            if (is_array($parsed)) {
                return $parsed;
            }
        }

        // Legacy direct serialized payload
        $parsed = @unserialize($raw, ['allowed_classes' => false]);
        return is_array($parsed) ? $parsed : [];
    }
}
