<?php

namespace Yuga\Live\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD)]
class Computed
{
    public function __construct(
        public bool $cache = true
    ) {}
}
