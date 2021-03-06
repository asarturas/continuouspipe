<?php

namespace ContinuousPipe\Builder\Reporting\ElasticSearch;

use ContinuousPipe\Builder\Reporting\ReportException;
use ContinuousPipe\Builder\Reporting\ReportPublisher;
use Elasticsearch\Client;
use Elasticsearch\ClientBuilder;

class ElasticSearchReportPublisher implements ReportPublisher
{
    /**
     * @var \Elasticsearch\Client|null
     */
    private $client;

    /**
     * @var string
     */
    private $indexName;

    public function __construct(string $indexName, string $elasticSearchHostname = null, bool $useSSLVerification = true, string $apiKey = null)
    {
        $this->indexName = $indexName;

        if (null !== $elasticSearchHostname) {
            $this->client = $this->createClient($elasticSearchHostname, $useSSLVerification, $apiKey);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function publish(string $buildIdentifier, array $report)
    {
        if (null === $this->client) {
            return;
        }


        try {
            $indexName = $this->indexName.'-'.date('d.m.Y');
            $documentType = 'build';

            // Ensure that the `@timestamp` field type is properly
            $this->ensureIndexExists($indexName, $documentType);

            // Add the report timestamp
            $report['@timestamp'] = time();
            $documentIdentifier = [
                'type' => $documentType,
                'index' => $indexName,
                'id' => $buildIdentifier,
            ];

            if ($this->client->exists($documentIdentifier)) {
                $this->client->update(
                    array_merge($documentIdentifier, [
                        'body' => [
                            'doc' => $report,
                        ],
                    ])
                );
            } else {
                $this->client->index(
                    array_merge($documentIdentifier, [
                        'timestamp' => $report['@timestamp'],
                        'body' => $report,
                    ])
                );
            }
        } catch (\Exception $e) {
            throw new ReportException('Something went wrong while publishing the report', $e->getCode(), $e);
        }
    }

    private function createClient(string $elasticSearchHostname, bool $useSSLVerification, string $apiKey) : Client
    {
        $apiKeyHandler = function (callable $next, string $apiKey) {
            return function (array $request) use ($next, $apiKey) {
                if (($questionMarkIndex = strpos($request['uri'], '?')) !== false) {
                    $request['query_string'] = substr($request['uri'], $questionMarkIndex + 1);
                    $request['uri'] = substr($request['uri'], 0, $questionMarkIndex);
                }

                if (isset($request['query_string'])) {
                    $request['query_string'] .= '&apikey='.$apiKey;
                } else {
                    $request['query_string'] = 'apikey='.$apiKey;
                }

                return $next($request);
            };
        };

        $hostNameTransformer = function (string $hostname) {
            $parsed = parse_url($hostname);
            if (!$parsed) {
                return $hostname;
            }

            $port = $parsed['port'];
            $port = $parsed['scheme'] == 'https' && empty($port) ? 443 : null;
            $parsed = array_filter([
                'scheme' => $parsed['scheme'],
                'host' => $parsed['host'],
                'port' => $port
            ]);
            return $parsed ?: $hostname;
        };

        return ClientBuilder::create()
            ->setHandler(
                $apiKeyHandler(ClientBuilder::defaultHandler(), $apiKey)
            )
            ->setHosts([
                $hostNameTransformer($elasticSearchHostname),
            ])
            ->setSSLVerification($useSSLVerification)
            ->build()
        ;
    }

    /**
     * @param $indexName
     * @param $documentType
     */
    private function ensureIndexExists($indexName, $documentType)
    {
        $indexExists = $this->client->indices()->exists([
            'index' => $indexName
        ]);

        if (false === $indexExists) {
            $this->client->indices()->create([
                'index' => $indexName,
                'body' => [
                    'mappings' => [
                        $documentType => [
                            '_source' => [
                                'enabled' => true
                            ],
                            'properties' => [
                                '@timestamp' => [
                                    'type' => 'date',
                                    'format' => 'epoch_second'
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
        }
    }
}
