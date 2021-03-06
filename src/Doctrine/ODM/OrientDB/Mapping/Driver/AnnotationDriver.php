<?php

namespace Doctrine\ODM\OrientDB\Mapping\Driver;

use Doctrine\Common\Annotations\Annotation;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Persistence\Mapping\ClassMetadata as BaseClassMetadata;
use Doctrine\Common\Persistence\Mapping\Driver\AnnotationDriver as AbstractAnnotationDriver;
use Doctrine\ODM\OrientDB\Events;
use Doctrine\ODM\OrientDB\Mapping\Annotations;
use Doctrine\ODM\OrientDB\Mapping\Annotations\AbstractDocument;
use Doctrine\ODM\OrientDB\Mapping\Annotations\ChangeTrackingPolicy;
use Doctrine\ODM\OrientDB\Mapping\Annotations\Document;
use Doctrine\ODM\OrientDB\Mapping\Annotations\DocumentListeners;
use Doctrine\ODM\OrientDB\Mapping\Annotations\Embedded;
use Doctrine\ODM\OrientDB\Mapping\Annotations\EmbeddedDocument;
use Doctrine\ODM\OrientDB\Mapping\Annotations\EmbeddedList;
use Doctrine\ODM\OrientDB\Mapping\Annotations\EmbeddedMap;
use Doctrine\ODM\OrientDB\Mapping\Annotations\EmbeddedPropertyBase;
use Doctrine\ODM\OrientDB\Mapping\Annotations\EmbeddedSet;
use Doctrine\ODM\OrientDB\Mapping\Annotations\In;
use Doctrine\ODM\OrientDB\Mapping\Annotations\Link;
use Doctrine\ODM\OrientDB\Mapping\Annotations\LinkList;
use Doctrine\ODM\OrientDB\Mapping\Annotations\LinkMap;
use Doctrine\ODM\OrientDB\Mapping\Annotations\LinkPropertyBase;
use Doctrine\ODM\OrientDB\Mapping\Annotations\LinkSet;
use Doctrine\ODM\OrientDB\Mapping\Annotations\MappedSuperclass;
use Doctrine\ODM\OrientDB\Mapping\Annotations\Property;
use Doctrine\ODM\OrientDB\Mapping\Annotations\PropertyBase;
use Doctrine\ODM\OrientDB\Mapping\Annotations\RelatedTo;
use Doctrine\ODM\OrientDB\Mapping\Annotations\RelatedToBase;
use Doctrine\ODM\OrientDB\Mapping\Annotations\Relationship;
use Doctrine\ODM\OrientDB\Mapping\Annotations\RID;
use Doctrine\ODM\OrientDB\Mapping\Annotations\Version;
use Doctrine\ODM\OrientDB\Mapping\Annotations\Vertex;
use Doctrine\ODM\OrientDB\Mapping\Annotations\VertexLink;
use Doctrine\ODM\OrientDB\Mapping\Builder\DocumentListenerBuilder;
use Doctrine\ODM\OrientDB\Mapping\ClassMetadata;
use Doctrine\ODM\OrientDB\Mapping\MappingException;

class AnnotationDriver extends AbstractAnnotationDriver
{
    protected $entityAnnotationClasses = [
        Document::class         => true,
        MappedSuperclass::class => true,
        EmbeddedDocument::class => true,
        Relationship::class     => true,
        Vertex::class           => true,
    ];

    /**
     * Registers annotation classes to the common registry.
     *
     * This method should be called when bootstrapping your application.
     */
    public static function registerAnnotationClasses() {
        AnnotationRegistry::registerFile(__DIR__ . '/../Annotations/DoctrineAnnotations.php');
    }

    /**
     * Loads the metadata for the specified class into the provided container.
     *
     * @param string            $className
     * @param BaseClassMetadata $metadata
     *
     * @throws MappingException
     */
    public function loadMetadataForClass($className, BaseClassMetadata $metadata) {
        /** @var ClassMetadata $metadata */
        $classAnnotations = $this->reader->getClassAnnotations($metadata->getReflectionClass());
        if (count($classAnnotations) === 0) {
            throw MappingException::classIsNotAValidEntityOrMappedSuperClass($className);
        }

        $documentAnnot = null;
        foreach ($classAnnotations as $annot) {

            if ($annot instanceof AbstractDocument) {
                if ($documentAnnot !== null) {
                    throw MappingException::duplicateDocumentAnnotation($className);
                }
                $documentAnnot = $annot;
                continue;
            }

            switch (true) {
                case $annot instanceof ChangeTrackingPolicy:
                    $metadata->setChangeTrackingPolicy(constant('Doctrine\\ODM\\OrientDB\\Mapping\\ClassMetadata::CHANGETRACKING_' . $annot->value));
                    continue;

                case $annot instanceof DocumentListeners:
                    // handle listeners
                    $this->mapDocumentListeners($className, $metadata, $annot);
                    continue;
            }
        }

        $isDocument = false;
        switch (true) {
            case $documentAnnot instanceof Document:
                $isDocument = true;
                $metadata->setIsDocument();
                break;

            case $documentAnnot instanceof Vertex:
                $isDocument = true;
                $metadata->setIsDocument();
                $metadata->setIsVertex();
                break;

            case $documentAnnot instanceof Relationship:
                $isDocument = true;
                $metadata->setIsDocument();
                $metadata->setIsEdge();
                break;

            case $documentAnnot instanceof EmbeddedDocument:
                $isDocument = true;
                $metadata->setIsEmbeddedDocument();
                break;

            case $documentAnnot instanceof MappedSuperclass:
                $metadata->setIsMappedSuperclass();
                break;
        }

        if ($documentAnnot instanceof Annotations\AbstractPersistentDocument) {
            $metadata->setCustomRepositoryClass($documentAnnot->repositoryClass);
        }

        if ($isDocument) {
            if ($documentAnnot->abstract === true) {
                $metadata->setIsAbstract();
            }
            $metadata->setOrientClass($documentAnnot->oclass);
        }

        foreach ($metadata->reflClass->getProperties() as $property) {
            if (($metadata->isMappedSuperclass() && !$property->isPrivate())
                ||
                $metadata->isInheritedField($property->name)
            ) {
                continue;
            }

            $pas = $this->reader->getPropertyAnnotations($property);
            foreach ($pas as $ann) {
                $mapping = [
                    'fieldName' => $property->getName(),
                    'nullable'  => true,
                ];

                if ($ann instanceof PropertyBase) {
                    if (!$ann->name) {
                        $ann->name = $property->getName();
                    }
                    $mapping['name'] = $ann->name;
                }

                switch (true) {
                    case $ann instanceof Property:
                        $mapping = $this->propertyToArray($property->getName(), $ann);
                        $metadata->mapField($mapping);
                        continue;

                    case $ann instanceof RID:
                        $metadata->mapRid($property->getName());
                        continue;

                    case $ann instanceof Version:
                        $metadata->mapVersion($property->getName());
                        continue;

                    case $ann instanceof Link:
                        $this->mergeLink($ann, $mapping);
                        $mapping['nullable'] = $ann->nullable;
                        $metadata->mapLink($mapping);
                        continue;

                    case $ann instanceof LinkList:
                        $this->mergeLink($ann, $mapping);
                        $metadata->mapLinkList($mapping);
                        continue;

                    case $ann instanceof LinkSet:
                        $this->mergeLink($ann, $mapping);
                        $metadata->mapLinkSet($mapping);
                        continue;

                    case $ann instanceof LinkMap:
                        $this->mergeLink($ann, $mapping);
                        $metadata->mapLinkMap($mapping);
                        continue;

                    case $ann instanceof Embedded:
                        $this->mergeEmbedded($ann, $mapping);
                        $mapping['nullable'] = $ann->nullable;
                        $metadata->mapEmbedded($mapping);
                        continue;

                    case $ann instanceof EmbeddedList:
                        $this->mergeEmbedded($ann, $mapping);
                        $metadata->mapEmbeddedList($mapping);
                        continue;

                    case $ann instanceof EmbeddedSet:
                        $this->mergeEmbedded($ann, $mapping);
                        $metadata->mapEmbeddedSet($mapping);
                        continue;

                    case $ann instanceof EmbeddedMap:
                        $this->mergeEmbedded($ann, $mapping);
                        $metadata->mapEmbeddedMap($mapping);
                        continue;

                    case $ann instanceof RelatedToBase:
                        $this->mergeRelatedToBase($ann, $mapping);
                        $mapping['indirect'] = ($ann instanceof RelatedTo);
                        $metadata->mapRelatedToLinkBag($mapping);
                        continue;

                    case $ann instanceof VertexLink:
                        if (isset($ann->targetDoc)) {
                            $mapping['targetDoc'] = $ann->targetDoc;
                        }
                        $dir = $ann instanceof In ? 'in' : 'out';
                        $metadata->mapVertexLink($mapping, $dir);
                        continue;
                }
            }
        }
    }

    /**
     * @param string            $className
     * @param ClassMetadata     $metadata
     * @param DocumentListeners $annot
     *
     * @throws MappingException
     */
    private function mapDocumentListeners($className, ClassMetadata $metadata, DocumentListeners $annot) {
        foreach ($annot->value as $item) {
            $listenerClassName = $metadata->fullyQualifiedClassName($item);

            if (!class_exists($listenerClassName)) {
                throw MappingException::documentListenerClassNotFound($listenerClassName, $className);
            }

            $hasMapping    = false;
            $listenerClass = new \ReflectionClass($listenerClassName);
            /* @var $method \ReflectionMethod */
            foreach ($listenerClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                // find method callbacks.
                $callbacks  = $this->getMethodCallbacks($method);
                $hasMapping = $hasMapping ?: (!empty($callbacks));

                foreach ($callbacks as $value) {
                    $metadata->addDocumentListener($value[1], $listenerClassName, $value[0]);
                }
            }
            // Evaluate the listener using naming convention.
            if (!$hasMapping) {
                DocumentListenerBuilder::bindDocumentListener($metadata, $listenerClassName);
            }
        }
    }

    private static $METHOD_CALLBACKS = [
        Annotations\PrePersist::class  => Events::prePersist,
        Annotations\PostPersist::class => Events::postPersist,
        Annotations\PreUpdate::class   => Events::preUpdate,
        Annotations\PostUpdate::class  => Events::postUpdate,
        Annotations\PreRemove::class   => Events::preRemove,
        Annotations\PostRemove::class  => Events::postRemove,
        Annotations\PostLoad::class    => Events::postLoad,
        Annotations\PreFlush::class    => Events::preFlush,
    ];

    /**
     * Parses the given method.
     *
     * @param \ReflectionMethod $method
     *
     * @return array
     */
    private function getMethodCallbacks(\ReflectionMethod $method) {
        $callbacks   = [];
        $annotations = $this->reader->getMethodAnnotations($method);

        foreach ($annotations as $annot) {
            $class = get_class($annot);
            if (isset(self::$METHOD_CALLBACKS[$class])) {
                $callbacks[] = [$method->name, self::$METHOD_CALLBACKS[$class]];
            }
        }

        return $callbacks;
    }

    public function &propertyToArray($fieldName, Property $prop) {
        $mapping = [
            'fieldName' => $fieldName,
            'name'      => $prop->name,
            'type'      => $prop->type,
            'nullable'  => $prop->nullable,
            'mandatory' => $prop->mandatory,
            'readonly'  => $prop->readonly,
            'min'       => $prop->min,
            'max'       => $prop->max,
            'regexp'    => $prop->regexp,
        ];

        return $mapping;
    }

    private function mergeLink(LinkPropertyBase $link, array &$mapping) {
        $mapping['cascade'] = $link->cascade;
        if (isset($link->targetDoc)) {
            $mapping['targetDoc'] = $link->targetDoc;
        }
        $mapping['orphanRemoval'] = $link->orphanRemoval;

        if (!empty($link->parentProperty)) {
            $mapping['parentProperty'] = $link->parentProperty;
        }
        if (!empty($link->childProperty)) {
            $mapping['childProperty'] = $link->childProperty;
        }
    }

    private function mergeEmbedded(EmbeddedPropertyBase $embed, array &$mapping) {
        if (isset($embed->targetDoc)) {
            $mapping['targetDoc'] = $embed->targetDoc;
        }
    }

    private function mergeRelatedToBase(RelatedToBase $edge, array &$mapping) {
        if (isset($edge->targetDoc)) {
            $mapping['targetDoc'] = $edge->targetDoc;
        }
        $mapping['oclass']    = $edge->oclass;
        $mapping['direction'] = $edge->direction;
    }

    /**
     * Factory method for the Annotation Driver
     *
     * @param array|string $paths
     * @param Reader       $reader
     *
     * @return AnnotationDriver
     */
    public static function create($paths = [], Reader $reader = null) {
        if ($reader === null) {
            $reader = new AnnotationReader();
        }

        return new self($reader, $paths);
    }
}