<?php

namespace Thoughtco\StatamicStacheSqlite\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Entries\GetDateFromPath;
use Statamic\Entries\GetSlugFromPath;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Facades\YAML;
use Statamic\Support\Arr;
use Statamic\Support\Str;
use Thoughtco\StatamicStacheSqlite\Models\Concerns\Flatfile;

class Entry extends Model
{
    use Flatfile;
    use HasUuids;

    public static $driver = 'stache';

    public string $path = '';

    protected function casts(): array
    {
        return [
            'data' => AsArrayObject::class,
            'date' => 'datetime',
        ];
    }

    public function getKeyName()
    {
        return 'id';
    }

    public static function getOrbitalPath()
    {
        return rtrim(Stache::store('entries')->directory(), '/');
    }

    public function getIncrementing()
    {
        return false;
    }

    public function makeContract()
    {
        $contract = app(EntryContract::class)::make();

        $attributes = collect($this->getAttributes())
            ->map(function ($_, $key) {
                $value = $this->{$key};

                if ($value instanceof \BackedEnum) {
                    return $value->value;
                }

                if ($value instanceof Carbon) {
                    if ($this->getRawOriginal($key) == '') {
                        return null;
                    }

                    return $value;
                }

                return $value;
            })
            ->toArray();

        foreach ($attributes as $key => $value) {
            if ($key == 'created_at' || $key == 'updated_at') {
                continue;
            }

            if ($value !== null) {
                if ($key == 'data' && is_string($value)) {
                    $value = json_decode($value, true);
                }

                if ($key == 'date') {
                    if (! $value) {
                        continue;
                    }
                }

                $contract->$key($value);
            }
        }

        return $contract;
    }

    public function fromPath(string $originalPath)
    {
        return $this->fromPathAndContents($originalPath, File::get($originalPath));
    }

    public function fromPathAndContents(string $originalPath, string $contents)
    {
        $path = Str::after($originalPath, static::getOrbitalPath().DIRECTORY_SEPARATOR);

        $collectionHandle = Str::before($path, DIRECTORY_SEPARATOR);

        if ($collectionHandle == $path) {
            return;
        }

        $data = [
            'collection' => $collectionHandle,
            'site' => 'default',
        ];

        // need to date, site etc
        $slug = Str::of($path)->after($collectionHandle.DIRECTORY_SEPARATOR)->before('.md');

        if (Site::multiEnabled()) {
            $data['site'] = (string) $slug->before(DIRECTORY_SEPARATOR);
            $slug = $slug->after(DIRECTORY_SEPARATOR);
        }

        if ($slug->contains('.')) {
            $data['date'] = (string) $slug->before('.');
            $slug = $slug->after('.');
        }

        $data['slug'] = (string) $slug;

        $columns = $this->getSchemaColumns();

        $yamlData = YAML::parse($contents);

        $data = array_merge(
            collect($columns)->mapWithKeys(fn ($value) => [
                $value => Arr::get(collect(static::$blueprintColumns)->firstWhere('name', $value)?->toArray() ?? [], 'default', ''),
            ])->all(),
            collect($yamlData)->only($columns)->all(),
            $data,
            ['data' => collect($yamlData)->except($columns)->all()]
        );

        return $data;
    }

    public function fromContract(EntryContract $entry)
    {
        foreach (['id', 'data', 'date', 'published', 'slug'] as $key) {
            $this->$key = $entry->{$key}();
        }

        $collection = $entry->collection();

        $this->blueprint = $entry->blueprint()->handle();
        $this->collection = $collection->handle();
        $this->site = $entry->locale();

        if (! $this->id) {
            $this->id = Str::uuid()->toString();
        }

        $this->path = Str::of($entry->buildPath())->after(static::getOrbitalPath().DIRECTORY_SEPARATOR)->beforeLast('.md');

        return $this;
    }

    public function makeItemFromFile($path, $contents)
    {
        $data = $this->fromPathAndContents($path, $contents);

        if (! $id = Arr::pull($data, 'id')) {
            $id = app('stache')->generateId();
        }

        $collectionHandle = $data['collection'];
        $collection = Collection::findByHandle($collectionHandle);

        $entry = \Statamic\Facades\Entry::make()
            ->id($id)
            ->collection($collection);

        if ($origin = Arr::pull($data, 'origin')) {
            $entry->origin($origin);
        }

        $entry
            ->blueprint($data['blueprint'] ?? null)
            ->locale($data['site'])
            ->initialPath($path)
            ->published(Arr::pull($data, 'published', true))
            ->data($data['data']);

        $slug = (new GetSlugFromPath)($path);

        if (! $collection->requiresSlugs() && $slug == $id) {
            $entry->slug(null);
        } else {
            $entry->slug($slug);
        }

        if ($collection->dated()) {
            $entry->date((new GetDateFromPath)($path));
        }

        $entry->model($this);

        return $entry;
    }

    public static function schema(Blueprint $table)
    {
        $table->string('id')->unique();
        // $table->string('path');
        $table->string('blueprint');
        $table->string('collection');
        $table->json('data')->nullable();
        $table->datetime('date')->nullable();
        $table->boolean('published')->default(true);
        $table->string('site');
        $table->string('slug');
    }

    public function fileData()
    {
        //        $origin = $this->origin;
        //        $blueprint = $this->blueprint;
        //
        //        if ($origin && $this->blueprint()->handle() === $origin->blueprint()->handle()) {
        //            $blueprint = null;
        //        }

        $array = Arr::removeNullValues([
            'id' => $this->id,
            'origin' => $this->origin,
            'published' => $this->published === false ? false : null,
            'blueprint' => $this->blueprint,
        ]);

        $data = $this->data->all();

        if (! $this->origin) {
            $data = Arr::removeNullValues($data);
        }

        return array_merge($array, $data);
    }

    public function fileExtension()
    {
        return 'md';
    }
}
