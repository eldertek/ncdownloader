<?php

namespace OCA\NCDownloader\Tools;

use OCP\Files\Cache\IScanner;
use OCP\Files\IRootFolder;

class folderScan
{
    private string $user;
    private string $relativeDir;
    private string $realDir;

    public function __construct(?string $path = null, ?string $user = null)
    {
        $this->user = $user ?? Helper::getUID();
        $dlDir = Helper::getDownloadDir($this->user);
        if ($path !== null && $path !== '') {
            $userPrefix = '/' . $this->user . '/files';
            if (str_starts_with($path, $userPrefix)) {
                $this->relativeDir = substr($path, strlen($userPrefix)) ?: '/';
            } else {
                $this->relativeDir = $path;
            }
        } else {
            $this->relativeDir = $dlDir;
        }
        $this->realDir = $this->user !== '' ? Helper::getLocalFolder($this->relativeDir, $this->user) : '';
    }

    public function getDefaultPath(): string
    {
        return Helper::getUserFolder($this->user) . $this->relativeDir;
    }

    public static function create(?string $path = null, ?string $user = null): static
    {
        return new static($path, $user);
    }

    public function setUser(string $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function setPath(string $path): self
    {
        $this->relativeDir = $path;
        return $this;
    }

    private function update(): array
    {
        if (!Helper::folderUpdated($this->realDir)) {
            return ['status' => true, 'message' => 'no change'];
        }
        $this->scan();
        return ['status' => true, 'message' => 'changed'];
    }

    public function scan(?string $targetName = null): array
    {
        if ($this->user === '') {
            return ['status' => false, 'path' => $this->relativeDir];
        }
        $logger = Helper::getLogger();
        try {
            $folder = Helper::ensureUserSubFolder($this->relativeDir, $this->user);
            if ($folder === null) {
                return ['status' => false, 'path' => $this->relativeDir];
            }
            $storage = $folder->getStorage();
            $scanner = $storage->getScanner();
            $internalDir = rtrim($folder->getInternalPath(), '/');

            if ($targetName !== null && $targetName !== '' && $targetName !== 'unknown') {
                $cleanName = trim(str_replace('\\', '/', $targetName), '/');
                $targetInternal = $internalDir . '/' . $cleanName;
                $scanner->scan($targetInternal, IScanner::SCAN_RECURSIVE);
                $scanner->scan($internalDir, IScanner::SCAN_SHALLOW);
            } else {
                $scanner->scan($internalDir, IScanner::SCAN_RECURSIVE);
            }
            $storage->getCache()->correctFolderSize($internalDir);
            return ['status' => true, 'path' => $folder->getPath()];
        } catch (\Throwable $e) {
            $logger->warning('Exception during folder scan: ' . $e->getMessage(), ['app' => 'ncdownloader']);
        }
        return ['status' => false, 'path' => $this->relativeDir];
    }

    /**
     * Targeted scan of a newly completed download file or directory inside a user's Nextcloud storage.
     */
    public static function scanTarget(?string $user, string $relativeDir, ?string $filename = null): array
    {
        if ($user === null || $user === '') {
            return ['status' => false];
        }
        $inst = new static($relativeDir, $user);
        return $inst->scan($filename);
    }

    /**
     * Scan an absolute local path belonging to a Nextcloud user's files directory.
     */
    public static function scanAbsolutePath(string $uid, string $absoluteLocalPath): bool
    {
        if ($uid === '' || $absoluteLocalPath === '') {
            return false;
        }
        try {
            /** @var IRootFolder $root */
            $root = \OC::$server->get(IRootFolder::class);
            $userFolder = $root->getUserFolder($uid);
            $storage = $userFolder->getStorage();
            $userRootLocal = $storage->getLocalFile($userFolder->getInternalPath());
            if (!is_string($userRootLocal) || $userRootLocal === '') {
                return false;
            }
            $normRoot = rtrim(str_replace('\\', '/', $userRootLocal), '/');
            $normTarget = str_replace('\\', '/', $absoluteLocalPath);
            if (!str_starts_with($normTarget, $normRoot . '/')) {
                return false;
            }
            $relWithinUser = ltrim(substr($normTarget, strlen($normRoot)), '/');
            if ($relWithinUser === '') {
                return false;
            }
            $parentRel = dirname($relWithinUser);
            if ($parentRel !== '.' && $parentRel !== '') {
                Helper::ensureUserSubFolder($parentRel, $uid);
            }
            $internalBase = rtrim($userFolder->getInternalPath(), '/');
            $scanner = $storage->getScanner();
            $scanner->scan($internalBase . '/' . $relWithinUser, IScanner::SCAN_RECURSIVE);
            if ($parentRel !== '.' && $parentRel !== '') {
                $scanner->scan($internalBase . '/' . $parentRel, IScanner::SCAN_SHALLOW);
                $storage->getCache()->correctFolderSize($internalBase . '/' . $parentRel);
            } else {
                $scanner->scan($internalBase, IScanner::SCAN_SHALLOW);
                $storage->getCache()->correctFolderSize($internalBase);
            }
            return true;
        } catch (\Throwable $e) {
            Helper::debug($e->getMessage());
            return false;
        }
    }

    public static function sync(bool $force = false, ?string $path = null, ?string $user = null): array
    {
        $inst = self::create($path, $user);
        return $force ? $inst->scan() : $inst->update();
    }
}
