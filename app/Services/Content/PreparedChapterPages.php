<?php

namespace App\Services\Content;

/** One prepared chapter. The caller must close() in a finally block. */
final class PreparedChapterPages
{
    /** @param \Closure(callable(string, int): void): void $each */
    public function __construct(
        private readonly \Closure $each,
        private readonly ?\Closure $cleanup = null,
    ) {}

    /** @param callable(string, int): void $consume */
    public function each(callable $consume): void
    {
        ($this->each)($consume);
    }

    public function close(): void
    {
        if ($this->cleanup !== null) {
            ($this->cleanup)();
        }
    }
}
