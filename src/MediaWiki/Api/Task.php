<?php

namespace SMW\MediaWiki\Api;

use ApiBase;
use Iterator;
use SMW\ApplicationFactory;
use SMW\DIWikiPage;
use SMW\MediaWiki\Jobs\UpdateJob;

/**
 * Module to support various tasks initiate using the API interface
 *
 * @license GNU GPL v2+
 * @since 3.0
 *
 * @author mwjames
 */
class Task extends ApiBase {

	const CACHE_NAMESPACE = 'smw:api:task';

	/**
	 * @since 3.0
	 *
	 * @param string $key
	 *
	 * @return string
	 */
	public static function makeCacheKey( $key ) {
		return smwfCacheKey( self::CACHE_NAMESPACE, [ $key ] );
	}

	/**
	 * @see ApiBase::execute
	 */
	public function execute() {

		$params = $this->extractRequestParams();

		$parameters = json_decode(
			$params['params'],
			true
		);

		$results = [];

		if ( $params['task'] === 'update' ) {
			$results = $this->callUpdateTask( $parameters );
		}

		if ( $params['task'] === 'duplookup' ) {
			$results = $this->callDupLookupTask( $parameters );
		}

		if ( $params['task'] === 'job' ) {
			$results = $this->callGenericJobTask( $parameters );
		}

		$this->getResult()->addValue(
			null,
			'task',
			$results
		);
	}

	private function callDupLookupTask( $parameters ) {

		$applicationFactory = ApplicationFactory::getInstance();
		$cache = $applicationFactory->getCache();

		$cacheUsage = $applicationFactory->getSettings()->get(
			'smwgCacheUsage'
		);

		$cacheTTL = 3600;

		if ( isset( $cacheUsage['api.task'] ) ) {
			$cacheTTL = $cacheUsage['api.task'];
		}

		$key = self::makeCacheKey( 'duplookup' );

		// Guard against repeated API calls (or fuzzing)
		if ( ( $result = $cache->fetch( $key ) ) !== false && $cacheTTL !== false ) {
			return $result + ['isFromCache' => true ];
		}

		$rows = $applicationFactory->getStore()->getObjectIds()->findDuplicates();

		// Avoid "Exception caught: Serialization of 'Closure' is not allowedException ..."
		if ( $rows instanceof Iterator ) {
			$rows = iterator_to_array( $rows );
		}

		$result = [
			'list' => $rows,
			'count' => count( $rows ),
			'time' => time()
		];

		$cache->save( $key, $result, $cacheTTL );

		return $result;
	}

	private function callGenericJobTask( $params ) {

		$this->checkParameters( $params );

		if ( $params['subject'] === '' ) {
			return ['done' => false ];
		}

		$title = DIWikiPage::doUnserialize( $params['subject'] )->getTitle();

		if ( $title === null ) {
			return ['done' => false ];
		}

		if ( !isset( $params['job'] ) ) {
			return ['done' => false ];
		}

		$parameters = [];

		if ( isset( $params['parameters'] ) ) {
			$parameters = $params['parameters'];
		}

		$jobFactory = ApplicationFactory::getInstance()->newJobFactory();

		$job = $jobFactory->newByType(
			$params['job'],
			$title,
			$parameters
		);

		$job->insert();
	}

	private function callUpdateTask( $parameters ) {

		$this->checkParameters( $parameters );

		if ( !isset( $parameters['subject'] ) || $parameters['subject'] === '' ) {
			return [ 'done' => false ];
		}

		$subject = DIWikiPage::doUnserialize( $parameters['subject'] );
		$title = $subject->getTitle();

		if ( $title === null ) {
			return ['done' => false ];
		}

		// Each single update is required to allow for a cascading computation
		// where one query follows another to ensure that results are updated
		// according to the value dependency of the referenced annotations that
		// rely on a computed (#ask) value
		if ( !isset( $parameters['ref'] ) ) {
			$parameters['ref'] = [ $subject->getHash() ];
		}

		$jobFactory = ApplicationFactory::getInstance()->newJobFactory();
		$isPost = isset( $parameters['post'] ) ? $parameters['post'] : false;
		$origin = [];

		if ( isset( $parameters['origin'] ) ) {
			$origin = [ 'origin' => $parameters['origin'] ];
		}

		foreach ( $parameters['ref'] as $ref ) {
			$updateJob = $jobFactory->newUpdateJob(
				$title,
				[
					UpdateJob::FORCED_UPDATE => true,
					'ref' => $ref
				] + $origin
			);

			if ( $isPost ) {
				$updateJob->insert();
			} else {
				$updateJob->run();
			}
		}

		return [ 'done' => true ];
	}

	private function checkParameters( $parameters ) {
		if ( json_last_error() !== JSON_ERROR_NONE || !is_array( $parameters ) ) {

			// 1.29+
			if ( method_exists( $this, 'dieWithError' ) ) {
				$this->dieWithError( [ 'smw-api-invalid-parameters' ] );
			} else {
				$this->dieUsageMsg( 'smw-api-invalid-parameters' );
			}
		}
	}

	/**
	 * @codeCoverageIgnore
	 * @see ApiBase::getAllowedParams
	 *
	 * @return array
	 */
	public function getAllowedParams() {
		return array(
			'task' => array(
				ApiBase::PARAM_REQUIRED => true,
				ApiBase::PARAM_TYPE => array(
					'update',
					'duplookup',
					'job'
				)
			),
			'params' => array(
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => false,
			),
		);
	}

	/**
	 * @codeCoverageIgnore
	 * @see ApiBase::getParamDescription
	 *
	 * @return array
	 */
	public function getParamDescription() {
		return array(
			'task' => 'Defines the task type',
			'params' => 'JSON encoded parameters that matches the selected type requirement'
		);
	}

	/**
	 * @codeCoverageIgnore
	 * @see ApiBase::getDescription
	 *
	 * @return array
	 */
	public function getDescription() {
		return array(
			'Semantic MediaWiki API module to invoke and execute tasks (for internal use only)'
		);
	}

	/**
	 * @codeCoverageIgnore
	 * @see ApiBase::needsToken
	 */
	public function needsToken() {
		return 'csrf';
	}

	/**
	 * @codeCoverageIgnore
	 * @see ApiBase::mustBePosted
	 */
	public function mustBePosted() {
		return true;
	}

	/**
	 * @codeCoverageIgnore
	 * @see ApiBase::isWriteMode
	 */
	public function isWriteMode() {
		return true;
	}

	/**
	 * @codeCoverageIgnore
	 * @see ApiBase::getExamples
	 *
	 * @return array
	 */
	protected function getExamples() {
		return array(
			'api.php?action=smwtask&task=update&params={ "subject": "Foo" }',
		);
	}

	/**
	 * @codeCoverageIgnore
	 * @see ApiBase::getVersion
	 *
	 * @return string
	 */
	public function getVersion() {
		return __CLASS__ . ':' . SMW_VERSION;
	}

}
