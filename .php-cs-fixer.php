<?php

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()->in([__DIR__ . '/bin', __DIR__ . '/migrations', __DIR__ . '/public', __DIR__ . '/src', __DIR__ . '/tests'])->name('*.php')->ignoreDotFiles(true)->ignoreVCS(true);

return (new Config())->setRiskyAllowed(false)->setRules(['@PER-CS' => true])->setFinder($finder);
