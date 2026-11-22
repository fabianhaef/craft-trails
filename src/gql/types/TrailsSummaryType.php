<?php

namespace anvildev\trails\gql\types;

use craft\gql\base\ObjectType;
use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\Type;

/**
 * Craft 6's legacy GQL bridge requires every type registered through the old
 * `EVENT_REGISTER_GQL_TYPES` event to implement `CraftCms\Cms\Gql\Contracts\
 * SingularTypeInterface`, or the whole schema fails to build. That interface
 * doesn't exist at all on Craft 5, so `implements` can't reference it
 * directly in a class declaration this file defines unconditionally on both
 * platforms — PHP resolves an `implements` clause at class-load time,
 * regardless of whether the Craft-6-only bridge code that actually checks it
 * ever runs. Declaring the class twice, gated on `interface_exists()`, is
 * the standard PHP workaround: exactly one branch below ever executes, so
 * the other's reference to the interface is never resolved.
 */
if (interface_exists(\CraftCms\Cms\Gql\Contracts\SingularTypeInterface::class)) {
    class TrailsSummaryType extends ObjectType implements \CraftCms\Cms\Gql\Contracts\SingularTypeInterface
    {
        public static function getName(): string
        {
            return 'TrailsSummary';
        }

        public static function getType(): self
        {
            return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new self([
                'name' => self::getName(),
                'fields' => fn() => self::getFieldDefinitions(),
            ]));
        }

        public static function getFieldDefinitions(): array
        {
            return [
                'totalEvents' => ['type' => Type::nonNull(Type::int())],
                'uniqueUsers' => ['type' => Type::nonNull(Type::int())],
                'logins' => ['type' => Type::nonNull(Type::int())],
                'elementsCreated' => ['type' => Type::nonNull(Type::int())],
                'elementsUpdated' => ['type' => Type::nonNull(Type::int())],
                'elementsDeleted' => ['type' => Type::nonNull(Type::int())],
                'days' => ['type' => Type::nonNull(Type::int())],
            ];
        }
    }
} else {
    class TrailsSummaryType extends ObjectType
    {
        public static function getName(): string
        {
            return 'TrailsSummary';
        }

        public static function getType(): self
        {
            return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new self([
                'name' => self::getName(),
                'fields' => fn() => self::getFieldDefinitions(),
            ]));
        }

        public static function getFieldDefinitions(): array
        {
            return [
                'totalEvents' => ['type' => Type::nonNull(Type::int())],
                'uniqueUsers' => ['type' => Type::nonNull(Type::int())],
                'logins' => ['type' => Type::nonNull(Type::int())],
                'elementsCreated' => ['type' => Type::nonNull(Type::int())],
                'elementsUpdated' => ['type' => Type::nonNull(Type::int())],
                'elementsDeleted' => ['type' => Type::nonNull(Type::int())],
                'days' => ['type' => Type::nonNull(Type::int())],
            ];
        }
    }
}
