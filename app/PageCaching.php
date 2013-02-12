<?php
/**
* Caching plugin
*
* @uses Zend_Controller_Plugin_Abstract
*/
class Efn_PageCaching extends Zend_Controller_Plugin_Abstract
{
	/**
	 *  @var bool Whether or not to disable caching
	 */
	public static $doNotCache = false;

	/**
	 * @var Zend_Cache_Frontend
	 */
	private $_cache = null;
	private $_key = null;
	private $_identity = null;
	private $_path = null;

	/**
	 * Constructor: initialize cache
	 *
	 * @param  array|Zend_Config $options
	 * @return void
	 * @throws Exception
	 */
	public function __construct( $cache )
	{
		$this->_cache = $cache;
	}

	public function getCache()
	{
		if ( ( $response = $this->_cache->load( $this->_key ) ) !== false )
			return $response;

		return false;
	}

	public function getLifetime() {
		$params = Zend_Registry::getInstance()->pagecacheParams;

		if ( isset( $_SESSION["invalidate_pagecache"] ) && ( $_SESSION["invalidate_pagecache"] == true ) ) {
			unset($_SESSION["invalidate_pagecache"]);
			return 0;
		}

		$lifetime = $params->frontendOptions->lifetime;
		if ( isset( $params->path ) ) {
			foreach ( array_keys( $params->path->toArray() ) as $path ) {
				if ( ( preg_match( '/^'.preg_replace( '/\*/', '.*', $path ).'/', $this->_path ) == 1 ) ) {
					if ( isset( $params->path->{$path}->lifetime ) ) {
						$lifetime = $params->path->{$path}->lifetime;
					}
					if ( isset( $params->path->{$path}->invalidate_next ) && $params->path->{$path}->invalidate_next == true ) {
						$_SESSION["invalidate_pagecache"] = true;
						$lifetime = 0;
					}
				}
			}
		}

		return $lifetime;
	}

	/**
	 * Start caching
	 *
	 * Determine if we have a cache hit. If so, return the response; else,
	 * start caching.
	 *
	 * @param  Zend_Controller_Request_Abstract $request
	 * @return void
	 */
	public function dispatchLoopStartup( Zend_Controller_Request_Abstract $request )
	{
		$this->_path = str_replace( '/', '_', $request->getPathInfo() );

		if ( ( !$request->isGet() ) || ( $this->getLifetime() == 0 ) ) {
			self::$doNotCache = true;
			return;
		}

		if ( Zend_Auth::getInstance()->hasIdentity() )
			$this->_identity = 'logged-in';//Zend_Auth::getInstance()->getIdentity();

		$this->_key =
			md5( serialize( $request ) ) .
			( !is_null( $this->_identity ) ? '_' . md5( serialize( $this->_identity ) ) : null );

		if ( ( $response = $this->getCache() ) !== false ) {
			var_dump('found');
			$response->sendResponse();
			exit;
		}
	}

	/**
	 * Store cache
	 *
	 * @return void
	 */
	public function dispatchLoopShutdown()
	{
		$response = $this->getResponse();

		if ( self::$doNotCache || $response->isRedirect() || ( null === $this->_key ) ){
			return;
		}

		//check if memcache item ignore exists...if doesnt save...else dont save just remove ignore
		if ( ( $ignore = $this->_cache->load( 'ignore' ) ) !== 'TRUE' ) {
			$this->_cache->save( $response, $this->_key, array(), $this->getLifetime() );
		} else {
			$this->_cache->save( '', 'ignore' , array(), '100' );
		}

	}


	private function _getLinks( $html )
	{
		require(dirname(__FILE__).'/../phpQuery/phpQuery.php');

		$host = $_SERVER['HTTP_HOST'];
		$doc = phpQuery::newDocument( $html );

		$links = array();
		foreach ( $doc['body a[href^=http://'.$host.']'] as $linkNode )
			$links[] = $linkNode->getAttribute( 'href' );

		return $links;
	}

	public static function excludeFromCache()
	{
		$cache = Zend_Registry::getInstance()->pagecache;
		$cache->save( 'TRUE', 'ignore' , array(), '100' );

	}



}


























