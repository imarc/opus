<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/test',
    ])
    ->withPhpSets(php74: true)
    ->withTypeCoverageLevel(0)
    ->withDeadCodeLevel(0)
    ->withCodeQualityLevel(0)
    ->withPhpVersion(\Rector\ValueObject\PhpVersion::PHP_84)
    ->withRules([
         \Rector\Php84\Rector\Param\ExplicitNullableParamTypeRector::class,
    ]);
;

