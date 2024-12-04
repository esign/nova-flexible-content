<?php

namespace Marshmallow\Nova\Flexible\Concerns;

use Laravel\Nova\Http\Requests\NovaRequest;
use Marshmallow\Nova\Flexible\Collections\FieldCollection;

trait HasFlexibleAwareFields
{
    /**
     * Get the fields that are available for the given request.
     *
     * @return \Laravel\Nova\Fields\FieldCollection
     */
    public function availableFields(NovaRequest $request)
    {
        return new FieldCollection(array_values($this->filter($this->fields($request))));
    }
}
