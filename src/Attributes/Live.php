<?php

namespace Yuga\Live\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS)]
class Live
{
    public function __construct(
        public ?string $name = null,
        public bool $discover = true,
        public bool $lazy = false,
        public bool $stream = false,
        public ?int $streamInterval = null,
        public bool $streamAlways = false,
        public bool $poll = false,
        public ?int $pollInterval = null,
    ) {}
}