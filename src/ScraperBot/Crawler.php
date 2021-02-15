<?php
declare(strict_types=1);

namespace ScraperBot;

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Promise\EachPromise;
use GuzzleHttp\Psr7\Response;
use ScraperBot\Event\CrawledEvent;
use ScraperBot\Event\CrawlInitiatedEvent;
use ScraperBot\Event\CrawlRejectedEvent;
use ScraperBot\Source\SourceInterface;
use ScraperBot\Source\XmlSitemapSource;
use ScraperBot\Storage\StorageInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

class Crawler {

    private $headers = NULL;

    private $storage;

    private $offIndex = 0;

    private $eventDispatcher = NULL;

    public function __construct(StorageInterface $storage, $config, EventDispatcher $eventDispatcher = NULL) {
        $this->headers = $config;
        $this->concurrency = $config['concurrency'];

        $this->storage = $storage;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * Crawl sites.
     *
     * @param $listOfSites
     * @param $client
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function crawlSites(SourceInterface $source, Client $client, $default_config = NULL, $timestamp = NULL, $debug = NULL) {
        $urls = $source->getLinks();

        // Trigger 'crawl initiated event' - chance to modify URLs.
        $event = new CrawlInitiatedEvent(CrawlInitiatedEvent::CRAWL_TYPE_SITE, $urls);
        $this->eventDispatcher->dispatch($event, CrawlInitiatedEvent::NAME);

        $urls = $event->getUrls();

        if (!isset($timestamp)) {
            $timestamp = time();
        }

        $promises = (function () use ($urls, $client, $default_config) {
            foreach ($urls as $url) {
                if (!empty($url)) {
                    // If default config is provided, create a new client each time.
                    if ($default_config != NULL) {
                        $config = $default_config + ['base_uri' => 'http://' . $url];
                        $url = '';
                        $client = new Client($config);
                    }

                    yield $client->getAsync($url, $this->headers);
                }
            }
        })();

        $eachPromise = new EachPromise($promises, [
            // Concurrency to use.
            'concurrency' => $this->concurrency,
            'fulfilled' => function (Response $response, $index) use ($timestamp, $urls, $debug) {
                $siteCrawled = array();
                $siteCrawled['site_id'] = ($index + 1);
                $siteCrawled['url'] = trim($urls[$index]);
                $siteCrawled['statusCode'] = $response->getStatusCode();
                $body = $response->getBody()->getContents();
                $siteCrawled['size'] = strlen($body);
                $siteCrawled['footprint'] = md5($body);

                $tagDistribution = $this->getTags($body);

                // Event notification.
                $event = new CrawledEvent($siteCrawled);
                $this->eventDispatcher->dispatch($event, CrawledEvent::NAME);

                $this->storage->addResult(
                    $siteCrawled['site_id'],
                    $siteCrawled['url'],
                    $siteCrawled['size'],
                    $siteCrawled['statusCode'],
                    $siteCrawled['footprint'],
                    $timestamp
                );
                $this->storage->addTagDistribution($siteCrawled['url'], $tagDistribution, $timestamp);
            },
            'rejected' => function ($reason, $index, $promise) use ($timestamp, $urls, $debug) {
                // Handle promise rejected here (ie: not existing domains, long timeouts or too many redirects).

                $siteCrawled = [];

                // TODO: Review if this index is correct.
                if (isset($urls[$index + 1])) {
                    $siteCrawled = array();
                    $siteCrawled['site_id'] = ($index + 1);
                    $siteCrawled['url'] = $urls[$index + 1];
                    $siteCrawled['statusCode'] = 'rejected';
                    $siteCrawled['size'] = $siteCrawled['footprint'] = 0;

                    $event = new CrawlRejectedEvent($siteCrawled, $reason);
                    $this->eventDispatcher->dispatch($event, CrawlRejectedEvent::NAME);

                    $this->storage->addResult(
                        $index,
                        $siteCrawled['url'],
                        0,
                        0,
                        0,
                        $timestamp
                    );
                }

                // Fire 'crawl rejected' event.
                $event = new CrawlRejectedEvent($siteCrawled, $reason);
                $this->eventDispatcher->dispatch($event, CrawlRejectedEvent::NAME);
            }
        ]);

        $eachPromise->promise()->wait();
    }

    /**
     * Return tag analysis of the given html as string.
     *
     * @param $body
     * @return mixed
     */
    public function getTags($body){
        if ($body != "") {
            $dom = new \DOMDocument();
            $dom->loadHTML($body, LIBXML_NOWARNING | LIBXML_NOERROR);
            $allElements = $dom->getElementsByTagName('*');

            $elementDistribution = [];
            foreach($allElements as $element) {
                if(array_key_exists($element->tagName, $elementDistribution)) {
                    $elementDistribution[$element->tagName] += 1;
                } else {
                    $elementDistribution[$element->tagName] = 1;
                }
            }
            $elementDistribution['total'] = $allElements->length;

            return $elementDistribution;
        }

    }

    /**
     * Crawl sites.
     *
     * @param $listOfSites
     * @param $client
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function determineSiteMapURLs(SourceInterface $source, Client $client, $default_config = NULL, $timestamp = NULL, $offIndex = 0) {
        $this->offIndex = $offIndex;

        if (!isset($timestamp)) {
            $timestamp = time();
        }

        $this->gatherSitemapURLs($source, $client, $default_config, $timestamp);
    }

    /**
     * Trigger Crawl using threads.
     *
     * @param $urls
     * @param $client
     * @param $default_config
     * @param $offIndex
     * @param $timestamp
     */
    public function gatherSitemapURLs($source, $client, $default_config, $timestamp) {
        // First read the robots, so we can find the sitemap (if any)
        $urls = $source->getLinks();
        // TODO: trigger gather sitemaps event.

        $promises = (function () use ($urls, $client, $default_config) {
            foreach ($urls as $url) {
                // If default config is provided, create a new client each time.
                if ($default_config != NULL) {
                    $config = $default_config + ['base_uri' => 'http://' . $url];
                    $url = '';
                    $client = new Client($config);
                }

                yield $client->getAsync($url . '/robots.txt', $this->headers);
            }
        })();

        $eachPromise = new EachPromise($promises, [
            // Concurrency to use.
            'concurrency' => $this->concurrency,
            'fulfilled' => function (Response $response, $index) use ($timestamp, $urls) {
                foreach(explode(PHP_EOL, $response->getBody()->getContents()) as $line) {
                    // We want to follow Sitemap: urls.
                    if (strpos($line, 'Sitemap:') !== false) {
                        $this->offIndex++;
                        $newurl = trim(substr($line, strlen("Sitemap:"), strlen($line)));

                        // Store new links.
                        $this->storage->addSitemapURL($newurl, $this->offIndex, $timestamp);
                    }
                    //TODO trigger 'crawl added' event
                }

            },
            'rejected' => function ($reason, $index, $promise) use ($timestamp, $urls) {
                // Handle promise rejected here (ie: not existing domains, long timeouts or too many redirects).
                // Trigger 'crawl rejected' event.
                $data = [];
                //TODO: extract data.
                $event = new CrawlRejectedEvent($data, $reason);
                $this->eventDispatcher->dispatch($event, CrawlRejectedEvent::NAME);
            }
        ]);

        $eachPromise->promise()->wait();
    }

    public function getListPendingSitemaps($dumpCurrent = TRUE) {
        return $this->storage->getSitemapURLs($dumpCurrent);
    }

    public function getListPendingURL($dumpCurrent = TRUE) {
        return $this->storage->getPendingURLs($dumpCurrent);
    }

    /**
     * Trigger Crawl using threads.
     *
     * @param $urls
     * @param $client
     * @param $default_config
     * @param $offIndex
     * @param $timestamp
     */
    public function crawlSitemaps($source, $client, $default_config, $timestamp, $offIndex) {
        // First read the robots, so we can find the sitemap (if any)
        $urls = $source->getLinks();

        // If source is empty, return here.
        if (empty($urls)) {
            return;
        }

        // Trigger 'crawl initiated event' - chance to modify URLs.
        $event = new CrawlInitiatedEvent(CrawlInitiatedEvent::CRAWL_TYPE_SITEMAP, $urls);
        $this->eventDispatcher->dispatch($event, CrawlInitiatedEvent::NAME);
        $urls = $event->getUrls();

        $promises = (function () use ($urls, $client, $default_config, $offIndex) {
            foreach ($urls as $url) {
                // If default config is provided, create a new client each time.
                if ($default_config != NULL) {
                    $config = $default_config + ['base_uri' => 'http://' . $url];
                    $url = '';
                    $client = new Client($config);
                }

                yield $client->getAsync($url, $this->headers);
            }
        })();

        $eachPromise = new EachPromise($promises, [
            // Concurrency to use.
            'concurrency' => $this->concurrency,
            'fulfilled' => function (Response $response, $index) use ($timestamp, $urls, $offIndex) {
                // We want to follow Sitemap: urls.
                // TODO: NEXT: CRAWL CONTENT OF THE SITEMAP AND INSERT IN THE TEMPORARY URLS:

                $sourceSitemap = new XmlSitemapSource();
                $links = $sourceSitemap->extractLinks($response->getBody()->getContents());
                if(is_array($links)){
                    foreach ($links as $link) {
                        $this->storage->addPendingURL($link, $this->offIndex, $timestamp);
                        $this->offIndex++;
                    }
                }
            },
            'rejected' => function ($reason, $index, $promise) use ($timestamp, $urls) {
                // Handle promise rejected here (ie: not existing domains, long timeouts or too many redirects).
                $data = [];
                //TODO: extract data.
                $event = new CrawlRejectedEvent($data, $reason);
                $this->eventDispatcher->dispatch($event, CrawlRejectedEvent::NAME);
            }
        ]);

        $eachPromise->promise()->wait();
    }
}
