<?php

namespace SMW\Elastic;

use Onoi\MessageReporter\MessageReporter;
use Onoi\MessageReporter\NullMessageReporter;
use Psr\Log\LoggerInterface;
use SMW\ApplicationFactory;
use SMW\Elastic\Admin\ElasticClientTaskHandler;
use SMW\Elastic\Admin\IndicesInfoProvider;
use SMW\Elastic\Admin\MappingsInfoProvider;
use SMW\Elastic\Admin\NodesInfoProvider;
use SMW\Elastic\Admin\SettingsInfoProvider;
use SMW\Elastic\Indexer\Indexer;
use SMW\Elastic\Indexer\Rebuilder;
use SMW\Elastic\QueryEngine\ConditionBuilder;
use SMW\Elastic\QueryEngine\QueryEngine;
use SMW\Elastic\QueryEngine\TermsLookup\CachingTermsLookup;
use SMW\Elastic\QueryEngine\TermsLookup\TermsLookup;
use SMW\Options;
use SMW\SQLStore\PropertyTableRowMapper;
use SMW\Store;
use SMW\Elastic\Connection\ConnectionProvider;
use SMW\Services\ServicesContainer;
use SMW\Elastic\QueryEngine\DescriptionInterpreters\ClassDescriptionInterpreter;
use SMW\Elastic\QueryEngine\DescriptionInterpreters\ConceptDescriptionInterpreter;
use SMW\Elastic\QueryEngine\DescriptionInterpreters\ConjunctionInterpreter;
use SMW\Elastic\QueryEngine\DescriptionInterpreters\DisjunctionInterpreter;
use SMW\Elastic\QueryEngine\DescriptionInterpreters\NamespaceDescriptionInterpreter;
use SMW\Elastic\QueryEngine\DescriptionInterpreters\SomePropertyInterpreter;
use SMW\Elastic\QueryEngine\DescriptionInterpreters\ValueDescriptionInterpreter;

/**
 * @license GNU GPL v2+
 * @since 3.0
 *
 * @author mwjames
 */
class ElasticFactory {

	/**
	 * @since 3.0
	 *
	 * @return Config
	 */
	public function newConfig() {

		$settings = ApplicationFactory::getInstance()->getSettings();

		$config = new Config(
			$settings->get( 'smwgElasticsearchConfig' )
		);

		$isElasticstore = strpos( $settings->get( 'smwgDefaultStore' ), 'Elastic' ) !== false;

		$config->set(
			'elastic.enabled',
			$isElasticstore
		);

		$config->set(
			'is.elasticstore',
			$isElasticstore
		);

		$config->set(
			'endpoints',
			$settings->get( 'smwgElasticsearchEndpoints' )
		);

		$config->loadFromJSON(
			$config->readFile( $settings->get( 'smwgElasticsearchProfile' ) )
		);

		return $config;
	}

	/**
	 * @since 3.0
	 *
	 * @return ConnectionProvider
	 */
	public function newConnectionProvider() {

		$applicationFactory = ApplicationFactory::getInstance();

		$connectionProvider = new ConnectionProvider(
			$this->newConfig(),
			$applicationFactory->getCache()
		);

		$connectionProvider->setLogger(
			$applicationFactory->getMediaWikiLogger( 'smw-elastic' )
		);

		return $connectionProvider;
	}

	/**
	 * @since 3.0
	 *
	 * @param Store $store
	 * @param MessageReporter|null $messageReporter
	 *
	 * @return Indexer
	 */
	public function newIndexer( Store $store, MessageReporter $messageReporter = null ) {

		$applicationFactory = ApplicationFactory::getInstance();
		$indexer = new Indexer( $store );

		if ( $messageReporter === null ) {
			$messageReporter = new NullMessageReporter();
		}

		$indexer->setLogger(
			$applicationFactory->getMediaWikiLogger( 'smw-elastic' )
		);

		$indexer->setMessageReporter(
			$messageReporter
		);

		return $indexer;
	}

	/**
	 * @since 3.0
	 *
	 * @param Store $store
	 *
	 * @return QueryEngine
	 */
	public function newQueryEngine( Store $store ) {

		$applicationFactory = ApplicationFactory::getInstance();
		$options = $this->newConfig();

		$queryOptions = new Options(
			$options->safeGet( 'query', [] )
		);

		$termsLookup = new CachingTermsLookup(
			new TermsLookup( $store, $queryOptions ),
			$applicationFactory->getCache()
		);

		$servicesContainer = new ServicesContainer(
			[
				'ConceptDescriptionInterpreter' => [ $this, 'newConceptDescriptionInterpreter' ],
				'SomePropertyInterpreter' => [ $this, 'newSomePropertyInterpreter' ],
				'ClassDescriptionInterpreter' => [ $this, 'newClassDescriptionInterpreter' ],
				'NamespaceDescriptionInterpreter' => [ $this, 'newNamespaceDescriptionInterpreter' ],
				'ValueDescriptionInterpreter' => [ $this, 'newValueDescriptionInterpreter' ],
				'ConjunctionInterpreter' => [ $this, 'newConjunctionInterpreter' ],
				'DisjunctionInterpreter' => [ $this, 'newDisjunctionInterpreter' ],
			]
		);

		$conditionBuilder = new ConditionBuilder(
			$store,
			$termsLookup,
			$applicationFactory->newHierarchyLookup(),
			$servicesContainer
		);

		$conditionBuilder->setOptions( $queryOptions );

		$queryEngine = new QueryEngine(
			$store,
			$conditionBuilder,
			$options
		);

		$queryEngine->setLogger(
			$applicationFactory->getMediaWikiLogger( 'smw-elastic' )
		);

		return $queryEngine;
	}

	/**
	 * @since 3.0
	 *
	 * @param Store $store
	 *
	 * @return Rebuilder
	 */
	public function newRebuilder( Store $store ) {

		$rebuilder = new Rebuilder(
			$store->getConnection( 'elastic' ),
			$this->newIndexer( $store ),
			new PropertyTableRowMapper( $store )
		);

		return $rebuilder;
	}

	/**
	 * @since 3.0
	 *
	 * @param Store $store
	 *
	 * @return ElasticClientTaskHandler
	 */
	public function newInfoTaskHandler( Store $store, $outputFormatter ) {

		$taskHandlers = [
			new SettingsInfoProvider( $outputFormatter ),
			new MappingsInfoProvider( $outputFormatter ),
			new IndicesInfoProvider( $outputFormatter ),
			new NodesInfoProvider( $outputFormatter )
		];

		return new ElasticClientTaskHandler( $outputFormatter, $taskHandlers );
	}

	/**
	 * @since 3.0
	 *
	 * @param ConditionBuilder $containerBuilder
	 *
	 * @return ConceptDescriptionInterpreter
	 */
	public function newConceptDescriptionInterpreter( ConditionBuilder $containerBuilder ) {
		return new ConceptDescriptionInterpreter(
			$containerBuilder,
			ApplicationFactory::getInstance()->newQueryParser()
		);
	}

	/**
	 * @since 3.0
	 *
	 * @param ConditionBuilder $containerBuilder
	 *
	 * @return SomePropertyInterpreter
	 */
	public function newSomePropertyInterpreter( ConditionBuilder $containerBuilder ) {
		return new SomePropertyInterpreter( $containerBuilder );
	}

	/**
	 * @since 3.0
	 *
	 * @param ConditionBuilder $containerBuilder
	 *
	 * @return ClassDescriptionInterpreter
	 */
	public function newClassDescriptionInterpreter( ConditionBuilder $containerBuilder ) {
		return new ClassDescriptionInterpreter( $containerBuilder );
	}

	/**
	 * @since 3.0
	 *
	 * @param ConditionBuilder $containerBuilder
	 *
	 * @return NamespaceDescriptionInterpreter
	 */
	public function newNamespaceDescriptionInterpreter( ConditionBuilder $containerBuilder ) {
		return new NamespaceDescriptionInterpreter( $containerBuilder );
	}

	/**
	 * @since 3.0
	 *
	 * @param ConditionBuilder $containerBuilder
	 *
	 * @return ValueDescriptionInterpreter
	 */
	public function newValueDescriptionInterpreter( ConditionBuilder $containerBuilder ) {
		return new ValueDescriptionInterpreter( $containerBuilder );
	}

	/**
	 * @since 3.0
	 *
	 * @param ConditionBuilder $containerBuilder
	 *
	 * @return ConjunctionInterpreter
	 */
	public function newConjunctionInterpreter( ConditionBuilder $containerBuilder ) {
		return new ConjunctionInterpreter( $containerBuilder );
	}

	/**
	 * @since 3.0
	 *
	 * @param ConditionBuilder $containerBuilder
	 *
	 * @return DisjunctionInterpreter
	 */
	public function newDisjunctionInterpreter( ConditionBuilder $containerBuilder ) {
		return new DisjunctionInterpreter( $containerBuilder );
	}

}
