<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Integration\Storage;

/**
 * Runs round-trip-proxy.php in a child process in front of the given Redis.
 */
final class RoundTripProxy
{
    public readonly string $dsn;

    /**
     * @var resource
     */
    private $process;

    /**
     * @var array<int, resource>
     */
    private array $pipes = [];

    public function __construct(string $redisDsn)
    {
        $url = parse_url($redisDsn);
        $upstream = ($url['host'] ?? 'localhost') . ':' . ($url['port'] ?? 6379);
        $process = proc_open([\PHP_BINARY, __DIR__ . '/round-trip-proxy.php', $upstream], [['pipe', 'r'], ['pipe', 'w']], $this->pipes);

        if ($process === false) {
            throw new \RuntimeException('Cannot start the round-trip proxy.');
        }

        $this->process = $process;
        $port = (int) fgets($this->pipes[1]);

        if ($port === 0) {
            $this->stop();

            throw new \RuntimeException('The round-trip proxy did not report its port.');
        }

        $this->dsn = 'redis://127.0.0.1:' . $port;
    }

    public function roundTrips(): int
    {
        fwrite($this->pipes[0], "\n");

        return (int) fgets($this->pipes[1]);
    }

    public function stop(): void
    {
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        $this->pipes = [];
        proc_close($this->process);
    }
}
