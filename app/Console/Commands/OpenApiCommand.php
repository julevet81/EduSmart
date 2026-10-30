<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Docs\OpenApiBuilder;
use Illuminate\Console\Command;

final class OpenApiCommand extends Command
{
    protected $signature = 'api:openapi {--output=docs/openapi.json : File to write, relative to the project}';

    protected $description = 'Generate the OpenAPI 3.1 document from the registered routes (import it in Postman, Swagger UI or a client generator).';

    public function handle(OpenApiBuilder $builder): int
    {
        $path = base_path((string) $this->option('output'));
        is_dir(dirname($path)) || mkdir(dirname($path), 0755, true);

        $spec = $builder->build();
        file_put_contents($path, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);

        $operations = array_sum(array_map('count', $spec['paths']));
        $this->info("Wrote {$path} ({$operations} operations).");

        return self::SUCCESS;
    }
}
