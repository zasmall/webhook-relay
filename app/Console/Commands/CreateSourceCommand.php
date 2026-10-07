<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CreateSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

final class CreateSourceCommand extends Command
{
    protected $signature = 'relay:source:create {name : A unique name for the publishing app}';

    protected $description = 'Register a source app and issue its API token';

    public function handle(CreateSource $createSource): int
    {
        $validator = Validator::make(
            ['name' => $this->argument('name')],
            ['name' => ['required', 'string', 'max:255', 'unique:sources,name']],
        );

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first('name'));

            return self::FAILURE;
        }

        $created = $createSource->handle($validator->validated()['name']);

        $this->components->info("Source [{$created->source->name}] created with id {$created->source->id}.");
        $this->components->warn('Copy this token now. It will not be shown again.');
        $this->line($created->plainTextToken);

        return self::SUCCESS;
    }
}
