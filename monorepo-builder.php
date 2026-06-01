<?php

declare(strict_types=1);

use Symplify\MonorepoBuilder\Config\MBConfig;

return static function (MBConfig $mbConfig): void {
    // Every directory below `packages/` is treated as an individual package
    // whose composer.json is merged into the root manifest.
    $mbConfig->packageDirectories([__DIR__ . '/packages']);

    // The demo application is part of the repo but is never released/split.
    $mbConfig->packageDirectoriesExcludes([__DIR__ . '/demo']);

    $mbConfig->defaultBranch('main');
};
