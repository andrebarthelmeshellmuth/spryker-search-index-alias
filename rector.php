<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/tools',
    ])
    ->withSkip([
        __DIR__ . '/tests/*/_support/_generated',
        __DIR__ . '/tests/*/_support/_generated/*',
        __DIR__ . '/tests/*/_output',
        __DIR__ . '/tests/*/_data',
        // Spryker.Commenting.DocBlockParam (active in this project's phpcs.xml) requires exactly one
        // @param tag per method parameter, typed or not. This rule strips @param tags for natively
        // typed params — a direct, systemic contradiction of that convention. Same rule, same reasoning,
        // already skipped in every sibling package (search-debug, search-ranking,
        // search-ranking-optimizer, search-feedback, search-variant-facets).
        RemoveUselessParamTagRector::class,
        // Rewrites plain === null / !== null checks on a nullable single-class type into
        // instanceof \Fully\Qualified\ClassName -- strictly more verbose for a simple null check, breaks
        // this codebase's consistent === null idiom used everywhere else, and writes an inline FQCN
        // instead of a use import, tripping Spryker.Namespaces.UseStatement. Identical finding to every
        // sibling package's.
        FlipTypeControlToUseExclusiveTypeRector::class,
        // Bridge classes are Spryker's generated dependency-glue boilerplate — real Spryker core Bridges
        // still ship the classic @var + assign-in-constructor form, not promoted properties. Promoting
        // these would permanently diverge from what the Spryker code generator itself produces (a future
        // regeneration would just revert it), and here it concretely breaks Spryker.Commenting.DocBlockVar
        // by deleting the property's standalone @var block with nowhere left to reattach one — confirmed
        // empirically. Same exemption class as search-ranking's and search-feedback's *Bridge.php skip.
        ClassPropertyAssignToConstructorPromotionRector::class => [
            __DIR__ . '/src/*Bridge.php',
        ],
    ])
    // Picks up the PHP floor (>=8.3) from composer.json.
    ->withPhpSets()
    ->withDeadCodeLevel(69)
    ->withCodeQualityLevel(75)
    ->withoutParallel();
