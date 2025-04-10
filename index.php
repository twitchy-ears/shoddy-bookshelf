<?php 

set_time_limit(300);
# error_reporting(E_ALL);
# ini_set('display_errors', '1');

# The books directory is presumed to be a calibre library in the form
# of
# "books/Author Name/Book/"
#
# The output_dir defaults to html/ and is where copies of epub files
# are put after conversion with pandoc
$library_dir = "books";
$calibre_db = "$library_dir/metadata.db";
$pdo = NULL;
$output_dir = "html";
$pandoc_bin = "/usr/bin/pandoc";

# Should include an ignore_filters array of /foo/ patterns which are
# tested against metadata but not tags.
$filter_file = "filter.php";
$ignore_filters = array();
$ignore_tags = array();
if (file_exists($filter_file)) {
  include_once($filter_file);
}

# pandoc -f epub -t html -o index.html file.epub --self-contained

# Takes a target metadata.opf file and a wanted extension (like
# 'epub') and attempts to find a version of the book in that format.
function get_bookfile_from_metadata($target, $extension) {
  # print "Checking $target<br />";
  if (file_exists($target)) {
    $dir = preg_replace("/\/metadata.opf$/", "", $target);
    if (is_dir($dir)) {
      $dh = opendir($dir);
      while ($item = readdir($dh)) {
        $path = $dir . '/' . $item;
        if (preg_match("/\." . $extension . "$/i", $item) && file_exists($path)) {
          return $path;
        }
      }
    }
  }
}

# Takes a target metadata.opf file and returns an existing epub file if one exists.
function get_epub_from_metadata($target) {
  return get_bookfile_from_metadata($target, 'epub');
}

# Takes a target metadata.opf file and looks for likely book cover files.
function get_cover_from_metadata($target) {
  if (file_exists($target)) {
    $dir = preg_replace("/\/metadata.opf$/", "", $target);
    if (is_dir($dir)) {
      $dh = opendir($dir);
      while ($item = readdir($dh)) {
        $path = $dir . '/' . $item;
        if (preg_match("/^cover.(png|jpg|jpeg)$/i", $item) && file_exists($path)) {
          return $path;
        }
      }
    }
  }
}


# Searches the books/ directory and returns an array of metadata.opf files
function generate_metadata_file_list($target) {
  $metadata_files = array();

  if (! is_dir($target)) {
    return $metadata_files;
  }
  
  $lib = opendir($target);
  while($item = readdir($lib)) {
    # print "$item<br />\n";
    $author_dirpath = $target . '/' . $item;

    # If we have a non . start directory open it
    if (! preg_match('/^\./', $item) && is_dir($author_dirpath)) {
      $author_dh = opendir($author_dirpath);

      # Assume that any directories not starting with . are books
      while ($bookdir = readdir($author_dh)) {
	      if (! preg_match('/^\./', $bookdir) && is_dir($author_dirpath)) {
	        $book_dirpath = $author_dirpath . '/' . $bookdir;

	        # Look up if we have a metadata file for each book
	        $meta_path = $book_dirpath . '/' . "metadata.opf";
	        if (file_exists($meta_path)) {
	          # print "Considering '$meta_path'<br />\n";
	          $metadata_files[$meta_path] = 1;
	        }

	      }
      }
    }
  }

  ksort($metadata_files);
  return $metadata_files;
}

# Takes a target epub file from the books directory and generates the
# location of where an HTML version should be put in the html output
# directory
function ebook_to_html_file ($target) {
  global $output_dir;

  $basename = basename($target);
  $out_file = preg_replace('/.epub$/i', '.html', $basename);
  $out_file = preg_replace('/[^a-z0-9 _\.-]/i', '', $out_file);

  $output_path = $output_dir . '/' . $out_file;
  
  return $output_path;
}

# Connects to the calibre library database, if already connected
# returns the existing connection.
function connect_to_db() {
  global $pdo;
  global $calibre_db;

  if (! is_null($pdo)) {
    return $pdo;
  }


  $dsn = "sqlite:$calibre_db";

  try {
    $pdo = new \PDO($dsn);
  } catch (\PDOException $e) {
    echo $e->getMessage();
  }

  return $pdo;
}

# Takes a book id number and fetches the books/ subdirectory associated.
function book_id_to_dir($id) {
  global $calibre_db;
  global $library_dir;

  $pdo = connect_to_db();

  $statement = $pdo->prepare('select * from books where id = :id;');
  $statement->bindValue(":id", $id);
  $book_data = $statement->execute();

  $path = NULL;
  while ($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
    if (isset($row["path"])) {
      $path = $library_dir . '/' . $row["path"];
      if (is_dir($path)) {
        return $path;
      }
    }
  }

  return $path;
}

# Looks in a target directory $dir to build a list of possible likely
# book files, then tries to return a epub if possible
function find_books_in_dir ($dir, $first = TRUE) {
  if (! is_dir($dir)) {
    # print "Not a dir '$dir'<br />";
    return FALSE;
  }

  $choices = array();
  
  $dh = opendir($dir);
  while ($item = readdir($dh)) {
    # print "Considering $item<br />";
    if (! preg_match('/^\./', $item) 
        && preg_match('/\.(pdf|epub|mobi|azw3)$/i', $item, $matches))
    {
      $type = $matches[0];
      $path = $dir . '/' . $item;
      $choices[$type] = $path;

      # print "$path is a $type<br />";
    }
  }

  if ($first == FALSE) {
    return array_values($choices);
  }

  # $preferences = array( '.epub', '.mobi', '.azw3', '.pdf' );
  $preferences = array( '.epub' );
  foreach ($preferences as $pref) {
    if (isset($choices[$pref])) {
      return $choices[$pref];
    }
  }

  return NULL;
}

# Takes a target epub book file from books/ and invokes pandoc to
# convert it to an HTML file.  If there is already an HTML file
# returns that, if the book file is newer than the HTML file it will
# delete the HTML file and regenerate it.
function generate_book($target) {
  global $pandoc_bin;
  global $output_dir;
  
  if (! file_exists($target)) {
    print "File '$target' doesn't exist, can't generate HTML version<br />\n";
    return FALSE;
  }

  if (! file_exists($pandoc_bin)) {
    print "Pandoc binary '$pandoc_bin' doesn't exist, can't convert '$target'<br />\n";
    return FALSE;
  }

  if (! is_dir($output_dir)) {
    print "Cannot locate output directory '$output_dir', can't convert '$target'<br />\n";
    return FALSE;
  }

  if (! is_writeable($output_dir)) {
    print "Cannot write to '$output_dir' so can't convert '$target'<br />\n";
    return FALSE;
  }

  $output_path = ebook_to_html_file($target);

  if (file_exists($output_path) 
    && filemtime($target) > filemtime($output_path)) 
  {
    print "Converted book exists, but source is newer, attempting to delete old copy to refresh<br />";
    unlink($output_path);
  }
  /* else {
    print "file exists? " . file_exists($output_path) . "<br />";
    print "target mtime<br />";
    print filemtime($target) . "<br />";
    print "output mtime<br />";
    print filemtime($output_path) . "<br />";
  }
   */

  if (! file_exists($output_path)) {
    $cmd = "$pandoc_bin -f epub -t html -o '$output_path' '$target' --standalone --self-contained --table-of-contents";
    print "$cmd<br />\n";

    exec($cmd, $out, $ret);
    if ($ret == 0) {
      print "Successfully converted '$target' to '$output_path'<br />\n";
    }
    else {
      print "Conversion attempt returned '$ret' said: <br />\n";
      print "<pre>";
      print_r($out);
      print "</pre>\n";
    }
  }

  return $output_path;
}

# Its not for security its for letting dots exist in regexps.
function escape_str($str) {
  return preg_replace('/\./', '\.', $str);
}




# Get criteria, what are we doing?
$search = "";
$title_suffix = "";
if (isset($_GET["search"])) {
  if ($_GET["search"] == 'NO_REALLY_ALL_BOOKS') {
    $search = '.*';
    $title_suffix = "All Books";
  }
  else {
    $search = preg_replace('/[^a-z0-9 _\(\)-\.]/i', "", $_GET["search"]);
    $title_suffix = "Search $search";
  }
}

$tag_dump = false;
if (isset($_GET["tag_dump"])) {
  $tag_dump = true;
  $title_suffix = "Showing all tags";
}

$author_dump = false;
if (isset($_GET["author_dump"])) {
  $author_dump = true;
  $title_suffix = "Showing all authors";
}

$convert_book = "";
if (isset($_GET["convert_book"])) {
  $convert_book = preg_replace('/[^\d]/i', "", $_GET["convert_book"]);
  $title_suffix = "Converting book ID $convert_book";
}


# Start the output now we know what we're doing.
print "<html><head><title>Shoddy Bookshelf $title_suffix</title></head><body>";



# Convert the book identified by the id number $id, this exec calls to
# pandoc and there is a very good chance there is a security risk here.
if (strlen($convert_book) > 0) {
  $id = $convert_book;
  $book_dir = book_id_to_dir($id);
  $book_source = find_books_in_dir($book_dir, TRUE);
  $bookfile = generate_book($book_source);
  if (file_exists($bookfile)) {
    print "<br /><br />\nRead: <a href=\"$bookfile\">$bookfile</a><br /><br />\n";
  }
}

# Output all the tags as clickable links
elseif ($tag_dump) {
  print "Listing all tags<br /><hr /><br />\n";

  if (! file_exists($library_dir) || ! is_dir($library_dir)) {
    print "Book Library dir '$library_dir' doesn't exist, nothing to search<br />\n";
    print "<a href=\"" . $_SERVER["PHP_SELF"] . "\">Return to index</a><br />\n";
    die("done");
  }

  $metadata_files = generate_metadata_file_list($library_dir);

  # Loop the metadata files with found
  $tags = array();
  foreach (array_keys($metadata_files) as $file) {
    if (file_exists($file)) {
      $xml = simplexml_load_file($file);
    }
    else {
      print "Error file '$file' doesn't exist<br />\n";
      continue;
    }

    if (is_object($xml->metadata->children('dc', true)->subject)) {
	    foreach(get_object_vars($xml->metadata->children('dc', true)->subject) as $tag) {
	      if (isset($tags[$tag])) {
	        $tags[$tag] += 1;
	      }
	      else {
	        $tags[$tag] = 1;
	      }
	    }
    }

  }

  ksort($tags);

  foreach ($tags as $tag => $count) {
    print "Tag: <a href=\"" . $_SERVER["PHP_SELF"] . "?search=$tag\">$tag (count $count)</a><br />\n";
  }

  print "<br /><br /><hr /><br /><br />\n";
}


# Output all the authors as clickable links
elseif ($author_dump) {
  print "Listing all authors<br /><hr /><br />\n";

  if (! file_exists($library_dir) || ! is_dir($library_dir)) {
    print "Book Library dir '$library_dir' doesn't exist, nothing to search<br />\n";
    print "<a href=\"" . $_SERVER["PHP_SELF"] . "\">Return to index</a><br />\n";
    die("done");
  }

  $metadata_files = generate_metadata_file_list($library_dir);

  # Loop the metadata files with found
  $authors = array();
  
  foreach (array_keys($metadata_files) as $file) {
    if (file_exists($file)) {
      $xml = simplexml_load_file($file);
    }
    else {
      print "Error file '$file' doesn't exist<br />\n";
      continue;
    }

    if (isset($xml->metadata->children('dc', true)->creator)) {
	    foreach(get_object_vars($xml->metadata->children('dc', true)->creator) as $author) {
        # print "Setting for $author<br />";
	      if (isset($authors[$author])) {
	        $authors[$author] += 1;
	      }
	      else {
	        $authors[$author] = 1;
	      }
      }
	  }
  }

  ksort($authors);

  foreach ($authors as $author => $count) {
    print "Author: <a href=\"" . $_SERVER["PHP_SELF"] . "?search=".escape_str($author)."\">$author (count $count)</a><br />\n";
  }
  
  print "<br /><br /><hr /><br /><br />\n";
}





# The default search action.  It doesn't cache anything and just reads
# the filesystem and metadata.opf files every time to search.  This is
# likely inefficient but also it does work well enough for a small and
# light amount of use.
elseif (strlen($search) > 0) {
  print "Searching books for '$search'<br /><br /><hr /><br /><br />\n";

  if (! file_exists($library_dir) || ! is_dir($library_dir)) {
    print "Book Library dir '$library_dir' doesn't exist, nothing to search<br />\n";
    print "<a href=\"" . $_SERVER["PHP_SELF"] . "\">Return to index</a><br />\n";
    die("done");
  }

  $metadata_files = generate_metadata_file_list($library_dir);

  
  # Prepare our search pattern
  $cooked_search = preg_replace('/\(/', '\(', $search);
  $cooked_search = preg_replace('/\)/', '\)', $cooked_search);
  
  $search_pattern = '/' . $cooked_search . '/i';
  # print "<pre>search pattern: $search_pattern</pre><br />";
  
  # Loop the metadata files we found
  foreach (array_keys($metadata_files) as $file) {

    # Load the file or bail.
    if (file_exists($file)) {
      $xml = simplexml_load_file($file);
      # print "Searching '$file'<br />\n";
    }
    else {
      print "Error file '$file' doesn't exist<br />\n";
      continue;
    }

    if (! isset($xml->metadata)) {
      print "Error reading metadata from '$file', skipping<br />\n";
      continue;
    }

    # $found = Did we find a book we want to show
    # $ignore = Do we want to hide it and not show it
    $found = FALSE;
    $ignore = FALSE;

    # Build an array of authors as we go
    $creators = array();
    foreach ($xml->metadata->children('dc', true) as $key => $item) {
      # print "XML '$key' => item '$item'<br />";
      if (! $ignore) {

        if ($key == "creator" && isset($item)) {
          # print "Attempting to set '$item' to '$key'<br />";
          $creators[strval($item)] = $key;
        }

        if (preg_match($search_pattern, $item)) {
          # print "YES '$item' matched '$search_pattern'<br />";
          $found = TRUE;
        }
        
        foreach($ignore_filters as $index => $ig) {
          if (preg_match($ig, $item)) {
	          # print "IGNORING '$item' from '$ig'<br />";
	          $ignore = TRUE;
	        }
        }
      }
    }
    
    # Create and also search the tags array
    $tags = array();
    if (is_object($xml->metadata->children('dc', true)->subject)) {
	    foreach(get_object_vars($xml->metadata->children('dc', true)->subject) as $tag)
      {
	      # array_push($tags, $tag);

        # Look for ignorable things
        foreach($ignore_tags as $index => $it) {
          if (preg_match($it, $tag)) {
            $ignore = TRUE;
          }
        }

        if (! $ignore) {
          # Check for match
	        if (preg_match($search_pattern, $tag)) {
            $found = TRUE;
          }

          # Build array
	        if (isset($tags[$tag])) {
	          $tags[$tag] += 1;
	        }
	        else {
	          $tags[$tag] = 1;
	        }
	      }
      }
    }
    
    # If we found the book dump its metadata
    if ($found && ! $ignore) {

      $id = NULL;
      if (is_object($xml->metadata->children('dc', true)->identifier)) {
        $obj = (string) $xml->metadata->children('dc', true)->identifier;
        if (gettype($obj) == "string" && preg_match('/^\d+$/', $obj)) {
          $id = $obj;
        }
      }

      print "<table><tr>";
      
      $cover_file = get_cover_from_metadata($file);
      if (file_exists($cover_file) && ! preg_match('/#/', $cover_file)) {
        print "<td style=\"vertical-align: top;\"><img src=\"$cover_file\" width=\"100px\" height=\"150px\" /></td>";
      }
      
      # Output results
      print "<td style=\"vertical-align: top;\">";
      ksort($creators);
      $creator_links = array();
      foreach($creators as $author => $mdt) {
        $link = "<a href=\""
            . $_SERVER["PHP_SELF"]
            . "?search="
            . # escape_str($xml->metadata->children('dc', true)->creator)
              escape_str($author)
            . "\">"
            . $author
              . "</a>";
        array_push($creator_links, $link);
      }
      if (count($creator_links) == 1) {
        print "Author: " . $creator_links[0] . "<br />\n";
      }
      else {
        print "Authors: " . implode(", ", $creator_links) . "<br />\n";
      }

      print "Title: " . $xml->metadata->children('dc', true)->title . "<br />\n";
      print "Date: " . $xml->metadata->children('dc', true)->date. "<br />\n";

      if ($xml->metadata->children('dc', true)->description) {
	      print "Description: " . $xml->metadata->children('dc', true)->description . "<br />\n";
      }

      $tag_links = array();
      foreach ($tags as $key => $value) {
        array_push($tag_links, "<a href=\"" . $_SERVER["PHP_SELF"] . "?search=$key\">$key</a>");
      }
      
      print "Tags: " . join(", ", $tag_links) . "<br />\n";


      # Show book files for download
      $all_books = find_books_in_dir(book_id_to_dir($id), FALSE);
      if (is_array($all_books)) {
        foreach ($all_books as $book) {
          $ext = pathinfo($book, PATHINFO_EXTENSION);
          print "Download <a href=\"$book\">$ext</a><br />\n";
        }
      }

      # Check for an epub for offering reading online
      $bookfile = get_epub_from_metadata($file);

      if (file_exists($bookfile)) {
        $output_path = ebook_to_html_file($bookfile);
	      if (file_exists($output_path)) {
          
	        # Check for newness
	        $original_mtime = filemtime($bookfile);
	        $online_mtime = filemtime($output_path);
          
	        if ($original_mtime > $online_mtime) {
	          print "<br />Book file has been updated, click to refresh it, or keep reading the old copy.<br />";
	          print "<br /><a href=\"" . $_SERVER["PHP_SELF"] . "?convert_book=$id\">Refresh book for online reading</a><br />";
	        }
          
          # print "bookfile: '$bookfile'<br />";
          # print "output_path '$output_path'<br />\n";
          print "<br /><a href=\"$output_path\">Read: $output_path</a><br />\n";
        }
        else {
          print "<br /><a href=\"" . $_SERVER["PHP_SELF"] . "?convert_book=$id\">Convert book for online reading</a><br />";
        }
      }
      else {
        $pdf_file = get_bookfile_from_metadata($file, 'pdf');
        if (file_exists($pdf_file)) {
          print "<br /><a href=\"$pdf_file\">Read PDF Version '$pdf_file' online</a><br />\n";
        }
      }

      print "</td></tr></table>\n";

      # End of found entry
      print "<br /><hr /><br />";
      
    }
    /* else {
     *   print "<pre>";
     *   print "NOT FOUND<br />";
     *   print_r($xml);
     *   print_r($tags);
     *   print "</pre>";
     * }
     */
  }

  print "<br /><br /><hr /></br /><br />\n";

}

else {
  $search = "";
}

# These are all our buttons and search options at the bottom.
print "<form action=\"" . $_SERVER["PHP_SELF"] . "\" method=\"get\">
  Search metadata: <input type=\"text\" name=\"search\" value=\"$search\"><br>
  <input type=\"submit\" value=\"Search\"></form><br />\n";

print "<form action=\"" . $_SERVER["PHP_SELF"] . "\" method=\"get\">
  <input type=\"hidden\" name=\"tag_dump\" value=\"tag_dump\">
  <input type=\"submit\" value=\"List all tags\"></form><br />\n";

print "<form action=\"" . $_SERVER["PHP_SELF"] . "\" method=\"get\">
  <input type=\"hidden\" name=\"author_dump\" value=\"author_dump\">
  <input type=\"submit\" value=\"List all authors\"></form><br />\n";

print "<form action=\"" . $_SERVER["PHP_SELF"] . "\" method=\"get\">
  <input type=\"hidden\" name=\"search\" value=\"NO_REALLY_ALL_BOOKS\">
  <input type=\"submit\" value=\"List all books\"></form><br />\n";

print "<a href=\"" . $_SERVER["PHP_SELF"] . "\">Return to index</a><br />\n";


print "</body></html>\n";

?>
