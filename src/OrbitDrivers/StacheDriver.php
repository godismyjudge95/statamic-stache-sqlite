<?php

namespace Thoughtco\StatamicStacheSqlite\OrbitDrivers;

use BackedEnum;
use FilesystemIterator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Orbit\Facades\Orbit;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Statamic\Facades\File;
use Statamic\Facades\Stache;
use Statamic\Facades\YAML;

class StacheDriver
{
    public function shouldRestoreCache(string $directory): bool
    {
        // if there is no watcher, always use existing cache
        if (! Stache::isWatcherEnabled()) {
            return false;
        }

        $databaseLastUpdated = filemtime(Orbit::getDatabasePath());

        foreach (new FilesystemIterator($directory) as $file) {
            if ($file->getMTime() > $databaseLastUpdated) {
                return true;
            }
        }

        return false;
    }

    public function save(Model $model, string $directory): bool
    {
        if ($model->wasChanged($model->getPathKeyName())) {
            unlink($this->filepath($directory, $model->getOriginal($model->getPathKeyName())));
        }

        $path = $this->filepath($directory, $model->{$model->getPathKeyName()});

        file_put_contents($path, $this->dumpContent($model));

        return true;
    }

    public function delete(Model $model, string $directory): bool
    {
        unlink($this->filepath($directory, $model->{$model->getPathKeyName()}));

        return true;
    }

    public function all(Model $model, string $directory): Collection
    {
        $collection = Collection::make();
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        $columns = $model->resolveConnection()->getSchemaBuilder()->getColumnListing($model->getTable());

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                $files = $this->all($model, $file->getRealPath());

                $collection->merge($files);

                continue;
            }

            if ($file->getExtension() !== 'md') {
                continue;
            }

            // let the model determine how to parse the data
            $row = array_merge(
                $model->fromPath($file->getPathname()),
                [
                    'file_path_read_from' => $file->getRealPath(),
                ]
            );

            $collection->push($row);
        }

        return $collection;
    }

    public function filepath(string $directory, string $key): string
    {
        return $directory.DIRECTORY_SEPARATOR.$key.'.md';
    }

    protected function getModelAttributes(Model $model)
    {
        return collect($model->getAttributes())
            ->map(function ($_, $key) use ($model) {
                $value = $model->{$key};

                if ($value instanceof BackedEnum) {
                    return $value->value;
                }

                return $value;
            })
            ->toArray();
    }

    protected function dumpContent(Model $model): string
    {
        $matter = array_filter($this->getModelAttributes($model), function ($value, $key) {
            return $value !== null;
        }, ARRAY_FILTER_USE_BOTH);

        if ($data = ($matter['data'] ?? false)) {
            unset($matter['data']);

            $matter = array_merge($matter, $data);
        }

        if ($content = ($matter['content'] ?? null)) {
            unset($matter['content']);
        }

        return YAML::dumpFrontMatter($matter, $content);
    }

    //    protected function parseContent(SplFileInfo $file, array $columns = [], ?Model $model = null): array
    //    {
    //        $yamlData = YAML::file($file->getPathname())->parse();
    //
    //        return array_merge(
    //            collect($columns)->mapWithKeys(fn ($value) => [$value => ''])->all(),
    //            collect($yamlData)->only($columns)->all(),
    //            $model ? $model->fromPath($file->getPathname()) : [],
    //            ['data' => collect($yamlData)->except($columns)->all()],
    //        );
    //    }
}
