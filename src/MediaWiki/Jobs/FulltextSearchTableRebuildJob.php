<?php

namespace SMW\MediaWiki\Jobs;

use SMW\ApplicationFactory;
use SMW\SQLStore\QueryEngine\FulltextSearchTableFactory;
use Title;

/**
 * @license GNU GPL v2+
 * @since 2.5
 *
 * @author mwjames
 */
class FulltextSearchTableRebuildJob extends JobBase {

	/**
	 * @since 2.5
	 *
	 * @param Title $title
	 * @param array $params job parameters
	 */
	public function __construct( Title $title, $params = array() ) {
		parent::__construct( 'SMW\FulltextSearchTableRebuildJob', $title, $params );
	}

	/**
	 * @see Job::run
	 *
	 * @since  2.5
	 */
	public function run() {

		if ( $this->waitOnCommandLineMode() ) {
			return true;
		}

		$fulltextSearchTableFactory = new FulltextSearchTableFactory();

		// Only the SQLStore is supported
		$searchTableRebuilder = $fulltextSearchTableFactory->newSearchTableRebuilder(
			ApplicationFactory::getInstance()->getStore( '\SMW\SQLStore\SQLStore' )
		);

		if ( $this->hasParameter( 'table' ) ) {
			$searchTableRebuilder->rebuildByTable( $this->getParameter( 'table' ) );
		} elseif ( $this->hasParameter( 'mode' ) && $this->getParameter( 'mode' ) === 'full' ) {
			$searchTableRebuilder->rebuild();
		} else {
			$searchTableRebuilder->flushTable();
			$this->createJobsFromTableList( $searchTableRebuilder->getQualifiedTableList() );
		}

		return true;
	}

	private function createJobsFromTableList( $tableList ) {

		if ( $tableList === array() ) {
			return;
		}

		foreach ( $tableList as $tableName ) {
			$fulltextSearchTableRebuildJob = new self( $this->getTitle(), array(
				'table' => $tableName
			) );

			$fulltextSearchTableRebuildJob->insert();
		}
	}

}
