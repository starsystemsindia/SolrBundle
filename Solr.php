<?php
namespace FS\SolrBundle;

use Solarium\QueryType\Update\Query\Document\Document;
use FS\SolrBundle\Doctrine\Mapper\EntityMapper;
use FS\SolrBundle\Doctrine\Mapper\Mapping\CommandFactory;
use FS\SolrBundle\Doctrine\Mapper\MetaInformation;
use FS\SolrBundle\Doctrine\Mapper\MetaInformationFactory;
use FS\SolrBundle\Event\ErrorEvent;
use FS\SolrBundle\Event\Event;
use FS\SolrBundle\Event\Events;
use FS\SolrBundle\Event\EventManager;
use FS\SolrBundle\Query\AbstractQuery;
use FS\SolrBundle\Query\FindByIdentifierQuery;
use FS\SolrBundle\Query\SolrQuery;
use FS\SolrBundle\Repository\Repository;
use Solarium\Client;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Solr
{


    /**
     * @var Client
     */
    protected $solrClient = null;

    /**
     * @var EntityMapper
     */
    protected $entityMapper = null;

    /**
     * @var CommandFactory
     */
    protected $commandFactory = null;

    /**
     * @var EventDispatcher
     */
    protected $eventManager = null;

    /**
     * @var MetaInformationFactory
     */
    protected $metaInformationFactory = null;

    /**
     * @var int numFound
     */
    private $numberOfFoundDocuments = 0;

    /**
     * @param Client $client
     * @param CommandFactory $commandFactory
     * @param EventManager $manager
     * @param MetaInformationFactory $metaInformationFactory
     */
    public function __construct(
        Client $client,
        CommandFactory $commandFactory,
        EventDispatcherInterface $manager,
        MetaInformationFactory $metaInformationFactory,
        EntityMapper $entityMapper
    ) {
        $this->solrClient = $client;
        $this->commandFactory = $commandFactory;
        $this->eventManager = $manager;
        $this->metaInformationFactory = $metaInformationFactory;

        $this->entityMapper = $entityMapper;
    }
    
    /**
     * @return Client
     */
    public function getClient()
    {
        return $this->solrClient;
    }

    /**
     * @return EntityMapper
     */
    public function getMapper()
    {
        return $this->entityMapper;
    }

    /**
     * @return CommandFactory
     */
    public function getCommandFactory()
    {
        return $this->commandFactory;
    }

    /**
     * @return MetaInformationFactory
     */
    public function getMetaFactory()
    {
        return $this->metaInformationFactory;
    }

    /**
     * @param object $entity
     * @return SolrQuery
     */
    public function createQuery($entity)
    {
        $metaInformation = $this->metaInformationFactory->loadInformation($entity);
        $class = $metaInformation->getClassName();
        $entity = new $class;

        $query = new SolrQuery();
        $query->setSolr($this);
        $query->setEntity($entity);

        $query->setMappedFields($metaInformation->getFieldMapping());

        return $query;
    }

    /**
     * @param string repositoryClassity
     * @return RepositoryInterface
     */
    public function getRepository($entityAlias)
    {
        $metaInformation = $this->metaInformationFactory->loadInformation($entityAlias);
        $class = $metaInformation->getClassName();

        $entity = new $class;

        $repositoryClass = $metaInformation->getRepository();
        if (class_exists($repositoryClass)) {
            $repositoryInstance = new $repositoryClass($this, $entity);

            if ($repositoryInstance instanceof Repository) {
                return $repositoryInstance;
            }

            throw new \RuntimeException(sprintf(
                '%s must extends the FS\SolrBundle\Repository\Repository',
                $repositoryClass
            ));
        }

        return new Repository($this, $entity);
    }

    /**
     * @param object $entity
     */
    public function removeDocument($entity)
    {
        $command = $this->commandFactory->get('identifier');

        $this->entityMapper->setMappingCommand($command);

        $metaInformations = $this->metaInformationFactory->loadInformation($entity);

        if ($document = $this->entityMapper->toDocument($metaInformations)) {
            $deleteQuery = new FindByIdentifierQuery();
            $deleteQuery->setDocument($document);

            $event = new Event($this->solrClient, $metaInformations);
            $this->eventManager->dispatch(Events::PRE_DELETE, $event);

            try {
                $delete = $this->solrClient->createUpdate();
                $delete->addDeleteQuery($deleteQuery->getQuery());
                $delete->addCommit();

                $this->solrClient->update($delete);
            } catch (\Exception $e) {
                $errorEvent = new ErrorEvent(null, $metaInformations, 'delete-document', $event);
                $errorEvent->setException($e);

                $this->eventManager->dispatch(Events::ERROR, $errorEvent);
            }

            $this->eventManager->dispatch(Events::POST_DELETE, $event);
        }
    }

    /**
     * @param object $entity
     */
    public function addDocument($entity)
    {
        $metaInformation = $this->metaInformationFactory->loadInformation($entity);

        if (!$this->addToIndex($metaInformation, $entity)) {
            return;
        }

        $doc = $this->toDocument($metaInformation);

        $event = new Event($this->solrClient, $metaInformation);
        $this->eventManager->dispatch(Events::PRE_INSERT, $event);

        $this->addDocumentToIndex($doc, $metaInformation, $event);

        $this->eventManager->dispatch(Events::POST_INSERT, $event);
    }

    /**
     * @param MetaInformation $metaInformation
     * @param object $entity
     * @throws \BadMethodCallException if callback method not exists
     * @return boolean
     */
    private function addToIndex(MetaInformation $metaInformation, $entity)
    {
        if (!$metaInformation->hasSynchronizationFilter()) {
            return true;
        }

        $callback = $metaInformation->getSynchronizationCallback();
        if (!method_exists($entity, $callback)) {
            throw new \BadMethodCallException(sprintf('unknown method %s in entity %s', $callback, get_class($entity)));
        }

        return $entity->$callback();
    }

    /**
     * @param AbstractQuery $query
     * @return array of found documents
     */
    public function query(AbstractQuery $query)
    {
        $entity = $query->getEntity();

        $queryString = $query->getQuery();
        $query = $this->solrClient->createSelect($query->getOptions());
        $query->setQuery($queryString);

        try {
            $response = $this->solrClient->select($query);
        } catch (\Exception $e) {
            $errorEvent = new ErrorEvent(null, null, 'query solr');
            $errorEvent->setException($e);

            $this->eventManager->dispatch(Events::ERROR, $errorEvent);

            return array();
        }

        $this->numberOfFoundDocuments = $response->getNumFound();
        if ($this->numberOfFoundDocuments == 0) {
            return array();
        }

        $targetEntity = $entity;
        $mappedEntities = array();
        foreach ($response as $document) {
            $mappedEntities[] = $this->entityMapper->toEntity($document, $targetEntity);
        }

        return $mappedEntities;
    }

    /**
     * Number of results found by query
     * @return integer
     */
    public function getNumFound()
    {
        return $this->numberOfFoundDocuments;
    }

    /**
     * clears the whole index by using the query *:*
     */
    public function clearIndex()
    {
        $this->eventManager->dispatch(Events::PRE_CLEAR_INDEX, new Event($this->solrClient));

        try {
            $delete = $this->solrClient->createUpdate();
            $delete->addDeleteQuery('*:*');
            $delete->addCommit();

            $this->solrClient->update($delete);
        } catch (\Exception $e) {
            $errorEvent = new ErrorEvent(null, null, 'clear-index');
            $errorEvent->setException($e);

            $this->eventManager->dispatch(Events::ERROR, $errorEvent);
        }

        $this->eventManager->dispatch(Events::POST_CLEAR_INDEX, new Event($this->solrClient));
    }

    /**
     * @param object $entity
     */
    public function synchronizeIndex($entity)
    {
        $this->updateDocument($entity);
    }

    /**
     * @param object $entity
     */
    public function updateDocument($entity)
    {
        $metaInformations = $this->metaInformationFactory->loadInformation($entity);
        
        if (!$this->addToIndex($metaInformations, $entity)) {
            return;
        }

        $doc = $this->toDocument($metaInformations);

        $event = new Event($this->solrClient, $metaInformations);
        $this->eventManager->dispatch(Events::PRE_UPDATE, $event);

        $this->addDocumentToIndex($doc, $metaInformations, $event);

        $this->eventManager->dispatch(Events::POST_UPDATE, $event);

        return true;
    }

    /**
     * @param MetaInformation metaInformationsy
     * @return Document|null
     */
    private function toDocument(MetaInformation $metaInformation)
    {
        $command = $this->commandFactory->get('all');

        $this->entityMapper->setMappingCommand($command);
        $doc = $this->entityMapper->toDocument($metaInformation);

        return $doc;
    }

    /**
     * @param Document $doc
     * @param MetaInformation $metaInformation
     */
    private function addDocumentToIndex($doc, MetaInformation $metaInformation, Event $event)
    {
        try {
            $update = $this->solrClient->createUpdate();
            $update->addDocument($doc);
            $update->addCommit();

            $this->solrClient->update($update);
        } catch (\Exception $e) {
            $errorEvent = new ErrorEvent(null, $metaInformation, json_encode($this->solrClient->getOptions()), $event);
            $errorEvent->setException($e);

            $this->eventManager->dispatch(Events::ERROR, $errorEvent);
        }
    }
}
