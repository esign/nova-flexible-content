<?php

namespace Marshmallow\Nova\Flexible\Concerns;

use Illuminate\Support\Facades\Cache;
use Laravel\Nova\NovaServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Marshmallow\Nova\Flexible\Facades\Flex;
use Marshmallow\Nova\Flexible\Layouts\Layout;
use Marshmallow\Nova\Flexible\Layouts\Collection;
use Marshmallow\Nova\Flexible\Value\FlexibleCast;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Collection as BaseCollection;
use Marshmallow\Nova\Flexible\Layouts\DependedLayout;

trait HasFlexible
{
    protected static function bootHasFlexible()
    {
        static::created(function (Model $model) {
            $model->clearCachedFlexibleData();
        });

        static::updated(function (Model $model) {
            $model->clearCachedFlexibleData();
        });

        static::deleted(function (Model $model) {
            $model->clearCachedFlexibleData();
        });
    }

    public function clearCachedFlexibleData()
    {
        $cache_tag = self::getCacheTagForDependedLayoutSelect();
        if ($cache_tag) {
            Cache::tags($cache_tag)->flush();
        }
    }

    /**
     * Parse a Flexible Content attribute
     *
     * @param string $attribute
     * @param array  $layoutMapping
     * @return \Marshmallow\Nova\Flexible\Layouts\Collection
     */
    public function flexible($attribute, $layoutMapping = [])
    {
        $flexible = data_get($this->attributes, $attribute);

        return $this->cast($flexible, $layoutMapping);
    }

    /**
     * Cast a Flexible Content value
     *
     * @param array $value
     * @param array $layoutMapping
     * @return \Marshmallow\Nova\Flexible\Layouts\Collection
     */
    public function cast($value, $layoutMapping = [])
    {
        if (app()->getProvider(NovaServiceProvider::class) && !app()->runningInConsole()) {
            return $value;
        }

        return $this->toFlexible($value ?: null, $layoutMapping);
    }

    public function flex($column, $with = [])
    {
        return $this->toFlexible(
            $this->{$column},
            Flex::getLayouts(get_class($this)),
            $with
        );
    }

    /**
     * Parse a Flexible Content from value
     *
     * @param mixed $value
     * @param array $layoutMapping
     * @return \Marshmallow\Nova\Flexible\Layouts\Collection
     */
    public function toFlexible($value, $layoutMapping = [], $with = [])
    {
        $flexible = $this->getFlexibleArrayFromValue($value);

        if (is_null($flexible)) {
            return new Collection();
        }

        if (!is_array($flexible)) {
            return new Collection();
        }

        $flexible_layouts = new Collection(
            array_filter($this->getMappedFlexibleLayouts($flexible, $layoutMapping, $with))
        );

        /** Add the cloned / mirrowed depended layouts */
        $flexible_layouts->each(function ($layout, $layout_array_key) use (&$flexible_layouts) {
            if ($layout instanceof DependedLayout || $layout->title() == 'depended-layout') {
                [$model_id, $column, $layout_key] = explode('___', $layout->layout);
                $model_class = get_class($this);
                $depended_page = $model_class::find($model_id);
                $depended_page_layouts = $depended_page->flex($column);
                $depended_page_layouts->each(function ($layout) use ($layout_key, $layout_array_key, &$flexible_layouts) {
                    if ($layout->key == $layout_key) {
                        $flexible_layouts[$layout_array_key] = $layout;
                    }
                });
            }
        });

        return $flexible_layouts;
    }

    /**
     * Transform incoming value into an array of usable layouts
     *
     * @param mixed $value
     * @return array|null
     */
    protected function getFlexibleArrayFromValue($value)
    {
        if (is_string($value)) {
            $value = json_decode($value);

            return is_array($value) ? $value : null;
        }

        if (is_a($value, BaseCollection::class)) {
            return $value->toArray();
        }

        if (is_array($value)) {
            return $value;
        }

        return null;
    }

    /**
     * Map array with Flexible Content Layouts
     *
     * @param array $flexible
     * @param array $layoutMapping
     * @return array
     */
    protected function getMappedFlexibleLayouts(array $flexible, array $layoutMapping, array $with = [])
    {
        return array_map(function ($item) use ($layoutMapping, $with) {
            return $this->getMappedLayout($item, $layoutMapping, $with);
        }, $flexible);
    }

    /**
     * Transform given layout value into a usable Layout instance
     *
     * @param mixed $item
     * @param array $layoutMapping
     * @return null|\Marshmallow\Nova\Flexible\Layouts\LayoutInterface
     */
    protected function getMappedLayout($item, array $layoutMapping, array $with = [])
    {
        $name = null;
        $key = null;
        $attributes = [];

        if (is_string($item)) {
            $item = json_decode($item);
        }

        if (is_array($item)) {
            $name = $item['layout'] ?? null;
            $key = $item['key'] ?? null;
            $attributes = (array_key_exists('attributes', $item) && $item['attributes']) ? $item['attributes'] : [];
        } elseif (is_a($item, \stdClass::class)) {
            $name = $item->layout ?? null;
            $key = $item->key ?? null;
            $attributes = (array) ($item->attributes ?? []);
        } elseif (is_a($item, Layout::class)) {
            $name = $item->name();
            $key = $item->key();
            $attributes = $item->getAttributes();
        }

        if (is_null($name)) {
            return;
        }

        return $this->createMappedLayout($name, $key, $attributes, $layoutMapping, $with);
    }

    /**
     * Transform given layout value into a usable Layout instance
     *
     * @param string $name
     * @param string $key
     * @param array  $attributes
     * @param array  $layoutMapping
     * @return \Marshmallow\Nova\Flexible\Layouts\LayoutInterface
     */
    protected function createMappedLayout($name, $key, $attributes, array $layoutMapping, array $with = [])
    {
        $classname = array_key_exists($name, $layoutMapping)
            ? $layoutMapping[$name]
            : config('flexible.default_layout_class');

        $layout = new $classname($name, $name, [], $key, $attributes);

        $model = is_a($this, FlexibleCast::class)
            ? $this->model
            : $this;

        $layout->onLoad();
        $layout->setModel($model);
        $layout->setWith($with);

        $attributes = $layout->getAttributes();
        foreach ($attributes as $key => $attribute) {
            if (!is_array($attribute) || !filled($attribute)) {
                continue;
            }

            $attributes[$key] = $this->toFlexible($attribute, $layoutMapping, $with)
                ->map(function ($sub_layout) {
                    // Set the attribute on the layout for backwards compatibility
                    $sub_attributes = $sub_layout->getAttributes();
                    $sub_layout->attributes = json_decode(json_encode($sub_attributes));
                    return $sub_layout;
                });
        }

        $layout->setRawAttributes($attributes);
        return $layout;
    }

    public static function getOptionsQueryBuilderForDependedLayoutSelect(): Builder
    {
        return self::query();
    }

    public static function getCacheTagForDependedLayoutSelect(): ?string
    {
        return 'depended-select-options';
    }

    public static function getCacheTtlForDependedLayoutSelect(): int
    {
        return 60 * 60 * 24;
    }

    public static function getCacheKeyForDependedLayoutSelect(): string
    {
        return 'options-for-depended-layout-select';
    }

    public static function getDependedLayoutSelectColumns(): array
    {
        return [
            'layout',
        ];
    }

    public function getDependedLayoutGroup(): string
    {
        if ($this->name) {
            return $this->name;
        }
        $unknown_name = (new \ReflectionClass($this))->getShortName();
        return  "{$unknown_name}: #{$this->id}";
    }

    public static function getDependedLayoutLabel($layout): string
    {
        if (isset($layout->attributes->title)) {
            return $layout->attributes->title;
        }

        if (isset($layout->attributes->name)) {
            return $layout->attributes->name;
        }

        if (isset($layout->attributes->title)) {
            return $layout->attributes->title;
        }

        return __('Unknown');
    }

    public static function getLayoutsToIgnoreFromDependendLayout(): array
    {
        return [
            //
        ];
    }

    public static function getOptionsForDependedLayoutSelect(Model $model): array
    {
        $callable = function () use ($model) {
            $options = [];
            $columns = $model::getDependedLayoutSelectColumns();
            $ignore_layouts = array_merge(self::getLayoutsToIgnoreFromDependendLayout(), [
                'depended-layout'
            ]);

            self::getOptionsQueryBuilderForDependedLayoutSelect()
                ->get()
                ->each(function ($model) use (&$options, $columns, $ignore_layouts) {
                    foreach ($columns as $column) {
                        $layouts = json_decode($model->{$column});
                        if (!is_array($layouts)) {
                            continue;
                        }
                        foreach ($layouts as $key => $layout) {
                            try {
                                if (in_array($layout->layout, $ignore_layouts)) {
                                    continue;
                                }

                                $key = "{$model->id}___{$column}___{$layout->key}";

                                $label = self::getDependedLayoutLabel($layout);
                                $group = $model->getDependedLayoutGroup($layout);
                                $options[$key] = ['label' => $label, 'group' => $group];
                            } catch (ErrorException $e) {
                                //
                            }
                        }
                    }
                });

            return $options;
        };

        $cache_tag = self::getCacheTagForDependedLayoutSelect();
        $cache_ttl = self::getCacheTtlForDependedLayoutSelect();
        $cache_key = self::getCacheKeyForDependedLayoutSelect();

        if ($cache_tag) {
            return Cache::tags($cache_tag)->remember($cache_key, $cache_ttl, $callable);
        }

        return $callable();
    }
}
