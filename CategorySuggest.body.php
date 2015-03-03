<?php
/* CategorySuggest Mediawiki Extension
 *
 * @author Andreas Rindler (mediawiki at jenandi dot com)
 * @credits Jared Milbank, Leon Weber <leon.weber@leonweber.de>, Manuel Schneider <manuel.schneider@wikimedia.ch>, Daniel Friesen http://wiki-tools.com, Adam Franco <afranco@middlebury.edu>
 * @licence GNU General Public Licence 2.0 or later
 * @description Adds input box to edit and upload page which allows users to assign categories to the article. When a user starts typing the name of a category, the extension queries the database to find categories that match the user input."
 *
*/

if( !defined( 'MEDIAWIKI' ) ) {
	die('This file is an extension to the MediaWiki software and cannot be used standalone.');
}

$foundCategories = array();

/*************************************************************************************/
## Entry point for the hook and main worker function for editing the page:
function fnCategorySuggestShowHook( $m_isUpload = false, &$m_pageObj ) {
	global $wgOut, $wgParser, $wgTitle, $wgRequest;
	global $wgTitle, $wgScriptPath, $wgCategorySuggestCloud, $wgCategorySuggestjs;

	# Get ALL categories from wiki:
//		$m_allCats = fnAjaxSuggestGetAllCategories();
	# Get the right member variables, depending on if we're on an upload form or not:
	if( !$m_isUpload ) {
		# Check if page is subpage once to save method calls later:
		$m_isSubpage = $wgTitle->isSubpage();

		# Check if page has been submitted already to Preview or Show Changes
		$strCatsFromPreview = trim($wgRequest->getVal('txtSelectedCategories'), '; ');
		# Extract all categorylinks from PAGE:
		$catList = fnCategorySuggestGetPageCategories( $m_pageObj );
		if($strCatsFromPreview){
		 	# Get cats from preview
			$catList = array_merge($catList,explode(';',$strCatsFromPreview));
		}
		# Check for duplicate
		$catList = array_unique($catList);
		# Never ever use editFormTextTop here as it resides outside the <form> so we will never get contents
		$m_place = 'editFormTextAfterWarn';
		# Print the localised title for the select box:
	} else	{
		# No need to get categories:
		$catList = array();

		# Place output at the right place:
		$m_place = 'uploadFormTextAfterSummary';
	}

	$catList = htmlspecialchars(str_replace('_',' ',implode(';', $catList)));
	if (!empty($catList)) {
		$catList .= ';';		
	}
	$extCategoryField = '<script type="text/javascript">/*<![CDATA[*/ var categorysuggestSelect = "'. wfMessage( 'categorysuggest-select' )->text() .'"; /*]]>*/</script>' .
		'<script type="text/javascript" src="' . $wgCategorySuggestjs . '"></script>' .
		'<div id="categoryselectmaster"><div><b>' .wfMsg( 'categorysuggest-title' ). '</b></div>' .
		'<table><caption>' . wfMsg( 'categorysuggest-subtitle' ). '</caption><tbody>' .
		'<tr><th><label for="txtSelectedCategories">' .wfMsg( 'categorysuggest-boxlabel' ).':</label></th>' .
		'<td><div><input onkeyup="sendRequest(this,event);" onkeydown="return checkSelect(this, event)" autocomplete="off" type="text" name="txtSelectedCategories" id="txtSelectedCategories" length="150" value="'. $catList .'" />' .
		'<br/><div id="searchResults"></div></div></td>' .
		'<td></td></tr></tbody></table>' .
		'<input type="hidden" value="' . $wgCategorySuggestCloud . '" id="txtCSDisplayType" />' .
//		'<input type="hidden" id="txtCSCats" name="txtCSCats" value="'. catList .'" />' .
		'</div>';
	$m_pageObj->$m_place .= $extCategoryField;

	return true;
}

/*************************************************************************************/
## Entry point for the hook and main worker function for saving the page:
function fnCategorySuggestSaveHook( $m_isUpload, $m_pageObj ) {
	global $wgContLang;
	global $wgOut;

	# Get localised namespace string:
	$m_catString = $wgContLang->getNsText( NS_CATEGORY );
	# Get some distance from the rest of the content:
	$m_text = "\n";

	#CHECK IF USER HAS SELECTED ANY CATEGORIES
	if($_POST['txtSelectedCategories']){
		$arrSelectedCats = explode(';',$_POST['txtSelectedCategories']);

	 	foreach( $arrSelectedCats as $m_cat ) {
	 	 	if($m_cat){
				$m_cat = Title::capitalize($m_cat, NS_CATEGORY);
				$m_text .= "\n[[". $m_catString .":" . trim($m_cat) . "]]";
			}
		}
		# If it is an upload we have to call a different method:
		if ( $m_isUpload ) {
			$m_pageObj->mUploadDescription .= $m_text;
		} else{
			$m_pageObj->textbox1 .= $m_text;
		}
	}

	# Return to the let MediaWiki do the rest of the work:
	return true;
}

/*************************************************************************************/
## Entry point for the CSS:
function fnCategorySuggestOutputHook( &$m_pageObj, $m_parserOutput ) {
	global $wgScriptPath, $wgCategorySuggestcss;

	# Register CSS file for input box:
	$m_pageObj->addLink(
		array(
			'rel'	=> 'stylesheet',
			'type'	=> 'text/css',
			'href'	=> $wgCategorySuggestcss
		)
	);

	return true;
}

/*************************************************************************************/
## Returns an array with the categories the articles is in.
## Also removes them from the text the user views in the editbox.
function fnCategorySuggestGetPageCategories( $m_pageObj ) {
	global $wgOut, $foundCategories;

	# Get page contents:
	$m_pageText = $m_pageObj->textbox1;

	# Split the page on reserved tags so that we can avoid pulling categories out of them.
	$reservedTags = array('nowiki','noinclude','includeonly','onlyinclude');
	$stacklist = array();
	foreach($reservedTags as $tag){
		$stacklist[$tag] = array(); // Stack for reserved tags nested state.
	}
	$stacklist['template'] = array(); // Stack for template nested state.

	$m_pageText = preg_split('#(</?('. implode('|',$reservedTags) . ')>|{{|}})#u', $m_pageText , null, PREG_SPLIT_DELIM_CAPTURE);
	$foundCategories = array();
	foreach($m_pageText as $index => $block) {
		$preventCheck = true;
		switch($block){
			// If we encounter a <nowiki>, <noinclude>, <includeonly> or <onlyinclude> tag, or a template opening string, add it to our stacks.
			case '{{' :
				$stack = 'template';
			case '<nowiki>':
			case '<noinclude>':
			case '<includeonly>' :
			case '<onlyinclude>' :
				$stack = ($stack) ?: substr($block,1,-1);
				if(empty($stacklist['nowiki'])){
					$stacklist[$stack][] = true;
				}
				unset($m_pageText[($index + 1)];
				break;

			// If we encounter a closing </nowiki>, </noinclude>, </includeonly> or </onlyinclude> tag, or a template closing string, remove them from our stack and continue.
			case '}}' :
				$stack = 'template';
			case '</nowiki>':
			case '</noinclude>':
			case '</includeonly>' :
			case '</onlyinclude>' :
				$stack = ($stack) ?: substr($block,2,-1);
				if((empty($stacklist['nowiki'])) || ($stack == 'nowiki')){
					array_pop($stacklist[$stack]);
				}
				unset($m_pageText[($index + 1)];
				break;

			default :
				$preventCheck = false;
				foreach($stacklist as $stack){
					if(!empty($stack)){
						$preventCheck = true;
					}
				}
				// If we are outside protected tags, pull out categories; otherwise, just pass through any content nested inside delimiters
				break;
		}
		# if it is not a tag, or if a stack is open, there are  no categories to strip.
		$m_pageText[$index] = ($preventCheck) ? $block : fnCategorySuggestStripCats($block);
	}
	# text recomposition
	$m_pageText = implode('',$m_pageText);

	//Place cleaned text back into the text box:
	$m_pageObj->textbox1 = trim($m_pageText);

	return $foundCategories;

}

function fnCategorySuggestStripCats($texttostrip){
	global $wgContLang, $wgOut, $foundCategories;

	# Get localised namespace string:
	$m_catString = strtolower( $wgContLang->getNsText( NS_CATEGORY ) );
	# The regular expression to find the category links:
	$m_pattern = "\[\[({$m_catString}|category):([^\]]*)\]\]";
	$m_replace = "$2";

	# Check linewise for category links:
	$texttostrip = explode( "\n", $texttostrip );
	foreach( $texttostrip as $index => $m_textLine ) {
		# Filter line through pattern and store the result:
        $texttostrip[$index] = rtrim( preg_replace_callback( 
			"/{$m_pattern}/i",
			function($matches){
				global $foundCategories;
				//Set first letter to upper case to match MediaWiki standard
				$foundCategories[] = ucfirst($matches[2]);
				return "";
			},
			$m_textLine 
		));
	}
	$texttostrip = implode("\n",$texttostrip);
	return $texttostrip;
}
?>
