<?php

namespace OCA\NCDownloader\Search\Sites;

use OCA\NCDownloader\Tools\tableData;

class TPB extends searchBase implements searchInterface
{
    private ?string $content = null;
    public string $baseUrl = 'https://piratebay.live/search/';

    public function __construct($crawler, $client)
    {
        $this->client = $client;
        $this->crawler = $crawler;
    }

    public function search(string $keyword): tableData
    {
        $this->searchUrl = $this->baseUrl . rawurlencode(trim($keyword));
        $html = $this->getContent();
        if ($this->hasErrors()) {
            return tableData::create()->setError($this->getErrors());
        }
        if ($html !== '') {
            $this->crawler->clear();
            $this->crawler->add($html);
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
            $response = $this->client->request('GET', $this->searchUrl);
            return $response->getContent();
        } catch (\Throwable $e) {
            $this->errors[] = $e->getMessage();
            return '';
        }
    }

    public function parse(): array
    {
        $data = $this->crawler->filter('#searchResult tr')->each(function ($node) {
            if ($node->getNode(0)) {
                try {
                    $title = $node->filter('a.detLink')->text();
                    $info = $node->filter('font.detDesc')->text();
                    $numSeeders = $node->filter('td:nth-child(3)')->text();
                    $magnetLink = $node->filter('td:nth-child(2) > a:nth-child(2)')->attr('href');
                    $parts = explode(',', $info);
                    $infoText = trim($parts[0]) . (isset($parts[1]) ? ' - ' . trim($parts[1]) : '');
                    return [
                        'title' => $title,
                        'data-link' => $magnetLink,
                        'seeders' => $numSeeders,
                        'info' => $infoText,
                    ];
                } catch (\Throwable $e) {
                    return null;
                }
            }
            return null;
        });
        return array_values(array_filter($data));
    }

    public function getItems(): self
    {
        $this->rows = $this->parse();
        return $this;
    }

    public static function getLabel(): string
    {
        return 'thepiratebay';
    }
}
