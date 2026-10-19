<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Monolog;

use Monolog\Logger;
use Monolog\LogRecord;
use ThreeBRS\SentryThrottleBundle\Throttle\Decision;

// @phpstan-ignore greaterOrEqual.alwaysTrue (Logger::API is a constant of the installed Monolog)
if (Logger::API >= 3) {
    /**
     * @internal
     */
    trait CompatibilityThrottlingHandlerTrait
    {
        public function handle(LogRecord $record): bool
        {
            if (! $this->handler->isHandling($record)) {
                return false;
            }

            $decision = $this->decide($record);

            return $decision->send ? $this->handler->handle($this->withTags($record, $decision->tags())) : $this->droppedRecordResult();
        }

        /**
         * @param array<LogRecord> $records
         */
        public function handleBatch(array $records): void
        {
            $passed = [];

            foreach ($records as $record) {
                if (! $this->handler->isHandling($record)) {
                    $passed[] = $record;

                    continue;
                }

                $decision = $this->decide($record);

                if ($decision->send) {
                    $passed[] = $this->withTags($record, $decision->tags());
                }
            }

            if ($passed !== []) {
                $this->handler->handleBatch($passed);
            }
        }

        private function decide(LogRecord $record): Decision
        {
            return $this->decision($record->channel, $record->level->getName(), $record->message, $record->context['exception'] ?? null);
        }

        /**
         * The record can be shared with other handlers, so the tags go to a copy made by with().
         *
         * @param array<string, string> $tags
         */
        private function withTags(LogRecord $record, array $tags): LogRecord
        {
            if ($tags === []) {
                return $record;
            }

            return $this->tagsIn === self::TAGS_IN_EXTRA
                ? $record->with(extra: [...$record->extra, ...$tags])
                : $record->with(context: [...$record->context, ...$tags]);
        }
    }
} else {
    /**
     * @internal
     */
    trait CompatibilityThrottlingHandlerTrait
    {
        /**
         * @param array<string, mixed> $record
         */
        public function handle(array $record): bool
        {
            if (! $this->handler->isHandling($record)) {
                return false;
            }

            $decision = $this->decide($record);

            return $decision->send ? $this->handler->handle($this->withTags($record, $decision->tags())) : $this->droppedRecordResult();
        }

        /**
         * @param array<array<string, mixed>> $records
         */
        public function handleBatch(array $records): void
        {
            $passed = [];

            foreach ($records as $record) {
                if (! $this->handler->isHandling($record)) {
                    $passed[] = $record;

                    continue;
                }

                $decision = $this->decide($record);

                if ($decision->send) {
                    $passed[] = $this->withTags($record, $decision->tags());
                }
            }

            if ($passed !== []) {
                $this->handler->handleBatch($passed);
            }
        }

        /**
         * @param array<string, mixed> $record
         */
        private function decide(array $record): Decision
        {
            return $this->decision((string) $record['channel'], (string) $record['level_name'], (string) $record['message'], $record['context']['exception'] ?? null);
        }

        /**
         * @param array<string, mixed> $record
         * @param array<string, string> $tags
         *
         * @return array<string, mixed>
         */
        private function withTags(array $record, array $tags): array
        {
            if ($tags !== []) {
                $record[$this->tagsIn] = [...$record[$this->tagsIn] ?? [], ...$tags];
            }

            return $record;
        }
    }
}
