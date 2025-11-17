<?php

namespace Backpack\CRUD\app\Models\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Methods for working with translatable models.
|--------------------------------------------------------------------------
*/
trait HasTranslatableFields
{
    /**
     * Get the attributes that were casted in the model.
     * Used for translations because Spatie/Laravel-Translatable
     * overwrites the getCasts() method.
     *
     * @return self
     */
    public function getCastedAttributes()
    {
        return parent::getCasts();
    }

    /**
     * Check if a model is translatable.
     * All translation adaptors must have the translationEnabledForModel() method.
     *
     * @return bool
     */
    public function translationEnabled()
    {
        if (method_exists($this, 'translationEnabledForModel')) {
            return $this->translationEnabledForModel();
        }

        return false;
    }

    /**
     * Return translation diagnostics (filled flag & length) for each locale of a field.
     *
     * @param  string|null  $attribute
     * @return array<string, array{filled: bool, length: int}>
     */
    public function getTranslationLocalesState(?string $attribute): array
    {
        if (! $attribute) {
            return [];
        }

        $attribute = (string) $attribute;
        $supportsStandard = $this->translationEnabled()
            && method_exists($this, 'isTranslatableAttribute')
            && $this->isTranslatableAttribute($attribute);
        $supportsAdditional = $this->isAdditionalTranslatableAttribute($attribute);

        if (! $supportsStandard && ! $supportsAdditional) {
            return [];
        }

        if ($method = $this->resolveCustomTranslationStateMethod($attribute)) {
            $result = $this->{$method}();

            return is_array($result) ? $result : [];
        }

        if (! $supportsStandard) {
            return [];
        }

        $locales = $this->getTranslatableLocaleKeys();
        $translations = method_exists($this, 'getTranslations')
            ? (array) $this->getTranslations($attribute)
            : [];

        $state = [];

        foreach ($locales as $locale) {
            $value = $translations[$locale] ?? null;
            $length = $this->calculateTranslationValueLength($value);

            $state[$locale] = [
                'filled' => $length > 0,
                'length' => $length,
            ];
        }

        return $state;
    }

    /**
     * Determine if attribute is part of additional translatable set.
     */
    public function isAdditionalTranslatableAttribute(?string $attribute): bool
    {
        if (! $attribute) {
            return false;
        }

        return in_array($attribute, $this->getAdditionalTranslatableAttributes(), true);
    }

    /**
     * @return array<int, string>
     */
    protected function getTranslatableLocaleKeys(): array
    {
        if (method_exists($this, 'getAvailableLocales')) {
            $available = $this->getAvailableLocales();

            if (is_array($available) && $available !== []) {
                return array_keys($available);
            }
        }

        $configured = config('backpack.crud.locales', []);

        if (is_array($configured) && $configured !== []) {
            return array_keys($configured);
        }

        return [app()->getLocale()];
    }

    /**
     * Resolve method that provides translation state for a custom attribute.
     */
    protected function resolveCustomTranslationStateMethod(string $attribute): ?string
    {
        $method = 'get'.Str::studly($attribute).'TranslationLocalesState';

        return method_exists($this, $method) ? $method : null;
    }

    /**
     * @return array<int, string>
     */
    protected function getAdditionalTranslatableAttributes(): array
    {
        if (! property_exists($this, 'additional_translatable')) {
            return [];
        }

        return array_values(array_filter(
            array_map(function ($attribute) {
                return is_string($attribute) ? trim($attribute) : null;
            }, (array) $this->additional_translatable),
            fn ($attribute) => is_string($attribute) && $attribute !== ''
        ));
    }

    /**
     * @param  mixed  $value
     */
    protected function calculateTranslationValueLength($value): int
    {
        if ($value instanceof \Stringable) {
            $value = (string) $value;
        }

        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? 0 : $this->measureStringLength($value);
        }

        if (is_numeric($value)) {
            return $this->measureStringLength((string) $value);
        }

        if (is_array($value)) {
            $filtered = array_filter($value, function ($item) {
                if (is_string($item)) {
                    return trim($item) !== '';
                }

                return ! empty($item);
            });

            if ($filtered === []) {
                return 0;
            }

            return $this->measureStringLength(json_encode($filtered, JSON_UNESCAPED_UNICODE));
        }

        return 0;
    }

    protected function measureStringLength(string $value): int
    {
        return function_exists('mb_strlen')
            ? mb_strlen($value)
            : strlen($value);
    }
}
