<?php


namespace Riclep\Storyblok;


use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Riclep\Storyblok\Fields\Asset;
use Riclep\Storyblok\Fields\Image;
use Riclep\Storyblok\Fields\MultiAsset;
use Riclep\Storyblok\Fields\RichText;
use Riclep\Storyblok\Fields\Table;
use Riclep\Storyblok\Traits\HasChildClasses;

class ProcessFields
{
	use HasChildClasses;

	private $block;

	public function __construct(Block $block)
	{
		$this->block = $block;
	}

	/**
	 * Loops over every field to get the ball rolling
	 */
	public function processFields()
	{
		$this->block->getFields()->transform(function ($field, $key) {
			return $this->getFieldType($field, $key);
		});
	}

	/**
	 * When the field is an array we need to do more processing
	 *
	 * @param $field
	 * @return Collection|mixed|Asset|Image|MultiAsset|RichText|Table
	 */
	public function arrayFieldTypes($field, $key)
	{
		// match link fields
		if (array_key_exists('linktype', $field)) {
			$class = 'Riclep\Storyblok\Fields\\' . Str::studly($field['linktype']) . 'Link';

			return new $class($field, $this->block);
		}

		// match rich-text fields
		if (array_key_exists('type', $field) && $field['type'] === 'doc') {
			return new RichText($field, $this->block);
		}

		// match asset fields - detecting raster images
		if (array_key_exists('fieldtype', $field) && $field['fieldtype'] === 'asset') {
			if (Str::endsWith($field['filename'], ['.jpg', '.jpeg', '.png', '.gif', '.webp'])) {
				return new Image($field, $this->block);
			}

			return new Asset($field, $this->block);
		}

		// match table fields
		if (array_key_exists('fieldtype', $field) && $field['fieldtype'] === 'table') {
			return new Table($field, $this);
		}

		if (array_key_exists(0, $field)) {
			// it’s an array of relations - request them if we’re auto or manual resolving
			if (Str::isUuid($field[0])) {
				if ($this->block->_autoResolveRelations || in_array($key, $this->block->_resolveRelations)) {
					return collect($field)->transform(function ($relation) {
						return $this->getRelation(new RequestStory(), $relation);
					});
				}
			}

			// has child items - single option, multi option and Blocks fields
			if (is_array($field[0])) {
				// resolved relationships - entire story is returned, we just want the content and a few meta items
				if (array_key_exists('content', $field[0])) {
					return collect($field)->transform(function ($relation) {
						$class = $this->getChildClassName('Block', $relation['content']['component']);
						$relationClass = new $class($relation['content'], $this->block);

						$relationClass->addMeta([
							'name' => $relation['name'],
							'published_at' => $relation['published_at'],
							'full_slug' => $relation['full_slug'],
						]);

						return $relationClass;
					});
				}

				// this field holds blocks!
				if (array_key_exists('component', $field[0])) {
					return collect($field)->transform(function ($block) {
						$class = $this->getChildClassName('Block', $block['component']);

						return new $class($block, $this->block);
					});
				}

				// multi assets
				if (array_key_exists('filename', $field[0])) {
					return new MultiAsset($field, $this->block);
				}
			}
		}

		// just return the array
		return $field;
	}

	/**
	 * Converts fields into Field Classes based on various properties of their content
	 *
	 * @param $field
	 * @param $key
	 * @return array|Collection|mixed|Asset|Image|MultiAsset|RichText|Table
	 * @throws \Storyblok\ApiException
	 */
	public function getFieldType($field, $key)
	{
		// TODO process old asset fields
		// TODO option to convert all text fields to a class - single or multiline?

		// does the Block assign any $_casts? This is key (field) => value (class)
		if (property_exists($this->block, '_casts') && array_key_exists($key, $this->block->getCasts())) {
			$casts = $this->block->getCasts();
			return new $casts[$key]($field, $this->block);
		}

		// find Fields specific to this Block matching: BlockNameFieldName
		if ($class = $this->getChildClassName('Field', $this->block->component() . '_' . $key)) {
			return new $class($field, $this->block);
		}

		// auto-match Field classes
		if ($class = $this->getChildClassName('Field', $key)) {
			return new $class($field, $this->block);
		}

		// single item relations
		if (Str::isUuid($field) && ($this->block->_autoResolveRelations || in_array($key, $this->block->_resolveRelations))) {
			return $this->getRelation(new RequestStory(), $this->block);
		}

		// complex fields
		if (is_array($field) && !empty($field)) {
			return $this->arrayFieldTypes($field, $this->block);
		}

		// legacy image fields
		if (is_string($field) && Str::endsWith($field, ['.jpg', '.jpeg', '.png', '.gif', '.webp'])) {
			return new Image($field, $this->block);
		}

		// strings or anything else - do nothing
		return $field;
	}

	protected function getRelation(RequestStory $request, $relation) {
		$response = $request->get($relation);

		$class = $this->getChildClassName('Block', $response['content']['component']);
		$relationClass = new $class($response['content'], $this);

		$relationClass->addMeta([
			'name' => $response['name'],
			'published_at' => $response['published_at'],
			'full_slug' => $response['full_slug'],
		]);

		return $relationClass;
	}
}