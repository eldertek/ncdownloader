<?php

namespace OCA\NCDownloader\Search\Sites;

use OCA\NCDownloader\Tools\tableData;

class bitSearch extends searchBase implements searchInterface
{
    private ?string $content = null;
    public string $baseUrl = 'https://bitsearch.to/search';
    protected $query = null;
    protected array $tableTitles = [];

    public function __construct($crawler, $client)
    {
        $this->client = $client;
        $this->crawler = $crawler;
    }

    public function search(string $keyword): tableData
    {
        $this->query = ['q' => trim($keyword), 'sort' => 'seeders'];
        $this->searchUrl = $this->baseUrl;
        $content = $this->getContent();
        if ($this->hasErrors()) {
            return tableData::create()->setError($this->getErrors());
        }
        if ($content !== '') {
            $this->crawler->clear();
            $this->crawler->add($content);
        }
        $this->getItems()->addActionLinks();
        return tableData::create($this->getTableTitles(), $this->getRows());
    }

    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    public function getContent(): string
    {
        if ($this->content !== null) {
            return $this->content;
        }
        try {
            $response = $this->client->request('GET', $this->searchUrl, ['query' => $this->query]);
            return $response->getContent();
        } catch (\Throwable $e) {
            $this->errors[] = $e->getMessage();
            return '';
        }
    }

    public function parse(): array
    {
        $modernNodes = $this->crawler->filter('div.space-y-4 > div');
        if ($modernNodes->count() > 0) {
            $data = $modernNodes->each(function ($node) {
                try {
                    if ($node->filter('h3 a')->count() === 0) {
                        return null;
                    }
                    $title = trim($node->filter('h3 a')->text());
                    $magnetNode = $node->filter('a[href^="magnet:"]');
                    if ($magnetNode->count() === 0) {
                        return null;
                    }
                    $magnetLink = $magnetNode->attr('href');
                    $seeders = '0';
                    if ($node->filter('span.text-green-600 span.font-medium')->count() > 0) {
                        $seeders = trim($node->filter('span.text-green-600 span.font-medium')->first()->text());
                    }
                    $size = 'N/A';
                    $date = 'N/A';
                    $node->filter('div.flex.flex-wrap span.inline-flex')->each(function ($span) use (&$size, &$date) {
                        $text = trim($span->text());
                        if (preg_match('/\b(KB|MB|GB|TB|KiB|MiB|GiB)\b/i', $text)) {
                            $size = $text;
                        } elseif (preg_match('/\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}|\bago\b/i', $text)) {
                            $date = $text;
                        }
                    });
                    return [
                        'title' => $title,
                        'data-link' => $magnetLink,
                        'seeders' => $seeders,
                        'info' => sprintf('%s on %s', $size, $date),
                    ];
                } catch (\Throwable $e) {
                    return null;
                }
            });
            $filtered = array_values(array_filter($data));
            if (!empty($filtered)) {
                return $filtered;
            }
        }

        $legacy = $this->crawler->filter('.search-result')->each(function ($node) {
            if ($node->getNode(0)) {
                try {
                    $title = $node->filter('.info h5.title')->text();
                    $infoNode = $node->filter('.info .stats div');
                    $count = $infoNode->count();
                    $info = [];
                    for ($i = 0; $i < $count; $i++) {
                        $name = strtolower((string) $infoNode->filter('img')->eq($i)->attr('alt'));
                        $info[$name] = trim($infoNode->eq($i)->text());
                    }
                    $seeders = $info['seeder'] ?? '0';
                    $infoText = sprintf('%s on %s', $info['size'] ?? 'N/A', $info['date'] ?? 'N/A');
                    $magnetLink = $node->filter('.links.center-flex a:nth-child(2)')->attr('href');
                    return [
                        'title' => $title,
                        'data-link' => $magnetLink,
                        'seeders' => $seeders,
                        'info' => $infoText,
                    ];
                } catch (\Throwable $e) {
                    return null;
                }
            }
            return null;
        });
        return array_values(array_filter($legacy));
    }

    public function getItems(): self
    {
        $this->rows = $this->parse();
        return $this;
    }

    public static function getLabel(): string
    {
        return 'bitsearch';
    }
}
