<?php

namespace Tests\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates payloads against the component schemas in docs/api/openapi.yaml.
 * OpenAPI 3.1 schemas are JSON Schema 2020-12, so a plain validator works.
 */
final class OpenApiContract
{
    private const DOCUMENT_URI = 'https://storefront.test/openapi.json';

    private static ?Validator $validator = null;

    /** @var array<string, mixed>|null */
    private static ?array $document = null;

    public static function specPath(): string
    {
        return dirname(base_path()).'/docs/api/openapi.yaml';
    }

    public static function examplesPath(string $file = ''): string
    {
        return dirname(base_path()).'/docs/api/examples'.($file === '' ? '' : '/'.$file);
    }

    /**
     * @return array<string, mixed>
     */
    public static function document(): array
    {
        return self::$document ??= Yaml::parseFile(self::specPath());
    }

    /**
     * Validate raw JSON text. Decoding to PHP arrays first would turn "{}"
     * into "[]" and hide object/array mistakes.
     *
     * @return array<string, mixed> Formatted errors; empty when valid.
     */
    public static function errors(string $json, string $schema): array
    {
        $result = self::validator()->validate(
            json_decode($json, false, 512, JSON_THROW_ON_ERROR),
            self::DOCUMENT_URI.'#/components/schemas/'.$schema,
        );

        return $result->isValid() ? [] : (new ErrorFormatter)->format($result->error());
    }

    private static function validator(): Validator
    {
        if (self::$validator === null) {
            self::$validator = new Validator;
            self::$validator->setMaxErrors(10);
            self::$validator->resolver()?->registerRaw(self::toJsonValue(self::document()), self::DOCUMENT_URI);
        }

        return self::$validator;
    }

    private static function toJsonValue(mixed $value): mixed
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }
}
