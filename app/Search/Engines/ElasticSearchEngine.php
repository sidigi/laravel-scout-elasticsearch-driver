<?php

namespace App\Search\Engines;

use Elasticsearch\Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;

class ElasticSearchEngine extends Engine
{
    protected Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function update($models)
    {
        $models->each(function ($model) {
            $params = $this->getRequestBody($model, [
                'id' => $model->id,
                'body' => $model->toSearchableArray(),
            ]);

            $this->client->index($params);
        });
    }

    public function delete($models)
    {
        $models->each(function ($model) {
            $params = $this->getRequestBody($model, [
                'id' => $model->id,
            ]);

            $this->client->delete($params);
        });
    }

    public function search(Builder $builder)
    {
        return $this->performSearch($builder);
    }

    protected function performSearch(Builder $builder, array $options = [])
    {
        $params = array_merge_recursive(
            $this->getRequestBody($builder->model),
            [
                'body' => [
                    'from' => 0,
                    'size' => 5000,
                    'query' => [
                        'multi_match' => [
                            'query' => $builder->query ?? '',
                            'fields' => $this->getSearchableFields($builder->model),
                            'type' => 'phrase_prefix',
                        ],
                    ],
                ],
            ],
            $options
        );

        return $this->client->search($params);
    }

    protected function getSearchableFields($model)
    {
        if (! method_exists($model, 'searchableFields')) {
            return [];
        }

        return $model->searchableFields();
    }

    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->performSearch($builder, [
            'from' => ($page - 1) * $perPage,
            'size' => $perPage,
        ]);
    }

    public function mapIds($results)
    {
        return collect(Arr::get($results, 'hits.hits'))->pluck('_id')->values();
    }

    public function map(Builder $builder, $results, $model)
    {
        if (count($hits = Arr::get($results, 'hits.hits')) === 0) {
            return $model->newCollection();
        }

        return $model->getScoutModelsByIds(
            $builder,
            collect($hits)->pluck('_id')->values()->all()
        );
    }

    public function getTotalCount($results)
    {
        return Arr::get($results, 'hits.total', 0);
    }

    public function flush($model)
    {
        $this->client->indices()->delete([
            'index' => $model->searchableAs(),
        ]);

        Artisan::call('scout:elasticsearch:create', [
            'model' => get_class($model),
        ]);
    }

    protected function getRequestBody($model, array $options = [])
    {
        return array_merge_recursive([
            'index' => $model->searchableAs(),
            'type' => $model->searchableAs(),
        ], $options);
    }
}
