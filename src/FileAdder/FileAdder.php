<?php

namespace Marshmallow\Nova\Flexible\FileAdder;

use Spatie\MediaLibrary\Helpers\File;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Exceptions\FileCannotBeAdded\FileIsTooBig;
use Spatie\MediaLibrary\Exceptions\FileCannotBeAdded\DiskDoesNotExist;
use Spatie\MediaLibrary\Exceptions\FileCannotBeAdded\FileDoesNotExist;
use Spatie\MediaLibrary\MediaCollections\FileAdder as OriginalFileAdder;

class FileAdder extends OriginalFileAdder
{
    /**
     * The suffix in which we append the media collection name
     *
     * @param  string  $suffix
     * @return FileAdder
     */
    protected $media_collection_suffix = null;

    public function setMediaCollectionSuffix(string $suffix): self
    {
        $this->media_collection_suffix = $suffix;

        return $this;
    }

    public function toMediaCollection(string $collectionName = 'default', string $diskName = ''): Media
    {
        if ($this->media_collection_suffix) {
            $collectionName .= $this->media_collection_suffix;
        }

        return parent::toMediaCollection($collectionName, $diskName);
    }

    public function determineDiskName(string $diskName, string $collectionName): string
    {
        if ($this->media_collection_suffix) {
            $collectionName = str_replace($this->media_collection_suffix, '', $collectionName);
        }

        return parent::determineDiskName($diskName, $collectionName);
    }
}
