<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\CreatedSource;
use App\Models\Source;
use Illuminate\Support\Facades\DB;

final class CreateSource
{
    public function handle(string $name): CreatedSource
    {
        return DB::transaction(function () use ($name): CreatedSource {
            $source = Source::create(['name' => $name]);

            return new CreatedSource($source, $source->createToken('ingest')->plainTextToken);
        });
    }
}
