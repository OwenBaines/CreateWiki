<?php

use MediaWiki\MediaWikiServices;
use Wikimedia\AtEase\AtEase;

class CreateWikiJson {
	private $config;
	private $dbr;
	private $cache;
	private $wiki;
	private $databaseArray;
	private $wikiArray;
	private $cacheDir;
	private $databaseTimestamp;
	private $wikiTimestamp;
	private $initTime;

	public function __construct( string $wiki ) {
		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'createwiki' );
		$this->dbr = wfGetDB( DB_REPLICA, [], $this->config->get( 'CreateWikiDatabase' ) );
		$this->cache = ObjectCache::getLocalClusterInstance();
		$this->cacheDir = $this->config->get( 'CreateWikiCacheDirectory' );
		$this->wiki = $wiki;

		AtEase::suppressWarnings();
		$this->databaseArray = json_decode( file_get_contents( $this->cacheDir . '/databases.json' ), true );
		$this->databaseTimestamp = (int)$this->cache->get( $this->cache->makeGlobalKey( 'CreateWiki', 'databases' ) );
		$this->wikiArray = json_decode( file_get_contents( $this->cacheDir . '/' . $wiki . '.json' ), true );
		$this->wikiTimestamp = (int)$this->cache->get( $this->cache->makeGlobalKey( 'CreateWiki', $wiki ) );
		AtEase::restoreWarnings();

		$this->initTime = $this->dbr->timestamp();

		if ( !$this->databaseTimestamp ) {
			$this->resetDatabaseList();
		}

		if ( !$this->wikiTimestamp ) {
			$this->resetWiki();
		}
	}

	public function resetWiki() {
		$this->cache->set( $this->cache->makeGlobalKey( 'CreateWiki', $this->wiki ), $this->initTime );

		// Rather than destroy object, let's fake the cache timestamp
		$this->wikiTimestamp = $this->initTime;
	}

	public function resetDatabaseList() {
		$this->cache->set( $this->cache->makeGlobalKey( 'CreateWiki', 'databases' ), $this->initTime );

		// Rather than destroy object, let's fake the catch timestamp
		$this->databaseTimestamp = $this->initTime;
	}

	public function update() {
		$changes = $this->newChanges();

		if ( $changes['databases'] ) {
			$this->generateDatabaseList();
		}

		if ( $changes['wiki'] ) {
			$this->generateWiki();
		}
	}

	private function generateDatabaseList() {
		$allWikis = $this->dbr->select(
			'cw_wikis',
			[
				'wiki_dbcluster',
				'wiki_dbname',
				'wiki_deleted',
				'wiki_url',
				'wiki_sitename'
			]
		);

		$combiList = [];
		$deletedList = [];

		foreach ( $allWikis as $wiki ) {
			if ( $wiki->wiki_deleted == 1 ) {
				$deletedList[$wiki->wiki_dbname] = [
					's' => $wiki->wiki_sitename,
					'c' => $wiki->wiki_dbcluster
				];
			} else {
				$combiList[$wiki->wiki_dbname] = [
					's' => $wiki->wiki_sitename,
					'c' => $wiki->wiki_dbcluster
				];

				if ( !is_null( $wiki->wiki_url ) ) {
					$combiList[$wiki->wiki_dbname]['u'] = $wiki->wiki_url;
				}
			}
		}

		$dbJson = file_put_contents( "{$this->cacheDir}/databases.json.tmp", json_encode( [ 'timestamp' => $this->databaseTimestamp, 'combi' => $combiList ] ), LOCK_EX );
		$deletedJson = file_put_contents( "{$this->cacheDir}/deleted.json.tmp", json_encode( [ 'timestamp' => $this->databaseTimestamp, 'databases' => $deletedList ] ), LOCK_EX );

		if ( $dbJson ) {
			rename( "{$this->cacheDir}/databases.json.tmp", "{$this->cacheDir}/databases.json" );
		}
		if ( $deletedJson ) {
			rename( "{$this->cacheDir}/deleted.json.tmp", "{$this->cacheDir}/deleted.json" );
		}
	}

	private function generateWiki() {
		$wikiObject = $this->dbr->selectRow(
			'cw_wikis',
			'*',
			[
				'wiki_dbname' => $this->wiki
			]
		);

		if ( !$wikiObject ) {
			throw new MWException( 'Wiki can not be found.' );
		}

		$jsonArray = [
			'timestamp' => ( file_exists( $this->cacheDir . '/' . $this->wiki . '.json' ) ) ? $this->wikiTimestamp : 0,
			'database' => $wikiObject->wiki_dbname,
			'created' => $wikiObject->wiki_creation,
			'dbcluster' => $wikiObject->wiki_dbcluster,
			'category' => $wikiObject->wiki_category,
			'url' => $wikiObject->wiki_url ?? false,
			'core' => [
				'wgSitename' => $wikiObject->wiki_sitename,
				'wgLanguageCode' => $wikiObject->wiki_language
			],
			'states' => [
				'private' => (bool)$wikiObject->wiki_private,
				'closed' => $wikiObject->wiki_closed_timestamp ?? false,
				'inactive' => ( $wikiObject->wiki_inactive_exempt ) ? 'exempt' : ( $wikiObject->wiki_inactive_timestamp ?? false )
			]
		];

		Hooks::run( 'CreateWikiJsonBuilder', [ $this->wiki, $this->dbr, &$jsonArray ] );

		$wikiJson = file_put_contents( "{$this->cacheDir}/{$this->wiki}.json.tmp", json_encode( $jsonArray ), LOCK_EX );

		if ( $wikiJson ) {
			rename( "{$this->cacheDir}/{$this->wiki}.json.tmp", "{$this->cacheDir}/{$this->wiki}.json" );
		}
	}

	private function newChanges() {
		$changes = [
			'databases' => false,
			'wiki' => false
		];

		if ( $this->databaseArray['timestamp'] < ( ( $this->databaseTimestamp ) ? $this->databaseTimestamp : PHP_INT_MAX ) ) {
			$changes['databases'] = true;
		}

		if ( $this->wikiArray['timestamp'] < ( ( $this->wikiTimestamp ) ? $this->wikiTimestamp : PHP_INT_MAX ) ) {
			$changes['wiki'] = true;
		}

		return $changes;
	}
}

