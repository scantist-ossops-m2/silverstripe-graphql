<?php

namespace SilverStripe\GraphQL\Schema;

use GraphQL\Type\Schema as GraphQLSchema;
use M1\Env\Exception\ParseException;
use SilverStripe\Config\MergeStrategy\Priority;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\Core\Path;
use SilverStripe\GraphQL\Dev\Benchmark;
use SilverStripe\GraphQL\Dev\BuildState;
use SilverStripe\GraphQL\Schema\Exception\SchemaNotFoundException;
use SilverStripe\GraphQL\Schema\Field\ModelField;
use SilverStripe\GraphQL\Schema\Interfaces\ConfigurationApplier;
use SilverStripe\GraphQL\Schema\Exception\SchemaBuilderException;
use SilverStripe\GraphQL\Schema\Field\Field;
use SilverStripe\GraphQL\Schema\Field\ModelMutation;
use SilverStripe\GraphQL\Schema\Field\ModelQuery;
use SilverStripe\GraphQL\Schema\Field\Mutation;
use SilverStripe\GraphQL\Schema\Field\Query;
use SilverStripe\GraphQL\Schema\Interfaces\ModelConfigurationProvider;
use SilverStripe\GraphQL\Schema\Interfaces\ModelOperation;
use SilverStripe\GraphQL\Schema\Interfaces\ModelTypePlugin;
use SilverStripe\GraphQL\Schema\Interfaces\MutationPlugin;
use SilverStripe\GraphQL\Schema\Interfaces\QueryPlugin;
use SilverStripe\GraphQL\Schema\Interfaces\SchemaComponent;
use SilverStripe\GraphQL\Schema\Interfaces\SchemaStorageCreator;
use SilverStripe\GraphQL\Schema\Interfaces\SchemaUpdater;
use SilverStripe\GraphQL\Schema\Interfaces\SchemaValidator;
use SilverStripe\GraphQL\Schema\Interfaces\SettingsProvider;
use SilverStripe\GraphQL\Schema\Interfaces\TypePlugin;
use SilverStripe\GraphQL\Schema\Registry\SchemaModelCreatorRegistry;
use SilverStripe\GraphQL\Schema\Type\Enum;
use SilverStripe\GraphQL\Schema\Type\InputType;
use SilverStripe\GraphQL\Schema\Type\InterfaceType;
use SilverStripe\GraphQL\Schema\Type\ModelType;
use SilverStripe\GraphQL\Schema\Type\Scalar;
use SilverStripe\GraphQL\Schema\Type\Type;
use SilverStripe\GraphQL\Schema\Type\UnionType;
use SilverStripe\GraphQL\Schema\Interfaces\SchemaStorageInterface;
use SilverStripe\ORM\ArrayLib;
use Exception;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Yaml\Yaml;
use TypeError;

/**
 * The main Schema definition. A docking station for all type, model, interface, etc., abstractions.
 * Applies plugins, validates, and persists to code.
 *
 */
class Schema implements ConfigurationApplier, SchemaValidator
{
    use Injectable;
    use Configurable;

    const SCHEMA_CONFIG = 'config';
    const SOURCE = 'src';
    const TYPES = 'types';
    const QUERIES = 'queries';
    const MUTATIONS = 'mutations';
    const MODELS = 'models';
    const INTERFACES = 'interfaces';
    const UNIONS = 'unions';
    const ENUMS = 'enums';
    const SCALARS = 'scalars';
    const QUERY_TYPE = 'Query';
    const MUTATION_TYPE = 'Mutation';
    const ALL = '*';

    /**
     * @var callable
     * @config
     */
    private static $pluraliser = [self::class, 'pluraliser'];

    /**
     * @var bool
     */
    private static $verbose = true;

    /**
     * @var string
     */
    private $schemaKey;

    /**
     * @var Type[]
     */
    private $types = [];

    /**
     * @var ModelType[]
     */
    private $models = [];

    /**
     * @var InterfaceType[]
     */
    private $interfaces = [];

    /**
     * @var UnionType[]
     */
    private $unions = [];

    /**
     * @var Enum[]
     */
    private $enums = [];

    /**
     * @var Scalar[]
     */
    private $scalars = [];

    /**
     * @var Query
     */
    private $queryType;

    /**
     * @var Mutation
     */
    private $mutationType;

    /**
     * @var SchemaStorageInterface
     */
    private $schemaStore;

    /**
     * @var SchemaContext
     */
    private $schemaContext;

    /**
     * @var bool See constructor for details about booting.
     */
    private $_booted = false;

    /**
     * In order to trigger auto-discovery of the schema configuration
     * in Silverstripe's config system, call {@link boot()} before using.
     * Since booting has a performance impact, some functionality is
     * available without booting (e.g. fetching an already persisted schema).
     *
     * @param string $schemaKey
     * @param SchemaContext|null $schemaContext
     * @throws SchemaBuilderException
     */
    public function __construct(string $schemaKey, SchemaContext $schemaContext = null)
    {
        $this->schemaKey = $schemaKey;
        $this->queryType = Type::create(self::QUERY_TYPE);
        $this->mutationType = Type::create(self::MUTATION_TYPE);

        $this->schemaContext = $schemaContext ?: SchemaContext::create();

        $store = Injector::inst()->get(SchemaStorageCreator::class)
            ->createStore($schemaKey);
        $this->setStore($store);
    }

    /**
     * Converts a configuration array to instance state.
     * This is only needed for deeper customisations,
     * since the configuration is auto-discovered and applied
     * through the {@link boot()} step.
     *
     * @param array $schemaConfig
     * @return Schema
     * @throws SchemaBuilderException
     */
    public function applyConfig(array $schemaConfig): Schema
    {
        if (empty($schemaConfig)) {
            return $this;
        }
        $types = $schemaConfig[self::TYPES] ?? [];
        $queries = $schemaConfig[self::QUERIES] ?? [];
        $mutations = $schemaConfig[self::MUTATIONS] ?? [];
        $interfaces = $schemaConfig[self::INTERFACES] ?? [];
        $unions = $schemaConfig[self::UNIONS] ?? [];
        $models = $schemaConfig[self::MODELS] ?? [];
        $enums = $schemaConfig[self::ENUMS] ?? [];
        $scalars = $schemaConfig[self::SCALARS] ?? [];
        $config = $schemaConfig[self::SCHEMA_CONFIG] ?? [];

        $this->getSchemaContext()->apply($config);

        static::assertValidConfig($types);
        foreach ($types as $typeName => $typeConfig) {
            static::assertValidName($typeName);
            $input = $typeConfig['input'] ?? false;
            unset($typeConfig['input']);
            $type = $input
                ? InputType::create($typeName, $typeConfig)
                : Type::create($typeName, $typeConfig);
            $this->addType($type);
        }

        static::assertValidConfig($queries);
        foreach ($queries as $queryName => $queryConfig) {
            $this->addQuery(Query::create($queryName, $queryConfig));
        }

        static::assertValidConfig($mutations);
        foreach ($mutations as $mutationName => $mutationConfig) {
            $this->addMutation(Mutation::create($mutationName, $mutationConfig));
        }

        static::assertValidConfig($interfaces);
        foreach ($interfaces as $interfaceName => $interfaceConfig) {
            static::assertValidName($interfaceName);
            $interface = InterfaceType::create($interfaceName, $interfaceConfig);
            $this->addInterface($interface);
        }

        static::assertValidConfig($unions);
        foreach ($unions as $unionName => $unionConfig) {
            static::assertValidName($unionName);
            $union = UnionType::create($unionName, $unionConfig);
            $this->addUnion($union);
        }

        static::assertValidConfig($models);
        foreach ($models as $modelName => $modelConfig) {
             $model = $this->createModel($modelName, $modelConfig);
             $this->addModel($model);
        }

        static::assertValidConfig($enums);
        foreach ($enums as $enumName => $enumConfig) {
            Schema::assertValidConfig($enumConfig, ['values', 'description']);
            $values = $enumConfig['values'] ?? null;
            Schema::invariant($values, 'No values passed to enum %s', $enumName);
            $description = $enumConfig['description'] ?? null;
            $enum = Enum::create($enumName, $enumConfig['values'], $description);
            $this->addEnum($enum);
        }

        static::assertValidConfig($scalars);
        foreach ($scalars as $scalarName => $scalarConfig) {
            $scalar = Scalar::create($scalarName, $scalarConfig);
            $this->addScalar($scalar);
        }

        $this->applyProceduralUpdates($config['execute'] ?? []);

        return $this;
    }

    /**
     * @return bool
     */
    public function isStored(): bool
    {
        try {
            $this->fetch();
            return true;
        } catch (SchemaNotFoundException $e) {
            return false;
        }
    }

    /**
     * @throws SchemaBuilderException
     */
    private function processModels(): void
    {
        foreach ($this->getModels() as $modelType) {
            // Apply default plugins
            $model = $modelType->getModel();
            if ($model instanceof ModelConfigurationProvider) {
                $plugins = $model->getModelConfig()->get('plugins', []);
                $modelType->setDefaultPlugins($plugins);
            }
            $modelType->buildOperations();

            foreach ($modelType->getOperations() as $operationName => $operationType) {
                Schema::invariant(
                    $operationType instanceof ModelOperation,
                    'Invalid operation defined on %s. Must implement %s',
                    $modelType->getName(),
                    ModelOperation::class
                );

                if ($operationType instanceof ModelQuery) {
                    $this->addQuery($operationType);
                } else {
                    if ($operationType instanceof ModelMutation) {
                        $this->addMutation($operationType);
                    }
                }
            }
        }
    }

    /**
     * @param array $builders
     * @throws SchemaBuilderException
     */
    private function applyProceduralUpdates(array $builders): void
    {
        foreach ($builders as $builderClass) {
            static::invariant(
                is_subclass_of($builderClass, SchemaUpdater::class),
                'The schema builder %s is not an instance of %s',
                $builderClass,
                SchemaUpdater::class
            );
            $builderClass::updateSchema($this);
        }
    }

    /**
     * @throws SchemaBuilderException
     * @throws Exception
     */
    private function applySchemaUpdates(): void
    {
        // These updates mutate the state of the schema, so each iteration
        // needs to retrieve the components dynamically.
        $this->applySchemaUpdatesFromSet($this->getTypeComponents());
        $this->applyComponentUpdatesFromSet($this->getTypeComponents());

        $this->applySchemaUpdatesFromSet($this->getFieldComponents());
        $this->applyComponentUpdatesFromSet($this->getFieldComponents());
    }

    /**
     * @param array $componentSet
     */
    private function applySchemaUpdatesFromSet(array $componentSet): void
    {
        $schemaUpdates = [];
        foreach ($componentSet as $components) {
            /* @var SchemaComponent $component */
            $schemaUpdates = array_merge($schemaUpdates, $this->collectSchemaUpdaters($components));
        }

        /* @var SchemaUpdater $builder */
        foreach ($schemaUpdates as $class) {
            $class::updateSchema($this);
        }
    }

    /**
     * @param array $componentSet
     * @throws SchemaBuilderException
     */
    private function applyComponentUpdatesFromSet(array $componentSet)
    {
        foreach ($componentSet as $name => $components) {
            /* @var SchemaComponent $component */
            foreach ($components as $component) {
                $this->applyComponentPlugins($component, $name);
            }
        }
    }

    /**
     * @return array
     */
    private function getTypeComponents(): array
    {
        return [
            'types' => $this->types,
            'models' => $this->models,
            'queries' => $this->queryType->getFields(),
            'mutations' => $this->mutationType->getFields(),
        ];
    }

    /**
     * @return array
     */
    private function getFieldComponents(): array
    {
        $allTypeFields = [];
        $allModelFields = [];
        foreach ($this->types as $type) {
            if ($type->getIsInput()) {
                continue;
            }
            $pluggedFields = array_filter($type->getFields(), function (Field $field) use ($type) {
                return !empty($field->getPlugins());
            });
            $allTypeFields = array_merge($allTypeFields, $pluggedFields);
        }
        foreach ($this->models as $model) {
            $pluggedFields = array_filter($model->getFields(), function (ModelField $field) {
                return !empty($field->getPlugins());
            });
            $allModelFields = array_merge($allModelFields, $pluggedFields);
        }

        return [
            'fields' => $allTypeFields,
            'modelFields' => $allModelFields,
        ];
    }

    /**
     * @param SchemaComponent $component
     * @param string $name
     * @throws SchemaBuilderException
     */
    private function applyComponentPlugins(SchemaComponent $component, string $name): void
    {
        foreach ($component->loadPlugins() as $data) {
            /* @var QueryPlugin|MutationPlugin|TypePlugin|ModelTypePlugin $plugin */
            list ($plugin, $config) = $data;

            // Duck programming here just because there is such an exhaustive list of possible
            // interfaces, and they can't have a common ancestor until PHP 7.4 allows it.
            // https://wiki.php.net/rfc/covariant-returns-and-contravariant-parameters
            // ideally, this should be `instanceof PluginInterface` and PluginInterface should have
            // apply(SchemaComponent)
            if (!method_exists($plugin, 'apply')) {
                continue;
            }

            try {
                $plugin->apply($component, $this, $config);
            } catch (SchemaBuilderException $e) {
                throw new SchemaBuilderException(sprintf(
                    'Failed to apply plugin %s to %s. Got error "%s"',
                    get_class($plugin),
                    $component->getName(),
                    $e->getMessage()
                ));
            } catch (TypeError $e) {
                throw new SchemaBuilderException(sprintf(
                    'Plugin %s does not apply to component "%s" (category: %s)',
                    $plugin->getIdentifier(),
                    $component->getName(),
                    $name
                ));
            }
        }
    }

    /**
     * @param array $components
     * @return array
     */
    private function collectSchemaUpdaters(array $components): array
    {
        $schemaUpdates = [];
        foreach ($components as $component) {
            foreach ($component->loadPlugins() as $data) {
                list ($plugin) = $data;
                if ($plugin instanceof SchemaUpdater) {
                    $schemaUpdates[get_class($plugin)] = get_class($plugin);
                }
            }
        }

        return $schemaUpdates;
    }

    /**
     * @throws SchemaBuilderException
     */
    public function save(): void
    {
        $this->processModels();
        $this->applySchemaUpdates();

        foreach ($this->models as $modelType) {
            $this->addType($modelType);
        }


        $this->types[self::QUERY_TYPE] = $this->queryType;
        if ($this->mutationType->exists()) {
            $this->types[self::MUTATION_TYPE] = $this->mutationType;
        }

        $this->validate();
        $this->getStore()->persistSchema($this);
    }

    /**
     * @return array
     */
    public function mapTypeNames(): array
    {
        $typeMapping = [];
        foreach ($this->getModels() as $modelType) {
            $typeMapping[$modelType->getModel()->getSourceClass()] = $modelType->getName();
        }

        return $typeMapping;
    }

    /**
     * @param string $class
     * @return string|null
     * @throws SchemaBuilderException
     */
    public function getTypeNameForClass(string $class): ?string
    {
        $mapping = $this->getStore()->getTypeMapping();
        $typeName = $mapping[$class] ?? null;
        if ($typeName) {
            return $typeName;
        }

        $model = $this->getSchemaContext()->createModel($class);
        if ($model) {
            return $model->getTypeName();
        }

        return null;
    }

    /**
     * @return GraphQLSchema
     * @throws SchemaNotFoundException
     */
    public function fetch(): GraphQLSchema
    {
        return $this->getStore()->getSchema();
    }

    /**
     * @return bool
     */
    public function exists(): bool
    {
        return !empty($this->types) && $this->queryType->exists();
    }

    /**
     * @throws SchemaBuilderException
     */
    public function validate(): void
    {
        $allNames = array_merge(
            array_keys($this->types),
            array_keys($this->enums),
            array_keys($this->interfaces),
            array_keys($this->unions),
            array_keys($this->scalars)
        );
        $dupes = [];
        foreach (array_count_values($allNames) as $val => $count) {
            if ($count > 1) {
                $dupes[] = $val;
            }
        }

        static::invariant(
            empty($dupes),
            'Your schema has multiple types with the same name. See %s',
            implode(', ', $dupes)
        );

        static::invariant(
            $this->exists(),
            'Your schema must contain at least one type and at least one query'
        );

        $validators = array_merge(
            $this->types,
            $this->queryType->getFields(),
            $this->mutationType->getFields(),
            $this->enums,
            $this->interfaces,
            $this->unions,
            $this->scalars
        );
        /* @var SchemaValidator $validator */
        foreach ($validators as $validator) {
            $validator->validate();
        }
    }

    /**
     * @return string
     */
    public function getSchemaKey(): string
    {
        return $this->schemaKey;
    }

    /**
     * @return SchemaContext
     */
    public function getSchemaContext(): SchemaContext
    {
        return $this->schemaContext;
    }

    /**
     * @param Field $query
     * @return $this
     */
    public function addQuery(Field $query): self
    {
        $this->queryType->addField($query->getName(), $query);

        return $this;
    }

    /**
     * @param Field $mutation
     * @return $this
     */
    public function addMutation(Field $mutation): self
    {
        $this->mutationType->addField($mutation->getName(), $mutation);

        return $this;
    }

    /**
     * @param Type $type
     * @param callable|null $callback
     * @return Schema
     * @throws SchemaBuilderException
     */
    public function addType(Type $type, ?callable $callback = null): Schema
    {
        $existing = $this->types[$type->getName()] ?? null;
        $typeObj = $existing ? $existing->mergeWith($type) : $type;
        $this->types[$type->getName()] = $typeObj;
        if ($callback) {
            $callback($typeObj);
        }
        return $this;
    }

    /**
     * @param string $name
     * @return Type|null
     */
    public function getType(string $name): ?Type
    {
        return $this->types[$name] ?? null;
    }

    /**
     * @param string $name
     * @return Type
     * @throws SchemaBuilderException
     */
    public function findOrMakeType(string $name): Type
    {
        $existing = $this->getType($name);
        if ($existing) {
            return $existing;
        }
        $this->addType(Type::create($name));

        return $this->getType($name);
    }

    /**
     * @return Type[]
     */
    public function getTypes(): array
    {
        return $this->types;
    }

    /**
     * @param Enum $enum
     * @return $this
     */
    public function addEnum(Enum $enum): self
    {
        $this->enums[$enum->getName()] = $enum;

        return $this;
    }

    /**
     * @return Enum[]
     */
    public function getEnums(): array
    {
        return $this->enums;
    }

    /**
     * @param $name
     * @return Enum|null
     */
    public function getEnum(string $name): ?Enum
    {
        return $this->enums[$name] ?? null;
    }

    /**
     * @return array
     */
    public function getScalars(): array
    {
        return $this->scalars;
    }

    /**
     * @param string $name
     * @return Scalar|null
     */
    public function getScalar(string $name): ?Scalar
    {
        return $this->scalars[$name] ?? null;
    }

    /**
     * @param Scalar $scalar
     * @return $this
     */
    public function addScalar(Scalar $scalar): self
    {
        $this->scalars[$scalar->getName()] = $scalar;

        return $this;
    }

    /**
     * @param ModelType $modelType
     * @param callable|null $callback
     * @return Schema
     * @throws SchemaBuilderException
     */
    public function addModel(ModelType $modelType, ?callable $callback = null): Schema
    {
        $existing = $this->models[$modelType->getName()] ?? null;
        $typeObj = $existing
            ? $existing->mergeWith($modelType)
            : $modelType;
        $this->models[$modelType->getName()] = $typeObj;
        foreach ($modelType->getExtraTypes() as $type) {
            if ($type instanceof ModelType) {
                $this->addModel($type);
            } else {
                $this->addType($type);
            }
        }
        if ($callback) {
            $callback($typeObj);
        }

        return $this;
    }

    /**
     * @param string $name
     * @return ModelType|null
     */
    public function getModel(string $name): ?ModelType
    {
        return $this->models[$name] ?? null;
    }

    /**
     * @param string $class
     * @return $this
     * @throws SchemaBuilderException
     */
    public function addModelbyClassName(string $class): self
    {
        $model = $this->createModel($class);
        Schema::invariant(
            $model,
            'Could not add class %s to schema. No model exists.'
        );

        return $this->addModel($model);
    }

    /**
     * @param string $class
     * @return ModelType|null
     */
    public function getModelByClassName(string $class): ?ModelType
    {
        foreach ($this->getModels() as $modelType) {
            if ($modelType->getModel()->getSourceClass() === $class) {
                return $modelType;
            }
        }

        return null;
    }

    /**
     * @param string $class
     * @param array $config
     * @return ModelType|null
     * @throws SchemaBuilderException
     */
    public function createModel(string $class, array $config = []): ?ModelType
    {
        $model = $this->getSchemaContext()->createModel($class);
        if (!$model) {
            return null;
        }

        return ModelType::create($model, $config);
    }

    /**
     * @return ModelType[]
     */
    public function getModels(): array
    {
        return $this->models;
    }

    /**
     * @param string $class
     * @return ModelType
     * @throws SchemaBuilderException
     */
    public function findOrMakeModel(string $class): ModelType
    {
        $newModel = $this->createModel($class);
        $name = $newModel->getName();
        $existing = $this->getModel($name);
        if ($existing) {
            return $existing;
        }
        $this->addModel($newModel);

        return $this->getModel($name);
    }

    /**
     * @param InterfaceType $type
     * @param callable|null $callback
     * @return $this
     * @throws SchemaBuilderException
     */
    public function addInterface(InterfaceType $type, ?callable $callback = null): self
    {
        $existing = $this->interfaces[$type->getName()] ?? null;
        $typeObj = $existing ? $existing->mergeWith($type) : $type;
        $this->interfaces[$type->getName()] = $typeObj;
        if ($callback) {
            $callback($typeObj);
        }
        return $this;
    }

    /**
     * @param string $name
     * @return InterfaceType|null
     */
    public function getInterface(string $name): ?InterfaceType
    {
        return $this->interfaces[$name] ?? null;
    }

    /**
     * @return InterfaceType[]
     */
    public function getInterfaces(): array
    {
        return $this->interfaces;
    }

    /**
     * @param UnionType $union
     * @param callable|null $callback
     * @return $this
     */
    public function addUnion(UnionType $union, ?callable $callback = null): self
    {
        $existing = $this->unions[$union->getName()] ?? null;
        $typeObj = $existing ? $existing->mergeWith($union) : $union;
        $this->unions[$union->getName()] = $typeObj;
        if ($callback) {
            $callback($typeObj);
        }
        return $this;
    }

    /**
     * @param string $name
     * @return UnionType|null
     */
    public function getUnion(string $name): ?UnionType
    {
        return $this->unions[$name] ?? null;
    }

    /**
     * @return array
     */
    public function getUnions(): array
    {
        return $this->unions;
    }

    /**
     * @return array
     */
    public static function getInternalTypes(): array
    {
        return ['String', 'Boolean', 'Int', 'Float', 'ID'];
    }

    /**
     * @param string $type
     * @return bool
     */
    public static function isInternalType(string $type): bool
    {
        return in_array($type, static::getInternalTypes());
    }

    /**
     * Pluralise a name
     *
     * @param string $typeName
     * @return string
     * @throws SchemaBuilderException
     */
    public function pluralise($typeName): string
    {
        $callable = $this->getSchemaContext()->getPluraliser();
        Schema::invariant(
            is_callable($callable),
            'Schema does not have a valid callable "pluraliser" property set in its config'
        );

        return call_user_func_array($callable, [$typeName]);
    }

    /**
     * @param array $config
     * @param array $allowedKeys
     * @param array $requiredKeys
     * @throws SchemaBuilderException
     */
    public static function assertValidConfig(array $config, $allowedKeys = [], $requiredKeys = []): void
    {
        static::invariant(
            empty($config) || ArrayLib::is_associative($config),
            '%s configurations must be key value pairs of names to configurations.
            Did you include an indexed array in your config?',
            static::class
        );

        if (!empty($allowedKeys)) {
            $invalidKeys = array_diff(array_keys($config), $allowedKeys);
            static::invariant(
                empty($invalidKeys),
                'Config contains invalid keys: %s',
                implode(',', $invalidKeys)
            );
        }

        if (!empty($requiredKeys)) {
            $missingKeys = array_diff($requiredKeys, array_keys($config));
            static::invariant(
                empty($missingKeys),
                'Config is missing required keys: %s',
                implode(',', $missingKeys)
            );
        }
    }

    /**
     * @param $name
     * @throws SchemaBuilderException
     */
    public static function assertValidName($name): void
    {
        static::invariant(
            preg_match(' /[_A-Za-z][_0-9A-Za-z]*/', $name),
            'Invalid name: %s. Names must only use underscores and alphanumeric characters, and cannot
          begin with a number.',
            $name
        );
    }

    /**
     * @param $test
     * @param string $message
     * @param mixed ...$params
     * @throws SchemaBuilderException
     */
    public static function invariant($test, $message = '', ...$params): void
    {
        if (!$test) {
            $message = sprintf($message, ...$params);
            throw new SchemaBuilderException($message);
        }
    }


    /**
     * @return SchemaStorageInterface
     */
    public function getStore(): SchemaStorageInterface
    {
        return $this->schemaStore;
    }

    /**
     * @param SchemaStorageInterface $store
     * @return $this
     */
    public function setStore(SchemaStorageInterface $store): self
    {
        $this->schemaStore = $store;

        return $this;
    }

    /**
     * Turns off messaging
     */
    public static function quiet(): void
    {
        self::$verbose = false;
    }

    /**
     * Used for logging in tasks
     * @param string $message
     */
    public static function message(string $message): void
    {
        if (!self::$verbose) {
            return;
        }
        if (Director::is_cli()) {
            fwrite(STDOUT, $message . PHP_EOL);
        } else {
            echo $message . "<br>";
        }
    }
}