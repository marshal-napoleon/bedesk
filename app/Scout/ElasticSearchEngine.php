<?php namespace App\Scout;

use Laravel\Scout\Builder;
use Elasticsearch\ClientBuilder;
use Laravel\Scout\Engines\Engine;
use Illuminate\Support\Collection;
use Elasticsearch\Client as Elastic;

class ElasticSearchEngine extends Engine
{
    /**
     * ElasticSearch PHP client instance.
     *
     * @var Elastic
     */
    private $elastic;

    /**
     * Name of ElasticSearch index.
     *
     * @var string
     */
    private $index = 'general';

    /**
     * ElasticSearchEngine constructor.
     */
    public function __construct()
    {
        $this->elastic = ClientBuilder::create()
            ->setLogger(ClientBuilder::defaultLogger(storage_path('logs/elastic.log')))
            ->build();
    }

    /**
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function update($models)
    {
        $params = [];

        $models->each(function($model) use (&$params)
        {
            $params['body'][] = [
                'update' => [
                    '_id' => $model->getKey(),
                    '_index' => $this->index,
                    '_type' => $model->searchableAs(),
                ]
            ];
            $params['body'][] = [
                'doc' => $model->toSearchableArray(),
                'doc_as_upsert' => true
            ];
        });

        $this->elastic->bulk($params);
    }

    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function delete($models)
    {
        $params = [];

        $models->each(function($model) use (&$params)
        {
            $params['body'][] = [
                'delete' => [
                    '_id' => $model->getKey(),
                    '_index' => $this->index,
                    '_type' => $model->searchableAs(),
                ]
            ];
        });

        $this->elastic->bulk($params);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return Collection
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder, array_filter([
            'numericFilters' => $this->filters($builder),
            'sorting' => $this->sorting($builder),
            'size' => $builder->limit,
        ]));
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  int  $perPage
     * @param  int  $page
     * @return Collection
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        $result = $this->performSearch($builder, [
            'numericFilters' => $this->filters($builder),
            'sorting' => $this->sorting($builder),
            'from' => ($page * $perPage) - $perPage,
            'size' => $perPage,
        ]);

        $result['nbPages'] = $result['hits']['total']/$perPage;

        return $result;
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  Builder  $builder
     * @param  array  $options
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        if ($builder->callback) {
            return call_user_func($builder->callback, $this->elastic, $builder->query, $options);
        }

        $params = [
            'index' => $this->index,
            'type' => $builder->model->searchableAs(),
            'body' => [
                "query" =>  [
                    "multi_match" => [
                        "type"   =>  "most_fields",
                        "query"  =>  $builder->query,
                        "fields" => [
                            "title",
                            "title.english",
                            'body',
                            'description',
                            'subject',
                            'replies',
                            'user',
                            'first_name',
                            'last_name',
                            'email',
                            'envato_username',
                            'tags',
                        ],
                    ]
                ],
                'sort' => [
                    '_score'
                ],
                'track_scores' => true,
            ]
        ];

        if (isset($options['from'])) {
            $params['body']['from'] = $options['from'];
        }

        if (isset($options['size'])) {
            $params['body']['size'] = $options['size'];
        }

        if (isset($options['numericFilters']) && count($options['numericFilters'])) {
            $params['body']['query']['bool']['filter'] = $options['numericFilters'];
        }

        // Sorting
        if(isset($options['sorting']) && count($options['sorting'])) {
            $params['body']['sort'] = array_merge($params['body']['sort'], $options['sorting']);
        }

        return $this->elastic->search($params);
    }

    /**
     * Get the filter array for the query.
     *
     * @param  Builder  $builder
     * @return array
     */
    protected function filters(Builder $builder)
    {
        return collect($builder->wheres)->map(function ($value, $key) {
            return ['term' => [$key => $value]];
        })->values()->all();
    }

    /**
     * @param Builder $builder
     * @return array
     */
    protected function sorting(Builder $builder)
    {
        return collect($builder->orders)->map(function ($value) {
            return [array_get($value, 'column') => ['order' => array_get($value, 'direction')]];
        })->values()->all();
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return collect($results['hits']['hits'])->pluck('_id')->values();
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return Collection
     */
    public function map($results, $model)
    {
        if ($results['hits']['total'] === 0) {
            return Collection::make();
        }

        $keys = $this->mapIds($results)->all();

        $models = $model->whereIn($model->getKeyName(), $keys)->get()->keyBy($model->getKeyName());

        return collect($results['hits']['hits'])->map(function ($hit) use ($model, $models) {
            $key = $hit['_id'];
            return isset($models[$key]) ? $models[$key] : false;
        });
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed  $results
     * @return int
     */
    public function getTotalCount($results)
    {
        return $results['hits']['total'];
    }
}
