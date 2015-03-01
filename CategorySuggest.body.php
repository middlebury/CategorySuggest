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

/*************************************************************************************/
## Entry point for the hook and main worker function for editing the page:
function fnCategorySuggestShowHook( $m_isUpload = false, &$m_pageObj ) {
	global $wgContLang, $wgOut, $wgParser, $wgTitle, $wgRequest;
	global $wgTitle, $wgScriptPath, $wgCategorySuggestCloud, $wgCategorySuggestjs;

	# Get localised namespace string:
	$m_catString = $wgContLang->getNsText( NS_CATEGORY );
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
		if(strlen($strCatsFromPreview) > 0){
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
	$extCategoryField = '<script type="text/javascript">/*<![CDATA[*/ var categorysuggestSelect = "'. wfMessage( 'categorysuggest-select' )->text() .'"; var catString = "'. $m_catString .'"; /*]]>*/</script>' .
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

	# Assign all selected category entries:
	$strSelectedCats = $_POST['txtSelectedCategories'];

	#CHECK IF USER HAS SELECTED ANY CATEGORIES
	if(strlen($strSelectedCats)>1){
		$arrSelectedCats = array();
		$arrSelectedCats = explode(";",$_POST['txtSelectedCategories']);

	 	foreach( $arrSelectedCats as $m_cat ) {
	 	 	if(strlen($m_cat)>0){
				$m_cat = Title::capitalize($m_cat, NS_CATEGORY);
				$m_text .= "\n[[". $m_catString .":" . mysql_escape_string(trim($m_cat)) . "]]";
			}
		}
		# If it is an upload we have to call a different method:
		$body = ($m_isUpload) ? $m_pageObj->mUploadDescription : $m_pageObj->textbox1; 
		$body .= $m_text;
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
global $wgOut;

	# Get page contents:
	$m_pageText = $m_pageObj->textbox1;

	# Split the page on <nowiki> and <noinclude> tags so that we can avoid pulling categories out of them.
	$blocks = preg_split('#(<nowiki>|</nowiki>|<noinclude>|</noinclude>|{{|}})#u', $m_pageText , null, PREG_SPLIT_DELIM_CAPTURE);
	$nowikiStack = array(); // Stack for nowiki nested state.
	$noincludeStack = array(); // Stack for nowiki nested state.
	$templateStack = array(); // Stack for template nested state.
	$foundCategories = array();
	$outputText = '';
	foreach ($blocks as $block) {
		// If we encounter a closing </nowiki> or </noinclude> tag, remove them from our stack and continue.
		if (!empty($nowikiStack) && $block == '</nowiki>') {
			$outputText .= $block;
			array_pop($nowikiStack);
			continue;
		}
		if (!empty($noincludeStack) && $block == '</noinclude>') {
			$outputText .= $block;
			array_pop($noincludeStack);
			continue;
		}
		if (!empty($templateStack) && $block == '}}') {
			$outputText .= $block;
			array_pop($templateStack);
			continue;
		}
		// If we encounter a <nowiki> or <noinclude> tag, add it to our stacks.
		if ($block == '<nowiki>') {
			array_push($nowikiStack, true);
		}
		if ($block == '<noinclude>') {
			array_push($noincludeStack, true);
		}
		if ($block == '{{') {
			array_push($templateStack, true);
		}
		// Just pass through any content nested inside <nowiki> or <noinclude> tags.
		if (!empty($nowikiStack) || !empty($noincludeStack) || !empty($templateStack)) {
			$outputText .= $block;
		}
		// If we are outside noinclude or nowiki tags, pull out categories
		else {
			$outputText .= fnCategorySuggestStripCats($block, $foundCategories);
		}
	}

	//Place cleaned text back into the text box:
	$m_pageObj->textbox1 = trim( $outputText );

	return $foundCategories;

}

function fnCategorySuggestStripCats($texttostrip, &$catsintext){
	global $wgContLang, $wgOut;

	# Get localised namespace string:
	$m_catString = strtolower( $wgContLang->getNsText( NS_CATEGORY ) );
	# The regular expression to find the category links:
	$m_pattern = "\[\[({$m_catString}|category):([^\]]*)\]\]";
	$m_replace = "$2";
	# The container to store all found category links:
	$m_catLinks = array ();
	# The container to store the processed text:
	$m_cleanText = '';


	# Check linewise for category links:
	foreach( explode( "\n", $texttostrip ) as $m_textLine ) {
		# Filter line through pattern and store the result:
        $m_cleanText .= rtrim( preg_replace( "/{$m_pattern}/i", "", $m_textLine ) ) . "\n";

		# Check if we have found a category, else proceed with next line:
        if( preg_match_all( "/{$m_pattern}/i", $m_textLine,$catsintext2,PREG_SET_ORDER) ){
			 foreach( $catsintext2 as $local_cat => $m_prefix ) {
				//Set first letter to upper case to match MediaWiki standard
				$strFirstLetter = substr($m_prefix[2], 0,1);
				strtoupper($strFirstLetter);
				$newString = strtoupper($strFirstLetter) . substr($m_prefix[2], 1);
				array_push($catsintext,$newString);

	 		}
			# Get the category link from the original text and store it in our list:
			preg_replace( "/.*{$m_pattern}/i", $m_replace, $m_textLine,-1,$intNumber );
		}

	}

	return $m_cleanText;

}
?>
