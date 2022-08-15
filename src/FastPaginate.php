<?php
/**
 * @author Aaron Francis <aarondfrancis@gmail.com|https://twitter.com/aarondfrancis>
 */

namespace Hammerstone\FastPaginate;

use Closure;
use Illuminate\Database\Query\Expression;
use Illuminate\Pagination\Paginator;

class FastPaginate
{
    public function fastPaginate()
    {
        return $this->paginate('paginate', function (array $items, $perPage, $page) {
            return $this->paginator(
                $items,
                $this->toBase()->getCountForPagination(),
                $perPage,
                $page,
                []
            );
        });
    }

    public function simpleFastPaginate()
    {
        return $this->paginate('simplePaginate', function (array $items, $perPage, $page) {
            return $this->simplePaginator(
                $items,
                $perPage,
                $page,
                []
            );
        });
    }

    protected function paginate(string $paginationMethod, Closure $paginatorOutput)
    {
        return function ($perPage = null, $columns = ['*'], $pageName = 'page', $page = null) use (
            $paginationMethod,
            $paginatorOutput
        ) {
            /** @var \Illuminate\Database\Query\Builder $this */
            $base = $this->getQuery();
            // Havings and groups don't work well with this paradigm, because we are
            // counting on each row of the inner query to return a primary key
            // that we can use. When grouping, that's not always the case.
            if (filled($base->havings) || filled($base->groups)) {
                return $this->{$paginationMethod}($perPage, $columns, $pageName, $page);
            }

            $model = $this->newModelInstance();
            $key = $model->getKeyName();
            $table = $model->getTable();

            $page = $page ?: Paginator::resolveCurrentPage($pageName);

            $perPage = $perPage ?: $this->model->getPerPage();

            $innerSelectColumns = FastPaginate::getInnerSelectColumns($this);

            $innerQuery = $this->clone()
                // Only select the primary keys
                ->select($innerSelectColumns)
                ->forPage($page, $perPage)
                // We don't need eager loads for this cloned query, they'll
                // remain on the query that actually gets the records.
                // (withoutEagerLoads not available on Laravel 8.)
                ->setEagerLoads([])
                ->getQuery();

            $this->query->joinSub($innerQuery, 'fast_paginate_inner_query', "{$table}.{$key}", "fast_paginate_inner_query.{$key}");

            $items = $this->simplePaginate($perPage, $columns, $pageName, 1)->items();

            return Closure::fromCallable($paginatorOutput)->call($this, $items, $perPage, $page);
        };
    }

    /**
     * @param $builder
     * @return array
     */
    public static function getInnerSelectColumns($builder)
    {
        $base = $builder->getQuery();
        $model = $builder->newModelInstance();
        $key = $model->getKeyName();
        $table = $model->getTable();

        // Collect all of the `orders` off of the base query and pluck
        // the column out. Based on what orders are present, we may
        // have to include certain columns in the inner query.
        $orders = collect($base->orders)
            ->pluck('column')
            ->map(function ($column) use ($base) {
                // Use the grammar to wrap them, so that our `str_contains`
                // (further down) doesn't return any false positives.
                return $base->grammar->wrap($column);
            });

        return collect($base->columns)
            ->filter(function ($column) use ($orders, $base) {
                $column = $column instanceof Expression ? $column->getValue() : $base->grammar->wrap($column);
                foreach ($orders as $order) {
                    // If we're ordering by this column, then we need to
                    // keep it in the inner query.
                    if (str_contains($column, "as $order")) {
                        return true;
                    }
                }

                // Otherwise we don't.
                return false;
            })
            ->prepend("$table.$key")
            ->unique()
            ->values()
            ->toArray();
    }
}
