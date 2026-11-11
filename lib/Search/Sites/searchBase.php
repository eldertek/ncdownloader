<?php

namespace OCA\NCDownloader\Search\Sites;

use OCP\IURLGenerator;

abstract class searchBase
{
    protected $query = null;
    protected array $tableTitles = [];
    protected array $rows = [];
    protected array $errors = [];
    protected $searchUrl;
    protected $crawler;
    protected $client;
    protected array $actionLinks = [
        ['name' => 'download', 'path' => '/index.php/apps/ncdownloader/new'],
        ['name' => 'clipboard'],
    ];

    protected function getDefaultActionLinks(): array
    {
        try {
            $path = \OC::$server->get(IURLGenerator::class)->linkToRoute('ncdownloader.main.Download');
            return [
                ['name' => 'download', 'path' => $path],
                ['name' => 'clipboard'],
            ];
        } catch (\Throwable $e) {
            return $this->actionLinks;
        }
    }

    public function getTableTitles(): array
    {
        if (empty($this->tableTitles)) {
            return ['title', 'seeders', 'info', 'actions'];
        }
        return $this->tableTitles;
    }

    public static function create($crawler, $client)
    {
        return new static($crawler, $client);
    }

    public function setTableTitles(array $titles)
    {
        $this->tableTitles = $titles;
        return $this;
    }

    protected function addActionLinks(?array $links = null): void
    {
        $links = $links ?? $this->getDefaultActionLinks();
        foreach ($this->rows as &$value) {
            if (!$value || !is_array($value)) {
                continue;
            }
            $value['actions'] = $links;
        }
    }

    public function getRows(): array
    {
        return array_values(array_filter($this->rows));
    }

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    public function getErrors(): string
    {
        return implode(', ', $this->errors);
    }
}
