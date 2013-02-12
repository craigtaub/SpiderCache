<?php

require_once 'Zend/Application/Bootstrap/Bootstrap.php';

class Bootstrap extends Zend_Application_Bootstrap_Bootstrap
{

    /**
     * Initialise Cache config
     *
     * @return array
     */
    protected function _initCacheConfig()
    {
        if (extension_loaded('igbinary')) {
            Zend_Serializer::setDefaultAdapter('Igbinary');
        }

        $confCache = new Zend_Config_Ini(
            APPLICATION_PATH . '/configs/cache.ini',
            APPLICATION_ENV
        );

        $this->_methodOrder[] = "_initCacheConfig()";

        return $confCache;
    }

    /**
     * Cache
     *
     * @return Zend_Cache_Core
     */
    protected function _initCache()
    {
        $this->bootstrap('cacheConfig');

        $cacheConfig = $this->getResource('cacheConfig');

        Zend_Registry::getInstance()->memcache = Zend_Cache::factory(
            $cacheConfig->segment->frontend,
            $cacheConfig->segment->backend,
            $cacheConfig->segment->frontendOptions->toArray(),
            $cacheConfig->segment->backendOptions->toArray()
        );


        Zend_Registry::getInstance()->pagecacheParams = $cacheConfig->page;
        Zend_Registry::getInstance()->pagecache = Zend_Cache::factory(
                $cacheConfig->page->frontend,
                $cacheConfig->page->backend,
                $cacheConfig->page->frontendOptions->toArray(),
                $cacheConfig->page->backendOptions->toArray()
        );

        Zend_Registry::getInstance()->sessionParams = $cacheConfig->session;
        Zend_Registry::getInstance()->session = Zend_Cache::factory(
                $cacheConfig->session->frontend,
                $cacheConfig->session->backend,
                $cacheConfig->session->frontendOptions->toArray(),
                $cacheConfig->session->backendOptions->toArray()
        );

        return Zend_Registry::getInstance()->memcache;
    }



    protected function _initGearmanWorker()
    {
        //new future caching idea:
        //page cache to use guest and loggedin as keys (no more key unique to user)
        //page to use ajax scatter loading for top/flash-messages/ect
        //page cache to use spider cache
        //-foreach crawled link found, check (guest/logged) key in pagecache
        //now 1 logged-in and 1 non-logged-in user on 1 page (2) will cache all pages for others.
        //all pages will stay in page cache for 5 mins...5 seconds to 800 ms

        //now:
        //keep unique keys and guest
        //gearman grabs a-links for page, checks if each one is in cache already (HARD)
        //if not crawl that one which puts it into cache.
        //HARD-gearman needs to generate  request and identity obejcts for key which
        //are dont using zend classes.
        //HEAVY-for every page (300 everytime) do 300 gets to memcache, 
        //every person, on every page load...until 1 persons returns 0 then curls all of them

        #gearmand -d
        #sudo /etc/init.d/gearman-job-server restart
        #sudo /etc/init.d/memcached restart       
        #php sudo php /home/taubc/scripts/gearman/reverse_worker.php

        //everytime does this before checks pagecache...
        //so shud ALWAYS have in page cache AFTER first page load which may not
        //works processing 1 page at a time and NOT reporter ajax call 

        return;

        if (!isset($_SERVER["HTTP_SPIDERCACHE"])){

            # create the gearman client
            $gmc= new GearmanClient();

            # add the default server (localhost)
            $gmc->addServer();

            $url = $_SERVER['REQUEST_URI'];
            $session_id = $_COOKIE['PHPSESSID'];
            $request = Zend_Controller_Front::getInstance()->getRequest();

/*
$objectA = serialize($request);
//var_dump($objectA);
$set_url = '\?mod=mainnav';
//$new_url = '\?mod=mainnav';
$new_url = 'aboutus?mod=mainnav';
$objectB = preg_replace('/'.$set_url.'/', $new_url, $objectA);
*/

/*
 $request = //the object

 $reflectionClass = new ReflectionClass('Zend_Controller_Request_Http');

 $reflectionProperty = $reflectionClass->getProperty('_requestUri');
 $reflectionProperty->setAccessible(true);

 $reflectionClass->getProperty('_requestUri')->setValue('newUri');
//$method->setAccessible(true);
*/
 /*
 var_dump($objectA);
var_dump($objectB);
var_dump('<pre>');
var_dump(unserialize($objectB));
var_dump('</pre>');

die();

   //var_dump('<pre>');
  //var_dump($request);
  //var_dump('</pre>');

$reflecRequest = new ReflectionObject($request);
$reflecRequestProp = $reflecRequest->getProperty('_requestUri');
$reflecRequestProp->setAccessible(true);
$reflecRequestProp->setValue($reflecRequest, 'newUri');
  //var_dump('<pre>');
  //var_dump($reflecRequest);
  //var_dump('</pre>');
  die();
*/

            $identity = Zend_Auth::getInstance()->getIdentity();

            $mydata['url']=$url;
            $mydata['session_id']=$session_id;
            $mydata['pagecache_key'] = md5( serialize( $request ) ) .
            ( !is_null( $identity ) ? '_' . md5( serialize( $identity ) ) : null );
            $mydata['request'] = serialize($request) ;
            $mydata['identity']=serialize($identity);

            $mydata = serialize($mydata);


            //run this in parallel to rest of fno code (unlike doTask())
            $task= $gmc->doBackground("spider", $mydata); 

            # run the tasks in parallel (assuming multiple workers)
            if (! $gmc->runTasks())
            {
                //echo "ERROR " . $gmc->error() . "\n";
                exit;
            }

            //echo "DONE\n";
        }
    }













    /**
     * Page Caching for FNO.
     * if on homepage on ipad, check if has required cookie and if not, dont use page cache
     * NO ipad-homepage without the required cookie will ever be cached.
     *
     * @return void
     */
    protected function _initPageCaching()
    {
        $this->_methodOrder[] = "_initPageCaching()";

        if ( !isset( $_SERVER['HTTP_USER_AGENT'] ) )
            $_SERVER['HTTP_USER_AGENT'] = 'no-agent';

        //if on IE6, cant use page cache
        if (strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE 6.') !== FALSE) {
            return;
        } else {

            $ipadUserAgent = new Fno_My_UserAgent_Ipad();
            $request = Zend_Controller_Front::getInstance()->getRequest();
            Zend_Controller_Front::getInstance()->getRouter()->route($request);
            $currentRoute = $request->getActionName();

            if (($currentRoute == 'home') && ($ipadUserAgent->isValidDevice($request->getServer('HTTP_USER_AGENT')))) {
                if (isset($_COOKIE['ipad-app-promotion-new'])){
                    $front = Zend_Controller_Front::getInstance();
                    $cacheConfig = $this->getResource('cacheConfig');
                    $front->registerPlugin(new Efn_PageCaching( Zend_Registry::getInstance()->pagecache ) );
                }
            } else {
                $front = Zend_Controller_Front::getInstance();
                $cacheConfig = $this->getResource('cacheConfig');
                $front->registerPlugin(new Efn_PageCaching( Zend_Registry::getInstance()->pagecache ) );
            }

        }
    }

}
