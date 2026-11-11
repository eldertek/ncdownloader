<?php

namespace OCA\NCDownloader\Search;

use OCA\NCDownloader\Search\Sites\searchInterface;
use OCP\IServerContainer;

class siteSearch
{
    public IServerContainer $container;
    private string $site;
    private string $defaultSite = __NAMESPACE__ . '\\Sites\\TPB';

    public function __construct()
    {
        $this->container = \OC::$server->get(IServerContainer::class);
        $this->site = $this->defaultSite;
    }

    public function go(string $keyword): array
    {
        try {
            $siteInst = $this->container->get($this->site);
        } catch (\Throwable $e) {
            try {
                $siteInst = $this->container->get($this->defaultSite);
            } catch (\Throwable $inner) {
                return ['error' => $inner->getMessage()];
            }
        }

        try {
            $result = $siteInst->search($keyword);
            if ($result->hasError()) {
                return ['error' => $result->getError()];
            }
            return $result->getData();
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function setSite(string $site): void
    {
        $clean = preg_replace('/[^A-Za-z0-9_\\\\]/', '', $site);
        if (!str_contains($clean, '\\')) {
            $candidate = __NAMESPACE__ . '\\Sites\\' . $clean;
        } else {
            $candidate = $clean;
        }
        $prefix = __NAMESPACE__ . '\\Sites\\';
        if (str_starts_with($candidate, $prefix) && class_exists($candidate) && is_subclass_of($candidate, searchInterface::class)) {
            $this->site = $candidate;
        }
    }
}
