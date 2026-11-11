<?php

namespace OCA\NCDownloader\Search\Sites;

use OCA\NCDownloader\Tools\Helper;
use OCA\NCDownloader\Tools\tableData;

class sliderkz extends searchBase implements searchInterface
{
    public string $baseUrl = 'https://hayqbhgr.slider.kz/vk_auth.php';
    protected $query = null;
    protected array $tableTitles = [];

    public function __construct($crawler, $client)
    {
        $this->client = $client;
    }

    public function search(string $keyword): tableData
    {
        $this->query = ['q' => trim($keyword)];
        $this->searchUrl = $this->baseUrl;
        $this->getItems()->setTableTitles(['Title', 'Duration', 'Actions'])->addActionLinks();
        if ($this->hasErrors()) {
            return tableData::create()->setError($this->getErrors());
        }
        return tableData::create($this->getTableTitles(), $this->getRows());
    }

    public function getItems(): self
    {
        $data = $this->getResponse();
        $this->rows = $this->transformResp($data);
        return $this;
    }

    protected function getDownloadUrl(array $item): string
    {
        $id = $item['id'] ?? '';
        $duration = $item['duration'] ?? 0;
        $durl = $item['durl'] ?? '';
        $titArt = (string) ($item['tit_art'] ?? '');
        return sprintf('https://hayqbhgr.slider.kz/%s/%s/%s/%s.mp3?extra=null', $id, $duration, $durl, urlencode($titArt));
    }

    private function transformResp(array $data): array
    {
        $items = [];
        if (count($data) < 1 || $this->hasErrors()) {
            return [];
        }
        foreach ($data as $item) {
            if (empty($item) || !is_array($item)) {
                continue;
            }
            $items[] = [
                'title' => $item['tit_art'] ?? '',
                'data-link' => $this->getDownloadUrl($item),
                'duration' => Helper::formatInterval($item['duration'] ?? 0),
            ];
        }
        return $items;
    }

    public function getResponse(): array
    {
        try {
            $response = $this->client->request('GET', $this->searchUrl, ['query' => $this->query]);
            $resp = $response->toArray();
            if (isset($resp['audios']) && is_array($resp['audios']) && !empty($resp['audios'])) {
                $first = array_values($resp['audios'])[0] ?? [];
                return is_array($first) ? $first : [];
            }
        } catch (\Throwable $e) {
            $this->errors[] = $e->getMessage();
        }
        return [];
    }

    public static function getLabel(): string
    {
        return 'music';
    }
}
