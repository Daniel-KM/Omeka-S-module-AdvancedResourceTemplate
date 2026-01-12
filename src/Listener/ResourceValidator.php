<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Listener;

use AdvancedResourceTemplate\Stdlib\ArtTrait;
use Common\Stdlib\PsrMessage;
use Doctrine\DBAL\Connection;
use Laminas\Log\Logger;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Mvc\Controller\Plugin\Messenger;
use Omeka\Stdlib\ErrorStore;

/**
 * Validates resources against template constraints.
 */
class ResourceValidator
{
    use ArtTrait;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \Omeka\Mvc\Controller\Plugin\Messenger|null
     */
    protected $messenger;

    public function __construct(
        Connection $connection,
        Logger $logger,
        ?Messenger $messenger = null
    ) {
        $this->connection = $connection;
        $this->logger = $logger;
        $this->messenger = $messenger;
    }

    /**
     * Set messenger for direct user feedback.
     */
    public function setMessenger(?Messenger $messenger): self
    {
        $this->messenger = $messenger;
        return $this;
    }

    /**
     * Validate resource properties against template constraints.
     */
    public function validateProperties(
        AbstractResourceEntityRepresentation $resource,
        ErrorStore $errorStore,
        bool $directMessage = false
    ): void {
        $template = $resource->resourceTemplate();
        if (!$template) {
            return;
        }

        $resourceId = (int) $resource->id();

        foreach ($template->resourceTemplateProperties() as $templateProperty) {
            foreach ($templateProperty->data() as $rtpData) {
                $property = $templateProperty->property();
                $propertyId = $property->id();
                $propertyTerm = $property->term();

                $this->validateInputControl($resource, $rtpData, $propertyTerm, $template->label(), $errorStore, $directMessage);
                $this->validateLength($resource, $rtpData, $propertyTerm, $errorStore, $directMessage);
                $this->validateValueCount($resource, $rtpData, $propertyTerm, $errorStore, $directMessage);
                $this->validateUniqueness($resource, $rtpData, $propertyId, $propertyTerm, $resourceId, $errorStore, $directMessage);
            }
        }
    }

    /**
     * Validate input control pattern.
     */
    protected function validateInputControl(
        AbstractResourceEntityRepresentation $resource,
        $rtpData,
        string $propertyTerm,
        string $templateLabel,
        ErrorStore $errorStore,
        bool $directMessage
    ): void {
        $inputControl = (string) $rtpData->dataValue('input_control');
        if (!strlen($inputControl)) {
            return;
        }

        // Find a valid regex anchor.
        $anchors = ['/', '#', '~', '%', '`', ';', '§', 'µ'];
        $regex = null;
        foreach ($anchors as $anchor) {
            if (mb_strpos($inputControl, $anchor) === false) {
                $regex = $anchor . '^(?:' . $inputControl . ')$' . $anchor . 'u';
                if (@preg_match($regex, '') === false) {
                    $regex = null;
                }
                break;
            }
        }

        if (!$regex) {
            $message = new PsrMessage(
                'The html input pattern "{pattern}" for template {template} cannot be processed.', // @translate
                ['pattern' => $inputControl, 'template' => $templateLabel]
            );
            $this->logger->warn((string) $message);
            return;
        }

        foreach ($resource->value($propertyTerm, ['all' => true, 'type' => 'literal']) as $value) {
            $val = $value->value();
            if (!preg_match($regex, $val)) {
                $message = new PsrMessage(
                    'The value "{value}" for term {property} does not follow the input pattern "{pattern}".', // @translate
                    ['value' => $val, 'property' => $propertyTerm, 'pattern' => $inputControl]
                );
                $errorStore->addError($propertyTerm, $message);
                if ($directMessage && $this->messenger) {
                    $this->messenger->addError($message);
                }
            }
        }
    }

    /**
     * Validate min/max length.
     */
    protected function validateLength(
        AbstractResourceEntityRepresentation $resource,
        $rtpData,
        string $propertyTerm,
        ErrorStore $errorStore,
        bool $directMessage
    ): void {
        $minLength = (int) $rtpData->dataValue('min_length');
        $maxLength = (int) $rtpData->dataValue('max_length');

        if (!$minLength && !$maxLength) {
            return;
        }

        foreach ($resource->value($propertyTerm, ['all' => true, 'type' => 'literal']) as $value) {
            $length = mb_strlen($value->value());

            if ($minLength && $length < $minLength) {
                $message = new PsrMessage(
                    'The value for term {property} is shorter ({length} characters) than the minimal size ({number} characters).', // @translate
                    ['property' => $propertyTerm, 'length' => $length, 'number' => $minLength]
                );
                $errorStore->addError($propertyTerm, $message);
                if ($directMessage && $this->messenger) {
                    $this->messenger->addError($message);
                }
            }

            if ($maxLength && $length > $maxLength) {
                $message = new PsrMessage(
                    'The value for term {property} is longer ({length} characters) than the maximal size ({number} characters).', // @translate
                    ['property' => $propertyTerm, 'length' => $length, 'number' => $maxLength]
                );
                $errorStore->addError($propertyTerm, $message);
                if ($directMessage && $this->messenger) {
                    $this->messenger->addError($message);
                }
            }
        }
    }

    /**
     * Validate min/max value count.
     */
    protected function validateValueCount(
        AbstractResourceEntityRepresentation $resource,
        $rtpData,
        string $propertyTerm,
        ErrorStore $errorStore,
        bool $directMessage
    ): void {
        $minValues = (int) $rtpData->dataValue('min_values');
        $maxValues = (int) $rtpData->dataValue('max_values');

        // TODO Fix api($form) to manage the minimum number of values in admin resource form.
        if ($directMessage || (!$minValues && !$maxValues)) {
            return;
        }

        $isRequired = $rtpData->isRequired();
        $values = $resource->value($propertyTerm, ['all' => true, 'type' => $rtpData->dataTypes()]);
        $countValues = count($values);

        if ($isRequired && $minValues && $countValues < $minValues) {
            $message = new PsrMessage(
                'The number of values ({count}) for term {property} is lower than the minimal number of {number}.', // @translate
                ['count' => $countValues, 'property' => $propertyTerm, 'number' => $minValues]
            );
            $errorStore->addError($propertyTerm, $message);
            if ($directMessage && $this->messenger) {
                $this->messenger->addError($message);
            }
        }

        if ($maxValues && $countValues > $maxValues) {
            $message = new PsrMessage(
                'The number of values ({count}) for term {property} is greater than the maximal number of {number}.', // @translate
                ['count' => $countValues, 'property' => $propertyTerm, 'number' => $maxValues]
            );
            $errorStore->addError($propertyTerm, $message);
            if ($directMessage && $this->messenger) {
                $this->messenger->addError($message);
            }
        }
    }

    /**
     * Validate value uniqueness.
     */
    protected function validateUniqueness(
        AbstractResourceEntityRepresentation $resource,
        $rtpData,
        int $propertyId,
        string $propertyTerm,
        int $resourceId,
        ErrorStore $errorStore,
        bool $directMessage
    ): void {
        if (!$rtpData->dataValue('unique_value')) {
            return;
        }

        $values = $resource->value($propertyTerm, ['all' => true]);
        if (!$values) {
            return;
        }

        $bind = [
            'resource_id' => $resourceId,
            'property_id' => $propertyId,
        ];
        $types = [
            'resource_id' => \Doctrine\DBAL\ParameterType::INTEGER,
            'property_id' => \Doctrine\DBAL\ParameterType::INTEGER,
        ];
        $sqlWhere = [];

        foreach ($values as $value) {
            if ($k = $value->valueResource()) {
                $bind['resource'][] = $k->id();
            } elseif ($k = $value->uri()) {
                $bind['uri'][] = $k;
            } else {
                $bind['literal'][] = $value->value();
            }
        }

        if (isset($bind['resource'])) {
            $sqlWhere[] = 'value.value_resource_id IN (:resource)';
            $types['resource'] = Connection::PARAM_INT_ARRAY;
        }
        if (isset($bind['uri'])) {
            $sqlWhere[] = 'value.uri IN (:uri)';
            $types['uri'] = Connection::PARAM_STR_ARRAY;
        }
        if (isset($bind['literal'])) {
            $sqlWhere[] = 'value.value IN (:literal)';
            $types['literal'] = Connection::PARAM_STR_ARRAY;
        }

        if (!$sqlWhere) {
            return;
        }

        $sqlWhereStr = implode(' OR ', $sqlWhere);
        $sql = <<<SQL
            SELECT value.resource_id
            FROM value
            WHERE value.resource_id != :resource_id
                AND value.property_id = :property_id
                AND ($sqlWhereStr)
            LIMIT 1;
            SQL;

        $resId = $this->connection->executeQuery($sql, $bind, $types)->fetchOne();
        if ($resId) {
            $message = new PsrMessage(
                'The value for term {property} should be unique, but already set for resource #{resource_id}.', // @translate
                ['property' => $propertyTerm, 'resource_id' => $resId]
            );
            $errorStore->addError($propertyTerm, $message);
            if ($directMessage && $this->messenger) {
                $this->messenger->addError($message);
            }
        }
    }
}
