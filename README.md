# shoddy-bookshelf
PHP page to make Calibre's Library trivially viewable online

This is a very small and very simple project, it should require just a basic PHP install and access to the [pandoc](https://pandoc.org/) binary.

I wanted to be able to easily view and read my Calibre Library online
in the event I just had my phone with me and not an ebook reader, or
the book I wanted wasn't on the reader etc.  However all the serious
tools for doing self hosted ebook libraries are much heavier, have a
bunch of library dependencies, offer weird flippy-page animated book
reading instead of just a webpage, and often suggest setting up whole
docker containers for them.

This was unacceptably heavy and also felt like work, so instead I did
a bit of fiddling around with pandoc to work out a good way of
converting an epub to HTML and wrote about 600 lines of shoddy PHP for
fun to make something that was good enough and you could just drop
into place.

## How to install

1. `git clone` or download an archive file of the repo

2. Put your files somewhere web accessible

3. `htpasswd -c .htpasswd your-username-here`

4. Edit `.htaccess` to give the full path to your `.htpasswd` file for the `AuthUserFile` directive.

5. Make sure the `html` is writable by your webserver, so something like `chown www-data:www-data html`

6. Make your `books` directory is looking at a `Calibre Library` directory, symlinking to one is acceptable, the webserver needs to be able to read the files but shouldn't be able to write them.

7. Check `filter.php` to see if you want to filter any titles, authors, or tags from being visible.

8. Make sure that the `$pandoc_bin` variable points at your pandoc binary (ominous noises intensifies)

8. Visit the URL you put this in and it should just work.

## How to use

Just visit the page, you should see a "Search metadata" box, if you type something in here and click the "Search" button it'll try and match items from the library that have that word or regex somewhere in their author, title, tags, description, etc.

There should also be buttons for "List all tags", "List all authors", and "List all books".

When you do any of these options that finds at least one book you'll be rewarded with a very messy HTML page that lists the details of said books (authors, titles, descriptions, tags) and offers any files that are in the Calibre library for download.

If there are PDF files you can just click and read them, if there are
epub files (recommended) then there will be a button for "Convert book
for online reading", this will use the pandoc program to convert the
epub file into an HTML file, which can then be read in a long downward
scroll as a single large HTML file.  Be patient, this can take a
little while to complete.

If the epub file has been updated and is newer than the converted HTMl
file you'll see "Book file has been updated, click to refresh it, or
keep reading the old copy." and a link to "Refresh book for online
reading", clicking this will essentially delete the HTML file and
regenerate it, so again may take a little while.

## Screenshots

Here's roughly what it looks like:

General book output view:

![Image showing a couple of books with simple cover art and details, its Aubrey Wood's Bang Bang Bodhisattva first, then Becky Chambers A closed and Common Orbit. The authors and tags for each book are clickable links to search for more](https://github.com/twitchy-ears/shoddy-bookshelf/blob/e6478d5ea35430dd1b11f99a5038f2e757bb2dc9/Screenshot%202025-04-10%20at%2023-28-31%20Shoddy%20Bookshelf%20All%20Books.png?raw=true)

Searching for specific books by tag:

![Text at top reads "Searching books for 'Science Fiction' followed by a couple of books in the same format as above](https://github.com/twitchy-ears/shoddy-bookshelf/blob/e6478d5ea35430dd1b11f99a5038f2e757bb2dc9/Screenshot%202025-04-10%20at%2023-29-07%20Shoddy%20Bookshelf%20Search%20Science%20Fiction.png?raw=true)

Dumping out all the tags:

![A list of links to various book tags, each has "Tag:" prefixing it and a count of how many books match that tag in brackets afterwards](https://github.com/twitchy-ears/shoddy-bookshelf/blob/e6478d5ea35430dd1b11f99a5038f2e757bb2dc9/Screenshot%202025-04-10%20at%2023-29-58%20Shoddy%20Bookshelf%20Showing%20all%20tags.png?raw=true)

Dumping out all the authors:

![A list of links to various authoirs prefixed by "Author:" and a count in brackets of how many matches for each afterwards](https://github.com/twitchy-ears/shoddy-bookshelf/blob/e6478d5ea35430dd1b11f99a5038f2e757bb2dc9/Screenshot%202025-04-10%20at%2023-30-56%20Shoddy%20Bookshelf%20Showing%20all%20authors.png?raw=true) 


## BUGS?

Almost certainly absolutely a million and some dreadful security
holes, hence hide it behind `.htpasswd` at all times.

I'm not even kidding, it does some really hacky loading of variables
out of GET requests and just exec calls to the pandoc binary.

Its a hack for internal use only.

## See also

The more serious heavyweight options for doing this correctly include:

* [Calibre Web](https://github.com/janeczku/calibre-web)
* [Calibre Web Automated](https://github.com/crocodilestick/Calibre-Web-Automated)
* [Kavita](https://www.kavitareader.com/)
* [Komga](https://komga.org/)
* [Ubooquity](https://vaemendis.net/ubooquity/)
* [Jellyfin](https://jellyfin.org/docs/general/server/media/books/) 

