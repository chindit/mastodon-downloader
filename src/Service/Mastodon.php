<?php
declare(strict_types=1);

namespace App\Service;

use Carbon\Carbon;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Mastodon
{
    private ?string $userServer = null;
    public function __construct(
        private string $server,
        private readonly string $token,
        private readonly HttpClientInterface $httpClient
    )
    {
        if (str_ends_with($this->server, '/')) {
            $this->server = substr('/', 0, -1);
        }
    }

    /**
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    public function findUser(string $user): array
    {
        return json_decode($this->httpClient->request(
            'GET',
            $this->getServer() . '/api/v2/search?limit=5&q=' . htmlspecialchars($user),
            $this->getHeaders()
        )->getContent(), true, JSON_THROW_ON_ERROR)['accounts'];
    }

    public function setServerForUser(array $user): void
    {
        if (!str_contains($user['url'], $this->server)) {
            $this->userServer = dirname($user['url']);
        }
    }

    public function getUserForSelectedServer(array $user): array
    {
        if ($this->isAuthenticatedServer()) {
            return $user;
        }

        try {
            return $this->findUser($user['acct'])[0];
        } catch (\Throwable $exception) {
            /**
             * Some servers have disabled their API.
             * In this case, we use our root server
             * We will lose some posts, but that's the best we can do
             */
            $this->userServer = null;

            return $user;
        }
    }

    public function getMedias(string $userId, SymfonyStyle $io): array
    {
        $medias = [];
        $page = $this->getServer() . '/api/v1/accounts/' . $userId . '/statuses?only_media=true';

        do {
            try {
                $response = $this->httpClient->request(
                    'GET',
                    $page,
                    $this->getHeaders()
                );

                $nextPage = [];

                if (isset($response->getHeaders()['link'])) {
                    preg_match('/<(.*)>; rel="next"/', $response->getHeaders()['link'][0], $nextPage);
                    $page = count($nextPage) > 1 ? $nextPage[1] : null;
                } else {
                    $page = null;
                }

                $statuses = json_decode($response->getContent(), true, JSON_THROW_ON_ERROR);

                foreach ($statuses as $status) {
                    foreach ($status['media_attachments'] as $media) {
                        $medias[] = $media['remote_url'] ?: $media['url'];
                    }
                }
            } catch (ClientExceptionInterface $exception) {
                if ($exception->getCode() === Response::HTTP_TOO_MANY_REQUESTS) {
                    $io->warning('Account is locked due to excessive requests.');
                    $io->note(sprintf('Program paused until %s', $exception->getResponse()->getHeaders(false)['x-ratelimit-reset'][0]));
                    $reset = new Carbon($exception->getResponse()->getHeaders(false)['x-ratelimit-reset'][0]);
                    sleep($reset->diffInSeconds(Carbon::now()) + 10);
                }
            }
        } while ($page);

        return $medias;
    }

    private function getHeaders(): array
    {
        if ($this->token && $this->isAuthenticatedServer()) {
            return ['headers' => [
                'Authorization' => 'Bearer ' . $this->token
            ]];
        }

        return [];
    }

    private function getServer(): string
    {
        return $this->userServer ?? $this->server;
    }

    private function isAuthenticatedServer(): bool
    {
        return !$this->userServer;
    }
}