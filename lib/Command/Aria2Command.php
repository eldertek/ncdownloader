<?php

namespace OCA\NCDownloader\Command;

use OCA\NCDownloader\Db\Helper as DbHelper;
use OCA\NCDownloader\Tools\folderScan;
use OCA\NCDownloader\Tools\Helper;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Aria2Command extends Command
{
    private DbHelper $dbconn;

    public function __construct(IDBConnection $connection)
    {
        $this->dbconn = new DbHelper($connection);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('aria2')
            ->setDescription('Aria2 event hooks')
            ->addArgument(
                'action',
                InputArgument::OPTIONAL,
                'Aria2 hook name: start, complete, error'
            )
            ->addArgument(
                'gid',
                InputArgument::OPTIONAL,
                'Aria2 GID'
            )
            ->addArgument(
                'numFiles',
                InputArgument::OPTIONAL,
                'Number of files (or file path if called manually)',
                '1'
            )
            ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                'Downloaded file path'
            );
    }

    private function inferUidFromPath(string $filePath): string
    {
        if ($filePath === '') {
            return '';
        }
        $normPath = str_replace('\\', '/', $filePath);
        $dataDir = rtrim(str_replace('\\', '/', Helper::getDataDir()), '/');
        if ($dataDir !== '' && str_starts_with($normPath, $dataDir . '/')) {
            $rest = substr($normPath, strlen($dataDir) + 1);
            $parts = explode('/', $rest);
            if (count($parts) >= 2 && $parts[1] === 'files' && $parts[0] !== '') {
                return $parts[0];
            }
        }
        return '';
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = strtolower((string) ($input->getArgument('action') ?: 'start'));
        $gid = $input->getArgument('gid');
        if (!is_string($gid) || $gid === '') {
            return Command::SUCCESS;
        }

        $argNum = (string) ($input->getArgument('numFiles') ?? '');
        $argPath = (string) ($input->getArgument('path') ?? '');
        $filePath = $argPath !== '' ? $argPath : (!ctype_digit($argNum) ? $argNum : '');

        if (in_array($action, ['complete', 'error'], true)) {
            $row = $this->dbconn->getByGid($gid);
            if (!$row) {
                usleep(200000);
                $row = $this->dbconn->getByGid($gid);
            }

            $statusKey = strtoupper($action);
            $this->dbconn->updateStatus($gid, Helper::STATUS[$statusKey]);

            if ($filePath !== '') {
                $this->dbconn->updateFilename($gid, basename($filePath), true);
            }

            if ($action === 'complete') {
                $uid = is_array($row) && !empty($row['uid'])
                    ? (string) $row['uid']
                    : $this->inferUidFromPath($filePath);

                if ($uid !== '') {
                    $extra = is_array($row) ? $this->dbconn->getExtra($row['data'] ?? null) : [];
                    $dlDir = (string) ($extra['path'] ?? Helper::getDownloadDir($uid));
                    if ($filePath !== '' && file_exists($filePath)) {
                        if (!folderScan::scanAbsolutePath($uid, $filePath)) {
                            folderScan::scanTarget($uid, $dlDir, basename($filePath));
                        }
                    } else {
                        $filename = is_array($row) ? (string) ($row['filename'] ?? '') : '';
                        folderScan::scanTarget($uid, $dlDir, ($filename !== '' && $filename !== 'unknown') ? $filename : null);
                    }
                }
            }
        } elseif ($action === 'start') {
            if ($filePath !== '') {
                $this->dbconn->updateFilename($gid, basename($filePath), true);
            }
        }

        return Command::SUCCESS;
    }
}
