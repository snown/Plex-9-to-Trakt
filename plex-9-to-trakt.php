#!/usr/bin/env php
<?php

// This script adds your shows from Plex 9 to Trakt, including seen/unseen status.

define('PLEX_URL', 'http://<IP of your Plex 9 media server>:32400');
define('TRAKT_APIKEY', '<your Trakt.tv API key>');
define('TRAKT_USERNAME', '<your Trakt.tv username>');
define('TRAKT_PASSWORD', '<your Trakt.tv password>');

// DO NOT MODIFY ANYTHING BELOW THIS LINE.

echo("\n\n=== Starting the import. This may take some time. ===\n");

ini_set('memory_limit', '512M');
set_time_limit(6000);
// Load in command line options
$options = getargs(array("m" => "movies-only", "t" => "tv-only", "w" => "watched", "l" => "library", "d" => "debug"));

// Get XML with sections from Plex
$sections_xml = simplexml_load_string(file_get_contents(PLEX_URL . '/library/sections'));

// Loop through sections and store movie and show sections in proper array
$show_sections = array();
$movie_sections = array();

foreach($sections_xml->Directory AS $value)
{
    if ((string) $value->attributes()->type == 'show')
    {
        $show_sections[] = (string) $value->attributes()->key;
    }
    elseif ((string) $value->attributes()->type == 'movie')
    {
        $movie_sections[] = (string) $value->attributes()->key;
    }
}

// Loop through each section and parse out the correct data
if ($options["movies-only"] === false)
{
    foreach ($show_sections AS $key)
    {
        // Get XML with section's shows from Plex.
        $section_xml = simplexml_load_string(file_get_contents(PLEX_URL . '/library/sections/' . $key . '/all'));
        $section_title = (string) $section_xml->attributes()->title1;
        echo("\nParsing section \"" . $section_title . "\"...\n\n");
        parse_show_section($section_xml);
    }
}
if ($options["tv-only"] === false)
{
    foreach ($movie_sections AS $key)
    {
        // Get XML with section's movies from Plex.
        $section_xml = simplexml_load_string(file_get_contents(PLEX_URL . '/library/sections/' . $key . '/all'));
        $section_title = (string) $section_xml->attributes()->title1;
        echo("\nParsing section \"" . $section_xml->attributes()->title1 . "\"...\n\n");
        parse_movie_section($section_xml);
    }
}

echo("\n=== All Done! ===\n");

function parse_show_section($xml)
{
    global $options;
    $show_keys = array();
    $shows = array();

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
        
        if ($options["library"] === false)
        {
            add_show_watched($data);
        }
        else
        {
            add_show_unwatched($data);
        }

        unset($data);
      }

      if (($options["watched"] === false) && (count($data_unwatched) > 0))
      {
        $data->title = $title;
        $data->year = $value->year;
        $data->episodes = $data_unwatched;

        add_show_unwatched($data);

        unset($data);
      }
    }
}


function parse_movie_section($xml)
{
    global $options;
    $movie_keys = array();
    $movies = array();
    
    // Loop through movies and store keys in array.
    foreach ($xml->Video AS $value)
    {
        $movie_keys[] = (string) $value->attributes()->key;
    }
    
    // loop through keys and get movie data
    foreach ($movie_keys AS $key)
    {
        $xml = simplexml_load_string(file_get_contents(PLEX_URL . $key));
        
        foreach ($xml->Video AS $value)
        {
            if ((string) $value->attributes()->type == 'movie')
            {
                // Use Plex's ratingKey attribute as a unique identifier
                $puid = (integer) $value->attributes()->ratingKey;
                
                if (!isset($movies[$puid]))
                {
                    $movies[$puid]->title = (string) $value->attributes()->title;
                    $movies[$puid]->year = (integer) $value->attributes()->year;
                    
                    if (isset($value->attributes()->viewCount))
                    {
                        $movies[$puid]->plays = (integer) $value->attributes()->viewCount;
                        
                        if (isset($value->attributes()->lastViewedAt))
                        {
                            $movies[$puid]->last_played = (integer) $value->attributes()->lastViewedAt;
                        }
                    }
                    
                    // Try to determine an IMDB number off the guid attribute
                    if (isset($value->attributes()->guid))
                    {
                        $guid = (string) $value->attributes()->guid;
                        if (strpos($guid, "com.plexapp.agents.imdb") !== false)
                        {
                            // Determine the start and end of the IMDB number
                            $imdb_start = strpos($guid, "tt");
                            $imdb_end = strpos($guid, "?lang=");
                            
                            $movies[$puid]->imdb_id = (string) substr($guid, $imdb_start, $imdb_end - $imdb_start);
                        }
                    }
                    
                    echo("Found " . $movies[$puid]->title . " (" . $movies[$puid]->year . ")\n");
                }
            }
        }
    }
    
    echo("\n=== Found " . count($movies) . " movies");
    if (count($movies) > 0)
    {
        echo(", adding them to Trakt now. Please be patient, this may take some time ===\n\n");
        // So now we have all the movies. Add them to Trakt (as library items if unwatched, otherwise as seen)!
        $movies_watched = array();
        $movies_unwatched = array();
    
        foreach ($movies AS $value)
        {
            if (isset($value->plays))
            {
                $movies_watched[] = $value;
            }
            else
            {
                $movies_unwatched[] = $value;
            }
        }
    
        if (count($movies_watched) > 0)
        {
            $data->movies = $movies_watched;
            if ($options["library"] === false)
            {
                add_movies_watched($data);
            }
            else
            {
                add_movies_unwatched($data);
            }
        
            unset($data);
        }
    
        if (($options["watched"] === false) && (count($movies_unwatched) > 0))
        {
            $data->movies = $movies_unwatched;
            add_movies_unwatched($data);
        
            unset($data);
        }
    }
    else
    {
        echo(". ===\n\n");
    }
}

// Some function for recurring actions.
function add_show_watched($data)
{
    global $options;
    $response = curl_post('http://api.trakt.tv/show/episode/seen/', $data);
    
    if ($response == false)
    {
        echo("Communication Error when adding watched shows\n");
    } elseif ($options["debug"] === true)
    {
        echo("Response from Trakt: " . $response);
    }
}

function add_show_unwatched($data)
{
    global $options;
    $response = curl_post('http://api.trakt.tv/show/episode/library/', $data);
    
    if ($response == false)
    {
        echo("Communication Error when adding unwatched shows\n");
    } elseif ($options["debug"] === true)
    {
        echo("Response from Trakt: " . $response);
    }
}

function add_movies_watched($data)
{
    global $options;
    $response = curl_post('http://api.trakt.tv/movie/seen/', $data);
    
    if ($response == false)
    {
        echo("Communication Error when adding watched movies\n");
    } elseif ($options["debug"] === true)
    {
        echo("Response from Trakt: " . $response);
    }
}

function add_movies_unwatched($data)
{
    global $options;
    $response = curl_post('http://api.trakt.tv/movie/library/', $data);
    
    if ($response == false)
    {
        echo("Communication Error when adding unwatched movies\n");
    } elseif ($options["debug"] === true)
    {
        echo("Response from Trakt: " . $response);
    }
}

function curl_post($url, $data)
{
  set_time_limit(45);

  $data->username = TRAKT_USERNAME;
  $data->password = sha1(TRAKT_PASSWORD);
  $data = json_encode($data);
  
  $opt_array = array(
      CURLOPT_URL => $url . TRAKT_APIKEY,
      CURLOPT_POSTFIELDS => $data,
        CURLOPT_POST => 1,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_TIMEOUT => 0
      );

  $ch = curl_init();
  curl_setopt_array($ch, $opt_array);
  
  global $options;
  if ($options["debug"] === true)
  {
    echo("Message sent to Trakt:\n");
    print_r($opt_array);
  }

  $return = curl_exec($ch);
  curl_close($ch);

  return $return;
}

function getargs($arguments)
{
    $result_array = array();
    $sys_args = $_SERVER['argv'];
    array_shift($sys_args);
    
    foreach ($arguments as $key => $value)
    {
        if (in_array('-' . rtrim($key, ":"), $sys_args))
        {
            $match_position = array_search('-' . rtrim($key, ":"), $sys_args);
            
            if (stripos($key, ":") === false)
            {
                $result_array[$key] = true;
                $result_array[$value] = true;
                
                unset($sys_args[$match_position]);
            }
            else
            {
                $key = rtrim($key, ":");
                $value = rtrim($value, ":");
                
                $result_array[$key] = $sys_args[$match_position + 1];
                $result_array[$value] = $sys_args[$match_position + 1];
                
                unset($sys_args[$match_position]);
                unset($sys_args[$match_position + 1]);
            }
        }
        else if (in_array('--' . rtrim($value, ":"), $sys_args))
        {
            $match_position = array_search('--' . rtrim($value, ":"), $sys_args);
            
            if (stripos($value, ":") === false)
            {
                $result_array[$key] = true;
                $result_array[$value] = true;
                
                unset($sys_args[$match_position]);
            }
            else
            {
                $key = rtrim($key, ":");
                $value = rtrim($value, ":");
                
                $result_array[$key] = $sys_args[$match_position + 1];
                $result_array[$value] = $sys_args[$match_position + 1];
                
                unset($sys_args[$match_position]);
                unset($sys_args[$match_position + 1]);
            }
        }
        else
        {
            $key = rtrim($key, ":");
            $value = rtrim($value, ":");
            
            $result_array[$key] = false;
            $result_array[$value] = false;
        }
    }
    
    if (count($sys_args) > 0)
    {
        var_dump($sys_args);
    }
    
    return $result_array;
}

?>