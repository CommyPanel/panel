<?php

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads docs/openapi.yaml and checks a JSON value against one of its schemas. It understands the
 * part of OpenAPI the spec uses (types, nullable, required, properties, enum, items, allOf,
 * $ref) and is strict: a key the schema does not declare is a failure, so the spec cannot fall
 * behind what the API really says (or quietly leak a field).
 */
final class OpenApiSpec
{
    /** @var array<string, mixed> */
    public readonly array $doc;

    public function __construct(?string $file = null)
    {
        $this->doc = Yaml::parseFile($file ?? dirname(__DIR__, 2).'/docs/openapi.yaml');
    }

    /**
     * Every operation as "METHOD /path" => the operation.
     *
     * @return array<string, array<string, mixed>>
     */
    public function operations(): array
    {
        $out = [];

        foreach ($this->doc['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $out[strtoupper($method).' '.$path] = $operation;
                }
            }
        }

        return $out;
    }

    /** The schema of the JSON answer an operation gives for a status, or null if none is declared. */
    public function responseSchema(string $operationKey, int $status): ?array
    {
        $response = $this->operations()[$operationKey]['responses'][(string) $status] ?? null;

        if ($response === null) {
            return null;
        }

        $response = $this->resolve($response);

        return $response['content']['application/json']['schema'] ?? null;
    }

    /**
     * @return list<string> what is wrong; empty when the value fits
     */
    public function check(mixed $value, array $schema, string $at = '$'): array
    {
        $schema = $this->resolve($schema);
        $errors = [];

        if (isset($schema['allOf'])) {
            $declared = [];

            foreach ($schema['allOf'] as $part) {
                $part = $this->resolve($part);
                $declared = [...$declared, ...array_keys($part['properties'] ?? [])];
            }

            // Each part checks its own keys; keys of the other parts are allowed.
            foreach ($schema['allOf'] as $part) {
                $part = $this->resolve($part);
                $part['x-known'] = $declared;
                $errors = [...$errors, ...$this->check($value, $part, $at)];
            }

            return $errors;
        }

        if ($value === null) {
            return ($schema['nullable'] ?? false) ? [] : ["{$at} is null but the schema does not allow it"];
        }

        $type = $schema['type'] ?? (isset($schema['properties']) ? 'object' : null);

        $ok = match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            default => true,
        };

        if (! $ok) {
            return ["{$at} should be {$type}, got ".get_debug_type($value)];
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$at} is ".json_encode($value).', not one of '.json_encode($schema['enum']);
        }

        if ($type === 'array' && isset($schema['items'])) {
            foreach ($value as $i => $item) {
                $errors = [...$errors, ...$this->check($item, $schema['items'], "{$at}[{$i}]")];
            }
        }

        if ($type === 'object') {
            $properties = $schema['properties'] ?? [];

            foreach ($schema['required'] ?? [] as $key) {
                if (! array_key_exists($key, $value)) {
                    $errors[] = "{$at}.{$key} is required but missing";
                }
            }

            $known = [...array_keys($properties), ...($schema['x-known'] ?? [])];

            foreach ($value as $key => $item) {
                if (isset($properties[$key])) {
                    $errors = [...$errors, ...$this->check($item, $properties[$key], "{$at}.{$key}")];
                } elseif (isset($schema['additionalProperties']) && is_array($schema['additionalProperties'])) {
                    $errors = [...$errors, ...$this->check($item, $schema['additionalProperties'], "{$at}.{$key}")];
                } elseif (! in_array($key, $known, true) && ($properties !== [] || ($schema['x-known'] ?? []) !== [])) {
                    $errors[] = "{$at}.{$key} is not in the schema";
                }
            }
        }

        return $errors;
    }

    /** Follows a `$ref` (only to this document). */
    public function resolve(array $node): array
    {
        while (isset($node['$ref'])) {
            $ref = (string) $node['$ref'];

            if (! str_starts_with($ref, '#/')) {
                throw new RuntimeException("Only local references are supported: {$ref}");
            }

            $target = $this->doc;

            foreach (explode('/', substr($ref, 2)) as $part) {
                $target = $target[$part] ?? throw new RuntimeException("Broken reference: {$ref}");
            }

            $node = $target;
        }

        return $node;
    }
}
