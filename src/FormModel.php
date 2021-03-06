<?php

declare(strict_types=1);

namespace Yiisoft\Form;

use Closure;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionNamedType;
use Yiisoft\Form\HtmlOptions\HtmlOptionsProvider;
use Yiisoft\Strings\Inflector;
use Yiisoft\Strings\StringHelper;
use Yiisoft\Validator\PostValidationHookInterface;
use Yiisoft\Validator\ResultSet;
use Yiisoft\Validator\Rule\Required;
use Yiisoft\Validator\RulesProviderInterface;
use function array_key_exists;
use function array_merge;
use function explode;
use function get_object_vars;
use function is_subclass_of;
use function reset;
use function sprintf;
use function strpos;

/**
 * Form model represents an HTML form: its data, validation and presentation.
 */
abstract class FormModel implements FormModelInterface, PostValidationHookInterface, RulesProviderInterface
{
    private array $attributes;
    private array $attributesLabels;
    private array $attributesErrors = [];
    private ?Inflector $inflector = null;
    private bool $validated = false;

    public function __construct()
    {
        $this->attributes = $this->collectAttributes();
        $this->attributesLabels = $this->getAttributeLabels();
    }

    public function isAttributeRequired(string $attribute): bool
    {
        $validators = $this->getRules()[$attribute] ?? [];

        foreach ($validators as $validator) {
            if ($validator instanceof Required) {
                return true;
            }
            if ($validator instanceof HtmlOptionsProvider && (bool)($validator->getHtmlOptions()['required'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    public function getAttributeValue(string $attribute)
    {
        return $this->readProperty($attribute);
    }

    public function getAttributeLabels(): array
    {
        return [];
    }

    public function getAttributeHint(string $attribute): string
    {
        [$attribute, $nested] = $this->getNestedAttribute($attribute);
        if ($nested !== null) {
            return $this->readProperty($attribute)->getAttributeHint($nested);
        }

        $hints = $this->getAttributeHints();

        return $hints[$attribute] ?? '';
    }

    public function getAttributeHints(): array
    {
        return [];
    }

    public function getAttributeLabel(string $attribute): string
    {
        if (array_key_exists($attribute, $this->attributesLabels)) {
            return $this->attributesLabels[$attribute];
        }

        [$attribute, $nested] = $this->getNestedAttribute($attribute);

        return $nested !== null
            ? $this->readProperty($attribute)->getAttributeLabel($nested)
            : $this->generateAttributeLabel($attribute);
    }

    /**
     * @return string Returns classname without a namespace part or empty string when class is anonymous
     */
    public function getFormName(): string
    {
        if (strpos(static::class, '@anonymous') !== false) {
            return '';
        }

        $className = strrchr(static::class, '\\');
        if ($className === false) {
            return static::class;
        }

        return substr($className, 1);
    }

    public function hasAttribute(string $attribute): bool
    {
        return array_key_exists($attribute, $this->attributes);
    }

    public function getError(string $attribute): array
    {
        return $this->attributesErrors[$attribute] ?? [];
    }

    public function getErrors(): array
    {
        return $this->attributesErrors;
    }

    public function getErrorSummary(bool $showAllErrors): array
    {
        $lines = [];
        $errors = $showAllErrors ? $this->getErrors() : [$this->getFirstErrors()];

        foreach ($errors as $error) {
            $lines = array_merge($lines, $error);
        }

        return $lines;
    }

    public function getFirstError(string $attribute): string
    {
        if (empty($this->attributesErrors[$attribute])) {
            return '';
        }

        return reset($this->attributesErrors[$attribute]);
    }

    public function getFirstErrors(): array
    {
        if (empty($this->attributesErrors)) {
            return [];
        }

        $errors = [];

        foreach ($this->attributesErrors as $name => $es) {
            if (!empty($es)) {
                $errors[$name] = reset($es);
            }
        }

        return $errors;
    }

    public function hasErrors(?string $attribute = null): bool
    {
        return $attribute === null ? !empty($this->attributesErrors) : isset($this->attributesErrors[$attribute]);
    }

    /**
     * @param array $data
     * @param string|null $formName
     *
     * @return bool
     */
    public function load(array $data, ?string $formName = null): bool
    {
        $scope = $formName ?? $this->getFormName();

        /**
         * @psalm-var array<string,mixed>
         */
        $values = [];

        if ($scope === '' && !empty($data)) {
            $values = $data;
        } elseif (isset($data[$scope])) {
            $values = $data[$scope];
        }

        foreach ($values as $name => $value) {
            $this->setAttribute($name, $value);
        }

        return $values !== [];
    }

    public function setAttribute(string $name, $value): void
    {
        [$realName] = $this->getNestedAttribute($name);
        if (isset($this->attributes[$realName])) {
            switch ($this->attributes[$realName]) {
                case 'bool':
                    $this->writeProperty($name, (bool) $value);
                    break;
                case 'float':
                    $this->writeProperty($name, (float) $value);
                    break;
                case 'int':
                    $this->writeProperty($name, (int) $value);
                    break;
                case 'string':
                    $this->writeProperty($name, (string) $value);
                    break;
                default:
                    $this->writeProperty($name, $value);
                    break;
            }
        }
    }

    public function processValidationResult(ResultSet $resultSet): void
    {
        $this->clearErrors();
        foreach ($resultSet as $attribute => $result) {
            if ($result->isValid() === false) {
                $this->addErrors([$attribute => $result->getErrors()]);
            }
        }
        $this->validated = true;
    }

    public function addError(string $attribute, string $error): void
    {
        $this->attributesErrors[$attribute][] = $error;
    }

    public function getRules(): array
    {
        return [];
    }

    /**
     * @param string[][] $items
     */
    private function addErrors(array $items): void
    {
        foreach ($items as $attribute => $errors) {
            foreach ($errors as $error) {
                $this->attributesErrors[$attribute][] = $error;
            }
        }
    }

    /**
     * Returns the list of attribute types indexed by attribute names.
     *
     * By default, this method returns all non-static properties of the class.
     *
     * @throws \ReflectionException
     *
     * @return array list of attribute types indexed by attribute names.
     */
    private function collectAttributes(): array
    {
        $class = new ReflectionClass($this);
        $attributes = [];

        foreach ($class->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            /** @var ReflectionNamedType $type */
            $type = $property->getType();
            if ($type === null) {
                throw new InvalidArgumentException(sprintf(
                    'You must specify the type hint for "%s" property in "%s" class.',
                    $property->getName(),
                    $property->getDeclaringClass()->getName(),
                ));
            }

            $attributes[$property->getName()] = $type->getName();
        }

        return $attributes;
    }

    private function clearErrors(?string $attribute = null): void
    {
        if ($attribute === null) {
            $this->attributesErrors = [];
        } else {
            unset($this->attributesErrors[$attribute]);
        }

        $this->validated = false;
    }

    private function getInflector(): Inflector
    {
        if ($this->inflector === null) {
            $this->inflector = new Inflector();
        }
        return $this->inflector;
    }

    /**
     * Generates a user friendly attribute label based on the give attribute name.
     *
     * This is done by replacing underscores, dashes and dots with blanks and changing the first letter of each word to
     * upper case.
     *
     * For example, 'department_name' or 'DepartmentName' will generate 'Department Name'.
     *
     * @param string $name the column name.
     *
     * @return string the attribute label.
     */
    private function generateAttributeLabel(string $name): string
    {
        return StringHelper::uppercaseFirstCharacterInEachWord(
            $this->getInflector()->toWords($name)
        );
    }

    private function readProperty(string $attribute)
    {
        $class = static::class;

        [$attribute, $nested] = $this->getNestedAttribute($attribute);

        if (!property_exists($class, $attribute)) {
            throw new InvalidArgumentException("Undefined property: \"$class::$attribute\".");
        }

        if ($this->isPublicAttribute($attribute)) {
            return $nested === null ? $this->$attribute : $this->$attribute->getAttributeValue($nested);
        }

        $getter = fn (FormModel $class, $attribute) => $nested === null
            ? $class->$attribute
            : $class->$attribute->getAttributeValue($nested);
        $getter = Closure::bind($getter, null, $this);

        /**
         * @psalm-var Closure $getter
         */
        return $getter($this, $attribute);
    }

    private function writeProperty(string $attribute, $value): void
    {
        [$attribute, $nested] = $this->getNestedAttribute($attribute);
        if ($this->isPublicAttribute($attribute)) {
            if ($nested === null) {
                $this->$attribute = $value;
            } else {
                $this->$attribute->setAttribute($attribute, $value);
            }
        } else {
            $setter = fn (FormModel $class, $attribute, $value) => $nested === null
                ? $class->$attribute = $value
                : $class->$attribute->setAttribute($nested, $value);
            $setter = Closure::bind($setter, null, $this);

            /**
             * @psalm-var Closure $setter
             */
            $setter($this, $attribute, $value);
        }
    }

    private function isPublicAttribute(string $attribute): bool
    {
        return array_key_exists($attribute, get_object_vars($this));
    }

    private function getNestedAttribute(string $attribute): array
    {
        if (strpos($attribute, '.') === false) {
            return [$attribute, null];
        }

        [$attribute, $nested] = explode('.', $attribute, 2);

        if (!is_subclass_of($this->attributes[$attribute], self::class)) {
            throw new InvalidArgumentException('Nested attribute can only be of ' . self::class . ' type.');
        }

        return [$attribute, $nested];
    }

    public function isValidated(): bool
    {
        return $this->validated;
    }
}
