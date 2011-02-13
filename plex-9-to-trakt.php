<?php

// This script adds your shows from Plex 9 to Trakt, including seen/unseen status.

define('PLEX_URL', 'http://<IP of your Plex 9 media server>:32400');
define('TRAKT_APIKEY', '<your Trakt.tv API key>');
define('TRAKT_USERNAME', '<your Trakt.tv username>');
define('TRAKT_PASSWORD', '<your Trakt.tv password>');

// DO NOT MODIFY ANYTHING BELOW THIS LINE.

echo("\n\n=== Starting the import. This may take some time. ===\n\n");

ini_set('memory_limit', '512M');
set_time_limit(6000);

$show_keys = array();
$shows = array();

// Get XML with shows from Plex.
$xml = simplexml_load_string(file_get_contents(PLEX_URL . '/library/sections/2/all'));

// Loop through shows and store keys in array.
foreach ($xml->Directory AS $value)
{
  $show_keys[] = (string) $value->attributes()->key;
}

// Loop through keys and get seasons.
foreach ($show_keys AS $key)
{
  $xml = simplexml_load_string(file_get_contents(PLEX_URL . $key));

  foreach ($xml->Directory AS $value)
  {
    if ((string) $value->attributes()->type == 'season')
    {
      $title = (string) $xml->attributes()->parentTitle;

      if (!isset($shows[$title]))
      {
        $shows[$title]->year = (integer) $xml->attributes()->parentYear;
        $shows[$title]->seasons = array();
      }

      $key = (string) $value->attributes()->key;
      $season_no = (string) trim(str_replace('season', '', strtolower($value->attributes()->title)));
      $shows[$title]->seasons[$season_no] = array();

      // Get the episodes for this season.
      $episodes_xml = simplexml_load_string(file_get_contents(PLEX_URL . $key));

      foreach ($episodes_xml->Video AS $episode)
      {
        if ((string) $episode->attributes()->type == 'episode')
        {
          $shows[$title]->seasons[$season_no][(integer) $episode->attributes()->index] = isset($episode->attributes()->viewCount) ? true : false;
        }
      }
    }
  }
}


// So now we have all shows with episodes, start year and watch status. Add them to Trakt (as library items if unwatched, otherwise as seen)!
echo("=== Found " . count($shows) ." shows, adding them to Trakt now. ===\n\n");

foreach ($shows AS $title => $value)
{
  echo("Adding {$title}\n");

  $data_watched = array();
  $data_unwatched = array();

  foreach ($value->seasons AS $season => $episodes)
  {
    foreach ($episodes AS $episode => $watched)
    {
      $ep->season = $season;
      $ep->episode = $episode;

      if ($watched)
      {
        $data_watched[] = $ep;
      }
      else
      {
        $data_unwatched[] = $ep;
      }

      unset($ep);
    }
  }

  if (count($data_watched) > 0)
  {
    $data->title = $title;
    $data->year = $value->year;
    $data->episodes = $data_watched;

    add_show_watched($data);

    unset($data);
  }

  if (count($data_unwatched) > 0)
  {
    $data->title = $title;
    $data->year = $value->year;
    $data->episodes = $data_unwatched;

    add_show_unwatched($data);

    unset($data);
  }
}

// Some function for recurring actions.
function add_show_watched($data)
{
  curl_post('http://api.trakt.tv/show/episode/seen/', $data);
}

function add_show_unwatched($data)
{
  curl_post('http://api.trakt.tv/show/episode/library/', $data);
}

function curl_post($url, $data)
{
  set_time_limit(30);

  $data->username = TRAKT_USERNAME;
  $data->password = sha1(TRAKT_PASSWORD);
  $data = json_encode($data);

  $ch = curl_init();
  curl_setopt_array($ch, array(
    CURLOPT_URL => $url . TRAKT_APIKEY,
    CURLOPT_POSTFIELDS => $data,
      CURLOPT_POST => 1,
      CURLOPT_RETURNTRANSFER => 1,
      CURLOPT_TIMEOUT => 0
    )
  );

  $return = curl_exec($ch);
  curl_close($ch);

  return $return;
}

?>