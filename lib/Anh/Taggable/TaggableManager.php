<?php

namespace Anh\Taggable;

use Doctrine\ORM\EntityManager;
use Doctrine\Common\Collections\ArrayCollection;
use Anh\Taggable\Entity\Tag;

/**
 * The TaggableManager is responsible for managing tags for resources.
 * It handles syncing between tags and tagging.
 *
 * @author Andrew Hilobok <hilobok@gmail.com>
 */
class TaggableManager
{
    /**
     * Doctrine entity manager for db interaction
     *
     * @var EntityManager
     */
    protected $em;

    /**
     * Tag entity class name
     *
     * @var string
     */
    protected $tagClass;

    /**
     * Tagging entity class name
     *
     * @var string
     */
    protected $taggingClass;

    /**
     * Holds tagging for resources
     *
     * @var array
     */
    protected $taggingMap;

    /**
     * Initializes a new TaggableManager instance with entity manager and class names for tag and tagging entities.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param string                      $tagClass
     * @param string                      $taggingClass
     */
    public function __construct(EntityManager $em, $tagClass, $taggingClass)
    {
        $this->em = $em;
        $this->tagClass = $tagClass;
        $this->taggingClass = $taggingClass;
    }

    /**
     * Tag entity class name getter
     *
     * @return string
     */
    public function getTagClass()
    {
        return $this->tagClass;
    }

    /**
     * Tagging entity class name getter
     *
     * @return string
     */
    public function getTaggingClass()
    {
        return $this->taggingClass;
    }

    /**
     * Creates Tag entity
     *
     * @return \Anh\Taggable\Entity\Tag
     */
    public function createTag()
    {
        $class = $this->getTagClass();

        return new $class;
    }

    /**
     * Creates Tagging entity
     *
     * @return \Anh\Taggable\Entity\Tagging
     */
    public function createTagging()
    {
        $class = $this->getTaggingClass();

        return new $class;
    }

    /**
     * Loads tag by name or creates if not exists.
     *
     * @param string $name
     *
     * @return \Anh\Taggable\Entity\Tag Tag entity
     */
    public function loadOrCreateTag($name)
    {
        $tags = $this->loadOrCreateTags((array) $name);

        return reset($tags);
    }

    /**
     * Loads array of tags by names or creates if not exists.
     *
     * @param array $names
     *
     * @return array Array of tag entities
     */
    public function loadOrCreateTags(array $names)
    {
        $names = array_unique(array_map('trim', $names));

        $persistentTags = $this->em->createQueryBuilder()
            ->select('tag')
            ->from($this->tagClass, 'tag')
            ->where('tag.name in (:names)')
            ->setParameter('names', $names)
            ->getQuery()
            ->getResult()
        ;

        $nonPersistentTags = array_filter(
            $this->em->getUnitOfWork()->getScheduledEntityInsertions(),
            function($tag) use ($names) {
                return ($tag instanceof Tag) and in_array($tag->getName(), $names);
            }
        );

        $tags = array_merge($persistentTags, $nonPersistentTags);

        $existingNames = array_map(function($tag) { return $tag->getName(); }, $tags);

        $missingNames = array_udiff($names, $existingNames, 'strcasecmp');

        foreach ($missingNames as $name) {
            $tag = $this->createTag();
            $tag->setName($name);
            $this->em->persist($tag);
            $tags[] = $tag;
        }

        // re-index for tests
        return array_values($tags);
    }

    /**
     * Loads tags associated with resource.
     *
     * @param \Anh\Taggable\TaggableInterface $resource
     *
     * @return \Doctrine\Common\Collections\ArrayCollection
     */
    public function loadTags(TaggableInterface $resource)
    {
        $taggingList = $this->loadTagging($resource);
        $this->setTagging($resource, $taggingList);

        $tags = array_map(
            function($tagging) { return $tagging->getTag(); },
            $taggingList->toArray()
        );

        return new ArrayCollection($tags);
    }

    /**
     * Syncs tags and tagging for resource.
     *
     * @param \Anh\Taggable\TaggableInterface $resource
     * @param boolean                         $flush
     *
     * @return void
     */
    public function syncTagging(TaggableInterface $resource, $flush = false)
    {
        if ($resource->getTaggableId() == null) {
            return;
        }

        $taggingCache = $this->getTagging($resource);

        $priorTags = array_map(function($tagging) { return $tagging->getTag(); }, $taggingCache->toArray());
        $currentTags = $resource->getTags()->toArray();

        $compareCallback = function($tag1, $tag2) { return strcasecmp($tag1->getName(), $tag2->getName()); };

        $removedTags = array_udiff($priorTags, $currentTags, $compareCallback);
        if (!empty($removedTags)) {
            $names = array_map(function($tag) { return $tag->getName(); }, $removedTags);
            $taggingToRemove = $taggingCache->filter(function($tagging) use ($names) { return in_array($tagging->getTag()->getName(), $names); });
            foreach ($taggingToRemove as $tagging) {
                $taggingCache->removeElement($tagging);
                $this->em->remove($tagging);
            }
        }

        $addedTags = array_udiff($currentTags, $priorTags, $compareCallback);
        foreach ($addedTags as $tag) {
            $tagging = $this->createTagging();
            $tagging->setResource($resource);
            $tagging->setTag($tag);
            $this->em->persist($tagging);
            $taggingCache->add($tagging);
        }

        if ($flush) {
            $this->em->flush();
        }
    }

    /**
     * Deletes tagging for resource.
     *
     * @param \Anh\Taggable\TaggableInterface $resource
     *
     * @return void
     */
    public function deleteTagging(TaggableInterface $resource)
    {
        $this->syncTagging($resource);

        $taggingList = $this->getTagging($resource);

        foreach ($taggingList as $tagging) {
            $this->em->remove($tagging);
        }
    }

    /**
     * Gets tagging for resource.
     *
     * @param \Anh\Taggable\TaggableInterface $resource
     *
     * @return \Doctrine\Common\Collections\ArrayCollection
     */
    protected function getTagging(TaggableInterface $resource)
    {
        $hash = spl_object_hash($resource);

        if (!isset($this->taggingMap[$hash])) {
            $this->taggingMap[$hash] = $this->loadTagging($resource);
        }

        return $this->taggingMap[$hash];
    }

    /**
     * Sets tagging for resource.
     *
     * @param \Anh\Taggable\TaggableInterface              $resource
     * @param \Doctrine\Common\Collections\ArrayCollection $tagging
     *
     * @return \Doctrine\Common\Collections\ArrayCollection
     */
    protected function setTagging(TaggableInterface $resource, ArrayCollection $tagging)
    {
        $hash = spl_object_hash($resource);

        return $this->taggingMap[$hash] = $tagging;
    }

   /**
     * Loads tagging for resource from db.
     *
     * @param \Anh\Taggable\TaggableInterface $resource
     *
     * @return \Doctrine\Common\Collections\ArrayCollection
     */
    protected function loadTagging(TaggableInterface $resource)
    {
        $taggingList = $this->em->createQueryBuilder()
            ->select('tagging')
            ->from($this->taggingClass, 'tagging')
            ->where('tagging.resourceId = :resourceId')
            ->andWhere('tagging.resourceType = :resourceType')
            ->setParameters(array(
                'resourceId' => $resource->getTaggableId(),
                'resourceType' => $resource->getTaggableType()
            ))
            ->getQuery()
            ->getResult()
        ;

        return new ArrayCollection($taggingList);
    }
}
