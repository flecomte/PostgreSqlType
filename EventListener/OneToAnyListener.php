<?php

namespace FLE\Bundle\PostgresqlTypeBundle\EventListener;

use Doctrine\Common\Annotations\Reader;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use FLE\Bundle\PostgresqlTypeBundle\Annotation\OneToAny;
use Symfony\Bridge\Doctrine\RegistryInterface;

class OneToAnyListener
{
    /**
     * @var RegistryInterface
     */
    private $registry;

    /**
     * @var Reader
     */
    private $reader;

    public function __construct(Reader $reader, RegistryInterface $registry)
    {
        $this->reader = $reader;
        $this->registry = $registry;
    }


    private function eachAnnotations ($entity, callable $callable)
    {
        $reflectionClass = new \ReflectionClass($entity);
        foreach ($reflectionClass->getProperties() as $reflectionProperty) {
            $reflectionProperty->setAccessible(true);
            $annotation = $this->reader->getPropertyAnnotation($reflectionProperty, OneToAny::class);
            if ($annotation instanceof OneToAny) {
                $callable($entity, $annotation, $reflectionProperty);
            }
        }
    }

    public function postLoad (LifecycleEventArgs $event)
    {
        $entity = $event->getEntity();

        $this->eachAnnotations($entity, function ($entity, OneToAny $annotation, \ReflectionProperty $reflectionProperty) use ($event)
        {
            /** @var EntityManager $em */
            $em = $this->registry->getManagerForClass(get_class($entity));

            $reference = $reflectionProperty->getValue($entity);
            if ($reference !== null) {
                $ids = $reference['ids'];
                $table = $reference['table'];
                $className = $this->getClassNameFromTableName($table);

                $qb = $em->createQueryBuilder()
                    ->select('e')
                    ->from($className, 'e');

                $i = 0;
                $expr = null;
                foreach ($ids as $k => $v) {
                    if (null === $expr) {
                        $expr = $qb->expr()->eq('e.'.$k, '?'.(++$i));
                    } else {
                        $expr = $qb->expr()->andX($expr, $qb->expr()->eq('e.'.$k, '?'.(++$i)));
                    }

                    $qb->setParameter($i, $v);
                }

                $qb->orWhere($expr);

                $object = $qb->getQuery()->getOneOrNullResult();

                $reflectionProperty->setValue($entity, $object);
            }
        });
    }

    protected function getSoftDeletableCacheId ($className)
    {
        return $className.'\\$GEDMO_SOFTDELETEABLE_CLASSMETADATA';
    }

    protected function isSoftDelete ($entity, EntityManager $em)
    {
        $softDeletableConfig = $em->getConfiguration()->getMetadataCacheImpl()->fetch($this->getSoftDeletableCacheId(get_class($entity)));
        if (isset($softDeletableConfig['softDeleteable']) && isset($softDeletableConfig['fieldName']) && $softDeletableConfig['softDeleteable'] === true) {
            $deleteFieldReflexion = new \ReflectionProperty(get_class($entity), $softDeletableConfig['fieldName']);
            $deleteFieldReflexion->setAccessible(true);
            $oldValue = $deleteFieldReflexion->getValue($entity);
            if ($oldValue === null) {
                return true;
            }
        }
        return false;
    }

    private function getClassNameFromTableName($table, EntityManager $em = null)
    {
        if ($em === null) {
            $em = $this->registry->getEntityManager();
        }
        // Go through all the classes
        $classNames = $em->getConfiguration()->getMetadataDriverImpl()->getAllClassNames();
        foreach ($classNames as $className) {
            $classMetaData = $em->getClassMetadata($className);
            if ($table == $classMetaData->getTableName()) {
                return $classMetaData->getName();
            }
        }
        return null;
    }

    public function preRemove (LifecycleEventArgs $event)
    {
        $entity = $event->getEntity();

        $this->eachAnnotations($entity, function ($entity, OneToAny $annotation, \ReflectionProperty $reflectionProperty) use ($event)
        {
            if ($annotation->orphanRemoval && !$this->isSoftDelete($entity, $event->getEntityManager())) {
                $object = $reflectionProperty->getValue($entity);
                $event->getEntityManager()->persist($object);
            }
        });
    }

    public function postPersist (LifecycleEventArgs $event)
    {
        $entity = $event->getEntity();
        $this->eachAnnotations($entity, function ($entity, OneToAny $annotation, \ReflectionProperty $reflectionProperty) use ($event)
        {
            $object = $reflectionProperty->getValue($entity);
            if ($object === null) {
                return;
            }

            $event->getEntityManager()->beginTransaction();

            if (in_array('persist', $annotation->cascade)) {
                $event->getEntityManager()->persist($object);
                $event->getEntityManager()->flush($object);
            }

            /** @var ClassMetadataInfo $metadata */
            $metadata = $event->getEntityManager()->getMetadataFactory()->getMetadataFor(get_class($object));
            $ids = $metadata->getIdentifierValues($object);
            $relatedTableName = $metadata->getTableName();
            $value = ['ids' => $ids, 'table' => $relatedTableName];
            $metadata->reflFields[$reflectionProperty->getName()] = $reflectionProperty;
            $metadata->setFieldValue($entity, $reflectionProperty->getName(), $value);
            $event->getEntityManager()->persist($entity);
            $event->getEntityManager()->flush($entity);
            $event->getEntityManager()->commit();
        });
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $event)
    {
        $classMetadata = $event->getClassMetadata();
        $this->eachAnnotations($classMetadata->getReflectionClass()->getName(), function ($className, OneToAny $annotation, \ReflectionProperty $reflectionProperty) use ($event, $classMetadata)
        {
            $classMetadata->mapField([
                "fieldName" => $reflectionProperty->getName(),
                "type" => "jsonb",
                "length" => null,
                "nullable" => $annotation->nullable,
                "columnName" => $reflectionProperty->getName()
            ]);
        });
    }
}