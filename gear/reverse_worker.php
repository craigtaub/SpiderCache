<?php

echo "Starting\n";

# Create our worker object.
$gmworker= new GearmanWorker();

# Add default server (localhost).
$gmworker->addServer();

$gmworker->addFunction("spider", "spider_cache");

print "Waiting for job...\n";
while($gmworker->work())
{
  if ($gmworker->returnCode() != GEARMAN_SUCCESS)
  {
    echo "return_code: " . $gmworker->returnCode() . "\n";
    break;
  }
}



function spider_cache($job)
{
  echo "Received job: " . $job->handle() . "\n";

  $data = unserialize($job->workload());
  echo "Data unserialized \n";

  //var_dump('<pre>');
  //var_dump(unserialize($data['request']));
  //var_dump('</pre>');
  //die();

  $url = $data['url'];
  $session_id = $data['session_id'];

  //current page doesnt matter if in page cache, need it to get/set others

  echo "url: ".$data['url']."\n";
  //print_r($data['session_id'].'-'.$data['url']);

  if (strpos($url,'/proxy/reporter-write') === false) {
    if (strpos($url,'get-data-explorers') === false) {
      custom_curl($url,$session_id, $data);
      //print_r($data['session_id'].'-'.$data['url']);
    }
  }


  # Return what we want to send back to the client.
  echo "Returned\n";
  $result = 'Returned';

  return $result;
}


function custom_curl($url, $session_id, $mydata){

  echo "run custom_curl() \n";
  //current_url and session_id req
  $url= 'http://10.8.0.6'.$url;

  $ch = curl_init();
  //sessionid used to authenticate user.
  curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Content-type: text/html',
    "Cookie: PHPSESSID=$session_id;",
    'SPIDERCACHE: true',
    ));
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  echo "execute initial curl (if not in pagecache will be slow, same for pages) \n";
  $data = curl_exec($ch);

  grab_links($ch, $data, $session_id, $mydata);

  
}

function grab_links($ch, $data, $session_id, $mydata){

  echo "run grab_links() \n";
  $url_array = array();
  //put into array first to remove duplicates
  $bad_array = array('http://www.penews.com',
              'http://www.wsj.com',
              'http://www.efinancialnewsevents.com',
              'http://www.fins.com',
              'http://www.marketwatch.com',
              'http://online.barrons.com',
              'http://bigcharts.marketwatch.com',
              'http://allthingsd.com');

  $good_array = array('/assetmanagement/',
    '/investmentbanking/',
    '/privateequity/',
    '/tradingandtechnology/',
    '/people/');
    //'/story/');

  $data = strip_tags($data,"<a>");
  $d = preg_split("/<\/a>/",$data);
  //up to now done everything only once per person
  foreach ( $d as $k=>$u ){
    if( strpos($u, "<a href=") !== FALSE ){
        $u = preg_replace("/.*<a\s+href=\"/sm","",$u);
        $u = preg_replace("/\".*/","",$u);

        if(!in_array($u, $bad_array)) {
          foreach($good_array as $good_string) {
            if(preg_match($good_string, $u)){
                //if url contains anything from good urls, use the url
                $url_array[]=$u;
            }
          }
          //$url_array[]=$u;
          
        }
    }
  }
  echo "now remove dupes from array \n";
  $url_array = array_unique($url_array);
  echo "removed dupes from array \n";
  echo 'count: '.count($url_array)."\n"; 
  //roughly 280 per page, 222 good_array, 90 remove story & dupes

  echo "removed dupes from array \n";
  foreach($url_array as $url) {
      curl_grabbed_link($ch, $url, $session_id, $mydata);
      echo $url."-grabbed\n";
  }
}

function curl_grabbed_link($ch, $url, $session_id, $mydata){
  //current_url and session_id req

//check_in_pagecache($url, $mydata);

  //$ch = curl_init();
  curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Content-type: text/html',
    'Cookie: PHPSESSID='.$session_id,
    'SPIDERCACHE: true',
    ));
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  //dont need response...
  //put back stopped it printing
  curl_exec($ch);

}

function check_in_pagecache($url, $mydata){


  $url = str_replace('http://10.8.0.6','',$url);
  //build request object changing requestUri
  $request = unserialize($mydata['request']);
  //$request->setRequestUri($url);
$request->_RequestUri = 'fff';
  var_dump('<pre>');
  var_dump($request);
  var_dump('</pre>');

  /*
  $objectA = serialize($request);
//var_dump($objectA);
$set_url = '\?mod=mainnav';
$new_url = 'aboutus?mod=mainnav';
$objectB = preg_replace('/'.$set_url.'/', $new_url, $objectA);
*/
  die();

  //build identity object from what user send initially
  $identity= $mydata['identity'];

  $pagecache_key = md5( serialize( $request ) ) .
            ( !is_null( $identity ) ? '_' . md5( serialize( $identity ) ) : null );

  $memcache = new Memcache;
  $memcache->connect('localhost', 11211) or die ("Could not connect");

  $cache_result = $memcache->get($pagecache_key); 

  if($cache_result)
  {
    echo $url."-FALSE \n";
      return false;
  } else {
    echo $url."-TRUE \n";
    return true;
  }

}

?>
