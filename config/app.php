<?php
declare(strict_types=1);
return ['storage_path' => getenv('CALEIDOS_STORAGE_PATH') ?: dirname(__DIR__) . '/storage'];
