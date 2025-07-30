<?php

namespace Thoughtco\StatamicStacheSqlite\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionClass;
use Statamic\Facades\YAML;
use Statamic\Support\Arr;
use Thoughtco\StatamicStacheSqlite\Events\FlatfileCreated;
use Thoughtco\StatamicStacheSqlite\Events\FlatfileDeleted;
use Thoughtco\StatamicStacheSqlite\Events\FlatfileUpdated;
use Thoughtco\StatamicStacheSqlite\Facades\Flatfile;

trait StoreAsFlatfile
{
    protected $schemaColumns;

    protected static $blueprintColumns;

    public function getPathKeyName(): string
    {
        return 'path';
    }

    public static function bootStoreAsFlatfile()
    {
        if (! static::enableFlatfile()) {
            return;
        }

        static::ensureFlatfileDirectoriesExist();

        $driver = Flatfile::driver(static::getFlatfileDriver());
        $modelFile = (new ReflectionClass(static::class))->getFileName();

        if (
            Flatfile::isTesting() ||
            filemtime($modelFile) > filemtime(Flatfile::getDatabasePath()) ||
            $driver->shouldRestoreCache((new static), static::getFlatfilePaths()) ||
            ! static::resolveConnection()->getSchemaBuilder()->hasTable((new static)->getTable())
        ) {
            (new static)->migrate();
        }

        static::created(function (Model $model) {
            if ($model->callTraitMethod('shouldCreate', $model) === false) {
                return;
            }

            // We need to refresh the model so that we can get all of the columns
            // and default values from the SQLite cache.
            // $model->refresh();

            $driver = Flatfile::driver(static::getFlatfileDriver());

            $status = $driver->save(
                $model,
                static::getFlatfilePaths($model)
            );

            event(new FlatfileCreated($model));

            return $status;
        });

        static::updated(function (Model $model) {
            if ($model->callTraitMethod('shouldUpdate', $model) === false) {
                return;
            }

            $driver = Flatfile::driver(static::getFlatfileDriver());

            $status = $driver->save(
                $model,
                static::getFlatfilePaths($model)
            );

            event(new FlatfileUpdated($model));

            return $status;
        });

        static::deleted(function (Model $model) {
            if ($model->callTraitMethod('shouldDelete', $model) === false) {
                return;
            }

            $status = Flatfile::driver(static::getFlatfileDriver())->delete(
                $model,
                static::getFlatfilePaths($model)
            );

            event(new FlatfileDeleted($model));

            return $status;
        });
    }

    public static function schema(Blueprint $table)
    {
        //
    }

    public static function resolveConnection($connection = null)
    {
        if (! static::enableFlatfile()) {
            return parent::resolveConnection($connection);
        }

        return static::$resolver->connection('statamic');
    }

    public function getConnectionName()
    {
        if (! static::enableFlatfile()) {
            return parent::getConnectionName();
        }

        return 'orbit';
    }

    public function migrate()
    {
        $table = $this->getTable();

        /** @var \Illuminate\Database\Schema\Builder $schema */
        $schema = static::resolveConnection()->getSchemaBuilder();

        if ($schema->hasTable($table)) {
            $schema->drop($table);
        }

        /** @var \Illuminate\Database\Schema\Blueprint|null $blueprint */
        static::$blueprintColumns = null;

        static::resolveConnection()->getSchemaBuilder()->create($table, function (Blueprint $table) use (&$blueprint) {
            static::schema($table);

            $this->callTraitMethod('schema', $table);

            $driver = Flatfile::driver(static::getFlatfileDriver());

            if (method_exists($driver, 'schema')) {
                $driver->schema($table);
            }

            if ($this->usesTimestamps()) {
                $table->timestamps();
            }

            static::$blueprintColumns = $table->getColumns();
        });

        $driver = Flatfile::driver(static::getFlatfileDriver());

        foreach (static::getFlatfilePaths() as $directory) {
            $files = $driver->all($this, $directory);

            ray()->measure('inserting_flatfiles: '.get_class($this).' directory: '.$directory);

            $files
                ->filter()
                ->map(fn ($row) => $this->prepareDataForModel($row))
                ->chunk(500)
                ->each(function (Collection $chunk) {
                    $insertWithoutUpdate = $chunk->map(function ($row) {
                        unset($row['updateAfterInsert']);

                        return $row;
                    });

                    static::insert($insertWithoutUpdate->toArray());
                })
                // @TODO: if we can avoid the need for this it reduces the build time on the test site from (2s to 200ms)
                // it would also allow us to switch to using lazy collections
                ->each(function (Collection $chunk) {
                    // some data needs to be added after the initial insert
                    $chunk->each(function ($row) {
                        if (! isset($row['updateAfterInsert'])) {
                            return;
                        }

                        if (! $values = $row['updateAfterInsert']()) {
                            return;
                        }

                        static::newQuery()->where('id', $row['id'])->update($values);
                    });
                });

            ray()->measure('inserting_flatfiles: '.get_class($this).' directory: '.$directory);

        }
    }

    protected function getSchemaColumns(): array
    {
        if ($this->schemaColumns) {
            return $this->schemaColumns;
        }

        $this->schemaColumns = static::resolveConnection()->getSchemaBuilder()->getColumnListing($this->getTable());

        return $this->schemaColumns;
    }

    protected function prepareDataForModel(array $row)
    {
        $columns = $this->getSchemaColumns();

        $newRow = collect($row)
            ->filter(fn ($_, $key) => in_array($key, $columns))
            ->map(function ($value, $key) use ($row) {
                try {
                    $this->setAttribute($key, $value);

                    return $this->attributes[$key];
                } catch (\Exception $e) {
                    dd($value, $key, $row);
                }
            })
            ->toArray();

        foreach ($columns as $column) {
            if (array_key_exists($column, $newRow)) {
                continue;
            }

            $definition = static::$blueprintColumns->firstWhere('name', $column);

            if ($definition->default !== null) {
                $newRow[$column] = $definition->default;
            } elseif ($definition->nullable) {
                $newRow[$column] = null;
            }
        }

        $newRow['updateAfterInsert'] = $row['updateAfterInsert'] ?? null;

        return $newRow;
    }

    protected static function getFlatfileDriver()
    {
        return property_exists(static::class, 'driver') ? static::$driver : null;
    }

    protected static function ensureFlatfileDirectoriesExist()
    {
        if (! static::enableFlatfile()) {
            return;
        }

        $fs = new Filesystem;

        foreach (static::getFlatfilePaths() as $path) {
            $fs->ensureDirectoryExists($path);
        }

        $database = Flatfile::getDatabasePath();

        if (! $fs->exists($database) && $database !== ':memory:') {
            $fs->put($database, '');
        }
    }

    public static function enableFlatfile(): bool
    {
        return true;
    }

    public static function getFlatfileName(): string
    {
        return (string) Str::of(class_basename(static::class))->snake()->lower()->plural();
    }

    public static function getFlatfilePaths(?Model $model = null)
    {
        $directories = [
            base_path('content').DIRECTORY_SEPARATOR.static::getFlatfileName(),
        ];

        return $model ? $directories[0] : $directories;
    }

    public function callTraitMethod(string $method, ...$args)
    {
        $result = null;

        foreach (class_uses_recursive(static::class) as $trait) {
            $methodToCall = $method.class_basename($trait);

            if (method_exists($this, $methodToCall)) {
                $result = $this->{$methodToCall}(...$args);
            }
        }

        return $result;
    }

    public function fileContents()
    {
        // This method should be clever about what contents to output depending on the
        // file type used. Right now it's assuming markdown. Maybe you'll want to
        // save JSON, etc. TODO: Make it smarter when the time is right.

        $data = $this->fileData();

        if ($this->shouldRemoveNullsFromFileData()) {
            $data = Arr::removeNullValues($data);
        }

        if ($this->fileExtension() === 'yaml') {
            return YAML::dump($data);
        }

        if (! Arr::has($data, 'content')) {
            return YAML::dumpFrontMatter($data);
        }

        $content = $data['content'];

        return $content === null
            ? YAML::dump($data)
            : YAML::dumpFrontMatter(Arr::except($data, 'content'), $content);
    }

    protected function shouldRemoveNullsFromFileData()
    {
        return true;
    }

    public function fileExtension()
    {
        return 'yaml';
    }
}
