<?php

namespace Bolt\Storage\Field\Type;

use Bolt\Exception\FieldConfigurationException;
use Bolt\Storage\Entity;
use Bolt\Storage\Field\Collection\RepeatingFieldCollection;
use Bolt\Storage\Mapping\ClassMetadata;
use Bolt\Storage\QuerySet;
use Bolt\Storage\Repository\FieldValueRepository;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Type;

/**
 * This is one of a suite of basic Bolt field transformers that handles
 * the lifecycle of a field from pre-query to persist.
 *
 * @author Ross Riley <riley.ross@gmail.com>
 */
class RepeaterType extends FieldTypeBase
{
    /**
     * For repeating fields, the load method adds extra joins and selects to the query that
     * fetches the related records from the field and field value tables in the same query as the content fetch.
     *
     * @param QueryBuilder  $query
     * @param ClassMetadata $metadata
     *
     * @return QueryBuilder|null
     */
    public function load(QueryBuilder $query, ClassMetadata $metadata)
    {
        $field = $this->mapping['fieldname'];
        $boltname = $metadata->getBoltName();
        $table = $this->mapping['tables']['field_value'];

        $from = $query->getQueryPart('from');

        if (isset($from[0]['alias'])) {
            $alias = $from[0]['alias'];
        } else {
            $alias = $from[0]['table'];
        }

        $subQuery = '(SELECT ' . $this->getPlatformGroupConcat($query) . " FROM $table f WHERE f.content_id = $alias.id AND f.contenttype='$boltname' AND f.name = '$field') as $field";
        $query->addSelect($subQuery);
    }

    public function persist(QuerySet $queries, $entity)
    {
        $this->normalize($entity);
        $key = $this->mapping['fieldname'];
        $accessor = 'get' . ucfirst($key);
        $proposed = $entity->$accessor();

        $collection = new RepeatingFieldCollection($this->em, $this->mapping);
        $existingFields = $this->getExistingFields($entity) ?: [];
        foreach ($existingFields as $group => $ids) {
            $collection->addFromReferences($ids, $group);
        }

        $toDelete = $collection->update($proposed);
        $repo = $this->em->getRepository(Entity\FieldValue::class);

        $queries->onResult(
            function ($query, $result, $id) use ($repo, $collection, $toDelete) {
                foreach ($collection as $entity) {
                    $entity->content_id = $id;
                    $repo->save($entity, true);
                }

                foreach ($toDelete as $entity) {
                    $repo->delete($entity);
                }
            }
        );
    }

    /**
     * {@inheritdoc}
     */
    public function hydrate($data, $entity)
    {
        /** @var string $key */
        $key = $this->mapping['fieldname'];
        $collection = new RepeatingFieldCollection($this->em, $this->mapping);
        $collection->setName($key);

        // If there isn't anything set yet then we just return an empty collection
        if (!isset($data[$key])) {
            $this->set($entity, $collection);

            return;
        }

        // This block separately handles JSON content for Templatefields
        if (isset($data[$key]) && $this->isJson($data[$key])) {
            $originalMapping[$key]['fields'] = $this->mapping['fields'];
            $originalMapping[$key]['type'] = 'repeater';
            $mapping = $this->em->getMapper()->getRepeaterMapping($originalMapping);

            $decoded = json_decode($data[$key], true);
            $collection = new RepeatingFieldCollection($this->em, $mapping);
            $collection->setName($key);

            if (isset($decoded) && count($decoded)) {
                foreach ($decoded as $group => $repdata) {
                    $collection->addFromArray($repdata, $group);
                }
            }

            $this->set($entity, $collection);

            return;
        }

        // Final block handles values stored in the DB and creates a lazy collection
        $vals = array_filter(explode(',', $data[$key]));
        $values = [];
        foreach ($vals as $fieldKey) {
            $split = explode('_', $fieldKey);
            $id = array_pop($split);
            $group = array_pop($split);
            $field = join('_', $split);
            $values[$field][$group][] = $id;
        }

        if (isset($values[$key]) && count($values[$key])) {
            foreach ($values[$key] as $group => $refs) {
                $collection->addFromReferences($refs, $group);
            }
        }

        $this->set($entity, $collection);
    }

    /**
     * The set method gets called directly by a new entity builder. For this field we never want to allow
     * null values, rather we want an empty collection so this overrides the default and handles that.
     *
     * @param object $entity
     * @param mixed  $val
     */
    public function set($entity, $val)
    {
        if ($val === null) {
            $val = new RepeatingFieldCollection($this->em, $this->mapping);
        }

        return parent::set($entity, $val);
    }

    /**
     * Normalize step ensures that we have correctly hydrated objects at the collection
     * and entity level.
     *
     * @param object $entity
     */
    public function normalize($entity)
    {
        $key = $this->mapping['fieldname'];
        $accessor = 'get' . ucfirst($key);

        $outerCollection = $entity->$accessor();
        if (!$outerCollection instanceof RepeatingFieldCollection) {
            $collection = new RepeatingFieldCollection($this->em, $this->mapping);
            $collection->setName($key);

            if (is_array($outerCollection)) {
                foreach ($outerCollection as $group => $fields) {
                    if (is_array($fields)) {
                        $collection->addFromArray($fields, $group, $entity);
                    }
                }
            }

            $setter = 'set' . ucfirst($key);
            $entity->$setter($collection);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'repeater';
    }

    /**
     * Get platform specific group_concat token for provided column
     *
     * @param QueryBuilder $query
     *
     * @return string
     */
    protected function getPlatformGroupConcat(QueryBuilder $query)
    {
        $platform = $query->getConnection()->getDatabasePlatform()->getName();

        switch ($platform) {
            case 'mysql':
                return "GROUP_CONCAT(DISTINCT CONCAT_WS('_', f.name, f.grouping, f.id))";
            case 'sqlite':
                return "GROUP_CONCAT(DISTINCT f.name||'_'||f.grouping||'_'||f.id)";
            case 'postgresql':
                return "string_agg(concat_ws('_', f.name,f.grouping,f.id), ',' ORDER BY f.grouping)";
        }

        throw new \RuntimeException(sprintf('Configured database platform "%s" is not supported.', $platform));
    }

    /**
     * Get existing fields for this record.
     *
     * @param object $entity
     *
     * @return array
     */
    protected function getExistingFields($entity)
    {
        /** @var FieldValueRepository $repo */
        $repo = $this->em->getRepository(Entity\FieldValue::class);

        return $repo->getExistingFields($entity->getId(), $entity->getContenttype(), $this->mapping['fieldname']);
    }

    /**
     * Query to insert new field values.
     *
     * @param QuerySet $queries
     * @param array    $changes
     * @param object   $entity
     */
    protected function addToInsertQuery(QuerySet $queries, $changes, $entity)
    {
        foreach ($changes as $fieldValue) {
            $repo = $this->em->getRepository(get_class($fieldValue));
            $field = $this->getFieldType($fieldValue->getFieldname());
            $type = $field->getStorageType();
            $typeCol = 'value_' . $type->getName();

            $fieldValue->$typeCol = $fieldValue->getValue();
            $fieldValue->setFieldtype($this->getFieldTypeName($fieldValue->getFieldname()));
            $fieldValue->setContenttype((string) $entity->getContenttype());

            // This takes care of instances where an entity might be inserted, and thus not
            // have an id. This registers a callback to set the id parameter when available.
            $queries->onResult(
                function ($query, $result, $id) use ($repo, $fieldValue) {
                    if ($result === 1 && $id) {
                        $fieldValue->setContent_id($id);
                        $repo->save($fieldValue, true);
                    }
                }
            );
        }
    }

    /**
     * Query to delete existing field values.
     *
     * @param QuerySet $queries
     * @param $changes
     */
    protected function addToDeleteQuery(QuerySet $queries, $changes)
    {
    }

    /**
     * Query to insert new field values.
     *
     * @param QuerySet $queries
     * @param array    $changes
     */
    protected function addToUpdateQuery(QuerySet $queries, $changes)
    {
        foreach ($changes as $fieldValue) {
            $repo = $this->em->getRepository(get_class($fieldValue));
            $field = $this->getFieldType($fieldValue->getFieldname());
            $type = $field->getStorageType();
            $typeCol = 'value_' . $type->getName();
            $fieldValue->$typeCol = $fieldValue->getValue();

            // This takes care of instances where an entity might be inserted, and thus not
            // have an id. This registers a callback to set the id parameter when available.
            $queries->onResult(
                function ($query, $result, $id) use ($repo, $fieldValue) {
                    if ($result === 1) {
                        $repo->save($fieldValue, true);
                    }
                }
            );
        }
    }

    /**
     * @param string $field
     *
     * @throws FieldConfigurationException
     *
     * @return FieldTypeBase
     */
    protected function getFieldType($field)
    {
        if (!isset($this->mapping['data']['fields'][$field]['fieldtype'])) {
            throw new FieldConfigurationException('Invalid repeating field configuration for ' . $field);
        }
        $mapping = $this->mapping['data']['fields'][$field];
        $setting = $mapping['fieldtype'];

        return $this->em->getFieldManager()->get($setting, $mapping);
    }

    /**
     * @param $field
     *
     * @throws FieldConfigurationException
     *
     * @return mixed
     */
    protected function getFieldTypeName($field)
    {
        if (!isset($this->mapping['data']['fields'][$field]['type'])) {
            throw new FieldConfigurationException('Invalid repeating field configuration for ' . $field);
        }
        $mapping = $this->mapping['data']['fields'][$field];

        return $mapping['type'];
    }

    /**
     * {@inheritdoc}
     */
    public function getStorageType()
    {
        return Type::getType('json_array');
    }
}
