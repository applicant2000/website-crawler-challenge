<?php
////////////////////////////////////////////////////////////////////////////////
// Website crawler
//
// A simple website crawler application
//
// The application consists of this single php source file that implements:
// - The input form
// - The form submission receipt
// - The crawler logic
// - The presentation of the results
//
// The input form self-submits to the same file.
//
// Source code roadmap:
// - Input form
// - Form submission handling
// - Classes/functions implementing the crawler logic
// - Invocation of the crawler
// - Presentation of the results

////////////////////////////////////////////////////////////////////////////////
// Input form display and processing

// If we're not receiving a POST, just display the input form and exit
if( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
?><!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Page Crawler</title>
<link rel="stylesheet" href="style.css" />
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
<script>
function updateExpectedCount() {
  var maxBreadth = parseInt( $("#maxBreadth").val() );
  var maxDepth = parseInt( $("#maxDepth").val() );
  var expectedCount = 0;
  if( !isNaN(maxBreadth) && !isNaN(maxDepth) ) {
    for( var i = maxDepth-1; i >= 0; i-- ) {
        expectedCount += maxBreadth ** i;
    }
  }
  $("#expectedCount").val( expectedCount );
}
$(document).ready(function() {
  $("#maxBreadth, #maxDepth").change(updateExpectedCount);
  updateExpectedCount();
});

</script>
</head>
<body>

<h1>Page Crawler</h1>
<p>Crawl a sequence of web pages starting at the given URL and gather information about them.</p>
<ul>
    <li>Start page URL: the page at which to start. This page will be crawled, and internal links will be followed for further crawling.</li>
    <li>Maximum breadth: the maximum number of internal links to crawl on a given page.</li>
    <li>Maximum depth: the maximum depth to crawl, including the Starting Page.</li>
    <li>Caution: ensure that the values for Maximum Breadth and Maximum Depth are not excessive. Their resulting Expected Page Count should not exceed 20.</li>
</ul>

<form action="crawler.php" method="POST">
<table>
    <tr>
        <td>Starting page URL</td>
        <td><input type="text" name="startPage" value="https://agencyanalytics.com" /></td>
    </tr>
    <tr>
        <td>Maximum breadth</td>
        <td><input type="text" id="maxBreadth" name="maxBreadth" value="4" /></td>
    </tr>
    <tr>
        <td>Maximum depth</td>
        <td><input type="text" id="maxDepth" name="maxDepth" value="2" /></td>
    </tr>
    <tr>
        <td>Expected page count</td>
        <td><input type="text" id="expectedCount" name="expectedCount" readonly="readonly" /></td>
    </tr>
    <tr>
        <td></td>
        <td><input type="submit" value="Submit" /></td>
    </tr>
</table>
</form>
</body>
</html>
<?php
    exit;
}

//
// We received a POST -- handle it: validate the input and display error if necessary
//

$formSuccess = true;
$formError = '';

$startPageFormVal = '';
$maxBreadthFormVal = '';
$maxDepthFormVal = '';
$expectedCountFormVal = '';

// Retrieve the POSTed values
if( isset( $_POST['startPage'] ) ) {
    $startPageFormVal = trim( $_POST['startPage'] );
}
if( isset( $_POST['maxBreadth'] ) ) {
    $maxBreadthFormVal = trim( $_POST['maxBreadth'] );
}
if( isset( $_POST['maxDepth'] ) ) {
    $maxDepthFormVal = trim( $_POST['maxDepth'] );
}
if( isset( $_POST['expectedCount'] ) ) {
    $expectedCountFormVal = trim( $_POST['expectedCount'] );
}

$startPage = $startPageFormVal;
$maxBreadth = intval( $maxBreadthFormVal );
$maxDepth = intval( $maxDepthFormVal );
$expectedCount = intval( $expectedCountFormVal );

// Validate the input
if( $startPageFormVal === '' || $maxBreadthFormVal === '' || $maxDepthFormVal === '' ) {
    $formSuccess = false;
    $formError = 'Bad input: values for all fields must be provided.';
}
elseif ( filter_var( $startPageFormVal, FILTER_VALIDATE_URL ) === false ||
         preg_match( '/^http[s]*:/', $startPageFormVal ) === 0 ) {
    $formSuccess = false;
    $formError = 'Bad input: invalid URL.';
}
elseif( filter_var( $maxBreadthFormVal, FILTER_VALIDATE_INT ) === false ) {
    $formSuccess = false;
    $formError = 'Bad input: invalid integer in Maximum Breadth.';
}
elseif( filter_var( $maxDepthFormVal, FILTER_VALIDATE_INT ) === false ) {
    $formSuccess = false;
    $formError = 'Bad input: invalid integer in Maximum Depth.';
}
elseif( $expectedCount > 20 ) {
    $formSuccess = false;
    $formError = 'Bad input: combination of Maximum Breadth and Maximum Depth would result in excessive crawling.';
}

// If there's any bad data, display error message and exit
if( !$formSuccess ) {
?><!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Page Crawler</title>
<link rel="stylesheet" href="style.css" />
</head>
<body>
<p><?php echo $formError; ?></p>
<p><a href="javascript:history.back()">Back</a></p>
<body>
<?php
    exit;
}

////////////////////////////////////////////////////////////////////////////////
// Main crawler application
//
// The input has been received and validated above.
//
// Below, the application classes and functions are defined, and then invoked
// with a single call to crawlPages.
//
// The results are rendered at the very bottom.

////////////////////////////////////////////////////////////////////////////////
// PageInfo
//
// Encapsulates the information gathered during a single page visit/crawl

class PageInfo {
    public string $url;
    public string $title;
    public array $words;
    public array $images;
    public array $internalLinks;
    public array $externalLinks;
    public float $loadTime;
    public string $statusCode;

    public function __construct(
        string $url,
        string $title,
        array $words,
        array $images,
        array $internalLinks,
        array $externalLinks,
        float $loadTime,
        string $statusCode )
    {
        $this->url = $url;
        $this->title = $title;
        $this->words = $words;
        $this->images = $images;
        $this->internalLinks = $internalLinks;
        $this->externalLinks = $externalLinks;
        $this->loadTime = $loadTime;
        $this->statusCode = $statusCode;
    }
}

////////////////////////////////////////////////////////////////////////////////
// Accumulator
//
// Singleton that stores the information from all of the page visits and
// provides aggregate statistics about the visits.

class Accumulator {
    // Adds the information captured for a single page visit
    public function addPageInfo( PageInfo $pageInfo ) {
        if( !array_key_exists( $pageInfo->url, $this->_pages ) ) {
            $this->_pages[$pageInfo->url] = $pageInfo;
        }
    }

    // Returns whether a visit for the given URL has already been captured in this Accumulator
    public function hasPage( string $url ): bool {
        return isset( $this->_pages[$url] );
    }

    // Returns the number of page visits captured in this Accumulator
    public function getNumPages(): int {
        return count( $this->_pages );
    }

    // Returns the number of unique images referenced from among the constituent page visits
    public function getUniqueImageCount(): int {
        $allImages = array();
        foreach( $this->_pages as $pageInfo ) {
            $allImages = array_merge( $allImages, $pageInfo->images );
        }
        $uniqueImages = array_unique( $allImages );
        return count( $uniqueImages );
    }

    // Returns the number of unique internal links from among the constituent page visits
    public function getUniqueInternalLinkCount(): int {
        $allInternalLinks = array();
        foreach( $this->_pages as $pageInfo ) {
            $allInternalLinks = array_merge( $allInternalLinks, $pageInfo->internalLinks );
        }
        $uniqueInternalLinks = array_unique( $allInternalLinks );
        return count( $uniqueInternalLinks );
    }

    // Returns the number of unique external links from among the constituent page visits
    public function getUniqueExternalLinkCount(): int {
        $allExternalLinks = array();
        foreach( $this->_pages as $pageInfo ) {
            $allExternalLinks = array_merge( $allExternalLinks, $pageInfo->externalLinks );
        }
        $uniqueExternalLinks = array_unique( $allExternalLinks );
        return count( $uniqueExternalLinks );
    }

    // Returns the average page load time from among the constituent page visits
    public function getAveragePageLoadTime(): float {
        $numPages = $this->getNumPages();
        if( $numPages === 0 ) {
            return 0.0;
        }

        $loadTimeSum = 0.0;
        foreach( $this->_pages as $pageInfo ) {
            $loadTimeSum += $pageInfo->loadTime;
        }
        return $loadTimeSum / $numPages;
    }

    // Returns the average word count from among the constituent page visits
    public function getAverageWordCount(): int {
        $numPages = $this->getNumPages();
        if( $numPages === 0 ) {
            return 0;
        }

        $wordCountSum = 0;
        foreach( $this->_pages as $pageInfo ) {
            $wordCountSum += count( $pageInfo->words );
        }
        return round( $wordCountSum / $numPages, 0 );
    }

    // Returns the average title length from among the constituent page visits
    public function getAverageTitleLength(): int {
        $numPages = $this->getNumPages();
        if( $numPages === 0 ) {
            return 0;
        }

        $titleLengthSum = 0;
        foreach( $this->_pages as $pageInfo ) {
            $titleLengthSum += strlen( $pageInfo->title );
        }
        return round( $titleLengthSum / $numPages, 0 );
    }

    // Returns an array indicating the URL of each page visited,
    // and the HTTP status code received when visiting the page
    public function getVisitStatusCodes(): array {
        $statusCodes = array();
        foreach( $this->_pages as $pageInfo ) {
            $statusCodes[] = array(
                'url' => $pageInfo->url,
                'statusCode' => $pageInfo->statusCode );
        }
        return $statusCodes;
    }

    // Returns an array containing the respective PageInfo object for each page visit
    public function getPageVisits(): array {
        return $this->_pages;
    }

    // Returns the single instance of this Singleton class
    public static function getInstance() {
        if( self::$_instance === null ) {
            self::$_instance = new Accumulator();
        }
        return self::$_instance;
    }

    // Constructor: no purpose other than to be private as per Singleton pattern
    private function __construct() {
    }

    // Private data

    // Singleton instance
    private static ?Accumulator $_instance = null;

    // Array of PageInfo objects (url => PageInfo): the constituent page visits
    private array $_pages = array();
}

////////////////////////////////////////////////////////////////////////////////
// PageEvaluator
//
// Fetches the page content for a given URL, and performs all scraping
// activities on that content.

class PageEvaluator {
    // Constructor: the page is specified by its fully-qualified URL.
    // All operations on this PageEvaluator act on that URL and its page content.
    public function __construct( string $url ) {
        $this->_url = $url;
        libxml_use_internal_errors(true);
    }

    // Loads the page content for this PageEvaluator's URL
    public function loadPage() {
        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/96.0.4664.45 Safari/537.36';
        $options = array(
            CURLOPT_USERAGENT       => $userAgent,
            CURLOPT_HTTPGET         => true,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_SSL_VERIFYHOST  => false,
            CURLOPT_SSL_VERIFYPEER  => false
        );
    
        $ch = curl_init( $this->_url );
        curl_setopt_array( $ch, $options );
        $content = curl_exec( $ch );
        $errno = curl_errno( $ch );
        $errMsg = curl_error( $ch );
        $info = curl_getinfo( $ch );
        curl_close( $ch );
    
        $info['errno'] = $errno;
        $info['errmsg'] = $errMsg;
        $info['content'] = $content;
      
        $this->_pageLoadInfo = $info;
        if( $info['errno'] === 0 ) {
            $this->_domDoc = new DOMDocument();
            if( $this->_domDoc->loadHTML( $this->_pageLoadInfo['content'] ) === false ) {
                $this->_domDoc = null;
            }
        }
    }

    // Returns the duration of time (in seconds) for the page load
    public function getLoadTime(): float {
        if( $this->_domDoc === null ) {
            return 0.0;
        }
        return $this->_pageLoadInfo['total_time'];
    }

    // Returns the HTTP status code returned by the server when the page was loaded
    public function getStatusCode(): string {
        if( $this->_domDoc === null ) {
            return '';
        }
        return $this->_pageLoadInfo['http_code'];
    }

    // Returns the page's title
    public function getTitle(): string {
        if( $this->_domDoc !== null ) {
            $titles = $this->_domDoc->getElementsByTagName('title');
            foreach( $titles as $titleElement ) {
                return $titleElement->textContent;
            }
        }
        return '';
    }

    // Gets all the "words" in the page, returned in an array
    // In general, the page's "words" are those normally displayed
    public function getWords(): array {
        if( $this->_domDoc === null ) {
            return array();
        }
        return $this->getElementWords( $this->_domDoc->getElementsByTagName('body')[0] );
    }

    // Gets the src and/or data-src attributes of all the img elements in the page, returned in an array
    public function getImages(): array {
        $images = array();

        if( $this->_domDoc !== null ) {
            $imgElements = $this->_domDoc->getElementsByTagName('img');
            foreach( $imgElements as $element ) {
                foreach( ['src', 'data-src'] as $attributeName ) {
                    $attr = $element->attributes->getNamedItem( $attributeName );
                    if( $attr !== null ) {
                        $images[] = $attr->nodeValue;
                    }
                }
            }
        }
        return $images;
    }

    // Gets the href of all internal links in the page, returned in an array
    public function getInternalLinks(): array {
        $links = array();

        // Run through all the <a> elements and look at their href attribute
        if( $this->_domDoc !== null ) {
            $aElements = $this->_domDoc->getElementsByTagName('a');
            foreach( $aElements as $element ) {
                $attr = $element->attributes->getNamedItem('href');
                if( $attr !== null ) {
                    $href = $attr->nodeValue;
                    // Internal links: starts with https://agencyanalytics.com,
                    // or does not start with http
                    if( preg_match( '/^https:\/\/agencyanalytics.com/', $href ) === 1 ||
                        preg_match( '/^http[s]*/', $href ) === 0 )
                    {
                        $links[] = $href;
                    }
                }
            }
        }
        return $links;
    }

    // Gets the href of all external links in the page, returned in an array
    public function getExternalLinks(): array {
        $links = array();

        // Run through all the <a> elements and look at their href attribute
        if( $this->_domDoc !== null ) {
            $aElements = $this->_domDoc->getElementsByTagName('a');
            foreach( $aElements as $element ) {
                $attr = $element->attributes->getNamedItem('href');
                if( $attr !== null ) {
                    $href = $attr->nodeValue;
                    // External links: starts with http, but not https://agencyanalytics.com
                    if( preg_match( '/^http[s]*/', $href ) === 1 &&
                        preg_match( '/^https:\/\/agencyanalytics.com/', $href ) === 0 )
                    {
                        $links[] = $href;
                    }
                }
            }
        }
        return $links;
    }

    // Determines whether the given element type is expected to directly
    // contain content (ie. "words")
    private function isContentElementType( string $tag ): bool {
        return
            $tag === 'div' ||
            $tag === 'p' ||
            $tag === 'span' ||
            $tag === 'a' ||
            $tag === 'h1' ||
            $tag === 'h2' ||
            $tag === 'h3' ||
            $tag === 'h4' ||
            $tag === 'h5' ||
            $tag === 'h6' ||
            $tag === 'h7';
    }
    
    // Splits the given text into an array of words, where delimited by
    // whitespace, punctuation, parentheses, and converted to lowercase
    private function splitText( string $text ): array {
        // Note: 0xC2A0 = UTF-8 non-breaking space
        return array_map(
            'strtolower',
            preg_split( "/([\s\.,!\?:{}()\[\]\/]|&nbsp;|\xC2\xA0)+/", $text, -1, PREG_SPLIT_NO_EMPTY ) );
    }

    // Gets all the words in the given element and its descendants, returned in an array
    private function getElementWords( DOMNode $element ): array {
        $captureTextNodes = $this->isContentElementType( $element->nodeName );
        $elementWords = array();

        foreach( $element->childNodes as $childNode ) {
            $childNodeWords = array();
            if( $childNode->nodeName === '#text' ) {
                if( $captureTextNodes ) {
                    $childNodeWords = $this->splitText( $childNode->nodeValue );
                }
            } else {
                $childNodeWords = $this->getElementWords( $childNode );
            }
            $elementWords = array_merge( $elementWords, $childNodeWords );
        }

        return $elementWords;
    }

    // Private data

    // URL of the page
    private string $_url;

    // Information about the loading of the page (including its content)
    private ?array $_pageLoadInfo = null;

    // DOM document: the parsed page content
    private ?DOMDocument $_domDoc = null;
}

////////////////////////////////////////////////////////////////////////////////
// Functions

// Crawls a sequence of pages, starting with the given URL
// Internal links are followed in turn to crawl their pages
// - maxBreadth: the maximum number of links to follow from a given page
// - maxDepth: the maximum depth to crawl, including the starting page
function crawlPages( string $startURL, int $maxBreadth, int $maxDepth ) {
    // Normalize URL: remove trailing slash
    $startURL = rtrim( $startURL, '/' );

    // Load and evaluate the current page, and save its data to the Accumulator
    $pageEvaluator = new PageEvaluator( $startURL );
    $pageEvaluator->loadPage();
    Accumulator::getInstance()->addPageInfo(
        new PageInfo(
            $startURL,
            $pageEvaluator->getTitle(),
            $pageEvaluator->getWords(),
            $pageEvaluator->getImages(),
            $pageEvaluator->getInternalLinks(),
            $pageEvaluator->getExternalLinks(),
            $pageEvaluator->getLoadTime(),
            $pageEvaluator->getStatusCode()
        )
    );

    // Crawl the internal links if there is any depth remaining
    $maxDepth--;
    if( $maxDepth > 0 ) {

        // For following internal links, we'll need the page's base URL
        $pageURLParts = parse_url( $startURL );
        $pageBaseURL = "{$pageURLParts['scheme']}://{$pageURLParts['host']}";
    
        $internalLinks = $pageEvaluator->getInternalLinks();
        $numInternalLinks = count( $internalLinks );
        $numLinksFollowed = 0;
        $linkIndex = 0;

        // Look for and follow internal links until we've hit maxBreadth or
        // exhausted the available links
        while( $numLinksFollowed < $maxBreadth && $linkIndex < $numInternalLinks ) {

            // If the internal link is not already qualified, then qualify it now:
            // prepend the base URL
            $qualifiedInternalLink = null;
            if( preg_match( '/^http[s]*/', $internalLinks[$linkIndex] ) === 1 ) {
                $qualifiedInternalLink = $internalLinks[$linkIndex];
            } else {
                $qualifiedInternalLink = $pageBaseURL . $internalLinks[$linkIndex];
            }
            $qualifiedInternalLink = rtrim( $qualifiedInternalLink, '/' );

            // Follow the link if we have not yet visited it before
            if( !Accumulator::getInstance()->hasPage( $qualifiedInternalLink ) ) {
                crawlPages( $qualifiedInternalLink, $maxBreadth, $maxDepth-1 );
                $numLinksFollowed++;
            }

            $linkIndex++;
        }
    }
}

////////////////////////////////////////////////////////////////////////////////
// Invoke the crawling process

if( $maxDepth > 0 ) {
    crawlPages( $startPage, $maxBreadth, $maxDepth );
}
$a = Accumulator::getInstance();

////////////////////////////////////////////////////////////////////////////////
// Render the results

?><!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Page Crawl Stats</title>
<link rel="stylesheet" href="style.css" />
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
<script>
$(document).ready(function() {
  $(".toggle-link").each(function(index) {
    var rowNum = $(this).attr("data-row");
    $(this).click(function() {
      $("#content-" + rowNum).toggleClass("hide-content");
      return false;
    });
  });
});
</script>
</head>
<body>

<h1>Page Crawler - AgencyAnalytics Backend Challenge - Colin McInnes</h1>

<h2>Aggregate Crawl Stats</h2>
<table>
    <tbody>
        <tr>
            <th>Pages crawled</th>
            <td class="numeric"><?php echo $a->getNumPages(); ?></td>
        </tr>
        <tr>
            <th>Unique images</th>
            <td class="numeric"><?php echo $a->getUniqueImageCount(); ?></td>
        </tr>
        <tr>
            <th>Unique internal links</th>
            <td class="numeric"><?php echo $a->getUniqueInternalLinkCount();?></td>
        </tr>
        <tr>
            <th>Unique external links</th>
            <td class="numeric"><?php echo $a->getUniqueExternalLinkCount();?></td>
        </tr>
        <tr>
            <th>Average page load time (s)</th>
            <td class="numeric"><?php echo round( $a->getAveragePageLoadTime(), 3 );?></td>
        </tr>
        <tr>
            <th>Average word count</th>
            <td class="numeric"><?php echo $a->getAverageWordCount();?></td>
        </tr>
        <tr>
            <th>Average title length</th>
            <td class="numeric"><?php echo $a->getAverageTitleLength();?></td>
        </tr>
    </tbody>
</table>

<h2>Pages Crawled</h2>
<table id="pages-crawled">
    <thead>
        <tr>
            <th>URL</th>
            <th>Status code</th>
            <th colspan="2">Content</th>
        </tr>
    </thead>
    <tbody>
<?php
$pages = $a->getPageVisits();
$rowNum = 0;
foreach( $pages as $pageVisit ) {
    $consolidatedWords = implode( ' ', $pageVisit->words );
    $consolidatedImages = implode( "<br/>\n", $pageVisit->images );
    $consolidatedInternalLinks = implode( "<br/>\n", $pageVisit->internalLinks );
    $consolidatedExternalLinks = implode( "<br/>\n", $pageVisit->externalLinks );
?>
<tr>
    <td><?=$pageVisit->url?></td>
    <td><?=$pageVisit->statusCode?></td>
    <td><a class="toggle-link" data-row="<?=$rowNum?>" href="#">Toggle view</a></td>
    <td class="hide-content" id="content-<?=$rowNum?>">
        <h3>Title</h3>
        <p><?=$pageVisit->title?></p>
        <h3>Words</h3>
        <p class="words"><?=$consolidatedWords?></p>
        <h3>Images</h3>
        <p><?=$consolidatedImages?></p>
        <h3>Internal links</h3>
        <p><?=$consolidatedInternalLinks?></p>
        <h3>External links</h3>
        <p><?=$consolidatedExternalLinks?></p>
    </td>
</tr>
<?php
    $rowNum++;
}
?>
    </tbody>
</table>
<p><a href="javascript:history.back()">Back</a></p>
</body>
</html>
