<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Entity;

use Omeka\Entity\AbstractEntity;
use Omeka\Entity\ResourceTemplate;

/**
 * @Entity
 * @Table(
 *     name="resource_template_data",
 *     uniqueConstraints={
 *         @UniqueConstraint(
 *             name="uniq_resource_template_id",
 *             columns={"resource_template_id"}
 *         )
 *     }
 * )
 */
class ResourceTemplateData extends AbstractEntity
{
    /**
     * @var int
     *
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    protected $id;

    /**
     * @var ResourceTemplate
     *
     * @OneToOne(
     *     targetEntity=\Omeka\Entity\ResourceTemplate::class
     * )
     * @JoinColumn(
     *     nullable=false,
     *     onDelete="CASCADE"
     * )
     */
    protected $resourceTemplate;

    /**
     * @var array
     *
     * @Column(
     *     type="json",
     *     nullable=false
     * )
     */
    protected $data;

    public function getId()
    {
        return $this->id;
    }

    public function setResourceTemplate(ResourceTemplate $resourceTemplate)
    {
        $this->resourceTemplate = $resourceTemplate;
        return $this;
    }

    public function getResourceTemplate(): ResourceTemplate
    {
        return $this->resourceTemplate;
    }

    public function setData(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getDataValue(string $name)
    {
        return $this->data[$name] ?? null;
    }
}
