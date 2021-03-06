<?php

namespace Console\App\Service;

use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use Cache\Adapter\Filesystem\FilesystemCachePool;
use Github\Client;

class Github
{
    /**
     * @var Client;
     */
    protected $client;

    public function __construct(string $ghToken = null)
    {
        $filesystemAdapter = new Local(__DIR__ . '/../../../var/');
        $filesystem = new Filesystem($filesystemAdapter);
        $pool = new FilesystemCachePool($filesystem);

        $this->client = new Client();
        $this->client->addCache($pool);

        if (!empty($ghToken)) {
            $this->client->authenticate($ghToken, null, Client::AUTH_URL_TOKEN);
        }
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    public function countRepoFiles(string $org, string $repository, string $path = null): int
    {
        $numFiles = 0;

        $arrayPath = $this->client->api('repo')->contents()->show($org, $repository, $path);
        foreach($arrayPath as $itemPath) {
            if ($itemPath['type'] == 'file') {
                $numFiles += 1;
                continue;
            }
            if ($itemPath['type'] == 'dir') {
                $numFiles += $this->countRepoFiles($org, $repository, $itemPath['path']);
                continue;
            }
        }
        return $numFiles;
    }

    public function getLinkedIssue(array $pullRequest)
    {
        // Linked Issue
        preg_match('#Fixes\s\#([0-9]{1,5})#', $pullRequest['body'], $matches);
        $issueId = !empty($matches) && !empty($matches[1]) ? $matches[1] : null;
        if (empty($issueId)) {
            preg_match('#Fixes\sissue\s\#([0-9]{1,5})#', $pullRequest['body'], $matches);
            $issueId = !empty($matches) && !empty($matches[1]) ? $matches[1] : null;
        }
        if (empty($issueId)) {
            preg_match('#Fixes\shttps:\/\/github.com\/PrestaShop\/PrestaShop\/issues\/([0-9]{1,5})#', $pullRequest['body'], $matches);
            $issueId = !empty($matches) && !empty($matches[1]) ? $matches[1] : null;
        }
        $issue = is_null($issueId) ? null : $this->client->api('issue')->show('PrestaShop', 'PrestaShop', $issueId);

        // API Alert
        if (isset($pullRequest['_links'])) {
            var_dump('PR #'.$pullRequest['number'].' has _links in its API');
        }

        return $issue;
    }

    public function getRepoTopics(string $org, string $repository): array
    {
        $query = '{
            repository(owner: "'.$org.'", name: "'.$repository.'") {
              repositoryTopics(first: 10) {
                edges {
                  node {
                    topic {
                      name
                    }
                  }
                }
              }
            }
          }';

        $repositoryInfoGraphQL = $this->client->api('graphql')->execute($query, []);
        $topics = [];
        foreach($repositoryInfoGraphQL['data']['repository']['repositoryTopics']['edges'] as $edge) {
            $topics[] = $edge['node']['topic']['name'];
        }
        return $topics;
    }

    public function countPRFileTypes(string $org, string $repository, int $prId): array
    {
        $files = $this->client->api('pull_request')->files($org, $repository, $prId);
        $types = [];

        foreach($files as $file) {
            $extension = pathinfo($file['filename'], PATHINFO_EXTENSION);
            if (!array_key_exists($extension, $types)) {
                $types[$extension] = 0;
            }
            $types[$extension]++;
        }
        return $types;
    }
}
