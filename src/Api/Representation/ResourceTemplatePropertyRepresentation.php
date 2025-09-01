<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Api\Representation;

use AdvancedResourceTemplate\Entity\ResourceTemplatePropertyData;

class ResourceTemplatePropertyRepresentation extends \Omeka\Api\Representation\ResourceTemplatePropertyRepresentation
{
    public function jsonSerialize(): array
    {
        $json = parent::jsonSerialize();
        $json['o:data'] = $this->data();
        return $json;
    }

    /**
     * List of labels used by this property.
     *
     * By design, labels are unique.
     *
     * The translated label of the property is added when none is set.
     */
    public function labels(): array
    {
        $result = [];
        $translator = $this->getServiceLocator()->get('MvcTranslator');
        $default = $translator->translate($this->property()->label());
        foreach ($this->data() ?: [$this] as $rtpData) {
            $result[] = $rtpData->alternateLabel() ?: $default;
        }
        return array_unique($result);
    }

    /**
     * List of comments used by this property.
     *
     * The translated comment of the property is added when none is set.
     */
    public function comments(): array
    {
        $result = [];
        $translator = $this->getServiceLocator()->get('MvcTranslator');
        $default = $translator->translate($this->property()->comment());
        foreach ($this->data() ?: [$this] as $rtpData) {
            $result[] = $rtpData->alternateComment() ?: $default;
        }
        return array_unique($result);
    }

    /**
     * Associative list of labels and comments used by this property.
     *
     * By design, labels are unique, but not comments.
     *
     * The translated label and comment of the property are added when no set.
     */
    public function labelsAndComments(): array
    {
        $result = [];
        $property = $this->property();
        $translator = $this->getServiceLocator()->get('MvcTranslator');
        $defaultLabel = $translator->translate($property->label());
        $defaultComment = $translator->translate($property->comment());
        foreach ($this->data() ?: [$this] as $rtpData) {
            $result[$rtpData->alternateLabel() ?: $defaultLabel]
                = $rtpData->alternateComment() ?: $defaultComment;
        }
        return $result;
    }

    public function alternateLabels(): array
    {
        $result = [];
        foreach ($this->data() ?: [$this] as $rtpData) {
            $result[] = $rtpData->alternateLabel();
        }
        return $result;
    }

    public function alternateComments(): array
    {
        $result = [];
        foreach ($this->data() ?: [$this] as $rtpData) {
            $result[] = $rtpData->alternateComment();
        }
        return $result;
    }

    /**
     * Associative list of labels by data type.
     *
     * There may be only one template property without label. If any, it uses
     * the key "default".
     */
    public function labelsByDataType(): array
    {
        $result = [];
        foreach ($this->data() ?: [$this] as $rtpData) {
            $rtpDataTypes = $rtpData->dataTypes();
            $alternate = $rtpData->alternateLabel();
            if (!$alternate) {
                $translator = $this->getServiceLocator()->get('MvcTranslator');
                $alternate = $translator->translate($this->property()->label());
            }
            if (count($rtpDataTypes)) {
                $result = array_merge($result, array_fill_keys($rtpDataTypes, $alternate));
            } else {
                $result['default'] = $alternate;
            }
        }
        return $result;
    }

    public function commentsByDataType(): array
    {
        $result = [];
        // There may be multiple template property without specific comment.
        $translator = $this->getServiceLocator()->get('MvcTranslator');
        $alternateComment = $translator->translate($this->property()->comment());
        foreach ($this->data() ?: [$this] as $rtpData) {
            $rtpDataTypes = $rtpData->dataTypes();
            $alternate = $rtpData->alternateComment() ?: $alternateComment;
            if (count($rtpDataTypes)) {
                $result = array_merge($result, array_fill_keys($rtpDataTypes, $alternate));
            } else {
                $result['default'] = $alternate;
            }
        }
        return $result;
    }

    /**
     * List labels and commens by data type.
     *
     * By design, labels are unique, but not comments.
     *
     * There may be only one template property without label. If any, it uses
     * the key "default".
     */
    public function labelsAndCommentsByDataType(): array
    {
        $result = [];
        $translator = $this->getServiceLocator()->get('MvcTranslator');
        foreach ($this->data() ?: [$this] as $rtpData) {
            $rtpDataTypes = $rtpData->dataTypes();
            $label = $rtpData->alternateLabel();
            if (!$label) {
                $label = $translator->translate($this->property()->label());
            }
            $comment = $rtpData->alternateComment() ?: $translator->translate($this->property()->comment());
            $labelAndComment = [
                'label' => $label,
                'comment' => $comment,
            ];
            if (count($rtpDataTypes)) {
                $result = array_merge($result, array_fill_keys($rtpDataTypes, $labelAndComment));
            } else {
                $result['default'] = $labelAndComment;
            }
        }
        return $result;
    }

    /**
     * Get all the template data associated to the current template property.
     *
     * Note: A template property may have multiple data according to data types.
     *
     * @return ResourceTemplatePropertyDataRepresentation[]|ResourceTemplatePropertyDataRepresentation|null
     */
    public function data(?int $index = null)
    {
        // Static data should store all resource templates to avoid to return
        // the same data for any template.
        static $lists = [];
        $id = $this->templateProperty->getId();
        if (!isset($lists[$id])) {
            $lists[$id] = [];
            $services = $this->getServiceLocator();
            $rtpDatas = $services->get('Omeka\EntityManager')
                ->getRepository(ResourceTemplatePropertyData::class)
                ->findBy(['resourceTemplateProperty' => $this->templateProperty]);
            foreach ($rtpDatas as $rtpData) {
                $lists[$id][] = new ResourceTemplatePropertyDataRepresentation($rtpData, $services);
            }
        }
        return $index === null
            ? $lists[$id]
            : ($lists[$id][$index] ?? null);
    }

    /**
     * Get the main data of the current template property.
     *
     * @return ResourceTemplatePropertyDataRepresentation[]|ResourceTemplatePropertyDataRepresentation
     */
    public function mainData(): ?ResourceTemplatePropertyDataRepresentation
    {
        return $this->data(0);
    }

    /**
     * Get all values from the main data of the current template property.
     */
    public function mainDataValues(): array
    {
        $dt = $this->data(0);
        return $dt ? $dt->data() : [];
    }

    /**
     * Get a value from the main data of the current template property.
     */
    public function mainDataValue(string $name)
    {
        $dt = $this->data(0);
        return $dt ? $dt->dataValue($name) : null;
    }

    /**
     * Get a value metadata from the main data of the current template property.
     */
    public function mainDataValueMetadata(string $name, ?string $metadata = null)
    {
        $dt = $this->data(0);
        return $dt ? $dt->dataValueMetadata($name, $metadata) : null;
    }
}
