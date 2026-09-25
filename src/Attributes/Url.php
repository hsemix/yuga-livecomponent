<?php

namespace Yuga\Live\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Url
{
    public function __construct(
        public ?string $as = null,
        public bool $history = true,
    ) {}
}