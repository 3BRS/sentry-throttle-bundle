<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Storage;

use Predis\Client;
use Predis\ClientInterface;
use Predis\Connection\Aggregate\ClusterInterface as Predis1ClusterInterface;
use Predis\Connection\Cluster\ClusterInterface;
use Predis\Connection\NodeConnectionInterface;
use Predis\Pipeline\Pipeline;
use Predis\Response\ErrorInterface;

/**
 * @internal
 */
final class PredisStorage implements StorageInterface
{
    public function __construct(
        private readonly ClientInterface $client,
    ) {
        $connection = $client->getConnection();

        // @phpstan-ignore class.notFound (the interface of Predis 1)
        if ($connection instanceof ClusterInterface || $connection instanceof Predis1ClusterInterface) {
            throw new StorageException('Redis Cluster is not supported.');
        }
    }

    /**
     * Gives a client of the application its own connection with the same parameters and options, so that its
     * transactions and WATCH are not affected. Not persistent: that would share its socket. Connections other than
     * to a single node, e.g. replication, are used as they are.
     */
    public static function withOwnConnection(ClientInterface $client): self
    {
        $connection = $client->getConnection();

        if (! $connection instanceof NodeConnectionInterface) {
            return new self($client);
        }

        $parameters = $connection->getParameters()->toArray();
        // Predis fills missing parameters from the "parameters" option, where Symfony sets "persistent" too.
        $parameters['persistent'] = false;

        return new self(new Client($parameters, $client->getOptions()));
    }

    /**
     * MULTI and EXEC inside a plain pipeline take one round trip; Predis transactions wait for every QUEUED reply.
     */
    public function run(array $operations): array
    {
        $pipeline = new Pipeline($this->client);
        $pipeline->multi();

        foreach ($operations as $operation) {
            $pipeline->incrby($operation->key, $operation->amount);

            if ($operation->take) {
                $pipeline->del($operation->key);
            } else {
                $pipeline->expire($operation->key, $operation->ttlSeconds);
            }
        }

        $pipeline->exec();
        $pipelineReplies = $pipeline->execute();
        $transactionReplies = end($pipelineReplies);

        if (! \is_array($transactionReplies)) {
            throw new StorageException('Redis transaction failed: ' . ($transactionReplies instanceof ErrorInterface ? $transactionReplies->getMessage() : 'no reply'));
        }

        $results = [];

        foreach ($operations as $index => $operation) {
            $results[] = $this->integer($transactionReplies[2 * $index] ?? null, $operation->key);
        }

        return $results;
    }

    private function integer(mixed $reply, string $key): int
    {
        if (! \is_int($reply)) {
            throw new StorageException(\sprintf('Redis command on "%s" failed: %s', $key, $reply instanceof ErrorInterface ? $reply->getMessage() : 'unexpected reply'));
        }

        return $reply;
    }
}
