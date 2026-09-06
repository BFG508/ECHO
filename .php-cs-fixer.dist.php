<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->files()
    ->name('*.php')
    ->in(__DIR__ . '/backend')
    ->exclude('vendor');

return (new Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PER-CS' => true,
    ])
    ->setFinder($finder)
    ->setIndent('    ')
    ->setLineEnding("\n")
    ->setUsingCache(false);
