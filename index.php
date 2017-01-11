<?php

require_once __DIR__.'/vendor/autoload.php';

use Mediawiki\Api\FluentRequest;
use Mediawiki\Api\MediawikiApi;
use Mediawiki\Api\UsageException;
use SparQL\Connection;
use Symfony\Component\HttpFoundation\Request;

//TODO image licence should be url
//TODO better types and mapping to schema.org
//TODO returning multiple results

error_reporting( -1 );
ini_set( 'display_errors', 1 );

$app = new Silex\Application();

$app->get('/v1/entities:search', function( Request $request ) use( $app ) {
	$queryString = $request->getQueryString();
	$query = $request->get( 'query' );
	// TODO get ids form queryString
	$ids = [];
	if( $request->get( 'ids' ) ) {
		$ids = [ $request->get( 'ids' ) ];
	}
	//TODO fix getting languages
	$languages = [];
	if( $request->get( 'languages' ) ) {
		$languages = [ $request->get( 'languages' ) ];
	}
	// TODO fix getting types
	$types = [];
	if( $request->get( 'types' ) ) {
		$types = [ $request->get( 'types' ) ];
	}
	$indent = $request->get( 'indent', false );
	$prefix = $request->get( 'prefix', false );
	$limit = $request->get( 'limit', 1 );

	if( $query === null ) {
		die( 'oh noes! no query!' );
	}
	if( $prefix === false ) {
		die( 'oh noes! cant do non prefix search!' );
	}
	if( $limit >5 ) {
		die( 'oh noes! limit can\'t be greater than 5!' );
	}

	try {
		$wd = new MediawikiApi( 'https://www.wikidata.org/w/api.php' );
		$response = $wd->getRequest(
			FluentRequest::factory()->addParams(
				[
					'action' => 'wbsearchentities',
					'search' => $query,
					//TODO actually pass in languages?
					'language' => 'en',
					'limit' => $limit,
					//TODO add prefix / non prefix to wbsearchentities
				]
			)
		);
	}
	catch( UsageException $e ) {
		die( 'UsageException: ' . $e->getMessage() );
	}

	$entities = $response['search'];
	$entityIds = [];
	foreach( $entities as $entity ) {
		$entityIds[] = $entity['id'];
	}

	//TODO allow more entities
	$entityId = $entityIds[0];

	try {

		$sparql = new Connection( "http://query.wikidata.org/sparql" );
		//TOOD dont hardcode language
		//TODO dont hardcode site!
		$sparqlQuery
			= "
PREFIX wd: <http://www.wikidata.org/entity/>
PREFIX wdt: <http://www.wikidata.org/prop/direct/>
PREFIX wikibase: <http://wikiba.se/ontology#>
PREFIX p: <http://www.wikidata.org/prop/>
PREFIX ps: <http://www.wikidata.org/prop/statement/>
PREFIX pq: <http://www.wikidata.org/prop/qualifier/>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX bd: <http://www.bigdata.com/rdf#>

SELECT ?label ?description ?image ?instanceOf ?instanceOfLabel ?sitelink ?url
WHERE
{
	wd:$entityId rdfs:label ?label .
  FILTER(LANG(?label) = \"en\").
	wd:$entityId schema:description ?description .
  FILTER(LANG(?description) = \"en\").
  wd:$entityId wdt:P18 ?image .
  wd:$entityId wdt:P856 ?url .
  wd:$entityId wdt:P31 ?instanceOf .
  SERVICE wikibase:label { bd:serviceParam wikibase:language \"en\" . }
  OPTIONAL {
  ?sitelink schema:about wd:Q64 ;
  schema:isPartOf <https://en.wikipedia.org/> .
  }
  
}
";
		$sparqlResult = $sparql->query( $sparqlQuery );
		$sparqlArray = $sparqlResult->fetchArray();
	}
	catch( Exception $e ) {
		die( 'SPARQL ERROR: ' . $e->getMessage() );
	}

	$json = [
		'@context' => [
			'@vocab' => 'http://schema.org/',
			'goog' => 'http://schema.googleapis.com/',
			'EntitySearchResult' => 'goog:EntitySearchResult',
			'detailedDescription' => 'goog:detailedDescription',
			'kg' => 'http://g.co/kg',
		],
		'@type' => 'ItemList',
		'itemListElement' => [
			'@type' => 'EntitySearchResult',
			'result' => [
				'@id' => $entityId,
				'name' => $sparqlArray['label'],
				'@type' => [
					'Thing',
					// TODO better type here
					ucfirst( $sparqlArray['instanceOfLabel'] )
				],
				'description' => $sparqlArray['description'],
				'image' => [
					'contentUrl' => $sparqlArray['image'],
					'url' =>
						'http://commons.wikimedia.org/wiki/File:' .
						explode('Special:FilePath/', $sparqlArray['image'])[1],
					'license' => end(
						( new MediawikiApi(
							'https://commons.wikimedia.org/w/api.php'
						)
					)->getRequest( FluentRequest::factory()->addParams([
						'action' => 'query',
						'prop' => 'imageinfo',
						'iiprop' => 'extmetadata',
						'titles' =>
							'File:' .
							urldecode(explode('Special:FilePath/', $sparqlArray['image'])[1]),
					]) )['query']['pages'])['imageinfo'][0]['extmetadata']['LicenseShortName']['value'],
				],
				'detailedDescription' => [
					'articleBody' => end(
						(
						new MediawikiApi(
							( explode( '/wiki/', $sparqlArray['sitelink'] )[0] ) . '/w/api.php'
						)
						)->getRequest(
							FluentRequest::factory()->addParams(
								[
									'action' => 'query',
									'prop' => 'extracts',
									'exchars' => '175',
									'explaintext' => '1',
									'titles' => ( explode(
										'/wiki/',
										$sparqlArray['sitelink']
									)[1] ),
								]
							)
						)['query']['pages']
					)['extract'],

					'url' => $sparqlArray['sitelink'],
					'license' => 'https://creativecommons.org/licenses/by-sa/3.0/',
				],
				'url' => $sparqlArray['url'],
			],
		],
	];

	if ( $indent ) {
		return '<pre>' . json_encode( $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . '</pre>';
	}
	return json_encode( $json );
});

$app->run();