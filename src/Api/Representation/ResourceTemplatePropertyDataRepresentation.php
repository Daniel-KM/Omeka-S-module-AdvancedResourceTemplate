<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Api\Representation;

use AdvancedResourceTemplate\Entity\ResourceTemplatePropertyData;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Representation\AbstractRepresentation;
use Omeka\Api\Representation\PropertyRepresentation;

/**
 * In order to be compatible with core, it copies original resource template
 * property too.
 */
class ResourceTemplatePropertyDataRepresentation extends AbstractRepresentation
{
    /**
     * @var ResourceTemplatePropertyData
     */
    protected $resource;

    public function __construct(ResourceTemplatePropertyData $rtpData, ServiceLocatorInterface $serviceLocator)
    {
        $this->setServiceLocator($serviceLocator);
        $this->resource = $rtpData;
    }

    public function jsonSerialize(): array
    {
        // This is not a json-ld resource, so no need to encapsulate it.
        return $this->data();
    }

    /**
     * Get all resource template data or settings.
     */
    public function data(): array
    {
        return $this->resource->getData();
    }

    /**
     * Get a specific resource template data (setting).
     *
     * @return mixed
     */
    public function dataValue(string $name)
    {
        return $this->resource->getDataValue($name);
    }

    /**
     * Get a value metadata from the data of the current template property.
     */
    public function dataValueMetadata(string $name, ?string $metadata = null)
    {
        $dt = $this->resource->getData();
        if (!isset($dt[$name])) {
            return null;
        }
        $meta = $dt[$name];
        switch ($metadata) {
            case 'params':
            case 'params_raw':
                return $meta;
            case 'params_json':
            case 'params_json_array':
                return @json_decode($meta, true) ?: [];
            case 'params_json_object':
                return @json_decode($meta) ?: (object) [];
            case 'params_key_value_array':
                $params = array_map('trim', explode("\n", trim($meta)));
                $list = [];
                foreach ($params as $keyValue) {
                    $list[] = array_map('trim', explode('=', $keyValue, 2));
                }
                return $list;
            case 'params_key_value':
            default:
                $params = array_filter(array_map('trim', explode("\n", trim($meta))), 'strlen');
                $list = [];
                foreach ($params as $keyValue) {
                    [$key, $value] = strpos($keyValue, '=') === false
                        ? [$keyValue, null]
                        : array_map('trim', explode('=', $keyValue, 2));
                    if ($key !== '') {
                        $list[$key] = $value;
                    }
                }
                if ($metadata === 'params_key_value') {
                    return $list;
                }
                return $list[$metadata] ?? null;
        }
    }

    public function template(): ResourceTemplateRepresentation
    {
        return $this->getAdapter('resource_templates')
            ->getRepresentation($this->resource->getResourceTemplate());
    }

    public function resourceTemplateProperty(): ResourceTemplatePropertyRepresentation
    {
        $resTemProp = $this->resource->getResourceTemplateProperty();
        return new ResourceTemplatePropertyRepresentation($resTemProp, $this->getServiceLocator());
    }

    public function property(): PropertyRepresentation
    {
        // Don't use the representation, the vocabulary may not be loaded
        // (extra lazy), but the property is normally cached by doctrine.
        // return $this->resourceTemplateProperty()->property();
        $property = $this->resource->getResourceTemplateProperty()->getProperty();
        return $this->getAdapter('properties')->getRepresentation($property);
    }

    public function alternateLabel(): ?string
    {
        return $this->dataValue('o:alternate_label');
    }

    public function alternateComment(): ?string
    {
        return $this->dataValue('o:alternate_comment');
    }

    public function dataType(): ?string
    {
        $datatypes = $this->dataValue('o:data_type');
        return $datatypes ? reset($datatypes) : null;
    }

    public function dataTypes(): array
    {
        return $this->dataValue('o:data_type') ?: [];
    }

    /**
     * @return array List of data type names and default labels.
     */
    public function dataTypeLabels(): array
    {
        $result = [];
        $dataTypeManager = $this->getServiceLocator()->get('Omeka\DataTypeManager');
        foreach ($this->dataTypes() as $dataType) {
            if (!$dataTypeManager->has($dataType)) {
                $this->getServiceLocator()->get('Omeka\Logger')->err(
                    'The data type "{data_type}" is not available.', // @translate
                    ['data_type' => $dataType]
                );
                continue;
            }
            $result[] = [
                'name' => $dataType,
                'label' => $dataTypeManager->get($dataType)->getLabel(),
            ];
        }
        return $result;
    }

    public function isRequired(): bool
    {
        return (bool) $this->dataValue('o:is_required');
    }

    public function isPrivate(): bool
    {
        return (bool) $this->dataValue('o:is_private');
    }

    public function defaultLang(): ?string
    {
        return $this->dataValue('o:default_lang');
    }
}
