<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('three_brs_sentry_throttle');

        $treeBuilder->getRootNode()
            ->validate()
            ->ifTrue(static fn (array $config): bool => $config['enabled'] === true && \in_array($config['prefix'], [null, ''], true))
            ->thenInvalid('"prefix" must be set when the throttle is enabled.')
            ->end()
            ->validate()
            ->ifTrue(static fn (array $config): bool => $config['enabled'] === true && $config['redis']['dsn'] === null && $config['redis']['service'] === null)
            ->thenInvalid('"redis" must be set when the throttle is enabled.')
            ->end()
            ->validate()
            ->always(static function (array $config): array {
                self::assertMinimums($config);

                return $config;
            })
            ->end()
            ->children()
            ->booleanNode('enabled')
            ->info('Decides which services are registered, so it cannot come from an environment variable; use when@dev / when@test.')
            ->defaultTrue()
            ->end()
            ->scalarNode('prefix')
            ->info('Part of every Redis key, so that several applications can share one Redis.')
            ->defaultNull()
            ->end()
            ->append($this->redisNode())
            ->append($this->monologNode())
            ->integerNode('window')
            ->info('Window length in seconds, at least 1.')
            ->defaultValue(60)
            ->end()
            ->integerNode('per_fingerprint')
            ->info('Events with the same fingerprint sent per window, at least 1.')
            ->defaultValue(50)
            ->end()
            ->integerNode('max_per_window')
            ->info('Events sent per window in total, at least 1; only events within the per-fingerprint limit count.')
            ->defaultValue(300)
            ->end()
            ->integerNode('fallback_max_per_window')
            ->info('Events one process sends per window while Redis is unavailable, at least 1.')
            ->defaultValue(50)
            ->end()
            ->end();

        return $treeBuilder;
    }

    /**
     * Checks only values written in the configuration: environment variables are placeholders here and are resolved
     * at runtime. The node min() checks cannot be used, Symfony before 8.1 applies them to placeholders too.
     *
     * @param array<string, mixed> $config
     */
    private static function assertMinimums(array $config): void
    {
        foreach (['window', 'per_fingerprint', 'max_per_window', 'fallback_max_per_window'] as $option) {
            if (\is_int($config[$option]) && $config[$option] < 1) {
                throw new \InvalidArgumentException(\sprintf('"%s" must be at least 1.', $option));
            }
        }

        /** @var array<string, mixed> $redis */
        $redis = $config['redis'];

        foreach (['timeout', 'read_timeout'] as $option) {
            if ((\is_int($redis[$option]) || \is_float($redis[$option])) && $redis[$option] <= 0) {
                throw new \InvalidArgumentException(\sprintf('"redis.%s" must be greater than 0.', $option));
            }
        }
    }

    private function monologNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('monolog'))->getRootNode();

        $node
            ->info('For applications that send to Sentry through their own Monolog handler, without the Sentry SDK.')
            ->addDefaultsIfNotSet()
            ->children()
            ->arrayNode('handlers')
            ->info('Ids of the Monolog handler services that send to Sentry; each of them is wrapped by the throttle.')
            ->beforeNormalization()
            ->castToArray()
            ->end()
            ->scalarPrototype()
            ->cannotBeEmpty()
            ->end()
            ->defaultValue([])
            ->end()
            ->enumNode('tags')
            ->info('Record field the wrapped handler reads Sentry tags from.')
            ->values(['context', 'extra'])
            ->defaultValue('context')
            ->end()
            ->end();

        return $node;
    }

    private function redisNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('redis'))->getRootNode();

        $node
            ->info('Redis DSN, or the id of an existing Redis client service.')
            ->addDefaultsIfNotSet()
            ->beforeNormalization()
            ->ifString()
            ->then(static fn (string $dsn): array => [
                'dsn' => $dsn,
            ])
            ->end()
            ->validate()
            ->ifTrue(static fn (array $redis): bool => $redis['dsn'] !== null && $redis['service'] !== null)
            ->thenInvalid('Set either "dsn" or "service", not both.')
            ->end()
            ->children()
            ->scalarNode('dsn')
            ->info('Query parameters of the DSN override "timeout" and "read_timeout".')
            ->defaultNull()
            ->cannotBeEmpty()
            ->end()
            ->scalarNode('service')
            ->info('The timeouts of this client apply, keep them short.')
            ->defaultNull()
            ->cannotBeEmpty()
            ->end()
            ->floatNode('timeout')
            ->info('Connect timeout in seconds, greater than 0; DSN only.')
            ->defaultValue(0.2)
            ->end()
            ->floatNode('read_timeout')
            ->info('Read timeout in seconds, greater than 0; DSN only.')
            ->defaultValue(0.2)
            ->end()
            ->end();

        return $node;
    }
}
